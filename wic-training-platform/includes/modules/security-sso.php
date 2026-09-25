<?php
/**
 * Single sign-on with an agency's own login (#11). DECISION FIRST — off until agreed.
 *
 * Generic OpenID Connect, authorization-code flow with a client secret. The ID token comes
 * straight from the token endpoint over TLS, so issuer, audience, expiry and nonce are checked
 * but the signature is not (OIDC Core 3.1.3.7 allows this for the code flow). People are matched
 * by email; new accounts are only made — as pending, needing approval — if a setting allows it.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['decision_sso']      = 0;
		$d['sso_issuer']        = '';
		$d['sso_client_id']     = '';
		$d['sso_client_secret'] = '';
		$d['sso_button_label']  = '';
		$d['sso_allow_create']  = 0;
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['decision_sso']      = array( __( 'Single sign-on with the agency\'s own login', 'wic-tp' ), 'checkbox', __( 'Agree first: whether the agency requires its own login system, and which one. Needs the four SSO settings below.', 'wic-tp' ) );
		$f['sso_issuer']        = array( __( 'SSO issuer URL (OpenID Connect)', 'wic-tp' ), 'url', sprintf( __( 'Register this redirect URI with the identity provider: %s', 'wic-tp' ), admin_url( 'admin-post.php?action=wic_sso_callback' ) ) );
		$f['sso_client_id']     = array( __( 'SSO client ID', 'wic-tp' ), 'text' );
		$f['sso_client_secret'] = array( __( 'SSO client secret', 'wic-tp' ), 'text' );
		$f['sso_button_label']  = array( __( 'SSO button label', 'wic-tp' ), 'text', __( 'Default: "Sign in with your agency account".', 'wic-tp' ) );
		$f['sso_allow_create']  = array( __( 'SSO: create a pending account for unknown staff', 'wic-tp' ), 'checkbox', __( 'Off = only people who already have an account can use SSO. On = unknown people get a pending account that still needs approval.', 'wic-tp' ) );
		return $f;
	}
);

class WIC_SSO {

	public static function init() {
		add_filter( 'wic_portal_signin', array( __CLASS__, 'button' ), 20 );
		foreach ( array( 'wic_sso_start', 'wic_sso_callback' ) as $a ) {
			add_action( 'admin_post_nopriv_' . $a, array( __CLASS__, substr( $a, 8 ) ) );
			add_action( 'admin_post_' . $a, array( __CLASS__, substr( $a, 8 ) ) );
		}
	}

	public static function enabled() {
		return (int) wic_setting( 'decision_sso' ) && wic_setting( 'sso_issuer' ) && wic_setting( 'sso_client_id' ) && wic_setting( 'sso_client_secret' );
	}

	private static function redirect_uri() {
		return admin_url( 'admin-post.php?action=wic_sso_callback' );
	}

	private static function config() {
		$issuer = untrailingslashit( (string) wic_setting( 'sso_issuer' ) );
		$key    = 'wic_sso_conf_' . md5( $issuer );
		$conf   = get_transient( $key );
		if ( is_array( $conf ) ) {
			return $conf;
		}
		$resp = wp_safe_remote_get( $issuer . '/.well-known/openid-configuration', array( 'timeout' => 15 ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$conf = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $conf['authorization_endpoint'] ) || empty( $conf['token_endpoint'] ) ) {
			return null;
		}
		set_transient( $key, $conf, 12 * HOUR_IN_SECONDS );
		return $conf;
	}

	private static function fail( $code ) {
		wic_audit( 'sso_failed', 'user', 0, array( 'reason' => $code ) );
		wp_safe_redirect( add_query_arg( 'wic_sso', $code, wic_page_url( 'portal' ) ) );
		exit;
	}

	public static function button( $html ) {
		if ( ! self::enabled() || isset( $_GET['wic_2fa'] ) ) {
			return $html;
		}
		$messages = array(
			'noaccount' => __( 'There is no portal account for your agency email. Register first, or ask your administrator.', 'wic-tp' ),
			'pending'   => __( 'Your account is waiting for approval. You will get an email when it is ready.', 'wic-tp' ),
			'inactive'  => __( 'Your portal account is not active. Please contact your agency administrator.', 'wic-tp' ),
			'error'     => __( 'Single sign-on did not complete. Please try again or sign in with your password.', 'wic-tp' ),
		);
		$code  = isset( $_GET['wic_sso'] ) ? sanitize_key( $_GET['wic_sso'] ) : '';
		$label = wic_setting( 'sso_button_label' ) ? wic_setting( 'sso_button_label' ) : __( 'Sign in with your agency account', 'wic-tp' );
		$extra = '<div class="wic-portal wic-sso">';
		if ( isset( $messages[ $code ] ) ) {
			$extra .= '<div class="wic-notice wic-notice--' . ( 'pending' === $code ? 'warn' : 'err' ) . '" role="alert">' . esc_html( $messages[ $code ] ) . '</div>';
		}
		$extra .= '<p><a class="wic-btn wic-btn--primary" href="' . esc_url( admin_url( 'admin-post.php?action=wic_sso_start' ) ) . '">' . esc_html( $label ) . '</a></p>';
		$extra .= '<p class="wic-help">' . esc_html__( 'Or sign in with your portal username and password below.', 'wic-tp' ) . '</p></div>';
		return $extra . $html;
	}

	public static function start() {
		if ( ! self::enabled() ) {
			self::fail( 'error' );
		}
		$conf = self::config();
		if ( ! $conf ) {
			self::fail( 'error' );
		}
		$state = wp_generate_password( 32, false );
		$nonce = wp_generate_password( 32, false );
		set_transient( 'wic_sso_state_' . $state, array( 'nonce' => $nonce ), 10 * MINUTE_IN_SECONDS );
		$url = add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => rawurlencode( wic_setting( 'sso_client_id' ) ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'scope'         => rawurlencode( 'openid email profile' ),
				'state'         => $state,
				'nonce'         => $nonce,
			),
			$conf['authorization_endpoint']
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- external identity provider.
		exit;
	}

	private static function b64url( $s ) {
		return base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) );
	}

	public static function callback() {
		if ( ! self::enabled() ) {
			self::fail( 'error' );
		}
		$state = isset( $_GET['state'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_GET['state'] ) ) : '';
		$saved = $state ? get_transient( 'wic_sso_state_' . $state ) : false;
		delete_transient( 'wic_sso_state_' . $state );
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$conf = self::config();
		if ( ! $saved || ! $code || ! $conf ) {
			self::fail( 'error' );
		}

		$resp = wp_safe_remote_post(
			$conf['token_endpoint'],
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => self::redirect_uri(),
					'client_id'     => wic_setting( 'sso_client_id' ),
					'client_secret' => wic_setting( 'sso_client_secret' ),
				),
			)
		);
		$tok  = is_wp_error( $resp ) ? null : json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $tok['id_token'] ) ) {
			self::fail( 'error' );
		}
		$parts  = explode( '.', $tok['id_token'] );
		$claims = count( $parts ) >= 2 ? json_decode( self::b64url( $parts[1] ), true ) : null;
		$aud    = isset( $claims['aud'] ) ? (array) $claims['aud'] : array();
		if (
			! is_array( $claims )
			|| untrailingslashit( (string) ( isset( $claims['iss'] ) ? $claims['iss'] : '' ) ) !== untrailingslashit( (string) ( isset( $conf['issuer'] ) ? $conf['issuer'] : wic_setting( 'sso_issuer' ) ) )
			|| ! in_array( wic_setting( 'sso_client_id' ), $aud, true )
			|| empty( $claims['exp'] ) || (int) $claims['exp'] < time() - 60
			|| empty( $claims['nonce'] ) || ! hash_equals( $saved['nonce'], (string) $claims['nonce'] )
		) {
			self::fail( 'error' );
		}

		if ( empty( $claims['email'] ) && ! empty( $conf['userinfo_endpoint'] ) && ! empty( $tok['access_token'] ) ) {
			$info = wp_safe_remote_get( $conf['userinfo_endpoint'], array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $tok['access_token'] ) ) );
			$more = is_wp_error( $info ) ? array() : (array) json_decode( wp_remote_retrieve_body( $info ), true );
			$claims = array_merge( $more, $claims );
		}
		$email = isset( $claims['email'] ) ? sanitize_email( $claims['email'] ) : '';
		if ( ! is_email( $email ) || ( isset( $claims['email_verified'] ) && false === $claims['email_verified'] ) ) {
			self::fail( 'error' );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			if ( ! (int) wic_setting( 'sso_allow_create' ) || ! class_exists( 'WIC_People' ) ) {
				self::fail( 'noaccount' );
			}
			$first = isset( $claims['given_name'] ) ? sanitize_text_field( $claims['given_name'] ) : '';
			$last  = isset( $claims['family_name'] ) ? sanitize_text_field( $claims['family_name'] ) : '';
			$id    = WIC_People::create_user( $email, $first ? $first : current( explode( '@', $email ) ), $last, 'wic_learner' );
			if ( ! $id ) {
				self::fail( 'error' );
			}
			update_user_meta( $id, 'wic_status', 'pending' );
			update_user_meta( $id, 'wic_reports_to', 0 );
			wic_audit( 'register', 'user', $id, array( 'via' => 'sso' ) );
			foreach ( WIC_Registration::approvers_for( $id ) as $approver ) {
				WIC_Notify::event( $approver, 'approval_waiting', $id, sprintf( __( '%s signed in with the agency account and is waiting for approval.', 'wic-tp' ), $email ), true );
			}
			self::fail( 'pending' );
		}
		$status = wic_user_status( $user->ID );
		if ( 'pending' === $status ) {
			self::fail( 'pending' );
		}
		if ( 'active' !== $status ) {
			self::fail( 'inactive' );
		}
		wp_set_auth_cookie( $user->ID, false );
		wp_set_current_user( $user->ID );
		wic_audit( 'sso_signin', 'user', $user->ID );
		$to = wic_page_url( 'portal' );
		wp_safe_redirect( apply_filters( 'login_redirect', $to, $to, $user ) );
		exit;
	}
}

add_action( 'wic_init', array( 'WIC_SSO', 'init' ) );
