<?php
/**
 * One agency configuration record. Portal, player, certificates and emails all read it,
 * and every colour is a named token — per-agency theming is a setting, not a rewrite.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Agency {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter(
			'option_page_capability_wic_agency',
			function () {
				return 'wic_manage_settings';
			}
		);
	}

	public static function register() {
		register_setting(
			'wic_agency',
			'wic_agency',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$out = array();
		foreach ( wic_agency_defaults() as $key => $default ) {
			$v = isset( $in[ $key ] ) ? wp_unslash( $in[ $key ] ) : '';
			if ( 0 === strpos( $key, 'color_' ) ) {
				$v = sanitize_hex_color( $v );
			} elseif ( 'logo_url' === $key ) {
				$v = esc_url_raw( $v );
			} elseif ( is_int( $default ) ) {
				$v = '' === $v ? ( 0 === $default ? 0 : '' ) : absint( $v );
			} elseif ( is_array( $v ) ) {
				$v = array_map( 'sanitize_text_field', $v );
			} elseif ( 'announcement' === $key ) {
				$v = sanitize_textarea_field( $v );
			} else {
				$v = sanitize_text_field( $v );
			}
			$out[ $key ] = $v;
		}
		wic_audit( 'agency_settings', 'settings', 0 );
		return $out;
	}

	public static function tokens_css() {
		$tokens = array(
			'--wic-primary' => wic_setting( 'color_primary' ),
			'--wic-accent'  => wic_setting( 'color_accent' ),
			'--wic-ink'     => wic_setting( 'color_ink' ),
			'--wic-surface' => wic_setting( 'color_surface' ),
		);
		$css = ':root{';
		foreach ( $tokens as $k => $v ) {
			$v = sanitize_hex_color( $v );
			if ( $v ) {
				$css .= $k . ':' . $v . ';';
			}
		}
		return $css . '}';
	}

	public static function announcement() {
		$text = trim( (string) wic_setting( 'announcement' ) );
		if ( '' === $text ) {
			return '';
		}
		$today = current_time( 'Y-m-d' );
		$from  = wic_setting( 'announce_from' );
		$until = wic_setting( 'announce_until' );
		if ( ( $from && $today < $from ) || ( $until && $today > $until ) ) {
			return '';
		}
		return $text;
	}

	public static function page() {
		if ( ! current_user_can( 'wic_manage_settings' ) ) {
			return;
		}
		$fields = array(
			'name'            => array( __( 'Agency name', 'wic-tp' ), 'text' ),
			'logo_url'        => array( __( 'Logo URL', 'wic-tp' ), 'url' ),
			'color_primary'   => array( __( 'Primary colour', 'wic-tp' ), 'color' ),
			'color_accent'    => array( __( 'Accent colour', 'wic-tp' ), 'color' ),
			'color_ink'       => array( __( 'Text colour', 'wic-tp' ), 'color' ),
			'color_surface'   => array( __( 'Surface colour', 'wic-tp' ), 'color' ),
			'cert_prefix'     => array( __( 'Certificate number prefix', 'wic-tp' ), 'text' ),
			'signatory_name'  => array( __( 'Certificate signatory name', 'wic-tp' ), 'text' ),
			'signatory_title' => array( __( 'Certificate signatory title', 'wic-tp' ), 'text' ),
			'sender_name'     => array( __( 'Email sender name', 'wic-tp' ), 'text' ),
			'pass_mark'       => array( __( 'Default pass mark (%)', 'wic-tp' ), 'number' ),
			'reminder_days'   => array( __( 'Send "due soon" reminder this many days before', 'wic-tp' ), 'number' ),
			'escalation_days' => array( __( 'Escalate unapproved registrations after (days)', 'wic-tp' ), 'number' ),
			'announcement'    => array( __( 'Announcement banner', 'wic-tp' ), 'textarea' ),
			'announce_from'   => array( __( 'Show banner from', 'wic-tp' ), 'date' ),
			'announce_until'  => array( __( 'Show banner until', 'wic-tp' ), 'date' ),
		);
		/**
		 * Modules add settings: $fields['key'] = array( label, type[, help] ) where type is
		 * text|url|number|color|date|textarea|checkbox, or array( 'select', array( value => label ) ).
		 * Declare the default in `wic_agency_defaults` too (int default = numeric/checkbox).
		 * Keys starting with "decision_" are listed under "Open decisions" — off until agreed.
		 */
		$fields = apply_filters( 'wic_agency_fields', $fields );
		$opts   = get_option( 'wic_agency', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agency settings', 'wic-tp' ); ?></h1>
			<p><?php esc_html_e( 'One record drives the portal, the lesson player, certificates and emails.', 'wic-tp' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'wic_agency' ); ?>
				<?php
				$sections = array(
					'main'     => array_filter( $fields, function ( $k ) { return 0 !== strpos( $k, 'decision_' ); }, ARRAY_FILTER_USE_KEY ),
					'decision' => array_filter( $fields, function ( $k ) { return 0 === strpos( $k, 'decision_' ); }, ARRAY_FILTER_USE_KEY ),
				);
				$defaults = wic_agency_defaults();
				?>
				<?php foreach ( $sections as $section => $list ) : ?>
					<?php if ( ! $list ) { continue; } ?>
					<?php if ( 'decision' === $section ) : ?>
						<h2><?php esc_html_e( 'Open decisions', 'wic-tp' ); ?></h2>
						<p class="description"><?php esc_html_e( 'These features are built but switched off, because the functionality list marks them "Decision first". Turn one on only once it has been agreed.', 'wic-tp' ); ?></p>
					<?php endif; ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $list as $key => $f ) : ?>
						<?php
						$type = is_array( $f[1] ) ? $f[1][0] : $f[1];
						$val  = isset( $opts[ $key ] ) && '' !== $opts[ $key ] ? $opts[ $key ] : ( in_array( $type, array( 'color', 'checkbox', 'select' ), true ) ? wic_setting( $key ) : '' );
						?>
						<tr>
							<th><label for="wic_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $f[0] ); ?></label></th>
							<td>
								<?php if ( 'textarea' === $type ) : ?>
									<textarea class="large-text" rows="3" id="wic_<?php echo esc_attr( $key ); ?>" name="wic_agency[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( $val ); ?></textarea>
								<?php elseif ( 'checkbox' === $type ) : ?>
									<input type="hidden" name="wic_agency[<?php echo esc_attr( $key ); ?>]" value="0">
									<input type="checkbox" id="wic_<?php echo esc_attr( $key ); ?>" name="wic_agency[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( (int) $val, 1 ); ?>>
								<?php elseif ( 'select' === $type ) : ?>
									<select id="wic_<?php echo esc_attr( $key ); ?>" name="wic_agency[<?php echo esc_attr( $key ); ?>]">
										<?php foreach ( $f[1][1] as $ov => $ol ) : ?>
											<option value="<?php echo esc_attr( $ov ); ?>" <?php selected( (string) $val, (string) $ov ); ?>><?php echo esc_html( $ol ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="<?php echo esc_attr( $type ); ?>" class="<?php echo 'text' === $type || 'url' === $type ? 'regular-text' : ''; ?>" id="wic_<?php echo esc_attr( $key ); ?>" name="wic_agency[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $val ); ?>" placeholder="<?php echo esc_attr( 'color' === $type || ! isset( $defaults[ $key ] ) ? '' : (string) $defaults[ $key ] ); ?>">
								<?php endif; ?>
								<?php if ( ! empty( $f[2] ) ) : ?>
									<p class="description"><?php echo esc_html( $f[2] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Check that text on the primary colour meets WCAG AA contrast (4.5:1) before saving a new theme.', 'wic-tp' ); ?></p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
