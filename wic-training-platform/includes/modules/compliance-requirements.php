<?php
/**
 * Requirements and credit types.
 *
 * A requirement is what an agency must be able to evidence ("annual civil rights training").
 * Each course records which requirements it meets — the crosswalk that turns a list of
 * courses into evidence. Requirements say who they apply to, which is what the gap
 * report ("who lacks a certification their role requires") is a query over.
 *
 * A course can also carry several continuing-education credits (CPEU, CERP, nursing CE),
 * each with its own hours, because they come from different bodies.
 */

defined( 'ABSPATH' ) || exit;

// Registered at load time so plugin activation (which runs before `wic_init`) sees them.
add_action( 'wic_register_types', array( 'WIC_Requirements', 'register_type' ) );
add_action( 'wic_install', array( 'WIC_Requirements', 'seed' ) );
add_action(
	'wic_init',
	function () {
		WIC_Requirements::init();
	}
);

class WIC_Requirements {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_type' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 20, 2 );
	}

	public static function register_type() {
		register_post_type(
			'wic_requirement',
			array(
				'labels'          => array(
					'name'          => __( 'Requirements', 'wic-tp' ),
					'singular_name' => __( 'Requirement', 'wic-tp' ),
					'add_new_item'  => __( 'Add requirement', 'wic-tp' ),
					'edit_item'     => __( 'Edit requirement', 'wic-tp' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wic-platform',
				'capability_type' => array( 'wic_item', 'wic_items' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'revisions' ),
			)
		);
	}

	/** Two placeholders so the crosswalk and the gap report have something to show. No citations are invented. */
	public static function seed() {
		if ( get_option( 'wic_requirements_seeded' ) ) {
			return;
		}
		update_option( 'wic_requirements_seeded', 1 );
		$course = get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$defs   = array(
			array( 'Portal orientation (Sample)', 0, $course ),
			array( 'Annual refresher (Sample)', 12, array() ),
		);
		foreach ( $defs as $d ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'wic_requirement',
					'post_status'  => 'publish',
					'post_title'   => $d[0],
					'post_content' => 'Sample requirement for trying the crosswalk and gap report. Replace it with a real requirement and fill in its source.',
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, '_wic_req_source', '' );
			update_post_meta( $id, '_wic_req_months', (string) $d[1] );
			update_post_meta( $id, '_wic_req_groups', array( 'staff', 'intern' ) );
			update_post_meta( $id, '_wic_req_roles', array() );
			update_post_meta( $id, '_wic_req_clinics', array() );
			foreach ( $d[2] as $cid ) {
				$list   = self::ids_for_course( $cid );
				$list[] = $id;
				update_post_meta( $cid, '_wic_requirements', array_values( array_unique( $list ) ) );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Queries                                                            */
	/* ------------------------------------------------------------------ */

	public static function all() {
		return get_posts(
			array(
				'post_type'      => 'wic_requirement',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	public static function ids_for_course( $course_id ) {
		return array_values( array_filter( array_map( 'intval', (array) get_post_meta( $course_id, '_wic_requirements', true ) ) ) );
	}

	/** Requirement posts a course meets. */
	public static function for_course( $course_id ) {
		$ids = self::ids_for_course( $course_id );
		if ( ! $ids ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'wic_requirement',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/** Published courses that meet a requirement. */
	public static function courses_for( $req_id ) {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) as $c ) {
			if ( in_array( (int) $req_id, self::ids_for_course( $c->ID ), true ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	public static function months( $req_id ) {
		return (int) get_post_meta( $req_id, '_wic_req_months', true );
	}

	public static function frequency_label( $req_id ) {
		$m = self::months( $req_id );
		if ( ! $m ) {
			return __( 'Once', 'wic-tp' );
		}
		return 12 === $m ? __( 'Every year', 'wic-tp' ) : sprintf( _n( 'Every %d month', 'Every %d months', $m, 'wic-tp' ), $m );
	}

	/** Empty lists mean "everyone"; all three must match. */
	public static function applies_to( $req_id, $user_id ) {
		$groups  = array_filter( (array) get_post_meta( $req_id, '_wic_req_groups', true ) );
		$roles   = array_filter( (array) get_post_meta( $req_id, '_wic_req_roles', true ) );
		$clinics = array_filter( array_map( 'strval', (array) get_post_meta( $req_id, '_wic_req_clinics', true ) ) );
		if ( $groups && ! in_array( wic_user_group( $user_id ), $groups, true ) ) {
			return false;
		}
		if ( $roles ) {
			$u = get_userdata( $user_id );
			if ( ! $u || ! array_intersect( $roles, (array) $u->roles ) ) {
				return false;
			}
		}
		if ( $clinics && ! WIC_Assign::user_in_any_clinic( $user_id, $clinics ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a person holds the requirement now: a completion of any course that meets it,
	 * whose certificate is not revoked, that has not expired, and that is inside the
	 * requirement's own frequency. Returns the evidence row or null.
	 */
	public static function evidence( $req_id, $user_id ) {
		global $wpdb;
		$best   = null;
		$months = self::months( $req_id );
		foreach ( self::courses_for( $req_id ) as $c ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'completions' ) . ' WHERE user_id = %d AND course_id = %d ORDER BY completed_at DESC', $user_id, $c->ID ) );
			foreach ( $rows as $r ) {
				$cert = WIC_Certificates::for_completion( $r->id );
				if ( $cert && 'valid' !== WIC_Certificates::state( $cert ) ) {
					continue;
				}
				$validity = (int) get_post_meta( $c->ID, '_wic_validity_months', true );
				$done     = strtotime( $r->completed_at . ' UTC' );
				if ( $validity > 0 && strtotime( '+' . $validity . ' months', $done ) < time() ) {
					continue;
				}
				if ( $months > 0 && strtotime( '+' . $months . ' months', $done ) < time() ) {
					continue;
				}
				if ( ! $best || $r->completed_at > $best->completed_at ) {
					$best         = $r;
					$best->course = $c->post_title;
				}
				break;
			}
		}
		return $best;
	}

	/** Gap rows: people in scope a requirement applies to who hold no current evidence. */
	public static function gaps( $viewer_id, $req_id = 0, $clinic = '' ) {
		$reqs = $req_id ? array_filter( array( get_post( $req_id ) ) ) : self::all();
		$out  = array();
		foreach ( WIC_Assign::people_in_scope( $viewer_id, $clinic ) as $u ) {
			foreach ( $reqs as $r ) {
				if ( ! self::applies_to( $r->ID, $u->ID ) || self::evidence( $r->ID, $u->ID ) ) {
					continue;
				}
				global $wpdb;
				$last  = null;
				$c_ids = wp_list_pluck( self::courses_for( $r->ID ), 'ID' );
				if ( $c_ids ) {
					$last = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(completed_at) FROM ' . wic_table( 'completions' ) . ' WHERE user_id = %d AND course_id IN (' . implode( ',', array_map( 'intval', $c_ids ) ) . ')', $u->ID ) );
				}
				$out[] = array(
					'user'        => $u,
					'requirement' => $r,
					'courses'     => $c_ids,
					'last'        => $last,
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Authoring                                                          */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'wic_req_meta', __( 'Requirement details', 'wic-tp' ), array( __CLASS__, 'req_box' ), 'wic_requirement', 'normal', 'high' );
		add_meta_box( 'wic_course_reqs', __( 'Requirements this course meets', 'wic-tp' ), array( __CLASS__, 'course_box' ), 'wic_course', 'side' );
		add_meta_box( 'wic_course_credits', __( 'Continuing education credits', 'wic-tp' ), array( __CLASS__, 'credits_box' ), 'wic_course', 'normal' );
	}

	public static function req_box( $post ) {
		wp_nonce_field( 'wic_req_meta', 'wic_req_nonce' );
		$groups  = (array) get_post_meta( $post->ID, '_wic_req_groups', true );
		$roles   = (array) get_post_meta( $post->ID, '_wic_req_roles', true );
		$clinics = array_map( 'strval', (array) get_post_meta( $post->ID, '_wic_req_clinics', true ) );
		?>
		<table class="form-table" role="presentation">
			<tr><th><label for="wic_req_source"><?php esc_html_e( 'Source / citation', 'wic-tp' ); ?></label></th>
				<td><input type="text" class="large-text" id="wic_req_source" name="wic_req_source" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_req_source', true ) ); ?>">
					<p class="description"><?php esc_html_e( 'Where the requirement comes from, as written in the source. Leave empty until it has been checked.', 'wic-tp' ); ?></p></td></tr>
			<tr><th><label for="wic_req_months"><?php esc_html_e( 'Must be renewed every (months)', 'wic-tp' ); ?></label></th>
				<td><input type="number" min="0" id="wic_req_months" name="wic_req_months" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_req_months', true ) ); ?>" placeholder="0"> <span class="description"><?php esc_html_e( '0 = once.', 'wic-tp' ); ?></span></td></tr>
			<tr><th><?php esc_html_e( 'Required for groups', 'wic-tp' ); ?></th>
				<td>
					<?php foreach ( array( 'staff', 'intern' ) as $g ) : ?>
						<label><input type="checkbox" name="wic_req_groups[]" value="<?php echo esc_attr( $g ); ?>" <?php checked( in_array( $g, $groups, true ) ); ?>> <?php echo esc_html( wic_group_label( $g ) ); ?></label> &nbsp;
					<?php endforeach; ?>
				</td></tr>
			<tr><th><?php esc_html_e( 'Required for roles', 'wic-tp' ); ?></th>
				<td>
					<?php foreach ( WIC_Assign::role_options() as $r => $label ) : ?>
						<label><input type="checkbox" name="wic_req_roles[]" value="<?php echo esc_attr( $r ); ?>" <?php checked( in_array( $r, $roles, true ) ); ?>> <?php echo esc_html( $label ); ?></label> &nbsp;
					<?php endforeach; ?>
				</td></tr>
			<tr><th><?php esc_html_e( 'Required at clinics', 'wic-tp' ); ?></th>
				<td>
					<?php foreach ( wic_clinic_options() as $k => $label ) : ?>
						<label><input type="checkbox" name="wic_req_clinics[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( (string) $k, $clinics, true ) ); ?>> <?php echo esc_html( $label ); ?></label> &nbsp;
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Leave a row empty to mean everyone. All ticked rows must match.', 'wic-tp' ); ?></p>
				</td></tr>
		</table>
		<?php if ( $post->ID ) : ?>
			<h4><?php esc_html_e( 'Met by', 'wic-tp' ); ?></h4>
			<?php $courses = self::courses_for( $post->ID ); ?>
			<?php if ( ! $courses ) : ?>
				<p><?php esc_html_e( 'No course meets this requirement yet. Tick it in a course\'s "Requirements this course meets" box.', 'wic-tp' ); ?></p>
			<?php else : ?>
				<ul><?php foreach ( $courses as $c ) : ?><li><a href="<?php echo esc_url( get_edit_post_link( $c->ID ) ); ?>"><?php echo esc_html( $c->post_title ); ?></a></li><?php endforeach; ?></ul>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	public static function course_box( $post ) {
		wp_nonce_field( 'wic_course_reqs', 'wic_course_reqs_nonce' );
		$mine = self::ids_for_course( $post->ID );
		$all  = self::all();
		if ( ! $all ) {
			echo '<p>' . esc_html__( 'No requirements defined yet.', 'wic-tp' ) . ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=wic_requirement' ) ) . '">' . esc_html__( 'Add one', 'wic-tp' ) . '</a></p>';
			return;
		}
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Requirements', 'wic-tp' ) . '</legend>';
		foreach ( $all as $r ) {
			echo '<label style="display:block;margin:.25rem 0"><input type="checkbox" name="wic_course_reqs[]" value="' . (int) $r->ID . '" ' . checked( in_array( (int) $r->ID, $mine, true ), true, false ) . '> ' . esc_html( $r->post_title ) . '</label>';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Completing this course counts as evidence for each ticked requirement.', 'wic-tp' ) . '</p>';
	}

	public static function credits_box( $post ) {
		wp_nonce_field( 'wic_course_credits', 'wic_course_credits_nonce' );
		$rows = WIC_Credits::stored( $post->ID );
		$rows = array_pad( $rows, max( 4, count( $rows ) + 2 ), array( 'type' => '', 'hours' => '' ) );
		?>
		<p class="description"><?php esc_html_e( 'Each credit type is awarded by a different body, so record each with its own hours. Leave empty to use the single credit type and hours in Course settings.', 'wic-tp' ); ?></p>
		<table class="widefat" style="max-width:520px">
			<thead><tr><th scope="col"><?php esc_html_e( 'Credit type', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Hours', 'wic-tp' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $i => $r ) : ?>
				<tr>
					<td><label class="screen-reader-text" for="wic_cr_t<?php echo (int) $i; ?>"><?php esc_html_e( 'Credit type', 'wic-tp' ); ?></label>
						<input type="text" id="wic_cr_t<?php echo (int) $i; ?>" name="wic_credits[<?php echo (int) $i; ?>][type]" value="<?php echo esc_attr( $r['type'] ); ?>" list="wic-credit-types" class="regular-text"></td>
					<td><label class="screen-reader-text" for="wic_cr_h<?php echo (int) $i; ?>"><?php esc_html_e( 'Hours', 'wic-tp' ); ?></label>
						<input type="number" step="0.25" min="0" id="wic_cr_h<?php echo (int) $i; ?>" name="wic_credits[<?php echo (int) $i; ?>][hours]" value="<?php echo esc_attr( $r['hours'] ); ?>" style="width:6rem"></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<datalist id="wic-credit-types"><option value="CPEU"><option value="CERP"><option value="Nursing CE"></datalist>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( 'wic_requirement' === $post->post_type && isset( $_POST['wic_req_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wic_req_nonce'] ), 'wic_req_meta' ) ) {
			update_post_meta( $post_id, '_wic_req_source', isset( $_POST['wic_req_source'] ) ? sanitize_text_field( wp_unslash( $_POST['wic_req_source'] ) ) : '' );
			update_post_meta( $post_id, '_wic_req_months', isset( $_POST['wic_req_months'] ) && '' !== $_POST['wic_req_months'] ? (string) absint( $_POST['wic_req_months'] ) : '0' );
			update_post_meta( $post_id, '_wic_req_groups', isset( $_POST['wic_req_groups'] ) ? array_values( array_intersect( (array) wp_unslash( $_POST['wic_req_groups'] ), array( 'staff', 'intern' ) ) ) : array() );
			update_post_meta( $post_id, '_wic_req_roles', isset( $_POST['wic_req_roles'] ) ? array_values( array_intersect( (array) wp_unslash( $_POST['wic_req_roles'] ), array_keys( WIC_Assign::role_options() ) ) ) : array() );
			update_post_meta( $post_id, '_wic_req_clinics', isset( $_POST['wic_req_clinics'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['wic_req_clinics'] ) ) : array() );
			wic_audit( 'requirement_save', 'requirement', $post_id );
		}
		if ( 'wic_course' !== $post->post_type ) {
			return;
		}
		if ( isset( $_POST['wic_course_reqs_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wic_course_reqs_nonce'] ), 'wic_course_reqs' ) ) {
			$ids = isset( $_POST['wic_course_reqs'] ) ? array_values( array_filter( array_map( 'absint', (array) $_POST['wic_course_reqs'] ) ) ) : array();
			if ( self::ids_for_course( $post_id ) !== $ids ) {
				wic_audit( 'course_requirements', 'course', $post_id, array( 'requirements' => $ids ) );
			}
			update_post_meta( $post_id, '_wic_requirements', $ids );
		}
		if ( isset( $_POST['wic_course_credits_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wic_course_credits_nonce'] ), 'wic_course_credits' ) ) {
			$out = array();
			foreach ( isset( $_POST['wic_credits'] ) ? (array) wp_unslash( $_POST['wic_credits'] ) : array() as $r ) {
				$type = isset( $r['type'] ) ? sanitize_text_field( $r['type'] ) : '';
				if ( '' === $type ) {
					continue;
				}
				$out[] = array(
					'type'  => $type,
					'hours' => isset( $r['hours'] ) ? round( (float) $r['hours'], 2 ) : 0,
				);
			}
			update_post_meta( $post_id, '_wic_credits', $out ? wp_json_encode( $out ) : '' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Portal views                                                       */
	/* ------------------------------------------------------------------ */

	public static function view_requirements( $uid ) {
		$reqs = self::all();
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Requirements', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'What the agency must be able to evidence, and which courses count as evidence for each.', 'wic-tp' ); ?>
			<?php if ( current_user_can( 'wic_manage_content' ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=wic_requirement' ) ); ?>"><?php esc_html_e( 'Add a requirement', 'wic-tp' ); ?></a>
			<?php endif; ?></p>
		<?php if ( ! $reqs ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No requirements defined yet.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table" data-wic-sortable>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Requirement', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Source', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Frequency', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Met by', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'People without it', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $reqs as $r ) : ?>
				<?php
				$courses = self::courses_for( $r->ID );
				$gap     = count( self::gaps( $uid, $r->ID ) );
				$source  = get_post_meta( $r->ID, '_wic_req_source', true );
				?>
				<tr>
					<td><?php echo esc_html( $r->post_title ); ?>
						<?php if ( current_user_can( 'edit_post', $r->ID ) ) : ?> <a class="wic-link" href="<?php echo esc_url( get_edit_post_link( $r->ID ) ); ?>"><?php esc_html_e( 'Edit', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $r->post_title ); ?></span></a><?php endif; ?></td>
					<td><?php echo $source ? esc_html( $source ) : '<span class="wic-na">' . esc_html__( 'Not recorded', 'wic-tp' ) . '</span>'; ?></td>
					<td><?php echo esc_html( self::frequency_label( $r->ID ) ); ?></td>
					<td><?php echo $courses ? esc_html( implode( ', ', wp_list_pluck( $courses, 'post_title' ) ) ) : '<strong class="wic-late">' . esc_html__( 'No course yet', 'wic-tp' ) . '</strong>'; ?></td>
					<td data-sort="<?php echo (int) $gap; ?>"><a href="<?php echo esc_url( wic_portal_url( 'gaps', array( 'req' => $r->ID ) ) ); ?>"><?php echo (int) $gap; ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	public static function view_gaps( $uid ) {
		$req    = isset( $_GET['req'] ) ? absint( $_GET['req'] ) : 0;
		$clinic = isset( $_GET['clinic'] ) ? sanitize_text_field( wp_unslash( $_GET['clinic'] ) ) : '';
		$rows   = self::gaps( $uid, $req, $clinic );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Who lacks a required certification', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'People a requirement applies to who hold no current completion of any course that meets it. Revoked and expired certificates do not count.', 'wic-tp' ); ?></p>
		<form method="get" class="wic-form wic-form--inline" action="<?php echo esc_url( wic_page_url( 'portal' ) ); ?>">
			<input type="hidden" name="view" value="gaps">
			<?php WIC_Reports::page_id_field(); ?>
			<div class="wic-field">
				<label for="wic-gap-req"><?php esc_html_e( 'Requirement', 'wic-tp' ); ?></label>
				<select id="wic-gap-req" name="req">
					<option value="0"><?php esc_html_e( 'All requirements', 'wic-tp' ); ?></option>
					<?php foreach ( self::all() as $r ) : ?>
						<option value="<?php echo (int) $r->ID; ?>" <?php selected( $req, $r->ID ); ?>><?php echo esc_html( $r->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php WIC_Reports::clinic_field( $clinic, 'wic-gap-clinic' ); ?>
			<button type="submit" class="wic-btn"><?php esc_html_e( 'Show', 'wic-tp' ); ?></button>
		</form>
		<?php WIC_Reports::export_links( 'gaps', array( 'req' => $req, 'clinic' => $clinic ) ); ?>
		<?php WIC_Saved_Reports::save_form( 'gaps', array( 'req' => $req, 'clinic' => $clinic ) ); ?>
		<?php if ( ! $rows ) : ?>
			<div class="wic-empty wic-empty--good"><p><?php esc_html_e( 'Nobody in this selection is missing a required certification.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table" data-wic-sortable>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Name', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Clinic', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Requirement', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last completed', 'wic-tp' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Met by', 'wic-tp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $g ) : ?>
				<tr>
					<td><?php echo WIC_Reports::person_link( $g['user'] ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( wic_user_clinic_name( $g['user']->ID ) ); ?></td>
					<td><?php echo esc_html( $g['requirement']->post_title ); ?></td>
					<td data-sort="<?php echo esc_attr( (string) $g['last'] ); ?>"><?php echo $g['last'] ? esc_html( wic_format_date( $g['last'] ) . ' — ' . __( 'no longer current', 'wic-tp' ) ) : esc_html__( 'Never', 'wic-tp' ); ?></td>
					<td><?php echo $g['courses'] ? esc_html( implode( ', ', array_map( 'get_the_title', $g['courses'] ) ) ) : esc_html__( 'No course meets it yet', 'wic-tp' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}
}

/**
 * Credit types per course. Falls back to the original single credit type and hours so
 * courses set up before this existed still report correctly.
 */
class WIC_Credits {

	public static function stored( $course_id ) {
		$rows = json_decode( (string) get_post_meta( $course_id, '_wic_credits', true ), true );
		return is_array( $rows ) ? array_values( array_filter( $rows, function ( $r ) {
			return ! empty( $r['type'] );
		} ) ) : array();
	}

	/** @return array[] list of array( 'type' => string, 'hours' => float ) */
	public static function for_course( $course_id ) {
		$rows = self::stored( $course_id );
		if ( $rows ) {
			return array_map(
				function ( $r ) {
					return array( 'type' => (string) $r['type'], 'hours' => (float) $r['hours'] );
				},
				$rows
			);
		}
		$type = (string) get_post_meta( $course_id, '_wic_credit_type', true );
		if ( '' === $type ) {
			return array();
		}
		return array( array( 'type' => $type, 'hours' => (float) WIC_Content::course_hours( $course_id ) ) );
	}

	public static function label( $credits ) {
		$parts = array();
		foreach ( (array) $credits as $c ) {
			$parts[] = $c['type'] . ' ' . rtrim( rtrim( number_format( (float) $c['hours'], 2, '.', '' ), '0' ), '.' ) . 'h';
		}
		return implode( '; ', $parts );
	}
}
