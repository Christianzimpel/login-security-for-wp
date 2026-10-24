<?php
/**
 * Core logic: serves the login form under the custom URL and hides the default entry points.
 *
 * @package LoginSecurityForWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class LSFW_Plugin {

	/**
	 * Option that stores the custom login slug. Kept from version 1.x for backwards compatibility.
	 */
	const OPTION_SLUG = 'wplogsec_url';

	/**
	 * Option that stores the installed plugin version (used for upgrade routines).
	 */
	const OPTION_VERSION = 'lsfw_version';

	/**
	 * Singleton instance.
	 *
	 * @var LSFW_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the current request targets the custom login URL.
	 *
	 * @var bool
	 */
	private $is_custom_login = false;

	/**
	 * Whether the current request targets a hidden entry point (wp-login.php etc.).
	 *
	 * @var bool
	 */
	private $is_blocked = false;

	/**
	 * Returns the singleton instance.
	 *
	 * @return LSFW_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers all hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'maybe_upgrade' ), 1 );

		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'detect_request' ), 9999 );
		add_action( 'wp_loaded', array( $this, 'handle_request' ), 9999 );

		add_filter( 'site_url', array( $this, 'filter_site_url' ), 10, 3 );
		add_filter( 'network_site_url', array( $this, 'filter_network_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'filter_wp_redirect' ), 10, 1 );

		// WordPress redirects /login, /admin and /dashboard to the real login. That would reveal the custom URL.
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	/**
	 * Returns the sanitized custom login slug (empty string if none is set).
	 *
	 * @return string
	 */
	public static function get_slug() {
		return sanitize_title( (string) get_option( self::OPTION_SLUG, '' ) );
	}

	/**
	 * Whether the protection is active.
	 *
	 * Can be switched off in an emergency with define( 'LSFW_DISABLE', true ); in wp-config.php.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if ( defined( 'LSFW_DISABLE' ) && LSFW_DISABLE ) {
			return false;
		}
		return '' !== self::get_slug();
	}

	/**
	 * Builds the custom login URL.
	 *
	 * @param string|null $scheme Optional URL scheme.
	 * @return string
	 */
	public static function get_login_url( $scheme = null ) {
		$slug = self::get_slug();
		$home = home_url( '/', $scheme );

		if ( get_option( 'permalink_structure' ) ) {
			$url = $home . $slug;
			// Follow the trailing slash setting of the permalink structure.
			return '/' === substr( (string) get_option( 'permalink_structure' ), -1 ) ? trailingslashit( $url ) : $url;
		}

		return $home . '?' . $slug;
	}

	/**
	 * Runs one-time upgrade tasks.
	 *
	 * Version 1.x forced the permalink structure to /%postname%/ and registered a rewrite rule.
	 * The permalink structure is left as it is (changing it again would break existing links),
	 * but the stale rewrite rule and the backup option are removed.
	 */
	public function maybe_upgrade() {
		$installed = (string) get_option( self::OPTION_VERSION, '' );
		if ( LSFW_VERSION === $installed ) {
			return;
		}

		delete_option( 'lsfw_current_permalink_structure' );
		if ( '' === $installed || version_compare( $installed, '2.0.0', '<' ) ) {
			flush_rewrite_rules( false );
		}

		update_option( self::OPTION_VERSION, LSFW_VERSION );
	}

	/**
	 * Detects early (before WordPress routes the request) whether the custom login
	 * or a hidden entry point is requested.
	 */
	public function detect_request() {
		global $pagenow;

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}

		$path = $this->get_request_path();
		$slug = self::get_slug();

		$login_path = $this->normalize_path( (string) wp_parse_url( self::get_login_url(), PHP_URL_PATH ) );
		$home_path  = $this->normalize_path( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks whether the key exists.
		$has_query_slug = isset( $_GET[ $slug ] );

		if ( $path === $login_path || ( $has_query_slug && in_array( $path, array( $home_path, $home_path . '/index.php' ), true ) ) ) {
			$this->is_custom_login = true;
			$pagenow               = 'wp-login.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Lets other plugins recognise the login screen.
			return;
		}

		$hidden = array( 'wp-login.php', 'wp-register.php' );
		if ( ! is_multisite() ) {
			// On single sites these files only redirect to the login – which would reveal the custom URL.
			$hidden[] = 'wp-signup.php';
			$hidden[] = 'wp-activate.php';
		}

		foreach ( $hidden as $file ) {
			if ( false !== strpos( $path, '/' . $file ) ) {
				$this->is_blocked = ! $this->is_allowed_login_action();
				return;
			}
		}
	}

	/**
	 * Serves the login form, or a 404 page for hidden entry points.
	 */
	public function handle_request() {
		global $pagenow;

		if ( $this->is_custom_login ) {
			$this->load_login();
		}

		if ( $this->is_blocked ) {
			$this->render_404();
		}

		// Logged-out visitors of /wp-admin/ would otherwise be redirected to the (custom) login URL.
		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() && 'admin-post.php' !== $pagenow && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->render_404();
		}
	}

	/**
	 * Rewrites links to wp-login.php created with site_url().
	 *
	 * @param string      $url    The complete URL.
	 * @param string      $path   Path relative to the site URL.
	 * @param string|null $scheme Scheme.
	 * @return string
	 */
	public function filter_site_url( $url, $path, $scheme ) {
		return $this->replace_login_url( $url, $scheme );
	}

	/**
	 * Rewrites links to wp-login.php created with network_site_url() (e.g. password reset e-mails).
	 *
	 * @param string      $url    The complete URL.
	 * @param string      $path   Path relative to the network site URL.
	 * @param string|null $scheme Scheme.
	 * @return string
	 */
	public function filter_network_site_url( $url, $path, $scheme ) {
		if ( is_multisite() ) {
			return $url;
		}
		return $this->replace_login_url( $url, $scheme );
	}

	/**
	 * Rewrites redirects to wp-login.php (wp-login.php itself uses relative redirects).
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	public function filter_wp_redirect( $location ) {
		return $this->replace_login_url( (string) $location );
	}

	/**
	 * Replaces wp-login.php in a URL with the custom login URL and keeps the query string.
	 *
	 * @param string      $url    URL to check.
	 * @param string|null $scheme Scheme.
	 * @return string
	 */
	private function replace_login_url( $url, $scheme = null ) {
		$parts = explode( '?', $url, 2 );
		if ( false === strpos( $parts[0], 'wp-login.php' ) ) {
			return $url;
		}

		$original_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$new_url         = self::get_login_url( $original_scheme ? $original_scheme : $scheme );

		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			$new_url .= ( false === strpos( $new_url, '?' ) ? '?' : '&' ) . $parts[1];
		}

		return $new_url;
	}

	/**
	 * Some requests to wp-login.php are harmless and must keep working,
	 * e.g. password protected posts submitted from cached pages.
	 *
	 * @return bool
	 */
	private function is_allowed_login_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the requested action.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		return 'postpass' === $action;
	}

	/**
	 * Loads the WordPress login screen and stops.
	 */
	private function load_login() {
		// wp-login.php expects these variables in the global scope.
		global $error, $interim_login, $action, $user_login, $user, $redirect_to;

		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/**
	 * Sends a proper 404 response using the theme's 404 template.
	 */
	private function render_404() {
		global $wp_query;

		status_header( 404 );
		nocache_headers();

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}

		/**
		 * Fires before the 404 page for a hidden login entry point is shown.
		 */
		do_action( 'lsfw_before_404' );

		$template = get_query_template( '404' );
		if ( $template && ! is_admin() ) {
			include $template;
			exit;
		}

		// Inside wp-admin block themes are not rendered completely – show a neutral 404 page instead.
		wp_die(
			esc_html__( 'The page you are looking for does not exist.', 'login-security-for-wp' ),
			esc_html__( 'Page not found', 'login-security-for-wp' ),
			array(
				'response'  => 404,
				'link_url'  => esc_url( home_url( '/' ) ),
				'link_text' => esc_html__( 'Go to the homepage', 'login-security-for-wp' ),
			)
		);
	}

	/**
	 * Returns the normalized path of the current request.
	 *
	 * @return string
	 */
	private function get_request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return $this->normalize_path( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	/**
	 * Lowercases, decodes and untrailingslashes a path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize_path( $path ) {
		return untrailingslashit( strtolower( rawurldecode( $path ) ) );
	}
}
