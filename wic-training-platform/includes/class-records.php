<?php
/**
 * Assignments, positions, attempts and completions.
 *
 * Status is always derived from these records, never stored, so it cannot go stale.
 * Nothing here ever deletes a row: restarting creates a new run, cancelling keeps the record.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Records {

	/* ------------------------------------------------------------------ */
	/* Assignments                                                        */
	/* ------------------------------------------------------------------ */

	public static function active_assignment( $user_id, $course_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . wic_table( 'assignments' ) . " WHERE user_id = %d AND course_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
				$user_id,
				$course_id
			)
		);
	}

	public static function assign( $user_id, $course_id, $source = 'manual', $due_mysql = null ) {
		global $wpdb;
		if ( 'publish' !== get_post_status( $course_id ) || 'wic_course' !== get_post_type( $course_id ) ) {
			return false;
		}
		$existing = self::active_assignment( $user_id, $course_id );
		if ( $existing ) {
			return (int) $existing->id;
		}
		if ( null === $due_mysql ) {
			$days      = (int) get_post_meta( $course_id, '_wic_due_days', true );
			$due_mysql = $days > 0 ? gmdate( 'Y-m-d 23:59:59', time() + $days * DAY_IN_SECONDS ) : null;
		}
		$wpdb->insert(
			wic_table( 'assignments' ),
			array(
				'user_id'        => $user_id,
				'course_id'      => $course_id,
				'course_version' => WIC_Content::course_version( $course_id ),
				'source'         => $source,
				'assigned_by'    => get_current_user_id(),
				'assigned_at'    => wic_now(),
				'due_at'         => $due_mysql,
				'status'         => 'active',
			)
		);
		$id = (int) $wpdb->insert_id;
		wic_audit( 'assign', 'assignment', $id, array( 'user' => $user_id, 'course' => $course_id, 'source' => $source ) );
		WIC_Notify::event( $user_id, 'assigned', $id, sprintf( __( 'You have been assigned "%s".', 'wic-tp' ), get_the_title( $course_id ) ), false );
		return $id;
	}

	/** Never delete an assignment with progress against it — cancel it and keep the record. */
	public static function cancel_assignment( $assignment_id, $reason = '' ) {
		global $wpdb;
		$wpdb->update( wic_table( 'assignments' ), array( 'status' => 'cancelled', 'note' => $reason ), array( 'id' => $assignment_id ) );
		wic_audit( 'assignment_cancel', 'assignment', $assignment_id, array( 'reason' => $reason ) );
	}

	public static function extend_assignment( $assignment_id, $due_mysql, $reason ) {
		global $wpdb;
		$wpdb->update( wic_table( 'assignments' ), array( 'due_at' => $due_mysql, 'note' => $reason ), array( 'id' => $assignment_id ) );
		wic_audit( 'assignment_extend', 'assignment', $assignment_id, array( 'due' => $due_mysql, 'reason' => $reason ) );
	}

	/** Auto-assign by group. Always additive — never removes anything already assigned or completed. */
	public static function apply_rules( $user_id ) {
		$group   = wic_user_group( $user_id );
		$courses = get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_wic_required',
				'meta_value'     => '1',
			)
		);
		foreach ( $courses as $c ) {
			$groups = (array) get_post_meta( $c->ID, '_wic_groups', true );
			if ( in_array( $group, $groups, true ) && ! self::latest_completion( $user_id, $c->ID ) ) {
				self::assign( $user_id, $c->ID, 'rule' );
			}
		}
		/** Assignment rules by role and location, learning paths and required forms run here. Additive only. */
		do_action( 'wic_apply_rules', $user_id );
	}

	public static function user_assignments( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . wic_table( 'assignments' ) . " WHERE user_id = %d AND status = 'active' ORDER BY due_at IS NULL, due_at ASC, id ASC", $user_id )
		);
		$out  = array();
		foreach ( $rows as $a ) {
			if ( 'publish' !== get_post_status( $a->course_id ) ) {
				continue; // Retired courses leave records readable but drop off the learner's list.
			}
			$out[] = self::describe( $user_id, $a );
		}
		return $out;
	}

	/** One place that turns records into what a learner or supervisor sees. */
	public static function describe( $user_id, $a ) {
		$course_id  = (int) $a->course_id;
		$completion = self::latest_completion( $user_id, $course_id );
		$run        = self::current_run( $user_id, $course_id );
		$position   = self::position( $user_id, $course_id, $run );
		$status     = self::derive_status( $a, $completion, $position );
		$pct        = 'complete' === $status ? 100 : self::progress_pct( $user_id, $course_id, $run );
		$cert       = $completion ? WIC_Certificates::for_completion( $completion->id ) : null;
		$days_late  = 0;
		if ( 'overdue' === $status && $a->due_at ) {
			$days_late = (int) floor( ( time() - strtotime( $a->due_at . ' UTC' ) ) / DAY_IN_SECONDS );
		}
		return apply_filters( 'wic_describe', array(
			'assignment_id' => (int) $a->id,
			'course_id'     => $course_id,
			'title'         => get_the_title( $course_id ),
			'status'        => $status,
			'progress'      => $pct,
			'due_at'        => $a->due_at,
			'days_late'     => max( 0, $days_late ),
			'last_access'   => $position ? $position->last_access : null,
			'completion'    => $completion,
			'certificate'   => $cert,
			'minutes'       => (int) round( WIC_Content::course_seconds( $course_id ) / 60 ),
			'required'      => (bool) get_post_meta( $course_id, '_wic_required', true ),
			'note'          => isset( $a->note ) ? (string) $a->note : '',
		), $user_id, $a );
	}

	public static function derive_status( $assignment, $completion, $position ) {
		if ( $completion ) {
			$months = (int) get_post_meta( $assignment->course_id, '_wic_validity_months', true );
			if ( $months > 0 && strtotime( $completion->completed_at . ' UTC +' . $months . ' months' ) < time() ) {
				$status = 'expired';
			} else {
				$status = 'complete';
			}
		} elseif ( $assignment->due_at && strtotime( $assignment->due_at . ' UTC' ) < time() ) {
			$status = 'overdue';
		} else {
			$status = $position ? 'in_progress' : 'not_started';
		}
		/** Reporting adds coming_due / not_applicable here; status stays derived, never stored. */
		return apply_filters( 'wic_derive_status', $status, $assignment, $completion, $position );
	}

	/**
	 * The one place a completion row is written — online courses, classroom attendance,
	 * competency sign-off, outside training and test-out all come through here, so
	 * certificates, notices and reports treat them the same.
	 *
	 * @param string $source online|attendance|competency|external|test_out|import
	 */
	public static function record_completion( $user_id, $course_id, $run, $score, $time_spent = 0, $source = 'online' ) {
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'completions' ) . ' WHERE user_id = %d AND course_id = %d AND run = %d', $user_id, $course_id, $run ) );
		if ( $existing ) {
			return $existing;
		}
		$assignment = self::active_assignment( $user_id, $course_id );
		$wpdb->insert(
			wic_table( 'completions' ),
			array(
				'user_id'        => $user_id,
				'course_id'      => $course_id,
				'course_version' => $assignment ? (int) $assignment->course_version : WIC_Content::course_version( $course_id ),
				'run'            => $run,
				'score'          => (int) $score,
				'time_spent'     => (int) $time_spent,
				'completed_at'   => wic_now(),
				'source'         => $source,
			)
		);
		$completion = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'completions' ) . ' WHERE id = %d', $wpdb->insert_id ) );
		wic_audit( 'course_complete', 'course', $course_id, array( 'user' => $user_id, 'score' => (int) $score, 'source' => $source ) );
		// One completion event, several listeners: certificate, learner notice, supervisor notice, xAPI, reinforcement.
		do_action( 'wic_course_completed', $completion );
		return $completion;
	}

	/* ------------------------------------------------------------------ */
	/* Positions                                                          */
	/* ------------------------------------------------------------------ */

	public static function current_run( $user_id, $course_id ) {
		global $wpdb;
		$run = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(run) FROM ' . wic_table( 'positions' ) . ' WHERE user_id = %d AND course_id = %d', $user_id, $course_id ) );
		return $run > 0 ? $run : 1;
	}

	/**
	 * The course as this learner sees it on this run. `wic_course_tree` has no learner and is
	 * cached per course, so anything learner-specific — question-bank draws, shuffled question
	 * order — goes through `wic_learner_tree` instead. Records always use this, so progress,
	 * completion and scoring count exactly the slides the learner was given.
	 */
	public static function learner_tree( $user_id, $course_id, $run = null ) {
		static $cache = array();
		if ( null === $run ) {
			$run = self::current_run( $user_id, $course_id );
		}
		$key = $user_id . ':' . $course_id . ':' . $run;
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = apply_filters( 'wic_learner_tree', WIC_Content::tree( $course_id ), (int) $user_id, (int) $course_id, (int) $run );
		}
		return $cache[ $key ];
	}

	/** slide_id => module_id for this learner and run, in order. */
	public static function learner_flat( $user_id, $course_id, $run = null ) {
		$flat = array();
		foreach ( self::learner_tree( $user_id, $course_id, $run ) as $m ) {
			foreach ( $m['slides'] as $sid ) {
				$flat[ $sid ] = $m['id'];
			}
		}
		return $flat;
	}

	public static function position( $user_id, $course_id, $run ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'positions' ) . ' WHERE user_id = %d AND course_id = %d AND run = %d', $user_id, $course_id, $run ) );
	}

	public static function seen( $position ) {
		if ( ! $position || ! $position->seen ) {
			return array();
		}
		$seen = json_decode( $position->seen, true );
		return is_array( $seen ) ? array_map( 'intval', $seen ) : array();
	}

	/** Written on every slide change. Idle time is capped so a forgotten tab does not record hours. */
	public static function touch( $user_id, $course_id, $slide_id ) {
		global $wpdb;
		$run  = self::current_run( $user_id, $course_id );
		$flat = self::learner_flat( $user_id, $course_id, $run );
		if ( ! isset( $flat[ $slide_id ] ) ) {
			return false;
		}
		do_action( 'wic_position_touched', $user_id, $course_id, $slide_id );
		$pos = self::position( $user_id, $course_id, $run );
		$now = wic_now();
		if ( ! $pos ) {
			$wpdb->insert(
				wic_table( 'positions' ),
				array(
					'user_id'       => $user_id,
					'course_id'     => $course_id,
					'run'           => $run,
					'current_slide' => $slide_id,
					'seen'          => wp_json_encode( array( $slide_id ) ),
					'first_access'  => $now,
					'last_access'   => $now,
					'time_spent'    => 0,
				)
			);
			return true;
		}
		$seen = self::seen( $pos );
		if ( ! in_array( $slide_id, $seen, true ) ) {
			$seen[] = $slide_id;
		}
		$delta = time() - strtotime( $pos->last_access . ' UTC' );
		$delta = max( 0, min( $delta, (int) wic_setting( 'idle_cap_seconds' ) ) );
		$wpdb->update(
			wic_table( 'positions' ),
			array(
				'current_slide' => $slide_id,
				'seen'          => wp_json_encode( array_values( $seen ) ),
				'last_access'   => $now,
				'time_spent'    => (int) $pos->time_spent + $delta,
			),
			array( 'id' => $pos->id )
		);
		return true;
	}

	/** Restart begins a new run; the old run and any completion stay exactly as they were. */
	public static function restart( $user_id, $course_id ) {
		global $wpdb;
		$run = self::current_run( $user_id, $course_id ) + 1;
		$now = wic_now();
		$wpdb->insert(
			wic_table( 'positions' ),
			array(
				'user_id'       => $user_id,
				'course_id'     => $course_id,
				'run'           => $run,
				'current_slide' => 0,
				'seen'          => '[]',
				'first_access'  => $now,
				'last_access'   => $now,
				'time_spent'    => 0,
			)
		);
		wic_audit( 'course_restart', 'course', $course_id, array( 'user' => $user_id, 'run' => $run ) );
		return $run;
	}

	/** Furthest reached, not current slide, so reviewing earlier slides never moves progress backwards. */
	public static function progress_pct( $user_id, $course_id, $run ) {
		$flat = self::learner_flat( $user_id, $course_id, $run );
		if ( ! $flat ) {
			return 0;
		}
		$seen = array_intersect( self::seen( self::position( $user_id, $course_id, $run ) ), array_keys( $flat ) );
		return (int) floor( count( $seen ) / count( $flat ) * 100 );
	}

	/* ------------------------------------------------------------------ */
	/* Attempts and scoring (server-side only)                            */
	/* ------------------------------------------------------------------ */

	public static function slide_attempts( $user_id, $course_id, $run, $slide_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . wic_table( 'attempts' ) . ' WHERE user_id = %d AND course_id = %d AND run = %d AND slide_id = %d ORDER BY attempt_no ASC', $user_id, $course_id, $run, $slide_id )
		);
	}

	public static function score_answer( $q, $answer ) {
		switch ( $q['type'] ) {
			case 'mc':
			case 'tf':
				$i = is_numeric( $answer ) ? (int) $answer : -1;
				return array( ! empty( $q['options'][ $i ]['correct'] ), $i );
			case 'mr':
				$given = array_values( array_unique( array_map( 'intval', (array) $answer ) ) );
				sort( $given );
				$want = array();
				foreach ( $q['options'] as $i => $o ) {
					if ( ! empty( $o['correct'] ) ) {
						$want[] = $i;
					}
				}
				return array( $given === $want, null );
			case 'sort':
				$given = (array) $answer;
				foreach ( $q['items'] as $i => $item ) {
					if ( ! isset( $given[ $i ] ) || (int) $given[ $i ] !== (int) $item['category'] ) {
						return array( false, null );
					}
				}
				return array( true, null );
			case 'match':
				$given = (array) $answer;
				foreach ( array_keys( $q['pairs'] ) as $i ) {
					if ( ! isset( $given[ $i ] ) || (int) $given[ $i ] !== (int) $i ) {
						return array( false, null );
					}
				}
				return array( true, null );
		}
		return array( false, null );
	}

	/** What the learner should see after the final attempt: the right answer, in words. */
	public static function correct_answer_text( $q ) {
		switch ( $q['type'] ) {
			case 'mc':
			case 'tf':
			case 'mr':
				$texts = array();
				foreach ( $q['options'] as $o ) {
					if ( ! empty( $o['correct'] ) ) {
						$texts[] = wp_strip_all_tags( $o['text'] );
					}
				}
				return implode( '; ', $texts );
			case 'sort':
				$parts = array();
				foreach ( $q['items'] as $item ) {
					$parts[] = wp_strip_all_tags( $item['text'] ) . ' → ' . wp_strip_all_tags( $q['categories'][ (int) $item['category'] ] );
				}
				return implode( '; ', $parts );
			case 'match':
				$parts = array();
				foreach ( $q['pairs'] as $p ) {
					$parts[] = wp_strip_all_tags( $p['left'] ) . ' → ' . wp_strip_all_tags( $p['right'] );
				}
				return implode( '; ', $parts );
		}
		return '';
	}

	/** Every answer is its own row — which is what makes answer review and most-missed questions a query. */
	public static function record_answer( $user_id, $course_id, $slide_id, $answer, $shown_order, $lang = '' ) {
		global $wpdb;
		$run  = self::current_run( $user_id, $course_id );
		$flat = self::learner_flat( $user_id, $course_id, $run );
		$q    = WIC_Content::question( $slide_id );
		if ( ! isset( $flat[ $slide_id ] ) || ! $q ) {
			return new WP_Error( 'wic_bad_question', __( 'That question is not part of this course.', 'wic-tp' ), array( 'status' => 400 ) );
		}
		/** Translations overlay wording only — never which option is correct. */
		$q = apply_filters( 'wic_question_for_learner', $q, $slide_id, (string) $lang );
		$previous = self::slide_attempts( $user_id, $course_id, $run, $slide_id );
		foreach ( $previous as $p ) {
			if ( (int) $p->correct ) {
				return new WP_Error( 'wic_answered', __( 'You have already answered this correctly.', 'wic-tp' ), array( 'status' => 409 ) );
			}
		}
		if ( count( $previous ) >= $q['attempts'] ) {
			return new WP_Error( 'wic_no_attempts', __( 'No attempts remain for this question.', 'wic-tp' ), array( 'status' => 409 ) );
		}

		list( $correct, $chosen ) = self::score_answer( $q, $answer );
		$attempt_no               = count( $previous ) + 1;
		$wpdb->insert(
			wic_table( 'attempts' ),
			array(
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'run'         => $run,
				'module_id'   => $flat[ $slide_id ],
				'slide_id'    => $slide_id,
				'attempt_no'  => $attempt_no,
				'answer'      => wp_json_encode( $answer ),
				'shown_order' => wp_json_encode( $shown_order ),
				'correct'     => $correct ? 1 : 0,
				'points'      => $correct ? $q['points'] : 0,
				'max_points'  => $q['points'],
				'created_at'  => wic_now(),
			)
		);

		/** Reinforcement, xAPI and analytics listen here. */
		do_action( 'wic_answer_recorded', $user_id, $course_id, $slide_id, (bool) $correct, $attempt_no, $run );

		$left     = $q['attempts'] - $attempt_no;
		$feedback = '';
		if ( $correct ) {
			$feedback = isset( $q['feedback_correct'] ) && $q['feedback_correct'] ? $q['feedback_correct'] : __( 'Correct.', 'wic-tp' );
		} else {
			// Feedback per option, falling back to the question-level message.
			if ( null !== $chosen && ! empty( $q['options'][ $chosen ]['feedback'] ) ) {
				$feedback = $q['options'][ $chosen ]['feedback'];
			} elseif ( ! empty( $q['feedback_incorrect'] ) ) {
				$feedback = $q['feedback_incorrect'];
			} else {
				$feedback = __( 'Not quite.', 'wic-tp' );
			}
		}

		$result = array(
			'correct'       => $correct,
			'feedback'      => wp_kses_post( $feedback ),
			'attempts_left' => $correct ? 0 : $left,
			'done'          => $correct || $left <= 0,
		);
		if ( ! $correct && ! empty( $q['explain_slide'] ) && isset( $flat[ (int) $q['explain_slide'] ] ) ) {
			// Feedback that teaches: point back at the slide that explains the answer.
			$result['explain_slide'] = (int) $q['explain_slide'];
			$result['explain_title'] = get_the_title( (int) $q['explain_slide'] );
		}
		if ( ! $correct && $left <= 0 ) {
			$result['correct_answer'] = self::correct_answer_text( $q );
		}
		/** Test-out and similar outcomes attach themselves to the answer result here. */
		return apply_filters( 'wic_answer_result', $result, $user_id, $course_id, $slide_id, $run );
	}

	/** Per-question state for resuming a run: attempts used and whether it is finished. */
	public static function question_states( $user_id, $course_id, $run ) {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT slide_id, COUNT(*) AS n, MAX(correct) AS ok FROM ' . wic_table( 'attempts' ) . ' WHERE user_id = %d AND course_id = %d AND run = %d GROUP BY slide_id', $user_id, $course_id, $run ) );
		$states = array();
		foreach ( $rows as $r ) {
			$states[ (int) $r->slide_id ] = array(
				'used'    => (int) $r->n,
				'correct' => (bool) $r->ok,
			);
		}
		return $states;
	}

	/** Best points per question, grouped by module. */
	public static function module_scores( $user_id, $course_id, $run ) {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT slide_id, MAX(points) AS pts FROM ' . wic_table( 'attempts' ) . ' WHERE user_id = %d AND course_id = %d AND run = %d GROUP BY slide_id', $user_id, $course_id, $run ) );
		$best   = array();
		foreach ( $rows as $r ) {
			$best[ (int) $r->slide_id ] = (int) $r->pts;
		}
		$scores = array();
		foreach ( self::learner_tree( $user_id, $course_id, $run ) as $m ) {
			$got = 0;
			$max = 0;
			foreach ( $m['slides'] as $sid ) {
				$q = WIC_Content::question( $sid );
				if ( ! $q ) {
					continue;
				}
				$max += $q['points'];
				$got += isset( $best[ $sid ] ) ? $best[ $sid ] : 0;
			}
			$scores[ $m['id'] ] = array(
				'title'      => $m['title'],
				'assessment' => $m['assessment'],
				'pass_mark'  => $m['pass_mark'],
				'got'        => $got,
				'max'        => $max,
				'pct'        => $max ? (int) round( $got / $max * 100 ) : 100,
				// A pre-test (test-out) module is scored on its own and never counts towards the course score.
				'pretest'    => (bool) apply_filters( 'wic_module_is_pretest', false, $m['id'], $course_id ),
			);
		}
		return $scores;
	}

	/* ------------------------------------------------------------------ */
	/* Completion — decided on the server, never by the browser           */
	/* ------------------------------------------------------------------ */

	public static function latest_completion( $user_id, $course_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'completions' ) . ' WHERE user_id = %d AND course_id = %d ORDER BY id DESC LIMIT 1', $user_id, $course_id ) );
	}

	public static function evaluate( $user_id, $course_id ) {
		global $wpdb;
		$run  = self::current_run( $user_id, $course_id );
		$flat = self::learner_flat( $user_id, $course_id, $run );
		$pos  = self::position( $user_id, $course_id, $run );
		$seen = self::seen( $pos );

		$unseen = array_values( array_diff( array_keys( $flat ), $seen ) );
		$scores = array_filter(
			self::module_scores( $user_id, $course_id, $run ),
			function ( $s ) {
				return empty( $s['pretest'] );
			}
		);
		$failed = array();
		$assess = array_filter(
			$scores,
			function ( $s ) {
				return $s['assessment'];
			}
		);
		foreach ( $assess as $mid => $s ) {
			if ( $s['pct'] < $s['pass_mark'] ) {
				$failed[ $mid ] = $s;
			}
		}
		$scored  = $assess ? $assess : $scores;
		$got     = array_sum( wp_list_pluck( $scored, 'got' ) );
		$max     = array_sum( wp_list_pluck( $scored, 'max' ) );
		$overall = $max ? (int) round( $got / $max * 100 ) : 100;

		$result = array(
			'complete'      => false,
			'score'         => $overall,
			'unseen'        => count( $unseen ),
			'first_unseen'  => $unseen ? $unseen[0] : null,
			'failed'        => array_values( $failed ),
			'modules'       => array_values( $scores ),
			'time_spent'    => $pos ? (int) $pos->time_spent : 0,
		);
		/**
		 * Modules add completion conditions here (a form that must be signed, a session that
		 * must be attended) by adding entries to $result['blockers'] = array( array( 'label' => …, 'url' => … ) ).
		 */
		$result['blockers'] = array();
		$result             = apply_filters( 'wic_evaluate_result', $result, $user_id, $course_id, $run );
		if ( $unseen || $failed || ! empty( $result['blockers'] ) ) {
			return $result;
		}

		$existing              = self::record_completion( $user_id, $course_id, $run, $overall, $pos ? (int) $pos->time_spent : 0, 'online' );
		$result['complete']    = true;
		$result['certificate'] = WIC_Certificates::for_completion( $existing->id );
		return $result;
	}
}
