<?php
/**
 * Accessibility deliverables (#149–#153).
 *
 * - Text size control in the portal header (#149).
 * - A manual screen-reader testing record (#150): automated checks cannot do this, so the
 *   test script is listed per screen with a place to record who tested, when, with what.
 * - A published accessibility statement (#151) that reports only what has been recorded,
 *   and says plainly when testing has not yet been done.
 * - A conformance report for procurement (#152): WCAG 2.1 A and AA, every criterion
 *   "Not evaluated" until an evaluator and date are recorded against it.
 * - Requests for Braille, large print or audio (#153), tracked to fulfilment.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'format_requests' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  material varchar(255) NOT NULL DEFAULT '',
  format varchar(30) NOT NULL DEFAULT '',
  contact varchar(255) NOT NULL DEFAULT '',
  needed_by date NULL,
  details text NULL,
  status varchar(20) NOT NULL DEFAULT 'open',
  notes text NULL,
  handled_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY user_id (user_id)
) $c;";
		return $sql;
	},
	10,
	2
);

add_filter(
	'wic_pages',
	function ( $pages ) {
		$pages['a11y_statement'] = array( 'Accessibility Statement', '[wic_accessibility_statement]' );
		return $pages;
	}
);

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['a11y_contact']      = '';
		$d['a11y_method']       = '';
		$d['a11y_known_issues'] = '';
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['a11y_contact']      = array( __( 'Accessibility contact (email or phone)', 'wic-tp' ), 'text', __( 'Shown on the accessibility statement for people who have a problem using the portal.', 'wic-tp' ) );
		$f['a11y_method']       = array( __( 'How accessibility was tested', 'wic-tp' ), 'textarea', __( 'For the accessibility statement. Describe only testing that has actually been done.', 'wic-tp' ) );
		$f['a11y_known_issues'] = array( __( 'Known accessibility issues', 'wic-tp' ), 'textarea', __( 'Listed on the accessibility statement, one per line.', 'wic-tp' ) );
		return $f;
	}
);

class WIC_A11y {

	const TESTS_OPT = 'wic_a11y_tests';
	const ACR_OPT   = 'wic_a11y_acr';
	const FORMATS   = array( 'braille', 'large_print', 'audio', 'easy_read', 'other' );
	const STATUSES  = array( 'open', 'in_progress', 'fulfilled', 'declined' );

	public static function init() {
		add_action( 'wic_portal_header_tools', array( __CLASS__, 'text_size_control' ), 10 );
		add_action( 'wic_portal_enqueue', array( __CLASS__, 'enqueue' ) );
		add_filter( 'wic_portal_style_pages', array( __CLASS__, 'style_pages' ) );
		add_shortcode( 'wic_accessibility_statement', array( __CLASS__, 'statement' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_wic_a11y_tests_save', array( __CLASS__, 'save_tests' ) );
		add_action( 'admin_post_wic_a11y_acr_save', array( __CLASS__, 'save_acr' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_filter( 'wic_email_subjects', array( __CLASS__, 'subjects' ) );
		add_action( 'admin_post_wic_format_request', array( __CLASS__, 'submit_request' ) );
		add_action( 'admin_post_wic_format_update', array( __CLASS__, 'update_request' ) );
	}

	public static function style_pages( $pages ) {
		$pages[] = 'a11y_statement';
		return $pages;
	}

	public static function enqueue() {
		// Styles and the text-size script are auto-enqueued from assets/modules/a11y-*.
	}

	/* ------------------------------------------------------------------ */
	/* Text size (#149)                                                   */
	/* ------------------------------------------------------------------ */

	public static function text_size_control() {
		?>
		<div class="wic-textsize" role="group" aria-label="<?php esc_attr_e( 'Text size', 'wic-tp' ); ?>" data-wic-textsize>
			<button type="button" class="wic-textsize__btn" data-size="sm" aria-pressed="false"><span aria-hidden="true">A−</span><span class="screen-reader-text"><?php esc_html_e( 'Smaller text', 'wic-tp' ); ?></span></button>
			<button type="button" class="wic-textsize__btn" data-size="md" aria-pressed="true"><span aria-hidden="true">A</span><span class="screen-reader-text"><?php esc_html_e( 'Standard text size', 'wic-tp' ); ?></span></button>
			<button type="button" class="wic-textsize__btn" data-size="lg" aria-pressed="false"><span aria-hidden="true">A+</span><span class="screen-reader-text"><?php esc_html_e( 'Larger text', 'wic-tp' ); ?></span></button>
			<button type="button" class="wic-textsize__btn" data-size="xl" aria-pressed="false"><span aria-hidden="true">A++</span><span class="screen-reader-text"><?php esc_html_e( 'Largest text', 'wic-tp' ); ?></span></button>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Manual screen-reader testing (#150)                                */
	/* ------------------------------------------------------------------ */

	/** Screens and the steps a person with a screen reader should work through on each. */
	public static function screens() {
		return apply_filters(
			'wic_a11y_screens',
			array(
				'signin'       => array( __( 'Sign-in', 'wic-tp' ), __( 'Reach the form with Tab; each field announces its label; a wrong password error is announced; the "Lost your password" and "Register" links are announced by name.', 'wic-tp' ) ),
				'register'     => array( __( 'Registration', 'wic-tp' ), __( 'Every field announces its label and that it is required; the supervisor list can be operated by keyboard; submitting with a missing field announces the problem.', 'wic-tp' ) ),
				'home'         => array( __( 'Portal home', 'wic-tp' ), __( 'Headings list gives the page structure; section and tab navigation announce the current page; status counts are read with their labels; "Continue" links name the course.', 'wic-tp' ) ),
				'training'     => array( __( 'My training', 'wic-tp' ), __( 'Filter buttons announce pressed/not pressed; the number of courses shown is announced after filtering; each card\'s status is read as words.', 'wic-tp' ) ),
				'player_nav'   => array( __( 'Lesson player — navigation', 'wic-tp' ), __( 'Skip link works; focus moves to the slide heading on each change; outline items announce seen/current; Back/Next and keyboard shortcuts work; progress is announced.', 'wic-tp' ) ),
				'player_layer' => array( __( 'Lesson player — layers and images', 'wic-tp' ), __( 'Layer buttons announce expanded/collapsed; revealed content is reachable; images announce their alt text; the lightbox traps and returns focus and closes with Escape.', 'wic-tp' ) ),
				'player_q'     => array( __( 'Lesson player — questions', 'wic-tp' ), __( 'Each question type (multiple choice, multiple response, true/false, sorting, matching) can be answered by keyboard alone; feedback is announced; attempts left are announced.', 'wic-tp' ) ),
				'player_audio' => array( __( 'Lesson player — narration and transcript', 'wic-tp' ), __( 'Audio controls and speed are labelled; K toggles narration; the transcript tab is reachable and reads the narration text.', 'wic-tp' ) ),
				'certificate'  => array( __( 'Certificate and verification', 'wic-tp' ), __( 'Certificate content reads in a sensible order; the verify page announces valid, expired or revoked.', 'wic-tp' ) ),
				'refreshers'   => array( __( 'Refreshers', 'wic-tp' ), __( 'Every question is a labelled group; sorting and matching selects announce their item; the result is announced and each answer is read with correct/not quite.', 'wic-tp' ) ),
				'team'         => array( __( 'Team, overdue and approvals', 'wic-tp' ), __( 'Tables have headers; sortable columns announce their sort state; inline forms (extend, approve, reject) have labelled fields; confirmation dialogs are announced.', 'wic-tp' ) ),
				'agency'       => array( __( 'Agency views and reports', 'wic-tp' ), __( 'Training matrix reads row and column headers; status cells are words, not colour; exports are links with clear names.', 'wic-tp' ) ),
			)
		);
	}

	public static function tests() {
		$t = get_option( self::TESTS_OPT, array() );
		return is_array( $t ) ? $t : array();
	}

	/** The most recent recorded test, or null when nothing has been recorded. */
	public static function last_test() {
		$last = null;
		foreach ( self::tests() as $key => $t ) {
			if ( ! empty( $t['date'] ) && ! empty( $t['tester'] ) && ! empty( $t['result'] ) && 'not_tested' !== $t['result'] ) {
				if ( ! $last || $t['date'] > $last['date'] ) {
					$last = $t + array( 'screen' => $key );
				}
			}
		}
		return $last;
	}

	public static function menu() {
		add_submenu_page( 'wic-platform', __( 'Accessibility testing', 'wic-tp' ), __( 'Accessibility testing', 'wic-tp' ), 'wic_manage_settings', 'wic-a11y-tests', array( __CLASS__, 'tests_page' ) );
		add_submenu_page( 'wic-platform', __( 'Conformance report', 'wic-tp' ), __( 'Conformance report', 'wic-tp' ), 'wic_manage_settings', 'wic-a11y-acr', array( __CLASS__, 'acr_page' ) );
	}

	private static function results() {
		return array(
			'not_tested' => __( 'Not tested', 'wic-tp' ),
			'pass'       => __( 'Passed', 'wic-tp' ),
			'issues'     => __( 'Issues found', 'wic-tp' ),
		);
	}

	public static function tests_page() {
		if ( ! current_user_can( 'wic_manage_settings' ) ) {
			return;
		}
		$tests = self::tests();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Accessibility testing with a screen reader', 'wic-tp' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Test record saved.', 'wic-tp' ); ?></p></div><?php endif; ?>
			<p><?php esc_html_e( 'Automated checks cannot tell whether the portal is usable with a screen reader. A person has to work through each screen. Record who tested, when, with which assistive technology (for example NVDA with Firefox, JAWS with Chrome, VoiceOver with Safari on iPhone), and what they found. A row counts as tested only when tester, date and a result are all recorded. The accessibility statement reads from this record.', 'wic-tp' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wic_a11y_tests_save">
				<?php wp_nonce_field( 'wic_a11y_tests_save' ); ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Screen and test script', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Tester', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Date', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Assistive technology', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Result', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Notes', 'wic-tp' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( self::screens() as $key => $s ) : ?>
						<?php $t = isset( $tests[ $key ] ) ? $tests[ $key ] : array(); ?>
						<tr>
							<td style="max-width:420px"><strong><?php echo esc_html( $s[0] ); ?></strong><br><span class="description"><?php echo esc_html( $s[1] ); ?></span></td>
							<td><label class="screen-reader-text" for="t-<?php echo esc_attr( $key ); ?>-tester"><?php esc_html_e( 'Tester', 'wic-tp' ); ?></label><input type="text" id="t-<?php echo esc_attr( $key ); ?>-tester" name="t[<?php echo esc_attr( $key ); ?>][tester]" value="<?php echo esc_attr( isset( $t['tester'] ) ? $t['tester'] : '' ); ?>"></td>
							<td><label class="screen-reader-text" for="t-<?php echo esc_attr( $key ); ?>-date"><?php esc_html_e( 'Date', 'wic-tp' ); ?></label><input type="date" id="t-<?php echo esc_attr( $key ); ?>-date" name="t[<?php echo esc_attr( $key ); ?>][date]" value="<?php echo esc_attr( isset( $t['date'] ) ? $t['date'] : '' ); ?>"></td>
							<td><label class="screen-reader-text" for="t-<?php echo esc_attr( $key ); ?>-at"><?php esc_html_e( 'Assistive technology', 'wic-tp' ); ?></label><input type="text" id="t-<?php echo esc_attr( $key ); ?>-at" name="t[<?php echo esc_attr( $key ); ?>][at]" value="<?php echo esc_attr( isset( $t['at'] ) ? $t['at'] : '' ); ?>"></td>
							<td><label class="screen-reader-text" for="t-<?php echo esc_attr( $key ); ?>-result"><?php esc_html_e( 'Result', 'wic-tp' ); ?></label>
								<select id="t-<?php echo esc_attr( $key ); ?>-result" name="t[<?php echo esc_attr( $key ); ?>][result]">
									<?php foreach ( self::results() as $rv => $rl ) : ?>
										<option value="<?php echo esc_attr( $rv ); ?>" <?php selected( isset( $t['result'] ) ? $t['result'] : 'not_tested', $rv ); ?>><?php echo esc_html( $rl ); ?></option>
									<?php endforeach; ?>
								</select></td>
							<td><label class="screen-reader-text" for="t-<?php echo esc_attr( $key ); ?>-notes"><?php esc_html_e( 'Notes', 'wic-tp' ); ?></label><textarea id="t-<?php echo esc_attr( $key ); ?>-notes" rows="2" name="t[<?php echo esc_attr( $key ); ?>][notes]"><?php echo esc_textarea( isset( $t['notes'] ) ? $t['notes'] : '' ); ?></textarea></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save test record', 'wic-tp' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_tests() {
		check_admin_referer( 'wic_a11y_tests_save' );
		if ( ! current_user_can( 'wic_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$in  = isset( $_POST['t'] ) && is_array( $_POST['t'] ) ? wp_unslash( $_POST['t'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field below.
		$out = array();
		foreach ( array_keys( self::screens() ) as $key ) {
			$t            = isset( $in[ $key ] ) ? (array) $in[ $key ] : array();
			$result       = isset( $t['result'] ) && isset( self::results()[ $t['result'] ] ) ? $t['result'] : 'not_tested';
			$out[ $key ] = array(
				'tester' => isset( $t['tester'] ) ? sanitize_text_field( $t['tester'] ) : '',
				'date'   => isset( $t['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $t['date'] ) ? $t['date'] : '',
				'at'     => isset( $t['at'] ) ? sanitize_text_field( $t['at'] ) : '',
				'result' => $result,
				'notes'  => isset( $t['notes'] ) ? sanitize_textarea_field( $t['notes'] ) : '',
			);
		}
		update_option( self::TESTS_OPT, $out, false );
		wic_audit( 'a11y_tests', 'settings', 0 );
		wp_safe_redirect( admin_url( 'admin.php?page=wic-a11y-tests&saved=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Accessibility statement (#151)                                     */
	/* ------------------------------------------------------------------ */

	public static function statement() {
		WIC_Portal::enqueue();
		$tests   = self::tests();
		$last    = self::last_test();
		$name    = wic_setting( 'name' );
		$contact = trim( (string) wic_setting( 'a11y_contact' ) );
		$method  = trim( (string) wic_setting( 'a11y_method' ) );
		$issues  = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) wic_setting( 'a11y_known_issues' ) ) ) );
		$ats     = array();
		foreach ( $tests as $key => $t ) {
			if ( ! empty( $t['date'] ) && ! empty( $t['tester'] ) && 'not_tested' !== ( isset( $t['result'] ) ? $t['result'] : 'not_tested' ) ) {
				if ( ! empty( $t['at'] ) ) {
					$ats[ $t['at'] ] = true;
				}
				if ( 'issues' === $t['result'] && ! empty( $t['notes'] ) ) {
					$screens  = self::screens();
					$issues[] = ( isset( $screens[ $key ] ) ? $screens[ $key ][0] . ': ' : '' ) . $t['notes'];
				}
			}
		}
		$untested = array();
		foreach ( self::screens() as $key => $s ) {
			if ( empty( $tests[ $key ]['date'] ) || empty( $tests[ $key ]['tester'] ) || 'not_tested' === ( isset( $tests[ $key ]['result'] ) ? $tests[ $key ]['result'] : 'not_tested' ) ) {
				$untested[] = $s[0];
			}
		}
		ob_start();
		?>
		<div class="wic-portal wic-a11y-statement">
			<h2 class="wic-h"><?php echo esc_html( sprintf( __( 'Accessibility statement for the %s training portal', 'wic-tp' ), $name ) ); ?></h2>
			<p><?php echo esc_html( sprintf( __( '%s wants everyone to be able to use its training portal. The portal is being built to meet the Web Content Accessibility Guidelines (WCAG) 2.1 at level AA.', 'wic-tp' ), $name ) ); ?></p>

			<h3 class="wic-h3"><?php esc_html_e( 'How this portal has been tested', 'wic-tp' ); ?></h3>
			<?php if ( ! $last ) : ?>
				<div class="wic-notice wic-notice--warn"><p><?php esc_html_e( 'Testing with assistive technology has not yet been completed. This statement will be updated with the results once it has. Until then we cannot say how fully the portal meets the standard.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
				<dl class="wic-dl">
					<dt><?php esc_html_e( 'Last tested', 'wic-tp' ); ?></dt><dd><?php echo esc_html( mysql2date( get_option( 'date_format' ), $last['date'] ) ); ?></dd>
					<?php if ( $ats ) : ?>
						<dt><?php esc_html_e( 'Assistive technology used', 'wic-tp' ); ?></dt><dd><?php echo esc_html( implode( ', ', array_keys( $ats ) ) ); ?></dd>
					<?php endif; ?>
				</dl>
				<?php if ( $method ) : ?>
					<p><?php echo nl2br( esc_html( $method ) ); ?></p>
				<?php endif; ?>
				<?php if ( $untested ) : ?>
					<p><?php esc_html_e( 'These parts have not yet been tested by a person with a screen reader:', 'wic-tp' ); ?></p>
					<ul><?php foreach ( $untested as $u ) : ?><li><?php echo esc_html( $u ); ?></li><?php endforeach; ?></ul>
				<?php endif; ?>
			<?php endif; ?>

			<h3 class="wic-h3"><?php esc_html_e( 'Known issues', 'wic-tp' ); ?></h3>
			<?php if ( $issues ) : ?>
				<ul><?php foreach ( $issues as $i ) : ?><li><?php echo esc_html( $i ); ?></li><?php endforeach; ?></ul>
			<?php elseif ( $last ) : ?>
				<p><?php esc_html_e( 'No issues have been recorded from testing so far.', 'wic-tp' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Known issues will be listed here once testing has been done.', 'wic-tp' ); ?></p>
			<?php endif; ?>

			<h3 class="wic-h3"><?php esc_html_e( 'Other formats and problems using the portal', 'wic-tp' ); ?></h3>
			<p><?php esc_html_e( 'Training material can be provided in Braille, large print or audio on request.', 'wic-tp' ); ?>
				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( wic_portal_url( 'formats' ) ); ?>"><?php esc_html_e( 'Request another format', 'wic-tp' ); ?></a>.
				<?php endif; ?>
			</p>
			<?php if ( $contact ) : ?>
				<p><?php echo esc_html( sprintf( __( 'If you have a problem using the portal, or cannot find what you need in a format you can use, contact: %s', 'wic-tp' ), $contact ) ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'If you have a problem using the portal, please contact your agency administrator.', 'wic-tp' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Conformance report (#152)                                          */
	/* ------------------------------------------------------------------ */

	/** WCAG 2.1 level A and AA success criteria. */
	public static function criteria() {
		return array(
			array( '1.1.1', 'Non-text Content', 'A' ),
			array( '1.2.1', 'Audio-only and Video-only (Prerecorded)', 'A' ),
			array( '1.2.2', 'Captions (Prerecorded)', 'A' ),
			array( '1.2.3', 'Audio Description or Media Alternative (Prerecorded)', 'A' ),
			array( '1.2.4', 'Captions (Live)', 'AA' ),
			array( '1.2.5', 'Audio Description (Prerecorded)', 'AA' ),
			array( '1.3.1', 'Info and Relationships', 'A' ),
			array( '1.3.2', 'Meaningful Sequence', 'A' ),
			array( '1.3.3', 'Sensory Characteristics', 'A' ),
			array( '1.3.4', 'Orientation', 'AA' ),
			array( '1.3.5', 'Identify Input Purpose', 'AA' ),
			array( '1.4.1', 'Use of Color', 'A' ),
			array( '1.4.2', 'Audio Control', 'A' ),
			array( '1.4.3', 'Contrast (Minimum)', 'AA' ),
			array( '1.4.4', 'Resize Text', 'AA' ),
			array( '1.4.5', 'Images of Text', 'AA' ),
			array( '1.4.10', 'Reflow', 'AA' ),
			array( '1.4.11', 'Non-text Contrast', 'AA' ),
			array( '1.4.12', 'Text Spacing', 'AA' ),
			array( '1.4.13', 'Content on Hover or Focus', 'AA' ),
			array( '2.1.1', 'Keyboard', 'A' ),
			array( '2.1.2', 'No Keyboard Trap', 'A' ),
			array( '2.1.4', 'Character Key Shortcuts', 'A' ),
			array( '2.2.1', 'Timing Adjustable', 'A' ),
			array( '2.2.2', 'Pause, Stop, Hide', 'A' ),
			array( '2.3.1', 'Three Flashes or Below Threshold', 'A' ),
			array( '2.4.1', 'Bypass Blocks', 'A' ),
			array( '2.4.2', 'Page Titled', 'A' ),
			array( '2.4.3', 'Focus Order', 'A' ),
			array( '2.4.4', 'Link Purpose (In Context)', 'A' ),
			array( '2.4.5', 'Multiple Ways', 'AA' ),
			array( '2.4.6', 'Headings and Labels', 'AA' ),
			array( '2.4.7', 'Focus Visible', 'AA' ),
			array( '2.5.1', 'Pointer Gestures', 'A' ),
			array( '2.5.2', 'Pointer Cancellation', 'A' ),
			array( '2.5.3', 'Label in Name', 'A' ),
			array( '2.5.4', 'Motion Actuation', 'A' ),
			array( '3.1.1', 'Language of Page', 'A' ),
			array( '3.1.2', 'Language of Parts', 'AA' ),
			array( '3.2.1', 'On Focus', 'A' ),
			array( '3.2.2', 'On Input', 'A' ),
			array( '3.2.3', 'Consistent Navigation', 'AA' ),
			array( '3.2.4', 'Consistent Identification', 'AA' ),
			array( '3.3.1', 'Error Identification', 'A' ),
			array( '3.3.2', 'Labels or Instructions', 'A' ),
			array( '3.3.3', 'Error Suggestion', 'AA' ),
			array( '3.3.4', 'Error Prevention (Legal, Financial, Data)', 'AA' ),
			array( '4.1.1', 'Parsing', 'A' ),
			array( '4.1.2', 'Name, Role, Value', 'A' ),
			array( '4.1.3', 'Status Messages', 'AA' ),
		);
	}

	private static function acr_statuses() {
		return array(
			'not_evaluated' => __( 'Not evaluated', 'wic-tp' ),
			'supports'      => __( 'Supports', 'wic-tp' ),
			'partial'       => __( 'Partially supports', 'wic-tp' ),
			'not_supported' => __( 'Does not support', 'wic-tp' ),
			'na'            => __( 'Not applicable', 'wic-tp' ),
		);
	}

	public static function acr() {
		$a = get_option( self::ACR_OPT, array() );
		return is_array( $a ) ? $a + array( 'rows' => array(), 'product' => '', 'report_date' => '' ) : array( 'rows' => array(), 'product' => '', 'report_date' => '' );
	}

	public static function acr_page() {
		if ( ! current_user_can( 'wic_manage_settings' ) ) {
			return;
		}
		$acr      = self::acr();
		$statuses = self::acr_statuses();
		$counts   = array_fill_keys( array_keys( $statuses ), 0 );
		foreach ( self::criteria() as $c ) {
			$s = isset( $acr['rows'][ $c[0] ]['status'] ) ? $acr['rows'][ $c[0] ]['status'] : 'not_evaluated';
			$counts[ $s ]++;
		}
		?>
		<style>@media print{#adminmenumain,#wpadminbar,#wpfooter,.wic-noprint,.notice{display:none!important}#wpcontent{margin:0!important}.wic-acr input,.wic-acr select,.wic-acr textarea{border:0;background:none;appearance:none;-webkit-appearance:none;resize:none}}</style>
		<div class="wrap wic-acr">
			<h1><?php esc_html_e( 'Accessibility Conformance Report — WCAG 2.1 A and AA', 'wic-tp' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Report saved.', 'wic-tp' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['reset'] ) ) : ?><div class="notice notice-warning"><p><?php echo esc_html( sprintf( _n( '%d criterion was set back to "Not evaluated" because it had no evaluator and date.', '%d criteria were set back to "Not evaluated" because they had no evaluator and date.', absint( $_GET['reset'] ), 'wic-tp' ), absint( $_GET['reset'] ) ) ); ?></p></div><?php endif; ?>
			<p class="wic-noprint"><?php esc_html_e( 'One document for procurement. Every criterion stays "Not evaluated" until someone records an evaluation — a status other than "Not evaluated" is only kept when an evaluator and a date are recorded against it. Use the browser\'s Print to save it as PDF.', 'wic-tp' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wic_a11y_acr_save">
				<?php wp_nonce_field( 'wic_a11y_acr_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="acr-product"><?php esc_html_e( 'Product and version', 'wic-tp' ); ?></label></th><td><input type="text" class="regular-text" id="acr-product" name="product" value="<?php echo esc_attr( $acr['product'] ? $acr['product'] : wic_setting( 'name' ) . ' training portal ' . WIC_TP_VERSION ); ?>"></td></tr>
					<tr><th><label for="acr-date"><?php esc_html_e( 'Report date', 'wic-tp' ); ?></label></th><td><input type="date" id="acr-date" name="report_date" value="<?php echo esc_attr( $acr['report_date'] ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Summary', 'wic-tp' ); ?></th><td>
						<?php foreach ( $statuses as $k => $l ) : ?>
							<?php echo esc_html( $l ); ?>: <strong><?php echo (int) $counts[ $k ]; ?></strong>&nbsp;&nbsp;
						<?php endforeach; ?>
					</td></tr>
				</table>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Criterion', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Level', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Conformance', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Evaluator', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Evaluated on', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Remarks and explanations', 'wic-tp' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( self::criteria() as $c ) : ?>
						<?php
						$r  = isset( $acr['rows'][ $c[0] ] ) ? $acr['rows'][ $c[0] ] : array();
						$id = 'acr-' . str_replace( '.', '-', $c[0] );
						?>
						<tr>
							<td><strong><?php echo esc_html( $c[0] ); ?></strong> <?php echo esc_html( $c[1] ); ?></td>
							<td><?php echo esc_html( $c[2] ); ?></td>
							<td><label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-s"><?php echo esc_html( sprintf( __( 'Conformance for %s', 'wic-tp' ), $c[0] ) ); ?></label>
								<select id="<?php echo esc_attr( $id ); ?>-s" name="rows[<?php echo esc_attr( $c[0] ); ?>][status]">
									<?php foreach ( $statuses as $k => $l ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( isset( $r['status'] ) ? $r['status'] : 'not_evaluated', $k ); ?>><?php echo esc_html( $l ); ?></option>
									<?php endforeach; ?>
								</select></td>
							<td><label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-e"><?php esc_html_e( 'Evaluator', 'wic-tp' ); ?></label><input type="text" id="<?php echo esc_attr( $id ); ?>-e" name="rows[<?php echo esc_attr( $c[0] ); ?>][evaluator]" value="<?php echo esc_attr( isset( $r['evaluator'] ) ? $r['evaluator'] : '' ); ?>"></td>
							<td><label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-d"><?php esc_html_e( 'Evaluated on', 'wic-tp' ); ?></label><input type="date" id="<?php echo esc_attr( $id ); ?>-d" name="rows[<?php echo esc_attr( $c[0] ); ?>][date]" value="<?php echo esc_attr( isset( $r['date'] ) ? $r['date'] : '' ); ?>"></td>
							<td><label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-r"><?php esc_html_e( 'Remarks', 'wic-tp' ); ?></label><textarea id="<?php echo esc_attr( $id ); ?>-r" rows="2" cols="40" name="rows[<?php echo esc_attr( $c[0] ); ?>][remarks]"><?php echo esc_textarea( isset( $r['remarks'] ) ? $r['remarks'] : '' ); ?></textarea></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="wic-noprint"><?php submit_button( __( 'Save report', 'wic-tp' ), 'primary', 'submit', false ); ?> <button type="button" class="button" onclick="window.print()"><?php esc_html_e( 'Print / save as PDF', 'wic-tp' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public static function save_acr() {
		check_admin_referer( 'wic_a11y_acr_save' );
		if ( ! current_user_can( 'wic_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$in    = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field below.
		$rows  = array();
		$reset = 0;
		foreach ( self::criteria() as $c ) {
			$r      = isset( $in[ $c[0] ] ) ? (array) $in[ $c[0] ] : array();
			$status = isset( $r['status'] ) && isset( self::acr_statuses()[ $r['status'] ] ) ? $r['status'] : 'not_evaluated';
			$eval   = isset( $r['evaluator'] ) ? sanitize_text_field( $r['evaluator'] ) : '';
			$date   = isset( $r['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $r['date'] ) ? $r['date'] : '';
			// No status claim without a recorded evaluation.
			if ( 'not_evaluated' !== $status && ( '' === $eval || '' === $date ) ) {
				$status = 'not_evaluated';
				++$reset;
			}
			$rows[ $c[0] ] = array(
				'status'    => $status,
				'evaluator' => $eval,
				'date'      => $date,
				'remarks'   => isset( $r['remarks'] ) ? sanitize_textarea_field( $r['remarks'] ) : '',
			);
		}
		update_option(
			self::ACR_OPT,
			array(
				'product'     => isset( $_POST['product'] ) ? sanitize_text_field( wp_unslash( $_POST['product'] ) ) : '',
				'report_date' => isset( $_POST['report_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['report_date'] ) ? sanitize_text_field( $_POST['report_date'] ) : '',
				'rows'        => $rows,
			),
			false
		);
		wic_audit( 'a11y_acr', 'settings', 0, array( 'reset' => $reset ) );
		wp_safe_redirect( admin_url( 'admin.php?page=wic-a11y-acr&saved=1' . ( $reset ? '&reset=' . $reset : '' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Alternate format requests (#153)                                   */
	/* ------------------------------------------------------------------ */

	private static function format_label( $f ) {
		$labels = array(
			'braille'     => __( 'Braille', 'wic-tp' ),
			'large_print' => __( 'Large print', 'wic-tp' ),
			'audio'       => __( 'Audio', 'wic-tp' ),
			'easy_read'   => __( 'Easy read', 'wic-tp' ),
			'other'       => __( 'Other', 'wic-tp' ),
		);
		return isset( $labels[ $f ] ) ? $labels[ $f ] : $f;
	}

	private static function status_label( $s ) {
		$labels = array(
			'open'        => __( 'Open', 'wic-tp' ),
			'in_progress' => __( 'In progress', 'wic-tp' ),
			'fulfilled'   => __( 'Fulfilled', 'wic-tp' ),
			'declined'    => __( 'Could not be provided', 'wic-tp' ),
		);
		return isset( $labels[ $s ] ) ? $labels[ $s ] : $s;
	}

	public static function open_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wic_table( 'format_requests' ) . " WHERE status IN ('open','in_progress')" );
	}

	public static function views( $views ) {
		$views['formats']         = array(
			'label'    => __( 'Accessible formats', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'read',
			'callback' => array( __CLASS__, 'view_request' ),
			'order'    => 80,
		);
		$views['format_requests'] = array(
			'label'    => __( 'Format requests', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_manage_people',
			'callback' => array( __CLASS__, 'view_requests' ),
			'order'    => 75,
			'badge'    => array( __CLASS__, 'open_count' ),
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['format_requested'] = __( 'Request sent. We will let you know here and by email when it is ready.', 'wic-tp' );
		$m['format_updated']   = __( 'Request updated and the requester notified.', 'wic-tp' );
		$m['err_format']       = __( 'Please say which material you need and in what format.', 'wic-tp' );
		return $m;
	}

	public static function subjects( $s ) {
		$s['format_request'] = __( 'Accessible format request', 'wic-tp' );
		$s['format_status']  = __( 'Your accessible format request', 'wic-tp' );
		return $s;
	}

	public static function view_request( $uid ) {
		global $wpdb;
		$mine = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'format_requests' ) . ' WHERE user_id = %d ORDER BY id DESC', $uid ) );
		$user = wp_get_current_user();
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Training in another format', 'wic-tp' ); ?></h2>
		<p class="wic-meta"><?php esc_html_e( 'Any course, handout or form can be provided in Braille, large print or audio. Tell us what you need and we will arrange it.', 'wic-tp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
			<input type="hidden" name="action" value="wic_format_request">
			<?php wp_nonce_field( 'wic_format_request' ); ?>
			<div class="wic-field"><label for="wic-fr-mat"><?php esc_html_e( 'Which material', 'wic-tp' ); ?></label><input type="text" id="wic-fr-mat" name="material" required placeholder="<?php esc_attr_e( 'Course, document or form name', 'wic-tp' ); ?>"></div>
			<div class="wic-field">
				<label for="wic-fr-fmt"><?php esc_html_e( 'Format', 'wic-tp' ); ?></label>
				<select id="wic-fr-fmt" name="format" required>
					<?php foreach ( self::FORMATS as $f ) : ?>
						<option value="<?php echo esc_attr( $f ); ?>"><?php echo esc_html( self::format_label( $f ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="wic-field"><label for="wic-fr-contact"><?php esc_html_e( 'How to reach you', 'wic-tp' ); ?></label><input type="text" id="wic-fr-contact" name="contact" required value="<?php echo esc_attr( $user->user_email ); ?>" autocomplete="email"></div>
			<div class="wic-field"><label for="wic-fr-by"><?php esc_html_e( 'Needed by (optional)', 'wic-tp' ); ?></label><input type="date" id="wic-fr-by" name="needed_by" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></div>
			<div class="wic-field"><label for="wic-fr-det"><?php esc_html_e( 'Anything else we should know (optional)', 'wic-tp' ); ?></label><textarea id="wic-fr-det" name="details" rows="3"></textarea></div>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Send request', 'wic-tp' ); ?></button>
		</form>
		<?php if ( $mine ) : ?>
			<h3 class="wic-h3"><?php esc_html_e( 'Your requests', 'wic-tp' ); ?></h3>
			<div class="wic-table-wrap">
			<table class="wic-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Material', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Format', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Requested', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Notes', 'wic-tp' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $mine as $r ) : ?>
					<tr><td><?php echo esc_html( $r->material ); ?></td><td><?php echo esc_html( self::format_label( $r->format ) ); ?></td><td><?php echo esc_html( wic_format_date( $r->created_at ) ); ?></td><td><?php echo esc_html( self::status_label( $r->status ) ); ?></td><td><?php echo esc_html( (string) $r->notes ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
		<?php
	}

	public static function submit_request() {
		global $wpdb;
		check_admin_referer( 'wic_format_request' );
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$material = isset( $_POST['material'] ) ? sanitize_text_field( wp_unslash( $_POST['material'] ) ) : '';
		$format   = isset( $_POST['format'] ) && in_array( $_POST['format'], self::FORMATS, true ) ? sanitize_key( $_POST['format'] ) : '';
		$contact  = isset( $_POST['contact'] ) ? sanitize_text_field( wp_unslash( $_POST['contact'] ) ) : '';
		if ( ! $material || ! $format || ! $contact ) {
			WIC_Portal::back( 'formats', 'err_format' );
		}
		$uid = get_current_user_id();
		$wpdb->insert(
			wic_table( 'format_requests' ),
			array(
				'user_id'    => $uid,
				'material'   => $material,
				'format'     => $format,
				'contact'    => $contact,
				'needed_by'  => isset( $_POST['needed_by'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['needed_by'] ) ? sanitize_text_field( $_POST['needed_by'] ) : null,
				'details'    => isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '',
				'status'     => 'open',
				'created_at' => wic_now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		wic_audit( 'format_request', 'format_request', $id, array( 'format' => $format ) );
		$who = wp_get_current_user()->display_name;
		foreach ( get_users( array( 'role' => 'wic_admin', 'fields' => 'ID' ) ) as $admin ) {
			WIC_Notify::event( (int) $admin, 'format_request', $id, sprintf( __( '%1$s asked for "%2$s" in %3$s.', 'wic-tp' ), $who, $material, self::format_label( $format ) ), true );
		}
		WIC_Portal::back( 'formats', 'format_requested' );
	}

	public static function view_requests( $uid ) {
		global $wpdb;
		$show = isset( $_GET['show'] ) && 'all' === $_GET['show'] ? 'all' : 'open';
		$rows = 'all' === $show
			? $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'format_requests' ) . ' ORDER BY id DESC LIMIT 200' )
			: $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'format_requests' ) . " WHERE status IN ('open','in_progress') ORDER BY needed_by IS NULL, needed_by ASC, id ASC" );
		?>
		<div class="wic-toolbar">
			<h2 class="wic-h"><?php esc_html_e( 'Accessible format requests', 'wic-tp' ); ?></h2>
			<a class="wic-btn wic-btn--small" href="<?php echo esc_url( wic_portal_url( 'format_requests', array( 'show' => 'all' === $show ? 'open' : 'all' ) ) ); ?>"><?php echo 'all' === $show ? esc_html__( 'Show open only', 'wic-tp' ) : esc_html__( 'Show all', 'wic-tp' ); ?></a>
		</div>
		<?php if ( ! $rows ) : ?>
			<div class="wic-empty wic-empty--good"><p><?php esc_html_e( 'No requests waiting.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Person', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Material', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Format', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Contact', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Needed by', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Update', 'wic-tp' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php $u = get_userdata( $r->user_id ); ?>
				<tr>
					<td><?php echo esc_html( $u ? $u->display_name : '—' ); ?><br><span class="wic-meta"><?php echo esc_html( wic_format_date( $r->created_at ) ); ?></span></td>
					<td><?php echo esc_html( $r->material ); ?><?php if ( $r->details ) : ?><br><span class="wic-meta"><?php echo esc_html( $r->details ); ?></span><?php endif; ?></td>
					<td><?php echo esc_html( self::format_label( $r->format ) ); ?></td>
					<td><?php echo esc_html( $r->contact ); ?></td>
					<td><?php echo $r->needed_by ? esc_html( mysql2date( get_option( 'date_format' ), $r->needed_by ) ) : '—'; ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
							<input type="hidden" name="action" value="wic_format_update">
							<input type="hidden" name="request" value="<?php echo (int) $r->id; ?>">
							<?php wp_nonce_field( 'wic_format_update_' . $r->id ); ?>
							<label class="screen-reader-text" for="wic-fs-<?php echo (int) $r->id; ?>"><?php esc_html_e( 'Status', 'wic-tp' ); ?></label>
							<select id="wic-fs-<?php echo (int) $r->id; ?>" name="status">
								<?php foreach ( self::STATUSES as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $r->status, $s ); ?>><?php echo esc_html( self::status_label( $s ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<label class="screen-reader-text" for="wic-fn-<?php echo (int) $r->id; ?>"><?php esc_html_e( 'Note to the requester', 'wic-tp' ); ?></label>
							<input type="text" id="wic-fn-<?php echo (int) $r->id; ?>" name="notes" value="<?php echo esc_attr( (string) $r->notes ); ?>" placeholder="<?php esc_attr_e( 'Note to the requester', 'wic-tp' ); ?>">
							<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Save', 'wic-tp' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	public static function update_request() {
		global $wpdb;
		$id = isset( $_POST['request'] ) ? absint( $_POST['request'] ) : 0;
		check_admin_referer( 'wic_format_update_' . $id );
		if ( ! current_user_can( 'wic_manage_people' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'format_requests' ) . ' WHERE id = %d', $id ) );
		if ( ! $r ) {
			WIC_Portal::back( 'format_requests', 'error' );
		}
		$status = isset( $_POST['status'] ) && in_array( $_POST['status'], self::STATUSES, true ) ? sanitize_key( $_POST['status'] ) : $r->status;
		$notes  = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';
		$wpdb->update(
			wic_table( 'format_requests' ),
			array(
				'status'     => $status,
				'notes'      => $notes,
				'handled_by' => get_current_user_id(),
				'updated_at' => wic_now(),
			),
			array( 'id' => $id )
		);
		wic_audit( 'format_request_update', 'format_request', $id, array( 'status' => $status ) );
		if ( $status !== $r->status || $notes !== (string) $r->notes ) {
			$msg = sprintf( __( 'Your request for "%1$s" in %2$s: %3$s.', 'wic-tp' ), $r->material, self::format_label( $r->format ), self::status_label( $status ) );
			if ( $notes ) {
				$msg .= ' ' . $notes;
			}
			WIC_Notify::event( (int) $r->user_id, 'format_status', $id, $msg, true );
		}
		WIC_Portal::back( 'format_requests', 'format_updated' );
	}
}

add_action( 'wic_init', array( 'WIC_A11y', 'init' ) );
