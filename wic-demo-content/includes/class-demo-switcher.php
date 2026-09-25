<?php
/**
 * "View as" for sales presentations: an administrator switches to a demo persona in one click
 * and back again. The way back is a signed cookie naming the original administrator, so it
 * works even though the persona itself has no admin rights. Demo users have unusable random
 * passwords; no password is ever created for display or shown.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Demo_Switcher {

	const COOKIE = 'wic_demo_orig';

	public static function init() {
		add_action( 'admin_post_wic_demo_switch', array( __CLASS__, 'switch_to' ) );
		add_action( 'wp_footer', array( __CLASS__, 'panel' ) );
		add_action( 'admin_footer', array( __CLASS__, 'panel' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_logout', array( __CLASS__, 'forget' ) );
	}

	public static function personas() {
		return array(
			'state_admin' => array( __( 'State administrator', 'wic-demo' ), __( 'Whole agency: reports, compliance, settings', 'wic-demo' ) ),
			'supervisor'  => array( __( 'Supervisor', 'wic-demo' ), __( 'Team progress, overdue list, approvals', 'wic-demo' ) ),
			'staff'       => array( __( 'Staff member', 'wic-demo' ), __( 'Courses in progress, one overdue', 'wic-demo' ) ),
			'new_starter' => array( __( 'New starter', 'wic-demo' ), __( 'First week, learning path, mentor', 'wic-demo' ) ),
			'author'      => array( __( 'Content author', 'wic-demo' ), __( 'Build and edit courses in the portal', 'wic-demo' ) ),
			'local_admin' => array( __( 'Local administrator', 'wic-demo' ), __( 'Two clinics only', 'wic-demo' ) ),
			'vendor'      => array( __( 'Vendor (store)', 'wic-demo' ), __( 'Vendor portal and yearly training', 'wic-demo' ) ),
		);
	}

	/** The administrator this browser started from, if the signed cookie is genuine. */
	public static function original() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return 0;
		}
		$parts = explode( '|', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		if ( 2 !== count( $parts ) ) {
			return 0;
		}
		$id = absint( $parts[0] );
		if ( ! $id || ! hash_equals( wp_hash( 'wic-demo-orig-' . $id ), $parts[1] ) || ! user_can( $id, 'manage_options' ) ) {
			return 0;
		}
		return $id;
	}

	public static function can_switch() {
		return current_user_can( 'manage_options' ) || self::original();
	}

	private static function set_cookie( $admin_id ) {
		$value = $admin_id ? $admin_id . '|' . wp_hash( 'wic-demo-orig-' . $admin_id ) : '';
		setcookie( self::COOKIE, $value, $admin_id ? 0 : time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
	}

	public static function forget() {
		if ( ! self::original() || current_user_can( 'manage_options' ) ) {
			self::set_cookie( 0 );
		}
	}

	public static function switch_to() {
		check_admin_referer( 'wic_demo_switch' );
		if ( ! self::can_switch() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-demo' ), '', array( 'response' => 403 ) );
		}
		$orig = current_user_can( 'manage_options' ) && ! self::original() ? get_current_user_id() : self::original();
		$to   = isset( $_GET['to'] ) ? sanitize_key( $_GET['to'] ) : '';
		$map  = WIC_Demo_Seeder::state();
		$map  = isset( $map['personas'] ) ? $map['personas'] : array();

		if ( 'back' === $to ) {
			$target = $orig;
		} elseif ( isset( $map[ $to ] ) ) {
			$target = (int) $map[ $to ];
		} else {
			$target = 0;
		}
		if ( ! $target || ! get_userdata( $target ) ) {
			wp_die( esc_html__( 'That demo persona does not exist. Load the demo content first.', 'wic-demo' ), '', array( 'response' => 404, 'back_link' => true ) );
		}
		// Only demo accounts or the original administrator can be switched to.
		if ( 'back' !== $to && ! get_user_meta( $target, 'wic_demo', true ) ) {
			wp_die( esc_html__( 'Only demo accounts can be viewed this way.', 'wic-demo' ), '', array( 'response' => 403 ) );
		}

		wp_clear_auth_cookie();
		wp_set_current_user( $target );
		wp_set_auth_cookie( $target, false );
		self::set_cookie( 'back' === $to ? 0 : $orig );
		if ( function_exists( 'wic_audit' ) ) {
			wic_audit( 'demo_view_as', 'user', $target, array( 'persona' => $to ) );
		}

		if ( 'back' === $to ) {
			$url = admin_url( 'admin.php?page=wic-demo' );
		} elseif ( 'vendor' === $to && function_exists( 'wic_page_id' ) && wic_page_id( 'vendor' ) ) {
			$url = wic_page_url( 'vendor' );
		} elseif ( 'author' === $to && function_exists( 'wic_portal_url' ) ) {
			$url = wic_portal_url( 'authoring' );
		} else {
			$url = function_exists( 'wic_page_url' ) ? wic_page_url( 'portal' ) : home_url( '/' );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public static function url( $to ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=wic_demo_switch&to=' . rawurlencode( $to ) ), 'wic_demo_switch' );
	}

	public static function assets() {
		if ( self::can_switch() ) {
			wp_enqueue_style( 'wic-demo-panel', WIC_DEMO_URL . 'assets/demo-panel.css', array(), WIC_DEMO_VERSION );
		}
	}

	public static function panel() {
		if ( ! self::can_switch() || ! WIC_Demo_Seeder::is_loaded() ) {
			return;
		}
		$state    = WIC_Demo_Seeder::state();
		$map      = isset( $state['personas'] ) ? $state['personas'] : array();
		$current  = get_current_user_id();
		$is_admin = ! self::original();
		?>
		<details class="wic-demo-panel" <?php echo $is_admin ? '' : 'open'; ?>>
			<summary><span class="wic-demo-panel__dot" aria-hidden="true"></span><?php esc_html_e( 'Demo: view as', 'wic-demo' ); ?></summary>
			<div class="wic-demo-panel__body">
				<p class="wic-demo-panel__warn"><?php esc_html_e( 'Demo mode — never install this plugin on a live site.', 'wic-demo' ); ?></p>
				<ul>
					<?php foreach ( self::personas() as $key => $p ) : ?>
						<?php
						if ( empty( $map[ $key ] ) ) {
							continue;
						}
						$u  = get_userdata( (int) $map[ $key ] );
						$on = (int) $map[ $key ] === (int) $current;
						?>
						<li>
							<a href="<?php echo esc_url( self::url( $key ) ); ?>" <?php echo $on ? 'aria-current="true"' : ''; ?>>
								<strong><?php echo esc_html( $p[0] ); ?></strong>
								<span><?php echo esc_html( ( $u ? $u->display_name . ' · ' : '' ) . $p[1] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( ! $is_admin ) : ?>
					<a class="wic-demo-panel__back" href="<?php echo esc_url( self::url( 'back' ) ); ?>"><?php esc_html_e( 'Switch back to admin', 'wic-demo' ); ?></a>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}
}
