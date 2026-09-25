<?php
/**
 * Version pinning: a learner finishes the version of a course they started, even if
 * it is republished part-way through.
 *
 * When an author starts a new version, the course as it then stands is frozen into a
 * snapshot for the outgoing version. A learner whose current run began before that
 * moment is served the snapshot — structure, slide text, media and questions — through
 * the `wic_course_tree`, `wic_player_slide` and `wic_question` filters. Everyone else
 * gets the live course. Records keep pointing at the same slide IDs either way.
 *
 * Limits: a slide that has been permanently deleted (not trashed) cannot be shown from
 * a snapshot, because the player still loads the post. Retire or trash slides instead.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'course_versions' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL,
  version int(11) NOT NULL,
  snapshot longtext NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY course_version (course_id,version)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_Course_Versions {

	/** slide_id => snapshot slide, for slides currently being served from a snapshot. */
	private static $active   = array();
	private static $building = false;
	private static $pins     = array();

	public static function init() {
		add_action( 'wic_course_version_closing', array( __CLASS__, 'freeze' ), 10, 2 );
		add_filter( 'wic_course_tree', array( __CLASS__, 'tree' ), 5, 2 );
		add_filter( 'wic_player_slide', array( __CLASS__, 'player_slide' ), 5, 4 );
		add_filter( 'wic_question', array( __CLASS__, 'question' ), 5, 2 );
		add_filter( 'wic_player_data', array( __CLASS__, 'player_data' ), 5, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Snapshots                                                          */
	/* ------------------------------------------------------------------ */

	/** Every _wic_ meta value on a post, as stored. */
	public static function post_meta( $post_id ) {
		$out = array();
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 === strpos( $key, '_wic_' ) && ! preg_match( '/^_wic_(pin_|agency_id$|forked_|copied_from$)/', $key ) ) {
				$out[ $key ] = maybe_unserialize( $values[0] );
			}
		}
		return $out;
	}

	/** Published children in authored order, read directly so no per-learner filter reshapes them. */
	public static function children( $parent_id, $type ) {
		return get_posts(
			array(
				'post_type'        => $type,
				'post_parent'      => (int) $parent_id,
				'post_status'      => 'publish',
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
	}

	/** The live course, as authored. */
	public static function build( $course_id ) {
		self::$building = true;
		$modules        = array();
		$slides         = array();
		foreach ( self::children( $course_id, 'wic_module' ) as $m ) {
			$ids = array();
			foreach ( self::children( $m->ID, 'wic_slide' ) as $s ) {
				$ids[]            = (int) $s->ID;
				$slides[ $s->ID ] = array(
					'title'   => $s->post_title,
					'content' => $s->post_content,
					'meta'    => self::post_meta( $s->ID ),
				);
			}
			$modules[] = array(
				'id'         => (int) $m->ID,
				'title'      => $m->post_title,
				'assessment' => (bool) get_post_meta( $m->ID, '_wic_assessment', true ),
				'pass_mark'  => WIC_Content::module_pass_mark( $m->ID, $course_id ),
				'meta'       => self::post_meta( $m->ID ),
				'slides'     => $ids,
			);
		}
		self::$building = false;
		return array(
			'modules' => $modules,
			'slides'  => $slides,
		);
	}

	public static function save( $course_id, $version ) {
		global $wpdb;
		$data = wp_json_encode( self::build( $course_id ) );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . wic_table( 'course_versions' ) . ' (course_id, version, snapshot, created_by, created_at) VALUES (%d, %d, %s, %d, %s) ON DUPLICATE KEY UPDATE snapshot = VALUES(snapshot), created_by = VALUES(created_by), created_at = VALUES(created_at)',
				$course_id,
				$version,
				$data,
				get_current_user_id(),
				wic_now()
			)
		);
	}

	/** Freeze the outgoing version the moment a new one starts. */
	public static function freeze( $course_id, $old_version ) {
		self::save( $course_id, $old_version );
	}

	public static function get( $course_id, $version ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'course_versions' ) . ' WHERE course_id = %d AND version = %d', $course_id, $version ) );
		if ( ! $row ) {
			return null;
		}
		$row->data = json_decode( $row->snapshot, true );
		return is_array( $row->data ) ? $row : null;
	}

	/** Frozen versions of a course, oldest first. */
	public static function history( $course_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, course_id, version, created_by, created_at FROM ' . wic_table( 'course_versions' ) . ' WHERE course_id = %d ORDER BY version ASC', $course_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Which version is this learner on?                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * The version that was live when the learner's current run began: the oldest frozen
	 * version that closed after they started, otherwise the current one.
	 */
	public static function pinned_version( $user_id, $course_id ) {
		global $wpdb;
		$current = WIC_Content::course_version( $course_id );
		$ck      = $user_id . ':' . $course_id;
		if ( isset( self::$pins[ $ck ] ) ) {
			return self::$pins[ $ck ];
		}
		$run = WIC_Records::current_run( $user_id, $course_id );
		$key = '_wic_pin_' . $course_id . '_' . $run;
		$pin = (int) get_user_meta( $user_id, $key, true );
		if ( ! $pin ) {
			$pos = WIC_Records::position( $user_id, $course_id, $run );
			if ( ! $pos ) {
				return self::$pins[ $ck ] = $current; // Not started: they will start on the live version.
			}
			$pin = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(version) FROM ' . wic_table( 'course_versions' ) . ' WHERE course_id = %d AND created_at > %s', $course_id, $pos->first_access ) );
			$pin = $pin ? $pin : $current;
			update_user_meta( $user_id, $key, $pin );
		}
		return self::$pins[ $ck ] = $pin;
	}

	private static function snapshot_for( $course_id ) {
		$uid = get_current_user_id();
		if ( ! $uid || self::$building ) {
			return null;
		}
		$pin = self::pinned_version( $uid, $course_id );
		if ( $pin >= WIC_Content::course_version( $course_id ) ) {
			return null;
		}
		$snap = self::get( $course_id, $pin );
		return $snap ? $snap : null;
	}

	public static function tree( $modules, $course_id ) {
		$snap = self::snapshot_for( $course_id );
		if ( ! $snap ) {
			return $modules;
		}
		$out = array();
		foreach ( $snap->data['modules'] as $m ) {
			foreach ( $m['slides'] as $sid ) {
				if ( isset( $snap->data['slides'][ $sid ] ) ) {
					self::$active[ (int) $sid ] = $snap->data['slides'][ $sid ];
				}
			}
			$out[] = array(
				'id'         => (int) $m['id'],
				'title'      => $m['title'],
				'assessment' => (bool) $m['assessment'],
				'pass_mark'  => (int) $m['pass_mark'],
				'slides'     => array_map( 'intval', $m['slides'] ),
			);
		}
		return $out;
	}

	private static function meta( $slide, $key, $default = '' ) {
		return isset( $slide['meta'][ $key ] ) && '' !== $slide['meta'][ $key ] ? $slide['meta'][ $key ] : $default;
	}

	/** The same normalising WIC_Content::question() applies, from snapshot meta. */
	public static function normalise_question( $slide ) {
		if ( 'question' !== self::meta( $slide, '_wic_layout' ) ) {
			return null;
		}
		$q = json_decode( (string) self::meta( $slide, '_wic_question' ), true );
		if ( ! is_array( $q ) || empty( $q['type'] ) || ! in_array( $q['type'], WIC_Content::QUESTION_TYPES, true ) ) {
			return null;
		}
		$q['points']   = isset( $q['points'] ) ? (int) $q['points'] : 10;
		$q['attempts'] = isset( $q['attempts'] ) ? max( 1, (int) $q['attempts'] ) : 2;
		if ( 'tf' === $q['type'] && empty( $q['options'] ) ) {
			$answer       = ! empty( $q['answer'] );
			$q['options'] = array(
				array(
					'text'    => __( 'True', 'wic-tp' ),
					'correct' => $answer,
				),
				array(
					'text'    => __( 'False', 'wic-tp' ),
					'correct' => ! $answer,
				),
			);
		}
		return $q;
	}

	public static function question( $q, $slide_id ) {
		if ( ! isset( self::$active[ (int) $slide_id ] ) ) {
			return $q;
		}
		$snap = self::normalise_question( self::$active[ (int) $slide_id ] );
		return $snap ? $snap : $q;
	}

	public static function player_slide( $slide, $sid, $uid, $course_id ) {
		if ( ! isset( self::$active[ (int) $sid ] ) ) {
			return $slide;
		}
		$s      = self::$active[ (int) $sid ];
		$layers = array();
		foreach ( (array) json_decode( (string) self::meta( $s, '_wic_layers' ), true ) as $i => $l ) {
			if ( is_array( $l ) && ! empty( $l['label'] ) ) {
				$layers[] = array(
					'id'    => 'l' . $i,
					'label' => wp_strip_all_tags( $l['label'] ),
					'html'  => wp_kses_post( wpautop( isset( $l['content'] ) ? $l['content'] : '' ) ),
				);
			}
		}
		$seconds = (int) self::meta( $s, '_wic_seconds', 0 );
		return array_merge(
			$slide,
			array(
				'title'    => $s['title'],
				'layout'   => self::meta( $s, '_wic_layout', 'text' ),
				'html'     => wp_kses_post( wpautop( $s['content'] ) ),
				'image'    => esc_url_raw( self::meta( $s, '_wic_image_url' ) ),
				'alt'      => (string) self::meta( $s, '_wic_image_alt' ),
				'audio'    => esc_url_raw( self::meta( $s, '_wic_audio_url' ) ),
				'script'   => (string) self::meta( $s, '_wic_script' ),
				'seconds'  => $seconds > 0 ? $seconds : 45,
				'layers'   => $layers,
				'question' => WIC_Content::public_question( self::normalise_question( $s ) ),
			)
		);
	}

	/** Tell the player (and the learner) which version they are on. */
	public static function player_data( $data, $uid, $course_id ) {
		$pin               = self::pinned_version( $uid, $course_id );
		$data['version']   = $pin;
		$data['versionOf'] = WIC_Content::course_version( $course_id );
		return $data;
	}
}

add_action( 'wic_init', array( 'WIC_Course_Versions', 'init' ) );
