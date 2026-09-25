<?php
/**
 * The lesson player: one standalone page, one component driven by a slide list.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Player {

	public static function init() {
		add_shortcode( 'wic_player', '__return_empty_string' );
		add_action( 'template_redirect', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! wic_page_id( 'learn' ) || ! is_page( wic_page_id( 'learn' ) ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$uid       = get_current_user_id();
		$course_id = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0;
		if ( ! $course_id || ! WIC_Rest::can_open_course( $uid, $course_id ) ) {
			wp_die( esc_html__( 'This course is not assigned to you, or is not available.', 'wic-tp' ), '', array( 'response' => 403, 'back_link' => true ) );
		}

		if ( isset( $_GET['restart'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'wic_restart_' . $course_id ) ) {
			// Retake rules (when agreed) are enforced here, on the server, not by hiding a button.
			$retake = class_exists( 'WIC_Bank' ) ? WIC_Bank::retake_state( $uid, $course_id ) : array( 'allowed' => true );
			if ( empty( $retake['allowed'] ) ) {
				wp_die( esc_html( $retake['message'] ), esc_html__( 'Retake not available', 'wic-tp' ), array( 'response' => 403, 'back_link' => true ) );
			}
			WIC_Records::restart( $uid, $course_id );
			wp_safe_redirect( wic_page_url( 'learn', array( 'course' => $course_id ) ) );
			exit;
		}

		if ( isset( $_GET['print'] ) && class_exists( 'WIC_Print' ) ) {
			WIC_Print::render( $uid, $course_id );
		}

		$data = self::data( $uid, $course_id );
		if ( ! $data['modules'] ) {
			wp_die( esc_html__( 'This course has no published slides yet.', 'wic-tp' ), '', array( 'back_link' => true ) );
		}
		$course = get_post( $course_id );
		include WIC_TP_DIR . 'templates/player.php';
		exit;
	}

	public static function data( $uid, $course_id ) {
		$run     = WIC_Records::current_run( $uid, $course_id );
		$pos     = WIC_Records::position( $uid, $course_id, $run );
		$seen    = WIC_Records::seen( $pos );
		$states  = WIC_Records::question_states( $uid, $course_id, $run );
		$drafts  = class_exists( 'WIC_Bank' ) ? WIC_Bank::drafts( $uid, $course_id, $run ) : array();
		$modules = array();
		// The learner's own tree: question-bank draws and shuffled order are already applied.
		foreach ( WIC_Records::learner_tree( $uid, $course_id, $run ) as $m ) {
			$slides = array();
			foreach ( $m['slides'] as $sid ) {
				$post = get_post( $sid );
				if ( ! $post ) {
					continue;
				}
				$q        = WIC_Content::question( $sid );
				$slides[] = apply_filters( 'wic_player_slide', array(
					'id'       => $sid,
					'title'    => $post->post_title,
					'layout'   => get_post_meta( $sid, '_wic_layout', true ) ? get_post_meta( $sid, '_wic_layout', true ) : 'text',
					'html'     => wp_kses_post( wpautop( $post->post_content ) ),
					'image'    => esc_url_raw( get_post_meta( $sid, '_wic_image_url', true ) ),
					'alt'      => (string) get_post_meta( $sid, '_wic_image_alt', true ),
					'audio'    => esc_url_raw( get_post_meta( $sid, '_wic_audio_url', true ) ),
					'script'   => (string) get_post_meta( $sid, '_wic_script', true ),
					'seconds'  => WIC_Content::slide_seconds( $sid ),
					'layers'   => WIC_Content::layers( $sid ),
					'question' => WIC_Content::public_question( $q ),
					'state'    => isset( $states[ $sid ] ) ? $states[ $sid ] : null,
					'draft'    => isset( $drafts[ $sid ] ) ? $drafts[ $sid ] : null,
				), $sid, $uid, $course_id );
			}
			$modules[] = array(
				'id'         => $m['id'],
				'title'      => $m['title'],
				'assessment' => $m['assessment'],
				'pretest'    => class_exists( 'WIC_Bank' ) && WIC_Bank::pretest_module( $course_id ) === (int) $m['id'],
				'printUrl'   => class_exists( 'WIC_Print' ) ? WIC_Print::url( $course_id, $m['id'] ) : '',
				'slides'     => $slides,
			);
		}

		$requested = isset( $_GET['slide'] ) ? absint( $_GET['slide'] ) : 0;
		$flat      = WIC_Records::learner_flat( $uid, $course_id, $run );
		$start     = isset( $flat[ $requested ] ) ? $requested : ( $pos && isset( $flat[ (int) $pos->current_slide ] ) ? (int) $pos->current_slide : (int) key( $flat ) );
		$nav       = 'linear' === get_post_meta( $course_id, '_wic_nav_mode', true ) ? 'linear' : 'free';
		if ( get_post_meta( $course_id, '_wic_nav_locked', true ) ) {
			$nav = 'locked';
		}

		/** Modules add player data (language tracks, notes, captions, resources, next course) here. */
		return apply_filters( 'wic_player_data', array(
			'courseId'    => $course_id,
			'title'       => get_the_title( $course_id ),
			'navMode'     => $nav,
			'autoAdvance' => (bool) get_post_meta( $course_id, '_wic_auto_advance', true ),
			'modules'     => $modules,
			'seen'        => $seen,
			'start'       => $start,
			'completed'   => (bool) WIC_Records::latest_completion( $uid, $course_id ),
			'rest'        => esc_url_raw( rest_url( WIC_Rest::NS ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'portalUrl'   => wic_page_url( 'portal' ),
			'learnUrl'    => wic_page_url( 'learn' ),
			'restartUrl'  => wp_nonce_url( wic_page_url( 'learn', array( 'course' => $course_id, 'restart' => 1 ) ), 'wic_restart_' . $course_id ),
			'retake'      => class_exists( 'WIC_Bank' ) ? WIC_Bank::retake_state( $uid, $course_id ) : array( 'allowed' => true, 'message' => '' ),
			'notes'       => class_exists( 'WIC_Notes' ) ? (object) WIC_Notes::for_course( $uid, $course_id ) : new stdClass(),
			'printUrl'    => class_exists( 'WIC_Print' ) ? WIC_Print::url( $course_id ) : '',
			'sw'          => class_exists( 'WIC_Offline' ) ? array( 'url' => WIC_Offline::url(), 'scope' => WIC_Offline::scope() ) : null,
			'assets'      => array(
				WIC_TP_URL . 'assets/css/player.css?ver=' . WIC_TP_VERSION,
				WIC_TP_URL . 'assets/js/player.js?ver=' . WIC_TP_VERSION,
			),
		), $uid, $course_id );
	}
}
