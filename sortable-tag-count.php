<?php
/**
 * Plugin Name: Sortable Tag Count
 * Plugin URI: https://www.fachkraeftesicherer.de
 * Description: Adds a sortable column to the posts and pages admin with the tag count.
 * Author: <a href="https://thomas.fachkraeftesicherer.de" target="_blank">Thomas</a> und <a href="https://www.thomaszwirner.de/" target="_blank">Thomas</a>
 * Author URI: https://thomas.fachkraeftesicherer.de
 * Version: 1.0.3
 * Requires PHP: 8.0
 * Text Domain: sortable-tag-count
 * Domain Path: /languages
 * License: GPL v3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * @package sortable-tag-count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! is_admin() ) {
	return false;
} // Don't run if not admin page

/**
 * Define variables
 */
define( 'FKS_STC_META_FIELD_KEY', 'fks_stc_meta_tag_count' );
define( 'FKS_STC_OPTION_FIELD_KEY', 'fks_stc_option_tag_count' );

/**
 * Initialize the plugin.
 */
fks_stc_sortable_tag_count_run();

/**
 * Deactivate plugin
 *
 * @return void
 */
function fks_stc_deactivation(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$plugin = isset( $_REQUEST['plugin'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) ) : '';
	check_admin_referer( "deactivate-plugin_{$plugin}" );

	// Delete counter marker option.
	delete_option( FKS_STC_OPTION_FIELD_KEY );
}
register_deactivation_hook( __FILE__, 'fks_stc_deactivation' );

/**
 * Init plugin
 */
function fks_stc_sortable_tag_count_run(): void {
	// Update posts/pages tag count.
	add_action( 'init', 'fks_stc_update_posts_tc_value' );

	// Add columns.
	add_filter( 'manage_posts_columns', 'fks_stc_add_tc_column_table_head' );
	add_filter( 'manage_page_posts_columns', 'fks_stc_add_tc_column_table_head' );

	// Fill tag count value.
	add_action( 'manage_posts_custom_column', 'fks_stc_add_tc_column_table_content', 10, 2 );
	add_action( 'manage_page_posts_custom_column', 'fks_stc_add_tc_column_table_content', 10, 2 );

	// Enable sorting for columns.
	add_filter( 'manage_edit-post_sortable_columns', 'fks_stc_add_tc_table_sorting' );
	add_filter( 'manage_edit-page_sortable_columns', 'fks_stc_add_tc_table_sorting' );

	// Sort values.
	add_filter( 'request', 'fks_stc_tc_column_sort' );

	// Update tag count value on save.
	add_action( 'save_post', 'fks_stc_update_post_tc_value' );

	// Add custom styles.
	add_action( 'admin_head', 'fks_stc_styles' );
}

/**
 * Add Tag Count column to post type
 *
 * @param  array $defaults List of columns.
 * @return array
 */
function fks_stc_add_tc_column_table_head( array $defaults ): array {
	$defaults['tag_count'] = __( 'Tag Count', 'sortable-tag-count' );
	return $defaults;
}

/**
 * Show tag count for post type
 *
 * @param string $column_name The column name.
 *
 * @return void
 */
function fks_stc_add_tc_column_table_content( string $column_name ): void {
	global $post;
	if ( 'tag_count' === $column_name ) {
		if ( get_post_meta( $post->ID, FKS_STC_META_FIELD_KEY, true ) ) {
			echo absint( get_post_meta( $post->ID, FKS_STC_META_FIELD_KEY, true ) );
		} else {
			$post_tags = get_the_tags( $post->ID );
			if ( $post_tags ) {
				echo count( $post_tags );
				update_post_meta( $post->ID, FKS_STC_META_FIELD_KEY, count( $post_tags ) );
			} else {
				update_post_meta( $post->ID, FKS_STC_META_FIELD_KEY, 0 );
			}
		}
	}
}

/**
 * Make column tag count sortable
 *
 * @param  array $columns List of columns.
 * @return mixed
 */
function fks_stc_add_tc_table_sorting( array $columns ): array {
	$columns['tag_count'] = 'tag_count';
	return $columns;
}

/**
 * Sort values by tag count
 *
 * @param  array $vars Variables for listings.
 * @return array
 */
function fks_stc_tc_column_sort( array $vars ): array {
	if ( ! empty( $vars['orderby'] ) && "redirect_url" !== $vars['orderby'] ) {
		$orderby = $vars['orderby'];
		if ( 'tag_count' === $orderby ) {
			$meta_query = array(
				'relation' => 'OR',
				array(
					'key'  => FKS_STC_META_FIELD_KEY,
					'type' => 'NUMERIC',
				),
			);

			$vars['meta_query'] = $meta_query;
			$vars['orderby']    = 'meta_value';
		}
	}
	return $vars;
}

/**
 * Set tag count for post type.
 *
 * @param string $post_type The requested post-type.
 *
 * @return void
 */
function fks_stc_update_tc_posts( string $post_type ): void {
	$query   = array(
		'post_type'      => $post_type,
		'posts_per_page' => -1,
	);
	$results = new WP_Query( $query );
	if ( $results->have_posts() ) {
		while ( $results->have_posts() ) {
			$results->the_post();
			$post_tags = get_the_tags( get_the_ID() );
			if ( $post_tags ) {
				update_post_meta( get_the_ID(), FKS_STC_META_FIELD_KEY, count( $post_tags ) );
			} else {
				update_post_meta( get_the_ID(), FKS_STC_META_FIELD_KEY, '0' );
			}
		}
	}
	wp_reset_postdata();
}


/**
 * Update the counter on supported types.
 *
 * @return void
 */
function fks_stc_update_posts_tc_value(): void {
	if ( false === (bool) get_option( FKS_STC_OPTION_FIELD_KEY ) ) {
		// Posts.
		fks_stc_update_tc_posts( 'post' );
		// Pages.
		fks_stc_update_tc_posts( 'page' );
		// mark it as initialized.
		add_option( FKS_STC_OPTION_FIELD_KEY, true );
	}
}

/**
 * Update tag count on save for supported post-types.
 *
 * @param int $post_id ID of the updates post.
 *
 * @return void
 */
function fks_stc_update_post_tc_value( int $post_id ): void {
	if ( in_array( get_post_type( $post_id ), array( 'post', 'page' ), true ) ) {
		$post_tags = get_the_tags( $post_id );
		if ( $post_tags ) {
			update_post_meta( $post_id, FKS_STC_META_FIELD_KEY, count( $post_tags ) );
		} else {
			update_post_meta( $post_id, FKS_STC_META_FIELD_KEY, 0 );
		}
	}
}

/**
 * Output custom styles.
 *
 * @return void
 */
function fks_stc_styles(): void {
	echo '<style>
           #tag_count { width: 12%; }
           .tag_count { text-align: center; }
         </style>';
}
