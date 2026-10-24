<?php
/**
 * Plugin Name:       Login Security – Custom Login URL
 * Plugin URI:        https://wordpress.org/plugins/login-security-for-wp/
 * Description:       Moves the WordPress login to a custom URL of your choice and answers requests to wp-login.php and wp-admin with a regular 404 page.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Christian Zimpel
 * Author URI:        https://christianzimpel.de
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       login-security-for-wp
 *
 * @package LoginSecurityForWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LSFW_VERSION', '2.0.0' );
define( 'LSFW_FILE', __FILE__ );
define( 'LSFW_DIR', plugin_dir_path( __FILE__ ) );
define( 'LSFW_URL', plugin_dir_url( __FILE__ ) );

require_once LSFW_DIR . 'includes/class-lsfw-plugin.php';
require_once LSFW_DIR . 'includes/class-lsfw-admin.php';

LSFW_Plugin::instance();

if ( is_admin() ) {
	LSFW_Admin::instance();
}
