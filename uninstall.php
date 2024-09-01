<?php
/**
 * Tasks to run during uninstallation of this plugin.
 *
 * @package sortable-tag-count
 */

// if uninstall.php is not called by WordPress, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// prevent direct access.
defined( 'ABSPATH' ) || exit;

// do nothing if PHP-version is not 8.0 or newer.
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

/**
 * Define variable.
 */
const FKS_STC_META_FIELD_KEY = 'fks_stc_meta_tag_count';

// delete all tag counter.
$query   = array(
	'post_type'      => 'any',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
);
$results = new WP_Query( $query );
foreach ( $results->posts as $my_post_id ) {
    if( metadata_exists( 'post', $my_post_id, FKS_STC_META_FIELD_KEY ) ) {
        delete_post_meta($my_post_id, FKS_STC_META_FIELD_KEY);
    }
}
