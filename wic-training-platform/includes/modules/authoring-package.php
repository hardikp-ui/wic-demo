<?php
/**
 * Course packages: one JSON format for export, import, copying a course between
 * agencies and forking the shared library.
 *
 * FORMAT (format_version 1) — other modules (cmi5 export) read this shape:
 *
 *   {
 *     "format": "wic-course-package",
 *     "format_version": 1,
 *     "exported_at": "2026-09-25T10:00:00Z",      // UTC, informational
 *     "source": "https://example.invalid/",          // informational
 *     "course": {
 *       "id": 123,                                   // source ID, used only to remap references
 *       "title": "Course title",
 *       "content": "<p>Description (HTML)</p>",
 *       "meta": { "_wic_required": "1", "_wic_groups": ["staff"], "_wic_due_days": "30", … },
 *       "modules": [
 *         {
 *           "id": 124, "title": "Module", "content": "", "menu_order": 10, "status": "publish",
 *           "meta": { "_wic_assessment": "1", "_wic_pass_mark": "80" },
 *           "slides": [
 *             {
 *               "id": 125, "title": "Slide", "content": "<p>Body</p>", "menu_order": 10, "status": "publish",
 *               "meta": {
 *                 "_wic_layout": "text|image|callout|question|…",
 *                 "_wic_image_url": "", "_wic_image_alt": "", "_wic_audio_url": "", "_wic_seconds": "45",
 *                 "_wic_script": "Narration", "_wic_layers": "[{\"label\":…,\"content\":…}]",
 *                 "_wic_question": "{\"type\":\"mc\",…}"            // same JSON the player reads
 *               }
 *             }
 *           ]
 *         }
 *       ]
 *     }
 *   }
 *
 * Every post meta key beginning `_wic_` travels, so module-added keys (translations,
 * captions, requirements) are carried without this file knowing about them. Keys that
 * describe where a copy lives (_wic_version, _wic_agency_id, _wic_forked_*, _wic_copied_from,
 * _wic_shared, _wic_pin_*) never travel. Imported courses always arrive as drafts at version 1.
 * `explain_slide` references inside question JSON are remapped to the new slide IDs.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Course_Package {

	const FORMAT = 'wic-course-package';

	const LOCAL_KEYS = array( '_wic_version', '_wic_agency_id', '_wic_forked_from', '_wic_forked_version', '_wic_copied_from', '_wic_shared' );

	private static function meta( $post_id ) {
		$out = array();
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 !== strpos( $key, '_wic_' ) || in_array( $key, self::LOCAL_KEYS, true ) || 0 === strpos( $key, '_wic_pin_' ) ) {
				continue;
			}
			$out[ $key ] = maybe_unserialize( $values[0] );
		}
		return $out;
	}

	private static function kids( $parent, $type ) {
		return get_posts(
			array(
				'post_type'        => $type,
				'post_parent'      => (int) $parent,
				'post_status'      => array( 'publish', 'draft', 'pending', 'future' ),
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
	}

	/** The package array for a course. */
	public static function export( $course_id ) {
		$course  = get_post( $course_id );
		$modules = array();
		foreach ( self::kids( $course_id, 'wic_module' ) as $m ) {
			$slides = array();
			foreach ( self::kids( $m->ID, 'wic_slide' ) as $s ) {
				$slides[] = array(
					'id'         => (int) $s->ID,
					'title'      => $s->post_title,
					'content'    => $s->post_content,
					'menu_order' => (int) $s->menu_order,
					'status'     => $s->post_status,
					'meta'       => self::meta( $s->ID ),
				);
			}
			$modules[] = array(
				'id'         => (int) $m->ID,
				'title'      => $m->post_title,
				'content'    => $m->post_content,
				'menu_order' => (int) $m->menu_order,
				'status'     => $m->post_status,
				'meta'       => self::meta( $m->ID ),
				'slides'     => $slides,
			);
		}
		return array(
			'format'         => self::FORMAT,
			'format_version' => 1,
			'exported_at'    => gmdate( 'c' ),
			'source'         => home_url( '/' ),
			'course'         => array(
				'id'      => (int) $course->ID,
				'title'   => $course->post_title,
				'content' => $course->post_content,
				'meta'    => self::meta( $course->ID ),
				'modules' => $modules,
			),
		);
	}

	/**
	 * Check a package before anything is written. Returns a list of problems; empty = valid.
	 */
	public static function validate( $pkg ) {
		$errors = array();
		if ( ! is_array( $pkg ) || ( $pkg['format'] ?? '' ) !== self::FORMAT ) {
			return array( __( 'This is not a WIC course package (the "format" field is missing or wrong).', 'wic-tp' ) );
		}
		if ( (int) ( $pkg['format_version'] ?? 0 ) > 1 ) {
			$errors[] = __( 'This package was made by a newer version of the platform.', 'wic-tp' );
		}
		$c = $pkg['course'] ?? null;
		if ( ! is_array( $c ) || '' === trim( (string) ( $c['title'] ?? '' ) ) ) {
			$errors[] = __( 'The course has no title.', 'wic-tp' );
			return $errors;
		}
		if ( empty( $c['modules'] ) || ! is_array( $c['modules'] ) ) {
			$errors[] = __( 'The course has no modules.', 'wic-tp' );
			return $errors;
		}
		foreach ( $c['modules'] as $mi => $m ) {
			if ( ! is_array( $m ) || '' === trim( (string) ( $m['title'] ?? '' ) ) ) {
				$errors[] = sprintf( __( 'Module %d has no title.', 'wic-tp' ), $mi + 1 );
				continue;
			}
			foreach ( (array) ( $m['slides'] ?? array() ) as $si => $s ) {
				if ( ! is_array( $s ) || '' === trim( (string) ( $s['title'] ?? '' ) ) ) {
					$errors[] = sprintf( __( 'Module %1$d, slide %2$d has no title.', 'wic-tp' ), $mi + 1, $si + 1 );
					continue;
				}
				$q = $s['meta']['_wic_question'] ?? '';
				if ( is_string( $q ) && '' !== trim( $q ) && null === json_decode( $q, true ) ) {
					$errors[] = sprintf( __( 'Slide "%s" has question data that is not valid JSON.', 'wic-tp' ), $s['title'] );
				}
			}
		}
		return $errors;
	}

	private static function write_meta( $post_id, $meta ) {
		foreach ( (array) $meta as $key => $value ) {
			$key = (string) $key;
			if ( 0 !== strpos( $key, '_wic_' ) || in_array( $key, self::LOCAL_KEYS, true ) || 0 === strpos( $key, '_wic_pin_' ) ) {
				continue;
			}
			if ( in_array( $key, array( '_wic_question', '_wic_layers' ), true ) && is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			update_post_meta( $post_id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
		}
	}

	/**
	 * Create a course from a package.
	 *
	 * @param array $opts agency (int), title (override), status (course status, default draft),
	 *                    link (bool: stamp _wic_copied_from with source IDs — used by copy and fork).
	 * @return array|WP_Error array( 'course_id' => int, 'warnings' => string[], 'map' => old_id => new_id )
	 */
	public static function import( $pkg, $opts = array() ) {
		$errors = self::validate( $pkg );
		if ( $errors ) {
			return new WP_Error( 'wic_package', implode( ' ', $errors ) );
		}
		$opts     = wp_parse_args(
			$opts,
			array(
				'agency' => null,
				'title'  => '',
				'status' => 'draft',
				'link'   => false,
			)
		);
		$c        = $pkg['course'];
		$warnings = array();
		$map      = array();
		$layouts  = WIC_Content::layouts();

		$course_id = wp_insert_post(
			array(
				'post_type'    => 'wic_course',
				'post_status'  => $opts['status'],
				'post_title'   => $opts['title'] ? $opts['title'] : sanitize_text_field( $c['title'] ),
				'post_content' => wp_slash( wp_kses_post( (string) ( $c['content'] ?? '' ) ) ),
			),
			true
		);
		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}
		self::write_meta( $course_id, $c['meta'] ?? array() );
		update_post_meta( $course_id, '_wic_version', 1 );
		if ( null !== $opts['agency'] ) {
			update_post_meta( $course_id, '_wic_agency_id', (int) $opts['agency'] );
		}
		if ( $opts['link'] && ! empty( $c['id'] ) ) {
			update_post_meta( $course_id, '_wic_copied_from', (int) $c['id'] );
		}
		if ( ! empty( $c['id'] ) ) {
			$map[ (int) $c['id'] ] = $course_id;
		}

		$questions = array();
		foreach ( $c['modules'] as $mi => $m ) {
			$mid = wp_insert_post(
				array(
					'post_type'    => 'wic_module',
					'post_status'  => in_array( $m['status'] ?? 'publish', array( 'publish', 'draft' ), true ) ? $m['status'] ?? 'publish' : 'publish',
					'post_title'   => sanitize_text_field( $m['title'] ),
					'post_content' => wp_slash( wp_kses_post( (string) ( $m['content'] ?? '' ) ) ),
					'post_parent'  => $course_id,
					'menu_order'   => isset( $m['menu_order'] ) ? (int) $m['menu_order'] : ( $mi + 1 ) * 10,
				)
			);
			if ( ! $mid || is_wp_error( $mid ) ) {
				continue;
			}
			self::write_meta( $mid, $m['meta'] ?? array() );
			if ( ! empty( $m['id'] ) ) {
				$map[ (int) $m['id'] ] = $mid;
				if ( $opts['link'] ) {
					update_post_meta( $mid, '_wic_copied_from', (int) $m['id'] );
				}
			}
			foreach ( (array) ( $m['slides'] ?? array() ) as $si => $s ) {
				$meta   = (array) ( $s['meta'] ?? array() );
				$status = in_array( $s['status'] ?? 'publish', array( 'publish', 'draft' ), true ) ? ( $s['status'] ?? 'publish' ) : 'publish';
				$layout = (string) ( $meta['_wic_layout'] ?? 'text' );
				if ( ! in_array( $layout, $layouts, true ) ) {
					$warnings[]           = sprintf( __( 'Slide "%1$s": layout "%2$s" is not known here, so it was set to text.', 'wic-tp' ), $s['title'], $layout );
					$meta['_wic_layout'] = 'text';
				}
				if ( ! empty( $meta['_wic_image_url'] ) && ! wic_alt_is_plausible( $meta['_wic_image_alt'] ?? '' ) && 'publish' === $status ) {
					$status     = 'draft';
					$warnings[] = sprintf( __( 'Slide "%s" was kept as a draft: its image needs meaningful alt text.', 'wic-tp' ), $s['title'] );
				}
				$sid = wp_insert_post(
					array(
						'post_type'    => 'wic_slide',
						'post_status'  => $status,
						'post_title'   => sanitize_text_field( $s['title'] ),
						'post_content' => wp_slash( wp_kses_post( (string) ( $s['content'] ?? '' ) ) ),
						'post_parent'  => $mid,
						'menu_order'   => isset( $s['menu_order'] ) ? (int) $s['menu_order'] : ( $si + 1 ) * 10,
					)
				);
				if ( ! $sid || is_wp_error( $sid ) ) {
					continue;
				}
				if ( 'draft' === get_post_status( $sid ) && 'publish' === $status ) {
					$warnings[] = sprintf( __( 'Slide "%s" was kept as a draft: an image in its text needs meaningful alt text.', 'wic-tp' ), $s['title'] );
				}
				self::write_meta( $sid, $meta );
				if ( ! empty( $s['id'] ) ) {
					$map[ (int) $s['id'] ] = $sid;
					if ( $opts['link'] ) {
						update_post_meta( $sid, '_wic_copied_from', (int) $s['id'] );
					}
				}
				if ( ! empty( $meta['_wic_question'] ) ) {
					$questions[] = $sid;
				}
			}
		}

		// Point "review this slide" links at the new copies.
		foreach ( $questions as $sid ) {
			$q = json_decode( (string) get_post_meta( $sid, '_wic_question', true ), true );
			if ( is_array( $q ) && ! empty( $q['explain_slide'] ) ) {
				$q['explain_slide'] = isset( $map[ (int) $q['explain_slide'] ] ) ? $map[ (int) $q['explain_slide'] ] : 0;
				update_post_meta( $sid, '_wic_question', wp_slash( wp_json_encode( $q ) ) );
			}
		}
		WIC_Content::flush_tree_cache();
		wic_audit( 'course_import', 'course', $course_id, array( 'warnings' => count( $warnings ) ) );
		return array(
			'course_id' => $course_id,
			'warnings'  => $warnings,
			'map'       => $map,
		);
	}

	/** Deep copy: course → modules → slides, every meta key, optionally into another agency. */
	public static function copy( $course_id, $agency = null, $title = '' ) {
		$pkg = self::export( $course_id );
		$res = self::import(
			$pkg,
			array(
				'agency' => $agency,
				'title'  => $title ? $title : sprintf( __( '%s (copy)', 'wic-tp' ), get_the_title( $course_id ) ),
				'link'   => true,
			)
		);
		if ( ! is_wp_error( $res ) ) {
			wic_audit( 'course_copy', 'course', $res['course_id'], array( 'from' => $course_id, 'agency' => $agency ) );
		}
		return $res;
	}

	/**
	 * CSV outline → draft course. Columns: module, slide, body, script (header row optional).
	 * Rows with the same module title go into the same module, in the order they appear.
	 */
	public static function import_csv( $path, $title, $agency = null ) {
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return new WP_Error( 'wic_csv', __( 'The file could not be read.', 'wic-tp' ) );
		}
		$modules = array();
		$first   = true;
		while ( false !== ( $row = fgetcsv( $fh ) ) ) {
			$row = array_map(
				function ( $v ) {
					return trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $v ) );
				},
				$row
			);
			if ( $first ) {
				$first = false;
				if ( 'module' === strtolower( $row[0] ?? '' ) ) {
					continue;
				}
			}
			if ( '' === ( $row[0] ?? '' ) || '' === ( $row[1] ?? '' ) ) {
				continue;
			}
			$key = strtolower( $row[0] );
			if ( ! isset( $modules[ $key ] ) ) {
				$modules[ $key ] = array(
					'title'  => $row[0],
					'meta'   => array(),
					'slides' => array(),
				);
			}
			$body                        = $row[2] ?? '';
			$modules[ $key ]['slides'][] = array(
				'title'   => $row[1],
				'content' => false === strpos( $body, '<' ) ? wpautop( esc_html( $body ) ) : $body,
				'meta'    => array(
					'_wic_layout' => 'text',
					'_wic_script' => sanitize_textarea_field( $row[3] ?? '' ),
				),
			);
		}
		fclose( $fh );
		if ( ! $modules ) {
			return new WP_Error( 'wic_csv', __( 'No rows with both a module and a slide title were found.', 'wic-tp' ) );
		}
		return self::import(
			array(
				'format'         => self::FORMAT,
				'format_version' => 1,
				'course'         => array(
					'title'   => $title,
					'content' => '',
					'meta'    => array(),
					'modules' => array_values( $modules ),
				),
			),
			array( 'agency' => $agency )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Forks of the shared library                                        */
	/* ------------------------------------------------------------------ */

	/** An agency's own editable copy of a shared course, still linked to the original. */
	public static function fork( $course_id, $agency ) {
		$res = self::copy( $course_id, $agency, get_the_title( $course_id ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		update_post_meta( $res['course_id'], '_wic_forked_from', (int) $course_id );
		update_post_meta( $res['course_id'], '_wic_forked_version', WIC_Content::course_version( $course_id ) );
		wic_audit( 'course_fork', 'course', $res['course_id'], array( 'from' => $course_id, 'agency' => $agency ) );
		return $res;
	}

	/** Copies of a source post that live inside a given course. */
	private static function copies_in( $source_id, $course_id, $type ) {
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => '_wic_copied_from',
				'meta_value'       => (int) $source_id,
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			if ( WIC_Agencies::course_of( $id ) === (int) $course_id ) {
				return (int) $id;
			}
		}
		return 0;
	}

	private static function fingerprint( $title, $content, $meta ) {
		ksort( $meta );
		return md5( $title . "\n" . $content . "\n" . wp_json_encode( $meta ) );
	}

	/**
	 * What changed in the shared original since the fork last took it.
	 *
	 * @return array|null null when nothing is offered; otherwise version, from, and a list of
	 *                    changes: array( 'type' => added|changed|removed, 'slide' => id, 'title' => … ).
	 */
	public static function pending_update( $fork_id ) {
		$orig = (int) get_post_meta( $fork_id, '_wic_forked_from', true );
		if ( ! $orig || ! get_post( $orig ) ) {
			return null;
		}
		$have = (int) get_post_meta( $fork_id, '_wic_forked_version', true );
		$now  = WIC_Content::course_version( $orig );
		if ( $now <= $have ) {
			return null;
		}
		$live = WIC_Course_Versions::build( $orig );
		$base = WIC_Course_Versions::get( $orig, $have );
		$prev = array();
		if ( $base ) {
			foreach ( $base->data['slides'] as $sid => $s ) {
				$prev[ (int) $sid ] = self::fingerprint( $s['title'], $s['content'], (array) $s['meta'] );
			}
		} else {
			// No frozen base: compare with the fork's own copies (local edits then show as changes too).
			foreach ( array_keys( $live['slides'] ) as $sid ) {
				$copy = self::copies_in( $sid, $fork_id, 'wic_slide' );
				if ( $copy ) {
					$p                  = get_post( $copy );
					$prev[ (int) $sid ] = self::fingerprint( $p->post_title, $p->post_content, WIC_Course_Versions::post_meta( $copy ) );
				}
			}
		}
		$changes = array();
		foreach ( $live['slides'] as $sid => $s ) {
			$fp = self::fingerprint( $s['title'], $s['content'], (array) $s['meta'] );
			if ( ! isset( $prev[ (int) $sid ] ) ) {
				$changes[] = array(
					'type'  => 'added',
					'slide' => (int) $sid,
					'title' => $s['title'],
				);
			} elseif ( $prev[ (int) $sid ] !== $fp ) {
				$changes[] = array(
					'type'  => 'changed',
					'slide' => (int) $sid,
					'title' => $s['title'],
				);
			}
		}
		foreach ( array_keys( $prev ) as $sid ) {
			if ( ! isset( $live['slides'][ $sid ] ) ) {
				$changes[] = array(
					'type'  => 'removed',
					'slide' => (int) $sid,
					'title' => $base && isset( $base->data['slides'][ $sid ] ) ? $base->data['slides'][ $sid ]['title'] : get_the_title( $sid ),
				);
			}
		}
		return array(
			'original' => $orig,
			'from'     => $have,
			'version'  => $now,
			'changes'  => $changes,
		);
	}

	/**
	 * Take the shared update into the fork. The fork starts a new version first, so its own
	 * learners part-way through keep what they started on.
	 */
	public static function take_update( $fork_id ) {
		$u = self::pending_update( $fork_id );
		if ( ! $u ) {
			return 0;
		}
		WIC_Content::bump_version( $fork_id );
		$live = WIC_Course_Versions::build( $u['original'] );
		$mod  = array();
		foreach ( $live['modules'] as $m ) {
			foreach ( $m['slides'] as $sid ) {
				$mod[ $sid ] = $m;
			}
		}
		$n = 0;
		foreach ( $u['changes'] as $ch ) {
			$copy = self::copies_in( $ch['slide'], $fork_id, 'wic_slide' );
			if ( 'removed' === $ch['type'] ) {
				if ( $copy ) {
					wp_update_post(
						array(
							'ID'          => $copy,
							'post_status' => 'draft',
						)
					);
					$n++;
				}
				continue;
			}
			$s = $live['slides'][ $ch['slide'] ];
			if ( ! $copy ) {
				$m      = $mod[ $ch['slide'] ];
				$module = self::copies_in( $m['id'], $fork_id, 'wic_module' );
				if ( ! $module ) {
					$module = wp_insert_post(
						array(
							'post_type'   => 'wic_module',
							'post_status' => 'publish',
							'post_title'  => $m['title'],
							'post_parent' => $fork_id,
							'menu_order'  => (int) get_post_field( 'menu_order', $m['id'] ),
						)
					);
					self::write_meta( $module, $m['meta'] );
					update_post_meta( $module, '_wic_copied_from', (int) $m['id'] );
				}
				$copy = wp_insert_post(
					array(
						'post_type'   => 'wic_slide',
						'post_status' => 'publish',
						'post_title'  => $s['title'],
						'post_parent' => $module,
						'menu_order'  => (int) get_post_field( 'menu_order', $ch['slide'] ),
					)
				);
				update_post_meta( $copy, '_wic_copied_from', (int) $ch['slide'] );
			}
			wp_update_post(
				array(
					'ID'           => $copy,
					'post_title'   => $s['title'],
					'post_content' => wp_slash( $s['content'] ),
				)
			);
			self::write_meta( $copy, $s['meta'] );
			$n++;
		}
		update_post_meta( $fork_id, '_wic_forked_version', $u['version'] );
		WIC_Content::flush_tree_cache();
		wic_audit( 'course_take_update', 'course', $fork_id, array( 'version' => $u['version'], 'slides' => $n ) );
		return $n;
	}
}
