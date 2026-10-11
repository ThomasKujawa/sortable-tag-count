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
 * Run on plugin activation.
 *
 * Calculates the tag count for all existing objects. On network activation
 * this is done for every site of the multisite network.
 *
 * @param bool $network_wide Whether the plugin is activated network-wide.
 *
 * @return void
 */
function fks_stc_sortable_tag_count_activation( bool $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
			switch_to_blog( $site_id );
			fks_stc_calculate_all_tc_values();
			restore_current_blog();
		}
		return;
	}
	fks_stc_calculate_all_tc_values();
}
register_activation_hook( __FILE__, 'fks_stc_sortable_tag_count_activation' );

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
 * Calculate the tag count for all objects of supported post types on the current site.
 *
 * @return void
 */
function fks_stc_calculate_all_tc_values(): void {
	$query   = array(
		'post_type'      => fks_stc_get_supported_post_types(),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	);
	$results = new WP_Query( $query );
	foreach ( $results->posts as $post_id ) {
		fks_stc_update_post_tc_value( $post_id );
	}
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
