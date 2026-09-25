<?php
/**
 * Signed acknowledgement forms and policies (#99–#103).
 *
 * A form has a body and a version number. A signature records the version it was given
 * against, so publishing a new version asks everyone to sign again while every earlier
 * signature stays on the record. Forms can be required by group, role and agency, and a
 * course can require forms before it completes.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'signatures' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  form_id bigint(20) unsigned NOT NULL,
  form_version int(11) NOT NULL DEFAULT 1,
  form_title varchar(255) NOT NULL DEFAULT '',
  signed_name varchar(200) NOT NULL,
  signed_at datetime NOT NULL,
  ip varchar(64) NOT NULL DEFAULT '',
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY user_form (user_id,form_id),
  KEY form_id (form_id)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_Forms {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_wic_form', array( __CLASS__, 'save_form' ) );
		add_action( 'save_post_wic_course', array( __CLASS__, 'save_course' ) );
		add_filter( 'wic_evaluate_result', array( __CLASS__, 'blockers' ), 10, 4 );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_sign_form', array( __CLASS__, 'handle_sign' ) );
		add_action( 'admin_post_wic_export_signatures', array( __CLASS__, 'export' ) );
		add_action( 'wic_person_record_sections', array( __CLASS__, 'person_section' ), 30, 2 );
		add_action( 'wic_apply_rules', array( __CLASS__, 'notify_required' ) );
	}

	public static function register() {
		WIC_E::register_type( 'wic_form', __( 'Signed forms', 'wic-tp' ), __( 'Form', 'wic-tp' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Records                                                            */
	/* ------------------------------------------------------------------ */

	public static function version( $form_id ) {
		$v = (int) get_post_meta( $form_id, '_wic_form_version', true );
		return $v > 0 ? $v : 1;
	}

	/** Latest signature for the current version, or null. */
	public static function current_signature( $user_id, $form_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'signatures' ) . ' WHERE user_id = %d AND form_id = %d AND form_version = %d ORDER BY id DESC LIMIT 1', $user_id, $form_id, self::version( $form_id ) ) );
	}

	public static function is_signed( $user_id, $form_id ) {
		return (bool) self::current_signature( $user_id, $form_id );
	}

	public static function user_signatures( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'signatures' ) . ' WHERE user_id = %d ORDER BY signed_at DESC, id DESC', $user_id ) );
	}

	/** Forms a course requires before it can complete. */
	public static function course_forms( $course_id ) {
		return array_values( array_filter( array_map( 'intval', (array) get_post_meta( $course_id, '_wic_forms', true ) ) ) );
	}

	/**
	 * Every form this person must sign, keyed by form ID with the reason:
	 * required for their group/role/agency, required by an assigned course, or a step on their path.
	 */
	public static function required_for( $user_id ) {
		$out   = array();
		$forms = get_posts(
			array(
				'post_type'      => 'wic_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);
		foreach ( $forms as $f ) {
			if ( WIC_E::matches( $user_id, get_post_meta( $f->ID, '_wic_form_groups', true ), get_post_meta( $f->ID, '_wic_form_roles', true ), get_post_meta( $f->ID, '_wic_form_agency', true ) ) ) {
				$out[ $f->ID ] = __( 'Required for your role', 'wic-tp' );
			}
		}
		foreach ( WIC_Records::user_assignments( $user_id ) as $a ) {
			foreach ( self::course_forms( $a['course_id'] ) as $fid ) {
				if ( ! isset( $out[ $fid ] ) && 'publish' === get_post_status( $fid ) ) {
					/* translators: %s: course title */
					$out[ $fid ] = sprintf( __( 'Needed to complete "%s"', 'wic-tp' ), $a['title'] );
				}
			}
		}
		return apply_filters( 'wic_required_forms', $out, $user_id );
	}

	public static function outstanding( $user_id ) {
		$out = array();
		foreach ( self::required_for( $user_id ) as $fid => $why ) {
			if ( ! self::is_signed( $user_id, $fid ) ) {
				$out[ $fid ] = $why;
			}
		}
		return $out;
	}

	public static function sign( $user_id, $form_id, $name, $course_id = 0 ) {
		global $wpdb;
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$wpdb->insert(
			wic_table( 'signatures' ),
			array(
				'user_id'      => $user_id,
				'form_id'      => $form_id,
				'form_version' => self::version( $form_id ),
				'form_title'   => get_the_title( $form_id ),
				'signed_name'  => $name,
				'signed_at'    => wic_now(),
				'ip'           => $ip,
				'course_id'    => (int) $course_id,
			)
		);
		wic_audit( 'form_signed', 'form', $form_id, array( 'user' => $user_id, 'version' => self::version( $form_id ) ) );
		do_action( 'wic_form_signed', $user_id, $form_id, self::version( $form_id ) );
	}

	/** A course with unsigned forms does not complete; the player lists what is left. */
	public static function blockers( $result, $user_id, $course_id, $run ) {
		foreach ( self::course_forms( $course_id ) as $fid ) {
			if ( 'publish' === get_post_status( $fid ) && ! self::is_signed( $user_id, $fid ) ) {
				$result['blockers'][] = array(
					/* translators: %s: form title */
					'label' => sprintf( __( 'Sign "%s"', 'wic-tp' ), get_the_title( $fid ) ),
					'url'   => wic_portal_url( 'form_sign', array( 'form' => $fid, 'course' => $course_id ) ),
				);
			}
		}
		return $result;
	}

	/** When rules run (approval, group change), tell the person about newly required forms. */
	public static function notify_required( $user_id ) {
		foreach ( self::outstanding( $user_id ) as $fid => $why ) {
			if ( ! WIC_Notify::has_event( $user_id, 'form_required', $fid ) ) {
				/* translators: %s: form title */
				WIC_Notify::event( $user_id, 'form_required', $fid, sprintf( __( 'Please read and sign "%s" under My learning → Forms.', 'wic-tp' ), get_the_title( $fid ) ), false );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Authoring                                                          */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'wic_form_meta', __( 'Form settings', 'wic-tp' ), array( __CLASS__, 'form_box' ), 'wic_form', 'normal', 'high' );
		add_meta_box( 'wic_course_forms', __( 'Forms to sign before completing', 'wic-tp' ), array( __CLASS__, 'course_box' ), 'wic_course', 'side' );
	}

	public static function form_box( $post ) {
		global $wpdb;
		wp_nonce_field( 'wic_form_meta', 'wic_form_nonce' );
		$n = $post->ID ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM ' . wic_table( 'signatures' ) . ' WHERE form_id = %d AND form_version = %d', $post->ID, self::version( $post->ID ) ) ) : 0;
		?>
		<p><?php esc_html_e( 'The editor above is the text people read before they sign.', 'wic-tp' ); ?></p>
		<p><strong><?php esc_html_e( 'Version', 'wic-tp' ); ?>:</strong> <?php echo (int) self::version( $post->ID ); ?>
			· <?php echo esc_html( sprintf( _n( '%d person has signed this version', '%d people have signed this version', $n, 'wic-tp' ), $n ) ); ?></p>
		<p><label><input type="checkbox" name="wic_form_bump" value="1"> <?php esc_html_e( 'Save as a new version — everyone must sign again. Earlier signatures stay on the record.', 'wic-tp' ); ?></label></p>
		<h4><?php esc_html_e( 'Required for', 'wic-tp' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Which people must sign this form. Leave everything unticked if it is only required by particular courses.', 'wic-tp' ); ?></p>
		<?php
		WIC_E::audience_fields( 'wic_form', get_post_meta( $post->ID, '_wic_form_groups', true ), get_post_meta( $post->ID, '_wic_form_roles', true ), (int) get_post_meta( $post->ID, '_wic_form_agency', true ) );
	}

	public static function save_form( $post_id ) {
		if ( ! WIC_E::can_save( $post_id, 'wic_form_nonce', 'wic_form_meta' ) ) {
			return;
		}
		if ( ! get_post_meta( $post_id, '_wic_form_version', true ) ) {
			update_post_meta( $post_id, '_wic_form_version', 1 );
		} elseif ( ! empty( $_POST['wic_form_bump'] ) ) {
			$v = self::version( $post_id ) + 1;
			update_post_meta( $post_id, '_wic_form_version', $v );
			wic_audit( 'form_version', 'form', $post_id, array( 'version' => $v ) );
		}
		WIC_E::save_audience( $post_id, 'wic_form', '_wic_form' );
	}

	public static function course_box( $post ) {
		wp_nonce_field( 'wic_course_forms', 'wic_course_forms_nonce' );
		$selected = self::course_forms( $post->ID );
		$forms    = WIC_E::options( 'wic_form' );
		if ( ! $forms ) {
			echo '<p>' . esc_html__( 'No published forms yet.', 'wic-tp' ) . '</p>';
			return;
		}
		foreach ( $forms as $id => $title ) {
			echo '<label style="display:block"><input type="checkbox" name="wic_course_forms[]" value="' . (int) $id . '" ' . checked( in_array( (int) $id, $selected, true ), true, false ) . '> ' . esc_html( $title ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'The course only completes once these are signed.', 'wic-tp' ) . '</p>';
	}

	public static function save_course( $post_id ) {
		if ( ! WIC_E::can_save( $post_id, 'wic_course_forms_nonce', 'wic_course_forms' ) ) {
			return;
		}
		$ids = isset( $_POST['wic_course_forms'] ) ? array_values( array_filter( array_map( 'absint', (array) $_POST['wic_course_forms'] ) ) ) : array();
		update_post_meta( $post_id, '_wic_forms', $ids );
	}

	/* ------------------------------------------------------------------ */
	/* Portal                                                             */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['forms']      = array(
			'label'    => __( 'Forms', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'wic_learn',
			'callback' => array( __CLASS__, 'view_forms' ),
			'order'    => 40,
			'badge'    => function ( $uid ) {
				return count( self::outstanding( $uid ) );
			},
		);
		$views['form_sign']  = array(
			'label'    => __( 'Sign a form', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'wic_learn',
			'callback' => array( __CLASS__, 'view_sign' ),
			'hidden'   => true,
		);
		$views['forms_team'] = array(
			'label'    => __( 'Signed forms', 'wic-tp' ),
			'group'    => 'team',
			'cap'      => 'wic_view_team',
			'callback' => array( __CLASS__, 'view_team' ),
			'order'    => 60,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['form_signed']     = __( 'Thank you. Your signature has been recorded.', 'wic-tp' );
		$m['err_form_sign']   = __( 'Type your full name and tick the box to sign.', 'wic-tp' );
		return $m;
	}

	public static function view_forms( $uid ) {
		$required = self::required_for( $uid );
		$history  = self::user_signatures( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Forms to read and sign', 'wic-tp' ); ?></h2>
		<?php if ( ! $required ) : ?>
			<div class="wic-empty wic-empty--good"><p><?php esc_html_e( 'No forms are required of you at the moment.', 'wic-tp' ); ?></p></div>
		<?php else : ?>
			<div class="wic-table-wrap">
			<table class="wic-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Form', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Why', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Version', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'wic-tp' ); ?></span></th></tr></thead>
				<tbody>
				<?php foreach ( $required as $fid => $why ) : ?>
					<?php $sig = self::current_signature( $uid, $fid ); ?>
					<tr>
						<td><?php echo esc_html( get_the_title( $fid ) ); ?></td>
						<td><?php echo esc_html( $why ); ?></td>
						<td><?php echo (int) self::version( $fid ); ?></td>
						<td>
							<?php if ( $sig ) : ?>
								<span class="wic-badge wic-badge--complete"><?php echo esc_html( sprintf( __( 'Signed %s', 'wic-tp' ), wic_format_date( $sig->signed_at ) ) ); ?></span>
							<?php else : ?>
								<span class="wic-badge wic-badge--overdue"><?php esc_html_e( 'Not signed', 'wic-tp' ); ?></span>
							<?php endif; ?>
						</td>
						<td><a class="wic-btn wic-btn--small <?php echo $sig ? '' : 'wic-btn--primary'; ?>" href="<?php echo esc_url( wic_portal_url( 'form_sign', array( 'form' => $fid ) ) ); ?>"><?php echo $sig ? esc_html__( 'Read', 'wic-tp' ) : esc_html__( 'Read and sign', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( get_the_title( $fid ) ); ?></span></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<h3 class="wic-h3"><?php esc_html_e( 'Everything you have signed', 'wic-tp' ); ?></h3>
		<?php if ( ! $history ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'Nothing signed yet.', 'wic-tp' ); ?></p></div>
		<?php else : ?>
			<?php self::history_table( $history ); ?>
		<?php endif; ?>
		<?php
	}

	private static function history_table( $rows ) {
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Form', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Version signed', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Signed as', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Date', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Current?', 'wic-tp' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $s ) : ?>
				<tr>
					<td><?php echo esc_html( $s->form_title ); ?></td>
					<td><?php echo (int) $s->form_version; ?></td>
					<td><?php echo esc_html( $s->signed_name ); ?></td>
					<td><?php echo esc_html( wic_format_date( $s->signed_at, true ) ); ?></td>
					<td><?php echo (int) $s->form_version === self::version( $s->form_id ) ? esc_html__( 'Yes', 'wic-tp' ) : esc_html__( 'No — a newer version exists', 'wic-tp' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	public static function view_sign( $uid ) {
		$fid    = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		$course = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0;
		$form   = $fid ? get_post( $fid ) : null;
		if ( ! $form || 'wic_form' !== $form->post_type || 'publish' !== $form->post_status ) {
			echo '<div class="wic-notice wic-notice--err">' . esc_html__( 'That form is not available.', 'wic-tp' ) . '</div>';
			return;
		}
		$sig = self::current_signature( $uid, $fid );
		?>
		<p><a href="<?php echo esc_url( wic_portal_url( 'forms' ) ); ?>">← <?php esc_html_e( 'All forms', 'wic-tp' ); ?></a></p>
		<h2 class="wic-h"><?php echo esc_html( $form->post_title ); ?></h2>
		<p class="wic-meta"><?php echo esc_html( sprintf( __( 'Version %d', 'wic-tp' ), self::version( $fid ) ) ); ?></p>
		<div class="wic-panel wic-form-body"><?php echo wp_kses_post( wpautop( $form->post_content ) ); ?></div>
		<?php if ( $sig ) : ?>
			<div class="wic-notice wic-notice--ok" role="status"><?php echo esc_html( sprintf( __( 'You signed this version as "%1$s" on %2$s.', 'wic-tp' ), $sig->signed_name, wic_format_date( $sig->signed_at, true ) ) ); ?></div>
			<?php if ( $course ) : ?>
				<p><a class="wic-btn wic-btn--primary" href="<?php echo esc_url( wic_page_url( 'learn', array( 'course' => $course ) ) ); ?>"><?php esc_html_e( 'Back to the course', 'wic-tp' ); ?></a></p>
			<?php endif; ?>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>" class="wic-form wic-panel">
				<input type="hidden" name="action" value="wic_sign_form">
				<input type="hidden" name="form" value="<?php echo (int) $fid; ?>">
				<input type="hidden" name="course" value="<?php echo (int) $course; ?>">
				<?php wp_nonce_field( 'wic_sign_form_' . $fid ); ?>
				<div class="wic-field">
					<label><input type="checkbox" name="agree" value="1" required> <?php esc_html_e( 'I have read and agree to this form.', 'wic-tp' ); ?></label>
				</div>
				<div class="wic-field">
					<label for="wic-sign-name"><?php esc_html_e( 'Type your full name to sign', 'wic-tp' ); ?></label>
					<input type="text" id="wic-sign-name" name="signed_name" required autocomplete="name">
				</div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Sign', 'wic-tp' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	public static function handle_sign() {
		$fid = isset( $_POST['form'] ) ? absint( $_POST['form'] ) : 0;
		check_admin_referer( 'wic_sign_form_' . $fid );
		$uid = get_current_user_id();
		if ( ! $uid || ! current_user_can( 'wic_learn' ) || 'wic_form' !== get_post_type( $fid ) || 'publish' !== get_post_status( $fid ) ) {
			WIC_E::deny();
		}
		$name   = isset( $_POST['signed_name'] ) ? sanitize_text_field( wp_unslash( $_POST['signed_name'] ) ) : '';
		$course = isset( $_POST['course'] ) ? absint( $_POST['course'] ) : 0;
		if ( empty( $_POST['agree'] ) || strlen( trim( $name ) ) < 2 ) {
			WIC_Portal::back( 'form_sign', 'err_form_sign', array( 'form' => $fid, 'course' => $course ) );
		}
		if ( ! self::is_signed( $uid, $fid ) ) {
			self::sign( $uid, $fid, $name, $course );
		}
		WIC_Portal::back( 'form_sign', 'form_signed', array( 'form' => $fid, 'course' => $course ) );
	}

	public static function view_team( $uid ) {
		$team = WIC_E::team( $uid );
		?>
		<div class="wic-toolbar">
			<h2 class="wic-h"><?php esc_html_e( 'Signed forms across your team', 'wic-tp' ); ?></h2>
			<a class="wic-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wic_export_signatures' ), 'wic_export_signatures' ) ); ?>"><?php esc_html_e( 'Export signatures (CSV)', 'wic-tp' ); ?></a>
		</div>
		<?php if ( ! $team ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'Nobody reports to you yet.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table" data-wic-sortable>
			<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Required', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Not signed', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Outstanding forms', 'wic-tp' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $team as $u ) : ?>
				<?php
				$req = self::required_for( $u->ID );
				$out = self::outstanding( $u->ID );
				?>
				<tr>
					<td><?php echo esc_html( $u->display_name ); ?></td>
					<td><?php echo esc_html( wic_user_clinic_name( $u->ID ) ); ?></td>
					<td><?php echo count( $req ); ?></td>
					<td data-sort="<?php echo count( $out ); ?>"><?php echo $out ? '<strong class="wic-late">' . count( $out ) . '</strong>' : '0'; ?></td>
					<td><?php echo $out ? esc_html( implode( ', ', array_map( 'get_the_title', array_keys( $out ) ) ) ) : esc_html__( 'All signed', 'wic-tp' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/** One export holds every signature in scope, every version. */
	public static function export() {
		check_admin_referer( 'wic_export_signatures' );
		if ( ! current_user_can( 'wic_view_team' ) ) {
			WIC_E::deny();
		}
		global $wpdb;
		$ids  = wic_scope_user_ids( get_current_user_id() );
		$rows = array( array( 'Name', 'Email', 'Clinic', 'Form', 'Version signed', 'Current version', 'Signed as', 'Signed at (UTC)', 'Course' ) );
		if ( $ids ) {
			$list = implode( ',', array_map( 'intval', $ids ) );
			$sigs = $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'signatures' ) . " WHERE user_id IN ($list) ORDER BY user_id, signed_at" ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
			foreach ( $sigs as $s ) {
				$u      = get_userdata( $s->user_id );
				$rows[] = array( $u ? $u->display_name : '#' . $s->user_id, $u ? $u->user_email : '', wic_user_clinic_name( $s->user_id ), $s->form_title, $s->form_version, self::version( $s->form_id ), $s->signed_name, $s->signed_at, $s->course_id ? get_the_title( $s->course_id ) : '' );
			}
		}
		wic_audit( 'export_signatures', 'report', 0 );
		wic_send_csv( 'signatures-' . gmdate( 'Y-m-d' ) . '.csv', $rows );
	}

	public static function person_section( $user_id, $viewer_id ) {
		$out  = self::outstanding( $user_id );
		$hist = self::user_signatures( $user_id );
		echo '<section class="wic-section"><h3 class="wic-h3">' . esc_html__( 'Signed forms', 'wic-tp' ) . '</h3>';
		if ( $out ) {
			echo '<p><strong class="wic-late">' . esc_html( sprintf( _n( '%d form not signed:', '%d forms not signed:', count( $out ), 'wic-tp' ), count( $out ) ) ) . '</strong> ' . esc_html( implode( ', ', array_map( 'get_the_title', array_keys( $out ) ) ) ) . '</p>';
		}
		if ( $hist ) {
			self::history_table( $hist );
		} else {
			echo '<p class="wic-meta">' . esc_html__( 'Nothing signed yet.', 'wic-tp' ) . '</p>';
		}
		echo '</section>';
	}
}

add_action( 'wic_init', array( 'WIC_Forms', 'init' ) );
add_action( 'wic_register_types', array( 'WIC_Forms', 'register' ) );
add_action(
	'wic_install',
	function () {
		if ( get_option( 'wic_sample_forms_seeded' ) || ! post_type_exists( 'wic_form' ) ) {
			return;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'wic_form',
				'post_status'  => 'draft',
				'post_title'   => 'Confidentiality acknowledgement (Sample)',
				'post_content' => 'This is a sample form to show how signing works. Replace this text with your agency\'s approved wording before publishing it.',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_wic_form_version', 1 );
		}
		update_option( 'wic_sample_forms_seeded', 1 );
	}
);
