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

/**
 * Define a constant.
 */
const FKS_STC_META_FIELD_KEY = 'fks_stc_meta_tag_count';

/**
 * Remove all data of the plugin on the current site.
 *
 * @return void
 */
function fks_stc_uninstall_site(): void {
	delete_post_meta_by_key( FKS_STC_META_FIELD_KEY );
	delete_option( 'fks_stc_calculation' );
	wp_clear_scheduled_hook( 'fks_stc_calculate_batch' );
}

// delete all tag counter on all sites.
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $fks_stc_site_id ) {
		switch_to_blog( $fks_stc_site_id );
		fks_stc_uninstall_site();
		restore_current_blog();
	}
} else {
	fks_stc_uninstall_site();
}
