<?php
/**
 * Authoring inside the portal: correct wording, questions, images and slide order
 * without WP-admin and without the vendor.
 *
 * Views (group "author", capability wic_manage_content):
 *   author          — course list, new course, copy, retire, export, fork, share
 *   author_course   — course settings, modules and slides, updates offered to forks
 *   author_slide    — one slide: body, media, narration, layers, question builder
 *   author_import   — JSON package and CSV outline import
 *
 * Every write goes through current_user_can( 'edit_post' ), so the agency rules
 * (no editing the shared library or another agency's course) apply here too.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['decision_content_ownership'] = 0;
		$d['ownership_terms']            = '';
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['ownership_terms']            = array( __( 'Ownership terms for adapted content', 'wic-tp' ), 'textarea', __( 'Shown wherever a course is shared or copied from the shared library.', 'wic-tp' ) );
		$f['decision_content_ownership'] = array( __( 'Ownership of adapted content has been agreed', 'wic-tp' ), 'checkbox', __( 'If an agency\'s course goes into the shared library, whose is it? Tick once the terms above are agreed (item 22).', 'wic-tp' ) );
		return $f;
	}
);

class WIC_Authoring {

	const STATUSES = array( 'publish', 'draft', 'wic_retired' );

	public static function init() {
		add_filter( 'wic_portal_views', array( __CLASS__, 'views' ) );
		add_filter( 'wic_flash_messages', array( __CLASS__, 'messages' ) );
		add_filter( 'get_post_status', array( __CLASS__, 'preview_status' ), 10, 2 );
		foreach ( array( 'new_course', 'course_save', 'course_op', 'module_save', 'module_op', 'slide_new', 'slide_save', 'slide_op', 'import' ) as $a ) {
			add_action( 'admin_post_wic_author_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'admin_box' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Plumbing                                                           */
	/* ------------------------------------------------------------------ */

	public static function views( $views ) {
		$views['author']        = array(
			'label'    => __( 'Courses', 'wic-tp' ),
			'group'    => 'author',
			'cap'      => 'wic_manage_content',
			'callback' => array( __CLASS__, 'view_list' ),
			'order'    => 10,
		);
		$views['author_course'] = array(
			'label'    => __( 'Edit course', 'wic-tp' ),
			'group'    => 'author',
			'cap'      => 'wic_manage_content',
			'callback' => array( __CLASS__, 'view_course' ),
			'hidden'   => true,
		);
		$views['author_slide']  = array(
			'label'    => __( 'Edit slide', 'wic-tp' ),
			'group'    => 'author',
			'cap'      => 'wic_manage_content',
			'callback' => array( __CLASS__, 'view_slide' ),
			'hidden'   => true,
		);
		$views['author_import'] = array(
			'label'    => __( 'Import', 'wic-tp' ),
			'group'    => 'author',
			'cap'      => 'wic_manage_content',
			'callback' => array( __CLASS__, 'view_import' ),
			'order'    => 30,
		);
		return $views;
	}

	public static function messages( $m ) {
		return array_merge(
			$m,
			array(
				'author_created'     => __( 'Course created as a draft.', 'wic-tp' ),
				'author_saved'       => __( 'Saved.', 'wic-tp' ),
				'author_copied'      => __( 'Course copied. The copy is a draft.', 'wic-tp' ),
				'author_forked'      => __( 'Your agency\'s own copy has been made. Edit it freely — the shared original is unchanged, and you will be offered its updates.', 'wic-tp' ),
				'author_retired'     => __( 'Course retired. It has left the catalogue; every completion and certificate is kept.', 'wic-tp' ),
				'author_unretired'   => __( 'Course returned to draft. Publish it to put it back in the catalogue.', 'wic-tp' ),
				'author_published'   => __( 'Course published.', 'wic-tp' ),
				'author_drafted'     => __( 'Course moved back to draft.', 'wic-tp' ),
				'author_versioned'   => __( 'New version started. Learners part-way through keep the previous one.', 'wic-tp' ),
				'author_updated'     => __( 'Shared update taken into your copy.', 'wic-tp' ),
				'author_shared'      => __( 'Sharing changed.', 'wic-tp' ),
				'author_imported'    => __( 'Course imported as a draft.', 'wic-tp' ),
				'author_moved'       => __( 'Order changed.', 'wic-tp' ),
				'author_removed'     => __( 'Moved to the bin. Records that point at it are kept.', 'wic-tp' ),
				'author_slide_draft' => __( 'Saved, but kept as a draft — see the note below.', 'wic-tp' ),
				'err_author_import'  => __( 'The file could not be imported — see the details below.', 'wic-tp' ),
				'err_author_denied'  => __( 'That course cannot be edited from your agency. Make your own copy to change it.', 'wic-tp' ),
			)
		);
	}

	private static function deny() {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wic-tp' ), '', array( 'response' => 403 ) );
	}

	/** Notes for the next page view (import warnings, question problems). */
	private static function report( $lines = null ) {
		$key = 'wic_author_report_' . get_current_user_id();
		if ( null === $lines ) {
			$r = get_transient( $key );
			delete_transient( $key );
			return $r ? (array) $r : array();
		}
		set_transient( $key, (array) $lines, 300 );
		return array();
	}

	private static function show_report() {
		$lines = self::report();
		if ( ! $lines ) {
			return;
		}
		echo '<div class="wic-notice wic-notice--warn" role="status"><ul class="wic-plain">';
		foreach ( $lines as $l ) {
			echo '<li>' . esc_html( $l ) . '</li>';
		}
		echo '</ul></div>';
	}

	private static function post_id( $key ) {
		return isset( $_REQUEST[ $key ] ) ? absint( $_REQUEST[ $key ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view parameter or checked by caller.
	}

	private static function can_edit( $post_id ) {
		return $post_id && current_user_can( 'wic_manage_content' ) && current_user_can( 'edit_post', $post_id );
	}

	private static function status_badge( $status ) {
		$map = array(
			'publish'     => array( 'complete', __( 'Published', 'wic-tp' ) ),
			'draft'       => array( 'in_progress', __( 'Draft', 'wic-tp' ) ),
			'wic_retired' => array( 'not_started', __( 'Retired', 'wic-tp' ) ),
			'pending'     => array( 'in_progress', __( 'Pending', 'wic-tp' ) ),
		);
		$s   = isset( $map[ $status ] ) ? $map[ $status ] : array( 'not_started', $status );
		return '<span class="wic-badge wic-badge--' . esc_attr( $s[0] ) . '">' . esc_html( $s[1] ) . '</span>';
	}

	private static function kids( $parent, $type ) {
		return get_posts(
			array(
				'post_type'        => $type,
				'post_parent'      => (int) $parent,
				'post_status'      => array( 'publish', 'draft', 'pending' ),
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
	}

	/** Where a course sits in the library, in words. */
	private static function library_label( $course_id ) {
		$owner  = WIC_Agencies::course_agency( $course_id );
		$forked = (int) get_post_meta( $course_id, '_wic_forked_from', true );
		if ( $forked ) {
			return sprintf( __( 'Own copy of "%s"', 'wic-tp' ), get_the_title( $forked ) );
		}
		if ( ! $owner ) {
			return __( 'Shared library', 'wic-tp' );
		}
		$mine = wic_user_agency_id( get_current_user_id() );
		if ( $owner === $mine ) {
			return get_post_meta( $course_id, '_wic_shared', true ) ? __( 'Own · shared with other agencies', 'wic-tp' ) : __( 'Own', 'wic-tp' );
		}
		return sprintf( __( 'Shared by %s', 'wic-tp' ), WIC_Agencies::label( $owner ) );
	}

	private static function ownership_note() {
		$terms = trim( (string) wic_setting( 'ownership_terms' ) );
		if ( (int) wic_setting( 'decision_content_ownership' ) && $terms ) {
			echo '<div class="wic-notice"><strong>' . esc_html__( 'Ownership terms', 'wic-tp' ) . '</strong><p>' . nl2br( esc_html( $terms ) ) . '</p></div>';
		} else {
			echo '<div class="wic-notice wic-notice--warn">' . esc_html__( 'Who owns adapted content is not yet agreed (item 22). Copies are kept as the copying agency\'s own course until it is.', 'wic-tp' ) . '</div>';
		}
	}

	/** Draft courses can be previewed by the people who can edit them. */
	public static function preview_status( $status, $post ) {
		static $busy = false;
		if ( $busy || 'draft' !== $status || ! $post || 'wic_course' !== $post->post_type || ! is_user_logged_in() ) {
			return $status;
		}
		$is_learn = isset( $_GET['wic_preview'] ) && wic_page_id( 'learn' ) && did_action( 'wp' ) && (int) get_queried_object_id() === wic_page_id( 'learn' ); // phpcs:ignore WordPress.Security.NonceVerification
		$is_rest  = defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( wp_unslash( $_SERVER['REQUEST_URI'] ), WIC_Rest::NS ); // phpcs:ignore
		if ( ! $is_learn && ! $is_rest ) {
			return $status;
		}
		// The capability check itself reads the post status, so guard against re-entry.
		$busy = true;
		$ok   = current_user_can( 'wic_manage_content' ) && current_user_can( 'edit_post', $post->ID );
		$busy = false;
		return $ok ? 'publish' : $status;
	}

	private static function preview_url( $course_id ) {
		return wic_page_url(
			'learn',
			array(
				'course'      => $course_id,
				'wic_preview' => 1,
			)
		);
	}

	private static function form_open( $action, $nonce, $class = 'wic-inline-form' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $nonce );
	}

	/* ------------------------------------------------------------------ */
	/* Course list                                                        */
	/* ------------------------------------------------------------------ */

	public static function view_list( $uid ) {
		$filter  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$courses = get_posts(
			array(
				'post_type'      => 'wic_course',
				'post_status'    => in_array( $filter, self::STATUSES, true ) ? $filter : self::STATUSES,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$agency  = wic_user_agency_id( $uid );
		$others  = WIC_Agencies::all( false );
		?>
		<div class="wic-toolbar">
			<h2 class="wic-h"><?php esc_html_e( 'Courses', 'wic-tp' ); ?></h2>
			<?php self::form_open( 'wic_author_new_course', 'wic_author_new_course' ); ?>
				<label for="wic-new-title"><?php esc_html_e( 'New course title', 'wic-tp' ); ?></label>
				<input id="wic-new-title" name="title" required>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Create draft', 'wic-tp' ); ?></button>
			</form>
		</div>
		<?php self::show_report(); ?>
		<div class="wic-filters" role="group" aria-label="<?php esc_attr_e( 'Show', 'wic-tp' ); ?>">
			<?php
			foreach ( array( '' => __( 'All', 'wic-tp' ), 'publish' => __( 'Published', 'wic-tp' ), 'draft' => __( 'Drafts', 'wic-tp' ), 'wic_retired' => __( 'Retired', 'wic-tp' ) ) as $k => $l ) {
				echo '<a class="wic-chip" aria-pressed="' . ( $k === $filter ? 'true' : 'false' ) . '" href="' . esc_url( wic_portal_url( 'author', $k ? array( 'status' => $k ) : array() ) ) . '">' . esc_html( $l ) . '</a>';
			}
			?>
		</div>
		<?php if ( ! $courses ) : ?>
			<div class="wic-empty"><p><?php esc_html_e( 'No courses here yet. Create one above, or bring one in under Import.', 'wic-tp' ); ?></p></div>
			<?php
			return;
		endif;
		?>
		<div class="wic-table-wrap">
			<table class="wic-table" data-wic-sortable>
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Course', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Version', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Library', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Slides', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last updated', 'wic-tp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'wic-tp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $courses as $c ) : ?>
					<?php
					$editable = self::can_edit( $c->ID );
					$update   = get_post_meta( $c->ID, '_wic_forked_from', true ) ? WIC_Course_Package::pending_update( $c->ID ) : null;
					$slides   = 0;
					foreach ( self::kids( $c->ID, 'wic_module' ) as $m ) {
						$slides += count( self::kids( $m->ID, 'wic_slide' ) );
					}
					$owner = WIC_Agencies::course_agency( $c->ID );
					?>
					<tr>
						<th scope="row">
							<?php if ( $editable ) : ?>
								<a href="<?php echo esc_url( wic_portal_url( 'author_course', array( 'course' => $c->ID ) ) ); ?>"><?php echo esc_html( $c->post_title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $c->post_title ); ?> <span class="wic-tag"><?php esc_html_e( 'Read only', 'wic-tp' ); ?></span>
							<?php endif; ?>
							<?php if ( $update ) : ?>
								<br><span class="wic-badge wic-badge--overdue"><?php echo esc_html( sprintf( __( 'Update available: version %d', 'wic-tp' ), $update['version'] ) ); ?></span>
							<?php endif; ?>
						</th>
						<td><?php echo self::status_badge( $c->post_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo (int) WIC_Content::course_version( $c->ID ); ?></td>
						<td><?php echo esc_html( self::library_label( $c->ID ) ); ?></td>
						<td><?php echo (int) $slides; ?></td>
						<td data-sort="<?php echo esc_attr( $c->post_modified_gmt ); ?>"><?php echo esc_html( wic_format_date( $c->post_modified_gmt ) ); ?></td>
						<td>
							<div class="wic-actions">
								<?php if ( in_array( $c->post_status, array( 'publish', 'draft' ), true ) ) : ?>
									<a class="wic-btn wic-btn--small" href="<?php echo esc_url( self::preview_url( $c->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $c->post_title ); ?> <?php esc_html_e( '(opens in a new tab)', 'wic-tp' ); ?></span></a>
								<?php endif; ?>
								<?php self::op_button( $c->ID, 'export', __( 'Export', 'wic-tp' ) ); ?>
								<?php self::op_button( $c->ID, 'copy', __( 'Copy', 'wic-tp' ), '', $agency ? array() : $others ); ?>
								<?php if ( ! $editable && $agency && ! $owner ) : ?>
									<?php self::op_button( $c->ID, 'fork', __( 'Make our own copy', 'wic-tp' ), __( 'Make your agency\'s own editable copy of this shared course? You will be offered the shared version\'s updates.', 'wic-tp' ), array(), true ); ?>
								<?php endif; ?>
								<?php if ( $editable ) : ?>
									<?php if ( 'wic_retired' === $c->post_status ) : ?>
										<?php self::op_button( $c->ID, 'unretire', __( 'Unretire', 'wic-tp' ) ); ?>
									<?php else : ?>
										<?php self::op_button( $c->ID, 'retire', __( 'Retire', 'wic-tp' ), __( 'Retire this course? It leaves the catalogue and nobody new can be assigned it. Completions and certificates are kept.', 'wic-tp' ) ); ?>
									<?php endif; ?>
									<?php if ( $agency && $owner === $agency && (int) wic_setting( 'decision_share_courses' ) ) : ?>
										<?php self::op_button( $c->ID, get_post_meta( $c->ID, '_wic_shared', true ) ? 'unshare' : 'share', get_post_meta( $c->ID, '_wic_shared', true ) ? __( 'Stop sharing', 'wic-tp' ) : __( 'Share with other agencies', 'wic-tp' ) ); ?>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $agency ) : ?>
			<h3 class="wic-h3"><?php esc_html_e( 'About the shared library', 'wic-tp' ); ?></h3>
			<?php self::ownership_note(); ?>
		<?php endif; ?>
		<?php
	}

	/** One small form per course action; copy can target another agency. */
	private static function op_button( $course_id, $op, $label, $confirm = '', $agencies = array(), $primary = false ) {
		self::form_open( 'wic_author_course_op', 'wic_author_course_op_' . $course_id );
		echo '<input type="hidden" name="course" value="' . (int) $course_id . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		if ( $agencies ) {
			$id = 'wic-copy-to-' . (int) $course_id;
			echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Copy into', 'wic-tp' ) . '</label><select id="' . esc_attr( $id ) . '" name="agency"><option value="0">' . esc_html( WIC_Agencies::label( 0 ) ) . '</option>';
			foreach ( $agencies as $a ) {
				echo '<option value="' . (int) $a->id . '">' . esc_html( $a->name ) . '</option>';
			}
			echo '</select>';
		}
		echo '<button type="submit" class="wic-btn wic-btn--small' . ( $primary ? ' wic-btn--primary' : '' ) . '"' . ( $confirm ? ' data-wic-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html( get_the_title( $course_id ) ) . '</span></button></form>';
	}

	/* ------------------------------------------------------------------ */
	/* One course                                                         */
	/* ------------------------------------------------------------------ */

	public static function view_course( $uid ) {
		$id     = self::post_id( 'course' );
		$course = get_post( $id );
		if ( ! $course || 'wic_course' !== $course->post_type || ! self::can_edit( $id ) ) {
			echo '<div class="wic-notice wic-notice--err">' . esc_html__( 'This course cannot be edited from your account.', 'wic-tp' ) . '</div>';
			return;
		}
		$m      = function ( $k, $d = '' ) use ( $id ) {
			$v = get_post_meta( $id, $k, true );
			return '' === $v ? $d : $v;
		};
		$groups = (array) get_post_meta( $id, '_wic_groups', true );
		$update = get_post_meta( $id, '_wic_forked_from', true ) ? WIC_Course_Package::pending_update( $id ) : null;
		$tpls   = WIC_Slide_Templates::all();
		$nav    = apply_filters(
			'wic_nav_modes',
			array(
				'free'   => __( 'Free — jump anywhere in the outline', 'wic-tp' ),
				'linear' => __( 'Linear — cannot skip ahead of slides not yet seen', 'wic-tp' ),
			)
		);
		?>
		<p><a href="<?php echo esc_url( wic_portal_url( 'author' ) ); ?>">&larr; <?php esc_html_e( 'All courses', 'wic-tp' ); ?></a></p>
		<div class="wic-toolbar">
			<h2 class="wic-h"><?php echo esc_html( $course->post_title ); ?> <?php echo self::status_badge( $course->post_status ); // phpcs:ignore ?></h2>
			<div class="wic-actions">
				<a class="wic-btn" href="<?php echo esc_url( self::preview_url( $id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview as a learner', 'wic-tp' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'wic-tp' ); ?></span></a>
				<?php if ( 'publish' !== $course->post_status ) : ?>
					<?php self::op_button( $id, 'publish', __( 'Publish', 'wic-tp' ), '', array(), true ); ?>
				<?php else : ?>
					<?php self::op_button( $id, 'draft', __( 'Back to draft', 'wic-tp' ), __( 'Take this course out of the catalogue while you work on it? Existing assignments stay.', 'wic-tp' ) ); ?>
				<?php endif; ?>
				<a class="wic-btn" href="<?php echo esc_url( get_edit_post_link( $id, 'url' ) ); ?>"><?php esc_html_e( 'More settings (WP-admin)', 'wic-tp' ); ?></a>
			</div>
		</div>
		<p class="wic-meta"><?php echo esc_html( self::library_label( $id ) ); ?> · <?php echo esc_html( sprintf( __( 'Version %d', 'wic-tp' ), WIC_Content::course_version( $id ) ) ); ?> · <?php echo esc_html( sprintf( __( 'Last updated %s', 'wic-tp' ), wic_format_date( $course->post_modified_gmt, true ) ) ); ?></p>
		<?php self::show_report(); ?>

		<?php if ( $update ) : ?>
			<section class="wic-section wic-panel" aria-labelledby="wic-upd-h">
				<h3 class="wic-h3" id="wic-upd-h"><?php echo esc_html( sprintf( __( 'Shared version %1$d available (you have version %2$d)', 'wic-tp' ), $update['version'], $update['from'] ) ); ?></h3>
				<?php if ( ! $update['changes'] ) : ?>
					<p><?php esc_html_e( 'The shared course has a new version number but no slide content changed.', 'wic-tp' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'These slides changed in the shared original. Taking the update overwrites your copies of them; your other slides stay as they are.', 'wic-tp' ); ?></p>
					<ul>
						<?php
						$words = array(
							'added'   => __( 'New', 'wic-tp' ),
							'changed' => __( 'Changed', 'wic-tp' ),
							'removed' => __( 'Removed (your copy becomes a draft)', 'wic-tp' ),
						);
						foreach ( $update['changes'] as $ch ) {
							echo '<li><strong>' . esc_html( $words[ $ch['type'] ] ) . ':</strong> ' . esc_html( $ch['title'] ) . '</li>';
						}
						?>
					</ul>
				<?php endif; ?>
				<?php self::ownership_note(); ?>
				<?php self::op_button( $id, 'take_update', __( 'Take update', 'wic-tp' ), __( 'Take the shared update? Your course starts a new version first, so your learners part-way through are not affected.', 'wic-tp' ), array(), true ); ?>
				<p class="wic-help"><?php esc_html_e( 'Updates are offered, never forced. Ignore this and your copy stays exactly as it is.', 'wic-tp' ); ?></p>
			</section>
		<?php endif; ?>

		<details class="wic-section wic-panel" <?php echo $update ? '' : 'open'; ?>>
			<summary><strong><?php esc_html_e( 'Course settings', 'wic-tp' ); ?></strong></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-form--wide">
				<input type="hidden" name="action" value="wic_author_course_save">
				<input type="hidden" name="course" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'wic_author_course_save_' . $id ); ?>
				<div class="wic-field"><label for="wic-c-title"><?php esc_html_e( 'Title', 'wic-tp' ); ?></label><input id="wic-c-title" name="title" value="<?php echo esc_attr( $course->post_title ); ?>" required></div>
				<div class="wic-field"><label for="wic-c-desc"><?php esc_html_e( 'Description', 'wic-tp' ); ?></label><textarea id="wic-c-desc" name="content" rows="3"><?php echo esc_textarea( $course->post_content ); ?></textarea></div>
				<div class="wic-grid2">
					<div class="wic-field"><span class="wic-label"><?php esc_html_e( 'Required training', 'wic-tp' ); ?></span><label><input type="checkbox" name="required" value="1" <?php checked( (bool) $m( '_wic_required' ) ); ?>> <?php esc_html_e( 'Required (drives auto-assignment)', 'wic-tp' ); ?></label></div>
					<fieldset class="wic-field"><legend class="wic-label"><?php esc_html_e( 'Auto-assign to', 'wic-tp' ); ?></legend>
						<label><input type="checkbox" name="groups[]" value="staff" <?php checked( in_array( 'staff', $groups, true ) ); ?>> <?php esc_html_e( 'Staff', 'wic-tp' ); ?></label>
						<label><input type="checkbox" name="groups[]" value="intern" <?php checked( in_array( 'intern', $groups, true ) ); ?>> <?php esc_html_e( 'Interns', 'wic-tp' ); ?></label>
					</fieldset>
					<div class="wic-field"><label for="wic-c-due"><?php esc_html_e( 'Due within (days, 0 = none)', 'wic-tp' ); ?></label><input type="number" min="0" id="wic-c-due" name="due_days" value="<?php echo esc_attr( $m( '_wic_due_days', '30' ) ); ?>"></div>
					<div class="wic-field"><label for="wic-c-pass"><?php esc_html_e( 'Default pass mark (%)', 'wic-tp' ); ?></label><input type="number" min="0" max="100" id="wic-c-pass" name="pass_mark" value="<?php echo esc_attr( $m( '_wic_pass_mark' ) ); ?>" placeholder="<?php echo esc_attr( wic_setting( 'pass_mark' ) ); ?>"></div>
					<div class="wic-field"><label for="wic-c-nav"><?php esc_html_e( 'Navigation', 'wic-tp' ); ?></label>
						<select id="wic-c-nav" name="nav_mode">
							<?php foreach ( $nav as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $m( '_wic_nav_mode', 'free' ), $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select></div>
					<div class="wic-field"><span class="wic-label"><?php esc_html_e( 'Auto-advance', 'wic-tp' ); ?></span><label><input type="checkbox" name="auto_advance" value="1" <?php checked( (bool) $m( '_wic_auto_advance' ) ); ?>> <?php esc_html_e( 'Move on when narration ends', 'wic-tp' ); ?></label></div>
					<div class="wic-field"><label for="wic-c-ct"><?php esc_html_e( 'Credit type', 'wic-tp' ); ?></label><input id="wic-c-ct" name="credit_type" value="<?php echo esc_attr( $m( '_wic_credit_type' ) ); ?>"></div>
					<div class="wic-field"><label for="wic-c-ch"><?php esc_html_e( 'Credit hours (empty = calculated)', 'wic-tp' ); ?></label><input type="number" step="0.25" min="0" id="wic-c-ch" name="credit_hours" value="<?php echo esc_attr( $m( '_wic_credit_hours' ) ); ?>"><p class="wic-help"><?php echo esc_html( sprintf( __( 'Calculated from the slides: about %d minutes.', 'wic-tp' ), (int) round( WIC_Content::course_seconds( $id ) / 60 ) ) ); ?></p></div>
					<div class="wic-field"><label for="wic-c-val"><?php esc_html_e( 'Recertify every (months, 0 = never)', 'wic-tp' ); ?></label><input type="number" min="0" id="wic-c-val" name="validity_months" value="<?php echo esc_attr( $m( '_wic_validity_months', '0' ) ); ?>"></div>
					<div class="wic-field"><label for="wic-c-own"><?php esc_html_e( 'Content owner', 'wic-tp' ); ?></label><input id="wic-c-own" name="owner" value="<?php echo esc_attr( $m( '_wic_owner' ) ); ?>"></div>
				</div>
				<div class="wic-field"><span class="wic-label"><?php esc_html_e( 'Version', 'wic-tp' ); ?></span><label><input type="checkbox" name="bump_version" value="1"> <?php echo esc_html( sprintf( __( 'Start version %d before making changes — learners already part-way through keep version %d.', 'wic-tp' ), WIC_Content::course_version( $id ) + 1, WIC_Content::course_version( $id ) ) ); ?></label></div>
				<p><button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Save settings', 'wic-tp' ); ?></button></p>
			</form>
		</details>

		<section class="wic-section">
			<h3 class="wic-h3"><?php esc_html_e( 'Modules and slides', 'wic-tp' ); ?></h3>
			<?php $modules = self::kids( $id, 'wic_module' ); ?>
			<?php if ( ! $modules ) : ?>
				<div class="wic-empty"><p><?php esc_html_e( 'No modules yet. Add the first one below.', 'wic-tp' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $modules as $mi => $mod ) : ?>
				<?php $slides = self::kids( $mod->ID, 'wic_slide' ); ?>
				<div class="wic-panel wic-module" id="wic-mod-<?php echo (int) $mod->ID; ?>">
					<div class="wic-toolbar">
						<h4 class="wic-module__title"><?php echo esc_html( ( $mi + 1 ) . '. ' . $mod->post_title ); ?> <?php echo 'publish' !== $mod->post_status ? self::status_badge( $mod->post_status ) : ''; // phpcs:ignore ?>
							<?php if ( get_post_meta( $mod->ID, '_wic_assessment', true ) ) : ?><span class="wic-tag"><?php esc_html_e( 'Graded', 'wic-tp' ); ?></span><?php endif; ?></h4>
						<div class="wic-actions">
							<?php self::module_op( $mod->ID, 'up', __( 'Move up', 'wic-tp' ), 0 === $mi ); ?>
							<?php self::module_op( $mod->ID, 'down', __( 'Move down', 'wic-tp' ), count( $modules ) - 1 === $mi ); ?>
							<?php self::module_op( $mod->ID, 'publish' === $mod->post_status ? 'draft' : 'publish', 'publish' === $mod->post_status ? __( 'Hide', 'wic-tp' ) : __( 'Publish', 'wic-tp' ) ); ?>
							<?php self::module_op( $mod->ID, 'trash', __( 'Remove', 'wic-tp' ), false, __( 'Remove this module and hide its slides from learners? Records that point at them are kept.', 'wic-tp' ) ); ?>
						</div>
					</div>
					<details>
						<summary><?php esc_html_e( 'Module settings', 'wic-tp' ); ?></summary>
						<?php self::module_form( $id, $mod ); ?>
					</details>
					<?php if ( $slides ) : ?>
						<ol class="wic-slides">
							<?php foreach ( $slides as $si => $s ) : ?>
								<?php
								$img = get_post_meta( $s->ID, '_wic_image_url', true );
								$bad = $img && ! wic_alt_is_plausible( get_post_meta( $s->ID, '_wic_image_alt', true ) );
								?>
								<li class="wic-slide-row">
									<span class="wic-slide-row__title">
										<a href="<?php echo esc_url( wic_portal_url( 'author_slide', array( 'slide' => $s->ID ) ) ); ?>"><?php echo esc_html( $s->post_title ); ?></a>
										<span class="wic-tag"><?php echo esc_html( ucfirst( (string) get_post_meta( $s->ID, '_wic_layout', true ) ?: 'text' ) ); ?></span>
										<?php echo 'publish' !== $s->post_status ? self::status_badge( $s->post_status ) : ''; // phpcs:ignore ?>
										<?php if ( $bad ) : ?><span class="wic-badge wic-badge--overdue"><?php esc_html_e( 'Needs alt text', 'wic-tp' ); ?></span><?php endif; ?>
									</span>
									<span class="wic-actions">
										<?php self::slide_op( $s->ID, 'up', __( 'Up', 'wic-tp' ), 0 === $si ); ?>
										<?php self::slide_op( $s->ID, 'down', __( 'Down', 'wic-tp' ), count( $slides ) - 1 === $si ); ?>
										<a class="wic-btn wic-btn--small" href="<?php echo esc_url( add_query_arg( 'slide', $s->ID, self::preview_url( $id ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'wic-tp' ); ?><span class="screen-reader-text"> <?php echo esc_html( $s->post_title ); ?></span></a>
										<?php self::slide_op( $s->ID, 'trash', __( 'Remove', 'wic-tp' ), false, __( 'Remove this slide? Records that point at it are kept.', 'wic-tp' ) ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p class="wic-help"><?php esc_html_e( 'No slides in this module yet.', 'wic-tp' ); ?></p>
					<?php endif; ?>
					<?php self::form_open( 'wic_author_slide_new', 'wic_author_slide_new_' . $mod->ID ); ?>
						<input type="hidden" name="module" value="<?php echo (int) $mod->ID; ?>">
						<label for="wic-ns-<?php echo (int) $mod->ID; ?>"><?php esc_html_e( 'New slide', 'wic-tp' ); ?></label>
						<input id="wic-ns-<?php echo (int) $mod->ID; ?>" name="title" placeholder="<?php esc_attr_e( 'Slide title', 'wic-tp' ); ?>">
						<label for="wic-nt-<?php echo (int) $mod->ID; ?>" class="screen-reader-text"><?php esc_html_e( 'Template', 'wic-tp' ); ?></label>
						<select id="wic-nt-<?php echo (int) $mod->ID; ?>" name="template">
							<option value="0"><?php esc_html_e( 'Blank slide', 'wic-tp' ); ?></option>
							<?php foreach ( $tpls as $t ) : ?>
								<option value="<?php echo (int) $t->ID; ?>"><?php echo esc_html( sprintf( __( 'From template: %s', 'wic-tp' ), $t->post_title ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="wic-btn wic-btn--small wic-btn--primary"><?php esc_html_e( 'Add slide', 'wic-tp' ); ?></button>
					</form>
				</div>
			<?php endforeach; ?>

			<div class="wic-panel">
				<h4 class="wic-module__title"><?php esc_html_e( 'Add a module', 'wic-tp' ); ?></h4>
				<?php self::module_form( $id, null ); ?>
			</div>
		</section>

		<?php $history = WIC_Course_Versions::history( $id ); ?>
		<?php if ( $history ) : ?>
			<section class="wic-section">
				<h3 class="wic-h3"><?php esc_html_e( 'Earlier versions kept for learners part-way through', 'wic-tp' ); ?></h3>
				<ul>
					<?php foreach ( $history as $h ) : ?>
						<li><?php echo esc_html( sprintf( __( 'Version %1$d — frozen %2$s', 'wic-tp' ), $h->version, wic_format_date( $h->created_at, true ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		<?php
	}

	private static function module_form( $course_id, $mod ) {
		$mid = $mod ? (int) $mod->ID : 0;
		$pfx = 'wic-m-' . ( $mid ? $mid : 'new' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wic-inline-form wic-module-form">';
		echo '<input type="hidden" name="action" value="wic_author_module_save"><input type="hidden" name="course" value="' . (int) $course_id . '"><input type="hidden" name="module" value="' . (int) $mid . '">';
		wp_nonce_field( 'wic_author_module_save_' . $course_id );
		?>
		<label for="<?php echo esc_attr( $pfx ); ?>-t"><?php esc_html_e( 'Title', 'wic-tp' ); ?></label>
		<input id="<?php echo esc_attr( $pfx ); ?>-t" name="title" value="<?php echo esc_attr( $mod ? $mod->post_title : '' ); ?>" required>
		<label><input type="checkbox" name="assessment" value="1" <?php checked( $mod && get_post_meta( $mid, '_wic_assessment', true ) ); ?>> <?php esc_html_e( 'Graded — must be passed', 'wic-tp' ); ?></label>
		<label for="<?php echo esc_attr( $pfx ); ?>-p"><?php esc_html_e( 'Pass mark %', 'wic-tp' ); ?></label>
		<input type="number" min="0" max="100" id="<?php echo esc_attr( $pfx ); ?>-p" name="pass_mark" value="<?php echo esc_attr( $mod ? get_post_meta( $mid, '_wic_pass_mark', true ) : '' ); ?>" placeholder="<?php esc_attr_e( 'Course default', 'wic-tp' ); ?>" style="width:7rem">
		<button type="submit" class="wic-btn wic-btn--small <?php echo $mid ? '' : 'wic-btn--primary'; ?>"><?php echo $mid ? esc_html__( 'Save module', 'wic-tp' ) : esc_html__( 'Add module', 'wic-tp' ); ?></button>
		</form>
		<?php
	}

	private static function module_op( $mid, $op, $label, $disabled = false, $confirm = '' ) {
		self::form_open( 'wic_author_module_op', 'wic_author_module_op_' . $mid );
		echo '<input type="hidden" name="module" value="' . (int) $mid . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<button type="submit" class="wic-btn wic-btn--small"' . ( $disabled ? ' disabled' : '' ) . ( $confirm ? ' data-wic-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html( get_the_title( $mid ) ) . '</span></button></form>';
	}

	private static function slide_op( $sid, $op, $label, $disabled = false, $confirm = '' ) {
		self::form_open( 'wic_author_slide_op', 'wic_author_slide_op_' . $sid );
		echo '<input type="hidden" name="slide" value="' . (int) $sid . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<button type="submit" class="wic-btn wic-btn--small"' . ( $disabled ? ' disabled' : '' ) . ( $confirm ? ' data-wic-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html( get_the_title( $sid ) ) . '</span></button></form>';
	}

	/* ------------------------------------------------------------------ */
	/* One slide                                                          */
	/* ------------------------------------------------------------------ */

	public static function view_slide( $uid ) {
		$sid   = self::post_id( 'slide' );
		$slide = get_post( $sid );
		if ( ! $slide || 'wic_slide' !== $slide->post_type || ! self::can_edit( $sid ) ) {
			echo '<div class="wic-notice wic-notice--err">' . esc_html__( 'This slide cannot be edited from your account.', 'wic-tp' ) . '</div>';
			return;
		}
		$course_id = WIC_Agencies::course_of( $sid );
		$m         = function ( $k, $d = '' ) use ( $sid ) {
			$v = get_post_meta( $sid, $k, true );
			return '' === $v ? $d : $v;
		};
		$layers    = json_decode( (string) $m( '_wic_layers' ), true );
		$layers    = is_array( $layers ) ? $layers : array();
		$q         = json_decode( (string) $m( '_wic_question' ), true );
		$q         = is_array( $q ) ? $q : array();
		$qtype     = isset( $q['type'] ) ? $q['type'] : 'mc';
		$modules   = self::kids( $course_id, 'wic_module' );
		$targets   = array();
		foreach ( $modules as $mod ) {
			foreach ( self::kids( $mod->ID, 'wic_slide' ) as $s ) {
				if ( $s->ID !== $sid ) {
					$targets[ $s->ID ] = $mod->post_title . ' › ' . $s->post_title;
				}
			}
		}
		?>
		<p><a href="<?php echo esc_url( wic_portal_url( 'author_course', array( 'course' => $course_id ) ) . '#wic-mod-' . (int) $slide->post_parent ); ?>">&larr; <?php echo esc_html( get_the_title( $course_id ) ); ?></a></p>
		<div class="wic-toolbar">
			<h2 class="wic-h"><?php echo esc_html( $slide->post_title ); ?> <?php echo self::status_badge( $slide->post_status ); // phpcs:ignore ?></h2>
			<a class="wic-btn" href="<?php echo esc_url( add_query_arg( 'slide', $sid, self::preview_url( $course_id ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View in the player', 'wic-tp' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'wic-tp' ); ?></span></a>
		</div>
		<?php self::show_report(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-form--wide wic-slide-editor" data-wic-slide-editor>
			<input type="hidden" name="action" value="wic_author_slide_save">
			<input type="hidden" name="slide" value="<?php echo (int) $sid; ?>">
			<?php wp_nonce_field( 'wic_author_slide_save_' . $sid ); ?>

			<div class="wic-grid2">
				<div class="wic-field"><label for="wic-s-title"><?php esc_html_e( 'Title', 'wic-tp' ); ?></label><input id="wic-s-title" name="title" value="<?php echo esc_attr( $slide->post_title ); ?>" required></div>
				<div class="wic-field"><label for="wic-s-mod"><?php esc_html_e( 'Module', 'wic-tp' ); ?></label>
					<select id="wic-s-mod" name="module">
						<?php foreach ( $modules as $mod ) : ?>
							<option value="<?php echo (int) $mod->ID; ?>" <?php selected( (int) $slide->post_parent, (int) $mod->ID ); ?>><?php echo esc_html( $mod->post_title ); ?></option>
						<?php endforeach; ?>
					</select></div>
				<div class="wic-field"><label for="wic-s-layout"><?php esc_html_e( 'Layout', 'wic-tp' ); ?></label>
					<select id="wic-s-layout" name="layout" data-wic-layout>
						<?php foreach ( WIC_Content::layouts() as $l ) : ?>
							<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $m( '_wic_layout', 'text' ), $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option>
						<?php endforeach; ?>
					</select></div>
				<div class="wic-field"><label for="wic-s-status"><?php esc_html_e( 'Status', 'wic-tp' ); ?></label>
					<select id="wic-s-status" name="status">
						<option value="publish" <?php selected( $slide->post_status, 'publish' ); ?>><?php esc_html_e( 'Published — learners see it', 'wic-tp' ); ?></option>
						<option value="draft" <?php selected( 'publish' !== $slide->post_status ); ?>><?php esc_html_e( 'Draft — hidden from learners', 'wic-tp' ); ?></option>
					</select></div>
			</div>

			<div class="wic-field"><label for="wic-s-body"><?php esc_html_e( 'Slide text', 'wic-tp' ); ?></label>
				<textarea id="wic-s-body" name="content" rows="8"><?php echo esc_textarea( $slide->post_content ); ?></textarea>
				<p class="wic-help"><?php esc_html_e( 'Plain paragraphs, or simple HTML (headings, lists, bold, links). Any image in the text needs alt text before the slide can be published.', 'wic-tp' ); ?></p></div>

			<fieldset class="wic-panel">
				<legend><strong><?php esc_html_e( 'Image', 'wic-tp' ); ?></strong></legend>
				<div class="wic-field"><label for="wic-s-img"><?php esc_html_e( 'Image URL', 'wic-tp' ); ?></label><input type="url" id="wic-s-img" name="image_url" value="<?php echo esc_attr( $m( '_wic_image_url' ) ); ?>"></div>
				<div class="wic-field"><label for="wic-s-alt"><?php esc_html_e( 'Describe the image (alt text)', 'wic-tp' ); ?></label><input id="wic-s-alt" name="image_alt" value="<?php echo esc_attr( $m( '_wic_image_alt' ) ); ?>" aria-describedby="wic-s-alt-help">
					<p class="wic-help" id="wic-s-alt-help"><?php esc_html_e( 'Required when there is an image. Empty or filename-like text (such as IMG_0042.jpg) keeps the slide as a draft.', 'wic-tp' ); ?></p></div>
			</fieldset>

			<fieldset class="wic-panel">
				<legend><strong><?php esc_html_e( 'Narration', 'wic-tp' ); ?></strong></legend>
				<div class="wic-grid2">
					<div class="wic-field"><label for="wic-s-audio"><?php esc_html_e( 'Audio URL', 'wic-tp' ); ?></label><input type="url" id="wic-s-audio" name="audio_url" value="<?php echo esc_attr( $m( '_wic_audio_url' ) ); ?>"></div>
					<div class="wic-field"><label for="wic-s-sec"><?php esc_html_e( 'Length (seconds)', 'wic-tp' ); ?></label><input type="number" min="0" id="wic-s-sec" name="seconds" value="<?php echo esc_attr( $m( '_wic_seconds' ) ); ?>" placeholder="45"></div>
				</div>
				<div class="wic-field"><label for="wic-s-script"><?php esc_html_e( 'Narration script and transcript', 'wic-tp' ); ?></label><textarea id="wic-s-script" name="script" rows="4"><?php echo esc_textarea( $m( '_wic_script' ) ); ?></textarea>
					<p class="wic-help"><?php esc_html_e( 'One field feeds the audio, the transcript panel and captions.', 'wic-tp' ); ?></p></div>
			</fieldset>

			<fieldset class="wic-panel">
				<legend><strong><?php esc_html_e( 'Layers (hidden steps revealed by a button)', 'wic-tp' ); ?></strong></legend>
				<div data-wic-rows="layers">
					<?php
					$rows = $layers;
					$rows[] = array();
					foreach ( $rows as $i => $l ) {
						self::layer_row( $i, $l );
					}
					?>
				</div>
				<template data-wic-row-template="layers"><?php self::layer_row( '__i__', array() ); ?></template>
				<button type="button" class="wic-btn wic-btn--small" data-wic-add-row="layers"><?php esc_html_e( 'Add a layer', 'wic-tp' ); ?></button>
				<p class="wic-help"><?php esc_html_e( 'Leave a button label empty to remove that layer.', 'wic-tp' ); ?></p>
			</fieldset>

			<fieldset class="wic-panel" data-wic-question-panel>
				<legend><strong><?php esc_html_e( 'Question (used when the layout is Question)', 'wic-tp' ); ?></strong></legend>
				<div class="wic-grid2">
					<div class="wic-field"><label for="wic-q-type"><?php esc_html_e( 'Question type', 'wic-tp' ); ?></label>
						<select id="wic-q-type" name="q[type]" data-wic-qtype>
							<?php
							foreach ( array(
								'mc'    => __( 'Multiple choice — one answer', 'wic-tp' ),
								'mr'    => __( 'Multiple response — several answers', 'wic-tp' ),
								'tf'    => __( 'True or false', 'wic-tp' ),
								'sort'  => __( 'Sort into categories', 'wic-tp' ),
								'match' => __( 'Match pairs', 'wic-tp' ),
							) as $k => $l ) {
								echo '<option value="' . esc_attr( $k ) . '" ' . selected( $qtype, $k, false ) . '>' . esc_html( $l ) . '</option>';
							}
							?>
						</select></div>
					<div class="wic-field"><label for="wic-q-explain"><?php esc_html_e( 'If wrong, point back to', 'wic-tp' ); ?></label>
						<select id="wic-q-explain" name="q[explain_slide]">
							<option value="0"><?php esc_html_e( '— No review link —', 'wic-tp' ); ?></option>
							<?php foreach ( $targets as $tid => $tl ) : ?>
								<option value="<?php echo (int) $tid; ?>" <?php selected( isset( $q['explain_slide'] ) ? (int) $q['explain_slide'] : 0, $tid ); ?>><?php echo esc_html( $tl ); ?></option>
							<?php endforeach; ?>
						</select></div>
					<div class="wic-field"><label for="wic-q-points"><?php esc_html_e( 'Points', 'wic-tp' ); ?></label><input type="number" min="0" id="wic-q-points" name="q[points]" value="<?php echo esc_attr( isset( $q['points'] ) ? $q['points'] : 10 ); ?>"></div>
					<div class="wic-field"><label for="wic-q-att"><?php esc_html_e( 'Attempts', 'wic-tp' ); ?></label><input type="number" min="1" max="10" id="wic-q-att" name="q[attempts]" value="<?php echo esc_attr( isset( $q['attempts'] ) ? $q['attempts'] : 2 ); ?>"></div>
				</div>
				<div class="wic-field"><label for="wic-q-prompt"><?php esc_html_e( 'Question', 'wic-tp' ); ?></label><textarea id="wic-q-prompt" name="q[prompt]" rows="2"><?php echo esc_textarea( isset( $q['prompt'] ) ? $q['prompt'] : '' ); ?></textarea></div>

				<div data-qshow="mc mr">
					<p class="wic-label"><?php esc_html_e( 'Answer options', 'wic-tp' ); ?></p>
					<div data-wic-rows="options">
						<?php
						$opts = isset( $q['options'] ) && in_array( $qtype, array( 'mc', 'mr' ), true ) ? (array) $q['options'] : array();
						while ( count( $opts ) < 4 ) {
							$opts[] = array();
						}
						foreach ( $opts as $i => $o ) {
							self::option_row( $i, $o );
						}
						?>
					</div>
					<template data-wic-row-template="options"><?php self::option_row( '__i__', array() ); ?></template>
					<button type="button" class="wic-btn wic-btn--small" data-wic-add-row="options"><?php esc_html_e( 'Add an option', 'wic-tp' ); ?></button>
					<p class="wic-help"><?php esc_html_e( 'Tick the correct answer (exactly one for multiple choice). Empty options are dropped.', 'wic-tp' ); ?></p>
				</div>

				<div data-qshow="tf">
					<fieldset class="wic-field"><legend class="wic-label"><?php esc_html_e( 'The statement is', 'wic-tp' ); ?></legend>
						<label><input type="radio" name="q[answer]" value="1" <?php checked( 'tf' !== $qtype || ! empty( $q['answer'] ) ); ?>> <?php esc_html_e( 'True', 'wic-tp' ); ?></label>
						<label><input type="radio" name="q[answer]" value="0" <?php checked( 'tf' === $qtype && empty( $q['answer'] ) ); ?>> <?php esc_html_e( 'False', 'wic-tp' ); ?></label>
					</fieldset>
				</div>

				<div data-qshow="sort">
					<div class="wic-field"><label for="wic-q-cats"><?php esc_html_e( 'Categories, one per line', 'wic-tp' ); ?></label>
						<textarea id="wic-q-cats" name="q[categories]" rows="3" data-wic-categories><?php echo esc_textarea( isset( $q['categories'] ) ? implode( "\n", (array) $q['categories'] ) : '' ); ?></textarea></div>
					<p class="wic-label"><?php esc_html_e( 'Items to sort', 'wic-tp' ); ?></p>
					<div data-wic-rows="items">
						<?php
						$cats  = isset( $q['categories'] ) ? array_values( (array) $q['categories'] ) : array();
						$items = 'sort' === $qtype && isset( $q['items'] ) ? (array) $q['items'] : array();
						while ( count( $items ) < 3 ) {
							$items[] = array();
						}
						foreach ( $items as $i => $it ) {
							self::item_row( $i, $it, $cats );
						}
						?>
					</div>
					<template data-wic-row-template="items"><?php self::item_row( '__i__', array(), $cats ); ?></template>
					<button type="button" class="wic-btn wic-btn--small" data-wic-add-row="items"><?php esc_html_e( 'Add an item', 'wic-tp' ); ?></button>
				</div>

				<div data-qshow="match">
					<p class="wic-label"><?php esc_html_e( 'Pairs', 'wic-tp' ); ?></p>
					<div data-wic-rows="pairs">
						<?php
						$pairs = 'match' === $qtype && isset( $q['pairs'] ) ? (array) $q['pairs'] : array();
						while ( count( $pairs ) < 3 ) {
							$pairs[] = array();
						}
						foreach ( $pairs as $i => $p ) {
							self::pair_row( $i, $p );
						}
						?>
					</div>
					<template data-wic-row-template="pairs"><?php self::pair_row( '__i__', array() ); ?></template>
					<button type="button" class="wic-btn wic-btn--small" data-wic-add-row="pairs"><?php esc_html_e( 'Add a pair', 'wic-tp' ); ?></button>
				</div>

				<div class="wic-grid2">
					<div class="wic-field"><label for="wic-q-fc"><?php esc_html_e( 'Feedback when right', 'wic-tp' ); ?></label><textarea id="wic-q-fc" name="q[feedback_correct]" rows="2"><?php echo esc_textarea( isset( $q['feedback_correct'] ) ? $q['feedback_correct'] : '' ); ?></textarea></div>
					<div class="wic-field"><label for="wic-q-fi"><?php esc_html_e( 'Feedback when wrong', 'wic-tp' ); ?></label><textarea id="wic-q-fi" name="q[feedback_incorrect]" rows="2"><?php echo esc_textarea( isset( $q['feedback_incorrect'] ) ? $q['feedback_incorrect'] : '' ); ?></textarea></div>
				</div>
				<div class="wic-field"><label for="wic-q-hint"><?php esc_html_e( 'Hint (behind a "Show a hint" control)', 'wic-tp' ); ?></label><input id="wic-q-hint" name="q[hint]" value="<?php echo esc_attr( isset( $q['hint'] ) ? $q['hint'] : '' ); ?>"></div>
			</fieldset>

			<p><button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Save slide', 'wic-tp' ); ?></button></p>
		</form>
		<?php
	}

	private static function layer_row( $i, $l ) {
		?>
		<div class="wic-row" data-wic-row>
			<div class="wic-field"><label for="wic-l-<?php echo esc_attr( $i ); ?>-l"><?php esc_html_e( 'Button label', 'wic-tp' ); ?></label><input id="wic-l-<?php echo esc_attr( $i ); ?>-l" name="layers[<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( isset( $l['label'] ) ? $l['label'] : '' ); ?>"></div>
			<div class="wic-field wic-row__grow"><label for="wic-l-<?php echo esc_attr( $i ); ?>-c"><?php esc_html_e( 'What it reveals', 'wic-tp' ); ?></label><textarea id="wic-l-<?php echo esc_attr( $i ); ?>-c" name="layers[<?php echo esc_attr( $i ); ?>][content]" rows="2"><?php echo esc_textarea( isset( $l['content'] ) ? $l['content'] : '' ); ?></textarea></div>
		</div>
		<?php
	}

	private static function option_row( $i, $o ) {
		?>
		<div class="wic-row" data-wic-row>
			<div class="wic-field wic-row__grow"><label for="wic-o-<?php echo esc_attr( $i ); ?>-t"><?php esc_html_e( 'Option', 'wic-tp' ); ?></label><input id="wic-o-<?php echo esc_attr( $i ); ?>-t" name="q[options][<?php echo esc_attr( $i ); ?>][text]" value="<?php echo esc_attr( isset( $o['text'] ) ? $o['text'] : '' ); ?>"></div>
			<div class="wic-field"><span class="wic-label"><?php esc_html_e( 'Correct?', 'wic-tp' ); ?></span><label><input type="checkbox" name="q[options][<?php echo esc_attr( $i ); ?>][correct]" value="1" <?php checked( ! empty( $o['correct'] ) ); ?>> <?php esc_html_e( 'Correct', 'wic-tp' ); ?></label></div>
			<div class="wic-field wic-row__grow"><label for="wic-o-<?php echo esc_attr( $i ); ?>-f"><?php esc_html_e( 'Feedback if chosen (optional)', 'wic-tp' ); ?></label><input id="wic-o-<?php echo esc_attr( $i ); ?>-f" name="q[options][<?php echo esc_attr( $i ); ?>][feedback]" value="<?php echo esc_attr( isset( $o['feedback'] ) ? $o['feedback'] : '' ); ?>"></div>
		</div>
		<?php
	}

	private static function item_row( $i, $it, $cats ) {
		$cur = isset( $it['category'] ) && isset( $cats[ (int) $it['category'] ] ) ? $cats[ (int) $it['category'] ] : '';
		?>
		<div class="wic-row" data-wic-row>
			<div class="wic-field wic-row__grow"><label for="wic-i-<?php echo esc_attr( $i ); ?>-t"><?php esc_html_e( 'Item', 'wic-tp' ); ?></label><input id="wic-i-<?php echo esc_attr( $i ); ?>-t" name="q[items][<?php echo esc_attr( $i ); ?>][text]" value="<?php echo esc_attr( isset( $it['text'] ) ? $it['text'] : '' ); ?>"></div>
			<div class="wic-field"><label for="wic-i-<?php echo esc_attr( $i ); ?>-c"><?php esc_html_e( 'Belongs in', 'wic-tp' ); ?></label><input id="wic-i-<?php echo esc_attr( $i ); ?>-c" name="q[items][<?php echo esc_attr( $i ); ?>][category]" value="<?php echo esc_attr( $cur ); ?>" list="wic-cat-list" data-wic-category-input></div>
		</div>
		<?php
		static $list_done = false;
		if ( ! $list_done && '__i__' !== $i ) {
			$list_done = true;
			echo '<datalist id="wic-cat-list" data-wic-category-list>';
			foreach ( $cats as $c ) {
				echo '<option value="' . esc_attr( $c ) . '">';
			}
			echo '</datalist>';
		}
	}

	private static function pair_row( $i, $p ) {
		?>
		<div class="wic-row" data-wic-row>
			<div class="wic-field wic-row__grow"><label for="wic-p-<?php echo esc_attr( $i ); ?>-l"><?php esc_html_e( 'Left', 'wic-tp' ); ?></label><input id="wic-p-<?php echo esc_attr( $i ); ?>-l" name="q[pairs][<?php echo esc_attr( $i ); ?>][left]" value="<?php echo esc_attr( isset( $p['left'] ) ? $p['left'] : '' ); ?>"></div>
			<div class="wic-field wic-row__grow"><label for="wic-p-<?php echo esc_attr( $i ); ?>-r"><?php esc_html_e( 'Matches', 'wic-tp' ); ?></label><input id="wic-p-<?php echo esc_attr( $i ); ?>-r" name="q[pairs][<?php echo esc_attr( $i ); ?>][right]" value="<?php echo esc_attr( isset( $p['right'] ) ? $p['right'] : '' ); ?>"></div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Import                                                             */
	/* ------------------------------------------------------------------ */

	public static function view_import( $uid ) {
		?>
		<h2 class="wic-h"><?php esc_html_e( 'Bring a course in', 'wic-tp' ); ?></h2>
		<?php self::show_report(); ?>
		<p class="wic-help"><?php esc_html_e( 'Imported courses always arrive as drafts, so nothing reaches learners until someone has checked and published them.', 'wic-tp' ); ?></p>
		<div class="wic-two">
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'Course package (JSON)', 'wic-tp' ); ?></h3>
				<p class="wic-help"><?php esc_html_e( 'A file made by "Export" on this or another WIC platform: every module, slide, question, layer and setting.', 'wic-tp' ); ?></p>
				<input type="hidden" name="action" value="wic_author_import">
				<input type="hidden" name="kind" value="json">
				<?php wp_nonce_field( 'wic_author_import' ); ?>
				<div class="wic-field"><label for="wic-imp-file"><?php esc_html_e( 'Package file', 'wic-tp' ); ?></label><input type="file" id="wic-imp-file" name="package" accept=".json,application/json"></div>
				<div class="wic-field"><label for="wic-imp-text"><?php esc_html_e( 'Or paste the package', 'wic-tp' ); ?></label><textarea id="wic-imp-text" name="package_text" rows="4" spellcheck="false"></textarea></div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Import package', 'wic-tp' ); ?></button>
			</form>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wic-form wic-panel">
				<h3 class="wic-h3"><?php esc_html_e( 'Course outline (CSV)', 'wic-tp' ); ?></h3>
				<p class="wic-help"><?php esc_html_e( 'For text an agency already has. Columns, in order: module, slide, body, script. A header row is optional. Rows with the same module title go in the same module.', 'wic-tp' ); ?></p>
				<input type="hidden" name="action" value="wic_author_import">
				<input type="hidden" name="kind" value="csv">
				<?php wp_nonce_field( 'wic_author_import' ); ?>
				<div class="wic-field"><label for="wic-csv-title"><?php esc_html_e( 'Course title', 'wic-tp' ); ?></label><input id="wic-csv-title" name="title" required></div>
				<div class="wic-field"><label for="wic-csv-file"><?php esc_html_e( 'CSV file', 'wic-tp' ); ?></label><input type="file" id="wic-csv-file" name="outline" accept=".csv,text/csv" required></div>
				<button type="submit" class="wic-btn wic-btn--primary"><?php esc_html_e( 'Import outline', 'wic-tp' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle_new_course() {
		check_admin_referer( 'wic_author_new_course' );
		if ( ! current_user_can( 'wic_manage_content' ) ) {
			self::deny();
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( ! $title ) {
			WIC_Portal::back( 'author', 'error' );
		}
		$id = wp_insert_post(
			array(
				'post_type'   => 'wic_course',
				'post_status' => 'draft',
				'post_title'  => $title,
			)
		);
		if ( ! $id || is_wp_error( $id ) ) {
			WIC_Portal::back( 'author', 'error' );
		}
		update_post_meta( $id, '_wic_version', 1 );
		update_post_meta( $id, '_wic_nav_mode', 'free' );
		wic_audit( 'course_create', 'course', $id );
		WIC_Portal::back( 'author_course', 'author_created', array( 'course' => $id ) );
	}

	public static function handle_course_save() {
		$id = self::post_id( 'course' );
		check_admin_referer( 'wic_author_course_save_' . $id );
		if ( ! self::can_edit( $id ) || 'wic_course' !== get_post_type( $id ) ) {
			self::deny();
		}
		// Freeze the outgoing version before anything about this save lands.
		if ( ! empty( $_POST['bump_version'] ) ) {
			WIC_Content::bump_version( $id );
		}
		$text = function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		};
		$int  = function ( $k ) {
			return isset( $_POST[ $k ] ) && '' !== $_POST[ $k ] ? (string) absint( $_POST[ $k ] ) : ''; // phpcs:ignore
		};
		wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => wp_slash( $text( 'title' ) ),
				'post_content' => isset( $_POST['content'] ) ? wp_slash( wp_kses_post( wp_unslash( $_POST['content'] ) ) ) : '',
			)
		);
		$nav    = array_keys( apply_filters( 'wic_nav_modes', array( 'free' => '', 'linear' => '' ) ) );
		$groups = isset( $_POST['groups'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $_POST['groups'] ), array( 'staff', 'intern' ) ) ) : array();
		update_post_meta( $id, '_wic_required', empty( $_POST['required'] ) ? '' : '1' );
		update_post_meta( $id, '_wic_groups', $groups );
		update_post_meta( $id, '_wic_due_days', $int( 'due_days' ) );
		update_post_meta( $id, '_wic_pass_mark', '' === $int( 'pass_mark' ) ? '' : (string) min( 100, (int) $int( 'pass_mark' ) ) );
		update_post_meta( $id, '_wic_nav_mode', in_array( $text( 'nav_mode' ), $nav, true ) ? $text( 'nav_mode' ) : 'free' );
		update_post_meta( $id, '_wic_auto_advance', empty( $_POST['auto_advance'] ) ? '' : '1' );
		update_post_meta( $id, '_wic_credit_type', $text( 'credit_type' ) );
		update_post_meta( $id, '_wic_credit_hours', isset( $_POST['credit_hours'] ) && '' !== $_POST['credit_hours'] ? (string) (float) $_POST['credit_hours'] : '' );
		update_post_meta( $id, '_wic_validity_months', $int( 'validity_months' ) );
		update_post_meta( $id, '_wic_owner', $text( 'owner' ) );
		wic_audit( 'course_edit', 'course', $id );
		WIC_Portal::back( 'author_course', empty( $_POST['bump_version'] ) ? 'author_saved' : 'author_versioned', array( 'course' => $id ) );
	}

	public static function handle_course_op() {
		$id = self::post_id( 'course' );
		check_admin_referer( 'wic_author_course_op_' . $id );
		$op = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		if ( ! current_user_can( 'wic_manage_content' ) || 'wic_course' !== get_post_type( $id ) ) {
			self::deny();
		}
		$uid    = get_current_user_id();
		$agency = wic_user_agency_id( $uid );
		if ( ! WIC_Agencies::course_visible( $id, $agency ) ) {
			self::deny();
		}

		// Actions that read a course: allowed on anything the person can see.
		if ( 'export' === $op ) {
			$pkg = WIC_Course_Package::export( $id );
			wic_audit( 'course_export', 'course', $id );
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( sanitize_title( get_the_title( $id ) ) . '-v' . WIC_Content::course_version( $id ) . '.json' ) . '"' );
			echo wp_json_encode( $pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			exit;
		}
		if ( 'copy' === $op ) {
			$target = $agency;
			if ( ! $agency && isset( $_POST['agency'] ) ) {
				$target = absint( $_POST['agency'] ); // Platform administrators can copy into another agency.
			}
			$res = WIC_Course_Package::copy( $id, $target );
			if ( is_wp_error( $res ) ) {
				self::report( array( $res->get_error_message() ) );
				WIC_Portal::back( 'author', 'error' );
			}
			self::report( $res['warnings'] );
			WIC_Portal::back( $target === $agency ? 'author_course' : 'author', 'author_copied', $target === $agency ? array( 'course' => $res['course_id'] ) : array() );
		}
		if ( 'fork' === $op ) {
			if ( ! $agency || WIC_Agencies::course_agency( $id ) ) {
				self::deny();
			}
			$res = WIC_Course_Package::fork( $id, $agency );
			if ( is_wp_error( $res ) ) {
				self::report( array( $res->get_error_message() ) );
				WIC_Portal::back( 'author', 'error' );
			}
			self::report( $res['warnings'] );
			WIC_Portal::back( 'author_course', 'author_forked', array( 'course' => $res['course_id'] ) );
		}

		// Everything else changes the course itself.
		if ( ! self::can_edit( $id ) ) {
			WIC_Portal::back( 'author', 'err_author_denied' );
		}
		$status = get_post_status( $id );
		switch ( $op ) {
			case 'publish':
			case 'draft':
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => $op,
					)
				);
				wic_audit( 'course_' . $op, 'course', $id );
				WIC_Portal::back( 'author_course', 'publish' === $op ? 'author_published' : 'author_drafted', array( 'course' => $id ) );
				break;
			case 'retire':
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'wic_retired',
					)
				);
				wic_audit( 'course_retire', 'course', $id, array( 'from' => $status ) );
				WIC_Portal::back( 'author', 'author_retired' );
				break;
			case 'unretire':
				wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'draft',
					)
				);
				wic_audit( 'course_unretire', 'course', $id );
				WIC_Portal::back( 'author', 'author_unretired' );
				break;
			case 'share':
			case 'unshare':
				if ( ! $agency || ! (int) wic_setting( 'decision_share_courses' ) ) {
					self::deny();
				}
				update_post_meta( $id, '_wic_shared', 'share' === $op ? '1' : '' );
				wic_audit( 'course_' . $op, 'course', $id );
				WIC_Portal::back( 'author', 'author_shared' );
				break;
			case 'take_update':
				$n = WIC_Course_Package::take_update( $id );
				self::report( array( sprintf( _n( '%d slide updated.', '%d slides updated.', $n, 'wic-tp' ), $n ) ) );
				WIC_Portal::back( 'author_course', 'author_updated', array( 'course' => $id ) );
				break;
		}
		WIC_Portal::back( 'author', 'error' );
	}

	/** Neat 10, 20, 30 ordering, then swap with the neighbour. */
	private static function move( $post_id, $type, $dir ) {
		$post     = get_post( $post_id );
		$siblings = self::kids( $post->post_parent, $type );
		$ids      = wp_list_pluck( $siblings, 'ID' );
		$pos      = array_search( $post_id, $ids, true );
		$swap     = 'up' === $dir ? $pos - 1 : $pos + 1;
		if ( false === $pos || $swap < 0 || $swap >= count( $ids ) ) {
			return;
		}
		$tmp          = $ids[ $pos ];
		$ids[ $pos ]  = $ids[ $swap ];
		$ids[ $swap ] = $tmp;
		foreach ( $ids as $i => $id ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'menu_order' => ( $i + 1 ) * 10,
				)
			);
		}
	}

	public static function handle_module_save() {
		$course = self::post_id( 'course' );
		check_admin_referer( 'wic_author_module_save_' . $course );
		if ( ! self::can_edit( $course ) ) {
			self::deny();
		}
		$mid   = self::post_id( 'module' );
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( $mid ) {
			if ( ! self::can_edit( $mid ) || (int) get_post_field( 'post_parent', $mid ) !== $course ) {
				self::deny();
			}
			wp_update_post(
				array(
					'ID'         => $mid,
					'post_title' => $title,
				)
			);
		} else {
			$last = self::kids( $course, 'wic_module' );
			$mid  = wp_insert_post(
				array(
					'post_type'   => 'wic_module',
					'post_status' => 'publish',
					'post_title'  => $title ? $title : __( 'New module', 'wic-tp' ),
					'post_parent' => $course,
					'menu_order'  => $last ? (int) end( $last )->menu_order + 10 : 10,
				)
			);
		}
		update_post_meta( $mid, '_wic_assessment', empty( $_POST['assessment'] ) ? '' : '1' );
		update_post_meta( $mid, '_wic_pass_mark', isset( $_POST['pass_mark'] ) && '' !== $_POST['pass_mark'] ? (string) min( 100, absint( $_POST['pass_mark'] ) ) : '' );
		wic_audit( 'module_edit', 'module', $mid );
		WIC_Portal::back( 'author_course', 'author_saved', array( 'course' => $course ) );
	}

	public static function handle_module_op() {
		$mid = self::post_id( 'module' );
		check_admin_referer( 'wic_author_module_op_' . $mid );
		if ( ! self::can_edit( $mid ) || 'wic_module' !== get_post_type( $mid ) ) {
			self::deny();
		}
		$course = (int) get_post_field( 'post_parent', $mid );
		$op     = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		$msg    = 'author_saved';
		if ( in_array( $op, array( 'up', 'down' ), true ) ) {
			self::move( $mid, 'wic_module', $op );
			$msg = 'author_moved';
		} elseif ( in_array( $op, array( 'publish', 'draft' ), true ) ) {
			wp_update_post(
				array(
					'ID'          => $mid,
					'post_status' => $op,
				)
			);
		} elseif ( 'trash' === $op ) {
			wp_trash_post( $mid );
			wic_audit( 'module_trash', 'module', $mid );
			$msg = 'author_removed';
		}
		WIC_Portal::back( 'author_course', $msg, array( 'course' => $course ) );
	}

	public static function handle_slide_new() {
		$mid = self::post_id( 'module' );
		check_admin_referer( 'wic_author_slide_new_' . $mid );
		if ( ! self::can_edit( $mid ) || 'wic_module' !== get_post_type( $mid ) ) {
			self::deny();
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$sid   = WIC_Slide_Templates::new_slide( $mid, self::post_id( 'template' ), $title );
		if ( ! $sid ) {
			WIC_Portal::back( 'author_course', 'error', array( 'course' => (int) get_post_field( 'post_parent', $mid ) ) );
		}
		wic_audit( 'slide_create', 'slide', $sid );
		WIC_Portal::back( 'author_slide', 'author_created', array( 'slide' => $sid ) );
	}

	public static function handle_slide_op() {
		$sid = self::post_id( 'slide' );
		check_admin_referer( 'wic_author_slide_op_' . $sid );
		if ( ! self::can_edit( $sid ) || 'wic_slide' !== get_post_type( $sid ) ) {
			self::deny();
		}
		$course = WIC_Agencies::course_of( $sid );
		$op     = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		$msg    = 'author_saved';
		if ( in_array( $op, array( 'up', 'down' ), true ) ) {
			self::move( $sid, 'wic_slide', $op );
			$msg = 'author_moved';
		} elseif ( 'trash' === $op ) {
			wp_trash_post( $sid );
			wic_audit( 'slide_trash', 'slide', $sid );
			$msg = 'author_removed';
		}
		WIC_Portal::back( 'author_course', $msg, array( 'course' => $course ) );
	}

	/**
	 * The question builder's fields → the `_wic_question` JSON the player reads.
	 * Keys the builder does not manage (added by other modules) are kept.
	 *
	 * @return array array( question array, problems[] )
	 */
	public static function build_question( $in, $existing ) {
		$q        = is_array( $existing ) ? $existing : array();
		$problems = array();
		$type     = isset( $in['type'] ) && in_array( $in['type'], WIC_Content::QUESTION_TYPES, true ) ? $in['type'] : 'mc';
		$txt      = function ( $v ) {
			return trim( wp_kses_post( wp_unslash( (string) $v ) ) );
		};
		foreach ( array( 'options', 'answer', 'categories', 'items', 'pairs' ) as $k ) {
			unset( $q[ $k ] );
		}
		$q['type']     = $type;
		$q['prompt']   = $txt( $in['prompt'] ?? '' );
		$q['points']   = isset( $in['points'] ) && '' !== $in['points'] ? absint( $in['points'] ) : 10;
		$q['attempts'] = max( 1, min( 10, isset( $in['attempts'] ) ? absint( $in['attempts'] ) : 2 ) );
		foreach ( array( 'hint', 'feedback_correct', 'feedback_incorrect' ) as $k ) {
			$v = $txt( $in[ $k ] ?? '' );
			if ( '' === $v ) {
				unset( $q[ $k ] );
			} else {
				$q[ $k ] = $v;
			}
		}
		$explain = absint( $in['explain_slide'] ?? 0 );
		if ( $explain ) {
			$q['explain_slide'] = $explain;
		} else {
			unset( $q['explain_slide'] );
		}
		if ( '' === $q['prompt'] ) {
			$problems[] = __( 'The question has no wording.', 'wic-tp' );
		}

		if ( in_array( $type, array( 'mc', 'mr' ), true ) ) {
			$opts = array();
			foreach ( (array) ( $in['options'] ?? array() ) as $o ) {
				$t = $txt( $o['text'] ?? '' );
				if ( '' === $t ) {
					continue;
				}
				$row = array( 'text' => $t );
				if ( ! empty( $o['correct'] ) ) {
					$row['correct'] = true;
				}
				$f = $txt( $o['feedback'] ?? '' );
				if ( '' !== $f ) {
					$row['feedback'] = $f;
				}
				$opts[] = $row;
			}
			$right        = count( array_filter( wp_list_pluck( $opts, 'correct' ) ) );
			$q['options'] = $opts;
			if ( count( $opts ) < 2 ) {
				$problems[] = __( 'A choice question needs at least two options.', 'wic-tp' );
			}
			if ( 'mc' === $type && 1 !== $right ) {
				$problems[] = __( 'A multiple-choice question needs exactly one correct option (use multiple response for more).', 'wic-tp' );
			}
			if ( 'mr' === $type && $right < 1 ) {
				$problems[] = __( 'Tick at least one correct option.', 'wic-tp' );
			}
		} elseif ( 'tf' === $type ) {
			$q['answer'] = ! empty( $in['answer'] );
		} elseif ( 'sort' === $type ) {
			$cats  = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', wp_unslash( (string) ( $in['categories'] ?? '' ) ) ) ), 'strlen' ) );
			$cats  = array_map( 'sanitize_text_field', $cats );
			$lower = array_map( 'wic_fold', $cats );
			$items = array();
			foreach ( (array) ( $in['items'] ?? array() ) as $it ) {
				$t = sanitize_text_field( wp_unslash( (string) ( $it['text'] ?? '' ) ) );
				if ( '' === $t ) {
					continue;
				}
				$c   = trim( wp_unslash( (string) ( $it['category'] ?? '' ) ) );
				$idx = array_search( wic_fold( $c ), $lower, true );
				if ( false === $idx && ctype_digit( $c ) && isset( $cats[ (int) $c ] ) ) {
					$idx = (int) $c;
				}
				if ( false === $idx ) {
					$problems[] = sprintf( __( '"%s" is not in one of the categories.', 'wic-tp' ), $t );
					$idx        = 0;
				}
				$items[] = array(
					'text'     => $t,
					'category' => (int) $idx,
				);
			}
			$q['categories'] = $cats;
			$q['items']      = $items;
			if ( count( $cats ) < 2 || ! $items ) {
				$problems[] = __( 'A sorting question needs at least two categories and one item.', 'wic-tp' );
			}
		} elseif ( 'match' === $type ) {
			$pairs = array();
			foreach ( (array) ( $in['pairs'] ?? array() ) as $p ) {
				$l = sanitize_text_field( wp_unslash( (string) ( $p['left'] ?? '' ) ) );
				$r = sanitize_text_field( wp_unslash( (string) ( $p['right'] ?? '' ) ) );
				if ( '' === $l && '' === $r ) {
					continue;
				}
				if ( '' === $l || '' === $r ) {
					$problems[] = __( 'Every pair needs both sides.', 'wic-tp' );
					continue;
				}
				$pairs[] = array(
					'left'  => $l,
					'right' => $r,
				);
			}
			$q['pairs'] = $pairs;
			if ( count( $pairs ) < 2 ) {
				$problems[] = __( 'A matching question needs at least two pairs.', 'wic-tp' );
			}
		}
		return array( $q, $problems );
	}

	public static function handle_slide_save() {
		$sid = self::post_id( 'slide' );
		check_admin_referer( 'wic_author_slide_save_' . $sid );
		if ( ! self::can_edit( $sid ) || 'wic_slide' !== get_post_type( $sid ) ) {
			self::deny();
		}
		$course = WIC_Agencies::course_of( $sid );
		$module = self::post_id( 'module' );
		if ( ! $module || 'wic_module' !== get_post_type( $module ) || WIC_Agencies::course_of( $module ) !== $course ) {
			$module = (int) get_post_field( 'post_parent', $sid );
		}
		$layout = isset( $_POST['layout'] ) ? sanitize_key( $_POST['layout'] ) : 'text';
		$layout = in_array( $layout, WIC_Content::layouts(), true ) ? $layout : 'text';
		$status = isset( $_POST['status'] ) && 'publish' === $_POST['status'] ? 'publish' : 'draft';
		$img    = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
		$alt    = isset( $_POST['image_alt'] ) ? sanitize_text_field( wp_unslash( $_POST['image_alt'] ) ) : '';
		$notes  = array();

		// Layers: repeatable rows; an empty label drops the row.
		$layers = array();
		foreach ( isset( $_POST['layers'] ) ? (array) $_POST['layers'] : array() as $l ) { // phpcs:ignore -- sanitised per field.
			$label = sanitize_text_field( wp_unslash( (string) ( $l['label'] ?? '' ) ) );
			if ( '' === $label ) {
				continue;
			}
			$layers[] = array(
				'label'   => $label,
				'content' => wp_kses_post( wp_unslash( (string) ( $l['content'] ?? '' ) ) ),
			);
		}

		$existing = json_decode( (string) get_post_meta( $sid, '_wic_question', true ), true );
		$qjson    = (string) get_post_meta( $sid, '_wic_question', true );
		if ( 'question' === $layout ) {
			list( $q, $problems ) = self::build_question( isset( $_POST['q'] ) ? (array) $_POST['q'] : array(), $existing ); // phpcs:ignore -- sanitised in build_question().
			$qjson                = wp_json_encode( $q );
			if ( $problems && 'publish' === $status ) {
				$status = 'draft';
				$notes  = array_merge( array( __( 'Kept as a draft until the question is complete:', 'wic-tp' ) ), $problems );
			} elseif ( $problems ) {
				$notes = $problems;
			}
		}
		if ( $img && ! wic_alt_is_plausible( $alt ) && 'publish' === $status ) {
			$status  = 'draft';
			$notes[] = __( 'Kept as a draft: the image needs meaningful alt text (not empty and not a filename).', 'wic-tp' );
		}

		wp_update_post(
			array(
				'ID'           => $sid,
				'post_title'   => wp_slash( isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : get_the_title( $sid ) ),
				'post_content' => wp_slash( isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '' ),
				'post_status'  => $status,
				'post_parent'  => $module,
			)
		);
		if ( 'publish' === $status && 'draft' === get_post_status( $sid ) ) {
			$notes[] = __( 'Kept as a draft: an image in the slide text needs meaningful alt text.', 'wic-tp' );
			$status  = 'draft';
		}
		update_post_meta( $sid, '_wic_layout', $layout );
		update_post_meta( $sid, '_wic_image_url', $img );
		update_post_meta( $sid, '_wic_image_alt', $alt );
		update_post_meta( $sid, '_wic_audio_url', isset( $_POST['audio_url'] ) ? esc_url_raw( wp_unslash( $_POST['audio_url'] ) ) : '' );
		update_post_meta( $sid, '_wic_seconds', isset( $_POST['seconds'] ) && '' !== $_POST['seconds'] ? (string) absint( $_POST['seconds'] ) : '' );
		update_post_meta( $sid, '_wic_script', isset( $_POST['script'] ) ? sanitize_textarea_field( wp_unslash( $_POST['script'] ) ) : '' );
		update_post_meta( $sid, '_wic_layers', $layers ? wp_slash( wp_json_encode( $layers ) ) : '' );
		update_post_meta( $sid, '_wic_question', wp_slash( $qjson ) );
		/** Other modules (translations, captions) save their own slide fields here. */
		do_action( 'wic_author_slide_saved', $sid, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		wic_audit( 'slide_edit', 'slide', $sid, array( 'status' => $status ) );
		self::report( $notes );
		WIC_Portal::back( 'author_slide', $notes && 'draft' === $status && isset( $_POST['status'] ) && 'publish' === $_POST['status'] ? 'author_slide_draft' : 'author_saved', array( 'slide' => $sid ) );
	}

	public static function handle_import() {
		check_admin_referer( 'wic_author_import' );
		if ( ! current_user_can( 'wic_manage_content' ) ) {
			self::deny();
		}
		$agency = wic_user_agency_id( get_current_user_id() );
		$kind   = isset( $_POST['kind'] ) && 'csv' === $_POST['kind'] ? 'csv' : 'json';
		if ( 'csv' === $kind ) {
			$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
			$file  = isset( $_FILES['outline']['tmp_name'] ) ? $_FILES['outline']['tmp_name'] : ''; // phpcs:ignore
			if ( ! $title || ! $file || ! is_uploaded_file( $file ) ) {
				self::report( array( __( 'Choose a CSV file and give the course a title.', 'wic-tp' ) ) );
				WIC_Portal::back( 'author_import', 'err_author_import' );
			}
			$res = WIC_Course_Package::import_csv( $file, $title, $agency );
		} else {
			$raw  = '';
			$file = isset( $_FILES['package']['tmp_name'] ) ? $_FILES['package']['tmp_name'] : ''; // phpcs:ignore
			if ( $file && is_uploaded_file( $file ) ) {
				$raw = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} elseif ( ! empty( $_POST['package_text'] ) ) {
				$raw = wp_unslash( $_POST['package_text'] ); // phpcs:ignore -- JSON, validated by the package reader.
			}
			$pkg = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $raw ), true );
			if ( null === $pkg ) {
				self::report( array( __( 'That is not valid JSON.', 'wic-tp' ) ) );
				WIC_Portal::back( 'author_import', 'err_author_import' );
			}
			$res = WIC_Course_Package::import( $pkg, array( 'agency' => $agency ) );
		}
		if ( is_wp_error( $res ) ) {
			self::report( array( $res->get_error_message() ) );
			WIC_Portal::back( 'author_import', 'err_author_import' );
		}
		self::report( $res['warnings'] );
		WIC_Portal::back( 'author_course', 'author_imported', array( 'course' => $res['course_id'] ) );
	}

	/* ------------------------------------------------------------------ */
	/* WP-admin: where a course sits                                      */
	/* ------------------------------------------------------------------ */

	public static function admin_box() {
		add_meta_box( 'wic_course_library', __( 'Library and versions', 'wic-tp' ), array( __CLASS__, 'admin_box_render' ), 'wic_course', 'side', 'default' );
	}

	public static function admin_box_render( $post ) {
		echo '<p><strong>' . esc_html( self::library_label( $post->ID ) ) . '</strong></p>';
		$u = get_post_meta( $post->ID, '_wic_forked_from', true ) ? WIC_Course_Package::pending_update( $post->ID ) : null;
		if ( $u ) {
			echo '<p>' . esc_html( sprintf( __( 'Shared version %d is available.', 'wic-tp' ), $u['version'] ) ) . '</p>';
		}
		foreach ( WIC_Course_Versions::history( $post->ID ) as $h ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Version %1$d frozen %2$s', 'wic-tp' ), $h->version, wic_format_date( $h->created_at ) ) ) . '</p>';
		}
		if ( $post->ID && 'auto-draft' !== $post->post_status ) {
			echo '<p><a class="button" href="' . esc_url( wic_portal_url( 'author_course', array( 'course' => $post->ID ) ) ) . '">' . esc_html__( 'Edit in the portal', 'wic-tp' ) . '</a></p>';
		}
	}
}

add_action( 'wic_init', array( 'WIC_Authoring', 'init' ) );
