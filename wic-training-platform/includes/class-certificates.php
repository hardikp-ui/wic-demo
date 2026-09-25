<?php
/**
 * Certificates are snapshots: name, course, score, hours and credit are copied onto
 * the record at issue, so a later name change or course edit never rewrites history.
 * The PDF is never stored — the page is rendered from the record on demand, and the
 * same template serves screen and print.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Certificates {

	const TEMPLATE_VERSION = 1;

	public static function init() {
		add_action( 'wic_course_completed', array( __CLASS__, 'issue' ), 10 );
		add_shortcode( 'wic_verify', array( __CLASS__, 'verify_shortcode' ) );
		add_shortcode( 'wic_certificate', '__return_empty_string' );
		add_action( 'template_redirect', array( __CLASS__, 'render_page' ) );
	}

	public static function issue( $completion ) {
		global $wpdb;
		if ( self::for_completion( $completion->id ) ) {
			return;
		}
		$user   = get_userdata( $completion->user_id );
		$months = (int) get_post_meta( $completion->course_id, '_wic_validity_months', true );

		// Agency prefix plus a sequence — never a global counter, so numbers never clash between states.
		$seq    = (int) get_option( 'wic_cert_seq', 0 ) + 1;
		update_option( 'wic_cert_seq', $seq );
		$number = sprintf( '%s-%s-%05d', strtoupper( sanitize_key( wic_setting( 'cert_prefix' ) ) ), gmdate( 'Y' ), $seq );

		do {
			$code = wic_random_code( 10 );
		} while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . wic_table( 'certificates' ) . ' WHERE verify_code = %s', $code ) ) );

		$wpdb->insert(
			wic_table( 'certificates' ),
			array(
				'completion_id'    => $completion->id,
				'user_id'          => $completion->user_id,
				'course_id'        => $completion->course_id,
				'cert_number'      => $number,
				'verify_code'      => $code,
				'learner_name'     => $user->display_name,
				'course_title'     => get_the_title( $completion->course_id ),
				'score'            => $completion->score,
				'hours'            => WIC_Content::course_hours( $completion->course_id ),
				'credit_type'      => (string) get_post_meta( $completion->course_id, '_wic_credit_type', true ),
				'issued_at'        => $completion->completed_at,
				'expires_at'       => $months > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( $completion->completed_at . ' UTC +' . $months . ' months' ) ) : null,
				'status'           => 'valid',
				'template_version' => self::TEMPLATE_VERSION,
			)
		);
		wic_audit( 'certificate_issue', 'certificate', $wpdb->insert_id, array( 'number' => $number ) );
	}

	public static function for_completion( $completion_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'certificates' ) . ' WHERE completion_id = %d', $completion_id ) );
	}

	public static function by_code( $code ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'certificates' ) . ' WHERE verify_code = %s', strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) ) ) );
	}

	public static function for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'certificates' ) . ' WHERE user_id = %d ORDER BY issued_at DESC', $user_id ) );
	}

	public static function url( $cert ) {
		return wic_page_url( 'certificate', array( 'code' => $cert->verify_code ) );
	}

	public static function verify_url( $cert ) {
		return wic_page_url( 'verify', array( 'code' => $cert->verify_code ) );
	}

	public static function state( $cert ) {
		if ( 'revoked' === $cert->status ) {
			return 'revoked';
		}
		if ( $cert->expires_at && strtotime( $cert->expires_at . ' UTC' ) < time() ) {
			return 'expired';
		}
		return 'valid';
	}

	public static function revoke( $cert_id, $reason ) {
		global $wpdb;
		$wpdb->update( wic_table( 'certificates' ), array( 'status' => 'revoked' ), array( 'id' => $cert_id ) );
		wic_audit( 'certificate_revoke', 'certificate', $cert_id, array( 'reason' => $reason ) );
	}

	/** Public check page: returns only name, course, date and validity. No account needed. */
	public static function verify_shortcode() {
		WIC_Portal::enqueue();
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		ob_start();
		?>
		<div class="wic-portal wic-verify">
			<form method="get" class="wic-form wic-form--inline">
				<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( wic_page_id( 'verify' ) ); ?>">
				<?php endif; ?>
				<div class="wic-field">
					<label for="wic_code"><?php esc_html_e( 'Verification code', 'wic-tp' ); ?></label>
					<input type="text" id="wic_code" name="code" value="<?php echo esc_attr( $code ); ?>" autocomplete="off" spellcheck="false">
				</div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Check', 'wic-tp' ); ?></button>
			</form>
			<?php
			if ( $code ) {
				$cert = self::by_code( $code );
				if ( ! $cert ) {
					echo '<div class="wic-notice wic-notice--err" role="status">' . esc_html__( 'No certificate was found with that code.', 'wic-tp' ) . '</div>';
				} else {
					$state  = self::state( $cert );
					$labels = array(
						'valid'   => array( 'ok', __( 'Valid certificate', 'wic-tp' ) ),
						'expired' => array( 'warn', __( 'This certificate has expired', 'wic-tp' ) ),
						'revoked' => array( 'err', __( 'This certificate has been revoked', 'wic-tp' ) ),
					);
					?>
					<div class="wic-notice wic-notice--<?php echo esc_attr( $labels[ $state ][0] ); ?>" role="status">
						<strong><?php echo esc_html( $labels[ $state ][1] ); ?></strong>
						<dl class="wic-dl">
							<dt><?php esc_html_e( 'Name', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $cert->learner_name ); ?></dd>
							<dt><?php esc_html_e( 'Course', 'wic-tp' ); ?></dt><dd><?php echo esc_html( $cert->course_title ); ?></dd>
							<dt><?php esc_html_e( 'Completed', 'wic-tp' ); ?></dt><dd><?php echo esc_html( wic_format_date( $cert->issued_at ) ); ?></dd>
							<?php if ( $cert->expires_at ) : ?>
								<dt><?php esc_html_e( 'Valid until', 'wic-tp' ); ?></dt><dd><?php echo esc_html( wic_format_date( $cert->expires_at ) ); ?></dd>
							<?php endif; ?>
							<dt><?php esc_html_e( 'Issued by', 'wic-tp' ); ?></dt><dd><?php echo esc_html( wic_setting( 'name' ) ); ?></dd>
							<?php /** Extra public facts, e.g. the store name on a vendor certificate. Output dt/dd pairs, escaped. */ do_action( 'wic_verify_details', $cert ); ?>
						</dl>
					</div>
					<?php
				}
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_page() {
		if ( ! wic_page_id( 'certificate' ) || ! is_page( wic_page_id( 'certificate' ) ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$cert = $code ? self::by_code( $code ) : null;
		if ( ! $cert || ! apply_filters( 'wic_can_view_certificate', wic_can_see_user( get_current_user_id(), $cert->user_id ), $cert, get_current_user_id() ) ) {
			wp_die( esc_html__( 'Certificate not found.', 'wic-tp' ), '', array( 'response' => 404 ) );
		}
		$state = self::state( $cert );
		include WIC_TP_DIR . 'templates/certificate.php';
		exit;
	}
}
