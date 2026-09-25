<?php
/**
 * Training completed elsewhere (#110 outside training logged against a person,
 * #95 completions imported from another system such as TRAIN).
 *
 * A learner logs what they did elsewhere, with the certificate; their supervisor approves it.
 * An administrator can import a CSV of completions in bulk. Approved records join the transcript,
 * so this is the only place anybody has to look.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'external' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  title varchar(255) NOT NULL,
  provider varchar(200) NOT NULL DEFAULT '',
  hours decimal(6,2) NOT NULL DEFAULT 0,
  credit_type varchar(100) NOT NULL DEFAULT '',
  completed_on date NULL,
  certificate_url varchar(500) NOT NULL DEFAULT '',
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  source varchar(20) NOT NULL DEFAULT 'self',
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime NULL,
  review_note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_status (user_id,status)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_External {

	public static function init() {
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_external_submit', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_wic_external_review', array( __CLASS__, 'handle_review' ) );
		add_action( 'admin_post_wic_external_import', array( __CLASS__, 'handle_import' ) );
		add_filter( 'wic_transcript_rows', array( __CLASS__, 'transcript_rows' ), 10, 2 );
		add_action( 'wic_person_record_sections', array( __CLASS__, 'person_section' ), 60, 2 );
	}

	public static function for_user( $user_id, $status = null ) {
		global $wpdb;
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'external' ) . ' WHERE user_id = %d AND status = %s ORDER BY completed_on DESC, id DESC', $user_id, $status ) );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'external' ) . ' WHERE user_id = %d ORDER BY completed_on DESC, id DESC', $user_id ) );
	}

	public static function pending_for( $viewer ) {
		global $wpdb;
		$ids = array_diff( array_map( 'intval', wic_scope_user_ids( $viewer ) ), array( (int) $viewer ) );
		if ( ! $ids ) {
			return array();
		}
		return $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'external' ) . " WHERE status = 'pending' AND user_id IN (" . implode( ',', $ids ) . ') ORDER BY created_at ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
	}

	public static function transcript_rows( $rows, $user_id ) {
		foreach ( self::for_user( $user_id, 'approved' ) as $r ) {
			$rows[] = array(
				'date'        => $r->completed_on,
				'title'       => $r->title,
				'source'      => $r->provider ? sprintf( __( 'Outside training — %s', 'wic-tp' ), $r->provider ) : __( 'Outside training', 'wic-tp' ),
				'hours'       => (float) $r->hours,
				'credit_type' => $r->credit_type,
				'score'       => '',
				'certificate' => $r->certificate_url,
			);
		}
		return $rows;
	}

	public static function views( $views ) {
		$views['outside']        = array(
			'label'    => __( 'Outside training', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'wic_learn',
			'callback' => array( __CLASS__, 'view_mine' ),
			'order'    => 60,
		);
		$views['outside_review'] = array(
			'label'    => __( 'Outside training', 'wic-tp' ),
			'group'    => 'team',
			'cap'      => 'wic_view_team',
			'callback' => array( __CLASS__, 'view_review' ),
			'order'    => 55,
			'badge'    => function ( $uid ) {
				return count( self::pending_for( $uid ) );
			},
		);
		$views['outside_import'] = array(
			'label'    => __( 'Import completions', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_view_all',
			'callback' => array( __CLASS__, 'view_import' ),
			'order'    => 70,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['external_sent']     = __( 'Sent to your supervisor for approval.', 'wic-tp' );
		$m['external_reviewed'] = __( 'Decision recorded.', 'wic-tp' );
		$m['external_imported'] = __( 'Import finished. The summary is below.', 'wic-tp' );
		$m['err_external']      = __( 'Give the training a title, a date and the hours.', 'wic-tp' );
		$m['err_import']        = __( 'Choose a CSV file with a header row.', 'wic-tp' );
		return $m;
	}

	private static function status_badge( $s ) {
		$map = array(
			'pending'  => array( 'in_progress', __( 'Waiting for approval', 'wic-tp' ) ),
			'approved' => array( 'complete', __( 'Approved', 'wic-tp' ) ),
			'rejected' => array( 'overdue', __( 'Not approved', 'wic-tp' ) ),
		);
		$l   = isset( $map[ $s ] ) ? $map[ $s ] : array( 'not_started', $s );
		return '<span class="wic-badge wic-badge--' . esc_attr( $l[0] ) . '">' . esc_html( $l[1] ) . '</span>';
	}

	private static function table( $rows, $review_uid = 0 ) {
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr>
				<?php if ( $review_uid ) : ?><th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th><?php endif; ?>
				<th scope="col"><?php esc_html_e( 'Training', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Provider', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Hours', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Credit', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Certificate', 'wic-tp' ); ?></th><th scope="col"><?php echo $review_uid ? esc_html__( 'Decision', 'wic-tp' ) : esc_html__( 'Status', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<?php if ( $review_uid ) : ?><td><?php $u = get_userdata( $r->user_id ); echo esc_html( $u ? $u->display_name : '' ); ?></td><?php endif; ?>
					<td><?php echo esc_html( $r->title ); ?></td>
					<td><?php echo esc_html( $r->provider ); ?></td>
					<td><?php echo esc_html( $r->completed_on ? mysql2date( get_option( 'date_format' ), $r->completed_on ) : '—' ); ?></td>
					<td><?php echo esc_html( rtrim( rtrim( number_format( (float) $r->hours, 2 ), '0' ), '.' ) ); ?></td>
					<td><?php echo esc_html( $r->credit_type ); ?></td>
					<td><?php echo $r->certificate_url ? '<a href="' . esc_url( $r->certificate_url ) . '" rel="noopener">' . esc_html__( 'View', 'wic-tp' ) . '</a>' : '—'; ?></td>
					<td>
						<?php if ( $review_uid ) : ?>
							<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>" class="wic-inline-form">
								<input type="hidden" name="action" value="wic_external_review">
								<input type="hidden" name="record" value="<?php echo (int) $r->id; ?>">
								<?php wp_nonce_field( 'wic_external_review_' . $r->id ); ?>
								<label class="screen-reader-text" for="wic-ex-note-<?php echo (int) $r->id; ?>"><?php esc_html_e( 'Note', 'wic-tp' ); ?></label>
								<input type="text" id="wic-ex-note-<?php echo (int) $r->id; ?>" name="note" placeholder="<?php esc_attr_e( 'Note (optional)', 'wic-tp' ); ?>">
								<button type="submit" name="decision" value="approved" class="wic-btn wic-btn--small wic-btn--primary"><?php esc_html_e( 'Approve', 'wic-tp' ); ?></button>
								<button type="submit" name="decision" value="rejected" class="wic-btn wic-btn--small"><?php esc_html_e( 'Reject', 'wic-tp' ); ?></button>
							</form>
						<?php else : ?>
							<?php echo self::status_badge( $r->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( $r->review_note ) : ?><div class="wic-meta"><?php echo esc_html( $r->review_note ); ?></div><?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	public static function view_mine( $uid ) {
		$rows = self::for_user( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Training completed elsewhere', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Log training you did outside this portal so it appears on your record. Your supervisor approves it first.', 'wic-tp' ); ?></p>
		<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>" enctype="multipart/form-data" class="wic-form wic-panel">
			<input type="hidden" name="action" value="wic_external_submit">
			<?php wp_nonce_field( 'wic_external_submit' ); ?>
			<div class="wic-field"><label for="wic-ex-title"><?php esc_html_e( 'Training name', 'wic-tp' ); ?></label><input type="text" id="wic-ex-title" name="title" required></div>
			<div class="wic-field"><label for="wic-ex-provider"><?php esc_html_e( 'Provider', 'wic-tp' ); ?></label><input type="text" id="wic-ex-provider" name="provider"></div>
			<div class="wic-field"><label for="wic-ex-date"><?php esc_html_e( 'Date completed', 'wic-tp' ); ?></label><input type="date" id="wic-ex-date" name="completed_on" required max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></div>
			<div class="wic-field"><label for="wic-ex-hours"><?php esc_html_e( 'Hours', 'wic-tp' ); ?></label><input type="number" id="wic-ex-hours" name="hours" min="0" step="0.25" required></div>
			<div class="wic-field"><label for="wic-ex-credit"><?php esc_html_e( 'Credit type (optional)', 'wic-tp' ); ?></label><input type="text" id="wic-ex-credit" name="credit_type" placeholder="<?php esc_attr_e( 'e.g. CPEU, CERP, nursing CE', 'wic-tp' ); ?>"></div>
			<div class="wic-field"><label for="wic-ex-file"><?php esc_html_e( 'Certificate (PDF or image, optional)', 'wic-tp' ); ?></label><input type="file" id="wic-ex-file" name="certificate" accept=".pdf,image/*"></div>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Send for approval', 'wic-tp' ); ?></button>
		</form>
		<h3 class="wic-h3"><?php esc_html_e( 'What you have logged', 'wic-tp' ); ?></h3>
		<?php
		if ( $rows ) {
			self::table( $rows );
		} else {
			echo '<div class="wic-empty"><p>' . esc_html__( 'Nothing logged yet.', 'wic-tp' ) . '</p></div>';
		}
	}

	public static function handle_submit() {
		global $wpdb;
		check_admin_referer( 'wic_external_submit' );
		$uid = get_current_user_id();
		if ( ! current_user_can( 'wic_learn' ) ) {
			WIC_E::deny();
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$date  = isset( $_POST['completed_on'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['completed_on'] ) ? sanitize_text_field( $_POST['completed_on'] ) : '';
		$hours = isset( $_POST['hours'] ) ? max( 0, (float) $_POST['hours'] ) : 0;
		if ( ! $title || ! $date ) {
			WIC_Portal::back( 'outside', 'err_external' );
		}
		$att = 0;
		$url = '';
		if ( ! empty( $_FILES['certificate']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$type = wp_check_filetype( sanitize_file_name( wp_unslash( $_FILES['certificate']['name'] ) ) );
			if ( in_array( $type['ext'], array( 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
				$att = media_handle_upload( 'certificate', 0 );
				if ( is_wp_error( $att ) ) {
					$att = 0;
				} else {
					$url = wp_get_attachment_url( $att );
				}
			}
		}
		$wpdb->insert(
			wic_table( 'external' ),
			array(
				'user_id'         => $uid,
				'title'           => $title,
				'provider'        => isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '',
				'hours'           => $hours,
				'credit_type'     => isset( $_POST['credit_type'] ) ? sanitize_text_field( wp_unslash( $_POST['credit_type'] ) ) : '',
				'completed_on'    => $date,
				'certificate_url' => (string) $url,
				'attachment_id'   => (int) $att,
				'status'          => 'pending',
				'source'          => 'self',
				'created_at'      => wic_now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		wic_audit( 'external_submitted', 'external', $id );
		$sup = wic_reports_to( $uid );
		if ( $sup ) {
			/* translators: 1: name, 2: training */
			WIC_Notify::event( $sup, 'external_waiting', $id, sprintf( __( '%1$s logged outside training "%2$s" for your approval.', 'wic-tp' ), wp_get_current_user()->display_name, $title ), false );
		}
		WIC_Portal::back( 'outside', 'external_sent' );
	}

	public static function view_review( $uid ) {
		$rows = self::pending_for( $uid );
		echo '<h2 class="wic-h">' . esc_html__( 'Outside training waiting for approval', 'wic-tp' ) . '</h2>';
		if ( ! $rows ) {
			echo '<div class="wic-empty wic-empty--good"><p>' . esc_html__( 'Nothing is waiting.', 'wic-tp' ) . '</p></div>';
			return;
		}
		self::table( $rows, $uid );
	}

	public static function handle_review() {
		global $wpdb;
		$id = isset( $_POST['record'] ) ? absint( $_POST['record'] ) : 0;
		check_admin_referer( 'wic_external_review_' . $id );
		$uid = get_current_user_id();
		$r   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'external' ) . ' WHERE id = %d', $id ) );
		if ( ! $r || ! current_user_can( 'wic_view_team' ) || (int) $r->user_id === $uid || ! wic_can_see_user( $uid, $r->user_id ) ) {
			WIC_E::deny();
		}
		$decision = isset( $_POST['decision'] ) && 'approved' === $_POST['decision'] ? 'approved' : 'rejected';
		$note     = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$wpdb->update(
			wic_table( 'external' ),
			array(
				'status'      => $decision,
				'reviewed_by' => $uid,
				'reviewed_at' => wic_now(),
				'review_note' => $note,
			),
			array( 'id' => $id )
		);
		wic_audit( 'external_' . $decision, 'external', $id, array( 'note' => $note ) );
		/* translators: 1: training, 2: decision */
		WIC_Notify::event( $r->user_id, 'external_decided', $id, sprintf( __( 'Your outside training "%1$s" was %2$s.', 'wic-tp' ), $r->title, 'approved' === $decision ? __( 'approved and added to your record', 'wic-tp' ) : __( 'not approved', 'wic-tp' ) ), false );
		WIC_Portal::back( 'outside_review', 'external_reviewed' );
	}

	public static function view_import( $uid ) {
		$summary = get_transient( 'wic_external_import_' . $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Import completions from another system', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'For training recorded somewhere else, such as TRAIN. Upload a CSV with a header row. Recognised columns: email, course title (or title), provider, date, hours, credit type. People are matched by email; imported rows are recorded as approved outside training.', 'wic-tp' ); ?></p>
		<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>" enctype="multipart/form-data" class="wic-form wic-panel">
			<input type="hidden" name="action" value="wic_external_import">
			<?php wp_nonce_field( 'wic_external_import' ); ?>
			<div class="wic-field"><label for="wic-imp-file"><?php esc_html_e( 'CSV file', 'wic-tp' ); ?></label><input type="file" id="wic-imp-file" name="csv" accept=".csv,text/csv" required></div>
			<div class="wic-field"><label for="wic-imp-provider"><?php esc_html_e( 'Provider, if the file has no provider column', 'wic-tp' ); ?></label><input type="text" id="wic-imp-provider" name="provider" placeholder="TRAIN"></div>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Import', 'wic-tp' ); ?></button>
		</form>
		<?php if ( $summary ) : ?>
			<?php delete_transient( 'wic_external_import_' . $uid ); ?>
			<section class="wic-section" aria-labelledby="wic-imp-sum">
				<h3 class="wic-h3" id="wic-imp-sum"><?php esc_html_e( 'Last import', 'wic-tp' ); ?></h3>
				<dl class="wic-dl">
					<dt><?php esc_html_e( 'Imported', 'wic-tp' ); ?></dt><dd><?php echo (int) $summary['imported']; ?></dd>
					<dt><?php esc_html_e( 'Already on record (skipped)', 'wic-tp' ); ?></dt><dd><?php echo (int) $summary['duplicate']; ?></dd>
					<dt><?php esc_html_e( 'Not matched to anyone', 'wic-tp' ); ?></dt><dd><?php echo (int) count( $summary['unmatched'] ); ?></dd>
					<dt><?php esc_html_e( 'Rows with missing data', 'wic-tp' ); ?></dt><dd><?php echo (int) $summary['invalid']; ?></dd>
				</dl>
				<?php if ( $summary['unmatched'] ) : ?>
					<p><?php esc_html_e( 'Emails not found:', 'wic-tp' ); ?> <?php echo esc_html( implode( ', ', array_slice( $summary['unmatched'], 0, 50 ) ) ); ?></p>
				<?php endif; ?>
			</section>
		<?php endif; ?>
		<?php
	}

	public static function handle_import() {
		global $wpdb;
		check_admin_referer( 'wic_external_import' );
		$uid = get_current_user_id();
		if ( ! current_user_can( 'wic_view_all' ) ) {
			WIC_E::deny();
		}
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			WIC_Portal::back( 'outside_import', 'err_import' );
		}
		$fh = fopen( $_FILES['csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $fh ) {
			WIC_Portal::back( 'outside_import', 'err_import' );
		}
		$default_provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$head             = fgetcsv( $fh );
		if ( ! $head ) {
			WIC_Portal::back( 'outside_import', 'err_import' );
		}
		$head = array_map(
			function ( $h ) {
				return trim( strtolower( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) );
			},
			$head
		);
		$col  = function ( $names ) use ( $head ) {
			foreach ( $names as $n ) {
				$i = array_search( $n, $head, true );
				if ( false !== $i ) {
					return $i;
				}
			}
			return -1;
		};
		$c    = array(
			'email'    => $col( array( 'email', 'email address', 'e-mail' ) ),
			'title'    => $col( array( 'course title', 'title', 'course', 'course name' ) ),
			'provider' => $col( array( 'provider', 'source' ) ),
			'date'     => $col( array( 'date', 'completion date', 'completed', 'date completed' ) ),
			'hours'    => $col( array( 'hours', 'credit hours', 'contact hours' ) ),
			'credit'   => $col( array( 'credit type', 'credit', 'ce type' ) ),
		);
		$sum  = array(
			'imported'  => 0,
			'duplicate' => 0,
			'invalid'   => 0,
			'unmatched' => array(),
		);
		if ( $c['email'] < 0 || $c['title'] < 0 || $c['date'] < 0 ) {
			fclose( $fh );
			WIC_Portal::back( 'outside_import', 'err_import' );
		}
		$get = function ( $row, $k ) use ( $c ) {
			return $c[ $k ] >= 0 && isset( $row[ $c[ $k ] ] ) ? trim( (string) $row[ $c[ $k ] ] ) : '';
		};
		$n   = 0;
		while ( ( $row = fgetcsv( $fh ) ) !== false && $n < 5000 ) {
			$n++;
			$email = sanitize_email( $get( $row, 'email' ) );
			$title = sanitize_text_field( $get( $row, 'title' ) );
			$ts    = strtotime( $get( $row, 'date' ) );
			if ( ! $email || ! $title || ! $ts ) {
				$sum['invalid']++;
				continue;
			}
			$user = get_user_by( 'email', $email );
			if ( ! $user || ! wic_can_see_user( $uid, $user->ID ) ) {
				$sum['unmatched'][] = $email;
				continue;
			}
			$date = gmdate( 'Y-m-d', $ts );
			$dup  = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . wic_table( 'external' ) . ' WHERE user_id = %d AND title = %s AND completed_on = %s LIMIT 1', $user->ID, $title, $date ) );
			if ( $dup ) {
				$sum['duplicate']++;
				continue;
			}
			$provider = sanitize_text_field( $get( $row, 'provider' ) );
			$wpdb->insert(
				wic_table( 'external' ),
				array(
					'user_id'      => $user->ID,
					'title'        => $title,
					'provider'     => $provider ? $provider : $default_provider,
					'hours'        => max( 0, (float) $get( $row, 'hours' ) ),
					'credit_type'  => sanitize_text_field( $get( $row, 'credit' ) ),
					'completed_on' => $date,
					'status'       => 'approved',
					'source'       => 'import',
					'reviewed_by'  => $uid,
					'reviewed_at'  => wic_now(),
					'created_at'   => wic_now(),
				)
			);
			$sum['imported']++;
		}
		fclose( $fh );
		$sum['unmatched'] = array_values( array_unique( $sum['unmatched'] ) );
		wic_audit( 'external_import', 'report', 0, array( 'imported' => $sum['imported'], 'unmatched' => count( $sum['unmatched'] ) ) );
		set_transient( 'wic_external_import_' . $uid, $sum, 600 );
		WIC_Portal::back( 'outside_import', 'external_imported' );
	}

	public static function person_section( $user_id, $viewer_id ) {
		$rows = self::for_user( $user_id );
		echo '<section class="wic-section"><h3 class="wic-h3">' . esc_html__( 'Outside training', 'wic-tp' ) . '</h3>';
		if ( $rows ) {
			self::table( $rows );
		} else {
			echo '<p class="wic-meta">' . esc_html__( 'None logged.', 'wic-tp' ) . '</p>';
		}
		echo '</section>';
	}
}

add_action( 'wic_init', array( 'WIC_External', 'init' ) );
