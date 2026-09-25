<?php
/**
 * Assignment tools: rules by role and location for new starters, "not applicable" with a
 * reason, the coming-due state, and targeting by clinic or role.
 *
 * "Not applicable" is a flag beside the assignment, not a change to it: the assignment
 * row keeps its history and the flag can be lifted again. Both are audited.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'assignment_na' ) . " (
  assignment_id bigint(20) unsigned NOT NULL,
  reason text NULL,
  marked_by bigint(20) unsigned NOT NULL DEFAULT 0,
  marked_at datetime NOT NULL,
  PRIMARY KEY  (assignment_id)
) $c;";
		$sql[] = 'CREATE TABLE ' . wic_table( 'assign_rules' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  grp varchar(20) NOT NULL DEFAULT '',
  clinic varchar(191) NOT NULL DEFAULT '',
  role varchar(60) NOT NULL DEFAULT '',
  due_days int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY course (course_id)
) $c;";
		return $sql;
	},
	10,
	2
);

add_action(
	'wic_init',
	function () {
		WIC_Assign::init();
	}
);

class WIC_Assign {

	private static $na = null;

	public static function init() {
		add_filter( 'wic_derive_status', array( __CLASS__, 'derive' ), 10, 4 );
		add_action( 'wic_apply_rules', array( __CLASS__, 'apply_rules' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		foreach ( array( 'mark_na', 'unmark_na', 'nudge', 'rule_add', 'rule_toggle', 'rule_delete', 'rules_run' ) as $a ) {
			add_action( 'admin_post_wic_' . $a, array( __CLASS__, $a ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Shared lookups                                                     */
	/* ------------------------------------------------------------------ */

	/** Roles a course can be targeted at: every platform role people learn in. */
	public static function role_options() {
		$names = wp_roles()->get_names();
		$out   = array();
		foreach ( WIC_Roles::all_roles() as $r ) {
			$role = get_role( $r );
			if ( ! $role || empty( $role->capabilities['wic_learn'] ) ) {
				continue;
			}
			$out[ $r ] = isset( $names[ $r ] ) ? translate_user_role( $names[ $r ] ) : $r;
		}
		return $out;
	}

	/** A clinic key is a clinic record ID when the clinics module is active, otherwise the clinic name. */
	public static function user_in_clinic( $user_id, $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return true;
		}
		$id = wic_user_clinic_id( $user_id );
		if ( $id && (string) $id === $key ) {
			return true;
		}
		return wic_user_clinic_name( $user_id ) === $key;
	}

	public static function user_in_any_clinic( $user_id, $keys ) {
		foreach ( (array) $keys as $k ) {
			if ( self::user_in_clinic( $user_id, $k ) ) {
				return true;
			}
		}
		return false;
	}

	public static function clinic_label( $key ) {
		$opts = wic_clinic_options();
		return isset( $opts[ $key ] ) ? $opts[ $key ] : (string) $key;
	}

	/** Active people in the viewer's scope who take training, optionally one clinic, excluding the viewer unless admin. */
	public static function people_in_scope( $viewer_id, $clinic = '' ) {
		$out = array();
		foreach ( wic_scope_user_ids( $viewer_id ) as $id ) {
			if ( ( $id === (int) $viewer_id && ! user_can( $viewer_id, 'wic_view_all' ) ) || 'active' !== wic_user_status( $id ) || ! user_can( $id, 'wic_learn' ) ) {
				continue;
			}
			if ( '' !== (string) $clinic && ! self::user_in_clinic( $id, $clinic ) ) {
				continue;
			}
			$u = get_userdata( $id );
			if ( $u ) {
				$out[] = $u;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcasecmp( $a->display_name, $b->display_name );
			}
		);
		return $out;
	}

	public static function assignment( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'assignments' ) . ' WHERE id = %d', $id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Derived states                                                     */
	/* ------------------------------------------------------------------ */

	public static function na_map() {
		global $wpdb;
		if ( null === self::$na ) {
			self::$na = array();
			$rows     = $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'assignment_na' ) );
			foreach ( (array) $rows as $r ) {
				self::$na[ (int) $r->assignment_id ] = $r;
			}
		}
		return self::$na;
	}

	public static function na_for( $assignment_id ) {
		$map = self::na_map();
		return isset( $map[ (int) $assignment_id ] ) ? $map[ (int) $assignment_id ] : null;
	}

	/** Not applicable and coming due, layered over the core derivation. A completion always wins. */
	public static function derive( $status, $assignment, $completion, $position ) {
		if ( in_array( $status, array( 'complete', 'expired' ), true ) ) {
			return $status;
		}
		if ( isset( $assignment->id ) && self::na_for( $assignment->id ) ) {
			return 'not_applicable';
		}
		if ( in_array( $status, array( 'not_started', 'in_progress' ), true ) && ! empty( $assignment->due_at ) ) {
			$due = strtotime( $assignment->due_at . ' UTC' );
			if ( $due >= time() && $due - time() <= max( 0, (int) wic_setting( 'reminder_days' ) ) * DAY_IN_SECONDS ) {
				return 'coming_due';
			}
		}
		return $status;
	}

	/* ------------------------------------------------------------------ */
	/* Rules by role and location                                         */
	/* ------------------------------------------------------------------ */

	public static function rules( $active_only = false ) {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . wic_table( 'assign_rules' ) . ( $active_only ? ' WHERE active = 1' : '' ) . ' ORDER BY id ASC' );
	}

	public static function rule_matches( $rule, $user_id ) {
		if ( $rule->grp && wic_user_group( $user_id ) !== $rule->grp ) {
			return false;
		}
		if ( $rule->role ) {
			$u = get_userdata( $user_id );
			if ( ! $u || ! in_array( $rule->role, (array) $u->roles, true ) ) {
				return false;
			}
		}
		return self::user_in_clinic( $user_id, $rule->clinic );
	}

	/** Additive only: never removes an assignment, never re-assigns something already completed. */
	public static function apply_rules( $user_id ) {
		if ( 'active' !== wic_user_status( $user_id ) ) {
			return;
		}
		foreach ( self::rules( true ) as $r ) {
			if ( ! self::rule_matches( $r, $user_id ) || WIC_Records::latest_completion( $user_id, $r->course_id ) ) {
				continue;
			}
			$due = (int) $r->due_days > 0 ? gmdate( 'Y-m-d 23:59:59', time() + (int) $r->due_days * DAY_IN_SECONDS ) : null;
			WIC_Records::assign( $user_id, (int) $r->course_id, 'rule', $due );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                           */
	/* ------------------------------------------------------------------ */

	private static function deny() {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
	}

	private static function return_view( $default ) {
		$v = isset( $_POST['return'] ) ? sanitize_key( $_POST['return'] ) : '';
		return $v ? $v : $default;
	}

	private static function return_args() {
		$args = array();
		if ( ! empty( $_POST['return_user'] ) ) {
			$args['user'] = absint( $_POST['return_user'] );
		}
		return $args;
	}

	/** Supervisor or administrator with this person in scope. */
	private static function can_manage_assignment( $a ) {
		return $a && current_user_can( 'wic_view_team' ) && (int) $a->user_id !== get_current_user_id() && wic_can_see_user( get_current_user_id(), $a->user_id );
	}

	public static function mark_na() {
		global $wpdb;
		$id = isset( $_POST['assignment'] ) ? absint( $_POST['assignment'] ) : 0;
		check_admin_referer( 'wic_na_' . $id );
		$a = self::assignment( $id );
		if ( ! self::can_manage_assignment( $a ) ) {
			self::deny();
		}
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		if ( '' === $reason ) {
			WIC_Portal::back( self::return_view( 'overdue' ), 'na_error', self::return_args() );
		}
		$wpdb->replace(
			wic_table( 'assignment_na' ),
			array(
				'assignment_id' => $id,
				'reason'        => $reason,
				'marked_by'     => get_current_user_id(),
				'marked_at'     => wic_now(),
			)
		);
		self::$na = null;
		wic_audit( 'assignment_not_applicable', 'assignment', $id, array( 'user' => (int) $a->user_id, 'course' => (int) $a->course_id, 'reason' => $reason ) );
		WIC_Notify::event( (int) $a->user_id, 'not_applicable', $id, sprintf( __( '"%1$s" has been marked not applicable to you: %2$s', 'wic-tp' ), get_the_title( $a->course_id ), $reason ), false );
		WIC_Portal::back( self::return_view( 'overdue' ), 'na_marked', self::return_args() );
	}

	public static function unmark_na() {
		global $wpdb;
		$id = isset( $_POST['assignment'] ) ? absint( $_POST['assignment'] ) : 0;
		check_admin_referer( 'wic_unna_' . $id );
		$a = self::assignment( $id );
		if ( ! self::can_manage_assignment( $a ) ) {
			self::deny();
		}
		$wpdb->delete( wic_table( 'assignment_na' ), array( 'assignment_id' => $id ) );
		self::$na = null;
		wic_audit( 'assignment_applicable_again', 'assignment', $id, array( 'user' => (int) $a->user_id, 'course' => (int) $a->course_id ) );
		WIC_Portal::back( self::return_view( 'team' ), 'na_lifted', self::return_args() );
	}

	/** One person, one course: queued like every other send, and logged. */
	public static function nudge() {
		$id = isset( $_POST['assignment'] ) ? absint( $_POST['assignment'] ) : 0;
		check_admin_referer( 'wic_nudge_' . $id );
		$a = self::assignment( $id );
		if ( ! self::can_manage_assignment( $a ) ) {
			self::deny();
		}
		$due = $a->due_at ? wic_format_date( $a->due_at ) : __( 'no set date', 'wic-tp' );
		WIC_Notify::event(
			(int) $a->user_id,
			'reminder',
			$id,
			sprintf( __( 'Reminder from %1$s: "%2$s" (due %3$s). Please complete it as soon as you can.', 'wic-tp' ), wp_get_current_user()->display_name, get_the_title( $a->course_id ), $due ),
			true
		);
		wic_audit( 'nudge', 'assignment', $id, array( 'user' => (int) $a->user_id ) );
		WIC_Portal::back( self::return_view( 'overdue' ), 'nudged', self::return_args() );
	}

	public static function rule_add() {
		global $wpdb;
		check_admin_referer( 'wic_rule_add' );
		if ( ! current_user_can( 'wic_view_all' ) ) {
			self::deny();
		}
		$course = isset( $_POST['course'] ) ? absint( $_POST['course'] ) : 0;
		if ( 'wic_course' !== get_post_type( $course ) ) {
			WIC_Portal::back( 'rules', 'error' );
		}
		$grp  = isset( $_POST['grp'] ) && in_array( $_POST['grp'], array( 'staff', 'intern' ), true ) ? sanitize_key( $_POST['grp'] ) : '';
		$role = isset( $_POST['role'] ) && array_key_exists( $_POST['role'], self::role_options() ) ? sanitize_key( $_POST['role'] ) : '';
		$wpdb->insert(
			wic_table( 'assign_rules' ),
			array(
				'course_id'  => $course,
				'grp'        => $grp,
				'clinic'     => isset( $_POST['clinic'] ) ? sanitize_text_field( wp_unslash( $_POST['clinic'] ) ) : '',
				'role'       => $role,
				'due_days'   => isset( $_POST['due_days'] ) ? absint( $_POST['due_days'] ) : 0,
				'active'     => 1,
				'created_by' => get_current_user_id(),
				'created_at' => wic_now(),
			)
		);
		wic_audit( 'rule_add', 'rule', $wpdb->insert_id, array( 'course' => $course ) );
		WIC_Portal::back( 'rules', 'rule_added' );
	}

	public static function rule_toggle() {
		global $wpdb;
		$id = isset( $_POST['rule'] ) ? absint( $_POST['rule'] ) : 0;
		check_admin_referer( 'wic_rule_' . $id );
		if ( ! current_user_can( 'wic_view_all' ) ) {
			self::deny();
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . wic_table( 'assign_rules' ) . ' SET active = 1 - active WHERE id = %d', $id ) );
		wic_audit( 'rule_toggle', 'rule', $id );
		WIC_Portal::back( 'rules', 'saved' );
	}

	/** Removing a rule only stops future assignments; everything it already assigned stays. */
	public static function rule_delete() {
		global $wpdb;
		$id = isset( $_POST['rule'] ) ? absint( $_POST['rule'] ) : 0;
		check_admin_referer( 'wic_rule_' . $id );
		if ( ! current_user_can( 'wic_view_all' ) ) {
			self::deny();
		}
		$wpdb->delete( wic_table( 'assign_rules' ), array( 'id' => $id ) );
		wic_audit( 'rule_delete', 'rule', $id );
		WIC_Portal::back( 'rules', 'rule_deleted' );
	}

	/** Apply every rule to everyone already in scope — for rules added after people joined. */
	public static function rules_run() {
		check_admin_referer( 'wic_rules_run' );
		if ( ! current_user_can( 'wic_view_all' ) ) {
			self::deny();
		}
		foreach ( self::people_in_scope( get_current_user_id() ) as $u ) {
			WIC_Records::apply_rules( $u->ID );
		}
		wic_audit( 'rules_run', 'rule', 0 );
		WIC_Portal::back( 'rules', 'rules_run' );
	}

	/* ------------------------------------------------------------------ */
	/* UI                                                                 */
	/* ------------------------------------------------------------------ */

	public static function messages( $m ) {
		return $m + array(
			'na_marked'    => __( 'Marked not applicable. The reason is on the record and it can be reversed.', 'wic-tp' ),
			'na_lifted'    => __( 'The course applies again and is back on their list.', 'wic-tp' ),
			'na_error'     => __( 'A reason is needed to mark a course not applicable.', 'wic-tp' ),
			'nudged'       => __( 'Reminder queued. It will be sent within the hour.', 'wic-tp' ),
			'rule_added'   => __( 'Rule added. It applies to new starters, and to others when you apply rules now.', 'wic-tp' ),
			'rule_deleted' => __( 'Rule removed. Courses it already assigned stay assigned.', 'wic-tp' ),
			'rules_run'    => __( 'Rules applied to everyone in scope. Nothing already assigned or completed was changed.', 'wic-tp' ),
		);
	}

	public static function views( $views ) {
		$views['rules'] = array(
			'label'    => __( 'Assignment rules', 'wic-tp' ),
			'group'    => 'agency',
			'cap'      => 'wic_view_all',
			'callback' => array( __CLASS__, 'view_rules' ),
			'order'    => 20,
		);
		return $views;
	}

	/** The "not applicable" / "applies again" controls for one assignment row. */
	public static function na_controls( $a, $return_view, $return_user = 0 ) {
		$aid = (int) $a['assignment_id'];
		ob_start();
		if ( 'not_applicable' === $a['status'] ) :
			$na = self::na_for( $aid );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
				<input type="hidden" name="action" value="wic_unmark_na">
				<input type="hidden" name="assignment" value="<?php echo (int) $aid; ?>">
				<input type="hidden" name="return" value="<?php echo esc_attr( $return_view ); ?>">
				<input type="hidden" name="return_user" value="<?php echo (int) $return_user; ?>">
				<?php wp_nonce_field( 'wic_unna_' . $aid ); ?>
				<span class="wic-meta"><?php echo esc_html( $na ? $na->reason : '' ); ?></span>
				<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Applies again', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $a['title'] ); ?></span></button>
			</form>
			<?php
		elseif ( ! in_array( $a['status'], array( 'complete', 'expired' ), true ) ) :
			?>
			<details class="wic-more">
				<summary><?php esc_html_e( 'Not applicable…', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $a['title'] ); ?></span></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
					<input type="hidden" name="action" value="wic_mark_na">
					<input type="hidden" name="assignment" value="<?php echo (int) $aid; ?>">
					<input type="hidden" name="return" value="<?php echo esc_attr( $return_view ); ?>">
					<input type="hidden" name="return_user" value="<?php echo (int) $return_user; ?>">
					<?php wp_nonce_field( 'wic_na_' . $aid ); ?>
					<label class="screen-reader-text" for="wic-na-<?php echo (int) $aid; ?>"><?php esc_html_e( 'Reason it does not apply', 'wic-tp' ); ?></label>
					<input type="text" id="wic-na-<?php echo (int) $aid; ?>" name="reason" required placeholder="<?php esc_attr_e( 'Reason', 'wic-tp' ); ?>">
					<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Mark not applicable', 'wic-tp' ); ?></button>
				</form>
			</details>
			<?php
		endif;
		return ob_get_clean();
	}

	public static function nudge_button( $a, $return_view, $return_user = 0 ) {
		$aid = (int) $a['assignment_id'];
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
			<input type="hidden" name="action" value="wic_nudge">
			<input type="hidden" name="assignment" value="<?php echo (int) $aid; ?>">
			<input type="hidden" name="return" value="<?php echo esc_attr( $return_view ); ?>">
			<input type="hidden" name="return_user" value="<?php echo (int) $return_user; ?>">
			<?php wp_nonce_field( 'wic_nudge_' . $aid ); ?>
			<button type="submit" class="wic-btn wic-btn--small"><?php esc_html_e( 'Nudge', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $a['title'] ); ?></span></button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function course_options() {
		return get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	public static function view_rules( $uid ) {
		$rules   = self::rules();
		$courses = self::course_options();
		$roles   = self::role_options();
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Assignment rules', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'New starters get these courses automatically when they are approved or change group, based on their group, role and clinic. Rules only ever add: nothing assigned or completed is removed.', 'wic-tp' ); ?></p>
		<?php if ( $rules ) : ?>
			<div class="wic-table-wrap">
			<table class="wic-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Group', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Role', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Due within', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'wic-tp' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wic-tp' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rules as $r ) : ?>
					<tr>
						<td><?php echo esc_html( get_the_title( $r->course_id ) ); ?></td>
						<td><?php echo $r->grp ? esc_html( wic_group_label( $r->grp ) ) : esc_html__( 'Any', 'wic-tp' ); ?></td>
						<td><?php echo $r->role ? esc_html( isset( $roles[ $r->role ] ) ? $roles[ $r->role ] : $r->role ) : esc_html__( 'Any', 'wic-tp' ); ?></td>
						<td><?php echo '' !== $r->clinic ? esc_html( self::clinic_label( $r->clinic ) ) : esc_html__( 'Any', 'wic-tp' ); ?></td>
						<td><?php echo (int) $r->due_days ? esc_html( sprintf( _n( '%d day', '%d days', (int) $r->due_days, 'wic-tp' ), (int) $r->due_days ) ) : esc_html__( 'Course default', 'wic-tp' ); ?></td>
						<td><?php echo $r->active ? esc_html__( 'On', 'wic-tp' ) : esc_html__( 'Paused', 'wic-tp' ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-inline-form">
								<input type="hidden" name="rule" value="<?php echo (int) $r->id; ?>">
								<?php wp_nonce_field( 'wic_rule_' . $r->id ); ?>
								<button type="submit" name="action" value="wic_rule_toggle" class="wic-btn wic-btn--small"><?php echo $r->active ? esc_html__( 'Pause', 'wic-tp' ) : esc_html__( 'Turn on', 'wic-tp' ); ?></button>
								<button type="submit" name="action" value="wic_rule_delete" class="wic-btn wic-btn--small" data-wic-confirm="<?php esc_attr_e( 'Remove this rule? Courses it already assigned stay assigned.', 'wic-tp' ); ?>"><?php esc_html_e( 'Remove', 'wic-tp' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-toolbar" style="margin-top:1rem">
				<input type="hidden" name="action" value="wic_rules_run">
				<?php wp_nonce_field( 'wic_rules_run' ); ?>
				<p class="wic-help"><?php esc_html_e( 'Rules run on their own for new starters. To apply them to people already here, run them now.', 'wic-tp' ); ?></p>
				<button type="submit" class="wic-btn" data-wic-confirm="<?php esc_attr_e( 'Apply every active rule to everyone in scope?', 'wic-tp' ); ?>"><?php esc_html_e( 'Apply rules to everyone now', 'wic-tp' ); ?></button>
			</form>
		<?php else : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No rules yet. Required courses set to auto-assign by group still apply; rules add role and clinic.', 'wic-tp' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel" style="margin-top:1.5rem">
			<h3 class="wic-h3"><?php esc_html_e( 'Add a rule', 'wic-tp' ); ?></h3>
			<input type="hidden" name="action" value="wic_rule_add">
			<?php wp_nonce_field( 'wic_rule_add' ); ?>
			<div class="wic-field">
				<label for="wic-rule-course"><?php esc_html_e( 'Course', 'wic-tp' ); ?></label>
				<select id="wic-rule-course" name="course" required>
					<?php foreach ( $courses as $c ) : ?>
						<option value="<?php echo (int) $c->ID; ?>"><?php echo esc_html( $c->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="wic-field">
				<label for="wic-rule-grp"><?php esc_html_e( 'Group', 'wic-tp' ); ?></label>
				<select id="wic-rule-grp" name="grp">
					<option value=""><?php esc_html_e( 'Any group', 'wic-tp' ); ?></option>
					<option value="staff"><?php esc_html_e( 'Staff', 'wic-tp' ); ?></option>
					<option value="intern"><?php esc_html_e( 'Intern', 'wic-tp' ); ?></option>
				</select>
			</div>
			<div class="wic-field">
				<label for="wic-rule-role"><?php esc_html_e( 'Role', 'wic-tp' ); ?></label>
				<select id="wic-rule-role" name="role">
					<option value=""><?php esc_html_e( 'Any role', 'wic-tp' ); ?></option>
					<?php foreach ( $roles as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="wic-field">
				<label for="wic-rule-clinic"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></label>
				<select id="wic-rule-clinic" name="clinic">
					<option value=""><?php esc_html_e( 'Any clinic', 'wic-tp' ); ?></option>
					<?php foreach ( wic_clinic_options() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="wic-field">
				<label for="wic-rule-due"><?php esc_html_e( 'Due within (days)', 'wic-tp' ); ?></label>
				<input type="number" min="0" id="wic-rule-due" name="due_days" value="0">
				<p class="wic-help"><?php esc_html_e( '0 uses the course\'s own default.', 'wic-tp' ); ?></p>
			</div>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Add rule', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	/**
	 * The shared "assign a course" form: administrators target anyone in the agency,
	 * supervisors their own people. Posts to wic_assign in class-actions.
	 */
	public static function assign_form( $uid, $return_view ) {
		$courses = self::course_options();
		$people  = self::people_in_scope( $uid );
		$all     = user_can( $uid, 'wic_view_all' );
		$clinics = array();
		foreach ( $people as $p ) {
			$key = wic_user_clinic_id( $p->ID ) ? (string) wic_user_clinic_id( $p->ID ) : wic_user_clinic_name( $p->ID );
			if ( '' !== $key ) {
				$clinics[ $key ] = self::clinic_label( $key );
			}
		}
		if ( $all ) {
			$clinics = wic_clinic_options() + $clinics;
		}
		asort( $clinics );
		$pfx = 'wic-as-' . $return_view;
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
			<h3 class="wic-h3"><?php esc_html_e( 'Assign a course', 'wic-tp' ); ?></h3>
			<input type="hidden" name="action" value="wic_assign">
			<input type="hidden" name="return" value="<?php echo esc_attr( $return_view ); ?>">
			<?php wp_nonce_field( 'wic_assign' ); ?>
			<div class="wic-field">
				<label for="<?php echo esc_attr( $pfx ); ?>-course"><?php esc_html_e( 'Course', 'wic-tp' ); ?></label>
				<select id="<?php echo esc_attr( $pfx ); ?>-course" name="course" required>
					<?php foreach ( $courses as $c ) : ?>
						<option value="<?php echo (int) $c->ID; ?>"><?php echo esc_html( $c->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="wic-field">
				<label for="<?php echo esc_attr( $pfx ); ?>-target"><?php esc_html_e( 'Assign to', 'wic-tp' ); ?></label>
				<select id="<?php echo esc_attr( $pfx ); ?>-target" name="target">
					<option value="all"><?php echo $all ? esc_html__( 'Everyone', 'wic-tp' ) : esc_html__( 'Everyone on my team', 'wic-tp' ); ?></option>
					<optgroup label="<?php esc_attr_e( 'Group', 'wic-tp' ); ?>">
						<option value="group:staff"><?php esc_html_e( 'All staff', 'wic-tp' ); ?></option>
						<option value="group:intern"><?php esc_html_e( 'All interns', 'wic-tp' ); ?></option>
					</optgroup>
					<?php if ( $all ) : ?>
						<optgroup label="<?php esc_attr_e( 'Role', 'wic-tp' ); ?>">
							<?php foreach ( self::role_options() as $k => $label ) : ?>
								<option value="role:<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endif; ?>
					<?php if ( $clinics ) : ?>
						<optgroup label="<?php esc_attr_e( 'Clinic', 'wic-tp' ); ?>">
							<?php foreach ( $clinics as $k => $label ) : ?>
								<option value="clinic:<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endif; ?>
					<optgroup label="<?php esc_attr_e( 'Person', 'wic-tp' ); ?>">
						<?php foreach ( $people as $p ) : ?>
							<option value="user:<?php echo (int) $p->ID; ?>"><?php echo esc_html( $p->display_name ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				</select>
			</div>
			<div class="wic-field">
				<label for="<?php echo esc_attr( $pfx ); ?>-due"><?php esc_html_e( 'Due date (optional)', 'wic-tp' ); ?></label>
				<input type="date" id="<?php echo esc_attr( $pfx ); ?>-due" name="due" min="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
				<p class="wic-help"><?php esc_html_e( 'Leave empty to use the course default.', 'wic-tp' ); ?></p>
			</div>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Assign', 'wic-tp' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}
}
