<?php
/**
 * Observed competency (#107 sign-off on a phone, #108 a checklist per competency,
 * #109 assessing several people at once).
 *
 * A checklist is a list of observable items. An evaluation records each item as met,
 * not met or not applicable, with a note, signed by the observer. A pass (nothing "not met",
 * at least one "met") on a checklist linked to a course records a completion of that course.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'competency' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  checklist_id bigint(20) unsigned NOT NULL,
  checklist_title varchar(255) NOT NULL DEFAULT '',
  evaluator_id bigint(20) unsigned NOT NULL,
  signed_name varchar(200) NOT NULL DEFAULT '',
  items longtext NULL,
  result varchar(20) NOT NULL DEFAULT 'not_yet',
  notes text NULL,
  batch_id varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY checklist_id (checklist_id)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_Competency {

	const MARKS = array( 'met', 'not_met', 'na' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_wic_checklist', array( __CLASS__, 'save' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_competency_save', array( __CLASS__, 'handle_single' ) );
		add_action( 'admin_post_wic_competency_batch', array( __CLASS__, 'handle_batch' ) );
		add_action( 'wic_person_record_sections', array( __CLASS__, 'person_section' ), 50, 2 );
	}

	public static function register() {
		WIC_E::register_type( 'wic_checklist', __( 'Competency checklists', 'wic-tp' ), __( 'Checklist', 'wic-tp' ) );
	}

	public static function labels() {
		return array(
			'met'     => __( 'Met', 'wic-tp' ),
			'not_met' => __( 'Not met', 'wic-tp' ),
			'na'      => __( 'Not applicable', 'wic-tp' ),
		);
	}

	public static function items( $checklist_id ) {
		$items = get_post_meta( $checklist_id, '_wic_items', true );
		return is_array( $items ) ? array_values( $items ) : array();
	}

	public static function checklists() {
		return WIC_E::options( 'wic_checklist' );
	}

	/** Pass = nothing not met, at least one met. */
	public static function result( $marks ) {
		$marks = array_column( $marks, 'mark' );
		if ( in_array( 'not_met', $marks, true ) || ! in_array( 'met', $marks, true ) ) {
			return 'not_yet';
		}
		return 'pass';
	}

	public static function record( $user_id, $checklist_id, $marks, $notes, $signed, $batch = '' ) {
		global $wpdb;
		$result = self::result( $marks );
		$wpdb->insert(
			wic_table( 'competency' ),
			array(
				'user_id'         => $user_id,
				'checklist_id'    => $checklist_id,
				'checklist_title' => get_the_title( $checklist_id ),
				'evaluator_id'    => get_current_user_id(),
				'signed_name'     => $signed,
				'items'           => wp_json_encode( $marks ),
				'result'          => $result,
				'notes'           => $notes,
				'batch_id'        => $batch,
				'created_at'      => wic_now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		wic_audit( 'competency_evaluated', 'competency', $id, array( 'user' => $user_id, 'checklist' => $checklist_id, 'result' => $result ) );
		$course = (int) get_post_meta( $checklist_id, '_wic_course', true );
		if ( 'pass' === $result && $course && 'wic_course' === get_post_type( $course ) ) {
			WIC_Records::record_completion( $user_id, $course, WIC_Records::current_run( $user_id, $course ), 100, 0, 'competency' );
		}
		/* translators: 1: checklist, 2: result */
		WIC_Notify::event( $user_id, 'competency', $id, sprintf( __( 'You were observed on "%1$s": %2$s.', 'wic-tp' ), get_the_title( $checklist_id ), 'pass' === $result ? __( 'competent', 'wic-tp' ) : __( 'not yet competent', 'wic-tp' ) ), false );
		do_action( 'wic_competency_recorded', $id, $user_id, $checklist_id, $result );
		return $id;
	}

	public static function for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'competency' ) . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC', $user_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Authoring                                                          */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'wic_checklist_meta', __( 'Checklist items', 'wic-tp' ), array( __CLASS__, 'box' ), 'wic_checklist', 'normal', 'high' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'wic_checklist_meta', 'wic_checklist_nonce' );
		$items  = implode( "\n", self::items( $post->ID ) );
		$course = (int) get_post_meta( $post->ID, '_wic_course', true );
		?>
		<p><label for="wic_items"><strong><?php esc_html_e( 'Items to observe — one per line', 'wic-tp' ); ?></strong></label><br>
			<textarea id="wic_items" name="wic_items" class="large-text" rows="10"><?php echo esc_textarea( $items ); ?></textarea></p>
		<p class="description"><?php esc_html_e( 'Each state writes its own checklist. Write each item as something an observer can see the person do.', 'wic-tp' ); ?></p>
		<p><label for="wic_ck_course"><strong><?php esc_html_e( 'A pass completes this course', 'wic-tp' ); ?></strong></label><br>
			<select id="wic_ck_course" name="wic_ck_course"><option value="0"><?php esc_html_e( '— None —', 'wic-tp' ); ?></option>
				<?php foreach ( WIC_E::options( 'wic_course', array( 'publish', 'draft' ) ) as $id => $t ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( $course, $id ); ?>><?php echo esc_html( $t ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label><?php esc_html_e( 'Agency ID this checklist belongs to (0 = all)', 'wic-tp' ); ?> <input type="number" min="0" name="wic_ck_agency" value="<?php echo esc_attr( (int) get_post_meta( $post->ID, '_wic_agency_id', true ) ); ?>" style="width:6em"></label></p>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! WIC_E::can_save( $post_id, 'wic_checklist_nonce', 'wic_checklist_meta' ) ) {
			return;
		}
		$raw   = isset( $_POST['wic_items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wic_items'] ) ) : '';
		$items = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
		update_post_meta( $post_id, '_wic_items', $items );
		update_post_meta( $post_id, '_wic_course', isset( $_POST['wic_ck_course'] ) ? absint( $_POST['wic_ck_course'] ) : 0 );
		update_post_meta( $post_id, '_wic_agency_id', isset( $_POST['wic_ck_agency'] ) ? absint( $_POST['wic_ck_agency'] ) : 0 );
	}

	/* ------------------------------------------------------------------ */
	/* Portal                                                             */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['observe']       = array(
			'label'    => __( 'Observe', 'wic-tp' ),
			'group'    => 'team',
			'cap'      => 'wic_view_team',
			'callback' => array( __CLASS__, 'view_observe' ),
			'order'    => 50,
		);
		$views['observe_batch'] = array(
			'label'    => __( 'Observe a group', 'wic-tp' ),
			'group'    => 'team',
			'cap'      => 'wic_view_team',
			'callback' => array( __CLASS__, 'view_batch' ),
			'order'    => 51,
		);
		$views['competencies']  = array(
			'label'    => __( 'Competencies', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'wic_learn',
			'callback' => array( __CLASS__, 'view_mine' ),
			'order'    => 55,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['competency_saved']   = __( 'Observation recorded.', 'wic-tp' );
		$m['competency_batch']   = __( 'Observations recorded for everyone in the group.', 'wic-tp' );
		$m['err_competency']     = __( 'Mark every item and type your name to sign.', 'wic-tp' );
		return $m;
	}

	private static function result_badge( $result ) {
		return 'pass' === $result
			? '<span class="wic-badge wic-badge--complete">' . esc_html__( 'Competent', 'wic-tp' ) . '</span>'
			: '<span class="wic-badge wic-badge--overdue">' . esc_html__( 'Not yet competent', 'wic-tp' ) . '</span>';
	}

	public static function view_observe( $uid ) {
		$lists = self::checklists();
		$ck    = isset( $_GET['checklist'] ) ? absint( $_GET['checklist'] ) : 0;
		$who   = isset( $_GET['person'] ) ? absint( $_GET['person'] ) : 0;
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Observe a competency', 'wic-tp' ); ?></h2>
		<?php if ( ! $lists ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No checklists are published yet. They are written under WIC Platform → Competency checklists.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<form method="get" class="wic-form wic-competency-pick">
			<?php if ( ! get_option( 'permalink_structure' ) ) : ?><input type="hidden" name="page_id" value="<?php echo (int) wic_page_id( 'portal' ); ?>"><?php endif; ?>
			<input type="hidden" name="view" value="observe">
			<div class="wic-field"><label for="wic-ob-person"><?php esc_html_e( 'Person', 'wic-tp' ); ?></label><?php WIC_E::person_select( 'person', 'wic-ob-person', $uid, $who ); ?></div>
			<div class="wic-field"><label for="wic-ob-ck"><?php esc_html_e( 'Checklist', 'wic-tp' ); ?></label>
				<select id="wic-ob-ck" name="checklist" required>
					<?php foreach ( $lists as $id => $t ) : ?><option value="<?php echo (int) $id; ?>" <?php selected( $ck, $id ); ?>><?php echo esc_html( $t ); ?></option><?php endforeach; ?>
				</select></div>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Start observation', 'wic-tp' ); ?></button>
		</form>
		<?php
		if ( ! $ck || ! $who || ! isset( $lists[ $ck ] ) || ! wic_can_see_user( $uid, $who ) || $who === $uid ) {
			self::recent( $uid );
			return;
		}
		$items  = self::items( $ck );
		$person = get_userdata( $who );
		?>
		<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>" class="wic-form wic-competency">
			<input type="hidden" name="action" value="wic_competency_save">
			<input type="hidden" name="checklist" value="<?php echo (int) $ck; ?>">
			<input type="hidden" name="person" value="<?php echo (int) $who; ?>">
			<?php wp_nonce_field( 'wic_competency_save' ); ?>
			<h3 class="wic-h3"><?php echo esc_html( sprintf( __( '%1$s — %2$s', 'wic-tp' ), $lists[ $ck ], $person->display_name ) ); ?></h3>
			<?php foreach ( $items as $i => $item ) : ?>
				<fieldset class="wic-ck-item">
					<legend><?php echo esc_html( ( $i + 1 ) . '. ' . $item ); ?></legend>
					<div class="wic-ck-marks">
						<?php foreach ( self::labels() as $mk => $ml ) : ?>
							<label class="wic-ck-mark wic-ck-mark--<?php echo esc_attr( $mk ); ?>"><input type="radio" name="mark[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $mk ); ?>" required> <?php echo esc_html( $ml ); ?></label>
						<?php endforeach; ?>
					</div>
					<label class="screen-reader-text" for="wic-ck-note-<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( __( 'Note for item %d', 'wic-tp' ), $i + 1 ) ); ?></label>
					<input type="text" id="wic-ck-note-<?php echo (int) $i; ?>" name="note[<?php echo (int) $i; ?>]" placeholder="<?php esc_attr_e( 'Note (optional)', 'wic-tp' ); ?>">
				</fieldset>
			<?php endforeach; ?>
			<div class="wic-field"><label for="wic-ck-notes"><?php esc_html_e( 'Overall notes', 'wic-tp' ); ?></label><textarea id="wic-ck-notes" name="notes" rows="3"></textarea></div>
			<div class="wic-field"><label for="wic-ck-sign"><?php esc_html_e( 'Type your full name to sign this observation', 'wic-tp' ); ?></label><input type="text" id="wic-ck-sign" name="signed_name" required autocomplete="name"></div>
			<p class="wic-help"><?php esc_html_e( 'Competent means no item is "Not met" and at least one is "Met".', 'wic-tp' ); ?></p>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Sign and save', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	private static function recent( $uid ) {
		global $wpdb;
		$ids = array_map( 'intval', wic_scope_user_ids( $uid ) );
		if ( ! $ids ) {
			return;
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'competency' ) . ' WHERE user_id IN (' . implode( ',', $ids ) . ') ORDER BY id DESC LIMIT 25' ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
		echo '<h3 class="wic-h3">' . esc_html__( 'Recent observations', 'wic-tp' ) . '</h3>';
		if ( ! $rows ) {
			echo '<div class="wic-empty"><p>' . esc_html__( 'No observations recorded yet.', 'wic-tp' ) . '</p></div>';
			return;
		}
		self::table( $rows, true );
	}

	private static function table( $rows, $with_person ) {
		echo '<div class="wic-table-wrap"><table class="wic-table"><thead><tr>';
		if ( $with_person ) {
			echo '<th scope="col">' . esc_html__( 'Name', 'wic-tp' ) . '</th>';
		}
		echo '<th scope="col">' . esc_html__( 'Checklist', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Date', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Observed by', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Result', 'wic-tp' ) . '</th><th scope="col">' . esc_html__( 'Details', 'wic-tp' ) . '</th></tr></thead><tbody>';
		$labels = self::labels();
		foreach ( $rows as $r ) {
			$u     = get_userdata( $r->user_id );
			$marks = json_decode( (string) $r->items, true );
			echo '<tr>';
			if ( $with_person ) {
				echo '<td>' . esc_html( $u ? $u->display_name : '#' . $r->user_id ) . '</td>';
			}
			echo '<td>' . esc_html( $r->checklist_title ) . '</td><td>' . esc_html( wic_format_date( $r->created_at ) ) . '</td><td>' . esc_html( $r->signed_name ) . '</td><td>' . self::result_badge( $r->result ) . '</td><td><details><summary>' . esc_html__( 'Items', 'wic-tp' ) . '</summary><ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
			foreach ( (array) $marks as $m ) {
				echo '<li>' . esc_html( $m['item'] . ': ' . ( isset( $labels[ $m['mark'] ] ) ? $labels[ $m['mark'] ] : $m['mark'] ) . ( $m['note'] ? ' — ' . $m['note'] : '' ) ) . '</li>';
			}
			echo '</ul>' . ( $r->notes ? '<p>' . esc_html( $r->notes ) . '</p>' : '' ) . '</details></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Collect marks for one person from posted arrays; null if any item is unmarked. */
	private static function collect( $items, $marks, $notes ) {
		$out = array();
		foreach ( $items as $i => $item ) {
			$mark = isset( $marks[ $i ] ) ? sanitize_key( $marks[ $i ] ) : '';
			if ( ! in_array( $mark, self::MARKS, true ) ) {
				return null;
			}
			$out[] = array(
				'item' => $item,
				'mark' => $mark,
				'note' => isset( $notes[ $i ] ) ? sanitize_text_field( $notes[ $i ] ) : '',
			);
		}
		return $out;
	}

	public static function handle_single() {
		check_admin_referer( 'wic_competency_save' );
		$uid = get_current_user_id();
		$ck  = isset( $_POST['checklist'] ) ? absint( $_POST['checklist'] ) : 0;
		$who = isset( $_POST['person'] ) ? absint( $_POST['person'] ) : 0;
		if ( ! current_user_can( 'wic_view_team' ) || 'wic_checklist' !== get_post_type( $ck ) || ! $who || $who === $uid || ! wic_can_see_user( $uid, $who ) ) {
			WIC_E::deny();
		}
		$signed = isset( $_POST['signed_name'] ) ? sanitize_text_field( wp_unslash( $_POST['signed_name'] ) ) : '';
		$marks  = self::collect( self::items( $ck ), isset( $_POST['mark'] ) ? (array) wp_unslash( $_POST['mark'] ) : array(), isset( $_POST['note'] ) ? (array) wp_unslash( $_POST['note'] ) : array() );
		if ( null === $marks || strlen( $signed ) < 2 ) {
			WIC_Portal::back( 'observe', 'err_competency', array( 'checklist' => $ck, 'person' => $who ) );
		}
		self::record( $who, $ck, $marks, isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '', $signed );
		WIC_Portal::back( 'observe', 'competency_saved' );
	}

	public static function view_batch( $uid ) {
		$lists  = self::checklists();
		$ck     = isset( $_GET['checklist'] ) ? absint( $_GET['checklist'] ) : 0;
		$chosen = isset( $_GET['people'] ) ? array_map( 'absint', (array) $_GET['people'] ) : array();
		$team   = WIC_E::team( $uid );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Observe several people at once', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'For a training day where everyone is observed on the same checklist. Choose the checklist and the people, then mark everyone on one screen.', 'wic-tp' ); ?></p>
		<?php if ( ! $lists || ! $team ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'You need at least one published checklist and someone on your team.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<form method="get" class="wic-form">
			<?php if ( ! get_option( 'permalink_structure' ) ) : ?><input type="hidden" name="page_id" value="<?php echo (int) wic_page_id( 'portal' ); ?>"><?php endif; ?>
			<input type="hidden" name="view" value="observe_batch">
			<div class="wic-field"><label for="wic-bt-ck"><?php esc_html_e( 'Checklist', 'wic-tp' ); ?></label>
				<select id="wic-bt-ck" name="checklist" required>
					<?php foreach ( $lists as $id => $t ) : ?><option value="<?php echo (int) $id; ?>" <?php selected( $ck, $id ); ?>><?php echo esc_html( $t ); ?></option><?php endforeach; ?>
				</select></div>
			<fieldset class="wic-field"><legend><?php esc_html_e( 'People', 'wic-tp' ); ?></legend>
				<?php foreach ( $team as $u ) : ?>
					<label><input type="checkbox" name="people[]" value="<?php echo (int) $u->ID; ?>" <?php checked( in_array( (int) $u->ID, $chosen, true ) ); ?>> <?php echo esc_html( $u->display_name ); ?></label>
				<?php endforeach; ?>
			</fieldset>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Show the grid', 'wic-tp' ); ?></button>
		</form>
		<?php
		$people = array_values(
			array_filter(
				$team,
				function ( $u ) use ( $chosen ) {
					return in_array( (int) $u->ID, $chosen, true );
				}
			)
		);
		if ( ! $ck || ! isset( $lists[ $ck ] ) || ! $people ) {
			return;
		}
		$items = self::items( $ck );
		?>
		<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>" class="wic-competency-batch">
			<input type="hidden" name="action" value="wic_competency_batch">
			<input type="hidden" name="checklist" value="<?php echo (int) $ck; ?>">
			<?php wp_nonce_field( 'wic_competency_batch' ); ?>
			<div class="wic-table-wrap">
			<table class="wic-table">
				<caption class="screen-reader-text"><?php echo esc_html( $lists[ $ck ] ); ?></caption>
				<thead><tr><th scope="col"><?php esc_html_e( 'Item', 'wic-tp' ); ?></th>
					<?php foreach ( $people as $p ) : ?><th scope="col"><?php echo esc_html( $p->display_name ); ?><input type="hidden" name="people[]" value="<?php echo (int) $p->ID; ?>"></th><?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $items as $i => $item ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $item ); ?></th>
						<?php foreach ( $people as $p ) : ?>
							<td>
								<label class="screen-reader-text" for="wic-bm-<?php echo (int) $p->ID . '-' . (int) $i; ?>"><?php echo esc_html( $p->display_name . ': ' . $item ); ?></label>
								<select id="wic-bm-<?php echo (int) $p->ID . '-' . (int) $i; ?>" name="mark[<?php echo (int) $p->ID; ?>][<?php echo (int) $i; ?>]" required>
									<option value=""><?php esc_html_e( '—', 'wic-tp' ); ?></option>
									<?php foreach ( self::labels() as $mk => $ml ) : ?><option value="<?php echo esc_attr( $mk ); ?>"><?php echo esc_html( $ml ); ?></option><?php endforeach; ?>
								</select>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<div class="wic-form">
				<div class="wic-field"><label for="wic-bt-notes"><?php esc_html_e( 'Notes for everyone (optional)', 'wic-tp' ); ?></label><textarea id="wic-bt-notes" name="notes" rows="2"></textarea></div>
				<div class="wic-field"><label for="wic-bt-sign"><?php esc_html_e( 'Type your full name to sign every observation', 'wic-tp' ); ?></label><input type="text" id="wic-bt-sign" name="signed_name" required autocomplete="name"></div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Sign and save all', 'wic-tp' ); ?></button>
			</div>
		</form>
		<?php
	}

	public static function handle_batch() {
		check_admin_referer( 'wic_competency_batch' );
		$uid = get_current_user_id();
		$ck  = isset( $_POST['checklist'] ) ? absint( $_POST['checklist'] ) : 0;
		if ( ! current_user_can( 'wic_view_team' ) || 'wic_checklist' !== get_post_type( $ck ) ) {
			WIC_E::deny();
		}
		$signed = isset( $_POST['signed_name'] ) ? sanitize_text_field( wp_unslash( $_POST['signed_name'] ) ) : '';
		$people = isset( $_POST['people'] ) ? array_map( 'absint', (array) $_POST['people'] ) : array();
		$marks  = isset( $_POST['mark'] ) ? (array) wp_unslash( $_POST['mark'] ) : array();
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$items  = self::items( $ck );
		if ( strlen( $signed ) < 2 || ! $people ) {
			WIC_Portal::back( 'observe_batch', 'err_competency', array( 'checklist' => $ck, 'people' => $people ) );
		}
		// Validate everyone before writing anything, so a batch is all or nothing.
		$all = array();
		foreach ( $people as $pid ) {
			if ( ! $pid || $pid === $uid || ! wic_can_see_user( $uid, $pid ) ) {
				WIC_E::deny();
			}
			$m = self::collect( $items, isset( $marks[ $pid ] ) ? (array) $marks[ $pid ] : array(), array() );
			if ( null === $m ) {
				WIC_Portal::back( 'observe_batch', 'err_competency', array( 'checklist' => $ck, 'people' => $people ) );
			}
			$all[ $pid ] = $m;
		}
		$batch = wic_random_code( 8 );
		foreach ( $all as $pid => $m ) {
			self::record( $pid, $ck, $m, $notes, $signed, $batch );
		}
		WIC_Portal::back( 'observe_batch', 'competency_batch' );
	}

	public static function view_mine( $uid ) {
		$rows = self::for_user( $uid );
		echo '<h2 class="wic-h">' . esc_html__( 'Observed competencies', 'wic-tp' ) . '</h2>';
		if ( ! $rows ) {
			echo '<div class="wic-empty"><p>' . esc_html__( 'No observations have been recorded for you yet. Your supervisor records them while watching you work.', 'wic-tp' ) . '</p></div>';
			return;
		}
		self::table( $rows, false );
	}

	public static function person_section( $user_id, $viewer_id ) {
		$rows = self::for_user( $user_id );
		echo '<section class="wic-section"><h3 class="wic-h3">' . esc_html__( 'Observed competencies', 'wic-tp' ) . '</h3>';
		if ( $rows ) {
			self::table( $rows, false );
		} else {
			echo '<p class="wic-meta">' . esc_html__( 'None recorded.', 'wic-tp' ) . '</p>';
		}
		echo '</section>';
	}
}

add_action( 'wic_init', array( 'WIC_Competency', 'init' ) );
add_action( 'wic_register_types', array( 'WIC_Competency', 'register' ) );
add_action(
	'wic_install',
	function () {
		if ( get_option( 'wic_sample_checklist_seeded' ) || ! post_type_exists( 'wic_checklist' ) ) {
			return;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'wic_checklist',
				'post_status'  => 'publish',
				'post_title'   => 'Using the training portal (Sample)',
				'post_content' => 'A sample checklist to show how observation works. Replace it with your state\'s own competency checklists.',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta(
				$id,
				'_wic_items',
				array(
					'Signs in to the portal without help',
					'Finds an assigned course and opens it',
					'Resumes a course from where they left off',
					'Opens and prints a certificate',
				)
			);
		}
		update_option( 'wic_sample_checklist_seeded', 1 );
	}
);
