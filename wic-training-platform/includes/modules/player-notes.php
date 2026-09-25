<?php
/**
 * Bookmarks and private notes (#37). One row per learner per slide; only the learner reads it.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_table_sql',
	function ( $sql, $c ) {
		$sql[] = 'CREATE TABLE ' . wic_table( 'notes' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  slide_id bigint(20) unsigned NOT NULL,
  bookmark tinyint(1) NOT NULL DEFAULT 0,
  note text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_slide (user_id,slide_id),
  KEY user_course (user_id,course_id)
) $c;";
		return $sql;
	},
	10,
	2
);

class WIC_Notes {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
	}

	public static function routes() {
		register_rest_route(
			WIC_Rest::NS,
			'/note',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => array( 'WIC_Rest', 'can_learn' ),
				'args'                => array(
					'course' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'slide'  => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	public static function save( WP_REST_Request $req ) {
		global $wpdb;
		$uid    = get_current_user_id();
		$course = (int) $req['course'];
		$slide  = (int) $req['slide'];
		$flat   = WIC_Content::flat_slides( $course );
		if ( ! isset( $flat[ $slide ] ) ) {
			return new WP_Error( 'wic_bad_slide', __( 'That slide is not part of this course.', 'wic-tp' ), array( 'status' => 400 ) );
		}
		$note     = sanitize_textarea_field( (string) $req->get_param( 'note' ) );
		$bookmark = $req->get_param( 'bookmark' ) ? 1 : 0;
		if ( '' === trim( $note ) && ! $bookmark ) {
			$wpdb->delete( wic_table( 'notes' ), array( 'user_id' => $uid, 'slide_id' => $slide ) );
			return array( 'ok' => true, 'deleted' => true );
		}
		$now = wic_now();
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . wic_table( 'notes' ) . ' (user_id, course_id, slide_id, bookmark, note, created_at, updated_at) VALUES (%d, %d, %d, %d, %s, %s, %s) ON DUPLICATE KEY UPDATE bookmark = VALUES(bookmark), note = VALUES(note), updated_at = VALUES(updated_at)',
				$uid,
				$course,
				$slide,
				$bookmark,
				$note,
				$now,
				$now
			)
		);
		return array( 'ok' => true, 'saved' => $now );
	}

	/** slide_id => { note, bookmark } for the player. */
	public static function for_course( $user_id, $course_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT slide_id, bookmark, note FROM ' . wic_table( 'notes' ) . ' WHERE user_id = %d AND course_id = %d', $user_id, $course_id ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->slide_id ] = array(
				'note'     => (string) $r->note,
				'bookmark' => (bool) $r->bookmark,
			);
		}
		return $out;
	}

	public static function views( $views ) {
		$views['notes'] = array(
			'label'    => __( 'My notes', 'wic-tp' ),
			'group'    => 'learn',
			'cap'      => 'wic_learn',
			'callback' => array( __CLASS__, 'view' ),
			'order'    => 40,
		);
		return $views;
	}

	public static function view( $uid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'notes' ) . ' WHERE user_id = %d ORDER BY course_id ASC, updated_at DESC', $uid ) );
		?>
		<h2 class="wic-h"><?php esc_html_e( 'My notes and bookmarks', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Only you can see these. Add them from the Notes tab in any lesson.', 'wic-tp' ); ?></p>
		<?php if ( ! $rows ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No notes or bookmarks yet. In a lesson, open the Notes tab to bookmark a slide or write yourself a note.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		$by_course = array();
		foreach ( $rows as $r ) {
			if ( 'publish' !== get_post_status( $r->course_id ) ) {
				continue;
			}
			$by_course[ (int) $r->course_id ][] = $r;
		}
		foreach ( $by_course as $cid => $list ) :
			?>
			<section class="wic-section">
				<h3 class="wic-h3"><?php echo esc_html( get_the_title( $cid ) ); ?></h3>
				<div class="wic-table-wrap">
				<table class="wic-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Slide', 'wic-tp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Bookmarked', 'wic-tp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Note', 'wic-tp' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Updated', 'wic-tp' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $list as $r ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( wic_page_url( 'learn', array( 'course' => $cid, 'slide' => $r->slide_id ) ) ); ?>"><?php echo esc_html( get_the_title( $r->slide_id ) ); ?></a></td>
							<td><?php echo $r->bookmark ? esc_html__( 'Yes', 'wic-tp' ) : esc_html__( 'No', 'wic-tp' ); ?></td>
							<td><?php echo nl2br( esc_html( $r->note ) ); ?></td>
							<td><?php echo esc_html( wic_format_date( $r->updated_at, true ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</section>
			<?php
		endforeach;
	}
}

add_action( 'wic_init', array( 'WIC_Notes', 'init' ) );
