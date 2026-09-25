<?php
/**
 * Questions beyond the single slide: question banks, shuffled question order,
 * answer drafts (autosave), retake rules and test-out.
 *
 * Draws and shuffles are per learner per run, derived from a stable seed and stored,
 * so a reload, another device or a supervisor's report all see the same questions.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'bank_draws' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  run int(11) NOT NULL DEFAULT 1,
  module_id bigint(20) unsigned NOT NULL,
  slides text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY draw (user_id,course_id,run,module_id)
) $c;";
		$sql[] = 'CREATE TABLE ' . wic_table( 'answer_drafts' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  run int(11) NOT NULL DEFAULT 1,
  slide_id bigint(20) unsigned NOT NULL,
  answer text NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY draft (user_id,course_id,run,slide_id)
) $c;";
		return $sql;
	},
	10,
	2
);

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['decision_retake_rules'] = 0;
		$d['decision_test_out']     = 0;
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['decision_retake_rules'] = array( __( 'Retake rules', 'wic-tp' ), 'checkbox', __( 'Decision first (#48): how many times a whole course may be retaken, and after how long, needs agreeing per agency. While off, restarts are unlimited. When on, each course\'s "Maximum attempts" and "Cooling-off" settings apply.', 'wic-tp' ) );
		$f['decision_test_out']     = array( __( 'Test out', 'wic-tp' ), 'checkbox', __( 'Decision first (#49): letting experienced staff skip a course by passing a pre-test changes what the completion record proves. While off, pre-test modules are ordinary modules.', 'wic-tp' ) );
		return $f;
	}
);

class WIC_Bank {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 20, 2 );
		add_filter( 'wic_learner_tree', array( __CLASS__, 'learner_tree' ), 10, 4 );
		add_filter( 'wic_module_is_pretest', array( __CLASS__, 'is_pretest' ), 10, 3 );
		add_filter( 'wic_answer_result', array( __CLASS__, 'test_out' ), 10, 5 );
		add_action( 'wic_answer_recorded', array( __CLASS__, 'clear_draft' ), 10, 6 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ------------------------------------------------------------ authoring */

	public static function meta_boxes() {
		add_meta_box( 'wic_player_course', __( 'Player and retake options', 'wic-tp' ), array( __CLASS__, 'course_box' ), 'wic_course', 'side' );
		add_meta_box( 'wic_player_module', __( 'Question options', 'wic-tp' ), array( __CLASS__, 'module_box' ), 'wic_module', 'side' );
	}

	public static function course_box( $post ) {
		wp_nonce_field( 'wic_bank_meta', 'wic_bank_nonce' );
		?>
		<p><label><input type="checkbox" name="wic_nav_locked" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_wic_nav_locked', true ) ); ?>> <?php esc_html_e( 'Locked navigation: Next is available only once the slide\'s narration has finished and every reveal has been opened', 'wic-tp' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'Slides already seen stay free to revisit. Overrides Free/Linear for new slides.', 'wic-tp' ); ?></p>
		<hr>
		<p><label for="wic_max_attempts"><?php esc_html_e( 'Maximum attempts at the whole course', 'wic-tp' ); ?></label><br>
			<input type="number" min="0" id="wic_max_attempts" name="wic_max_attempts" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_max_attempts', true ) ); ?>" placeholder="<?php esc_attr_e( 'Unlimited', 'wic-tp' ); ?>" style="width:7em"></p>
		<p><label for="wic_cooloff_hours"><?php esc_html_e( 'Cooling-off before a retake (hours)', 'wic-tp' ); ?></label><br>
			<input type="number" min="0" id="wic_cooloff_hours" name="wic_cooloff_hours" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_cooloff_hours', true ) ); ?>" placeholder="0" style="width:7em"></p>
		<?php if ( ! (int) wic_setting( 'decision_retake_rules' ) ) : ?>
			<p class="description"><?php esc_html_e( 'Not applied: retake rules are switched off in Agency settings → Open decisions.', 'wic-tp' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function module_box( $post ) {
		wp_nonce_field( 'wic_bank_meta', 'wic_bank_nonce' );
		$questions = 0;
		foreach ( WIC_Content::children( $post->ID, 'wic_slide' ) as $s ) {
			if ( WIC_Content::question( $s->ID ) ) {
				$questions++;
			}
		}
		?>
		<p><label><input type="checkbox" name="wic_shuffle_q" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_wic_shuffle_q', true ) ); ?>> <?php esc_html_e( 'Shuffle the order of question slides for each learner', 'wic-tp' ); ?></label></p>
		<p><label for="wic_draw"><?php esc_html_e( 'Question bank: draw this many questions', 'wic-tp' ); ?></label><br>
			<input type="number" min="0" id="wic_draw" name="wic_draw" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_draw', true ) ); ?>" placeholder="<?php esc_attr_e( 'All', 'wic-tp' ); ?>" style="width:7em">
			<span class="description"><?php printf( esc_html__( 'of the %d question slides in this module. Each learner gets a fixed draw per attempt at the course.', 'wic-tp' ), (int) $questions ); ?></span></p>
		<hr>
		<p><label><input type="checkbox" name="wic_pretest" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_wic_pretest', true ) ); ?>> <?php esc_html_e( 'Pre-test: passing it at the pass mark completes the course (test out)', 'wic-tp' ); ?></label></p>
		<?php if ( ! (int) wic_setting( 'decision_test_out' ) ) : ?>
			<p class="description"><?php esc_html_e( 'Not applied: test out is switched off in Agency settings → Open decisions.', 'wic-tp' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! in_array( $post->post_type, array( 'wic_course', 'wic_module' ), true ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! isset( $_POST['wic_bank_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_bank_nonce'] ), 'wic_bank_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$int = function ( $k ) {
			return isset( $_POST[ $k ] ) && '' !== $_POST[ $k ] ? (string) absint( $_POST[ $k ] ) : '';
		};
		if ( 'wic_course' === $post->post_type ) {
			update_post_meta( $post_id, '_wic_nav_locked', empty( $_POST['wic_nav_locked'] ) ? '' : '1' );
			update_post_meta( $post_id, '_wic_max_attempts', $int( 'wic_max_attempts' ) );
			update_post_meta( $post_id, '_wic_cooloff_hours', $int( 'wic_cooloff_hours' ) );
		} else {
			update_post_meta( $post_id, '_wic_shuffle_q', empty( $_POST['wic_shuffle_q'] ) ? '' : '1' );
			update_post_meta( $post_id, '_wic_draw', $int( 'wic_draw' ) );
			// Only one pre-test per course: marking this one clears the others.
			if ( ! empty( $_POST['wic_pretest'] ) ) {
				foreach ( WIC_Content::children( $post->post_parent, 'wic_module' ) as $m ) {
					if ( (int) $m->ID !== (int) $post_id ) {
						delete_post_meta( $m->ID, '_wic_pretest' );
					}
				}
				update_post_meta( $post_id, '_wic_pretest', '1' );
			} else {
				delete_post_meta( $post_id, '_wic_pretest' );
			}
		}
	}

	/* ------------------------------------------------------ draws and order */

	/** A stable per-learner ordering: sort by a hash of the seed and the slide ID. */
	private static function seeded_order( $ids, $seed ) {
		usort(
			$ids,
			function ( $a, $b ) use ( $seed ) {
				return strcmp( md5( $seed . ':' . $a ), md5( $seed . ':' . $b ) );
			}
		);
		return $ids;
	}

	private static function is_question( $slide_id ) {
		return (bool) WIC_Content::question( $slide_id );
	}

	public static function learner_tree( $tree, $user_id, $course_id, $run ) {
		if ( ! $user_id ) {
			return $tree;
		}
		global $wpdb;
		foreach ( $tree as $i => $m ) {
			$draw    = (int) get_post_meta( $m['id'], '_wic_draw', true );
			$shuffle = (bool) get_post_meta( $m['id'], '_wic_shuffle_q', true );
			if ( ! $draw && ! $shuffle ) {
				continue;
			}
			$questions = array_values( array_filter( $m['slides'], array( __CLASS__, 'is_question' ) ) );
			if ( ! $questions ) {
				continue;
			}
			$seed = $user_id . ':' . $course_id . ':' . $run . ':' . $m['id'];
			$kept = $questions;
			if ( $draw && $draw < count( $questions ) ) {
				$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT slides FROM ' . wic_table( 'bank_draws' ) . ' WHERE user_id = %d AND course_id = %d AND run = %d AND module_id = %d', $user_id, $course_id, $run, $m['id'] ) );
				$stored = $stored ? json_decode( $stored, true ) : null;
				if ( is_array( $stored ) ) {
					// Keep the stored draw even if questions were added later; drop any that were removed.
					$kept = array_values( array_intersect( array_map( 'intval', $stored ), $questions ) );
				} else {
					$kept = array_slice( self::seeded_order( $questions, $seed ), 0, $draw );
					$wpdb->query(
						$wpdb->prepare(
							'INSERT IGNORE INTO ' . wic_table( 'bank_draws' ) . ' (user_id, course_id, run, module_id, slides, created_at) VALUES (%d, %d, %d, %d, %s, %s)',
							$user_id,
							$course_id,
							$run,
							$m['id'],
							wp_json_encode( $kept ),
							wic_now()
						)
					);
				}
			}
			$order = $shuffle ? self::seeded_order( $kept, $seed . ':order' ) : array_values( array_intersect( $questions, $kept ) );
			// Put the kept questions back into the question positions; content slides stay where they are.
			$slides = array();
			$next   = 0;
			foreach ( $m['slides'] as $sid ) {
				if ( in_array( $sid, $questions, true ) ) {
					if ( isset( $order[ $next ] ) ) {
						$slides[] = (int) $order[ $next ];
					}
					$next++;
					continue;
				}
				$slides[] = $sid;
			}
			$tree[ $i ]['slides'] = $slides;
		}
		return $tree;
	}

	/* ------------------------------------------------------------- test out */

	public static function pretest_module( $course_id ) {
		if ( ! (int) wic_setting( 'decision_test_out' ) ) {
			return 0;
		}
		foreach ( WIC_Content::tree( $course_id ) as $m ) {
			if ( get_post_meta( $m['id'], '_wic_pretest', true ) ) {
				return (int) $m['id'];
			}
		}
		return 0;
	}

	public static function is_pretest( $is, $module_id, $course_id ) {
		return $is || ( (int) wic_setting( 'decision_test_out' ) && (bool) get_post_meta( $module_id, '_wic_pretest', true ) );
	}

	/** After each pre-test answer: once every pre-test question is finished, pass → completion with source test_out. */
	public static function test_out( $result, $user_id, $course_id, $slide_id, $run ) {
		$pre = self::pretest_module( $course_id );
		if ( ! $pre || empty( $result['done'] ) ) {
			return $result;
		}
		$flat = WIC_Records::learner_flat( $user_id, $course_id, $run );
		if ( ! isset( $flat[ $slide_id ] ) || (int) $flat[ $slide_id ] !== $pre ) {
			return $result;
		}
		$states = WIC_Records::question_states( $user_id, $course_id, $run );
		foreach ( $flat as $sid => $mid ) {
			if ( (int) $mid !== $pre ) {
				continue;
			}
			$q = WIC_Content::question( $sid );
			if ( ! $q ) {
				continue;
			}
			$st = isset( $states[ $sid ] ) ? $states[ $sid ] : null;
			if ( ! $st || ( ! $st['correct'] && $st['used'] < $q['attempts'] ) ) {
				return $result; // Pre-test not finished yet.
			}
		}
		$scores = WIC_Records::module_scores( $user_id, $course_id, $run );
		$s      = isset( $scores[ $pre ] ) ? $scores[ $pre ] : null;
		if ( ! $s ) {
			return $result;
		}
		$passed            = $s['pct'] >= $s['pass_mark'];
		$result['test_out'] = array(
			'passed'    => $passed,
			'pct'       => $s['pct'],
			'pass_mark' => $s['pass_mark'],
		);
		if ( $passed ) {
			$pos        = WIC_Records::position( $user_id, $course_id, $run );
			$completion = WIC_Records::record_completion( $user_id, $course_id, $run, $s['pct'], $pos ? (int) $pos->time_spent : 0, 'test_out' );
			$cert       = WIC_Certificates::for_completion( $completion->id );
			if ( $cert ) {
				$result['test_out']['certificate'] = array(
					'number' => $cert->cert_number,
					'url'    => WIC_Certificates::url( $cert ),
				);
			}
		}
		return $result;
	}

	/* --------------------------------------------------------- retake rules */

	/** Whether a whole-course restart is allowed now, and if not, why — in words the learner can act on. */
	public static function retake_state( $user_id, $course_id ) {
		if ( ! (int) wic_setting( 'decision_retake_rules' ) ) {
			return array( 'allowed' => true, 'message' => '' );
		}
		$max = (int) get_post_meta( $course_id, '_wic_max_attempts', true );
		$run = WIC_Records::current_run( $user_id, $course_id );
		if ( $max > 0 && $run >= $max ) {
			return array(
				'allowed' => false,
				/* translators: %d: number of attempts */
				'message' => sprintf( _n( 'This course allows %d attempt, and it has been used. Please speak to your supervisor.', 'This course allows %d attempts, and they have all been used. Please speak to your supervisor.', $max, 'wic-tp' ), $max ),
			);
		}
		$hours = (int) get_post_meta( $course_id, '_wic_cooloff_hours', true );
		$pos   = WIC_Records::position( $user_id, $course_id, $run );
		if ( $hours > 0 && $pos ) {
			$ready = strtotime( $pos->last_access . ' UTC' ) + $hours * HOUR_IN_SECONDS;
			if ( $ready > time() ) {
				return array(
					'allowed' => false,
					/* translators: %s: date and time */
					'message' => sprintf( __( 'You can start this course again from %s.', 'wic-tp' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ready ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
				);
			}
		}
		return array( 'allowed' => true, 'message' => '' );
	}

	/* --------------------------------------------------------------- drafts */

	public static function routes() {
		register_rest_route(
			WIC_Rest::NS,
			'/draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_draft' ),
				'permission_callback' => array( 'WIC_Rest', 'can_learn' ),
				'args'                => array(
					'course' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'slide'  => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/** A draft is only ever the learner's working state; it is never scored and is removed on submit. */
	public static function save_draft( WP_REST_Request $req ) {
		global $wpdb;
		$uid    = get_current_user_id();
		$course = (int) $req['course'];
		$slide  = (int) $req['slide'];
		$run    = WIC_Records::current_run( $uid, $course );
		$flat   = WIC_Records::learner_flat( $uid, $course, $run );
		if ( ! isset( $flat[ $slide ] ) || ! WIC_Content::question( $slide ) ) {
			return new WP_Error( 'wic_bad_question', __( 'That question is not part of this course.', 'wic-tp' ), array( 'status' => 400 ) );
		}
		$answer = $req->get_param( 'answer' );
		$answer = is_array( $answer ) ? array_map( 'intval', $answer ) : ( null === $answer ? null : (int) $answer );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . wic_table( 'answer_drafts' ) . ' (user_id, course_id, run, slide_id, answer, updated_at) VALUES (%d, %d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE answer = VALUES(answer), updated_at = VALUES(updated_at)',
				$uid,
				$course,
				$run,
				$slide,
				wp_json_encode( $answer ),
				wic_now()
			)
		);
		return array( 'ok' => true );
	}

	public static function drafts( $user_id, $course_id, $run ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT slide_id, answer FROM ' . wic_table( 'answer_drafts' ) . ' WHERE user_id = %d AND course_id = %d AND run = %d', $user_id, $course_id, $run ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->slide_id ] = json_decode( $r->answer, true );
		}
		return $out;
	}

	public static function clear_draft( $user_id, $course_id, $slide_id, $correct, $attempt_no, $run ) {
		global $wpdb;
		$wpdb->delete(
			wic_table( 'answer_drafts' ),
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'run'       => $run,
				'slide_id'  => $slide_id,
			)
		);
	}
}

add_action( 'wic_init', array( 'WIC_Bank', 'init' ) );
