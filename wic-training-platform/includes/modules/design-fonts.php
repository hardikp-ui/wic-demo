<?php
/**
 * Type as an agency setting: a web-font stylesheet URL and the heading and body families.
 * The portal, the lesson player and the certificate all read the same CSS variables
 * (--wic-font-heading, --wic-font-body, --wic-heading-stretch), so an agency's type is
 * a setting, like its colours. Empty settings leave the system font stack in place.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['logo_inverse_url']     = '';
		$d['font_css_url']         = '';
		$d['font_heading']         = '';
		$d['font_body']            = '';
		$d['font_heading_stretch'] = '';
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['logo_inverse_url']     = array( __( 'Logo for dark backgrounds (URL)', 'wic-tp' ), 'url', __( 'Optional. A light or white version of the logo, used on the lesson player bar and the sign-in panel. Without it the main logo is shown on a white chip.', 'wic-tp' ) );
		$f['font_css_url']         = array( __( 'Web font stylesheet URL', 'wic-tp' ), 'url', __( 'Optional. A font stylesheet such as a Google Fonts link. Leave empty to use the system fonts.', 'wic-tp' ) );
		$f['font_heading']         = array( __( 'Heading font family', 'wic-tp' ), 'text', __( 'For example: "Archivo", sans-serif', 'wic-tp' ) );
		$f['font_body']            = array( __( 'Body font family', 'wic-tp' ), 'text', __( 'For example: "Inter", sans-serif', 'wic-tp' ) );
		$f['font_heading_stretch'] = array( __( 'Heading width (%)', 'wic-tp' ), 'text', __( 'For variable fonts with a width axis, e.g. 115. Leave empty for normal width.', 'wic-tp' ) );
		return $f;
	}
);

class WIC_Design_Fonts {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'portal_head' ), 20 );
		add_action( 'wic_player_head', array( __CLASS__, 'output' ) );
		add_action( 'wic_certificate_head', array( __CLASS__, 'output' ) );
	}

	/** A family list may hold names, quotes, commas and hyphens — nothing that can end the rule. */
	public static function family( $value ) {
		$value = trim( preg_replace( '/[^A-Za-z0-9 ,"\'\-]/', '', (string) $value ) );
		return $value;
	}

	public static function css() {
		$vars    = array();
		$heading = self::family( wic_setting( 'font_heading' ) );
		$body    = self::family( wic_setting( 'font_body' ) );
		$stretch = (int) wic_setting( 'font_heading_stretch' );
		if ( $body ) {
			$vars[] = '--wic-font-body:' . $body . ',system-ui,sans-serif';
		}
		if ( $heading ) {
			$vars[] = '--wic-font-heading:' . $heading . ',system-ui,sans-serif';
		}
		if ( $stretch >= 50 && $stretch <= 200 ) {
			$vars[] = '--wic-heading-stretch:' . $stretch . '%';
		}
		return $vars ? ':root{' . implode( ';', $vars ) . '}' : '';
	}

	/** Font link plus variables. Safe to call in any <head>. */
	public static function output() {
		$url = esc_url( wic_setting( 'font_css_url' ) );
		if ( $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( 'fonts.googleapis.com' === $host ) {
				echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
				echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
			}
			echo '<link rel="stylesheet" href="' . $url . '">' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources -- also used in standalone templates.
		}
		$css = self::css();
		if ( $css ) {
			echo '<style id="wic-fonts">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- built from filtered family names.
		}
	}

	/**
	 * Logo markup for a dark surface: the inverse logo when set, otherwise the main logo on a white chip,
	 * otherwise the agency name in words.
	 */
	public static function logo_on_dark( $class = '' ) {
		$name = wic_setting( 'name' );
		if ( wic_setting( 'logo_inverse_url' ) ) {
			return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( wic_setting( 'logo_inverse_url' ) ) . '" alt="' . esc_attr( $name ) . '">';
		}
		if ( wic_setting( 'logo_url' ) ) {
			return '<img class="' . esc_attr( trim( $class . ' is-chip' ) ) . '" src="' . esc_url( wic_setting( 'logo_url' ) ) . '" alt="' . esc_attr( $name ) . '">';
		}
		return '<span class="wic-brand__name">' . esc_html( $name ) . '</span>';
	}

	/** On the site, only where the portal stylesheet is in use. */
	public static function portal_head() {
		if ( wp_style_is( 'wic-portal', 'enqueued' ) || wp_style_is( 'wic-portal', 'done' ) ) {
			self::output();
		}
	}
}

add_action( 'wic_init', array( 'WIC_Design_Fonts', 'init' ) );
