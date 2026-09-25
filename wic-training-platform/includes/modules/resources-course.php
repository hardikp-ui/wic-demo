<?php
/**
 * Course resources (#116 a companion document per course, #117 curated links with titles and
 * thumbnails, #118 checking the links still work) and #112 a live class recorded into a course.
 *
 * Resources reach the lesson player through `wic_player_data` as 'resources', so they are
 * reachable without leaving the lesson. Links are checked a few at a time on the hourly job.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Course_Resources {

	const BLANK_ROWS  = 3;
	const CHECK_BATCH = 10;
	const RECHECK     = WEEK_IN_SECONDS;

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_wic_course', array( __CLASS__, 'save' ) );
		add_filter( 'wic_player_data', array( __CLASS__, 'player_data' ), 10, 3 );
		add_action( 'wic_hourly', array( __CLASS__, 'check_links' ) );
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_action( 'admin_post_wic_record_class', array( __CLASS__, 'handle_record_class' ) );
		add_action( 'admin_post_wic_check_links_now', array( __CLASS__, 'handle_check_now' ) );
	}

	/** array( array( 'title', 'url', 'thumb', 'status', 'checked' ) ) */
	public static function links( $course_id ) {
		$l = get_post_meta( $course_id, '_wic_links', true );
		return is_array( $l ) ? array_values( $l ) : array();
	}

	public static function broken( $link ) {
		return ! empty( $link['checked'] ) && ( empty( $link['status'] ) || (int) $link['status'] >= 400 );
	}

	/* ------------------------------------------------------------------ */
	/* Authoring                                                          */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'wic_course_resources', __( 'Resources in the lesson', 'wic-tp' ), array( __CLASS__, 'box' ), 'wic_course', 'normal' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'wic_course_resources', 'wic_course_resources_nonce' );
		$doc   = (int) get_post_meta( $post->ID, '_wic_companion_doc', true );
		$links = array_merge( self::links( $post->ID ), array_fill( 0, self::BLANK_ROWS, array() ) );
		?>
		<p><label for="wic_companion_doc"><strong><?php esc_html_e( 'Companion document', 'wic-tp' ); ?></strong></label><br>
			<select id="wic_companion_doc" name="wic_companion_doc"><option value="0"><?php esc_html_e( '— None —', 'wic-tp' ); ?></option>
				<?php foreach ( WIC_E::options( 'wic_doc' ) as $id => $t ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( $doc, $id ); ?>><?php echo esc_html( $t ); ?></option>
				<?php endforeach; ?>
			</select> <span class="description"><?php esc_html_e( 'From the document library; its current version always opens.', 'wic-tp' ); ?></span></p>
		<h4><?php esc_html_e( 'Useful links', 'wic-tp' ); ?></h4>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Title', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Address', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Thumbnail image URL', 'wic-tp' ); ?></th><th><?php esc_html_e( 'Last check', 'wic-tp' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $links as $i => $l ) : ?>
				<tr>
					<td><label class="screen-reader-text" for="wic-lk-t-<?php echo (int) $i; ?>"><?php esc_html_e( 'Title', 'wic-tp' ); ?></label><input type="text" id="wic-lk-t-<?php echo (int) $i; ?>" name="wic_links[<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( isset( $l['title'] ) ? $l['title'] : '' ); ?>"></td>
					<td><label class="screen-reader-text" for="wic-lk-u-<?php echo (int) $i; ?>"><?php esc_html_e( 'Address', 'wic-tp' ); ?></label><input type="url" class="widefat" id="wic-lk-u-<?php echo (int) $i; ?>" name="wic_links[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( isset( $l['url'] ) ? $l['url'] : '' ); ?>"></td>
					<td><label class="screen-reader-text" for="wic-lk-i-<?php echo (int) $i; ?>"><?php esc_html_e( 'Thumbnail', 'wic-tp' ); ?></label><input type="url" class="widefat" id="wic-lk-i-<?php echo (int) $i; ?>" name="wic_links[<?php echo (int) $i; ?>][thumb]" value="<?php echo esc_attr( isset( $l['thumb'] ) ? $l['thumb'] : '' ); ?>"></td>
					<td><?php echo esc_html( self::status_text( $l ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Empty rows are ignored; save to get more. Links are checked weekly and broken ones are listed in the portal under Authoring → Broken links.', 'wic-tp' ); ?></p>
		<?php
	}

	public static function status_text( $l ) {
		if ( empty( $l['url'] ) ) {
			return '';
		}
		if ( empty( $l['checked'] ) ) {
			return __( 'Not checked yet', 'wic-tp' );
		}
		$when = wic_format_date( gmdate( 'Y-m-d H:i:s', (int) $l['checked'] ) );
		if ( self::broken( $l ) ) {
			/* translators: 1: status code or "no response", 2: date */
			return sprintf( __( 'Broken (%1$s), %2$s', 'wic-tp' ), empty( $l['status'] ) ? __( 'no response', 'wic-tp' ) : (int) $l['status'], $when );
		}
		/* translators: %s: date */
		return sprintf( __( 'Working, %s', 'wic-tp' ), $when );
	}

	public static function save( $post_id ) {
		if ( ! WIC_E::can_save( $post_id, 'wic_course_resources_nonce', 'wic_course_resources' ) ) {
			return;
		}
		update_post_meta( $post_id, '_wic_companion_doc', isset( $_POST['wic_companion_doc'] ) ? absint( $_POST['wic_companion_doc'] ) : 0 );
		$old = array();
		foreach ( self::links( $post_id ) as $l ) {
			$old[ $l['url'] ] = $l;
		}
		$out = array();
		foreach ( isset( $_POST['wic_links'] ) ? (array) wp_unslash( $_POST['wic_links'] ) : array() as $row ) {
			$url = isset( $row['url'] ) ? esc_url_raw( trim( $row['url'] ) ) : '';
			if ( ! $url ) {
				continue;
			}
			$title = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
			$out[] = array(
				'title'   => '' !== $title ? $title : $url,
				'url'     => $url,
				'thumb'   => isset( $row['thumb'] ) ? esc_url_raw( trim( $row['thumb'] ) ) : '',
				// A link whose address did not change keeps its last check.
				'status'  => isset( $old[ $url ] ) ? $old[ $url ]['status'] : 0,
				'checked' => isset( $old[ $url ] ) ? $old[ $url ]['checked'] : 0,
			);
		}
		update_post_meta( $post_id, '_wic_links', $out );
	}

	/* ------------------------------------------------------------------ */
	/* Player                                                             */
	/* ------------------------------------------------------------------ */

	public static function player_data( $data, $uid, $course_id ) {
		$res = isset( $data['resources'] ) && is_array( $data['resources'] ) ? $data['resources'] : array();
		$doc = (int) get_post_meta( $course_id, '_wic_companion_doc', true );
		if ( $doc && 'publish' === get_post_status( $doc ) && class_exists( 'WIC_Library' ) && WIC_Library::current_index( $doc ) >= 0 && WIC_Library::can_see( $doc, $uid ) ) {
			$res[] = array(
				'title' => get_the_title( $doc ),
				'url'   => WIC_Library::open_url( $doc ),
				'kind'  => 'document',
				'thumb' => '',
			);
		}
		foreach ( self::links( $course_id ) as $l ) {
			$res[] = array(
				'title' => $l['title'],
				'url'   => $l['url'],
				'kind'  => 'link',
				'thumb' => $l['thumb'],
			);
		}
		if ( $res ) {
			$data['resources'] = $res;
		}
		return $data;
	}

	/* ------------------------------------------------------------------ */
	/* Link checking                                                      */
	/* ------------------------------------------------------------------ */

	public static function check_url( $url ) {
		$args = array(
			'timeout'     => 8,
			'redirection' => 5,
			'user-agent'  => 'WIC Training Platform link check; ' . home_url( '/' ),
		);
		$r    = wp_remote_head( $url, $args );
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		// Some servers refuse HEAD; ask again with a small GET before calling a link broken.
		if ( ! $code || 405 === $code || 403 === $code || $code >= 500 ) {
			$r    = wp_remote_get( $url, $args + array( 'limit_response_size' => 4096 ) );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		}
		return $code;
	}

	/** A few links per run, oldest check first. */
	public static function check_links( $limit = self::CHECK_BATCH ) {
		$courses = get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'meta_key'       => '_wic_links',
				'fields'         => 'ids',
			)
		);
		$queue   = array();
		foreach ( $courses as $cid ) {
			foreach ( self::links( $cid ) as $i => $l ) {
				if ( empty( $l['checked'] ) || (int) $l['checked'] < time() - self::RECHECK ) {
					$queue[] = array( (int) $l['checked'], $cid, $i );
				}
			}
		}
		sort( $queue );
		$done = 0;
		// Hooked on the scheduled job, which passes its own argument (a date string), so cast.
		$limit = is_numeric( $limit ) ? max( 1, (int) $limit ) : 10;
		foreach ( array_slice( $queue, 0, $limit ) as $q ) {
			$links = self::links( $q[1] );
			if ( ! isset( $links[ $q[2] ] ) ) {
				continue;
			}
			$was                          = self::broken( $links[ $q[2] ] );
			$links[ $q[2] ]['status']  = self::check_url( $links[ $q[2] ]['url'] );
			$links[ $q[2] ]['checked'] = time();
			update_post_meta( $q[1], '_wic_links', $links );
			if ( ! $was && self::broken( $links[ $q[2] ] ) ) {
				wic_audit( 'link_broken', 'course', $q[1], array( 'url' => $links[ $q[2] ]['url'], 'status' => $links[ $q[2] ]['status'] ) );
			}
			$done++;
		}
		return $done;
	}

	/* ------------------------------------------------------------------ */
	/* Portal                                                             */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['broken_links'] = array(
			'label'    => __( 'Broken links', 'wic-tp' ),
			'group'    => 'author',
			'cap'      => 'wic_manage_content',
			'callback' => array( __CLASS__, 'view_links' ),
			'order'    => 60,
		);
		$views['record_class'] = array(
			'label'    => __( 'Record a live class', 'wic-tp' ),
			'group'    => 'author',
			'cap'      => 'wic_manage_content',
			'callback' => array( __CLASS__, 'view_record_class' ),
			'order'    => 65,
		);
		return $views;
	}

	public static function messages( $m ) {
		$m['links_checked']  = __( 'Links checked.', 'wic-tp' );
		$m['class_recorded'] = __( 'Draft course created from the recording. Review it, then publish.', 'wic-tp' );
		$m['err_class']      = __( 'Give the class a title and a video address.', 'wic-tp' );
		return $m;
	}

	public static function view_links( $uid ) {
		$courses = get_posts( array( 'post_type' => 'wic_course', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$rows    = array();
		$total   = 0;
		$unknown = 0;
		foreach ( $courses as $c ) {
			foreach ( self::links( $c->ID ) as $l ) {
				$total++;
				if ( empty( $l['checked'] ) ) {
					$unknown++;
				}
				if ( self::broken( $l ) ) {
					$rows[] = array( $c, $l );
				}
			}
		}
		?>
		<div class="wic-toolbar">
			<h2 class="wic-h"><?php esc_html_e( 'Broken links in courses', 'wic-tp' ); ?></h2>
			<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>">
				<input type="hidden" name="action" value="wic_check_links_now">
				<?php wp_nonce_field( 'wic_check_links_now' ); ?>
				<button type="submit" class="wic-btn"><?php esc_html_e( 'Check some links now', 'wic-tp' ); ?></button>
			</form>
		</div>
		<p class="wic-help"><?php echo esc_html( sprintf( __( '%1$d links across all courses; %2$d not checked yet. Links are re-checked weekly.', 'wic-tp' ), $total, $unknown ) ); ?></p>
		<?php if ( ! $rows ) : ?>
			<div class="wic-empty wic-empty--good"><p><?php esc_html_e( 'No broken links found.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
		<table class="wic-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Link', 'wic-tp' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'wic-tp' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'wic-tp' ); ?></span></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r[0]->post_title ); ?></td>
					<td><?php echo esc_html( $r[1]['title'] ); ?><br><code><?php echo esc_html( $r[1]['url'] ); ?></code></td>
					<td><strong class="wic-late"><?php echo esc_html( self::status_text( $r[1] ) ); ?></strong></td>
					<td><a class="wic-btn wic-btn--small" href="<?php echo esc_url( get_edit_post_link( $r[0]->ID ) ); ?>"><?php esc_html_e( 'Edit course', 'wic-tp' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	public static function handle_check_now() {
		check_admin_referer( 'wic_check_links_now' );
		if ( ! current_user_can( 'wic_manage_content' ) ) {
			WIC_E::deny();
		}
		self::check_links( self::CHECK_BATCH );
		WIC_Portal::back( 'broken_links', 'links_checked' );
	}

	public static function view_record_class( $uid ) {
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Turn a recorded live class into a course', 'wic-tp' ); ?></h2>
		<p class="wic-help"><?php esc_html_e( 'Creates a draft course with one video slide. The transcript becomes the slide transcript, so people who cannot listen can still follow. Add captions and questions before publishing.', 'wic-tp' ); ?></p>
		<form method="post" action="<?php echo esc_url( WIC_E::post_url() ); ?>" class="wic-form wic-panel">
			<input type="hidden" name="action" value="wic_record_class">
			<?php wp_nonce_field( 'wic_record_class' ); ?>
			<div class="wic-field"><label for="wic-rc-title"><?php esc_html_e( 'Course title', 'wic-tp' ); ?></label><input type="text" id="wic-rc-title" name="title" required></div>
			<div class="wic-field"><label for="wic-rc-video"><?php esc_html_e( 'Video address (the uploaded recording)', 'wic-tp' ); ?></label><input type="url" id="wic-rc-video" name="video" required></div>
			<div class="wic-field"><label for="wic-rc-date"><?php esc_html_e( 'Date of the class', 'wic-tp' ); ?></label><input type="date" id="wic-rc-date" name="date"></div>
			<div class="wic-field"><label for="wic-rc-text"><?php esc_html_e( 'Transcript', 'wic-tp' ); ?></label><textarea id="wic-rc-text" name="transcript" rows="8"></textarea></div>
			<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Create draft course', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	public static function handle_record_class() {
		check_admin_referer( 'wic_record_class' );
		if ( ! current_user_can( 'wic_manage_content' ) || ! current_user_can( 'publish_wic_items' ) ) {
			WIC_E::deny();
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$video = isset( $_POST['video'] ) ? esc_url_raw( wp_unslash( $_POST['video'] ) ) : '';
		$text  = isset( $_POST['transcript'] ) ? sanitize_textarea_field( wp_unslash( $_POST['transcript'] ) ) : '';
		$date  = isset( $_POST['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';
		if ( ! $title || ! $video ) {
			WIC_Portal::back( 'record_class', 'err_class' );
		}
		$intro  = $date ? sprintf( __( 'Recording of the live class held on %s.', 'wic-tp' ), mysql2date( get_option( 'date_format' ), $date ) ) : __( 'Recording of a live class.', 'wic-tp' );
		$course = wp_insert_post( array( 'post_type' => 'wic_course', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => $intro ) );
		if ( ! $course || is_wp_error( $course ) ) {
			WIC_Portal::back( 'record_class', 'error' );
		}
		update_post_meta( $course, '_wic_version', 1 );
		update_post_meta( $course, '_wic_nav_mode', 'free' );
		update_post_meta( $course, '_wic_source', 'live_class' );
		// Children are published so the course works as soon as the course itself is published.
		$module = wp_insert_post( array( 'post_type' => 'wic_module', 'post_status' => 'publish', 'post_parent' => $course, 'menu_order' => 10, 'post_title' => __( 'Recording', 'wic-tp' ) ) );
		$slide  = wp_insert_post( array( 'post_type' => 'wic_slide', 'post_status' => 'publish', 'post_parent' => $module, 'menu_order' => 10, 'post_title' => $title, 'post_content' => '<p>' . esc_html( $intro ) . '</p>' ) );
		if ( $slide && ! is_wp_error( $slide ) ) {
			update_post_meta( $slide, '_wic_layout', 'video' );
			update_post_meta( $slide, '_wic_video_url', $video );
			update_post_meta( $slide, '_wic_script', $text );
		}
		wic_audit( 'live_class_course', 'course', $course, array( 'video' => $video ) );
		wp_safe_redirect( add_query_arg( 'wic_msg', 'class_recorded', get_edit_post_link( $course, 'raw' ) ) );
		exit;
	}
}

add_action( 'wic_init', array( 'WIC_Course_Resources', 'init' ) );
