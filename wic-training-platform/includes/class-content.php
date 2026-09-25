<?php
/**
 * Course → module → slide, built on WordPress content types so ordering,
 * revisions, preview, scheduling and permissions come free.
 *
 * Slides are addressed by post ID (a stable identifier), never by position.
 * Layers are named states inside a slide, not extra slides.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Content {

	const LAYOUTS        = array( 'text', 'image', 'callout', 'question' );
	const QUESTION_TYPES = array( 'mc', 'mr', 'tf', 'sort', 'match' );

	private static $tree_cache = array();

	/** Slide layouts, extensible by modules (video, signing step) through `wic_slide_layouts`. */
	public static function layouts() {
		return array_values( array_unique( (array) apply_filters( 'wic_slide_layouts', self::LAYOUTS ) ) );
	}

	/** Forget cached course trees, e.g. after an editor reorders slides in the same request. */
	public static function flush_tree_cache( $course_id = 0 ) {
		if ( $course_id ) {
			unset( self::$tree_cache[ (int) $course_id ] );
		} else {
			self::$tree_cache = array();
		}
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_types' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'filter_post_data' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'manage_wic_slide_posts_columns', array( __CLASS__, 'slide_columns' ) );
		add_action( 'manage_wic_slide_posts_custom_column', array( __CLASS__, 'slide_column_value' ), 10, 2 );
		add_filter( 'manage_wic_module_posts_columns', array( __CLASS__, 'slide_columns' ) );
		add_action( 'manage_wic_module_posts_custom_column', array( __CLASS__, 'slide_column_value' ), 10, 2 );
	}

	public static function register_types() {
		$common = array(
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'wic-platform',
			'show_in_rest'    => false,
			'capability_type' => array( 'wic_item', 'wic_items' ),
			'map_meta_cap'    => true,
			'supports'        => array( 'title', 'editor', 'revisions', 'page-attributes' ),
		);
		register_post_type(
			'wic_course',
			$common + array(
				'labels' => array(
					'name'          => __( 'Courses', 'wic-tp' ),
					'singular_name' => __( 'Course', 'wic-tp' ),
					'add_new_item'  => __( 'Add course', 'wic-tp' ),
					'edit_item'     => __( 'Edit course', 'wic-tp' ),
				),
			)
		);
		register_post_type(
			'wic_module',
			$common + array(
				'labels' => array(
					'name'          => __( 'Modules', 'wic-tp' ),
					'singular_name' => __( 'Module', 'wic-tp' ),
					'add_new_item'  => __( 'Add module', 'wic-tp' ),
					'edit_item'     => __( 'Edit module', 'wic-tp' ),
				),
			)
		);
		register_post_type(
			'wic_slide',
			$common + array(
				'labels' => array(
					'name'          => __( 'Slides', 'wic-tp' ),
					'singular_name' => __( 'Slide', 'wic-tp' ),
					'add_new_item'  => __( 'Add slide', 'wic-tp' ),
					'edit_item'     => __( 'Edit slide', 'wic-tp' ),
				),
			)
		);
		// Retired: out of the catalogue and every picker, but completions and certificates stay readable.
		register_post_status(
			'wic_retired',
			array(
				'label'                     => _x( 'Retired', 'course status', 'wic-tp' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of courses */
				'label_count'               => _n_noop( 'Retired <span class="count">(%s)</span>', 'Retired <span class="count">(%s)</span>', 'wic-tp' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Reading the structure                                              */
	/* ------------------------------------------------------------------ */

	public static function children( $parent_id, $type ) {
		return apply_filters( 'wic_children', get_posts(
			array(
				'post_type'      => $type,
				'post_parent'    => (int) $parent_id,
				'post_status'    => 'publish',
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'posts_per_page' => -1,
			)
		), $parent_id, $type );
	}

	/** Never assume a fixed module or slide count; derive everything from what exists. */
	public static function tree( $course_id ) {
		$course_id = (int) $course_id;
		if ( isset( self::$tree_cache[ $course_id ] ) ) {
			return self::$tree_cache[ $course_id ];
		}
		$modules = array();
		foreach ( self::children( $course_id, 'wic_module' ) as $m ) {
			$slides = self::children( $m->ID, 'wic_slide' );
			$modules[] = array(
				'id'         => $m->ID,
				'title'      => $m->post_title,
				'assessment' => (bool) get_post_meta( $m->ID, '_wic_assessment', true ),
				'pass_mark'  => self::module_pass_mark( $m->ID, $course_id ),
				'slides'     => array_map( 'intval', wp_list_pluck( $slides, 'ID' ) ),
			);
		}
		/** Version pinning and question banks can reshape the tree a learner sees. */
		$modules                        = apply_filters( 'wic_course_tree', $modules, $course_id );
		self::$tree_cache[ $course_id ] = $modules;
		return $modules;
	}

	public static function flat_slides( $course_id ) {
		$flat = array();
		foreach ( self::tree( $course_id ) as $m ) {
			foreach ( $m['slides'] as $sid ) {
				$flat[ $sid ] = $m['id'];
			}
		}
		return $flat; // slide_id => module_id, in order.
	}

	public static function module_pass_mark( $module_id, $course_id ) {
		$m = get_post_meta( $module_id, '_wic_pass_mark', true );
		if ( '' !== $m ) {
			return (int) $m;
		}
		$c = get_post_meta( $course_id, '_wic_pass_mark', true );
		return '' !== $c ? (int) $c : (int) wic_setting( 'pass_mark' );
	}

	public static function slide_seconds( $slide_id ) {
		$s = (int) get_post_meta( $slide_id, '_wic_seconds', true );
		return $s > 0 ? $s : 45; // Per-slide reading allowance when no narration length is recorded.
	}

	/** Auto duration: sum of narration lengths plus reading allowance, never typed by hand. */
	public static function course_seconds( $course_id ) {
		$total = 0;
		foreach ( array_keys( self::flat_slides( $course_id ) ) as $sid ) {
			$total += self::slide_seconds( $sid );
		}
		return $total;
	}

	public static function course_hours( $course_id ) {
		$set = get_post_meta( $course_id, '_wic_credit_hours', true );
		if ( '' !== $set && (float) $set > 0 ) {
			return (float) $set;
		}
		return round( self::course_seconds( $course_id ) / 3600 * 4 ) / 4; // Nearest quarter hour.
	}

	public static function course_version( $course_id ) {
		$v = (int) get_post_meta( $course_id, '_wic_version', true );
		return $v > 0 ? $v : 1;
	}

	/**
	 * Start a new version. The content as it stands is frozen as the outgoing version first
	 * (`wic_course_version_closing`), so learners mid-course keep what they started on.
	 * Start the new version before editing, not after.
	 */
	public static function bump_version( $course_id ) {
		$old = self::course_version( $course_id );
		do_action( 'wic_course_version_closing', $course_id, $old );
		$v = $old + 1;
		update_post_meta( $course_id, '_wic_version', $v );
		wic_audit( 'course_version', 'course', $course_id, array( 'version' => $v ) );
		do_action( 'wic_course_version_saved', $course_id, $v );
		return $v;
	}

	public static function question( $slide_id ) {
		if ( 'question' !== get_post_meta( $slide_id, '_wic_layout', true ) ) {
			return null;
		}
		$q = json_decode( (string) get_post_meta( $slide_id, '_wic_question', true ), true );
		if ( ! is_array( $q ) || empty( $q['type'] ) || ! in_array( $q['type'], self::QUESTION_TYPES, true ) ) {
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
		/** Question banks, translations and shuffling hook in here. */
		return apply_filters( 'wic_question', $q, $slide_id );
	}

	/** What the browser is allowed to see: never the correct answers or the feedback. */
	public static function public_question( $q ) {
		if ( ! $q ) {
			return null;
		}
		$out = array(
			'type'     => $q['type'],
			'prompt'   => isset( $q['prompt'] ) ? wp_kses_post( $q['prompt'] ) : '',
			'hint'     => isset( $q['hint'] ) ? wp_kses_post( $q['hint'] ) : '',
			'points'   => $q['points'],
			'attempts' => $q['attempts'],
		);
		if ( in_array( $q['type'], array( 'mc', 'mr', 'tf' ), true ) ) {
			$out['options'] = array_map(
				function ( $o ) {
					return array( 'text' => wp_kses_post( isset( $o['text'] ) ? $o['text'] : '' ) );
				},
				isset( $q['options'] ) ? $q['options'] : array()
			);
		} elseif ( 'sort' === $q['type'] ) {
			$out['categories'] = array_map( 'wp_strip_all_tags', isset( $q['categories'] ) ? $q['categories'] : array() );
			$out['items']      = array_map(
				function ( $i ) {
					return wp_strip_all_tags( isset( $i['text'] ) ? $i['text'] : '' );
				},
				isset( $q['items'] ) ? $q['items'] : array()
			);
		} elseif ( 'match' === $q['type'] ) {
			$pairs         = isset( $q['pairs'] ) ? $q['pairs'] : array();
			$out['left']   = array_map(
				function ( $p ) {
					return wp_strip_all_tags( isset( $p['left'] ) ? $p['left'] : '' );
				},
				$pairs
			);
			$out['right']  = array_map(
				function ( $p ) {
					return wp_strip_all_tags( isset( $p['right'] ) ? $p['right'] : '' );
				},
				$pairs
			);
		}
		return $out;
	}

	public static function layers( $slide_id ) {
		$layers = json_decode( (string) get_post_meta( $slide_id, '_wic_layers', true ), true );
		if ( ! is_array( $layers ) ) {
			return array();
		}
		$out = array();
		foreach ( $layers as $i => $l ) {
			if ( empty( $l['label'] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => 'l' . $i,
				'label' => wp_strip_all_tags( $l['label'] ),
				'html'  => wp_kses_post( wpautop( isset( $l['content'] ) ? $l['content'] : '' ) ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Authoring screens                                                  */
	/* ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'wic_course_meta', __( 'Course settings', 'wic-tp' ), array( __CLASS__, 'course_box' ), 'wic_course', 'normal', 'high' );
		add_meta_box( 'wic_module_meta', __( 'Module settings', 'wic-tp' ), array( __CLASS__, 'module_box' ), 'wic_module', 'normal', 'high' );
		add_meta_box( 'wic_slide_meta', __( 'Slide settings', 'wic-tp' ), array( __CLASS__, 'slide_box' ), 'wic_slide', 'normal', 'high' );
	}

	private static function meta( $post_id, $key, $default = '' ) {
		$v = get_post_meta( $post_id, $key, true );
		return '' === $v ? $default : $v;
	}

	public static function course_box( $post ) {
		wp_nonce_field( 'wic_content_meta', 'wic_content_nonce' );
		$groups = (array) get_post_meta( $post->ID, '_wic_groups', true );
		$mins   = $post->ID ? round( self::course_seconds( $post->ID ) / 60 ) : 0;
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Required', 'wic-tp' ); ?></th>
				<td><label><input type="checkbox" name="wic_required" value="1" <?php checked( (bool) self::meta( $post->ID, '_wic_required' ) ); ?>> <?php esc_html_e( 'Required training (drives auto-assignment)', 'wic-tp' ); ?></label></td></tr>
			<tr><th><?php esc_html_e( 'Auto-assign to', 'wic-tp' ); ?></th>
				<td>
					<label><input type="checkbox" name="wic_groups[]" value="staff" <?php checked( in_array( 'staff', $groups, true ) ); ?>> <?php esc_html_e( 'Staff', 'wic-tp' ); ?></label>
					&nbsp; <label><input type="checkbox" name="wic_groups[]" value="intern" <?php checked( in_array( 'intern', $groups, true ) ); ?>> <?php esc_html_e( 'Interns', 'wic-tp' ); ?></label>
					<p class="description"><?php esc_html_e( 'Applied at approval and whenever a person changes group. Required courses only.', 'wic-tp' ); ?></p>
				</td></tr>
			<tr><th><label for="wic_due_days"><?php esc_html_e( 'Due within (days)', 'wic-tp' ); ?></label></th>
				<td><input type="number" min="0" name="wic_due_days" id="wic_due_days" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_due_days', '30' ) ); ?>"> <span class="description"><?php esc_html_e( 'Counted from the date it is assigned. 0 = no due date.', 'wic-tp' ); ?></span></td></tr>
			<tr><th><label for="wic_pass_mark"><?php esc_html_e( 'Default pass mark (%)', 'wic-tp' ); ?></label></th>
				<td><input type="number" min="0" max="100" name="wic_pass_mark" id="wic_pass_mark" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_pass_mark' ) ); ?>" placeholder="<?php echo esc_attr( wic_setting( 'pass_mark' ) ); ?>"> <span class="description"><?php esc_html_e( 'Modules can override this.', 'wic-tp' ); ?></span></td></tr>
			<tr><th><label for="wic_nav_mode"><?php esc_html_e( 'Navigation', 'wic-tp' ); ?></label></th>
				<td><select name="wic_nav_mode" id="wic_nav_mode">
					<option value="free" <?php selected( self::meta( $post->ID, '_wic_nav_mode', 'free' ), 'free' ); ?>><?php esc_html_e( 'Free — jump anywhere in the outline', 'wic-tp' ); ?></option>
					<option value="linear" <?php selected( self::meta( $post->ID, '_wic_nav_mode', 'free' ), 'linear' ); ?>><?php esc_html_e( 'Linear — cannot skip ahead of slides not yet seen', 'wic-tp' ); ?></option>
				</select></td></tr>
			<tr><th><?php esc_html_e( 'Auto-advance', 'wic-tp' ); ?></th>
				<td><label><input type="checkbox" name="wic_auto_advance" value="1" <?php checked( (bool) self::meta( $post->ID, '_wic_auto_advance' ) ); ?>> <?php esc_html_e( 'Move on when narration ends (learner can switch it off)', 'wic-tp' ); ?></label></td></tr>
			<tr><th><label for="wic_credit_type"><?php esc_html_e( 'Credit type', 'wic-tp' ); ?></label></th>
				<td><input type="text" class="regular-text" name="wic_credit_type" id="wic_credit_type" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_credit_type' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Continuing education', 'wic-tp' ); ?>"></td></tr>
			<tr><th><label for="wic_credit_hours"><?php esc_html_e( 'Credit hours', 'wic-tp' ); ?></label></th>
				<td><input type="number" step="0.25" min="0" name="wic_credit_hours" id="wic_credit_hours" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_credit_hours' ) ); ?>">
					<span class="description"><?php printf( esc_html__( 'Leave empty to use the calculated duration (currently about %d minutes).', 'wic-tp' ), (int) $mins ); ?></span></td></tr>
			<tr><th><label for="wic_validity_months"><?php esc_html_e( 'Recertify every (months)', 'wic-tp' ); ?></label></th>
				<td><input type="number" min="0" name="wic_validity_months" id="wic_validity_months" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_validity_months', '0' ) ); ?>"> <span class="description"><?php esc_html_e( '0 = does not expire.', 'wic-tp' ); ?></span></td></tr>
			<tr><th><label for="wic_owner"><?php esc_html_e( 'Content owner', 'wic-tp' ); ?></label></th>
				<td><input type="text" class="regular-text" name="wic_owner" id="wic_owner" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_owner' ) ); ?>"> <span class="description"><?php esc_html_e( 'Visible to authors only.', 'wic-tp' ); ?></span></td></tr>
			<tr><th><?php esc_html_e( 'Version', 'wic-tp' ); ?></th>
				<td><strong><?php echo (int) self::course_version( $post->ID ); ?></strong>
					&nbsp; <label><input type="checkbox" name="wic_bump_version" value="1"> <?php esc_html_e( 'Start a new version — learners already part-way through keep the current one. Do this before editing the slides.', 'wic-tp' ); ?></label></td></tr>
		</table>
		<?php if ( $post->ID && 'publish' === $post->post_status ) : ?>
			<p><a class="button" href="<?php echo esc_url( wic_page_url( 'learn', array( 'course' => $post->ID ) ) ); ?>" target="_blank"><?php esc_html_e( 'Preview as a learner', 'wic-tp' ); ?></a></p>
		<?php endif; ?>
		<?php
		$tree = $post->ID ? self::tree( $post->ID ) : array();
		if ( $tree ) {
			echo '<h4>' . esc_html__( 'Structure', 'wic-tp' ) . '</h4><ol>';
			foreach ( $tree as $m ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( $m['id'] ) ) . '">' . esc_html( get_the_title( $m['id'] ) ) . '</a> — ' . count( $m['slides'] ) . ' ' . esc_html__( 'slides', 'wic-tp' ) . ( $m['assessment'] ? ' · ' . esc_html__( 'assessment', 'wic-tp' ) : '' ) . '</li>';
			}
			echo '</ol>';
		}
	}

	private static function parent_select( $post, $type, $label ) {
		$options = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		echo '<p><label for="wic_parent"><strong>' . esc_html( $label ) . '</strong></label><br><select name="wic_parent" id="wic_parent" style="min-width:320px">';
		echo '<option value="0">' . esc_html__( '— Choose —', 'wic-tp' ) . '</option>';
		foreach ( $options as $o ) {
			$prefix = '';
			if ( 'wic_module' === $type && $o->post_parent ) {
				$prefix = get_the_title( $o->post_parent ) . ' › ';
			}
			echo '<option value="' . esc_attr( $o->ID ) . '" ' . selected( $post->post_parent, $o->ID, false ) . '>' . esc_html( $prefix . $o->post_title ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Order within the parent is set by "Order" in Page Attributes. Leave gaps (10, 20, 30) so inserting does not renumber everything.', 'wic-tp' ) . '</p>';
	}

	public static function module_box( $post ) {
		wp_nonce_field( 'wic_content_meta', 'wic_content_nonce' );
		self::parent_select( $post, 'wic_course', __( 'Course', 'wic-tp' ) );
		?>
		<p><label><input type="checkbox" name="wic_assessment" value="1" <?php checked( (bool) self::meta( $post->ID, '_wic_assessment' ) ); ?>> <?php esc_html_e( 'Graded assessment — must be passed for the course to complete', 'wic-tp' ); ?></label></p>
		<p><label for="wic_pass_mark"><?php esc_html_e( 'Pass mark (%)', 'wic-tp' ); ?></label>
			<input type="number" min="0" max="100" name="wic_pass_mark" id="wic_pass_mark" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_pass_mark' ) ); ?>" placeholder="<?php esc_attr_e( 'Course default', 'wic-tp' ); ?>"></p>
		<?php
	}

	public static function slide_box( $post ) {
		wp_nonce_field( 'wic_content_meta', 'wic_content_nonce' );
		self::parent_select( $post, 'wic_module', __( 'Module', 'wic-tp' ) );
		$layout = self::meta( $post->ID, '_wic_layout', 'text' );
		?>
		<table class="form-table" role="presentation">
			<tr><th><label for="wic_layout"><?php esc_html_e( 'Layout', 'wic-tp' ); ?></label></th>
				<td><select name="wic_layout" id="wic_layout">
					<?php foreach ( self::layouts() as $l ) : ?>
						<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $layout, $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option>
					<?php endforeach; ?>
				</select></td></tr>
			<tr><th><label for="wic_image_url"><?php esc_html_e( 'Image URL', 'wic-tp' ); ?></label></th>
				<td><input type="url" class="large-text" name="wic_image_url" id="wic_image_url" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_image_url' ) ); ?>"></td></tr>
			<tr><th><label for="wic_image_alt"><?php esc_html_e( 'Image alt text', 'wic-tp' ); ?></label></th>
				<td><input type="text" class="large-text" name="wic_image_alt" id="wic_image_alt" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_image_alt' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Required when there is an image. Empty or filename-shaped alt text blocks publishing.', 'wic-tp' ); ?></p></td></tr>
			<tr><th><label for="wic_audio_url"><?php esc_html_e( 'Narration audio URL', 'wic-tp' ); ?></label></th>
				<td><input type="url" class="large-text" name="wic_audio_url" id="wic_audio_url" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_audio_url' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Generated or recorded — the slide does not care which.', 'wic-tp' ); ?></p></td></tr>
			<tr><th><label for="wic_seconds"><?php esc_html_e( 'Narration length (seconds)', 'wic-tp' ); ?></label></th>
				<td><input type="number" min="0" name="wic_seconds" id="wic_seconds" value="<?php echo esc_attr( self::meta( $post->ID, '_wic_seconds' ) ); ?>" placeholder="45"></td></tr>
			<tr><th><label for="wic_script"><?php esc_html_e( 'Narration script / transcript', 'wic-tp' ); ?></label></th>
				<td><textarea class="large-text" rows="5" name="wic_script" id="wic_script"><?php echo esc_textarea( self::meta( $post->ID, '_wic_script' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One field feeds the audio, the transcript panel and captions.', 'wic-tp' ); ?></p></td></tr>
			<tr><th><label for="wic_layers"><?php esc_html_e( 'Layers (JSON)', 'wic-tp' ); ?></label></th>
				<td><textarea class="large-text code" rows="5" name="wic_layers" id="wic_layers"><?php echo esc_textarea( self::meta( $post->ID, '_wic_layers' ) ); ?></textarea>
					<p class="description"><code>[{"label":"Button text","content":"What the layer reveals"}]</code></p></td></tr>
			<tr><th><label for="wic_question"><?php esc_html_e( 'Question (JSON)', 'wic-tp' ); ?></label></th>
				<td><textarea class="large-text code" rows="10" name="wic_question" id="wic_question"><?php echo esc_textarea( self::meta( $post->ID, '_wic_question' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Used when layout is Question. Types: mc, mr, tf, sort, match. Examples:', 'wic-tp' ); ?><br>
						<code>{"type":"mc","prompt":"…","options":[{"text":"…","correct":true},{"text":"…","feedback":"Why this is wrong"}],"hint":"…","explain_slide":123,"points":10,"attempts":2}</code><br>
						<code>{"type":"tf","prompt":"…","answer":true}</code><br>
						<code>{"type":"sort","prompt":"…","categories":["A","B"],"items":[{"text":"…","category":0}]}</code><br>
						<code>{"type":"match","prompt":"…","pairs":[{"left":"…","right":"…"}]}</code></p></td></tr>
		</table>
		<?php
	}

	/** Parent links and the publish-time alt-text rule are applied before the post is written. */
	public static function filter_post_data( $data, $postarr ) {
		if ( ! in_array( $data['post_type'], array( 'wic_module', 'wic_slide' ), true ) ) {
			return $data;
		}
		if ( ! isset( $_POST['wic_content_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_content_nonce'] ), 'wic_content_meta' ) ) {
			return $data;
		}
		if ( isset( $_POST['wic_parent'] ) ) {
			$data['post_parent'] = absint( $_POST['wic_parent'] );
		}
		if ( 'wic_slide' === $data['post_type'] && 'publish' === $data['post_status'] ) {
			$img = isset( $_POST['wic_image_url'] ) ? trim( wp_unslash( $_POST['wic_image_url'] ) ) : '';
			$alt = isset( $_POST['wic_image_alt'] ) ? wp_unslash( $_POST['wic_image_alt'] ) : '';
			if ( '' !== $img && ! wic_alt_is_plausible( $alt ) ) {
				$data['post_status'] = 'draft';
				set_transient( 'wic_notice_' . get_current_user_id(), __( 'Slide kept as draft: the image needs meaningful alt text (not empty and not a filename) before it can be published.', 'wic-tp' ), 60 );
			}
		}
		return $data;
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! in_array( $post->post_type, array( 'wic_course', 'wic_module', 'wic_slide' ), true ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['wic_content_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_content_nonce'] ), 'wic_content_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$text = function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};
		$int  = function ( $key ) {
			return isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ? (string) absint( $_POST[ $key ] ) : '';
		};

		if ( 'wic_course' === $post->post_type ) {
			update_post_meta( $post_id, '_wic_required', empty( $_POST['wic_required'] ) ? '' : '1' );
			$groups = isset( $_POST['wic_groups'] ) ? array_values( array_intersect( (array) $_POST['wic_groups'], array( 'staff', 'intern' ) ) ) : array();
			update_post_meta( $post_id, '_wic_groups', $groups );
			update_post_meta( $post_id, '_wic_due_days', $int( 'wic_due_days' ) );
			update_post_meta( $post_id, '_wic_pass_mark', $int( 'wic_pass_mark' ) );
			update_post_meta( $post_id, '_wic_nav_mode', 'linear' === $text( 'wic_nav_mode' ) ? 'linear' : 'free' );
			update_post_meta( $post_id, '_wic_auto_advance', empty( $_POST['wic_auto_advance'] ) ? '' : '1' );
			update_post_meta( $post_id, '_wic_credit_type', $text( 'wic_credit_type' ) );
			update_post_meta( $post_id, '_wic_credit_hours', isset( $_POST['wic_credit_hours'] ) && '' !== $_POST['wic_credit_hours'] ? (string) (float) $_POST['wic_credit_hours'] : '' );
			update_post_meta( $post_id, '_wic_validity_months', $int( 'wic_validity_months' ) );
			update_post_meta( $post_id, '_wic_owner', $text( 'wic_owner' ) );
			if ( ! empty( $_POST['wic_bump_version'] ) ) {
				self::bump_version( $post_id );
			} elseif ( ! get_post_meta( $post_id, '_wic_version', true ) ) {
				update_post_meta( $post_id, '_wic_version', 1 );
			}
		}

		if ( 'wic_module' === $post->post_type ) {
			update_post_meta( $post_id, '_wic_assessment', empty( $_POST['wic_assessment'] ) ? '' : '1' );
			update_post_meta( $post_id, '_wic_pass_mark', $int( 'wic_pass_mark' ) );
		}

		if ( 'wic_slide' === $post->post_type ) {
			$layout = $text( 'wic_layout' );
			update_post_meta( $post_id, '_wic_layout', in_array( $layout, self::layouts(), true ) ? $layout : 'text' );
			update_post_meta( $post_id, '_wic_image_url', isset( $_POST['wic_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['wic_image_url'] ) ) : '' );
			update_post_meta( $post_id, '_wic_image_alt', $text( 'wic_image_alt' ) );
			update_post_meta( $post_id, '_wic_audio_url', isset( $_POST['wic_audio_url'] ) ? esc_url_raw( wp_unslash( $_POST['wic_audio_url'] ) ) : '' );
			update_post_meta( $post_id, '_wic_seconds', $int( 'wic_seconds' ) );
			update_post_meta( $post_id, '_wic_script', isset( $_POST['wic_script'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wic_script'] ) ) : '' );

			foreach ( array( 'wic_layers' => '_wic_layers', 'wic_question' => '_wic_question' ) as $field => $key ) {
				$raw = isset( $_POST[ $field ] ) ? trim( wp_unslash( $_POST[ $field ] ) ) : '';
				if ( '' !== $raw && null === json_decode( $raw, true ) ) {
					set_transient( 'wic_notice_' . get_current_user_id(), sprintf( __( '%s is not valid JSON and was not saved.', 'wic-tp' ), 'wic_layers' === $field ? __( 'Layers', 'wic-tp' ) : __( 'Question', 'wic-tp' ) ), 60 );
					continue;
				}
				update_post_meta( $post_id, $key, $raw );
			}
		}
	}

	public static function notices() {
		$key = 'wic_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	public static function slide_columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['wic_parent'] = __( 'Belongs to', 'wic-tp' );
				$new['wic_order']  = __( 'Order', 'wic-tp' );
			}
		}
		return $new;
	}

	public static function slide_column_value( $column, $post_id ) {
		$post = get_post( $post_id );
		if ( 'wic_parent' === $column ) {
			echo $post->post_parent ? esc_html( get_the_title( $post->post_parent ) ) : '—';
		}
		if ( 'wic_order' === $column ) {
			echo (int) $post->menu_order;
		}
	}
}
