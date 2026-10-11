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

// delete all tag counter on all sites.
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $fks_stc_site_id ) {
		switch_to_blog( $fks_stc_site_id );
		delete_post_meta_by_key( FKS_STC_META_FIELD_KEY );
		restore_current_blog();
	}
} else {
	delete_post_meta_by_key( FKS_STC_META_FIELD_KEY );
}
