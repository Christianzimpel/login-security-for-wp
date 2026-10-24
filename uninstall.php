<?php
/**
 * Removes all plugin data when the plugin is deleted.
 *
 * @package LoginSecurityForWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wplogsec_url' );
delete_option( 'lsfw_version' );
delete_option( 'lsfw_current_permalink_structure' );
