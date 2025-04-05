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
 */
function fks_stc_sortable_tag_count_activation(): void {
	$query   = array(
		'post_type'      => fks_stc_get_supported_post_types(),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	);
	$results = new WP_Query( $query );
	foreach ( $results->posts as $post_id ) {
		fks_stc_update_post_tc_value( $post_id );
	}
}
register_activation_hook( __FILE__, 'fks_stc_sortable_tag_count_activation' );

/**
 * Initialize the plugin on every request.
 */
fks_stc_sortable_tag_count_init();

/**
 * Initialize the plugin.
 */
function fks_stc_sortable_tag_count_init(): void {
	// Update objects tag count if post_tag gets deleted.
	add_action( 'delete_post_tag', 'fks_stc_delete_term_tc_value', 10, 0 );

	// add save and show actions to each supported post type.
	foreach ( fks_stc_get_supported_post_types() as $post_type ) {
		add_action( 'save_post_' . $post_type, 'fks_stc_update_post_tc_value' );
		add_filter( 'manage_' . $post_type . '_posts_columns', 'fks_stc_add_tc_column_table_head' );
		add_action( 'manage_' . $post_type . '_posts_custom_column', 'fks_stc_add_tc_column_table_content', 10, 2 );
		add_filter( 'manage_edit-' . $post_type . '_sortable_columns', 'fks_stc_add_tc_table_sorting' );
	}

	// Sort values by request.
	add_filter( 'posts_results', 'fks_stc_tc_column_sort', 10, 2 );

	// Add custom styles.
	add_action( 'admin_enqueue_scripts', 'fks_stc_add_styles' );
}

/**
 * Sort the resulting posts by its tag_count.
 *
 * @param array    $posts List of posts.
 * @param WP_Query $query The query object.
 * @return array
 */
function fks_stc_tc_column_sort( array $posts, WP_Query $query ): array {
	// bail if this is not the backend.
	if ( ! is_admin() ) {
		return $posts;
	}

	// bail if this is not the main query.
	if ( ! $query->is_main_query() ) {
		return $posts;
	}

	// bail if this is not a posts query.
	if ( 'post' !== $query->get( 'post_type' ) ) {
		return $posts;
	}

	// bail if sort is not by tag_count.
	if ( 'tag_count' !== $query->get( 'orderby' ) ) {
		return $posts;
	}

	// order the resulting posts by tag_count depending on sort direction.
	if ( 'DESC' === $query->get( 'order' ) ) {
		usort(
			$posts,
			function ( $a, $b ) {
				return absint( get_post_meta( $a->ID, FKS_STC_META_FIELD_KEY, true ) ) < absint( get_post_meta( $b->ID, FKS_STC_META_FIELD_KEY, true ) );
			}
		);
	} else {
		usort(
			$posts,
			function ( $a, $b ) {
				return absint( get_post_meta( $a->ID, FKS_STC_META_FIELD_KEY, true ) ) > absint( get_post_meta( $b->ID, FKS_STC_META_FIELD_KEY, true ) );
			}
		);
	}
	return $posts;
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
 * Update tag count on save for supported post-types.
 *
 * @param int $post_id ID of the updates post.
 *
 * @return void
 */
function fks_stc_update_post_tc_value( int $post_id ): void {
	// get the post tags for given object.
	$post_tags = get_the_tags( $post_id );

	if ( $post_tags ) {
		// update counter.
		update_post_meta( $post_id, FKS_STC_META_FIELD_KEY, count( $post_tags ) );
	} else {
		// delete counter.
		update_post_meta( $post_id, FKS_STC_META_FIELD_KEY, 0 );
	}
}

/**
 * Update tag counter of all object on globally deletion of single tag.
 *
 * @return void
 */
function fks_stc_delete_term_tc_value(): void {
	$query   = array(
		'post_type'      => 'any',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	);
	$results = new WP_Query( $query );
	foreach ( $results->posts as $post_id ) {
		if ( metadata_exists( 'post', $post_id, FKS_STC_META_FIELD_KEY ) ) {
			fks_stc_update_post_tc_value( $post_id );
		}
	}
}

/**
 * Return whether the requested object_type is supported by our plugin.
 *
 * Hint: we support all post types which supports the post_tax taxonomy.
 *
 * @param WP_Post_Type|null $object_type The requested object type.
 *
 * @return bool
 */
function fks_stc_is_object_type_supported( null|WP_Post_Type $object_type ): bool {
	// return false if post type is null.
	if ( is_null( $object_type ) ) {
		return false;
	}

	// return whether it is supported.
	return in_array( $object_type->name, fks_stc_get_supported_post_types(), true );
}

/**
 * Return the supported post types for our plugin.
 *
 * @return array
 */
function fks_stc_get_supported_post_types(): array {
	$taxonomy   = get_taxonomy( 'post_tag' );
	$post_types = array();
	foreach ( get_post_types() as $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		if ( $post_type_object instanceof WP_Post_Type && in_array( $post_type_object->name, $taxonomy->object_type, true ) ) {
			$post_types[] = $post_type;
		}
	}
	return $post_types;
}

/**
 * Add plugin styles.
 *
 * @return void
 */
function fks_stc_add_styles(): void {
	// admin-specific styles.
	wp_enqueue_style(
		'sortable-tag-count-admin',
		plugins_url( '/style.css', __FILE__ ),
		array(),
		filemtime( plugin_dir_path( __FILE__ ) . '/style.css' ),
	);
}
