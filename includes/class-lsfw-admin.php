<?php
/**
 * Settings page and admin notices.
 *
 * @package LoginSecurityForWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class.
 */
final class LSFW_Admin {

	/**
	 * Settings page slug (unchanged since 1.0 so existing bookmarks keep working).
	 */
	const PAGE = 'wplogsec-page';

	/**
	 * Settings group.
	 */
	const GROUP = 'wplogsec_settings_group';

	/**
	 * Slugs that must not be used because WordPress or common setups already use them.
	 *
	 * @var string[]
	 */
	const RESERVED = array( 'admin', 'dashboard', 'login', 'wp-admin', 'wp-login', 'wp-login-php', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'xmlrpc', 'xmlrpc-php', 'index-php', 'wp-signup', 'wp-activate', 'wp-register' );

	/**
	 * Singleton instance.
	 *
	 * @var LSFW_Admin|null
	 */
	private static $instance = null;

	/**
	 * Prevents duplicated error messages (WordPress may sanitize an option twice on first save).
	 *
	 * @var bool
	 */
	private $error_added = false;

	/**
	 * Returns the singleton instance.
	 *
	 * @return LSFW_Admin
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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'notice_missing_slug' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LSFW_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the page below "Settings".
	 */
	public function add_menu() {
		add_options_page(
			__( 'Login Security', 'login-security-for-wp' ),
			__( 'Login Security', 'login-security-for-wp' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the setting, section and field.
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			LSFW_Plugin::OPTION_SLUG,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_slug' ),
			)
		);

		add_settings_section( 'lsfw_main', '', '__return_false', self::PAGE );

		add_settings_field(
			'lsfw_slug',
			__( 'Custom login URL', 'login-security-for-wp' ),
			array( $this, 'render_field' ),
			self::PAGE,
			'lsfw_main',
			array( 'label_for' => 'wplogsec_url' )
		);
	}

	/**
	 * Sanitizes and validates the slug.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_slug( $value ) {
		$old  = LSFW_Plugin::get_slug();
		$slug = sanitize_title( is_string( $value ) ? $value : '' );

		if ( '' === $slug ) {
			return '';
		}

		$error = '';
		if ( in_array( $slug, self::RESERVED, true ) ) {
			/* translators: %s: login slug */
			$error = sprintf( __( '"%s" is used by WordPress itself. Please choose another login URL.', 'login-security-for-wp' ), $slug );
		} elseif ( $this->slug_is_taken( $slug ) ) {
			/* translators: %s: login slug */
			$error = sprintf( __( '"%s" is already used by a page or post. Please choose another login URL.', 'login-security-for-wp' ), $slug );
		}

		if ( '' !== $error ) {
			if ( ! $this->error_added ) {
				add_settings_error( LSFW_Plugin::OPTION_SLUG, 'lsfw_invalid', $error );
				$this->error_added = true;
			}
			return $old;
		}

		return $slug;
	}

	/**
	 * Checks whether a public post or page already uses the slug.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	private function slug_is_taken( $slug ) {
		$post_types = array_values( get_post_types( array( 'public' => true ) ) );
		return null !== get_page_by_path( $slug, OBJECT, $post_types );
	}

	/**
	 * Renders the input field.
	 */
	public function render_field() {
		$slug = LSFW_Plugin::get_slug();
		$base = get_option( 'permalink_structure' ) ? home_url( '/' ) : home_url( '/?' );
		?>
		<div class="lsfw-url">
			<code><?php echo esc_html( $base ); ?></code>
			<input type="text" id="wplogsec_url" name="<?php echo esc_attr( LSFW_Plugin::OPTION_SLUG ); ?>" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
		</div>
		<p class="description">
			<?php esc_html_e( 'Letters, numbers, hyphens and underscores. Leave empty to switch the protection off.', 'login-security-for-wp' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$slug = LSFW_Plugin::get_slug();
		?>
		<div class="wrap lsfw-wrap">
			<h1><span class="dashicons dashicons-shield" aria-hidden="true"></span> <?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( '' !== $slug ) : ?>
				<div class="lsfw-card lsfw-card--active">
					<p><strong><?php esc_html_e( 'Your login URL:', 'login-security-for-wp' ); ?></strong></p>
					<p><a href="<?php echo esc_url( LSFW_Plugin::get_login_url() ); ?>" class="lsfw-login-link"><?php echo esc_html( LSFW_Plugin::get_login_url() ); ?></a></p>
					<p><?php esc_html_e( 'Bookmark this address. wp-login.php and /wp-admin/ now answer logged-out visitors with a 404 page.', 'login-security-for-wp' ); ?></p>
				</div>
			<?php else : ?>
				<div class="lsfw-card">
					<p><?php esc_html_e( 'The protection is off. Choose a login URL below to activate it.', 'login-security-for-wp' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Good to know', 'login-security-for-wp' ); ?></h2>
			<ul class="lsfw-list">
				<li><?php esc_html_e( 'A hidden login reduces automated attacks and log noise. It is not a replacement for strong passwords, two-factor authentication or a limit on login attempts – combine them.', 'login-security-for-wp' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: PHP code */
						esc_html__( 'Locked out? Add %s to wp-config.php or rename the plugin folder via FTP. The normal login works again immediately.', 'login-security-for-wp' ),
						"<code>define( 'LSFW_DISABLE', true );</code>"
					);
					?>
				</li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Shows a hint while no login URL is configured.
	 */
	public function notice_missing_slug() {
		if ( '' !== LSFW_Plugin::get_slug() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Login Security is active but no custom login URL is set yet.', 'login-security-for-wp' ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			esc_html__( 'Set it now', 'login-security-for-wp' )
		);
	}

	/**
	 * Loads the stylesheet on the settings page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'lsfw-admin', LSFW_URL . 'assets/admin.css', array(), LSFW_VERSION );
	}

	/**
	 * Adds a "Settings" link to the plugin list.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
				esc_html__( 'Settings', 'login-security-for-wp' )
			)
		);
		return $links;
	}
}
