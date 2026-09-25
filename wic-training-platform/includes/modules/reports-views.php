<?php
/**
 * Reports: the transcript, the management-evaluation report, completion by clinic and by
 * course, most-missed questions, where people stop, date-range exports, saved reports and
 * the records-retention review. One engine: every report is a function returning rows,
 * and the screen, the CSV and the PDF all render the same rows.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'saved_reports' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL,
  name varchar(191) NOT NULL,
  type varchar(40) NOT NULL,
  config longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY owner (owner_id)
) $c;";
		return $sql;
	},
	10,
	2
);

add_action(
	'wic_init',
	function () {
		WIC_Reports::init();
		WIC_Saved_Reports::init();
	}
);

class WIC_Reports {

	private static $assignments = array();

	public static function init() {
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_report_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_wic_anonymise', array( __CLASS__, 'anonymise' ) );
		add_action( 'wic_person_record_sections', array( __CLASS__, 'person_section' ), 20, 2 );
	}

	public static function views( $views ) {
		$views['transcript']   = array(
			'label'    => __( 'My transcript', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'wic_learn',
			'callback' => array( __CLASS__, 'view_transcript' ),
			'order'    => 40,
		);
		$views['reports']      = array(
			'label'    => __( 'Reports', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_view_all',
			'callback' => array( __CLASS__, 'view_reports' ),
			'order'    => 30,
		);
		$views['compliance']   = array(
			'label'    => __( 'Compliance report', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_view_all',
			'callback' => array( __CLASS__, 'view_compliance' ),
			'order'    => 40,
		);
		$views['requirements'] = array(
			'label'    => __( 'Requirements', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_view_all',
			'callback' => array( 'WIC_Requirements', 'view_requirements' ),
			'order'    => 50,
		);
		$views['gaps']         = array(
			'label'    => __( 'Certification gaps', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_view_all',
			'callback' => array( 'WIC_Requirements', 'view_gaps' ),
			'order'    => 55,
		);
		$views['records_retention'] = array(
			'label'    => __( 'Records retention', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => function ( $uid ) {
				return user_can( $uid, 'wic_manage_people' ) && (int) wic_setting( 'decision_retention_years' ) > 0;
			},
			'callback' => array( __CLASS__, 'view_retention' ),
			'order'    => 90,
		);
		return $views;
	}

	public static function messages( $m ) {
		return $m + array(
			'anonymised'  => __( 'Account anonymised. Its completion records are kept.', 'wic-tp' ),
			'err_anon'    => __( 'That account cannot be anonymised: it is not closed, not past the retention period, or already anonymised.', 'wic-tp' ),
			'report_saved' => __( 'Report saved. Run it again any time from Saved reports.', 'wic-tp' ),
			'report_renamed' => __( 'Report renamed.', 'wic-tp' ),
			'report_deleted' => __( 'Saved report deleted.', 'wic-tp' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Small shared pieces                                                */
	/* ------------------------------------------------------------------ */

	/** GET forms to the portal need page_id when pretty permalinks are off. */
	public static function page_id_field() {
		if ( ! get_option( 'permalink_structure' ) ) {
			echo '<input type="hidden" name="page_id" value="' . (int) wic_page_id( 'portal' ) . '">';
		}
	}

	public static function clinic_field( $current, $id ) {
		$opts = wic_clinic_options();
		?>
		<div class="wic-field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></label>
			<select id="<?php echo esc_attr( $id ); ?>" name="clinic">
				<option value=""><?php esc_html_e( 'All clinics', 'wic-tp' ); ?></option>
				<?php foreach ( $opts as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $current, (string) $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	public static function date_fields( $from, $to, $prefix, $legend ) {
		?>
		<div class="wic-field">
			<label for="<?php echo esc_attr( $prefix ); ?>-from"><?php echo esc_html( $legend ); ?> — <?php esc_html_e( 'from', 'wic-tp' ); ?></label>
			<input type="date" id="<?php echo esc_attr( $prefix ); ?>-from" name="from" value="<?php echo esc_attr( $from ); ?>">
		</div>
		<div class="wic-field">
			<label for="<?php echo esc_attr( $prefix ); ?>-to"><?php esc_html_e( 'to', 'wic-tp' ); ?></label>
			<input type="date" id="<?php echo esc_attr( $prefix ); ?>-to" name="to" value="<?php echo esc_attr( $to ); ?>">
		</div>
		<?php
	}

	public static function date_arg( $key ) {
		$v = isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	}

	/** Local Y-m-d bounds to GMT datetimes; empty stays empty. */
	public static function bounds( $from, $to ) {
		return array(
			$from ? get_gmt_from_date( $from . ' 00:00:00' ) : '',
			$to ? get_gmt_from_date( $to . ' 23:59:59' ) : '',
		);
	}

	public static function in_range( $mysql_gmt, $from, $to ) {
		list( $f, $t ) = self::bounds( $from, $to );
		return ( ! $f || $mysql_gmt >= $f ) && ( ! $t || $mysql_gmt <= $t );
	}

	public static function export_links( $type, $params ) {
		$base = array_merge( array( 'action' => 'wic_report_export', 'type' => $type ), array_filter( array_map( 'strval', $params ), 'strlen' ) );
		$csv  = wp_nonce_url( add_query_arg( $base + array( 'format' => 'csv' ), admin_url( 'admin-post.php' ) ), 'wic_report_export' );
		$pdf  = wp_nonce_url( add_query_arg( $base + array( 'format' => 'pdf' ), admin_url( 'admin-post.php' ) ), 'wic_report_export' );
		?>
		<p class="wic-toolbar">
			<span>
				<a class="wic-btn wic-btn--small" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Download spreadsheet (CSV)', 'wic-tp' ); ?></a>
				<a class="wic-btn wic-btn--small" href="<?php echo esc_url( $pdf ); ?>"><?php esc_html_e( 'Download PDF', 'wic-tp' ); ?></a>
			</span>
		</p>
		<?php
	}

	public static function person_link( $u ) {
		if ( class_exists( 'WIC_People' ) && method_exists( 'WIC_People', 'person_url' ) ) {
			return '<a href="' . esc_url( WIC_People::person_url( $u->ID ) ) . '">' . esc_html( $u->display_name ) . '</a>';
		}
		if ( wic_can_see_user( get_current_user_id(), $u->ID ) ) {
			return '<a href="' . esc_url( wic_portal_url( 'transcript', array( 'user' => $u->ID ) ) ) . '">' . esc_html( $u->display_name ) . '</a>';
		}
		return esc_html( $u->display_name );
	}

	public static function badge( $status ) {
		return '<span class="wic-badge wic-badge--' . esc_attr( $status ) . '">' . esc_html( wic_status_label( $status ) ) . '</span>';
	}

	/** Described assignments for one person, cached for the request. */
	public static function assignments( $user_id ) {
		if ( ! isset( self::$assignments[ $user_id ] ) ) {
			self::$assignments[ $user_id ] = WIC_Records::user_assignments( $user_id );
		}
		return self::$assignments[ $user_id ];
	}

	public static function clinic_key( $user_id ) {
		$id = wic_user_clinic_id( $user_id );
		return $id ? (string) $id : wic_user_clinic_name( $user_id );
	}

	/* ------------------------------------------------------------------ */
	/* Transcript (#69, #96)                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Every completion a person holds, across runs, oldest last.
	 * Row shape (also for rows added through `wic_transcript_rows`):
	 *   date (GMT mysql), title, kind, score (int|''), hours (float), credits (array of type/hours),
	 *   source, certificate (number or ''), state (valid|expired|revoked|'')
	 */
	public static function transcript_rows( $user_id, $from = '', $to = '' ) {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'completions' ) . ' WHERE user_id = %d ORDER BY completed_at DESC', $user_id ) ) as $c ) {
			if ( ! self::in_range( $c->completed_at, $from, $to ) ) {
				continue;
			}
			$cert    = WIC_Certificates::for_completion( $c->id );
			$credits = WIC_Credits::for_course( $c->course_id );
			$hours   = $cert ? (float) $cert->hours : (float) WIC_Content::course_hours( $c->course_id );
			$rows[]  = array(
				'date'        => $c->completed_at,
				'title'       => $cert ? $cert->course_title : get_the_title( $c->course_id ),
				'kind'        => 'course',
				'version'     => (int) $c->course_version,
				'score'       => (int) $c->score,
				'hours'       => $hours,
				'credits'     => $credits,
				'source'      => isset( $c->source ) ? $c->source : 'online',
				'certificate' => $cert ? $cert->cert_number : '',
				'state'       => $cert ? WIC_Certificates::state( $cert ) : '',
				'cert'        => $cert,
			);
		}
		/** Outside training, classroom records and imports add their rows here. */
		$rows = apply_filters( 'wic_transcript_rows', $rows, $user_id, $from, $to );
		// Rows from other modules may be partial: fill defaults, accept a single credit_type, re-apply the date range.
		$norm = array();
		foreach ( (array) $rows as $r ) {
			$r = array_merge(
				array( 'date' => '', 'title' => '', 'kind' => 'other', 'version' => 0, 'score' => '', 'hours' => 0, 'credits' => array(), 'source' => '', 'certificate' => '', 'state' => '', 'cert' => null ),
				(array) $r
			);
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['date'] ) ) {
				$r['date'] .= ' 12:00:00';
			}
			if ( ! $r['credits'] && ! empty( $r['credit_type'] ) ) {
				$r['credits'] = array( array( 'type' => (string) $r['credit_type'], 'hours' => (float) $r['hours'] ) );
			}
			if ( 'course' !== $r['kind'] && ( $from || $to ) && ! self::in_range( $r['date'], $from, $to ) ) {
				continue;
			}
			$norm[] = $r;
		}
		$rows = $norm;
		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( (string) $b['date'], (string) $a['date'] );
			}
		);
		return $rows;
	}

	public static function credit_totals( $rows ) {
		$totals = array();
		foreach ( $rows as $r ) {
			if ( isset( $r['state'] ) && 'revoked' === $r['state'] ) {
				continue;
			}
			foreach ( isset( $r['credits'] ) ? (array) $r['credits'] : array() as $c ) {
				if ( empty( $c['type'] ) ) {
					continue;
				}
				$totals[ $c['type'] ] = ( isset( $totals[ $c['type'] ] ) ? $totals[ $c['type'] ] : 0 ) + (float) $c['hours'];
			}
		}
		ksort( $totals );
		return $totals;
	}

	public static function source_label( $s ) {
		$labels = array(
			'online'     => __( 'Online course', 'wic-tp' ),
			'attendance' => __( 'Classroom session', 'wic-tp' ),
			'competency' => __( 'Observed competency', 'wic-tp' ),
			'external'   => __( 'Outside training', 'wic-tp' ),
			'test_out'   => __( 'Tested out', 'wic-tp' ),
			'import'     => __( 'Imported', 'wic-tp' ),
		);
		return isset( $labels[ $s ] ) ? $labels[ $s ] : ucfirst( (string) $s );
	}

	private static function fmt_hours( $h ) {
		return rtrim( rtrim( number_format( (float) $h, 2, '.', '' ), '0' ), '.' );
	}

	/** Header + rows for one or more people's transcripts. */
	private static function transcript_table( $user_ids, $from, $to, $with_person ) {
		$head = array( 'Completed', 'Title', 'Type', 'Score %', 'Hours', 'Credits', 'Certificate', 'Certificate status' );
		if ( $with_person ) {
			$head = array_merge( array( 'Name', 'Staff number', 'Clinic' ), $head );
		}
		$out = array();
		foreach ( $user_ids as $id ) {
			$u = get_userdata( $id );
			if ( ! $u ) {
				continue;
			}
			foreach ( self::transcript_rows( $id, $from, $to ) as $r ) {
				$line = array(
					get_date_from_gmt( $r['date'], 'Y-m-d' ),
					$r['title'],
					self::source_label( $r['source'] ),
					'' === $r['score'] || null === $r['score'] ? '' : $r['score'],
					self::fmt_hours( $r['hours'] ),
					WIC_Credits::label( $r['credits'] ),
					$r['certificate'],
					$r['state'] ? ucfirst( $r['state'] ) : '',
				);
				if ( $with_person ) {
					$line = array_merge( array( $u->display_name, get_user_meta( $id, 'wic_staff_number', true ), wic_user_clinic_name( $id ) ), $line );
				}
				$out[] = $line;
			}
		}
		return array( $head, $out );
	}

	public static function view_transcript( $uid ) {
		$person = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : $uid;
		if ( $person !== $uid && ! ( user_can( $uid, 'wic_view_team' ) && wic_can_see_user( $uid, $person ) ) ) {
			$person = $uid;
		}
		$from = self::date_arg( 'from' );
		$to   = self::date_arg( 'to' );
		$user = get_userdata( $person );
		$rows = self::transcript_rows( $person, $from, $to );
		$tot  = self::credit_totals( $rows );
		$hrs  = 0;
		foreach ( $rows as $r ) {
			if ( 'revoked' !== $r['state'] ) {
				$hrs += (float) $r['hours'];
			}
		}
		?>
		<h2 class="wic-h"><?php echo $person === $uid ? esc_html__( 'My transcript', 'wic-tp' ) : esc_html( sprintf( __( 'Transcript: %s', 'wic-tp' ), $user->display_name ) ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Every completion on record, including earlier runs of the same course. Certificates are kept permanently.', 'wic-tp' ); ?></p>
		<form method="get" class="wic-form wic-form--inline" action="<?php echo esc_url( wic_page_url( 'portal' ) ); ?>">
			<input type="hidden" name="view" value="transcript">
			<?php self::page_id_field(); ?>
			<?php if ( $person !== $uid ) : ?><input type="hidden" name="user" value="<?php echo (int) $person; ?>"><?php endif; ?>
			<?php self::date_fields( $from, $to, 'wic-tr', __( 'Completed', 'wic-tp' ) ); ?>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Show', 'wic-tp' ); ?></button>
		</form>
		<?php self::export_links( 'transcript', array( 'who' => 'person', 'user' => $person, 'from' => $from, 'to' => $to ) ); ?>

		<div class="wic-stats">
			<div class="wic-stat"><span class="wic-stat__n"><?php echo count( $rows ); ?></span><span class="wic-stat__l"><?php esc_html_e( 'Completions', 'wic-tp' ); ?></span></div>
			<div class="wic-stat"><span class="wic-stat__n"><?php echo esc_html( self::fmt_hours( $hrs ) ); ?></span><span class="wic-stat__l"><?php esc_html_e( 'Hours', 'wic-tp' ); ?></span></div>
			<?php foreach ( $tot as $type => $h ) : ?>
				<div class="wic-stat"><span class="wic-stat__n"><?php echo esc_html( self::fmt_hours( $h ) ); ?></span><span class="wic-stat__l"><?php echo esc_html( sprintf( __( '%s hours', 'wic-tp' ), $type ) ); ?></span></div>
			<?php endforeach; ?>
		</div>

		<?php if ( ! $rows ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No completions in this period.', 'wic-tp' ); ?></p></div>
		<?php else : ?>
			<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Title', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Score', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Hours', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Credits', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Certificate', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td data-sort="<?php echo esc_attr( $r['date'] ); ?>"><?php echo esc_html( wic_format_date( $r['date'] ) ); ?></td>
						<td><?php echo esc_html( $r['title'] ); ?><?php echo ! empty( $r['version'] ) && $r['version'] > 1 ? ' <span class="wic-tag">' . esc_html( sprintf( __( 'v%d', 'wic-tp' ), $r['version'] ) ) . '</span>' : ''; ?></td>
						<td><?php echo esc_html( self::source_label( $r['source'] ) ); ?></td>
						<td><?php echo '' === $r['score'] || null === $r['score'] ? '—' : (int) $r['score'] . '%'; ?></td>
						<td><?php echo esc_html( self::fmt_hours( $r['hours'] ) ); ?></td>
						<td><?php echo esc_html( WIC_Credits::label( $r['credits'] ) ); ?></td>
						<td>
							<?php if ( ! empty( $r['cert'] ) ) : ?>
								<a href="<?php echo esc_url( WIC_Certificates::url( $r['cert'] ) ); ?>"><?php echo esc_html( $r['certificate'] ); ?></a>
								<?php if ( 'valid' !== $r['state'] ) : ?> <strong class="wic-late"><?php echo esc_html( ucfirst( $r['state'] ) ); ?></strong><?php endif; ?>
							<?php elseif ( $r['certificate'] && wp_http_validate_url( $r['certificate'] ) ) : ?>
								<a href="<?php echo esc_url( $r['certificate'] ); ?>"><?php esc_html_e( 'Certificate file', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $r['title'] ); ?></span></a>
							<?php else : ?>
								<?php echo esc_html( $r['certificate'] ? $r['certificate'] : '—' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<?php if ( user_can( $uid, 'wic_view_team' ) ) : ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel" style="margin-top:1.5rem">
				<h3 class="wic-h3"><?php esc_html_e( 'Export transcripts for a period', 'wic-tp' ); ?></h3>
				<input type="hidden" name="action" value="wic_report_export">
				<input type="hidden" name="type" value="transcript">
				<?php wp_nonce_field( 'wic_report_export', '_wpnonce', false ); ?>
				<div class="wic-field">
					<label for="wic-trx-who"><?php esc_html_e( 'Whose', 'wic-tp' ); ?></label>
					<select id="wic-trx-who" name="who">
						<option value="all"><?php echo user_can( $uid, 'wic_view_all' ) ? esc_html__( 'Everyone', 'wic-tp' ) : esc_html__( 'Everyone on my team', 'wic-tp' ); ?></option>
						<option value="clinic"><?php esc_html_e( 'One clinic (choose below)', 'wic-tp' ); ?></option>
						<option value="person"><?php echo esc_html( sprintf( __( 'Only %s', 'wic-tp' ), $user->display_name ) ); ?></option>
					</select>
					<input type="hidden" name="user" value="<?php echo (int) $person; ?>">
				</div>
				<?php self::clinic_field( '', 'wic-trx-clinic' ); ?>
				<?php self::date_fields( $from, $to, 'wic-trx', __( 'Completed', 'wic-tp' ) ); ?>
				<div class="wic-field">
					<label for="wic-trx-format"><?php esc_html_e( 'Format', 'wic-tp' ); ?></label>
					<select id="wic-trx-format" name="format">
						<option value="csv"><?php esc_html_e( 'Spreadsheet (CSV)', 'wic-tp' ); ?></option>
						<option value="pdf"><?php esc_html_e( 'PDF', 'wic-tp' ); ?></option>
					</select>
				</div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Download', 'wic-tp' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Management evaluation (#87)                                        */
	/* ------------------------------------------------------------------ */

	public static function required_courses() {
		return get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_wic_required',
				'meta_value'     => '1',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/** Every person in scope × every required course, with its status as of now. */
	public static function compliance_rows( $viewer, $clinic, $from, $to, $status_filter = '' ) {
		$rows    = array();
		$courses = self::required_courses();
		foreach ( WIC_Assign::people_in_scope( $viewer, $clinic ) as $u ) {
			$by = array();
			foreach ( self::assignments( $u->ID ) as $a ) {
				$by[ $a['course_id'] ] = $a;
			}
			foreach ( $courses as $c ) {
				$a    = isset( $by[ $c->ID ] ) ? $by[ $c->ID ] : null;
				$comp = $a ? $a['completion'] : WIC_Records::latest_completion( $u->ID, $c->ID );
				if ( $a ) {
					$status = $a['status'];
				} else {
					$status = $comp ? 'complete' : 'never_assigned';
				}
				if ( $comp && ( $from || $to ) && ! self::in_range( $comp->completed_at, $from, $to ) ) {
					continue;
				}
				if ( $status_filter && $status !== $status_filter ) {
					continue;
				}
				$cert   = $comp ? WIC_Certificates::for_completion( $comp->id ) : null;
				$na     = ( $a && 'not_applicable' === $status ) ? WIC_Assign::na_for( $a['assignment_id'] ) : null;
				$rows[] = array(
					'user'        => $u,
					'course'      => $c->post_title,
					'status'      => $status,
					'due'         => $a ? $a['due_at'] : '',
					'completed'   => $comp ? $comp->completed_at : '',
					'score'       => $comp ? (int) $comp->score : '',
					'certificate' => $cert ? $cert->cert_number : '',
					'note'        => $na ? $na->reason : '',
				);
			}
		}
		return $rows;
	}

	private static function compliance_table( $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				$r['user']->display_name,
				get_user_meta( $r['user']->ID, 'wic_staff_number', true ),
				wic_user_clinic_name( $r['user']->ID ),
				$r['course'],
				wic_status_label( $r['status'] ) . ( $r['note'] ? ' (' . $r['note'] . ')' : '' ),
				$r['due'] ? get_date_from_gmt( $r['due'], 'Y-m-d' ) : '',
				$r['completed'] ? get_date_from_gmt( $r['completed'], 'Y-m-d' ) : '',
				'' === $r['score'] ? '' : $r['score'],
				$r['certificate'],
			);
		}
		return array( array( 'Name', 'Staff number', 'Clinic', 'Required course', 'Status', 'Due', 'Completed', 'Score %', 'Certificate' ), $out );
	}

	public static function status_filter_field( $current, $id ) {
		?>
		<div class="wic-field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Status', 'wic-tp' ); ?></label>
			<select id="<?php echo esc_attr( $id ); ?>" name="status">
				<option value=""><?php esc_html_e( 'Any status', 'wic-tp' ); ?></option>
				<?php foreach ( array( 'complete', 'overdue', 'coming_due', 'in_progress', 'not_started', 'never_assigned', 'expired', 'not_applicable' ) as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $current, $s ); ?>><?php echo esc_html( wic_status_label( $s ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	public static function view_compliance( $uid ) {
		$clinic = isset( $_GET['clinic'] ) ? sanitize_text_field( wp_unslash( $_GET['clinic'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$from   = self::date_arg( 'from' );
		$to     = self::date_arg( 'to' );
		$rows   = self::compliance_rows( $uid, $clinic, $from, $to, $status );
		$count  = array_count_values( wp_list_pluck( $rows, 'status' ) );
		$params = array( 'clinic' => $clinic, 'status' => $status, 'from' => $from, 'to' => $to );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Compliance report', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'For a management evaluation: every person, every required course — status, due date, completion date, score and certificate. The date range narrows which completions are shown; people who have not completed are always listed.', 'wic-tp' ); ?></p>
		<form method="get" class="wic-form wic-form--inline" action="<?php echo esc_url( wic_page_url( 'portal' ) ); ?>">
			<input type="hidden" name="view" value="compliance">
			<?php self::page_id_field(); ?>
			<?php self::clinic_field( $clinic, 'wic-cmp-clinic' ); ?>
			<?php self::status_filter_field( $status, 'wic-cmp-status' ); ?>
			<?php self::date_fields( $from, $to, 'wic-cmp', __( 'Completed', 'wic-tp' ) ); ?>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Show', 'wic-tp' ); ?></button>
		</form>
		<?php self::export_links( 'compliance', $params ); ?>
		<?php WIC_Saved_Reports::save_form( 'compliance', $params ); ?>
		<div class="wic-stats">
			<?php foreach ( array( 'complete', 'overdue', 'coming_due', 'in_progress', 'not_started', 'never_assigned' ) as $s ) : ?>
				<div class="wic-stat wic-stat--<?php echo esc_attr( $s ); ?>"><span class="wic-stat__n"><?php echo isset( $count[ $s ] ) ? (int) $count[ $s ] : 0; ?></span><span class="wic-stat__l"><?php echo esc_html( wic_status_label( $s ) ); ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php if ( ! self::required_courses() ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No course is marked as required yet. Tick "Required training" in a course\'s settings.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		if ( ! $rows ) :
			?>
			<div class="wic-empty"><p><?php esc_html_e( 'No rows match these filters.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table" data-wic-sortable>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Required course', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Due', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Score', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Certificate', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo self::person_link( $r['user'] ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( wic_user_clinic_name( $r['user']->ID ) ); ?></td>
					<td><?php echo esc_html( $r['course'] ); ?></td>
					<td data-sort="<?php echo esc_attr( $r['status'] ); ?>"><?php echo self::badge( $r['status'] ); // phpcs:ignore ?><?php echo $r['note'] ? ' <span class="wic-meta">' . esc_html( $r['note'] ) . '</span>' : ''; ?></td>
					<td data-sort="<?php echo esc_attr( (string) $r['due'] ); ?>"><?php echo esc_html( wic_format_date( $r['due'] ) ); ?></td>
					<td data-sort="<?php echo esc_attr( (string) $r['completed'] ); ?>"><?php echo esc_html( wic_format_date( $r['completed'] ) ); ?></td>
					<td><?php echo '' === $r['score'] ? '—' : (int) $r['score'] . '%'; ?></td>
					<td><?php echo $r['certificate'] ? '<code>' . esc_html( $r['certificate'] ) . '</code>' : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Completion by clinic and course, most missed, drop-off (#92–#94)   */
	/* ------------------------------------------------------------------ */

	public static function by_clinic( $viewer, $clinic = '' ) {
		$out = array();
		foreach ( WIC_Assign::people_in_scope( $viewer, $clinic ) as $u ) {
			$key = self::clinic_key( $u->ID );
			$lab = '' === $key ? __( '(no clinic)', 'wic-tp' ) : WIC_Assign::clinic_label( $key );
			if ( ! isset( $out[ $lab ] ) ) {
				$out[ $lab ] = array( 'people' => 0, 'assigned' => 0, 'complete' => 0, 'overdue' => 0, 'coming_due' => 0, 'in_progress' => 0, 'not_started' => 0, 'expired' => 0, 'not_applicable' => 0 );
			}
			$out[ $lab ]['people']++;
			foreach ( self::assignments( $u->ID ) as $a ) {
				if ( 'not_applicable' !== $a['status'] ) {
					$out[ $lab ]['assigned']++;
				}
				if ( isset( $out[ $lab ][ $a['status'] ] ) ) {
					$out[ $lab ][ $a['status'] ]++;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	public static function by_course( $viewer, $clinic = '' ) {
		$people = WIC_Assign::people_in_scope( $viewer, $clinic );
		$out    = array();
		foreach ( WIC_Assign::course_options() as $c ) {
			$out[ $c->ID ] = array( 'title' => $c->post_title, 'assigned' => 0, 'complete' => 0, 'overdue' => 0, 'coming_due' => 0, 'in_progress' => 0, 'not_started' => 0, 'expired' => 0, 'not_applicable' => 0, 'never_assigned' => 0 );
		}
		foreach ( $people as $u ) {
			$has = array();
			foreach ( self::assignments( $u->ID ) as $a ) {
				if ( ! isset( $out[ $a['course_id'] ] ) ) {
					continue;
				}
				$has[ $a['course_id'] ] = true;
				if ( 'not_applicable' !== $a['status'] ) {
					$out[ $a['course_id'] ]['assigned']++;
				}
				if ( isset( $out[ $a['course_id'] ][ $a['status'] ] ) ) {
					$out[ $a['course_id'] ][ $a['status'] ]++;
				}
			}
			foreach ( array_keys( $out ) as $cid ) {
				if ( empty( $has[ $cid ] ) ) {
					$out[ $cid ]['never_assigned']++;
				}
			}
		}
		return $out;
	}

	public static function most_missed( $viewer, $clinic = '', $limit = 15 ) {
		global $wpdb;
		$ids = wp_list_pluck( WIC_Assign::people_in_scope( $viewer, $clinic ), 'ID' );
		if ( ! $ids ) {
			return array();
		}
		return $wpdb->get_results(
			'SELECT slide_id, course_id, COUNT(*) AS answers, SUM(correct = 0) AS wrong, COUNT(DISTINCT user_id) AS people FROM ' . wic_table( 'attempts' )
			. ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') GROUP BY slide_id, course_id HAVING answers > 0 ORDER BY (SUM(correct = 0) / COUNT(*)) DESC, answers DESC LIMIT ' . (int) $limit
		);
	}

	/**
	 * Where people stop: for each slide in order, how many runs reached it and how many
	 * unfinished runs are sitting on it now.
	 */
	public static function dropoff( $viewer, $course_id, $clinic = '' ) {
		global $wpdb;
		$ids  = array_map( 'intval', wp_list_pluck( WIC_Assign::people_in_scope( $viewer, $clinic ), 'ID' ) );
		$flat = WIC_Content::flat_slides( $course_id );
		if ( ! $ids || ! $flat ) {
			return array( 'runs' => 0, 'slides' => array() );
		}
		$in   = implode( ',', $ids );
		$pos  = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, run, current_slide, seen FROM ' . wic_table( 'positions' ) . " WHERE course_id = %d AND user_id IN ($in)", $course_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$done = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, run FROM ' . wic_table( 'completions' ) . " WHERE course_id = %d AND user_id IN ($in)", $course_id ) ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$done[ $c->user_id . ':' . $c->run ] = true;
		}
		$slides = array();
		foreach ( array_keys( $flat ) as $i => $sid ) {
			$slides[ $sid ] = array( 'n' => $i + 1, 'title' => get_the_title( $sid ), 'reached' => 0, 'stopped' => 0 );
		}
		$runs = 0;
		foreach ( $pos as $p ) {
			$seen = json_decode( (string) $p->seen, true );
			if ( ! is_array( $seen ) || ! $seen ) {
				continue;
			}
			$runs++;
			foreach ( $seen as $sid ) {
				if ( isset( $slides[ (int) $sid ] ) ) {
					$slides[ (int) $sid ]['reached']++;
				}
			}
			if ( empty( $done[ $p->user_id . ':' . $p->run ] ) && isset( $slides[ (int) $p->current_slide ] ) ) {
				$slides[ (int) $p->current_slide ]['stopped']++;
			}
		}
		return array( 'runs' => $runs, 'slides' => $slides );
	}

	private static function pct( $n, $d ) {
		return $d ? (int) round( $n / $d * 100 ) . '%' : '—';
	}

	public static function view_reports( $uid ) {
		$clinic  = isset( $_GET['clinic'] ) ? sanitize_text_field( wp_unslash( $_GET['clinic'] ) ) : '';
		$course  = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0;
		$courses = WIC_Assign::course_options();
		if ( ! $course && $courses ) {
			$course = (int) $courses[0]->ID;
		}
		$clinics = self::by_clinic( $uid, $clinic );
		$bycrs   = self::by_course( $uid, $clinic );
		$missed  = self::most_missed( $uid, $clinic );
		$drop    = self::dropoff( $uid, $course, $clinic );
		$params  = array( 'clinic' => $clinic, 'course' => $course );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Reports', 'wic-tp' ); ?></h2>
		<form method="get" class="wic-form wic-form--inline" action="<?php echo esc_url( wic_page_url( 'portal' ) ); ?>">
			<input type="hidden" name="view" value="reports">
			<?php self::page_id_field(); ?>
			<?php self::clinic_field( $clinic, 'wic-rep-clinic' ); ?>
			<div class="wic-field">
				<label for="wic-rep-course"><?php esc_html_e( 'Course for "where people stop"', 'wic-tp' ); ?></label>
				<select id="wic-rep-course" name="course">
					<?php foreach ( $courses as $c ) : ?>
						<option value="<?php echo (int) $c->ID; ?>" <?php selected( $course, $c->ID ); ?>><?php echo esc_html( $c->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Show', 'wic-tp' ); ?></button>
		</form>
		<?php self::export_links( 'reports', $params ); ?>
		<?php WIC_Saved_Reports::save_form( 'reports', $params ); ?>

		<section class="wic-section" aria-labelledby="wic-rep-clinic-h">
			<h3 class="wic-h3" id="wic-rep-clinic-h"><?php esc_html_e( 'Completion by clinic', 'wic-tp' ); ?></h3>
			<?php if ( ! $clinics ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'Nobody in scope yet.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
			<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'People', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Assigned', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Complete', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rate', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Overdue', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Coming due', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'In progress', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Not started', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $clinics as $name => $c ) : ?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo (int) $c['people']; ?></td>
						<td><?php echo (int) $c['assigned']; ?></td>
						<td><?php echo (int) $c['complete']; ?></td>
						<td data-sort="<?php echo $c['assigned'] ? (int) round( $c['complete'] / $c['assigned'] * 100 ) : -1; ?>"><?php echo esc_html( self::pct( $c['complete'], $c['assigned'] ) ); ?></td>
						<td><?php echo $c['overdue'] ? '<strong class="wic-late">' . (int) $c['overdue'] . '</strong>' : '0'; ?></td>
						<td><?php echo (int) $c['coming_due']; ?></td>
						<td><?php echo (int) $c['in_progress']; ?></td>
						<td><?php echo (int) $c['not_started']; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
		</section>

		<section class="wic-section" aria-labelledby="wic-rep-course-h">
			<h3 class="wic-h3" id="wic-rep-course-h"><?php esc_html_e( 'Completion by course', 'wic-tp' ); ?></h3>
			<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Assigned', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Complete', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rate', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Overdue', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Coming due', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'In progress', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Not applicable', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Never assigned', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $bycrs as $c ) : ?>
					<tr>
						<td><?php echo esc_html( $c['title'] ); ?></td>
						<td><?php echo (int) $c['assigned']; ?></td>
						<td><?php echo (int) $c['complete']; ?></td>
						<td data-sort="<?php echo $c['assigned'] ? (int) round( $c['complete'] / $c['assigned'] * 100 ) : -1; ?>"><?php echo esc_html( self::pct( $c['complete'], $c['assigned'] ) ); ?></td>
						<td><?php echo $c['overdue'] ? '<strong class="wic-late">' . (int) $c['overdue'] . '</strong>' : '0'; ?></td>
						<td><?php echo (int) $c['coming_due']; ?></td>
						<td><?php echo (int) $c['in_progress']; ?></td>
						<td><?php echo (int) $c['not_applicable']; ?></td>
						<td><?php echo (int) $c['never_assigned']; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</section>

		<section class="wic-section" aria-labelledby="wic-rep-missed-h">
			<h3 class="wic-h3" id="wic-rep-missed-h"><?php esc_html_e( 'Questions the agency gets wrong most', 'wic-tp' ); ?></h3>
			<p class="wic-help"><?php esc_html_e( 'A high wrong rate usually means the material needs rewriting, not the staff chasing.', 'wic-tp' ); ?></p>
			<?php if ( ! $missed ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'No answers recorded yet.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
			<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Question', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'People', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Answers', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Wrong', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Wrong rate', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $missed as $m ) : ?>
					<tr>
						<td>
							<?php if ( current_user_can( 'edit_post', $m->slide_id ) ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $m->slide_id ) ); ?>"><?php echo esc_html( get_the_title( $m->slide_id ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( get_the_title( $m->slide_id ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( get_the_title( $m->course_id ) ); ?></td>
						<td><?php echo (int) $m->people; ?></td>
						<td><?php echo (int) $m->answers; ?></td>
						<td><?php echo (int) $m->wrong; ?></td>
						<td data-sort="<?php echo (int) round( $m->wrong / $m->answers * 100 ); ?>"><?php echo esc_html( self::pct( $m->wrong, $m->answers ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
		</section>

		<section class="wic-section" aria-labelledby="wic-rep-drop-h">
			<h3 class="wic-h3" id="wic-rep-drop-h"><?php echo esc_html( sprintf( __( 'Where people stop: %s', 'wic-tp' ), $course ? get_the_title( $course ) : '' ) ); ?></h3>
			<p class="wic-help"><?php echo esc_html( sprintf( _n( 'Based on %d run of this course. "Stopped here" counts unfinished runs whose last slide is this one.', 'Based on %d runs of this course. "Stopped here" counts unfinished runs whose last slide is this one.', $drop['runs'], 'wic-tp' ), $drop['runs'] ) ); ?></p>
			<?php if ( ! $drop['runs'] ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'Nobody has opened this course yet.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
			<div class="wic-table-wrap">
			<table class="wic-table wic-dropoff">
				<thead><tr>
					<th scope="col">#</th>
					<th scope="col"><?php esc_html_e( 'Slide', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reached', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Of runs started', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stopped here', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $drop['slides'] as $s ) : ?>
					<?php $p = $drop['runs'] ? (int) round( $s['reached'] / $drop['runs'] * 100 ) : 0; ?>
					<tr>
						<td><?php echo (int) $s['n']; ?></td>
						<td><?php echo esc_html( $s['title'] ); ?></td>
						<td><?php echo (int) $s['reached']; ?></td>
						<td><span class="wic-mini-bar" aria-hidden="true"><span style="width:<?php echo (int) $p; ?>%"></span></span> <?php echo (int) $p; ?>%</td>
						<td><?php echo $s['stopped'] ? '<strong>' . (int) $s['stopped'] . '</strong>' : '0'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Exports: one handler, CSV or PDF, same rows as the screen          */
	/* ------------------------------------------------------------------ */

	public static function export() {
		check_admin_referer( 'wic_report_export' );
		$uid    = get_current_user_id();
		$type   = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		$format = isset( $_GET['format'] ) && 'pdf' === $_GET['format'] ? 'pdf' : 'csv';
		$clinic = isset( $_GET['clinic'] ) ? sanitize_text_field( wp_unslash( $_GET['clinic'] ) ) : '';
		$from   = self::date_arg( 'from' );
		$to     = self::date_arg( 'to' );
		$tables = array();
		$title  = '';
		$sub    = array();
		if ( $clinic ) {
			$sub[] = sprintf( 'Clinic: %s', WIC_Assign::clinic_label( $clinic ) );
		}
		if ( $from || $to ) {
			$sub[] = sprintf( 'Completed %s to %s', $from ? $from : '…', $to ? $to : '…' );
		}

		if ( 'transcript' === $type ) {
			$who  = isset( $_GET['who'] ) ? sanitize_key( $_GET['who'] ) : 'person';
			$user = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : $uid;
			if ( 'person' === $who ) {
				if ( $user !== $uid && ! ( current_user_can( 'wic_view_team' ) && wic_can_see_user( $uid, $user ) ) ) {
					self::deny();
				}
				$ids   = array( $user );
				$u     = get_userdata( $user );
				$title = sprintf( 'Training transcript: %s', $u ? $u->display_name : '' );
				$rows  = self::transcript_rows( $user, $from, $to );
				$tot   = self::credit_totals( $rows );
				if ( $tot ) {
					$parts = array();
					foreach ( $tot as $t => $h ) {
						$parts[] = $t . ': ' . self::fmt_hours( $h ) . 'h';
					}
					$sub[] = 'Credit totals — ' . implode( '; ', $parts );
				}
			} else {
				if ( ! current_user_can( 'wic_view_team' ) ) {
					self::deny();
				}
				$ids   = wp_list_pluck( WIC_Assign::people_in_scope( $uid, 'clinic' === $who ? $clinic : '' ), 'ID' );
				$title = 'Training transcripts';
			}
			list( $h, $r ) = self::transcript_table( $ids, $from, $to, 'person' !== $who );
			$tables[]      = array( $h, $r, 'person' === $who ? array( 1.2, 3, 1.4, 0.8, 0.7, 2, 1.6, 1.1 ) : null );
		} elseif ( 'compliance' === $type ) {
			if ( ! current_user_can( 'wic_view_all' ) ) {
				self::deny();
			}
			$status        = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
			$title         = 'Compliance report — required training';
			list( $h, $r ) = self::compliance_table( self::compliance_rows( $uid, $clinic, $from, $to, $status ) );
			$tables[]      = array( $h, $r, array( 2, 1, 1.5, 2.5, 1.6, 1, 1, 0.7, 1.4 ) );
		} elseif ( 'gaps' === $type ) {
			if ( ! current_user_can( 'wic_view_all' ) ) {
				self::deny();
			}
			$req   = isset( $_GET['req'] ) ? absint( $_GET['req'] ) : 0;
			$title = 'People lacking a required certification';
			$r     = array();
			foreach ( WIC_Requirements::gaps( $uid, $req, $clinic ) as $g ) {
				$r[] = array( $g['user']->display_name, get_user_meta( $g['user']->ID, 'wic_staff_number', true ), wic_user_clinic_name( $g['user']->ID ), $g['requirement']->post_title, $g['last'] ? get_date_from_gmt( $g['last'], 'Y-m-d' ) : 'Never', implode( ', ', array_map( 'get_the_title', $g['courses'] ) ) );
			}
			$tables[] = array( array( 'Name', 'Staff number', 'Clinic', 'Requirement', 'Last completed', 'Met by' ), $r, array( 2, 1, 1.5, 2.5, 1.2, 3 ) );
		} elseif ( 'reports' === $type ) {
			if ( ! current_user_can( 'wic_view_all' ) ) {
				self::deny();
			}
			$title = 'Completion by clinic and by course';
			$r     = array();
			foreach ( self::by_clinic( $uid, $clinic ) as $name => $c ) {
				$r[] = array( $name, $c['people'], $c['assigned'], $c['complete'], self::pct( $c['complete'], $c['assigned'] ), $c['overdue'], $c['coming_due'], $c['in_progress'], $c['not_started'] );
			}
			$tables[] = array( array( 'Clinic', 'People', 'Assigned', 'Complete', 'Rate', 'Overdue', 'Coming due', 'In progress', 'Not started' ), $r, array( 3, 1, 1, 1, 1, 1, 1, 1, 1 ) );
			$r        = array();
			foreach ( self::by_course( $uid, $clinic ) as $c ) {
				$r[] = array( $c['title'], $c['assigned'], $c['complete'], self::pct( $c['complete'], $c['assigned'] ), $c['overdue'], $c['coming_due'], $c['in_progress'], $c['not_applicable'], $c['never_assigned'] );
			}
			$tables[] = array( array( 'Course', 'Assigned', 'Complete', 'Rate', 'Overdue', 'Coming due', 'In progress', 'Not applicable', 'Never assigned' ), $r, array( 3, 1, 1, 1, 1, 1, 1, 1, 1 ) );
			$r        = array();
			foreach ( self::most_missed( $uid, $clinic, 50 ) as $m ) {
				$r[] = array( get_the_title( $m->slide_id ), get_the_title( $m->course_id ), $m->people, $m->answers, $m->wrong, self::pct( $m->wrong, $m->answers ) );
			}
			$tables[] = array( array( 'Question', 'Course', 'People', 'Answers', 'Wrong', 'Wrong rate' ), $r, array( 3, 3, 1, 1, 1, 1 ) );
			$course   = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0;
			if ( $course ) {
				$d = self::dropoff( $uid, $course, $clinic );
				$r = array();
				foreach ( $d['slides'] as $s ) {
					$r[] = array( $s['n'], $s['title'], $s['reached'], self::pct( $s['reached'], $d['runs'] ), $s['stopped'] );
				}
				$tables[] = array( array( '#', 'Slide (' . get_the_title( $course ) . ')', 'Reached', 'Of runs started', 'Stopped here' ), $r, array( 0.5, 4, 1, 1, 1 ) );
			}
		} else {
			self::deny();
		}

		wic_audit( 'export', 'report', 0, array( 'type' => $type, 'format' => $format ) );
		$file = sanitize_title( $title ) . '-' . gmdate( 'Y-m-d' );
		if ( 'pdf' === $format ) {
			$pdf = new WIC_Pdf( $title, 'Letter', trim( wic_setting( 'name' ) . ' · ' . sprintf( 'Generated %s', current_time( 'Y-m-d H:i' ) ) . ( $sub ? ' · ' . implode( ' · ', $sub ) : '' ) ) );
			foreach ( $tables as $t ) {
				$pdf->table( $t[0], $t[1], $t[2] );
			}
			$pdf->send( $file . '.pdf' );
		}
		$csv = array();
		foreach ( $tables as $i => $t ) {
			if ( $i ) {
				$csv[] = array();
			}
			$csv[] = $t[0];
			foreach ( $t[1] as $row ) {
				$csv[] = $row;
			}
		}
		wic_send_csv( $file . '.csv', $csv );
	}

	private static function deny() {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Records retention (#73) — decision first                           */
	/* ------------------------------------------------------------------ */

	/** When the account was closed: the audit trail first, the scheduled leaving date second. */
	public static function closed_at( $user_id ) {
		global $wpdb;
		$at = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM ' . wic_table( 'audit' ) . " WHERE action = 'user_status' AND object_id = %d AND details LIKE %s", $user_id, '%"to":"deactivated"%' ) );
		if ( $at ) {
			return $at;
		}
		$on = get_user_meta( $user_id, 'wic_deactivate_on', true );
		return $on ? $on . ' 00:00:00' : '';
	}

	public static function retention_candidates( $viewer ) {
		$years = (int) wic_setting( 'decision_retention_years' );
		$out   = array( 'due' => array(), 'unknown' => array() );
		if ( $years <= 0 ) {
			return $out;
		}
		$cut = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $years . ' years' ) );
		foreach ( get_users(
			array(
				'meta_key'   => 'wic_status',
				'meta_value' => 'deactivated',
			)
		) as $u ) {
			if ( get_user_meta( $u->ID, 'wic_anonymised', true ) || ! wic_can_see_user( $viewer, $u->ID ) && ! user_can( $viewer, 'wic_view_all' ) ) {
				continue;
			}
			$at = self::closed_at( $u->ID );
			if ( ! $at ) {
				$out['unknown'][] = $u;
			} elseif ( $at <= $cut ) {
				$u->wic_closed_at = $at;
				$out['due'][]     = $u;
			}
		}
		return $out;
	}

	public static function view_retention( $uid ) {
		$years = (int) wic_setting( 'decision_retention_years' );
		$c     = self::retention_candidates( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Records retention review', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php echo esc_html( sprintf( _n( 'Closed accounts whose closing date is more than %d year ago. Anonymising replaces the person\'s name and email everywhere, including certificate snapshots; every completion row is kept. It cannot be undone.', 'Closed accounts whose closing date is more than %d years ago. Anonymising replaces the person\'s name and email everywhere, including certificate snapshots; every completion row is kept. It cannot be undone.', $years, 'wic-tp' ), $years ) ); ?></p>
		<?php if ( ! $c['due'] ) : ?>
			<div class="wic-empty wic-empty--good"><p><?php esc_html_e( 'No closed account is past the retention period.', 'wic-tp' ); ?></p></div>
		<?php else : ?>
			<div class="wic-table-wrap">
			<table class="wic-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Closed', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Completions kept', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $c['due'] as $u ) : ?>
					<tr>
						<td><?php echo esc_html( $u->display_name ); ?></td>
						<td><?php echo esc_html( wic_format_date( $u->wic_closed_at ) ); ?></td>
						<td><?php echo count( self::transcript_rows( $u->ID ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
								<input type="hidden" name="action" value="wic_anonymise">
								<input type="hidden" name="user" value="<?php echo (int) $u->ID; ?>">
								<?php wp_nonce_field( 'wic_anonymise_' . $u->ID ); ?>
								<button type="submit" class="wic-btn wic-btn--small" data-wic-confirm="<?php echo esc_attr( sprintf( __( 'Anonymise %s? This cannot be undone.', 'wic-tp' ), $u->display_name ) ); ?>"><?php esc_html_e( 'Anonymise', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $u->display_name ); ?></span></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
		<?php if ( $c['unknown'] ) : ?>
			<p class="wic-help"><?php echo esc_html( sprintf( _n( '%d closed account has no recorded closing date and is not listed.', '%d closed accounts have no recorded closing date and are not listed.', count( $c['unknown'] ), 'wic-tp' ), count( $c['unknown'] ) ) ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function anonymise() {
		global $wpdb;
		$id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( 'wic_anonymise_' . $id );
		if ( ! current_user_can( 'wic_manage_people' ) || (int) wic_setting( 'decision_retention_years' ) <= 0 ) {
			self::deny();
		}
		$eligible = wp_list_pluck( self::retention_candidates( get_current_user_id() )['due'], 'ID' );
		if ( ! in_array( $id, array_map( 'intval', $eligible ), true ) ) {
			WIC_Portal::back( 'records_retention', 'err_anon' );
		}
		/* translators: %d: user ID */
		$label = sprintf( __( 'Anonymised person #%d', 'wic-tp' ), $id );
		$wpdb->update(
			$wpdb->users,
			array(
				'user_login'    => 'anonymised-' . $id,
				'user_nicename' => 'anonymised-' . $id,
				'user_email'    => 'anonymised-' . $id . '@invalid.invalid',
				'display_name'  => $label,
				'user_url'      => '',
			),
			array( 'ID' => $id )
		);
		foreach ( array( 'first_name', 'last_name', 'nickname', 'description', 'wic_staff_number' ) as $k ) {
			delete_user_meta( $id, $k );
		}
		update_user_meta( $id, 'wic_anonymised', wic_now() );
		$wpdb->update( wic_table( 'certificates' ), array( 'learner_name' => $label ), array( 'user_id' => $id ) );
		clean_user_cache( $id );
		wic_audit( 'anonymise', 'user', $id, array( 'retention_years' => (int) wic_setting( 'decision_retention_years' ) ) );
		WIC_Portal::back( 'records_retention', 'anonymised' );
	}

	/* ------------------------------------------------------------------ */
	/* The person record (agent A's screen) — assignments and compliance  */
	/* ------------------------------------------------------------------ */

	public static function person_section( $user_id, $viewer_id ) {
		if ( ! wic_can_see_user( $viewer_id, $user_id ) ) {
			return;
		}
		$items  = self::assignments( $user_id );
		$manage = user_can( $viewer_id, 'wic_view_team' ) && (int) $viewer_id !== (int) $user_id;
		?>
		<section class="wic-section" aria-labelledby="wic-pr-asg-h">
			<div class="wic-toolbar">
				<h3 class="wic-h3" id="wic-pr-asg-h"><?php esc_html_e( 'Assigned training', 'wic-tp' ); ?></h3>
				<a class="wic-btn wic-btn--small" href="<?php echo esc_url( wic_portal_url( 'transcript', array( 'user' => $user_id ) ) ); ?>"><?php esc_html_e( 'Full transcript', 'wic-tp' ); ?></a>
			</div>
			<?php if ( ! $items ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'Nothing assigned.', 'wic-tp' ); ?></p></div>
			<?php else : ?>
			<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Progress', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Due', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Completed', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Score', 'wic-tp' ); ?></th>
					<?php if ( $manage ) : ?><th scope="col"><?php esc_html_e( 'Actions', 'wic-tp' ); ?></th><?php endif; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $items as $a ) : ?>
					<tr>
						<td><?php echo esc_html( $a['title'] ); ?></td>
						<td data-sort="<?php echo esc_attr( $a['status'] ); ?>"><?php echo self::badge( $a['status'] ); // phpcs:ignore ?></td>
						<td data-sort="<?php echo (int) $a['progress']; ?>"><?php echo (int) $a['progress']; ?>%</td>
						<td data-sort="<?php echo esc_attr( (string) $a['due_at'] ); ?>"><?php echo esc_html( wic_format_date( $a['due_at'] ) ); ?></td>
						<td><?php echo esc_html( $a['completion'] ? wic_format_date( $a['completion']->completed_at ) : '—' ); ?></td>
						<td><?php echo $a['completion'] ? (int) $a['completion']->score . '%' : '—'; ?></td>
						<?php if ( $manage ) : ?>
							<td>
								<?php
								if ( in_array( $a['status'], array( 'overdue', 'coming_due', 'in_progress', 'not_started' ), true ) ) {
									echo WIC_Assign::nudge_button( $a, 'person', $user_id ); // phpcs:ignore
								}
								echo WIC_Assign::na_controls( $a, 'person', $user_id ); // phpcs:ignore
								?>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
		</section>
		<?php
		$reqs = array_filter(
			WIC_Requirements::all(),
			function ( $r ) use ( $user_id ) {
				return WIC_Requirements::applies_to( $r->ID, $user_id );
			}
		);
		if ( ! $reqs ) {
			return;
		}
		?>
		<section class="wic-section" aria-labelledby="wic-pr-req-h">
			<h3 class="wic-h3" id="wic-pr-req-h"><?php esc_html_e( 'Requirements', 'wic-tp' ); ?></h3>
			<ul class="wic-list">
				<?php foreach ( $reqs as $r ) : ?>
					<?php $ev = WIC_Requirements::evidence( $r->ID, $user_id ); ?>
					<li>
						<span><?php echo esc_html( $r->post_title ); ?> <span class="wic-meta">(<?php echo esc_html( WIC_Requirements::frequency_label( $r->ID ) ); ?>)</span></span>
						<span><?php echo $ev ? '<span class="wic-badge wic-badge--complete">' . esc_html__( 'Met', 'wic-tp' ) . '</span> ' . esc_html( $ev->course . ', ' . wic_format_date( $ev->completed_at ) ) : '<span class="wic-badge wic-badge--overdue">' . esc_html__( 'Not met', 'wic-tp' ) . '</span>'; ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}
}

/**
 * Saved reports: a name, a report type and its filters. Running one opens the report view
 * with those filters, so the result is always current.
 */
class WIC_Saved_Reports {

	const TYPES = array(
		'compliance' => 'compliance',
		'gaps'       => 'gaps',
		'reports'    => 'reports',
		'transcript' => 'transcript',
	);

	public static function init() {
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		foreach ( array( 'save', 'rename', 'delete' ) as $a ) {
			add_action( 'admin_post_wic_report_' . $a, array( __CLASS__, $a ) );
		}
	}

	public static function views( $views ) {
		$views['saved_reports'] = array(
			'label'    => __( 'Saved reports', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_view_all',
			'callback' => array( __CLASS__, 'view' ),
			'order'    => 60,
		);
		return $views;
	}

	public static function type_label( $t ) {
		$l = array(
			'compliance' => __( 'Compliance report', 'wic-tp' ),
			'gaps'       => __( 'Certification gaps', 'wic-tp' ),
			'reports'    => __( 'Completion reports', 'wic-tp' ),
			'transcript' => __( 'Transcript', 'wic-tp' ),
		);
		return isset( $l[ $t ] ) ? $l[ $t ] : $t;
	}

	public static function mine( $uid ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'saved_reports' ) . ' WHERE owner_id = %d ORDER BY name ASC', $uid ) );
	}

	public static function run_url( $r ) {
		$cfg = json_decode( (string) $r->config, true );
		return wic_portal_url( self::TYPES[ $r->type ], is_array( $cfg ) ? array_filter( array_map( 'strval', $cfg ), 'strlen' ) : array() );
	}

	public static function save_form( $type, $params ) {
		if ( ! current_user_can( 'wic_view_all' ) ) {
			return;
		}
		?>
		<details class="wic-more wic-save-report">
			<summary><?php esc_html_e( 'Save this report…', 'wic-tp' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
				<input type="hidden" name="action" value="wic_report_save">
				<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
				<input type="hidden" name="config" value="<?php echo esc_attr( wp_json_encode( $params ) ); ?>">
				<?php wp_nonce_field( 'wic_report_save' ); ?>
				<label for="wic-save-<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Name', 'wic-tp' ); ?></label>
				<input type="text" id="wic-save-<?php echo esc_attr( $type ); ?>" name="name" required maxlength="190">
				<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Save', 'wic-tp' ); ?></button>
			</form>
		</details>
		<?php
	}

	private static function owned( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'saved_reports' ) . ' WHERE id = %d', $id ) );
		if ( ! $r || (int) $r->owner_id !== get_current_user_id() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		return $r;
	}

	public static function save() {
		global $wpdb;
		check_admin_referer( 'wic_report_save' );
		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		if ( ! current_user_can( 'wic_view_all' ) || ! isset( self::TYPES[ $type ] ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
		}
		$cfg  = json_decode( isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '', true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cfg  = is_array( $cfg ) ? array_map( 'sanitize_text_field', array_map( 'strval', $cfg ) ) : array();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			WIC_Portal::back( self::TYPES[ $type ], 'error' );
		}
		$wpdb->insert(
			wic_table( 'saved_reports' ),
			array(
				'owner_id'   => get_current_user_id(),
				'name'       => $name,
				'type'       => $type,
				'config'     => wp_json_encode( $cfg ),
				'created_at' => wic_now(),
				'updated_at' => wic_now(),
			)
		);
		WIC_Portal::back( 'saved_reports', 'report_saved' );
	}

	public static function rename() {
		global $wpdb;
		$id = isset( $_POST['report'] ) ? absint( $_POST['report'] ) : 0;
		check_admin_referer( 'wic_report_' . $id );
		self::owned( $id );
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' !== $name ) {
			$wpdb->update( wic_table( 'saved_reports' ), array( 'name' => $name, 'updated_at' => wic_now() ), array( 'id' => $id ) );
		}
		WIC_Portal::back( 'saved_reports', 'report_renamed' );
	}

	public static function delete() {
		global $wpdb;
		$id = isset( $_POST['report'] ) ? absint( $_POST['report'] ) : 0;
		check_admin_referer( 'wic_report_' . $id );
		self::owned( $id );
		$wpdb->delete( wic_table( 'saved_reports' ), array( 'id' => $id ) );
		WIC_Portal::back( 'saved_reports', 'report_deleted' );
	}

	public static function view( $uid ) {
		$rows = self::mine( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Saved reports', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Define a report once and rerun it whenever you need it; the numbers are always current. Save one from any report screen.', 'wic-tp' ); ?></p>
		<?php if ( ! $rows ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No saved reports yet. Open the Compliance report, Reports or Certification gaps, choose filters, then "Save this report".', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Report', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Filters', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Saved', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php
				$cfg   = json_decode( (string) $r->config, true );
				$parts = array();
				foreach ( is_array( $cfg ) ? $cfg : array() as $k => $v ) {
					if ( '' === (string) $v || '0' === (string) $v ) {
						continue;
					}
					if ( 'clinic' === $k ) {
						$v = WIC_Assign::clinic_label( $v );
					} elseif ( in_array( $k, array( 'course', 'req' ), true ) ) {
						$v = get_the_title( (int) $v );
					} elseif ( 'status' === $k ) {
						$v = wic_status_label( $v );
					}
					$parts[] = ucfirst( $k ) . ': ' . $v;
				}
				?>
				<tr>
					<td><a href="<?php echo esc_url( self::run_url( $r ) ); ?>"><?php echo esc_html( $r->name ); ?></a></td>
					<td><?php echo esc_html( self::type_label( $r->type ) ); ?></td>
					<td><?php echo $parts ? esc_html( implode( '; ', $parts ) ) : esc_html__( 'None', 'wic-tp' ); ?></td>
					<td><?php echo esc_html( wic_format_date( $r->updated_at ) ); ?></td>
					<td>
						<a class="wic-btn wic-btn--small wic-btn--primary" href="<?php echo esc_url( self::run_url( $r ) ); ?>"><?php esc_html_e( 'Run', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $r->name ); ?></span></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
							<input type="hidden" name="report" value="<?php echo (int) $r->id; ?>">
							<?php wp_nonce_field( 'wic_report_' . $r->id ); ?>
							<label class="screen-reader-text" for="wic-rn-<?php echo (int) $r->id; ?>"><?php esc_html_e( 'New name', 'wic-tp' ); ?></label>
							<input type="text" id="wic-rn-<?php echo (int) $r->id; ?>" name="name" value="<?php echo esc_attr( $r->name ); ?>" maxlength="190">
							<button type="submit" name="action" value="wic_report_rename" class="wic-btn wic-btn--small"><?php esc_html_e( 'Rename', 'wic-tp' ); ?></button>
							<button type="submit" name="action" value="wic_report_delete" class="wic-btn wic-btn--small" data-wic-confirm="<?php esc_attr_e( 'Delete this saved report? The data it reports on is not affected.', 'wic-tp' ); ?>"><?php esc_html_e( 'Delete', 'wic-tp' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}
}
