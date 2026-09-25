<?php
/**
 * The player's three calls: save position, submit an answer, ask whether the course is complete.
 * Scoring and completion happen here, never in the browser.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Rest {

	const NS = 'wic/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$course_arg = array(
			'course' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		register_rest_route(
			self::NS,
			'/position',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'position' ),
				'permission_callback' => array( __CLASS__, 'can_learn' ),
				'args'                => $course_arg + array(
					'slide' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/answer',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'answer' ),
				'permission_callback' => array( __CLASS__, 'can_learn' ),
				'args'                => $course_arg + array(
					'slide' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'complete' ),
				'permission_callback' => array( __CLASS__, 'can_learn' ),
				'args'                => $course_arg,
			)
		);
	}

	public static function can_learn( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		if ( ! $uid || 'active' !== wic_user_status( $uid ) ) {
			return false;
		}
		return self::can_open_course( $uid, (int) $req['course'] );
	}

	/** Learners open what is assigned to them; authors and administrators can open any published course. */
	public static function can_open_course( $user_id, $course_id ) {
		if ( 'wic_course' !== get_post_type( $course_id ) || 'publish' !== get_post_status( $course_id ) ) {
			return false;
		}
		if ( user_can( $user_id, 'wic_manage_content' ) || user_can( $user_id, 'wic_view_all' ) ) {
			return true;
		}
		return user_can( $user_id, 'wic_learn' ) && (bool) WIC_Records::active_assignment( $user_id, $course_id );
	}

	public static function position( WP_REST_Request $req ) {
		$ok = WIC_Records::touch( get_current_user_id(), (int) $req['course'], (int) $req['slide'] );
		if ( ! $ok ) {
			return new WP_Error( 'wic_bad_slide', __( 'That slide is not part of this course.', 'wic-tp' ), array( 'status' => 400 ) );
		}
		$uid = get_current_user_id();
		$cid = (int) $req['course'];
		return array(
			'ok'       => true,
			'progress' => WIC_Records::progress_pct( $uid, $cid, WIC_Records::current_run( $uid, $cid ) ),
		);
	}

	public static function answer( WP_REST_Request $req ) {
		$answer = $req->get_param( 'answer' );
		$order  = $req->get_param( 'order' );
		if ( is_array( $answer ) ) {
			$answer = array_map( 'intval', $answer );
		} elseif ( null !== $answer ) {
			$answer = (int) $answer;
		}
		$order = is_array( $order ) ? array_map( 'intval', $order ) : array();
		$lang  = sanitize_key( (string) $req->get_param( 'lang' ) );
		return WIC_Records::record_answer( get_current_user_id(), (int) $req['course'], (int) $req['slide'], $answer, $order, $lang );
	}

	public static function complete( WP_REST_Request $req ) {
		$result = WIC_Records::evaluate( get_current_user_id(), (int) $req['course'] );
		if ( ! empty( $result['certificate'] ) ) {
			$cert                  = $result['certificate'];
			$result['certificate'] = array(
				'number' => $cert->cert_number,
				'code'   => $cert->verify_code,
				'url'    => WIC_Certificates::url( $cert ),
			);
		}
		if ( $result['first_unseen'] ) {
			$result['first_unseen_title'] = get_the_title( $result['first_unseen'] );
		}
		// Blockers are shown as text and a link; nothing else from a module reaches the browser.
		$result['blockers'] = array_values(
			array_map(
				function ( $b ) {
					return array(
						'label' => wp_strip_all_tags( isset( $b['label'] ) ? $b['label'] : '' ),
						'url'   => isset( $b['url'] ) ? esc_url_raw( $b['url'] ) : '',
					);
				},
				isset( $result['blockers'] ) ? (array) $result['blockers'] : array()
			)
		);
		$result['retake'] = class_exists( 'WIC_Bank' ) ? WIC_Bank::retake_state( get_current_user_id(), (int) $req['course'] ) : array( 'allowed' => true, 'message' => '' );
		return $result;
	}
}
