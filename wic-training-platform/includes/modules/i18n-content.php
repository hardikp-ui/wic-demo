<?php
/**
 * Course content in more than one language, narration recorded in-house or generated,
 * and video with captions and a second audio track (#55–#62, #127, #148).
 *
 * Language is a field on the slide, not a copy of the course: slide meta `_wic_i18n` holds
 * { lang: { title, html, script, audio, vtt, alt_audio, question, needs_review, reviewed_at } }.
 * The default language is the slide itself. When the default wording changes, every
 * translation is flagged needs_review, so a stale Spanish slide is visible, not silent.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'wic_agency_defaults',
	function ( $d ) {
		$d['content_languages']            = 'en:English';
		$d['pronunciations']               = '';
		$d['decision_generated_narration'] = 0;
		return $d;
	}
);

add_filter(
	'wic_agency_fields',
	function ( $f ) {
		$f['content_languages']            = array( __( 'Course languages', 'wic-tp' ), 'text', __( 'Comma-separated code:name pairs, default language first, e.g. "en:English, es:Español". Each slide can then carry a translation per language.', 'wic-tp' ) );
		$f['pronunciations']               = array( __( 'Pronunciation list', 'wic-tp' ), 'textarea', __( 'One per line: term = how to say it, e.g. "WIC = wick". Applied to generated narration.', 'wic-tp' ) );
		$f['decision_generated_narration'] = array( __( 'Generated narration', 'wic-tp' ), 'checkbox', __( 'Decision first (#61): whether generated narration is acceptable for official state training is unanswered (asked 3 September). While off, only recorded audio plays. When on, slides with a script but no recording offer a clearly labelled generated voice in the learner\'s browser.', 'wic-tp' ) );
		return $f;
	}
);

add_filter(
	'wic_slide_layouts',
	function ( $layouts ) {
		if ( ! in_array( 'video', $layouts, true ) ) {
			$layouts[] = 'video';
		}
		return $layouts;
	}
);

class WIC_I18n {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_wic_slide', array( __CLASS__, 'save' ), 20, 2 );
		add_filter( 'manage_wic_slide_posts_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_wic_slide_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_filter( 'wic_player_slide', array( __CLASS__, 'player_slide' ), 20, 4 );
		add_filter( 'wic_player_data', array( __CLASS__, 'player_data' ), 20, 3 );
		add_filter( 'wic_question_for_learner', array( __CLASS__, 'question_for_learner' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_ajax_wic_upload_voice', array( __CLASS__, 'upload_voice' ) );
		add_action( 'admin_footer-post.php', array( __CLASS__, 'admin_script' ) );
		add_action( 'admin_footer-post-new.php', array( __CLASS__, 'admin_script' ) );
	}

	/* ------------------------------------------------------------ languages */

	/** code => label, default first. */
	public static function languages() {
		$out = array();
		foreach ( explode( ',', (string) wic_setting( 'content_languages' ) ) as $pair ) {
			$parts = array_map( 'trim', explode( ':', $pair, 2 ) );
			$code  = sanitize_key( $parts[0] );
			if ( $code ) {
				$out[ $code ] = isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : strtoupper( $code );
			}
		}
		return $out ? $out : array( 'en' => 'English' );
	}

	public static function default_lang() {
		$langs = self::languages();
		return (string) key( $langs );
	}

	public static function user_lang( $user_id ) {
		$lang  = (string) get_user_meta( $user_id, 'wic_content_lang', true );
		$langs = self::languages();
		return isset( $langs[ $lang ] ) ? $lang : self::default_lang();
	}

	public static function get( $slide_id ) {
		$v = json_decode( (string) get_post_meta( $slide_id, '_wic_i18n', true ), true );
		return is_array( $v ) ? $v : array();
	}

	/** lang => date flagged, for every translation that is out of date with the default wording. */
	public static function needs_review( $slide_id ) {
		$out = array();
		foreach ( self::get( $slide_id ) as $lang => $t ) {
			if ( ! empty( $t['needs_review'] ) ) {
				$out[ $lang ] = $t['needs_review'];
			}
		}
		return $out;
	}

	private static function has_content( $t ) {
		foreach ( array( 'title', 'html', 'script', 'audio', 'vtt', 'question' ) as $k ) {
			if ( ! empty( $t[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function pronunciations() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) wic_setting( 'pronunciations' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$out[] = array( $parts[0], $parts[1] );
			}
		}
		return $out;
	}

	/** Captions as a data URL, so they work offline and need no authenticated request. */
	public static function vtt_url( $vtt ) {
		$vtt = trim( (string) $vtt );
		if ( '' === $vtt ) {
			return '';
		}
		if ( 0 !== strpos( $vtt, 'WEBVTT' ) ) {
			$vtt = "WEBVTT\n\n" . $vtt;
		}
		return 'data:text/vtt;charset=utf-8;base64,' . base64_encode( $vtt );
	}

	/* ------------------------------------------------------- authoring box */

	public static function meta_boxes() {
		add_meta_box( 'wic_i18n_meta', __( 'Video, captions, narration and languages', 'wic-tp' ), array( __CLASS__, 'box' ), 'wic_slide', 'normal', 'default' );
	}

	private static function recorder( $target_id ) {
		?>
		<span class="wic-rec" data-target="<?php echo esc_attr( $target_id ); ?>">
			<button type="button" class="button wic-rec-start"><?php esc_html_e( 'Record', 'wic-tp' ); ?></button>
			<button type="button" class="button wic-rec-stop" disabled><?php esc_html_e( 'Stop', 'wic-tp' ); ?></button>
			<audio class="wic-rec-preview" controls hidden></audio>
			<button type="button" class="button button-primary wic-rec-use" hidden><?php esc_html_e( 'Use this recording', 'wic-tp' ); ?></button>
			<span class="wic-rec-status" role="status" aria-live="polite"></span>
		</span>
		<?php
	}

	public static function box( $post ) {
		wp_nonce_field( 'wic_i18n_meta', 'wic_i18n_nonce' );
		$langs   = self::languages();
		$default = self::default_lang();
		$data    = self::get( $post->ID );
		$seconds = WIC_Content::slide_seconds( $post->ID );
		?>
		<h4><?php esc_html_e( 'Video', 'wic-tp' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Any slide can carry a video; choose the Video layout for a slide that is mainly a video, such as a short refresher clip.', 'wic-tp' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="wic_video_url"><?php esc_html_e( 'Video URL', 'wic-tp' ); ?></label></th>
				<td><input type="url" class="large-text" id="wic_video_url" name="wic_video_url" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_video_url', true ) ); ?>"></td></tr>
			<tr><th><label for="wic_video_poster"><?php esc_html_e( 'Poster image URL', 'wic-tp' ); ?></label></th>
				<td><input type="url" class="large-text" id="wic_video_poster" name="wic_video_poster" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wic_video_poster', true ) ); ?>"></td></tr>
			<tr><th><label for="wic_vtt"><?php printf( esc_html__( 'Captions (WebVTT, %s)', 'wic-tp' ), esc_html( $langs[ $default ] ) ); ?></label></th>
				<td><textarea class="large-text code" rows="5" id="wic_vtt" name="wic_vtt"><?php echo esc_textarea( get_post_meta( $post->ID, '_wic_vtt', true ) ); ?></textarea>
					<p><button type="button" class="button wic-vtt-draft" data-script="#wic_script" data-target="#wic_vtt" data-seconds="<?php echo (int) $seconds; ?>"><?php esc_html_e( 'Draft captions from the narration script', 'wic-tp' ); ?></button>
					<span class="description"><?php esc_html_e( 'Timings are spread over the narration length; check them against the video before publishing.', 'wic-tp' ); ?></span></p></td></tr>
		</table>

		<h4><?php printf( esc_html__( 'Record narration (%s)', 'wic-tp' ), esc_html( $langs[ $default ] ) ); ?></h4>
		<p><?php self::recorder( 'wic_audio_url' ); ?></p>
		<p class="description"><?php esc_html_e( 'Records in the browser, uploads to the media library and fills in the narration audio URL above. Save the slide afterwards.', 'wic-tp' ); ?></p>

		<?php foreach ( $langs as $code => $label ) : ?>
			<?php
			if ( $code === $default ) {
				continue;
			}
			$t     = isset( $data[ $code ] ) ? $data[ $code ] : array();
			$field = function ( $k ) use ( $t ) {
				return isset( $t[ $k ] ) ? (string) $t[ $k ] : '';
			};
			$p     = 'wic_i18n_' . $code . '_';
			?>
			<details class="wic-i18n-lang" open>
				<summary><strong><?php echo esc_html( $label ); ?></strong>
					<?php if ( ! empty( $t['needs_review'] ) ) : ?>
						— <span style="color:#b32d2e;font-weight:600"><?php printf( esc_html__( 'Needs review: the %1$s wording changed on %2$s', 'wic-tp' ), esc_html( $langs[ $default ] ), esc_html( wic_format_date( $t['needs_review'] ) ) ); ?></span>
					<?php elseif ( self::has_content( $t ) ) : ?>
						— <?php esc_html_e( 'Up to date', 'wic-tp' ); ?>
					<?php else : ?>
						— <?php esc_html_e( 'Not translated', 'wic-tp' ); ?>
					<?php endif; ?>
				</summary>
				<table class="form-table" role="presentation">
					<tr><th><label for="<?php echo esc_attr( $p ); ?>title"><?php esc_html_e( 'Title', 'wic-tp' ); ?></label></th>
						<td><input type="text" class="large-text" id="<?php echo esc_attr( $p ); ?>title" name="wic_i18n[<?php echo esc_attr( $code ); ?>][title]" value="<?php echo esc_attr( $field( 'title' ) ); ?>"></td></tr>
					<tr><th><label for="<?php echo esc_attr( $p ); ?>html"><?php esc_html_e( 'Slide text', 'wic-tp' ); ?></label></th>
						<td><textarea class="large-text" rows="5" id="<?php echo esc_attr( $p ); ?>html" name="wic_i18n[<?php echo esc_attr( $code ); ?>][html]"><?php echo esc_textarea( $field( 'html' ) ); ?></textarea></td></tr>
					<tr><th><label for="<?php echo esc_attr( $p ); ?>script"><?php esc_html_e( 'Narration script / transcript', 'wic-tp' ); ?></label></th>
						<td><textarea class="large-text" rows="4" id="<?php echo esc_attr( $p ); ?>script" name="wic_i18n[<?php echo esc_attr( $code ); ?>][script]"><?php echo esc_textarea( $field( 'script' ) ); ?></textarea></td></tr>
					<tr><th><label for="<?php echo esc_attr( $p ); ?>audio"><?php esc_html_e( 'Narration audio URL', 'wic-tp' ); ?></label></th>
						<td><input type="url" class="large-text" id="<?php echo esc_attr( $p ); ?>audio" name="wic_i18n[<?php echo esc_attr( $code ); ?>][audio]" value="<?php echo esc_attr( $field( 'audio' ) ); ?>">
							<p><?php self::recorder( $p . 'audio' ); ?></p></td></tr>
					<tr><th><label for="<?php echo esc_attr( $p ); ?>vtt"><?php esc_html_e( 'Video captions (WebVTT)', 'wic-tp' ); ?></label></th>
						<td><textarea class="large-text code" rows="4" id="<?php echo esc_attr( $p ); ?>vtt" name="wic_i18n[<?php echo esc_attr( $code ); ?>][vtt]"><?php echo esc_textarea( $field( 'vtt' ) ); ?></textarea>
							<p><button type="button" class="button wic-vtt-draft" data-script="#<?php echo esc_attr( $p ); ?>script" data-target="#<?php echo esc_attr( $p ); ?>vtt" data-seconds="<?php echo (int) $seconds; ?>"><?php esc_html_e( 'Draft captions from this script', 'wic-tp' ); ?></button></p></td></tr>
					<tr><th><label for="<?php echo esc_attr( $p ); ?>alt_audio"><?php esc_html_e( 'Second audio track for the video', 'wic-tp' ); ?></label></th>
						<td><input type="url" class="large-text" id="<?php echo esc_attr( $p ); ?>alt_audio" name="wic_i18n[<?php echo esc_attr( $code ); ?>][alt_audio]" value="<?php echo esc_attr( $field( 'alt_audio' ) ); ?>">
							<p class="description"><?php esc_html_e( 'For a video with a voice: a dubbed audio file played in step with the (muted) video.', 'wic-tp' ); ?></p></td></tr>
					<tr><th><label for="<?php echo esc_attr( $p ); ?>question"><?php esc_html_e( 'Question wording (JSON)', 'wic-tp' ); ?></label></th>
						<td><textarea class="large-text code" rows="4" id="<?php echo esc_attr( $p ); ?>question" name="wic_i18n[<?php echo esc_attr( $code ); ?>][question]"><?php echo esc_textarea( $field( 'question' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Wording only, in the same order as the original — which answer is correct always comes from the original. Keys: prompt, hint, options (list of texts), feedback (list, per option), feedback_correct, feedback_incorrect, categories, items, pairs ([{"left","right"}]).', 'wic-tp' ); ?></p></td></tr>
					<?php if ( ! empty( $t['needs_review'] ) ) : ?>
						<tr><th><?php esc_html_e( 'Review', 'wic-tp' ); ?></th>
							<td><label><input type="checkbox" name="wic_i18n[<?php echo esc_attr( $code ); ?>][reviewed]" value="1"> <?php esc_html_e( 'I have checked this translation against the current wording', 'wic-tp' ); ?></label></td></tr>
					<?php endif; ?>
				</table>
			</details>
		<?php endforeach; ?>
		<?php if ( 1 === count( $langs ) ) : ?>
			<p class="description"><?php esc_html_e( 'Only one course language is set up. Add more under WIC Platform → Agency settings → Course languages.', 'wic-tp' ); ?></p>
		<?php endif; ?>
		<?php
	}

	private static function source_hash( $post ) {
		$script = isset( $_POST['wic_script'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wic_script'] ) ) : (string) get_post_meta( $post->ID, '_wic_script', true ); // phpcs:ignore WordPress.Security.NonceVerification
		return md5( $post->post_title . "\n" . $post->post_content . "\n" . $script );
	}

	public static function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['wic_i18n_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wic_i18n_nonce'] ), 'wic_i18n_meta' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_wic_video_url', isset( $_POST['wic_video_url'] ) ? esc_url_raw( wp_unslash( $_POST['wic_video_url'] ) ) : '' );
		update_post_meta( $post_id, '_wic_video_poster', isset( $_POST['wic_video_poster'] ) ? esc_url_raw( wp_unslash( $_POST['wic_video_poster'] ) ) : '' );
		update_post_meta( $post_id, '_wic_vtt', isset( $_POST['wic_vtt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wic_vtt'] ) ) : '' );

		$old     = self::get( $post_id );
		$in      = isset( $_POST['wic_i18n'] ) && is_array( $_POST['wic_i18n'] ) ? wp_unslash( $_POST['wic_i18n'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$langs   = self::languages();
		$default = self::default_lang();
		$new     = $old;
		foreach ( $in as $code => $t ) {
			$code = sanitize_key( $code );
			if ( ! isset( $langs[ $code ] ) || $code === $default || ! is_array( $t ) ) {
				continue;
			}
			$q = isset( $t['question'] ) ? trim( (string) $t['question'] ) : '';
			if ( '' !== $q && null === json_decode( $q, true ) ) {
				set_transient( 'wic_notice_' . get_current_user_id(), sprintf( __( 'The %s question wording is not valid JSON and was not saved.', 'wic-tp' ), $langs[ $code ] ), 60 );
				$q = isset( $old[ $code ]['question'] ) ? $old[ $code ]['question'] : '';
			}
			$entry = array(
				'title'        => sanitize_text_field( isset( $t['title'] ) ? $t['title'] : '' ),
				'html'         => wp_kses_post( isset( $t['html'] ) ? $t['html'] : '' ),
				'script'       => sanitize_textarea_field( isset( $t['script'] ) ? $t['script'] : '' ),
				'audio'        => esc_url_raw( isset( $t['audio'] ) ? $t['audio'] : '' ),
				'vtt'          => sanitize_textarea_field( isset( $t['vtt'] ) ? $t['vtt'] : '' ),
				'alt_audio'    => esc_url_raw( isset( $t['alt_audio'] ) ? $t['alt_audio'] : '' ),
				'question'     => $q,
				'needs_review' => isset( $old[ $code ]['needs_review'] ) ? $old[ $code ]['needs_review'] : '',
				'reviewed_at'  => isset( $old[ $code ]['reviewed_at'] ) ? $old[ $code ]['reviewed_at'] : '',
			);
			if ( ! empty( $t['reviewed'] ) ) {
				$entry['needs_review'] = '';
				$entry['reviewed_at']  = wic_now();
				wic_audit( 'translation_reviewed', 'slide', $post_id, array( 'lang' => $code ) );
			}
			$new[ $code ] = $entry;
		}

		// Needs-review flag (#62): the default wording changed, so every existing translation is now suspect.
		$hash = self::source_hash( $post );
		$prev = (string) get_post_meta( $post_id, '_wic_src_hash', true );
		if ( $prev && $prev !== $hash ) {
			foreach ( $new as $code => $t ) {
				$just_reviewed = ! empty( $in[ $code ]['reviewed'] );
				if ( self::has_content( $t ) && ! $just_reviewed ) {
					$new[ $code ]['needs_review'] = wic_now();
				}
			}
			wic_audit( 'translation_flagged', 'slide', $post_id, array( 'langs' => array_keys( $new ) ) );
		}
		update_post_meta( $post_id, '_wic_src_hash', $hash );
		update_post_meta( $post_id, '_wic_i18n', wp_slash( wp_json_encode( $new ) ) );
	}

	public static function columns( $cols ) {
		if ( count( self::languages() ) > 1 ) {
			$cols['wic_i18n'] = __( 'Translations', 'wic-tp' );
		}
		return $cols;
	}

	public static function column_value( $column, $post_id ) {
		if ( 'wic_i18n' !== $column ) {
			return;
		}
		$langs = self::languages();
		$data  = self::get( $post_id );
		$parts = array();
		foreach ( $langs as $code => $label ) {
			if ( $code === self::default_lang() ) {
				continue;
			}
			if ( empty( $data[ $code ] ) || ! self::has_content( $data[ $code ] ) ) {
				$parts[] = esc_html( $label ) . ': ' . esc_html__( 'missing', 'wic-tp' );
			} elseif ( ! empty( $data[ $code ]['needs_review'] ) ) {
				$parts[] = '<strong style="color:#b32d2e">' . esc_html( $label ) . ': ' . esc_html__( 'needs review', 'wic-tp' ) . '</strong>';
			} else {
				$parts[] = esc_html( $label ) . ': ' . esc_html__( 'up to date', 'wic-tp' );
			}
		}
		echo implode( '<br>', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	/* ---------------------------------------------------------------- player */

	/** Overlay translated wording onto a question by position. Correctness is never read from a translation. */
	public static function overlay_question( $q, $tq ) {
		if ( ! is_array( $tq ) ) {
			return $q;
		}
		foreach ( array( 'prompt', 'hint', 'feedback_correct', 'feedback_incorrect' ) as $k ) {
			if ( ! empty( $tq[ $k ] ) ) {
				$q[ $k ] = (string) $tq[ $k ];
			}
		}
		if ( ! empty( $q['options'] ) ) {
			foreach ( $q['options'] as $i => $o ) {
				if ( ! empty( $tq['options'][ $i ] ) ) {
					$q['options'][ $i ]['text'] = (string) $tq['options'][ $i ];
				}
				if ( ! empty( $tq['feedback'][ $i ] ) ) {
					$q['options'][ $i ]['feedback'] = (string) $tq['feedback'][ $i ];
				}
			}
		}
		if ( ! empty( $q['categories'] ) && ! empty( $tq['categories'] ) ) {
			foreach ( $q['categories'] as $i => $c ) {
				if ( ! empty( $tq['categories'][ $i ] ) ) {
					$q['categories'][ $i ] = (string) $tq['categories'][ $i ];
				}
			}
		}
		if ( ! empty( $q['items'] ) && ! empty( $tq['items'] ) ) {
			foreach ( $q['items'] as $i => $it ) {
				if ( ! empty( $tq['items'][ $i ] ) ) {
					$q['items'][ $i ]['text'] = (string) $tq['items'][ $i ];
				}
			}
		}
		if ( ! empty( $q['pairs'] ) && ! empty( $tq['pairs'] ) ) {
			foreach ( $q['pairs'] as $i => $p ) {
				foreach ( array( 'left', 'right' ) as $side ) {
					if ( ! empty( $tq['pairs'][ $i ][ $side ] ) ) {
						$q['pairs'][ $i ][ $side ] = (string) $tq['pairs'][ $i ][ $side ];
					}
				}
			}
		}
		return $q;
	}

	public static function question_for_learner( $q, $slide_id, $lang ) {
		if ( ! $lang || $lang === self::default_lang() ) {
			return $q;
		}
		$data = self::get( $slide_id );
		if ( empty( $data[ $lang ]['question'] ) ) {
			return $q;
		}
		return self::overlay_question( $q, json_decode( $data[ $lang ]['question'], true ) );
	}

	public static function player_slide( $slide, $sid, $uid, $course_id ) {
		$video = esc_url_raw( (string) get_post_meta( $sid, '_wic_video_url', true ) );
		$langs = self::languages();
		$def   = self::default_lang();
		$data  = self::get( $sid );
		$i18n  = array();
		$q     = WIC_Content::question( $sid );
		foreach ( $data as $code => $t ) {
			if ( ! isset( $langs[ $code ] ) || $code === $def || ! self::has_content( $t ) ) {
				continue;
			}
			$entry = array(
				'title'       => isset( $t['title'] ) ? (string) $t['title'] : '',
				'html'        => ! empty( $t['html'] ) ? wp_kses_post( wpautop( $t['html'] ) ) : '',
				'script'      => isset( $t['script'] ) ? (string) $t['script'] : '',
				'audio'       => isset( $t['audio'] ) ? esc_url_raw( $t['audio'] ) : '',
				'altAudio'    => isset( $t['alt_audio'] ) ? esc_url_raw( $t['alt_audio'] ) : '',
				'needsReview' => ! empty( $t['needs_review'] ),
			);
			if ( $q && ! empty( $t['question'] ) ) {
				$entry['question'] = WIC_Content::public_question( self::overlay_question( $q, json_decode( $t['question'], true ) ) );
			}
			$i18n[ $code ] = $entry;
		}
		$slide['i18n'] = (object) $i18n;
		if ( $video ) {
			$tracks = array();
			$def_vtt = self::vtt_url( get_post_meta( $sid, '_wic_vtt', true ) );
			if ( $def_vtt ) {
				$tracks[] = array( 'lang' => $def, 'label' => $langs[ $def ], 'src' => $def_vtt );
			}
			foreach ( $data as $code => $t ) {
				if ( isset( $langs[ $code ] ) && $code !== $def && ! empty( $t['vtt'] ) ) {
					$tracks[] = array( 'lang' => $code, 'label' => $langs[ $code ], 'src' => self::vtt_url( $t['vtt'] ) );
				}
			}
			$slide['video'] = array(
				'url'    => $video,
				'poster' => esc_url_raw( (string) get_post_meta( $sid, '_wic_video_poster', true ) ),
				'tracks' => $tracks,
			);
		}
		return $slide;
	}

	public static function player_data( $data, $uid, $course_id ) {
		$langs = array();
		foreach ( self::languages() as $code => $label ) {
			$langs[] = array( 'code' => $code, 'label' => $label );
		}
		$data['languages']          = $langs;
		$data['defaultLang']        = self::default_lang();
		$data['lang']               = self::user_lang( $uid );
		$data['generatedNarration'] = (bool) (int) wic_setting( 'decision_generated_narration' );
		$data['pronunciations']     = self::pronunciations();
		return $data;
	}

	/* ------------------------------------------------------------------ REST */

	public static function routes() {
		register_rest_route(
			WIC_Rest::NS,
			'/pref',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_pref' ),
				'permission_callback' => function () {
					return is_user_logged_in() && 'active' === wic_user_status( get_current_user_id() );
				},
			)
		);
	}

	public static function save_pref( WP_REST_Request $req ) {
		$lang = sanitize_key( (string) $req->get_param( 'lang' ) );
		if ( ! isset( self::languages()[ $lang ] ) ) {
			return new WP_Error( 'wic_bad_lang', __( 'That language is not available.', 'wic-tp' ), array( 'status' => 400 ) );
		}
		update_user_meta( get_current_user_id(), 'wic_content_lang', $lang );
		return array( 'ok' => true, 'lang' => $lang );
	}

	/** Recorded narration: upload from the browser recorder into the media library. */
	public static function upload_voice() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_ajax_referer( 'wic_upload_voice_' . $post_id, 'nonce' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'upload_files' ) || empty( $_FILES['audio'] ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot upload a recording for this slide.', 'wic-tp' ) ), 403 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$allow = function ( $mimes ) {
			$mimes['webm'] = 'audio/webm';
			$mimes['ogg']  = 'audio/ogg';
			$mimes['m4a']  = 'audio/mp4';
			return $mimes;
		};
		add_filter( 'upload_mimes', $allow );
		$id = media_handle_upload( 'audio', $post_id, array( 'post_title' => sprintf( __( 'Narration for "%s"', 'wic-tp' ), get_the_title( $post_id ) ) ), array( 'test_form' => false, 'test_type' => false ) );
		remove_filter( 'upload_mimes', $allow );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}
		wic_audit( 'narration_recorded', 'slide', $post_id, array( 'attachment' => $id ) );
		wp_send_json_success( array( 'url' => wp_get_attachment_url( $id ) ) );
	}

	/** Recorder and caption-draft controls on the slide edit screen. */
	public static function admin_script() {
		$screen = get_current_screen();
		if ( ! $screen || 'wic_slide' !== $screen->post_type ) {
			return;
		}
		global $post;
		$cfg = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'post'  => $post ? (int) $post->ID : 0,
			'nonce' => $post ? wp_create_nonce( 'wic_upload_voice_' . $post->ID ) : '',
			'text'  => array(
				'recording' => __( 'Recording…', 'wic-tp' ),
				'stopped'   => __( 'Recorded. Listen, then choose "Use this recording".', 'wic-tp' ),
				'uploading' => __( 'Uploading…', 'wic-tp' ),
				'done'      => __( 'Uploaded. Save the slide to keep it.', 'wic-tp' ),
				'noMic'     => __( 'Recording is not available: the browser has no microphone access.', 'wic-tp' ),
				'failed'    => __( 'Upload failed.', 'wic-tp' ),
				'noScript'  => __( 'Write the narration script first.', 'wic-tp' ),
			),
		);
		?>
		<script>
		(function () {
			var C = <?php echo wp_json_encode( $cfg ); ?>;
			function pad(n, w) { n = String(n); while (n.length < w) { n = '0' + n; } return n; }
			function ts(s) { var ms = Math.round(s * 1000); return pad(Math.floor(ms / 3600000), 2) + ':' + pad(Math.floor(ms / 60000) % 60, 2) + ':' + pad(Math.floor(ms / 1000) % 60, 2) + '.' + pad(ms % 1000, 3); }
			document.querySelectorAll('.wic-vtt-draft').forEach(function (b) {
				b.addEventListener('click', function () {
					var src = document.querySelector(b.getAttribute('data-script'));
					var out = document.querySelector(b.getAttribute('data-target'));
					var text = src ? src.value.trim() : '';
					if (!text) { window.alert(C.text.noScript); return; }
					var parts = text.match(/[^.!?]+[.!?]*/g) || [text];
					parts = parts.map(function (p) { return p.trim(); }).filter(Boolean);
					var total = parseInt(b.getAttribute('data-seconds'), 10) || 45;
					var chars = parts.reduce(function (a, p) { return a + p.length; }, 0) || 1;
					var t = 0, lines = ['WEBVTT', ''];
					parts.forEach(function (p, i) {
						var d = total * p.length / chars;
						lines.push(String(i + 1), ts(t) + ' --> ' + ts(t + d), p, '');
						t += d;
					});
					out.value = lines.join('\n');
				});
			});
			document.querySelectorAll('.wic-rec').forEach(function (box) {
				var start = box.querySelector('.wic-rec-start'), stop = box.querySelector('.wic-rec-stop');
				var prev = box.querySelector('.wic-rec-preview'), use = box.querySelector('.wic-rec-use');
				var status = box.querySelector('.wic-rec-status');
				var rec = null, chunks = [], blob = null;
				start.addEventListener('click', function () {
					if (!navigator.mediaDevices || !window.MediaRecorder) { status.textContent = C.text.noMic; return; }
					navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
						chunks = [];
						rec = new MediaRecorder(stream);
						rec.ondataavailable = function (e) { if (e.data.size) { chunks.push(e.data); } };
						rec.onstop = function () {
							stream.getTracks().forEach(function (t) { t.stop(); });
							blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
							prev.src = URL.createObjectURL(blob); prev.hidden = false; use.hidden = false;
							status.textContent = C.text.stopped;
						};
						rec.start(); start.disabled = true; stop.disabled = false; status.textContent = C.text.recording;
						stop.focus();
					}).catch(function () { status.textContent = C.text.noMic; });
				});
				stop.addEventListener('click', function () { if (rec) { rec.stop(); } stop.disabled = true; start.disabled = false; });
				use.addEventListener('click', function () {
					if (!blob) { return; }
					var ext = /ogg/.test(blob.type) ? 'ogg' : (/mp4/.test(blob.type) ? 'm4a' : 'webm');
					var fd = new FormData();
					fd.append('action', 'wic_upload_voice'); fd.append('post_id', C.post); fd.append('nonce', C.nonce);
					fd.append('audio', blob, 'narration-' + C.post + '-' + Date.now() + '.' + ext);
					status.textContent = C.text.uploading; use.disabled = true;
					fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
						use.disabled = false;
						if (j && j.success) {
							var target = document.getElementById(box.getAttribute('data-target'));
							if (target) { target.value = j.data.url; }
							status.textContent = C.text.done;
						} else { status.textContent = (j && j.data && j.data.message) || C.text.failed; }
					}).catch(function () { use.disabled = false; status.textContent = C.text.failed; });
				});
			});
		})();
		</script>
		<?php
	}
}

add_action( 'wic_init', array( 'WIC_I18n', 'init' ) );
