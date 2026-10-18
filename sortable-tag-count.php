<?php
/**
 * Plugin Name: Sortable Tag Count
 * Plugin URI: https://www.fachkraeftesicherer.de
 * Description: Adds a sortable column to the posts and pages admin with the tag count.
 * Author: Thomas Kujawa, Thomas Zwirner
 * Author URI: https://thomas.fachkraeftesicherer.de
 * Requires at least: 6.0
 * License: GPL v3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Version: @@VersionNumber@@
 * Requires PHP: 8.0
 * Text Domain: sortable-tag-count
 *
 * @package sortable-tag-count
 */

// prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Define variable.
 */
const FKS_STC_META_FIELD_KEY = 'fks_stc_meta_tag_count';

/**
 * Name of the option which holds the state of the background calculation.
 */
const FKS_STC_CALCULATION_OPTION = 'fks_stc_calculation';

/**
 * Name of the cron hook which runs the background calculation.
 */
const FKS_STC_CALCULATION_HOOK = 'fks_stc_calculate_batch';

// load WP-CLI command.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/class-fks-stc-cli-command.php';
	WP_CLI::add_command( 'sortable-tag-count', 'FKS_STC_CLI_Command' );
}

/**
 * Run on plugin activation.
 *
 * Does not calculate the tag counts directly, as this could lead to a timeout
 * on large sites. Instead, the calculation is scheduled to run in the background.
 * On network activation this is done for every site of the multisite network.
 *
 * @param bool $network_wide Whether the plugin is activated network-wide.
 *
 * @return void
 */
function fks_stc_sortable_tag_count_activation( bool $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( $site_id );
			fks_stc_start_calculation();
			restore_current_blog();
		}
		return;
	}
	fks_stc_start_calculation();
}
register_activation_hook( __FILE__, 'fks_stc_sortable_tag_count_activation' );

/**
 * Run on plugin deactivation.
 *
 * Removes scheduled background calculations. The current state is kept, so the
 * calculation will be restarted on the next activation.
 *
 * @param bool $network_wide Whether the plugin is deactivated network-wide.
 *
 * @return void
 */
function fks_stc_sortable_tag_count_deactivation( bool $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( $site_id );
			wp_clear_scheduled_hook( FKS_STC_CALCULATION_HOOK );
			restore_current_blog();
		}
		return;
	}
	wp_clear_scheduled_hook( FKS_STC_CALCULATION_HOOK );
}
register_deactivation_hook( __FILE__, 'fks_stc_sortable_tag_count_deactivation' );

/**
 * Schedule the background calculation for new sites if the plugin is network-activated.
 *
 * @param WP_Site $new_site The new site.
 *
 * @return void
 */
function fks_stc_on_initialize_site( WP_Site $new_site ): void {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// bail if plugin is not network-activated.
	if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	fks_stc_start_calculation();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'fks_stc_on_initialize_site', 200 );

// run the background calculation via WP-Cron.
add_action( FKS_STC_CALCULATION_HOOK, 'fks_stc_run_calculation' );

// show the progress of the background calculation.
add_action( 'admin_notices', 'fks_stc_show_calculation_notice' );

/**
 * Initialize the plugin after all post types and taxonomies are registered.
 */
add_action( 'init', 'fks_stc_sortable_tag_count_init', 20 );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function fks_stc_sortable_tag_count_init(): void {
	// Update the counter whenever tags of an object are set or changed (classic editor, block editor/REST, quick edit, bulk edit, API calls).
	add_action( 'set_object_terms', 'fks_stc_on_set_object_terms', 10, 4 );

	// Update the counter whenever tags are removed from an object (also fired when a tag gets deleted globally).
	add_action( 'deleted_term_relationships', 'fks_stc_on_deleted_term_relationships', 10, 3 );

	// Initialize the counter for new objects after all terms and meta have been saved.
	add_action( 'wp_after_insert_post', 'fks_stc_on_after_insert_post', 10, 2 );

	// add column and sorting to each supported post type.
	foreach ( fks_stc_get_supported_post_types() as $post_type ) {
		add_filter( 'manage_' . $post_type . '_posts_columns', 'fks_stc_add_tc_column_table_head' );
		add_action( 'manage_' . $post_type . '_posts_custom_column', 'fks_stc_add_tc_column_table_content', 10, 2 );
		add_filter( 'manage_edit-' . $post_type . '_sortable_columns', 'fks_stc_add_tc_table_sorting' );
	}

	// Sort the list in the database query to respect pagination.
	add_action( 'pre_get_posts', 'fks_stc_tc_column_sort' );

	// Add custom styles.
	add_action( 'admin_enqueue_scripts', 'fks_stc_add_styles' );
}

/**
 * Sort the list of objects by its tag count.
 *
 * The sorting is done in the database query, so it works across all pages
 * of the list. Objects without a saved counter are included as well.
 *
 * @param WP_Query $query The query object.
 *
 * @return void
 */
function fks_stc_tc_column_sort( WP_Query $query ): void {
	// bail if this is not the backend.
	if ( ! is_admin() ) {
		return;
	}

	// bail if this is not the main query.
	if ( ! $query->is_main_query() ) {
		return;
	}

	// bail if sort is not by tag_count.
	if ( 'tag_count' !== $query->get( 'orderby' ) ) {
		return;
	}

	// bail if this is not a supported post type.
	$post_type = $query->get( 'post_type' );
	if ( empty( $post_type ) ) {
		$post_type = 'post';
	}
	if ( ! is_string( $post_type ) || ! in_array( $post_type, fks_stc_get_supported_post_types(), true ) ) {
		return;
	}

	// get the sort direction.
	$order = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';

	// order by our counter, include objects without counter.
	$query->set(
		'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		array(
			'relation'    => 'OR',
			'fks_stc_tc'  => array(
				'key'  => FKS_STC_META_FIELD_KEY,
				'type' => 'NUMERIC',
			),
			'fks_stc_new' => array(
				'key'     => FKS_STC_META_FIELD_KEY,
				'compare' => 'NOT EXISTS',
			),
		)
	);
	$query->set(
		'orderby',
		array(
			'fks_stc_tc' => $order,
			'date'       => 'DESC',
		)
	);
}

/**
 * Add Tag Count column to post type.
 *
 * @param  array $defaults List of columns.
 *
 * @return array
 */
function fks_stc_add_tc_column_table_head( array $defaults ): array {
	// add our column.
	$defaults['tag_count'] = __( 'Tag Count', 'sortable-tag-count' );

	// return resulting list of columns.
	return $defaults;
}

/**
 * Show tag count for post type.
 *
 * @param string $column_name The column name.
 * @param int    $post_id The post ID.
 *
 * @return void
 */
function fks_stc_add_tc_column_table_content( string $column_name, int $post_id ): void {
	// bail if this is not our column.
	if ( 'tag_count' !== $column_name ) {
		return;
	}

	// calculate the counter if it does not exist yet (e.g. new site in multisite).
	if ( ! metadata_exists( 'post', $post_id, FKS_STC_META_FIELD_KEY ) ) {
		fks_stc_update_post_tc_value( $post_id );
	}

	// show count from db.
	echo absint( get_post_meta( $post_id, FKS_STC_META_FIELD_KEY, true ) );
}

/**
 * Add column count sortable to the cpt-table.
 *
 * @param  array $columns List of columns.
 *
 * @return array
 */
function fks_stc_add_tc_table_sorting( array $columns ): array {
	// add our custom column as sortable column.
	$columns['tag_count'] = 'tag_count';

	// return all sortable columns.
	return $columns;
}

/**
 * Update the counter if tags of an object have been set.
 *
 * @param int    $object_id The object ID.
 * @param array  $terms     List of terms.
 * @param array  $tt_ids    List of term taxonomy IDs.
 * @param string $taxonomy  The taxonomy slug.
 *
 * @return void
 */
function fks_stc_on_set_object_terms( int $object_id, array $terms, array $tt_ids, string $taxonomy ): void {
	// bail if this is not the post_tag taxonomy.
	if ( 'post_tag' !== $taxonomy ) {
		return;
	}

	// update the counter.
	fks_stc_update_post_tc_value( $object_id );
}

/**
 * Update the counter if tags have been removed from an object.
 *
 * @param int    $object_id The object ID.
 * @param array  $tt_ids    List of term taxonomy IDs.
 * @param string $taxonomy  The taxonomy slug.
 *
 * @return void
 */
function fks_stc_on_deleted_term_relationships( int $object_id, array $tt_ids, string $taxonomy ): void {
	// bail if this is not the post_tag taxonomy.
	if ( 'post_tag' !== $taxonomy ) {
		return;
	}

	// update the counter.
	fks_stc_update_post_tc_value( $object_id );
}

/**
 * Initialize the counter for supported objects after they have been saved.
 *
 * @param int     $post_id The post ID.
 * @param WP_Post $post    The post object.
 *
 * @return void
 */
function fks_stc_on_after_insert_post( int $post_id, WP_Post $post ): void {
	// bail if this is a revision.
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	// bail if post type is not supported.
	if ( ! in_array( $post->post_type, fks_stc_get_supported_post_types(), true ) ) {
		return;
	}

	// update the counter.
	fks_stc_update_post_tc_value( $post_id );
}

/**
 * Update tag count for given object.
 *
 * @param int $post_id ID of the object.
 *
 * @return void
 */
function fks_stc_update_post_tc_value( int $post_id ): void {
	// get the post tags for given object.
	$post_tags = get_the_tags( $post_id );

	// save the counter.
	update_post_meta( $post_id, FKS_STC_META_FIELD_KEY, is_array( $post_tags ) ? count( $post_tags ) : 0 );
}

/**
 * Start the background calculation of all tag counts on the current site.
 *
 * @return void
 */
function fks_stc_start_calculation(): void {
	update_option(
		FKS_STC_CALCULATION_OPTION,
		array(
			'last_id' => 0,
			'done'    => 0,
			'total'   => null,
			'started' => time(),
		),
		false
	);
	wp_clear_scheduled_hook( FKS_STC_CALCULATION_HOOK );
	wp_schedule_single_event( time(), FKS_STC_CALCULATION_HOOK );
}

/**
 * Return the state of the background calculation on the current site.
 *
 * @return array|null The state or null if no calculation is pending.
 */
function fks_stc_get_calculation_state(): ?array {
	$state = get_option( FKS_STC_CALCULATION_OPTION );
	return is_array( $state ) ? $state : null;
}

/**
 * Finish the background calculation on the current site.
 *
 * @return void
 */
function fks_stc_finish_calculation(): void {
	delete_option( FKS_STC_CALCULATION_OPTION );
	wp_clear_scheduled_hook( FKS_STC_CALCULATION_HOOK );
}

/**
 * Return the number of objects to process per batch.
 *
 * @return int
 */
function fks_stc_get_batch_size(): int {
	/**
	 * Filter the number of objects which are processed per batch.
	 *
	 * @param int $batch_size The batch size.
	 */
	return max( 1, absint( apply_filters( 'fks_stc_batch_size', 100 ) ) );
}

/**
 * Return the IDs of objects of supported post types with an ID greater than the given ID.
 *
 * Uses the ID as cursor instead of an offset, so the query stays fast on large tables
 * and objects added during the calculation are not skipped.
 *
 * @param int $last_id The last processed ID.
 * @param int $limit   The maximum number of IDs.
 *
 * @return int[]
 */
function fks_stc_get_object_ids( int $last_id, int $limit ): array {
	global $wpdb;

	$post_types = fks_stc_get_supported_post_types();
	if ( empty( $post_types ) ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status != 'auto-draft' AND post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ') ORDER BY ID ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			array_merge( array( $last_id ), $post_types, array( $limit ) )
		)
	);

	return array_map( 'absint', $ids );
}

/**
 * Return the number of objects of supported post types.
 *
 * @return int
 */
function fks_stc_count_objects(): int {
	global $wpdb;

	$post_types = fks_stc_get_supported_post_types();
	if ( empty( $post_types ) ) {
		return 0;
	}

	return absint(
		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status != 'auto-draft' AND post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$post_types
			)
		)
	);
}

/**
 * Process the next batch of the background calculation on the current site.
 *
 * @return bool True if more objects are waiting, false if the calculation is finished.
 */
function fks_stc_process_batch(): bool {
	$state = fks_stc_get_calculation_state();

	// bail if no calculation is pending.
	if ( is_null( $state ) ) {
		return false;
	}

	// calculate total on first run (in the context of the site, so all post types are registered).
	if ( is_null( $state['total'] ) ) {
		$state['total'] = fks_stc_count_objects();
	}

	// get and process the next objects.
	$batch_size = fks_stc_get_batch_size();
	$ids        = fks_stc_get_object_ids( absint( $state['last_id'] ), $batch_size );
	foreach ( $ids as $post_id ) {
		fks_stc_update_post_tc_value( $post_id );
	}

	// finish if this was the last batch.
	if ( count( $ids ) < $batch_size ) {
		fks_stc_finish_calculation();
		return false;
	}

	// save the progress.
	$state['last_id'] = end( $ids );
	$state['done']    = absint( $state['done'] ) + count( $ids );
	update_option( FKS_STC_CALCULATION_OPTION, $state, false );

	return true;
}

/**
 * Run the background calculation via WP-Cron.
 *
 * Processes batches until the time limit is reached and schedules the next run
 * if more objects are waiting.
 *
 * @return void
 */
function fks_stc_run_calculation(): void {
	/**
	 * Filter the maximum number of seconds per WP-Cron run.
	 *
	 * @param int $time_limit The time limit in seconds.
	 */
	$time_limit = max( 1, absint( apply_filters( 'fks_stc_cron_time_limit', 20 ) ) );
	$start      = microtime( true );

	do {
		$more = fks_stc_process_batch();
	} while ( $more && ( microtime( true ) - $start ) < $time_limit );

	// schedule the next run.
	if ( $more && ! wp_next_scheduled( FKS_STC_CALCULATION_HOOK ) ) {
		wp_schedule_single_event( time(), FKS_STC_CALCULATION_HOOK );
	}
}

/**
 * Show the progress of the background calculation in the backend.
 *
 * @return void
 */
function fks_stc_show_calculation_notice(): void {
	// bail if user is not allowed to edit posts.
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	// bail if this is not the dashboard, the plugin list or a list view.
	$screen = get_current_screen();
	if ( ! $screen instanceof WP_Screen || ! in_array( $screen->base, array( 'dashboard', 'plugins', 'edit' ), true ) ) {
		return;
	}

	// bail if no calculation is pending.
	$state = fks_stc_get_calculation_state();
	if ( is_null( $state ) ) {
		return;
	}

	// create the message.
	if ( is_null( $state['total'] ) ) {
		$message = __( 'Sortable Tag Count is calculating the tag counts in the background. Sorting by tag count may be incomplete until then.', 'sortable-tag-count' );
	} else {
		$message = sprintf(
			/* translators: 1: number of processed entries, 2: total number of entries */
			__( 'Sortable Tag Count is calculating the tag counts in the background: %1$s of %2$s entries done. Sorting by tag count may be incomplete until then.', 'sortable-tag-count' ),
			number_format_i18n( min( absint( $state['done'] ), absint( $state['total'] ) ) ),
			number_format_i18n( absint( $state['total'] ) )
		);
	}

	// add hint if WP-Cron is disabled.
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		$message .= ' ' . __( 'WP-Cron is disabled on this site. Please make sure a system cron job runs wp-cron.php or run "wp sortable-tag-count recalculate".', 'sortable-tag-count' );
	}

	printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( $message ) );
}

/**
 * Return the supported post types for our plugin.
 *
 * Hint: we support all post types which supports the post_tag taxonomy.
 *
 * @return array
 */
function fks_stc_get_supported_post_types(): array {
	$taxonomy = get_taxonomy( 'post_tag' );

	// return empty list if taxonomy does not exist.
	if ( ! $taxonomy instanceof WP_Taxonomy ) {
		return array();
	}

	// return the list of post types which are using this taxonomy.
	return array_values( array_intersect( get_post_types(), $taxonomy->object_type ) );
}

/**
 * Add plugin styles on list views only.
 *
 * @param string $hook_suffix The current admin page.
 *
 * @return void
 */
function fks_stc_add_styles( string $hook_suffix ): void {
	// bail if this is not a list view.
	if ( 'edit.php' !== $hook_suffix ) {
		return;
	}

	// admin-specific styles.
	wp_enqueue_style(
		'sortable-tag-count-admin',
		plugins_url( 'style.css', __FILE__ ),
		array(),
		(string) filemtime( plugin_dir_path( __FILE__ ) . 'style.css' ),
	);
}
