<?php
/**
 * A short sample course, written for this build, that exercises every part of the player:
 * layouts, layers, all five question types, a graded assessment module and a certificate.
 * It is about using the portal itself, so it makes no clinical claims.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Sample {

	public static function seed() {
		if ( get_option( 'wic_sample_seeded' ) ) {
			return;
		}
		$course = self::post(
			'wic_course',
			0,
			0,
			'Portal Orientation (Sample)',
			'A fifteen-minute introduction to the training portal: finding your way round a lesson, how your progress is kept, and how certificates work.'
		);
		update_post_meta( $course, '_wic_required', '1' );
		update_post_meta( $course, '_wic_groups', array( 'staff', 'intern' ) );
		update_post_meta( $course, '_wic_due_days', '14' );
		update_post_meta( $course, '_wic_nav_mode', 'free' );
		update_post_meta( $course, '_wic_credit_type', 'Orientation' );
		update_post_meta( $course, '_wic_version', 1 );
		update_post_meta( $course, '_wic_owner', 'Platform team' );

		/* Module 1 ---------------------------------------------------- */
		$m1 = self::post( 'wic_module', $course, 10, 'Finding your way', '' );

		$welcome = self::slide(
			$m1,
			10,
			'Welcome',
			'text',
			'<p>This short course shows you how every lesson on the portal works. It takes about fifteen minutes and you can stop at any point — your place is saved automatically.</p><p>Use the <strong>Next</strong> button, or the right arrow key, to move on.</p>',
			'Welcome to the training portal. This short course shows you how every lesson works. It takes about fifteen minutes, and you can stop at any point. Your place is saved automatically.'
		);
		$map = self::slide(
			$m1,
			20,
			'The course outline',
			'callout',
			'<p>The panel on the left is the <strong>Outline</strong>. It lists every module and slide in the course. A tick shows a slide you have already seen, and the highlighted row is where you are now.</p><p>Beside it, the <strong>Transcript</strong> tab shows the words of the narration for the current slide.</p>',
			'The panel on the left is the outline. It lists every module and slide, with a tick beside the ones you have already seen. The transcript tab beside it shows the words of the narration for the slide you are on.'
		);
		$layers = self::slide(
			$m1,
			30,
			'Three parts of the player',
			'text',
			'<p>Some slides hold more detail than fits at once. Select each button below to reveal it.</p>',
			'Some slides hold more detail than fits on screen at once. Select each button to reveal it.'
		);
		update_post_meta(
			$layers,
			'_wic_layers',
			wp_json_encode(
				array(
					array(
						'label'   => 'The outline',
						'content' => 'Jump to any slide, see what you have finished, and see how much is left.',
					),
					array(
						'label'   => 'The slide',
						'content' => 'The lesson itself: text, pictures and questions. Narration plays with the controls underneath.',
					),
					array(
						'label'   => 'The controls',
						'content' => 'Back and Next, playback speed, full screen, and Exit — which saves your place before you leave.',
					),
				)
			)
		);
		self::question(
			$m1,
			40,
			'Check: saving your place',
			array(
				'type'          => 'mc',
				'prompt'        => 'You need to stop halfway through a lesson. What happens to your progress?',
				'options'       => array(
					array(
						'text'     => 'It is lost unless you finish the module first',
						'feedback' => 'Progress is saved on every slide, so nothing is lost when you stop part way.',
					),
					array(
						'text'    => 'It is saved automatically, and you can continue from the same slide on any computer',
						'correct' => true,
					),
					array(
						'text'     => 'It is saved only on the computer you were using',
						'feedback' => 'Your place is saved to your account, not the computer — that is what makes shared clinic machines safe to use.',
					),
				),
				'hint'          => 'Think about what the Welcome slide said about stopping.',
				'explain_slide' => $welcome,
				'points'        => 10,
				'attempts'      => 2,
			)
		);

		/* Module 2 ---------------------------------------------------- */
		$m2 = self::post( 'wic_module', $course, 20, 'Progress and records', '' );

		$records = self::slide(
			$m2,
			10,
			'How your record is kept',
			'text',
			'<p>Every slide you open, every answer you give and every course you finish is stored as part of your training record.</p><ul><li>Your record belongs to you, not to your role — if you move from intern to staff, everything you have completed stays.</li><li>Closing an account never erases its history.</li><li>Starting a course again keeps your earlier completion.</li></ul>',
			'Every slide you open, every answer you give and every course you finish is stored in your training record. It belongs to you, not your role, so moving from intern to staff keeps everything. Closing an account never erases its history, and starting a course again keeps your earlier completion.'
		);
		self::question(
			$m2,
			20,
			'Check: changing roles',
			array(
				'type'               => 'tf',
				'prompt'             => 'When an intern becomes a member of staff, the courses they already completed have to be taken again.',
				'answer'             => false,
				'feedback_correct'   => 'Right — completions stay with the person, whatever their role.',
				'feedback_incorrect' => 'Completions belong to the person, not the role, so nothing has to be repeated.',
				'explain_slide'      => $records,
			)
		);
		self::question(
			$m2,
			30,
			'Check: who does what',
			array(
				'type'          => 'sort',
				'prompt'        => 'Place each item under who takes care of it.',
				'categories'    => array( 'The portal does it for you', 'You do it' ),
				'items'         => array(
					array(
						'text'     => 'Saving your place on every slide',
						'category' => 0,
					),
					array(
						'text'     => 'Issuing your certificate when you pass',
						'category' => 0,
					),
					array(
						'text'     => 'Answering the knowledge checks',
						'category' => 1,
					),
					array(
						'text'     => 'Choosing when to take a break',
						'category' => 1,
					),
				),
				'explain_slide' => $records,
			)
		);

		/* Module 3: graded ------------------------------------------- */
		$m3 = self::post( 'wic_module', $course, 30, 'Final check', '' );
		update_post_meta( $m3, '_wic_assessment', '1' );
		update_post_meta( $m3, '_wic_pass_mark', '80' );

		$certs = self::slide(
			$m3,
			10,
			'Certificates',
			'callout',
			'<p>When you have seen every slide and passed the final check, your certificate is issued straight away. It shows your name, the course, the date, your score and a unique number.</p><p>Each certificate carries a <strong>verification code</strong>. Anyone can check it on the portal\'s verification page, without an account.</p>',
			'When you have seen every slide and passed the final check, your certificate is issued straight away. It carries a verification code, which anyone can check on the portal without needing an account.'
		);
		self::question(
			$m3,
			20,
			'Final: what a certificate shows',
			array(
				'type'               => 'mr',
				'prompt'             => 'Select everything that appears on your certificate.',
				'options'            => array(
					array(
						'text'    => 'Your name and the course title',
						'correct' => true,
					),
					array(
						'text'    => 'A verification code',
						'correct' => true,
					),
					array( 'text' => 'Every answer you gave' ),
					array(
						'text'    => 'Your score',
						'correct' => true,
					),
				),
				'feedback_incorrect' => 'Look again at the Certificates slide — it lists what is printed.',
				'explain_slide'      => $certs,
			)
		);
		self::question(
			$m3,
			30,
			'Final: match the feature',
			array(
				'type'          => 'match',
				'prompt'        => 'Match each part of the player with what it is for.',
				'pairs'         => array(
					array(
						'left'  => 'Outline',
						'right' => 'Jump to any slide and see what is left',
					),
					array(
						'left'  => 'Transcript',
						'right' => 'Read the narration for the current slide',
					),
					array(
						'left'  => 'Exit',
						'right' => 'Save your place and leave',
					),
				),
				'explain_slide' => $layers,
			)
		);
		self::question(
			$m3,
			40,
			'Final: checking a certificate',
			array(
				'type'          => 'mc',
				'prompt'        => 'A new employer wants to confirm your certificate is genuine. What do they need?',
				'options'       => array(
					array(
						'text'     => 'Your portal username and password',
						'feedback' => 'Never share your password. The verification code is all anyone needs.',
					),
					array(
						'text'    => 'The verification code printed on the certificate',
						'correct' => true,
					),
					array(
						'text'     => 'A letter from your supervisor',
						'feedback' => 'No letter is needed — the code can be checked by anyone on the verification page.',
					),
				),
				'explain_slide' => $certs,
			)
		);

		unset( $map );

		update_option( 'wic_sample_seeded', $course );
	}

	private static function post( $type, $parent, $order, $title, $content ) {
		return (int) wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_parent'  => $parent,
				'menu_order'   => $order,
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	private static function slide( $module, $order, $title, $layout, $html, $script ) {
		$id = self::post( 'wic_slide', $module, $order, $title, $html );
		update_post_meta( $id, '_wic_layout', $layout );
		update_post_meta( $id, '_wic_script', $script );
		update_post_meta( $id, '_wic_seconds', (string) max( 20, (int) round( str_word_count( $script ) / 2.5 ) ) );
		return $id;
	}

	private static function question( $module, $order, $title, $q ) {
		$id = self::post( 'wic_slide', $module, $order, $title, '' );
		update_post_meta( $id, '_wic_layout', 'question' );
		update_post_meta( $id, '_wic_question', wp_slash( wp_json_encode( $q ) ) );
		update_post_meta( $id, '_wic_seconds', '60' );
		return $id;
	}
}
