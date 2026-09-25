<?php
/**
 * Seeds a complete, clearly-sample agency through the platform's own functions, then moves
 * dates back so the dashboards look like a portal that has been in use for most of a year.
 *
 * Everything created is recorded in the `wic_demo_state` option so Reset removes exactly
 * what was seeded and nothing else.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Demo_Seeder {

	const STATE = 'wic_demo_state';

	/** @var array Running record of everything created. */
	private static $s = array();

	/* ------------------------------------------------------------------ */
	/* Public entry points                                                */
	/* ------------------------------------------------------------------ */

	public static function is_loaded() {
		$s = get_option( self::STATE );
		return is_array( $s ) && ! empty( $s['users'] );
	}

	public static function state() {
		$s = get_option( self::STATE );
		return is_array( $s ) ? $s : array();
	}

	public static function seed() {
		if ( ! class_exists( 'WIC_Records' ) ) {
			return new WP_Error( 'wic_demo_missing', __( 'The WIC Training Platform plugin must be active.', 'wic-demo' ) );
		}
		if ( self::is_loaded() ) {
			self::reset();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// No email leaves a demo seed: every address is example.invalid anyway.
		add_filter( 'pre_wp_mail', '__return_true', 99 );

		$runner = get_current_user_id();
		if ( ! $runner || ! user_can( $runner, 'manage_options' ) ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			$runner = $admins ? (int) $admins[0] : 0;
		}
		wp_set_current_user( $runner );

		// The platform's own orientation sample overlaps "Using the Training Portal", so retire it
		// for the demo (retired courses keep their records and can be restored from Authoring).
		foreach ( get_posts( array( 'post_type' => 'wic_course', 'post_status' => 'publish', 'title' => 'Portal Orientation (Sample)', 'numberposts' => 5, 'fields' => 'ids' ) ) as $sample_id ) {
			wp_update_post( array( 'ID' => $sample_id, 'post_status' => get_post_status_object( 'wic_retired' ) ? 'wic_retired' : 'draft' ) );
		}

		self::$s = array(
			'users'       => array(),
			'posts'       => array(),
			'terms'       => array(),
			'clinics'     => array(),
			'rules'       => array(),
			'assignments' => array(),
			'courses'     => array(),
			'slides'      => array(),
			'sessions'    => array(),
			'forms'       => array(),
			'personas'    => array(),
			'prev_agency' => get_option( 'wic_agency', array() ),
			'seeded_at'   => current_time( 'mysql' ),
		);
		mt_srand( 20260925 );
		$seq_before = (int) get_option( 'wic_cert_seq', 0 );

		self::agency();
		self::clinics();
		self::courses();
		self::people();
		self::records();
		// The presenter's own admin account gets training too, so its portal home is not empty.
		if ( $runner ) {
			self::scenario( $runner, 'welcome', 'complete', array( 'days' => 120 ) );
			self::scenario( $runner, 'privacy', 'complete', array( 'days' => 45 ) );
			self::scenario( $runner, 'service', 'progress', array( 'pct' => 55, 'due_in' => 10 ) );
			self::scenario( $runner, 'interpreter', 'overdue', array( 'pct' => 20, 'late' => 6, 'source' => 'manual' ) );
			self::scenario( $runner, 'portal', 'coming_due', array( 'due_in' => 2 ) );
			self::scenario( $runner, 'safety', 'complete', array( 'days' => 210 ) );
			self::scenario( $runner, 'dashboards', 'progress', array( 'pct' => 40, 'due_in' => 16, 'source' => 'manual' ) );
			self::$s['runner'] = $runner;
		}
		self::modules();
		self::tidy_events();
		self::renumber_certificates( $seq_before );

		wp_set_current_user( $runner );
		update_option( self::STATE, self::$s, false );
		remove_filter( 'pre_wp_mail', '__return_true', 99 );
		return true;
	}

	/**
	 * Remove only what was seeded. The platform itself never deletes people; this demo plugin
	 * does, by direct deletes of demo-tagged rows, because demo accounts are not real records.
	 */
	public static function reset() {
		global $wpdb;
		$s = self::state();
		if ( ! $s ) {
			return;
		}
		$users = array_values( array_filter( array_map( 'intval', (array) $s['users'] ) ) );
		$in    = $users ? implode( ',', $users ) : '0';

		$wpdb->suppress_errors( true );
		if ( ! empty( $s['assignments'] ) ) {
			$wpdb->query( 'DELETE FROM ' . wic_table( 'assignment_na' ) . ' WHERE assignment_id IN (' . implode( ',', array_map( 'intval', $s['assignments'] ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
		}
		foreach ( array( 'assignments', 'positions', 'attempts', 'completions', 'certificates', 'events', 'notes', 'signatures', 'roster', 'external', 'competency', 'doc_opens', 'path_assign', 'boost_rounds', 'boost_items', 'boost_answers', 'format_requests', 'answer_drafts', 'bank_draws', 'xapi_queue' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . wic_table( $t ) . " WHERE user_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
		}
		$wpdb->query( 'DELETE FROM ' . wic_table( 'saved_reports' ) . " WHERE owner_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! empty( $s['runner'] ) ) {
			// The presenter's own demo notifications (their training records are theirs to keep).
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . wic_table( 'events' ) . ' WHERE user_id = %d AND message LIKE %s', (int) $s['runner'], '%(Demo notification)%' ) );
		}
		if ( ! empty( $s['sessions'] ) ) {
			$wpdb->query( 'DELETE FROM ' . wic_table( 'roster' ) . ' WHERE session_id IN (' . implode( ',', array_map( 'intval', $s['sessions'] ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		foreach ( (array) $s['rules'] as $id ) {
			$wpdb->delete( wic_table( 'assign_rules' ), array( 'id' => (int) $id ) );
		}
		foreach ( (array) $s['clinics'] as $id ) {
			$wpdb->delete( wic_table( 'clinics' ), array( 'id' => (int) $id ) );
		}
		$wpdb->suppress_errors( false );

		foreach ( (array) $s['posts'] as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( (array) $s['terms'] as $t ) {
			wp_delete_term( (int) $t[0], $t[1] );
		}
		foreach ( $users as $id ) {
			$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $id ) );
			$wpdb->delete( $wpdb->users, array( 'ID' => $id ) );
			clean_user_cache( $id );
		}
		if ( isset( $s['prev_agency'] ) ) {
			update_option( 'wic_agency', $s['prev_agency'] );
		}
		if ( class_exists( 'WIC_Clinics' ) ) {
			WIC_Clinics::flush();
		}
		delete_option( self::STATE );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	/** GMT mysql datetime $days ago (negative = in the future), with a working-hours time. */
	private static function ago( $days, $hour = null ) {
		$ts = time() - (int) round( $days * DAY_IN_SECONDS );
		$h  = null === $hour ? mt_rand( 14, 22 ) : $hour; // 14–22 UTC is a US working day.
		return gmdate( 'Y-m-d', $ts ) . sprintf( ' %02d:%02d:00', $h, mt_rand( 0, 59 ) );
	}

	private static function ts( $mysql ) {
		return strtotime( $mysql . ' UTC' );
	}

	private static function post( $type, $title, $content = '', $args = array() ) {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_type'    => $type,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $content,
					'post_author'  => get_current_user_id(),
				),
				$args
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_post_meta( $id, '_wic_demo', 1 );
		self::$s['posts'][] = (int) $id;
		return (int) $id;
	}

	private static function has_table( $name ) {
		return in_array( $name, self::$tables, true );
	}

	/** @var array Module tables known to exist (from the platform's own table list). */
	private static $tables = array();

	private static function uid( $key ) {
		return isset( self::$s['users'][ $key ] ) ? (int) self::$s['users'][ $key ] : 0;
	}

	private static function cid( $key ) {
		return isset( self::$s['courses'][ $key ] ) ? (int) self::$s['courses'][ $key ] : 0;
	}

	private static function clinic( $key ) {
		return isset( self::$s['clinics'][ $key ] ) ? (int) self::$s['clinics'][ $key ] : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Agency settings                                                    */
	/* ------------------------------------------------------------------ */

	private static function agency() {
		foreach ( WIC_Install::table_sql() as $sql ) {
			if ( preg_match( '/CREATE TABLE \S*wic_(\w+)/', $sql, $m ) ) {
				self::$tables[] = $m[1];
			}
		}
		$defaults = wic_agency_defaults();
		$opts     = (array) get_option( 'wic_agency', array() );
		$img      = get_theme_root_uri() . '/brushart-wic-theme/assets/img/';
		$set      = array(
			'name'              => 'BrushArt WIC Training (Demo)',
			'logo_url'          => $img . 'brushart-logo-navy.svg',
			'logo_inverse_url'  => $img . 'brushart-logo-white.svg',
			'font_heading_stretch' => 115,
			'color_primary'     => '#112337',
			'color_accent'      => '#C22227',
			'color_ink'         => '#1D2430',
			'color_surface'     => '#ffffff',
			'cert_prefix'       => 'BA',
			'signatory_name'    => 'Program Director (Demo)',
			'signatory_title'   => 'State WIC Training (Demonstration)',
			'sender_name'       => 'BrushArt WIC Training (Demo)',
			'announcement'      => 'This is a demonstration site. Every person, clinic, course and record here is fictional sample data.',
			'announce_from'     => current_time( 'Y-m-d' ),
			'announce_until'    => gmdate( 'Y-m-d', current_time( 'timestamp' ) + 30 * DAY_IN_SECONDS ),
			'content_languages' => 'en:English, es:Español',
			'vendor_help_phone' => '(555) 010-0199',
			'vendor_help_email' => 'vendor-help@example.invalid',
			'vendor_help_hours' => 'Monday to Friday, 8am–5pm (demo)',
			'font_css_url'      => 'https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..800&family=Inter:wght@400;500;600;700&display=swap',
			'font_heading'      => 'Archivo',
			'font_body'         => 'Inter',
			'lang_switcher'     => 1,
			'boost_enabled'     => 1,
		);
		foreach ( $set as $k => $v ) {
			// Only keys the platform knows about; the font keys arrive with the design work.
			if ( array_key_exists( $k, $defaults ) ) {
				$opts[ $k ] = $v;
			}
		}
		update_option( 'wic_agency', $opts );
	}

	/* ------------------------------------------------------------------ */
	/* Clinics                                                            */
	/* ------------------------------------------------------------------ */

	private static function clinics() {
		global $wpdb;
		if ( ! class_exists( 'WIC_Clinics' ) ) {
			return;
		}
		$defs = array(
			'c1' => array( 'Riverside Clinic', 'RIV', '120 River Road, Anytown, ST 00001', '(555) 010-0101', 'Mon–Fri 8:00–4:30' ),
			'c2' => array( 'Maple Street Clinic', 'MAP', '45 Maple Street, Anytown, ST 00002', '(555) 010-0102', 'Mon–Thu 8:30–5:00' ),
			'c3' => array( 'Northgate Clinic', 'NOR', '9 Northgate Plaza, Lakeview, ST 00003', '(555) 010-0103', 'Mon–Fri 9:00–5:00' ),
			'c4' => array( 'Prairie View Clinic', 'PRA', '300 Prairie Avenue, Fieldton, ST 00004', '(555) 010-0104', 'Tue–Fri 8:00–4:00' ),
			'c5' => array( 'Lakeside Clinic', 'LAK', '18 Shore Drive, Lakeview, ST 00005', '(555) 010-0105', 'Mon–Fri 8:00–5:00, Sat 9:00–12:00' ),
			'c6' => array( 'Hillcrest Clinic', 'HIL', '77 Summit Way, Hilltown, ST 00006', '(555) 010-0106', 'Mon, Wed, Fri 8:30–4:30' ),
		);
		foreach ( $defs as $key => $d ) {
			$existing = WIC_Clinics::by_name( $d[0] );
			$id       = WIC_Clinics::ensure( $d[0] );
			if ( ! $id ) {
				continue;
			}
			$wpdb->update(
				wic_table( 'clinics' ),
				array(
					'code'    => 'DEMO-' . $d[1],
					'address' => $d[2] . "\nDemonstration record",
					'phone'   => $d[3],
					'email'   => strtolower( $d[1] ) . '-clinic@example.invalid',
					'hours'   => $d[4],
					'status'  => 'active',
				),
				array( 'id' => $id )
			);
			self::$s['clinics'][ $key ] = $id;
			if ( $existing ) {
				// Already there before the demo: keep it on reset.
				unset( self::$s['clinics'][ $key ] );
				self::$s['clinics_kept'][ $key ] = $id;
			}
		}
		WIC_Clinics::flush();
	}

	private static function clinic_id( $key ) {
		if ( isset( self::$s['clinics'][ $key ] ) ) {
			return (int) self::$s['clinics'][ $key ];
		}
		return isset( self::$s['clinics_kept'][ $key ] ) ? (int) self::$s['clinics_kept'][ $key ] : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Courses                                                            */
	/* ------------------------------------------------------------------ */

	private static function courses() {
		$defs = array_merge( include WIC_DEMO_DIR . 'includes/courses.php', include WIC_DEMO_DIR . 'includes/courses-more.php' );
		foreach ( $defs as $key => $c ) {
			$cid = self::post( 'wic_course', $c['title'], '<p>' . esc_html( $c['excerpt'] ) . '</p>', array( 'post_excerpt' => $c['excerpt'] ) );
			if ( ! $cid ) {
				continue;
			}
			self::$s['courses'][ $key ] = $cid;
			$meta = array(
				'_wic_required'        => $c['required'] ? '1' : '',
				'_wic_groups'          => $c['groups'],
				'_wic_due_days'        => (string) $c['due_days'],
				'_wic_pass_mark'       => '80',
				'_wic_nav_mode'        => 'free',
				'_wic_credit_type'     => $c['credit'][0],
				'_wic_credit_hours'    => (string) $c['credit'][1],
				'_wic_validity_months' => (string) $c['validity'],
				'_wic_owner'           => 'Demo content team',
				'_wic_version'         => 1,
				'_wic_agency_id'       => 0,
			);
			if ( ! empty( $c['credits'] ) ) {
				$meta['_wic_credits'] = wp_json_encode( $c['credits'] );
			}
			if ( ! empty( $c['vendor'] ) ) {
				$meta['_wic_vendor_course'] = '1';
				$meta['_wic_validity_mode'] = 'calendar_year';
			}
			foreach ( $meta as $k => $v ) {
				update_post_meta( $cid, $k, $v );
			}

			$explain = array();
			$order   = 0;
			foreach ( $c['modules'] as $m ) {
				$order += 10;
				$mid    = self::post( 'wic_module', $m['title'], '', array( 'post_parent' => $cid, 'menu_order' => $order ) );
				update_post_meta( $mid, '_wic_assessment', empty( $m['assessment'] ) ? '' : '1' );
				update_post_meta( $mid, '_wic_pass_mark', '' );
				$so = 0;
				foreach ( $m['slides'] as $sl ) {
					$so  += 10;
					$html = isset( $sl['html'] ) ? $sl['html'] : '';
					$sid  = self::post( 'wic_slide', $sl['title'], $html, array( 'post_parent' => $mid, 'menu_order' => $so ) );
					self::$s['slides'][ $sl['key'] ] = $sid;
					$script = isset( $sl['script'] ) ? $sl['script'] : '';
					$layout = $sl['layout'];
					if ( ! empty( $sl['image'] ) && file_exists( WIC_DEMO_DIR . 'assets/img/' . $sl['image'] ) ) {
						// Illustrations ship with this plugin; the alt text is a real description, never a filename.
						update_post_meta( $sid, '_wic_image_url', WIC_DEMO_URL . 'assets/img/' . $sl['image'] );
						update_post_meta( $sid, '_wic_image_alt', isset( $sl['alt'] ) ? $sl['alt'] : '' );
						if ( 'text' === $layout ) {
							$layout = 'image';
						}
					}
					update_post_meta( $sid, '_wic_layout', $layout );
					update_post_meta( $sid, '_wic_script', $script );
					update_post_meta( $sid, '_wic_seconds', (string) ( isset( $sl['seconds'] ) ? $sl['seconds'] : 30 ) );
					if ( ! empty( $sl['layers'] ) ) {
						update_post_meta( $sid, '_wic_layers', wp_slash( wp_json_encode( $sl['layers'] ) ) );
					}
					if ( ! empty( $sl['q'] ) ) {
						if ( ! empty( $sl['q']['explain'] ) ) {
							$explain[ $sid ] = $sl['q']['explain'];
						}
						update_post_meta( $sid, '_wic_question', wp_slash( wp_json_encode( $sl['q'] ) ) );
					}
					if ( ! empty( $sl['hard'] ) ) {
						update_post_meta( $sid, '_wic_demo_hard', 1 );
					}
					// Mark the source wording so the needs-review flag only trips on real edits.
					update_post_meta( $sid, '_wic_src_hash', md5( $sl['title'] . "\n" . $html . "\n" . $script ) );
					if ( ! empty( $sl['es'] ) ) {
						$es = $sl['es'];
						update_post_meta(
							$sid,
							'_wic_i18n',
							wp_slash(
								wp_json_encode(
									array(
										'es' => array(
											'title'        => $es['title'],
											'html'         => $es['html'],
											'script'       => $es['script'],
											'audio'        => '',
											'vtt'          => '',
											'alt_audio'    => '',
											'question'     => '',
											// One translation is left flagged so the needs-review column has something to show.
											'needs_review' => empty( $es['review'] ) ? '' : self::ago( 3 ),
											'reviewed_at'  => empty( $es['review'] ) ? self::ago( 20 ) : '',
										),
									)
								)
							)
						);
					}
				}
			}
			// Resolve "review this slide" links now that every slide has an ID.
			foreach ( $explain as $sid => $key ) {
				$q = json_decode( (string) get_post_meta( $sid, '_wic_question', true ), true );
				if ( is_array( $q ) && isset( self::$s['slides'][ $key ] ) ) {
					$q['explain_slide'] = self::$s['slides'][ $key ];
					unset( $q['explain'] );
					update_post_meta( $sid, '_wic_question', wp_slash( wp_json_encode( $q ) ) );
				}
			}
			if ( method_exists( 'WIC_Content', 'flush_tree_cache' ) ) {
				WIC_Content::flush_tree_cache( $cid );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* People                                                             */
	/* ------------------------------------------------------------------ */

	private static function user( $key, $first, $last, $role, $args = array() ) {
		$login = 'demo-' . sanitize_user( strtolower( $first . '-' . $last ), true );
		$login = str_replace( array( "'", ' ' ), '', $login );
		$id    = wp_insert_user(
			array(
				'user_login'      => $login,
				'user_pass'       => wp_generate_password( 40, true, true ), // Unusable: demo users are reached through the switcher only.
				'user_email'      => $login . '@example.invalid',
				'first_name'      => $first,
				'last_name'       => $last,
				'display_name'    => $first . ' ' . $last,
				'role'            => $role,
				'user_registered' => gmdate( 'Y-m-d H:i:s', time() - (int) ( isset( $args['registered'] ) ? $args['registered'] : mt_rand( 200, 330 ) ) * DAY_IN_SECONDS ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		self::$s['users'][ $key ] = (int) $id;
		update_user_meta( $id, 'wic_demo', 1 );
		update_user_meta( $id, 'wic_status', isset( $args['status'] ) ? $args['status'] : 'active' );
		update_user_meta( $id, 'wic_group', isset( $args['group'] ) ? $args['group'] : 'staff' );
		update_user_meta( $id, 'wic_agency_id', 0 );
		if ( ! empty( $args['staff_no'] ) ) {
			update_user_meta( $id, 'wic_staff_number', $args['staff_no'] );
		}
		if ( ! empty( $args['clinic'] ) ) {
			$c = self::clinic_id( $args['clinic'] );
			if ( $c && class_exists( 'WIC_Clinics' ) ) {
				WIC_Clinics::set_user_clinic( $id, $c );
			}
		}
		if ( ! empty( $args['reports_to'] ) ) {
			update_user_meta( $id, 'wic_reports_to', self::uid( $args['reports_to'] ) );
		}
		if ( 'active' === ( isset( $args['status'] ) ? $args['status'] : 'active' ) ) {
			update_user_meta( $id, 'wic_approved_at', gmdate( 'Y-m-d H:i:s', time() - (int) ( isset( $args['registered'] ) ? $args['registered'] : 200 ) * DAY_IN_SECONDS + DAY_IN_SECONDS ) );
		}
		return (int) $id;
	}

	private static function people() {
		self::user( 'morgan', 'Morgan', 'Reyes', 'wic_admin', array( 'registered' => 340, 'staff_no' => 'E1001' ) );
		self::user( 'priya', 'Priya', 'Natarajan', get_role( 'wic_local_admin' ) ? 'wic_local_admin' : 'wic_supervisor', array( 'registered' => 330, 'staff_no' => 'E1002', 'clinic' => 'c1', 'reports_to' => 'morgan' ) );
		update_user_meta( self::uid( 'priya' ), 'wic_admin_clinics', array_values( array_filter( array( self::clinic_id( 'c1' ), self::clinic_id( 'c2' ) ) ) ) );
		self::user( 'jordan', 'Jordan', 'Ellis', 'wic_author', array( 'registered' => 320, 'staff_no' => 'E1003' ) );

		$sups = array(
			'dana' => array( 'Dana', 'Whitfield', 'c1' ),
			'luis' => array( 'Luis', 'Ortega', 'c2' ),
			'grace' => array( 'Grace', 'Kim', 'c3' ),
			'tom'  => array( 'Tom', 'Becker', 'c5' ),
		);
		$n = 1010;
		foreach ( $sups as $k => $s ) {
			self::user( $k, $s[0], $s[1], 'wic_supervisor', array( 'registered' => mt_rand( 280, 330 ), 'staff_no' => 'E' . ( $n++ ), 'clinic' => $s[2], 'reports_to' => 'morgan' ) );
		}

		// key => first, last, clinic, supervisor, group, days since registration.
		$staff = array(
			'sam'    => array( 'Sam', 'Rivera', 'c1', 'dana', 'staff', 240 ),
			'maria'  => array( 'Maria', 'Lopez', 'c1', 'dana', 'staff', 300 ),
			'hannah' => array( 'Hannah', 'Schultz', 'c1', 'dana', 'staff', 210 ),
			'aisha'  => array( 'Aisha', 'Bello', 'c1', 'dana', 'staff', 6 ),
			'kevin'  => array( 'Kevin', 'Osei', 'c1', 'dana', 'intern', 90 ),
			'jamal'  => array( 'Jamal', 'Wright', 'c2', 'luis', 'staff', 280 ),
			'olivia' => array( 'Olivia', 'Chen', 'c2', 'luis', 'staff', 190 ),
			'rosa'   => array( 'Rosa', 'Martinez', 'c2', 'luis', 'intern', 70 ),
			'ben'    => array( 'Ben', 'Carter', 'c2', 'luis', 'staff', 9 ),
			'tyler'  => array( 'Tyler', 'Jensen', 'c3', 'grace', 'staff', 260 ),
			'nadia'  => array( 'Nadia', 'Rahman', 'c3', 'grace', 'staff', 150 ),
			'emily'  => array( 'Emily', 'Novak', 'c3', 'grace', 'intern', 60 ),
			'chloe'  => array( 'Chloe', 'Nguyen', 'c3', 'grace', 'staff', 4 ),
			'marcus' => array( 'Marcus', 'Hill', 'c4', 'grace', 'staff', 310 ),
			'lily'   => array( 'Lily', 'Anderson', 'c4', 'grace', 'staff', 170 ),
			'diego'  => array( 'Diego', 'Fernandez', 'c4', 'grace', 'staff', 120 ),
			'sarah'  => array( 'Sarah', 'OConnell', 'c5', 'tom', 'staff', 290 ),
			'andre'  => array( 'Andre', 'Thompson', 'c5', 'tom', 'staff', 230 ),
			'megan'  => array( 'Megan', 'Walsh', 'c5', 'tom', 'intern', 45 ),
			'ruth'   => array( 'Ruth', 'Yazzie', 'c6', 'tom', 'staff', 270 ),
			'noah'   => array( 'Noah', 'Fischer', 'c6', 'tom', 'staff', 200 ),
			'kim'    => array( 'Kim', 'Tran', 'c6', 'tom', 'staff', 140 ),
		);
		$n = 2001;
		foreach ( $staff as $k => $p ) {
			self::user( $k, $p[0], $p[1], 'wic_learner', array( 'clinic' => $p[2], 'reports_to' => $p[3], 'group' => $p[4], 'registered' => $p[5], 'staff_no' => 'E' . ( $n++ ) ) );
		}
		if ( self::uid( 'sarah' ) ) {
			wp_update_user( array( 'ID' => self::uid( 'sarah' ), 'last_name' => "O'Connell", 'display_name' => "Sarah O'Connell" ) );
		}
		// Named mentors for the three new starters.
		foreach ( array( 'aisha' => 'maria', 'ben' => 'jamal', 'chloe' => 'tyler' ) as $new => $mentor ) {
			update_user_meta( self::uid( $new ), 'wic_mentor', self::uid( $mentor ) );
		}

		// Two registrations waiting for approval, and one closed account.
		self::user( 'evan', 'Evan', 'Brooks', 'wic_learner', array( 'status' => 'pending', 'clinic' => 'c1', 'reports_to' => 'dana', 'registered' => 2 ) );
		self::user( 'fatima', 'Fatima', 'Hassan', 'wic_learner', array( 'status' => 'pending', 'clinic' => 'c5', 'reports_to' => 'tom', 'registered' => 7 ) );
		self::user( 'gary', 'Gary', 'Olsen', 'wic_learner', array( 'status' => 'deactivated', 'clinic' => 'c4', 'reports_to' => 'grace', 'registered' => 320, 'staff_no' => 'E1999' ) );

		// Vendor stores: two approved, two applications waiting.
		if ( get_role( 'wic_vendor' ) ) {
			$vendors = array(
				'alex' => array( 'Alex', 'Dunn', 'Corner Market (Demo)', 'V-0001-DEMO', '12 Corner Street, Anytown, ST 00001', 'active', 150 ),
				'pat'  => array( 'Pat', 'Kowalski', 'Valley Grocery (Demo)', 'V-0002-DEMO', '400 Valley Road, Fieldton, ST 00004', 'active', 260 ),
				'chris' => array( 'Chris', 'Obi', 'Sunrise Foods (Demo)', 'V-0003-DEMO', '5 Sunrise Boulevard, Lakeview, ST 00005', 'pending', 3 ),
				'lee'  => array( 'Lee', 'Park', 'Main Street Grocery (Demo)', 'V-0004-DEMO', '88 Main Street, Hilltown, ST 00006', 'pending', 8 ),
			);
			foreach ( $vendors as $k => $v ) {
				$id = self::user( $k, $v[0], $v[1], 'wic_vendor', array( 'status' => $v[5], 'registered' => $v[6], 'group' => 'staff' ) );
				update_user_meta( $id, 'wic_vendor_store', $v[2] );
				update_user_meta( $id, 'wic_vendor_number', $v[3] );
				update_user_meta( $id, 'wic_vendor_address', $v[4] );
				update_user_meta( $id, 'wic_vendor_phone', '(555) 010-02' . mt_rand( 10, 99 ) );
			}
		}

		self::$s['personas'] = array_filter(
			array(
				'state_admin' => self::uid( 'morgan' ),
				'supervisor'  => self::uid( 'dana' ),
				'staff'       => self::uid( 'sam' ),
				'new_starter' => self::uid( 'aisha' ),
				'author'      => self::uid( 'jordan' ),
				'local_admin' => self::uid( 'priya' ),
				'vendor'      => self::uid( 'alex' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Training records                                                   */
	/* ------------------------------------------------------------------ */

	private static function correct_answer( $q ) {
		switch ( $q['type'] ) {
			case 'mc':
			case 'tf':
				foreach ( $q['options'] as $i => $o ) {
					if ( ! empty( $o['correct'] ) ) {
						return $i;
					}
				}
				return 0;
			case 'mr':
				$a = array();
				foreach ( $q['options'] as $i => $o ) {
					if ( ! empty( $o['correct'] ) ) {
						$a[] = $i;
					}
				}
				return $a;
			case 'sort':
				return array_map( 'intval', wp_list_pluck( $q['items'], 'category' ) );
			case 'match':
				return array_keys( $q['pairs'] );
		}
		return 0;
	}

	private static function wrong_answer( $q ) {
		switch ( $q['type'] ) {
			case 'mc':
			case 'tf':
				foreach ( $q['options'] as $i => $o ) {
					if ( empty( $o['correct'] ) ) {
						return $i;
					}
				}
				return 0;
			case 'mr':
				foreach ( $q['options'] as $i => $o ) {
					if ( empty( $o['correct'] ) ) {
						return array( $i );
					}
				}
				return array();
			case 'sort':
				$a    = self::correct_answer( $q );
				$a[0] = $a[0] ? 0 : 1;
				return $a;
			case 'match':
				$a = self::correct_answer( $q );
				if ( count( $a ) > 1 ) {
					$t    = $a[0];
					$a[0] = $a[1];
					$a[1] = $t;
				}
				return $a;
		}
		return 0;
	}

	/**
	 * Work through the first $pct percent of a course as a learner would: every slide
	 * opened on the server, every question answered — sometimes wrongly first.
	 */
	private static function work( $uid, $cid, $pct, $allow_fail = false ) {
		$flat   = WIC_Records::learner_flat( $uid, $cid );
		$slides = array_keys( $flat );
		if ( ! $slides || $pct <= 0 ) {
			return 0;
		}
		$n = $pct >= 100 ? count( $slides ) : max( 1, (int) round( count( $slides ) * $pct / 100 ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$sid = $slides[ $i ];
			WIC_Records::touch( $uid, $cid, $sid );
			$q = WIC_Content::question( $sid );
			if ( ! $q ) {
				continue;
			}
			$hard  = (bool) get_post_meta( $sid, '_wic_demo_hard', true );
			$wrong = mt_rand( 1, 100 ) <= ( $hard ? 55 : 12 );
			if ( $wrong ) {
				WIC_Records::record_answer( $uid, $cid, $sid, self::wrong_answer( $q ), array() );
				if ( $allow_fail && mt_rand( 1, 100 ) <= 25 ) {
					WIC_Records::record_answer( $uid, $cid, $sid, self::wrong_answer( $q ), array() );
					continue;
				}
			}
			WIC_Records::record_answer( $uid, $cid, $sid, self::correct_answer( $q ), array() );
		}
		return $n;
	}

	/** Give the current run a believable history: first and last access, time spent, answer times. */
	private static function date_run( $uid, $cid, $start_mysql, $end_mysql, $pct ) {
		global $wpdb;
		$run = WIC_Records::current_run( $uid, $cid );
		$sec = (int) round( WIC_Content::course_seconds( $cid ) * max( 5, $pct ) / 100 * ( mt_rand( 80, 140 ) / 100 ) );
		$wpdb->update(
			wic_table( 'positions' ),
			array( 'first_access' => $start_mysql, 'last_access' => $end_mysql, 'time_spent' => $sec ),
			array( 'user_id' => $uid, 'course_id' => $cid, 'run' => $run )
		);
		$a  = self::ts( $start_mysql );
		$b  = max( $a + 60, self::ts( $end_mysql ) );
		$at = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . wic_table( 'attempts' ) . ' WHERE user_id = %d AND course_id = %d AND run = %d ORDER BY id ASC', $uid, $cid, $run ) );
		$k  = 0;
		foreach ( $at as $id ) {
			$t = $a + (int) ( ( $b - $a ) * ( ++$k / ( count( $at ) + 1 ) ) );
			$wpdb->update( wic_table( 'attempts' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', $t ) ), array( 'id' => (int) $id ) );
		}
		return $sec;
	}

	private static function date_assignment( $aid, $assigned_mysql, $due_mysql ) {
		global $wpdb;
		$wpdb->update( wic_table( 'assignments' ), array( 'assigned_at' => $assigned_mysql, 'due_at' => $due_mysql ), array( 'id' => (int) $aid ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . wic_table( 'events' ) . " SET created_at = %s WHERE type = 'assigned' AND object_id = %d", $assigned_mysql, (int) $aid ) );
	}

	/**
	 * One learner, one course, one scenario:
	 * complete | progress | overdue | coming_due | not_started | expired.
	 */
	private static function scenario( $uid, $ckey, $kind, $opt = array() ) {
		global $wpdb;
		$cid = self::cid( $ckey );
		if ( ! $uid || ! $cid ) {
			return 0;
		}
		$due_days = (int) get_post_meta( $cid, '_wic_due_days', true );
		$vendor   = (bool) get_post_meta( $cid, '_wic_vendor_course', true );
		$aid      = WIC_Records::assign( $uid, $cid, isset( $opt['source'] ) ? $opt['source'] : 'rule' );
		if ( ! $aid ) {
			return 0;
		}
		self::$s['assignments'][] = (int) $aid;

		if ( 'complete' === $kind || 'expired' === $kind ) {
			$days = isset( $opt['days'] ) ? $opt['days'] : mt_rand( 15, 355 );
			if ( $vendor && 'complete' === $kind ) {
				$days = min( $days, max( 1, (int) floor( ( time() - strtotime( gmdate( 'Y' ) . '-01-02 UTC' ) ) / DAY_IN_SECONDS ) ) );
			}
			$done_at  = self::ago( $days );
			$assigned = gmdate( 'Y-m-d H:i:s', self::ts( $done_at ) - mt_rand( 5, 25 ) * DAY_IN_SECONDS );
			self::work( $uid, $cid, 100 );
			$res = WIC_Records::evaluate( $uid, $cid );
			if ( empty( $res['complete'] ) ) {
				WIC_Records::record_completion( $uid, $cid, WIC_Records::current_run( $uid, $cid ), isset( $res['score'] ) ? (int) $res['score'] : 100, 0, 'online' );
			}
			$start = gmdate( 'Y-m-d H:i:s', self::ts( $done_at ) - mt_rand( 1, 4 ) * DAY_IN_SECONDS );
			$sec   = self::date_run( $uid, $cid, $start, $done_at, 100 );
			self::date_assignment( $aid, $assigned, $due_days ? gmdate( 'Y-m-d 23:59:59', self::ts( $assigned ) + $due_days * DAY_IN_SECONDS ) : null );
			self::date_completion( $uid, $cid, $done_at, $sec, $vendor );
			return $aid;
		}

		$pct = isset( $opt['pct'] ) ? $opt['pct'] : 0;
		switch ( $kind ) {
			case 'progress':
				$due = self::ago( -1 * ( isset( $opt['due_in'] ) ? $opt['due_in'] : mt_rand( 6, 25 ) ) );
				break;
			case 'overdue':
				$due = self::ago( isset( $opt['late'] ) ? $opt['late'] : mt_rand( 3, 40 ) );
				break;
			case 'coming_due':
				$due = self::ago( -1 * ( isset( $opt['due_in'] ) ? $opt['due_in'] : mt_rand( 1, 3 ) ) );
				break;
			default:
				$due = self::ago( -1 * ( isset( $opt['due_in'] ) ? $opt['due_in'] : mt_rand( 10, 28 ) ) );
		}
		$due      = substr( $due, 0, 10 ) . ' 23:59:59';
		$assigned = gmdate( 'Y-m-d H:i:s', self::ts( $due ) - max( 7, $due_days ? $due_days : 30 ) * DAY_IN_SECONDS );
		if ( self::ts( $assigned ) > time() ) {
			$assigned = self::ago( 1 );
		}
		if ( $pct > 0 ) {
			self::work( $uid, $cid, $pct, true );
			$last  = min( time() - HOUR_IN_SECONDS, self::ts( $assigned ) + mt_rand( 1, 20 ) * DAY_IN_SECONDS );
			$first = max( self::ts( $assigned ), $last - mt_rand( 0, 3 ) * DAY_IN_SECONDS );
			self::date_run( $uid, $cid, gmdate( 'Y-m-d H:i:s', $first ), gmdate( 'Y-m-d H:i:s', $last ), $pct );
		}
		self::date_assignment( $aid, $assigned, $vendor ? null : $due );
		if ( $vendor ) {
			// Vendor training is due at the end of the calendar year.
			$wpdb->update( wic_table( 'assignments' ), array( 'due_at' => gmdate( 'Y' ) . '-12-31 23:59:59' ), array( 'id' => (int) $aid ) );
		}
		return $aid;
	}

	/** Move a completion, its certificate and its refreshers back to when it "happened". */
	private static function date_completion( $uid, $cid, $done_at, $sec, $vendor ) {
		global $wpdb;
		$comp = WIC_Records::latest_completion( $uid, $cid );
		if ( ! $comp ) {
			return;
		}
		$shift = time() - self::ts( $done_at );
		$wpdb->update( wic_table( 'completions' ), array( 'completed_at' => $done_at, 'time_spent' => $sec ), array( 'id' => $comp->id ) );
		$months  = (int) get_post_meta( $cid, '_wic_validity_months', true );
		$expires = null;
		if ( $vendor ) {
			$expires = gmdate( 'Y', self::ts( $done_at ) ) . '-12-31 23:59:59';
		} elseif ( $months > 0 ) {
			$expires = gmdate( 'Y-m-d H:i:s', strtotime( $done_at . ' UTC +' . $months . ' months' ) );
		}
		$wpdb->update( wic_table( 'certificates' ), array( 'issued_at' => $done_at, 'expires_at' => $expires ), array( 'completion_id' => $comp->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . wic_table( 'events' ) . " SET created_at = %s, read_at = %s WHERE user_id = %d AND object_id = %d AND type IN ('completed')", $done_at, $done_at, $uid, $cid ) );

		if ( self::has_table( 'boost_rounds' ) ) {
			$rounds = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_rounds' ) . ' WHERE completion_id = %d', $comp->id ) );
			foreach ( (array) $rounds as $r ) {
				$due  = self::ts( $r->due_at ) - $shift;
				$data = array( 'due_at' => gmdate( 'Y-m-d H:i:s', $due ), 'created_at' => $done_at );
				// Refreshers well past their date were mostly taken; the most recent ones are waiting.
				if ( $due < time() - 3 * DAY_IN_SECONDS && mt_rand( 1, 100 ) <= 75 ) {
					$total = max( 1, (int) get_post_meta( $cid, '_wic_boost_count', true ) ? (int) get_post_meta( $cid, '_wic_boost_count', true ) : 3 );
					$ok    = mt_rand( max( 0, $total - 2 ), $total );
					$score = (int) round( $ok / $total * 100 );
					$data += array(
						'status'       => 'done',
						'correct'      => $ok,
						'total'        => $total,
						'score'        => $score,
						'passed'       => $score >= 80 ? 1 : 0,
						'completed_at' => gmdate( 'Y-m-d H:i:s', $due + mt_rand( 0, 2 ) * DAY_IN_SECONDS + HOUR_IN_SECONDS ),
					);
				}
				$wpdb->update( wic_table( 'boost_rounds' ), $data, array( 'id' => $r->id ) );
			}
			$items = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . wic_table( 'boost_items' ) . ' WHERE user_id = %d AND course_id = %d', $uid, $cid ) );
			foreach ( (array) $items as $it ) {
				$upd = array();
				if ( ! empty( $it->next_due ) ) {
					$upd['next_due'] = gmdate( 'Y-m-d H:i:s', self::ts( $it->next_due ) - $shift );
				}
				if ( ! empty( $it->last_reviewed ) ) {
					$upd['last_reviewed'] = $done_at;
				}
				if ( $upd ) {
					$wpdb->update( wic_table( 'boost_items' ), $upd, array( 'id' => $it->id ) );
				}
			}
		}
	}

	private static function records() {
		global $wpdb;
		$core = array( 'welcome', 'portal', 'privacy', 'service' );

		// Leadership: complete almost everything, some time ago.
		foreach ( array( 'morgan', 'priya', 'dana', 'luis', 'grace', 'tom' ) as $k ) {
			foreach ( $core as $c ) {
				if ( 'service' === $c && in_array( $k, array( 'morgan' ), true ) ) {
					continue;
				}
				self::scenario( self::uid( $k ), $c, 'complete', array( 'days' => mt_rand( 60, 320 ) ) );
			}
		}
		self::scenario( self::uid( 'dana' ), 'interpreter', 'complete', array( 'days' => 95 ) );
		self::scenario( self::uid( 'grace' ), 'interpreter', 'complete', array( 'days' => 140 ) );

		// The staff persona tells a clear story: finished, in progress, and one overdue.
		$sam = self::uid( 'sam' );
		self::scenario( $sam, 'welcome', 'complete', array( 'days' => 228 ) );
		self::scenario( $sam, 'portal', 'complete', array( 'days' => 225 ) );
		self::scenario( $sam, 'privacy', 'complete', array( 'days' => 32 ) );
		self::scenario( $sam, 'service', 'progress', array( 'pct' => 60, 'due_in' => 12 ) );
		self::scenario( $sam, 'interpreter', 'overdue', array( 'pct' => 25, 'late' => 9, 'source' => 'manual' ) );

		// New starters: their first week.
		foreach ( array( 'aisha' => 3, 'ben' => 6, 'chloe' => 2 ) as $k => $d ) {
			$u = self::uid( $k );
			self::scenario( $u, 'portal', 'complete', array( 'days' => max( 1, $d - 1 ) ) );
			self::scenario( $u, 'welcome', 'progress', array( 'pct' => mt_rand( 30, 70 ), 'due_in' => 14 - $d ) );
			self::scenario( $u, 'privacy', 'not_started', array( 'due_in' => 30 - $d ) );
			self::scenario( $u, 'service', 'not_started', array( 'due_in' => 30 - $d ) );
		}

		// Everyone else: a realistic spread, deterministic from the seed.
		$rest = array( 'maria', 'hannah', 'kevin', 'jamal', 'olivia', 'rosa', 'tyler', 'nadia', 'emily', 'marcus', 'lily', 'diego', 'sarah', 'andre', 'megan', 'ruth', 'noah', 'kim', 'gary' );
		foreach ( $rest as $k ) {
			$u     = self::uid( $k );
			$group = wic_user_group( $u );
			foreach ( array( 'welcome', 'portal', 'privacy', 'service', 'interpreter' ) as $c ) {
				if ( 'service' === $c && 'intern' === $group ) {
					continue;
				}
				if ( 'interpreter' === $c && ( 'kevin' === $k || mt_rand( 1, 100 ) > 40 ) ) {
					continue; // Kevin's interpreter course is the "not applicable" example below.
				}
				if ( 'privacy' === $c && in_array( $k, array( 'marcus', 'ruth' ), true ) ) {
					continue; // These two carry the expired examples below.
				}
				$r = mt_rand( 1, 100 );
				if ( 'welcome' === $c || 'portal' === $c ) {
					$r = min( $r, 70 ); // Orientation is mostly done.
				}
				if ( $r <= 58 ) {
					self::scenario( $u, $c, 'complete' );
				} elseif ( $r <= 72 ) {
					self::scenario( $u, $c, 'progress', array( 'pct' => mt_rand( 15, 85 ) ) );
				} elseif ( $r <= 84 ) {
					self::scenario( $u, $c, 'overdue', array( 'pct' => mt_rand( 0, 60 ) ) );
				} elseif ( $r <= 90 ) {
					self::scenario( $u, $c, 'coming_due', array( 'pct' => mt_rand( 0, 40 ) ) );
				} else {
					self::scenario( $u, $c, 'not_started' );
				}
			}
		}

		// Two privacy completions older than the 12-month validity, so "Expired" appears.
		foreach ( array( 'marcus', 'ruth' ) as $k ) {
			self::scenario( self::uid( $k ), 'privacy', 'expired', array( 'days' => mt_rand( 380, 420 ) ) );
		}

		// One extension with a reason, one "not applicable" with a reason.
		$extended = false;
		foreach ( array( 'olivia', 'nadia', 'andre', 'hannah', 'lily', 'noah' ) as $k ) {
			foreach ( WIC_Records::user_assignments( self::uid( $k ) ) as $a ) {
				if ( 'overdue' === $a['status'] ) {
					WIC_Records::extend_assignment( $a['assignment_id'], self::ago( -14, 23 ), 'Extended by supervisor: on leave for two weeks (demo)' );
					$extended = true;
					break 2;
				}
			}
		}
		if ( ! $extended ) {
			$aid = self::scenario( self::uid( 'olivia' ), 'interpreter', 'overdue', array( 'pct' => 30, 'late' => 6 ) );
			if ( $aid ) {
				WIC_Records::extend_assignment( $aid, self::ago( -14, 23 ), 'Extended by supervisor: on leave for two weeks (demo)' );
			}
		}
		if ( self::has_table( 'assignment_na' ) ) {
			$aid = self::scenario( self::uid( 'kevin' ), 'interpreter', 'not_started' );
			if ( $aid ) {
				$wpdb->insert(
					wic_table( 'assignment_na' ),
					array(
						'assignment_id' => $aid,
						'reason'        => 'Works in the back office and does not see participants (demo)',
						'marked_by'     => self::uid( 'dana' ),
						'marked_at'     => self::ago( 20 ),
					)
				);
			}
		}

		// The second wave of courses, with history spread over the last twelve months.
		if ( self::cid( 'safety' ) ) {
			$lead = array( 'morgan', 'priya', 'dana', 'luis', 'grace', 'tom' );
			foreach ( $lead as $k ) {
				self::scenario( self::uid( $k ), 'safety', 'complete', array( 'days' => mt_rand( 40, 330 ) ) );
				if ( 'morgan' !== $k && 'priya' !== $k ) {
					self::scenario( self::uid( $k ), 'phone', 'complete', array( 'days' => mt_rand( 60, 350 ) ) );
				}
			}
			// Supervisors' own course: one done early, one mid-way, one late, one not yet due.
			self::scenario( self::uid( 'dana' ), 'dashboards', 'complete', array( 'days' => 70 ) );
			self::scenario( self::uid( 'grace' ), 'dashboards', 'complete', array( 'days' => 150 ) );
			self::scenario( self::uid( 'luis' ), 'dashboards', 'progress', array( 'pct' => 50, 'due_in' => 9 ) );
			self::scenario( self::uid( 'tom' ), 'dashboards', 'overdue', array( 'pct' => 30, 'late' => 12, 'source' => 'manual' ) );
			self::scenario( self::uid( 'priya' ), 'dashboards', 'coming_due', array( 'due_in' => 3 ) );

			self::scenario( $sam, 'phone', 'complete', array( 'days' => 150 ) );
			self::scenario( $sam, 'safety', 'complete', array( 'days' => 300 ) );
			self::scenario( $sam, 'docs', 'coming_due', array( 'due_in' => 2, 'source' => 'manual' ) );
			self::scenario( $sam, 'humility', 'progress', array( 'pct' => 35, 'due_in' => 25, 'source' => 'manual' ) );

			foreach ( array( 'aisha', 'ben', 'chloe' ) as $k ) {
				self::scenario( self::uid( $k ), 'safety', 'not_started', array( 'due_in' => 28 ) );
				self::scenario( self::uid( $k ), 'phone', 'not_started', array( 'due_in' => 20 ) );
			}

			foreach ( $rest as $k ) {
				$u     = self::uid( $k );
				$group = wic_user_group( $u );
				$list  = array( 'safety' );
				if ( 'intern' !== $group ) {
					$list[] = 'phone';
				}
				if ( mt_rand( 1, 100 ) <= 50 ) {
					$list[] = 'docs';
				}
				if ( mt_rand( 1, 100 ) <= 35 ) {
					$list[] = 'humility';
				}
				foreach ( $list as $c ) {
					if ( 'safety' === $c && 'kim' === $k ) {
						continue; // Kim carries the expired safety example below.
					}
					$r = mt_rand( 1, 100 );
					if ( $r <= 55 ) {
						self::scenario( $u, $c, 'complete', array( 'days' => mt_rand( 15, 360 ) ) );
					} elseif ( $r <= 70 ) {
						self::scenario( $u, $c, 'progress', array( 'pct' => mt_rand( 15, 85 ), 'source' => 'manual' ) );
					} elseif ( $r <= 82 ) {
						self::scenario( $u, $c, 'overdue', array( 'pct' => mt_rand( 0, 60 ), 'source' => 'manual' ) );
					} elseif ( $r <= 90 ) {
						self::scenario( $u, $c, 'coming_due', array( 'pct' => mt_rand( 0, 40 ), 'source' => 'manual' ) );
					} else {
						self::scenario( $u, $c, 'not_started', array( 'source' => 'manual' ) );
					}
				}
			}
			self::scenario( self::uid( 'kim' ), 'safety', 'expired', array( 'days' => 395 ) );
		}

		// Vendors: one store finished this year, the persona store part-way through.
		if ( self::cid( 'vendor' ) ) {
			self::scenario( self::uid( 'pat' ), 'vendor', 'complete', array( 'days' => 60, 'source' => 'vendor' ) );
			self::scenario( self::uid( 'alex' ), 'vendor', 'progress', array( 'pct' => 40, 'source' => 'vendor' ) );
		}

		// A few bookmarks and notes for the staff persona.
		if ( self::has_table( 'notes' ) ) {
			$notes = array(
				array( 'service', 's_listen', 1, 'Try reflecting back at the desk this week.' ),
				array( 'privacy', 'p_spaces', 1, 'Ask Dana where the secure bin is at Riverside.' ),
				array( 'welcome', 'w_help', 0, 'Library → Procedures has the opening checklist.' ),
			);
			foreach ( $notes as $n ) {
				if ( ! isset( self::$s['slides'][ $n[1] ] ) ) {
					continue;
				}
				$wpdb->insert(
					wic_table( 'notes' ),
					array(
						'user_id'    => $sam,
						'course_id'  => self::cid( $n[0] ),
						'slide_id'   => self::$s['slides'][ $n[1] ],
						'bookmark'   => $n[2],
						'note'       => $n[3],
						'created_at' => self::ago( mt_rand( 5, 40 ) ),
						'updated_at' => self::ago( mt_rand( 1, 4 ) ),
					)
				);
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Other modules                                                      */
	/* ------------------------------------------------------------------ */

	private static function modules() {
		self::requirements();
		self::rules();
		self::forms();
		self::sessions();
		self::path();
		self::competency();
		self::external();
		self::library();
		self::news();
		self::vendor_resources();
		self::misc();
		self::more_modules();
	}

	/**
	 * The second wave of activity: another form, more sessions, a supervisor path, a second
	 * checklist, more outside training, documents, messages, reference tables, news, FAQs
	 * and notifications — so every list and report in the portal has depth.
	 */
	private static function more_modules() {
		global $wpdb;
		$staff = self::active_staff();

		// A fourth form, signed by most people over the year.
		if ( post_type_exists( 'wic_form' ) && self::has_table( 'signatures' ) ) {
			$fid = self::post( 'wic_form', 'Workstation Safety Acknowledgement (Sample)', '<p><strong>Sample form for demonstration.</strong> Your agency supplies the actual wording of its workstation safety acknowledgement.</p><p>By signing below you confirm that you have read this form, understood it, and will follow it. Your signature, the date and the version of the form are kept with your training record.</p>' );
			update_post_meta( $fid, '_wic_form_version', 1 );
			update_post_meta( $fid, '_wic_form_groups', array( 'staff', 'intern' ) );
			update_post_meta( $fid, '_wic_form_roles', array() );
			update_post_meta( $fid, '_wic_form_agency', 0 );
			self::$s['forms']['workstation'] = $fid;
			foreach ( $staff as $k => $uid ) {
				if ( 'sam' === $k || mt_rand( 1, 100 ) > 75 ) {
					continue;
				}
				$u = get_userdata( $uid );
				$wpdb->insert(
					wic_table( 'signatures' ),
					array(
						'user_id'      => $uid,
						'form_id'      => $fid,
						'form_version' => 1,
						'form_title'   => get_the_title( $fid ),
						'signed_name'  => $u ? $u->display_name : '',
						'signed_at'    => self::ago( mt_rand( 5, 300 ) ),
						'ip'           => '192.0.2.' . mt_rand( 10, 200 ),
						'course_id'    => 0,
					)
				);
			}
		}

		// Four more sessions: two past with attendance, two upcoming (one online).
		if ( post_type_exists( 'wic_session' ) && self::has_table( 'roster' ) ) {
			$a = self::session( 'Phone Skills Practice (Sample)', 62, array( '10:00', '12:00' ), 'Northgate Clinic — Training Room (Demo)', '', 'grace', 8, self::cid( 'phone' ) );
			self::roster( $a, array( 'tyler', 'nadia', 'emily', 'marcus', 'lily', 'diego' ), 'attended', self::uid( 'grace' ), 75 );
			self::roster( $a, array( 'jamal' ), 'no_show', self::uid( 'grace' ), 75 );

			$b = self::session( 'Safety Walk-through — Lakeside (Sample)', 110, array( '08:30', '09:30' ), 'Lakeside Clinic — Front Entrance (Demo)', '', 'tom', 12, self::cid( 'safety' ) );
			self::roster( $b, array( 'sarah', 'andre', 'megan', 'ruth', 'noah', 'kim' ), 'attended', self::uid( 'tom' ), 120 );

			$c = self::session( 'Supervisor Dashboards Clinic — Online (Sample)', -5, array( '14:00', '15:00' ), 'Online', 'https://example.invalid/demo-dashboards-session', 'morgan', 15, self::cid( 'dashboards' ) );
			self::roster( $c, array( 'dana', 'luis', 'grace', 'tom', 'priya' ), 'booked', 0, 4 );

			$d = self::session( 'Cultural Humility Conversation Circle (Sample)', -23, array( '13:30', '15:00' ), 'Hillcrest Clinic — Community Room (Demo)', '', 'tom', 10, self::cid( 'humility' ) );
			self::roster( $d, array( 'ruth', 'noah', 'kim', 'sam', 'olivia' ), 'booked', 0, 6 );
			self::$s['session_ids']['dashboards'] = $c;
		}

		// A second learning path for new supervisors.
		if ( post_type_exists( 'wic_path' ) && class_exists( 'WIC_Paths' ) && self::cid( 'dashboards' ) ) {
			$f  = self::$s['forms'];
			$ss = isset( self::$s['session_ids'] ) ? self::$s['session_ids'] : array();
			$pid = self::post( 'wic_path', 'Supervisor Onboarding (Sample)', '<p>Sample learning path for demonstration: the order a new supervisor works through their first month in the role.</p>' );
			update_post_meta(
				$pid,
				'_wic_phases',
				array(
					array( 'name' => 'Week 1 — Your team and your tools', 'steps' => array_values( array_filter( array( 'course:' . self::cid( 'dashboards' ), isset( $f['confidentiality'] ) ? 'form:' . $f['confidentiality'] : '' ) ) ) ),
					array( 'name' => 'Weeks 2–3 — Leading well', 'steps' => array_values( array_filter( array( 'course:' . self::cid( 'humility' ), isset( $ss['dashboards'] ) ? 'session:' . $ss['dashboards'] : '' ) ) ) ),
					array( 'name' => 'Week 4 — Records that hold up', 'steps' => array_values( array_filter( array( 'course:' . self::cid( 'docs' ) ) ) ) ),
				)
			);
			update_post_meta( $pid, '_wic_path_groups', array() );
			update_post_meta( $pid, '_wic_path_roles', array() );
			update_post_meta( $pid, '_wic_path_agency', 0 );
			foreach ( array( 'luis', 'tom' ) as $k ) {
				$before = wp_list_pluck( WIC_Records::user_assignments( self::uid( $k ) ), 'assignment_id' );
				WIC_Paths::assign( $pid, self::uid( $k ) );
				foreach ( WIC_Records::user_assignments( self::uid( $k ) ) as $as ) {
					if ( ! in_array( $as['assignment_id'], $before, true ) ) {
						self::$s['assignments'][] = (int) $as['assignment_id'];
					}
				}
			}
		}

		// A second observed checklist, with a spread of evaluations.
		if ( post_type_exists( 'wic_checklist' ) && self::has_table( 'competency' ) ) {
			$items = array(
				'Answers with the clinic name and own first name',
				'Confirms who they are speaking to using the agency procedure',
				'Takes a message with who, what, when and who it is for',
				'Asks before placing the caller on hold',
				'Summarises the next step before ending the call',
			);
			$cl = self::post( 'wic_checklist', 'Phone Call Handling — Observed (Sample)', '<p>Sample checklist for demonstration. Each state writes its own.</p>' );
			update_post_meta( $cl, '_wic_items', $items );
			update_post_meta( $cl, '_wic_course', 0 );
			update_post_meta( $cl, '_wic_agency_id', 0 );
			$evals = array(
				array( 'tyler', 'grace', array(), 'Clear and friendly; great messages.', 58, '' ),
				array( 'nadia', 'grace', array( 3 => 'not_met' ), 'Placed a caller on hold without asking — practise and re-observe.', 56, '' ),
				array( 'marcus', 'grace', array(), '', 55, 'demo-batch-2' ),
				array( 'lily', 'grace', array(), '', 55, 'demo-batch-2' ),
				array( 'diego', 'grace', array( 4 => 'na' ), '', 55, 'demo-batch-2' ),
				array( 'andre', 'tom', array(), 'Confident on a busy morning.', 21, '' ),
				array( 'sam', 'dana', array(), '', 7, '' ),
			);
			foreach ( $evals as $e ) {
				$out = array();
				foreach ( $items as $i => $it ) {
					$out[] = array( 'item' => $it, 'mark' => isset( $e[2][ $i ] ) ? $e[2][ $i ] : 'met', 'note' => '' );
				}
				$ev = get_userdata( self::uid( $e[1] ) );
				$wpdb->insert(
					wic_table( 'competency' ),
					array(
						'user_id'         => self::uid( $e[0] ),
						'checklist_id'    => $cl,
						'checklist_title' => get_the_title( $cl ),
						'evaluator_id'    => self::uid( $e[1] ),
						'signed_name'     => $ev ? $ev->display_name : '',
						'items'           => wp_json_encode( $out ),
						'result'          => in_array( 'not_met', array_column( $out, 'mark' ), true ) ? 'not_yet' : 'pass',
						'notes'           => $e[3],
						'batch_id'        => $e[5],
						'created_at'      => self::ago( $e[4] ),
					)
				);
			}
		}

		// More outside training, approved and waiting.
		if ( self::has_table( 'external' ) ) {
			$rows = array(
				array( 'hannah', 'De-escalation Skills Workshop (Demo)', 'Regional Training Network (Demo)', 3, 'Continuing education', 210, 'approved' ),
				array( 'jamal', 'Plain Language Writing (Demo)', 'Anytown Community College (Demo)', 2, 'Professional development', 120, 'approved' ),
				array( 'ruth', 'Workplace First Aid (Demo)', 'Hilltown Adult Education (Demo)', 4, 'Safety', 45, 'approved' ),
				array( 'kim', 'Customer Experience Webinar (Demo)', 'Demo Webinar Series', 1, 'Customer service', 11, 'pending' ),
				array( 'rosa', 'Time Management Basics (Demo)', 'Anytown Library (Demo)', 1.5, 'Professional development', 3, 'pending' ),
			);
			foreach ( $rows as $r ) {
				$approved = 'approved' === $r[6];
				$wpdb->insert(
					wic_table( 'external' ),
					array(
						'user_id'         => self::uid( $r[0] ),
						'title'           => $r[1],
						'provider'        => $r[2],
						'hours'           => $r[3],
						'credit_type'     => $r[4],
						'completed_on'    => gmdate( 'Y-m-d', time() - $r[5] * DAY_IN_SECONDS ),
						'certificate_url' => '',
						'attachment_id'   => 0,
						'status'          => $r[6],
						'source'          => 'self',
						'reviewed_by'     => $approved ? self::uid( 'dana' ) : 0,
						'reviewed_at'     => $approved ? self::ago( $r[5] - 2 ) : null,
						'review_note'     => $approved ? 'Certificate seen (demo).' : '',
						'created_at'      => self::ago( max( 0, $r[5] - 1 ) ),
					)
				);
			}
		}

		// Eight more library documents in more categories, two with version history.
		if ( post_type_exists( 'wic_doc' ) ) {
			$cats = array(
				'comm'   => self::term( 'Communication (Sample)', 'wic_doc_cat' ),
				'safety' => self::term( 'Safety (Sample)', 'wic_doc_cat' ),
				'sup'    => self::term( 'Supervisor toolkit (Sample)', 'wic_doc_cat' ),
				'quick'  => self::term( 'Quick references (Sample)', 'wic_doc_cat' ),
			);
			$defs = array(
				array( 'Phone Message Template (Sample)', 'comm', 'phone-message-template.html', 'A fill-in template for messages that the next person can act on.', false, 'phone' ),
				array( 'Before You Send an Email — Checklist (Sample)', 'comm', 'email-checklist.html', 'Five checks before you press send.', true, 'phone' ),
				array( 'Building Safety Walk-through (Sample)', 'safety', 'building-safety-walk.html', 'What to find in your first week: exits, the plan, alarms and equipment.', true, 'safety' ),
				array( 'Reporting a Hazard or Near Miss (Sample)', 'safety', 'hazard-report-guide.html', 'The steps to make an area safe and report it.', false, '' ),
				array( 'Supervisor Weekly Ten-Minute Routine (Sample)', 'sup', 'supervisor-weekly-routine.html', 'A short weekly routine for keeping your team on track.', false, 'dashboards' ),
				array( 'Reading the Compliance Report (Sample)', 'sup', 'reading-the-compliance-report.html', 'How to read and print the compliance view.', false, '' ),
				array( 'Writing Useful Notes — Quick Tips (Sample)', 'quick', 'note-writing-tips.html', 'Four habits that make any note more useful.', false, 'docs' ),
				array( 'Welcoming Every Visitor (Sample)', 'comm', 'welcoming-every-visitor.html', 'Asking rather than assuming, at the desk and on the phone.', false, 'humility' ),
			);
			$new = array();
			foreach ( $defs as $d ) {
				$id = self::post( 'wic_doc', $d[0], '<p>' . esc_html( $d[3] ) . ' Sample document for demonstration.</p>' );
				if ( ! $id ) {
					continue;
				}
				if ( $cats[ $d[1] ] ) {
					wp_set_object_terms( $id, array( $cats[ $d[1] ] ), 'wic_doc_cat' );
				}
				$versions = array( array( 'label' => 'Version 1', 'url' => self::doc_url( $d[2] ), 'attachment' => 0, 'date' => self::ago( mt_rand( 120, 330 ) ), 'note' => 'First issue (demo)' ) );
				if ( $d[4] ) {
					$versions[] = array( 'label' => 'Version 2', 'url' => self::doc_url( $d[2] ) . '?v=2', 'attachment' => 0, 'date' => self::ago( mt_rand( 10, 60 ) ), 'note' => 'Wording simplified after staff feedback (demo)' );
				}
				update_post_meta( $id, '_wic_doc_versions', $versions );
				update_post_meta( $id, '_wic_doc_current', count( $versions ) - 1 );
				update_post_meta( $id, '_wic_doc_groups', array() );
				update_post_meta( $id, '_wic_doc_roles', 'sup' === $d[1] ? array( 'wic_supervisor', 'wic_admin', 'wic_local_admin' ) : array() );
				$new[] = array( $id, count( $versions ) );
				if ( $d[5] && self::cid( $d[5] ) && ! get_post_meta( self::cid( $d[5] ), '_wic_companion_doc', true ) ) {
					update_post_meta( self::cid( $d[5] ), '_wic_companion_doc', $id );
				}
			}
			if ( self::has_table( 'doc_opens' ) ) {
				foreach ( $staff as $uid ) {
					foreach ( $new as $n ) {
						if ( mt_rand( 1, 100 ) <= 35 ) {
							$wpdb->insert( wic_table( 'doc_opens' ), array( 'doc_id' => $n[0], 'user_id' => $uid, 'version' => 'Version ' . $n[1], 'opened_at' => self::ago( mt_rand( 1, 90 ) ) ) );
						}
					}
				}
			}
		}

		// Six more ready-written messages.
		if ( post_type_exists( 'wic_message' ) ) {
			$rem  = self::term( 'Appointment reminders (Sample)', 'wic_message_cat' );
			$fol  = self::term( 'Follow-up (Sample)', 'wic_message_cat' );
			$bring = self::term( 'What to bring (Sample)', 'wic_message_cat' );
			$upd  = self::term( 'Clinic updates (Sample)', 'wic_message_cat' );
			$msgs = array(
				array( 'Appointment Tomorrow — Short Text (Sample)', $rem, 'Hi [first name], see you tomorrow at [time] at [clinic name]. Need to change? Call [clinic phone].' ),
				array( 'Rescheduled Appointment Confirmation (Sample)', $fol, 'Hi [first name], your appointment has moved to [date] at [time] at [clinic name]. Call [clinic phone] with any questions.' ),
				array( 'What to Bring — General (Sample)', $bring, 'Hi [first name], for your visit on [date], please bring [items your agency lists]. Call [clinic phone] if you are not sure.' ),
				array( 'Clinic Closed for a Holiday (Sample)', $upd, 'Hi [first name], [clinic name] will be closed on [date]. If your appointment was that day, we will call you to find a new time.' ),
				array( 'Running Late — Waiting Room Update (Sample)', $upd, 'Hi [first name], we are running about [minutes] minutes behind today. Thank you for your patience — we will see you as soon as we can.' ),
				array( 'Interpreter Arranged (Sample)', $fol, 'Hi [first name], we have arranged an interpreter for your visit on [date] at [time]. See you at [clinic name].' ),
			);
			foreach ( $msgs as $m ) {
				$id = self::post( 'wic_message', $m[0], wpautop( esc_html( $m[2] ) ) );
				if ( $id && $m[1] ) {
					wp_set_object_terms( $id, array( $m[1] ), 'wic_message_cat' );
				}
			}
		}

		// Two more reference tables.
		if ( post_type_exists( 'wic_reference' ) ) {
			$contacts = array(
				array( 'Training questions', 'Your supervisor', 'In person or by portal message' ),
				array( 'Portal sign-in problems', 'Agency training office (demo)', '(555) 010-0190' ),
				array( 'Building or safety issue', 'Clinic lead on duty', 'Clinic phone' ),
				array( 'Interpreter request', 'Front desk', 'Using the agency procedure' ),
				array( 'Vendor questions', 'Vendor helpline (demo)', '(555) 010-0199' ),
			);
			$rows = '';
			foreach ( $contacts as $r ) {
				$rows .= '<tr><td>' . esc_html( $r[0] ) . '</td><td>' . esc_html( $r[1] ) . '</td><td>' . esc_html( $r[2] ) . '</td></tr>';
			}
			self::post( 'wic_reference', 'Who to Contact — Quick Directory (Demo)', '<table><thead><tr><th>For</th><th>Contact</th><th>How</th></tr></thead><tbody>' . $rows . '</tbody></table><p>Demonstration data — every number here is fictional.</p>' );

			$rows = '';
			foreach ( self::$s['courses'] as $ck => $cid ) {
				$mins    = max( 1, (int) round( WIC_Content::course_seconds( $cid ) / 60 ) );
				$credit  = get_post_meta( $cid, '_wic_credit_type', true );
				$hours   = get_post_meta( $cid, '_wic_credit_hours', true );
				$months  = (int) get_post_meta( $cid, '_wic_validity_months', true );
				$renew   = get_post_meta( $cid, '_wic_vendor_course', true ) ? 'Each calendar year' : ( $months ? sprintf( 'Every %d months', $months ) : 'No renewal' );
				$rows   .= '<tr><td>' . esc_html( get_the_title( $cid ) ) . '</td><td>' . esc_html( $mins . ' min' ) . '</td><td>' . esc_html( trim( $credit . ' ' . ( $hours ? '(' . $hours . ' h)' : '' ) ) ) . '</td><td>' . esc_html( $renew ) . '</td></tr>';
			}
			self::post( 'wic_reference', 'Course Catalogue at a Glance (Demo)', '<table><thead><tr><th>Course</th><th>Length</th><th>Credit</th><th>Renewal</th></tr></thead><tbody>' . $rows . '</tbody></table><p>Sample courses only — replace with agency-approved content.</p>' );
		}

		// Six more news posts and six more FAQs.
		if ( post_type_exists( 'wic_news' ) ) {
			$news = array(
				array( 'Five New Sample Courses Added', 2, 'Phone and email etiquette, safety basics, documentation habits, supervisor dashboards and cultural humility are now in the demo catalogue — each with illustrations, narration scripts and a short graded check.' ),
				array( 'Supervisor Onboarding Path Now Available', 8, 'New supervisors get their own learning path: dashboards and tools in week one, leading well in weeks two and three, and records that hold up in week four.' ),
				array( 'Online Session: Supervisor Dashboards Clinic', 10, 'A one-hour online session walks supervisors through the team, overdue and compliance views. Book from Sessions; the video link appears on the session page once you are booked.' ),
				array( 'Library Update: Safety and Communication Documents', 25, 'New quick references are in the Library, including a building safety walk-through, a phone message template and an email checklist. The current version of each is clearly marked.' ),
				array( 'Spanish Course Wording in Review', 33, 'Some sample slides now carry Spanish wording. Where the English changes, the Spanish is automatically flagged for review so nothing goes out of date unnoticed.' ),
				array( 'Certificates Can Be Checked by Anyone', 47, 'Every certificate carries a code and a QR code. Inspectors, employers and colleagues can confirm a certificate is genuine on the public verification page — no account needed.' ),
			);
			foreach ( $news as $n ) {
				self::post( 'wic_news', $n[0], '<p>' . esc_html( $n[2] ) . '</p>', array( 'post_date_gmt' => self::ago( $n[1] ), 'post_date' => get_date_from_gmt( self::ago( $n[1] ) ) ) );
			}
		}
		if ( post_type_exists( 'wic_faq' ) ) {
			$faqs = array(
				array( 'How do I book a classroom or online session?', 'Open Sessions, choose a session and select Book. If it is full you join the waiting list and move up automatically when a place opens.' ),
				array( 'What are refreshers?', 'Short quizzes that follow a course at set intervals, drawn from the questions people find hardest. They never change your original completion or certificate.' ),
				array( 'Can I record training I did somewhere else?', 'Yes. Use Outside training to add the course name, provider, hours and date, and attach a certificate if you have one. Your supervisor approves it and it appears on your transcript.' ),
				array( 'Why does a course say "Coming due"?', 'It is due within the next few days. Finish it before the due date and it will show as Complete; after the due date it shows as Overdue.' ),
				array( 'What does "Not applicable" mean on my training list?', 'Your supervisor has marked the course as not applying to your role, with a reason. It no longer counts as outstanding.' ),
				array( 'Where can I find my notes and bookmarks?', 'Open My notes in the portal. Every bookmark and note you made in a lesson is listed there with a link straight back to the slide.' ),
			);
			$o = 60;
			foreach ( $faqs as $f ) {
				self::post( 'wic_faq', $f[0], '<p>' . esc_html( $f[1] ) . '</p>', array( 'menu_order' => ( $o += 10 ) ) );
			}
		}

		// Notifications that make the bell look lived-in, including the presenter's own.
		$notes = array(
			array( 'dana', 'team_completed', 'Maria Lopez completed "Safety and Emergency Basics at the Clinic (Sample)". (Demo notification)', 1 ),
			array( 'dana', 'approval_waiting', 'Evan Brooks is waiting for you to approve their registration. (Demo notification)', 0 ),
			array( 'luis', 'reminder', 'Reminder: "Using Data Dashboards as a Supervisor (Sample)" is due soon. (Demo notification)', 0 ),
			array( 'tom', 'overdue', '"Using Data Dashboards as a Supervisor (Sample)" is overdue. (Demo notification)', 0 ),
			array( 'sam', 'due_soon', '"Documentation Habits That Help Your Team (Sample)" is due in two days. (Demo notification)', 0 ),
			array( 'sam', 'session', 'You are booked on "Cultural Humility Conversation Circle (Sample)". (Demo notification)', 2 ),
		);
		foreach ( $notes as $n ) {
			if ( self::uid( $n[0] ) ) {
				$id = WIC_Notify::event( self::uid( $n[0] ), $n[1], 0, $n[2], false );
				$wpdb->update( wic_table( 'events' ), array( 'created_at' => self::ago( $n[3] ) ), array( 'id' => $id ) );
			}
		}
		if ( ! empty( self::$s['runner'] ) ) {
			$mine = array(
				array( 'approval_waiting', 'Two registrations are waiting for approval across the agency. (Demo notification)', 0, false ),
				array( 'escalation', 'Fatima Hassan has waited more than 5 days for approval by Tom Becker. (Demo notification)', 1, false ),
				array( 'team_completed', 'Dana Whitfield completed "Using Data Dashboards as a Supervisor (Sample)". (Demo notification)', 4, true ),
				array( 'format_request', 'New accessible-format request: large print, from Ruth Yazzie. (Demo notification)', 2, false ),
				array( 'reminder', '"Using Data Dashboards as a Supervisor (Sample)" is due in 16 days. (Demo notification)', 6, true ),
			);
			foreach ( $mine as $m ) {
				$id = WIC_Notify::event( (int) self::$s['runner'], $m[0], 0, $m[1], false );
				$at = self::ago( $m[2] );
				$wpdb->update( wic_table( 'events' ), array( 'created_at' => $at, 'sent_at' => $at, 'send_result' => 'demo', 'read_at' => $m[3] ? $at : null ), array( 'id' => $id ) );
			}
		}
	}

	private static function requirements() {
		if ( ! post_type_exists( 'wic_requirement' ) ) {
			return;
		}
		$defs = array(
			array( 'Annual privacy training (Sample)', 12, array( 'privacy' ) ),
			array( 'New staff orientation (Sample)', 0, array( 'welcome', 'portal' ) ),
			array( 'Customer service standards (Sample)', 24, array( 'service' ) ),
			array( 'Workplace safety awareness (Sample)', 12, array( 'safety' ) ),
			// Role-based: applies to supervisors only.
			array( 'Supervisor data skills (Sample)', 0, array( 'dashboards' ), array(), array( 'wic_supervisor' ) ),
		);
		foreach ( $defs as $d ) {
			if ( ! self::cid( $d[2][0] ) ) {
				continue;
			}
			$id = self::post( 'wic_requirement', $d[0], '<p>Sample requirement for the demonstration. The citation is to be completed by the agency.</p>' );
			update_post_meta( $id, '_wic_req_source', '' );
			update_post_meta( $id, '_wic_req_months', (string) $d[1] );
			update_post_meta( $id, '_wic_req_groups', isset( $d[3] ) ? $d[3] : array( 'staff', 'intern' ) );
			update_post_meta( $id, '_wic_req_roles', isset( $d[4] ) ? $d[4] : array() );
			update_post_meta( $id, '_wic_req_clinics', array() );
			foreach ( $d[2] as $ck ) {
				$cid  = self::cid( $ck );
				$list = array_filter( array_map( 'intval', (array) get_post_meta( $cid, '_wic_requirements', true ) ) );
				$list[] = $id;
				update_post_meta( $cid, '_wic_requirements', array_values( array_unique( $list ) ) );
			}
		}
	}

	private static function rules() {
		global $wpdb;
		if ( ! self::has_table( 'assign_rules' ) ) {
			return;
		}
		$rows = array(
			array( 'portal', '', '', '', 7 ),
			array( 'privacy', 'intern', '', '', 30 ),
			array( 'interpreter', 'staff', (string) self::clinic_id( 'c5' ), '', 45 ),
		);
		foreach ( $rows as $r ) {
			$wpdb->insert(
				wic_table( 'assign_rules' ),
				array(
					'course_id'  => self::cid( $r[0] ),
					'grp'        => $r[1],
					'clinic'     => $r[2],
					'role'       => $r[3],
					'due_days'   => $r[4],
					'active'     => 1,
					'created_by' => self::uid( 'morgan' ),
					'created_at' => self::ago( mt_rand( 100, 200 ) ),
				)
			);
			self::$s['rules'][] = (int) $wpdb->insert_id;
		}
	}

	private static function active_staff() {
		$out = array();
		foreach ( array( 'dana', 'luis', 'grace', 'tom', 'priya', 'sam', 'maria', 'hannah', 'kevin', 'jamal', 'olivia', 'rosa', 'tyler', 'nadia', 'emily', 'marcus', 'lily', 'diego', 'sarah', 'andre', 'megan', 'ruth', 'noah', 'kim' ) as $k ) {
			if ( self::uid( $k ) ) {
				$out[ $k ] = self::uid( $k );
			}
		}
		return $out;
	}

	private static function forms() {
		global $wpdb;
		if ( ! post_type_exists( 'wic_form' ) || ! self::has_table( 'signatures' ) ) {
			return;
		}
		$body = function ( $what ) {
			return '<p><strong>Sample form for demonstration.</strong> Your agency supplies the actual wording of its ' . $what . '.</p><p>By signing below you confirm that you have read this form, understood it, and will follow it. Your signature, the date and the version of the form are kept with your training record.</p>';
		};
		$defs = array(
			'confidentiality' => array( 'Confidentiality Acknowledgement (Sample)', $body( 'confidentiality acknowledgement' ) ),
			'equipment'       => array( 'Equipment Use Agreement (Sample)', $body( 'equipment use agreement' ) ),
			'civil_rights'    => array( 'Civil Rights Acknowledgement (Sample)', $body( 'civil rights acknowledgement' ) ),
		);
		foreach ( $defs as $k => $d ) {
			$id = self::post( 'wic_form', $d[0], $d[1] );
			update_post_meta( $id, '_wic_form_version', 1 );
			update_post_meta( $id, '_wic_form_groups', 'equipment' === $k ? array( 'staff' ) : array( 'staff', 'intern' ) );
			update_post_meta( $id, '_wic_form_roles', array() );
			update_post_meta( $id, '_wic_form_agency', 0 );
			self::$s['forms'][ $k ] = $id;
		}
		$sign = function ( $uid, $fid, $version, $when ) use ( $wpdb ) {
			$u = get_userdata( $uid );
			$wpdb->insert(
				wic_table( 'signatures' ),
				array(
					'user_id'      => $uid,
					'form_id'      => $fid,
					'form_version' => $version,
					'form_title'   => get_the_title( $fid ),
					'signed_name'  => $u ? $u->display_name : '',
					'signed_at'    => $when,
					'ip'           => '192.0.2.' . mt_rand( 10, 200 ), // Documentation address range.
					'course_id'    => 0,
				)
			);
		};
		foreach ( self::active_staff() as $k => $uid ) {
			$reg = strtotime( get_userdata( $uid )->user_registered . ' UTC' );
			$at  = gmdate( 'Y-m-d H:i:s', min( time() - DAY_IN_SECONDS, $reg + mt_rand( 1, 10 ) * DAY_IN_SECONDS ) );
			$sign( $uid, self::$s['forms']['confidentiality'], 1, $at );
			if ( mt_rand( 1, 100 ) <= 90 ) {
				$sign( $uid, self::$s['forms']['civil_rights'], 1, $at );
			}
			if ( mt_rand( 1, 100 ) <= 70 && 'intern' !== wic_user_group( $uid ) ) {
				$sign( $uid, self::$s['forms']['equipment'], 1, $at );
			}
		}
		// A new version of the confidentiality form: about half have signed it again.
		update_post_meta( self::$s['forms']['confidentiality'], '_wic_form_version', 2 );
		foreach ( self::active_staff() as $k => $uid ) {
			if ( 'sam' !== $k && mt_rand( 1, 100 ) <= 55 ) {
				$sign( $uid, self::$s['forms']['confidentiality'], 2, self::ago( mt_rand( 1, 9 ) ) );
			}
		}
		foreach ( array( 'aisha', 'ben' ) as $k ) {
			$sign( self::uid( $k ), self::$s['forms']['confidentiality'], 2, self::ago( 1 ) );
		}
	}

	private static function session( $title, $days, $hours, $place, $link, $trainer, $capacity, $course = 0 ) {
		$start = gmdate( 'Y-m-d', current_time( 'timestamp' ) - (int) round( $days * DAY_IN_SECONDS ) ) . ' ' . $hours[0];
		$end   = substr( $start, 0, 10 ) . ' ' . $hours[1];
		$id    = self::post( 'wic_session', $title, '<p>Sample session for demonstration. Bring questions from your own clinic.</p>' );
		update_post_meta( $id, '_wic_start', $start );
		update_post_meta( $id, '_wic_end', $end );
		update_post_meta( $id, '_wic_place', $place );
		update_post_meta( $id, '_wic_link', $link );
		update_post_meta( $id, '_wic_trainer', self::uid( $trainer ) );
		update_post_meta( $id, '_wic_capacity', $capacity );
		update_post_meta( $id, '_wic_course', $course );
		self::$s['sessions'][] = $id;
		return $id;
	}

	private static function roster( $sid, $keys, $status, $marked_by = 0, $days_booked = 10 ) {
		global $wpdb;
		foreach ( $keys as $k ) {
			$uid = self::uid( $k );
			if ( ! $uid ) {
				continue;
			}
			$wpdb->insert(
				wic_table( 'roster' ),
				array(
					'session_id' => $sid,
					'user_id'    => $uid,
					'status'     => $status,
					'booked_at'  => self::ago( $days_booked + mt_rand( 0, 5 ) ),
					'updated_at' => self::ago( max( 0, $days_booked - 3 ) ),
					'marked_by'  => $marked_by,
				)
			);
		}
	}

	private static function sessions() {
		if ( ! post_type_exists( 'wic_session' ) || ! self::has_table( 'roster' ) ) {
			return;
		}
		$s1 = self::session( 'New Staff Orientation Day (Sample)', 20, array( '09:00', '15:00' ), 'Riverside Clinic — Training Room (Demo)', '', 'dana', 12 );
		self::roster( $s1, array( 'maria', 'hannah', 'olivia', 'rosa', 'kevin' ), 'attended', self::uid( 'dana' ), 30 );
		self::roster( $s1, array( 'jamal' ), 'no_show', self::uid( 'dana' ), 30 );

		$s2 = self::session( 'Front Desk Practice Workshop (Sample)', 45, array( '13:00', '16:00' ), 'Maple Street Clinic — Meeting Room (Demo)', '', 'luis', 10 );
		self::roster( $s2, array( 'jamal', 'olivia', 'tyler', 'nadia', 'emily' ), 'attended', self::uid( 'luis' ), 55 );

		$s3 = self::session( 'Customer Service Practice Workshop (Sample)', -9, array( '10:00', '12:30' ), 'Lakeside Clinic — Community Room (Demo)', '', 'tom', 6, self::cid( 'service' ) );
		self::roster( $s3, array( 'sam', 'andre', 'megan', 'noah', 'kim', 'lily' ), 'booked', 0, 6 );
		self::roster( $s3, array( 'diego', 'sarah' ), 'waitlist', 0, 2 );

		$s4 = self::session( 'Working with Interpreters — Live Q&A (Sample)', -16, array( '15:00', '16:00' ), 'Online', 'https://example.invalid/demo-meeting', 'grace', 20, self::cid( 'interpreter' ) );
		self::roster( $s4, array( 'aisha', 'chloe', 'ben', 'marcus' ), 'booked', 0, 3 );
		self::$s['session_ids'] = array( 'orientation' => $s1, 'workshop' => $s3 );
	}

	private static function path() {
		if ( ! post_type_exists( 'wic_path' ) || ! class_exists( 'WIC_Paths' ) ) {
			return;
		}
		$f  = self::$s['forms'];
		$ss = isset( self::$s['session_ids'] ) ? self::$s['session_ids'] : array();
		$id = self::post( 'wic_path', 'New Staff: First 30 Days (Sample)', '<p>Sample learning path for demonstration: the order a new starter works through their first month.</p>' );
		$phases = array(
			array(
				'name'  => 'Week 1 — Getting started',
				'steps' => array_values( array_filter( array( 'course:' . self::cid( 'portal' ), 'course:' . self::cid( 'welcome' ), isset( $f['confidentiality'] ) ? 'form:' . $f['confidentiality'] : '' ) ) ),
			),
			array(
				'name'  => 'Weeks 2–3 — Working with participants',
				'steps' => array_values( array_filter( array( 'course:' . self::cid( 'service' ), 'course:' . self::cid( 'privacy' ), isset( $ss['workshop'] ) ? 'session:' . $ss['workshop'] : '' ) ) ),
			),
			array(
				'name'  => 'Week 4 — Rounding out',
				'steps' => array_values( array_filter( array( 'course:' . self::cid( 'interpreter' ), isset( $f['civil_rights'] ) ? 'form:' . $f['civil_rights'] : '' ) ) ),
			),
		);
		update_post_meta( $id, '_wic_phases', $phases );
		update_post_meta( $id, '_wic_path_groups', array() );
		update_post_meta( $id, '_wic_path_roles', array() );
		update_post_meta( $id, '_wic_path_agency', 0 );
		foreach ( array( 'aisha', 'ben', 'chloe' ) as $k ) {
			$before = wp_list_pluck( WIC_Records::user_assignments( self::uid( $k ) ), 'assignment_id' );
			WIC_Paths::assign( $id, self::uid( $k ) );
			foreach ( WIC_Records::user_assignments( self::uid( $k ) ) as $a ) {
				if ( ! in_array( $a['assignment_id'], $before, true ) ) {
					self::$s['assignments'][] = (int) $a['assignment_id'];
				}
			}
		}
	}

	private static function competency() {
		global $wpdb;
		if ( ! post_type_exists( 'wic_checklist' ) || ! self::has_table( 'competency' ) ) {
			return;
		}
		$items = array(
			'Greets the participant and introduces self by name',
			'Confirms identity using the agency procedure',
			'Keeps the screen and paperwork out of public view',
			'Explains what happens next and how long it will take',
			'Checks the participant has no further questions before they leave',
		);
		$id = self::post( 'wic_checklist', 'Front Desk Check-in — Observed (Sample)', '<p>Sample checklist for demonstration. Each state writes its own.</p>' );
		update_post_meta( $id, '_wic_items', $items );
		update_post_meta( $id, '_wic_course', 0 );
		update_post_meta( $id, '_wic_agency_id', 0 );
		$rec = function ( $user, $evaluator, $marks, $notes, $days, $batch = '' ) use ( $wpdb, $id, $items ) {
			$out = array();
			foreach ( $items as $i => $it ) {
				$out[] = array( 'item' => $it, 'mark' => isset( $marks[ $i ] ) ? $marks[ $i ] : 'met', 'note' => '' );
			}
			$result = in_array( 'not_met', array_column( $out, 'mark' ), true ) ? 'not_yet' : 'pass';
			$ev     = get_userdata( self::uid( $evaluator ) );
			$wpdb->insert(
				wic_table( 'competency' ),
				array(
					'user_id'         => self::uid( $user ),
					'checklist_id'    => $id,
					'checklist_title' => get_the_title( $id ),
					'evaluator_id'    => self::uid( $evaluator ),
					'signed_name'     => $ev ? $ev->display_name : '',
					'items'           => wp_json_encode( $out ),
					'result'          => $result,
					'notes'           => $notes,
					'batch_id'        => $batch,
					'created_at'      => self::ago( $days ),
				)
			);
		};
		$rec( 'maria', 'dana', array(), 'Confident and warm with every participant.', 120 );
		$rec( 'hannah', 'dana', array(), '', 64 );
		$rec( 'sam', 'dana', array( 2 => 'not_met' ), 'Left the screen facing the waiting area twice — re-observe next week.', 12 );
		foreach ( array( 'jamal', 'olivia', 'rosa' ) as $k ) {
			$rec( $k, 'luis', 'rosa' === $k ? array( 3 => 'na' ) : array(), '', 40, 'demo-batch-1' );
		}
	}

	private static function external() {
		global $wpdb;
		if ( ! self::has_table( 'external' ) ) {
			return;
		}
		$rows = array(
			array( 'sam', 'Workplace First Aid (Demo)', 'Anytown Community College (Demo)', 4, 'Safety', 150, 'approved' ),
			array( 'maria', 'Customer Service Webinar (Demo)', 'Regional Training Network (Demo)', 1.5, 'Continuing education', 90, 'approved' ),
			array( 'olivia', 'Spreadsheet Basics (Demo)', 'Anytown Library (Demo)', 2, 'Professional development', 8, 'pending' ),
			array( 'tyler', 'State Conference Session (Demo)', 'Demo Conference Organisers', 1, 'Continuing education', 5, 'pending' ),
		);
		foreach ( $rows as $r ) {
			$approved = 'approved' === $r[6];
			$wpdb->insert(
				wic_table( 'external' ),
				array(
					'user_id'         => self::uid( $r[0] ),
					'title'           => $r[1],
					'provider'        => $r[2],
					'hours'           => $r[3],
					'credit_type'     => $r[4],
					'completed_on'    => gmdate( 'Y-m-d', time() - $r[5] * DAY_IN_SECONDS ),
					'certificate_url' => '',
					'attachment_id'   => 0,
					'status'          => $r[6],
					'source'          => 'self',
					'reviewed_by'     => $approved ? self::uid( 'dana' ) : 0,
					'reviewed_at'     => $approved ? self::ago( $r[5] - 3 ) : null,
					'review_note'     => $approved ? 'Certificate seen (demo).' : '',
					'created_at'      => self::ago( $r[5] - 1 ),
				)
			);
		}
	}

	private static function term( $name, $tax ) {
		if ( ! taxonomy_exists( $tax ) ) {
			return 0;
		}
		$t = term_exists( $name, $tax );
		if ( ! $t ) {
			$t = wp_insert_term( $name, $tax );
			if ( is_wp_error( $t ) ) {
				return 0;
			}
			self::$s['terms'][] = array( (int) $t['term_id'], $tax );
		}
		return (int) ( is_array( $t ) ? $t['term_id'] : $t );
	}

	private static function doc_url( $file ) {
		return WIC_DEMO_URL . 'assets/docs/' . $file;
	}

	private static function library() {
		global $wpdb;
		$docs = array();
		if ( post_type_exists( 'wic_doc' ) ) {
			$cats = array(
				'proc'  => self::term( 'Procedures (Sample)', 'wic_doc_cat' ),
				'forms' => self::term( 'Forms & templates (Sample)', 'wic_doc_cat' ),
				'quick' => self::term( 'Quick references (Sample)', 'wic_doc_cat' ),
			);
			$defs = array(
				'opening'   => array( 'Clinic Opening and Closing Checklist (Sample)', 'proc', 'opening-closing-checklist.html', 'A one-page checklist for the start and end of a clinic day.', true ),
				'first'     => array( 'New Starter First-Week Checklist (Sample)', 'forms', 'first-week-checklist.html', 'What to do, read and ask in your first five days.', false ),
				'interp'    => array( 'Requesting an Interpreter — Quick Guide (Sample)', 'quick', 'interpreter-quick-guide.html', 'The four habits for a clear conversation through an interpreter.', false ),
				'privacy'   => array( 'Privacy Do\'s and Don\'ts — Quick Reference (Sample)', 'quick', 'privacy-quick-reference.html', 'Everyday habits for screens, paper and conversations.', false ),
			);
			foreach ( $defs as $k => $d ) {
				$id = self::post( 'wic_doc', $d[0], '<p>' . esc_html( $d[3] ) . ' Sample document for demonstration.</p>' );
				if ( $cats[ $d[1] ] ) {
					wp_set_object_terms( $id, array( $cats[ $d[1] ] ), 'wic_doc_cat' );
				}
				$versions = array(
					array( 'label' => 'Version 1', 'url' => self::doc_url( $d[2] ), 'attachment' => 0, 'date' => self::ago( 200 ), 'note' => 'First issue (demo)' ),
				);
				if ( $d[4] ) {
					$versions[] = array( 'label' => 'Version 2', 'url' => self::doc_url( $d[2] ) . '?v=2', 'attachment' => 0, 'date' => self::ago( 18 ), 'note' => 'Added the end-of-day screen check (demo)' );
				}
				update_post_meta( $id, '_wic_doc_versions', $versions );
				update_post_meta( $id, '_wic_doc_current', count( $versions ) - 1 );
				update_post_meta( $id, '_wic_doc_groups', array() );
				update_post_meta( $id, '_wic_doc_roles', array() );
				$docs[ $k ] = $id;
			}
			if ( self::has_table( 'doc_opens' ) ) {
				foreach ( self::active_staff() as $uid ) {
					foreach ( $docs as $k => $did ) {
						if ( mt_rand( 1, 100 ) <= ( 'opening' === $k ? 75 : 40 ) ) {
							$wpdb->insert( wic_table( 'doc_opens' ), array( 'doc_id' => $did, 'user_id' => $uid, 'version' => 'opening' === $k ? 'Version 2' : 'Version 1', 'opened_at' => self::ago( mt_rand( 1, 60 ) ) ) );
						}
					}
				}
			}
		}
		// Course companion documents and links (#116, #117).
		$companion = array( 'welcome' => 'first', 'interpreter' => 'interp', 'privacy' => 'privacy', 'service' => 'opening' );
		foreach ( $companion as $ck => $dk ) {
			if ( isset( $docs[ $dk ] ) ) {
				update_post_meta( self::cid( $ck ), '_wic_companion_doc', $docs[ $dk ] );
			}
		}
		update_post_meta(
			self::cid( 'service' ),
			'_wic_links',
			array(
				array( 'title' => 'Opening and closing checklist (demo document)', 'url' => self::doc_url( 'opening-closing-checklist.html' ), 'thumb' => '', 'status' => 200, 'checked' => time() - DAY_IN_SECONDS ),
				array( 'title' => 'Privacy quick reference (demo document)', 'url' => self::doc_url( 'privacy-quick-reference.html' ), 'thumb' => '', 'status' => 200, 'checked' => time() - DAY_IN_SECONDS ),
			)
		);

		if ( post_type_exists( 'wic_message' ) ) {
			$rem = self::term( 'Appointment reminders (Sample)', 'wic_message_cat' );
			$fol = self::term( 'Follow-up (Sample)', 'wic_message_cat' );
			$msgs = array(
				array( 'Appointment Reminder — Text Message (Sample)', $rem, 'Hi [first name], this is [clinic name]. A reminder of your appointment on [date] at [time]. Reply or call [clinic phone] if you need to change it.' ),
				array( 'Appointment Reminder — Email (Sample)', $rem, "Hello [first name],\n\nThis is a friendly reminder of your appointment at [clinic name] on [date] at [time]. If you need to change it, please call us on [clinic phone].\n\nThank you,\n[staff name]" ),
				array( 'Missed Appointment Follow-up (Sample)', $fol, 'Hi [first name], we missed you at [clinic name] today. Please call [clinic phone] and we will find a new time that works for you.' ),
				array( 'Welcome After First Visit (Sample)', $fol, 'Hi [first name], thank you for visiting [clinic name] today. If you have any questions before your next visit on [date], call us on [clinic phone].' ),
			);
			foreach ( $msgs as $m ) {
				$id = self::post( 'wic_message', $m[0], wpautop( esc_html( $m[2] ) ) );
				if ( $m[1] ) {
					wp_set_object_terms( $id, array( $m[1] ), 'wic_message_cat' );
				}
			}
		}

		if ( post_type_exists( 'wic_reference' ) && class_exists( 'WIC_Clinics' ) ) {
			$rows = '';
			foreach ( array( 'c1', 'c2', 'c3', 'c4', 'c5', 'c6' ) as $k ) {
				$c = WIC_Clinics::get( self::clinic_id( $k ) );
				if ( $c ) {
					$rows .= '<tr><td>' . esc_html( $c->name ) . '</td><td>' . esc_html( $c->hours ) . '</td><td>' . esc_html( $c->phone ) . '</td></tr>';
				}
			}
			self::post( 'wic_reference', 'Clinic Hours and Phone Numbers (Demo)', '<table><thead><tr><th>Clinic</th><th>Hours</th><th>Phone</th></tr></thead><tbody>' . $rows . '</tbody></table><p>Demonstration data — every clinic here is fictional.</p>' );
		}
	}

	private static function news() {
		if ( post_type_exists( 'wic_news' ) ) {
			$news = array(
				array( 'Welcome to the BrushArt WIC Training Demo', 1, 'This demonstration site shows the WIC staff training platform with a fictional agency: six clinics, around thirty staff and six sample courses. Use the "View as" panel to see the portal as a state administrator, a supervisor, a member of staff, a new starter, an author, a local administrator or a vendor.' ),
				array( 'New: Learning Paths for New Staff', 5, 'New starters now get a phased path — Week 1, Weeks 2–3 and Week 4 — that brings their courses, signed forms and classroom sessions together in one place, with a named mentor alongside.' ),
				array( 'Refresher Quizzes After Each Course', 12, 'A short refresher now follows each completed course at set intervals, drawn from the questions people found hardest. Refreshers never change your original completion or certificate.' ),
				array( 'Customer Service Workshop — Places Available', 18, 'A practice workshop on front-desk conversations is coming up at Lakeside Clinic. Book a place from Sessions in the training portal; if it is full you will join the waiting list automatically.' ),
			);
			foreach ( $news as $n ) {
				self::post( 'wic_news', $n[0], '<p>' . esc_html( $n[2] ) . '</p>', array( 'post_date_gmt' => self::ago( $n[1] ), 'post_date' => get_date_from_gmt( self::ago( $n[1] ) ) ) );
			}
		}
		if ( post_type_exists( 'wic_faq' ) ) {
			$faqs = array(
				array( 'I forgot my password. What do I do?', 'Use "Lost your password?" on the sign-in page. You will receive an email with a link to set a new one. If your account has been closed, contact your agency administrator.' ),
				array( 'Where do I find my certificates?', 'Open Certificates in the training portal. Every certificate you have earned is kept there permanently, and you can print it at any time.' ),
				array( 'What happens if I close a lesson halfway through?', 'Your place is saved on the server each time you move to a new slide. Use Continue on your Home page to pick up where you left off, on any computer.' ),
				array( 'Can I make the text bigger or change the language?', 'Yes. Use the text-size buttons at the top of the portal or the lesson player. Where a course offers another language, choose it from the language menu in the player.' ),
				array( 'Who approves my registration?', 'The supervisor you chose when you registered. If you were not sure who your supervisor is, an administrator will approve it instead.' ),
				array( 'How can someone check my certificate is genuine?', 'Every certificate carries a verification code and QR code. Anyone can enter the code on the public Verify a Certificate page — no account needed.' ),
			);
			$o = 0;
			foreach ( $faqs as $f ) {
				self::post( 'wic_faq', $f[0], '<p>' . esc_html( $f[1] ) . '</p>', array( 'menu_order' => ( $o += 10 ) ) );
			}
		}
	}

	private static function vendor_resources() {
		if ( ! post_type_exists( 'wic_vendor_res' ) ) {
			return;
		}
		$r = array(
			array( 'Store Checklist for the New Year (Sample)', 'A reminder of what to check each January. Your agency supplies the actual vendor requirements.', 'opening-closing-checklist.html' ),
			array( 'Training New Cashiers (Sample)', 'How to make sure every new cashier completes their vendor training before working the register.', 'first-week-checklist.html' ),
		);
		foreach ( $r as $x ) {
			$id = self::post( 'wic_vendor_res', $x[0], '<p>' . esc_html( $x[1] ) . '</p>' );
			update_post_meta( $id, '_wic_file_url', self::doc_url( $x[2] ) );
		}
	}

	private static function misc() {
		global $wpdb;
		if ( self::has_table( 'format_requests' ) ) {
			$wpdb->insert(
				wic_table( 'format_requests' ),
				array( 'user_id' => self::uid( 'ruth' ), 'material' => 'Protecting Participant Privacy — Principles (Sample)', 'format' => 'large_print', 'contact' => 'ruth.yazzie@example.invalid', 'needed_by' => gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ), 'details' => 'Large print copy of the course text, please (demo request).', 'status' => 'open', 'created_at' => self::ago( 2 ), 'updated_at' => self::ago( 2 ) )
			);
			$wpdb->insert(
				wic_table( 'format_requests' ),
				array( 'user_id' => self::uid( 'noah' ), 'material' => 'Welcome to the Clinic Team (Sample)', 'format' => 'audio', 'contact' => 'noah.fischer@example.invalid', 'needed_by' => null, 'details' => 'Audio version of the course (demo request).', 'status' => 'fulfilled', 'notes' => 'Audio file sent (demo).', 'handled_by' => self::uid( 'morgan' ), 'created_at' => self::ago( 40 ), 'updated_at' => self::ago( 33 ) )
			);
		}
		if ( self::has_table( 'saved_reports' ) ) {
			$wpdb->insert(
				wic_table( 'saved_reports' ),
				array( 'owner_id' => self::uid( 'morgan' ), 'name' => 'Overdue at Riverside (demo)', 'type' => 'compliance', 'config' => wp_json_encode( array( 'clinic' => (string) self::clinic_id( 'c1' ), 'status' => 'overdue' ) ), 'created_at' => self::ago( 30 ), 'updated_at' => self::ago( 30 ) )
			);
		}
		// Supervisor reminders to overdue people, and a welcome for each new starter.
		foreach ( array( 'sam', 'kevin', 'lily' ) as $k ) {
			if ( self::uid( $k ) ) {
				WIC_Notify::event( self::uid( $k ), 'reminder', 0, 'Reminder from your supervisor: please finish your overdue training this week. (Demo notification)', false );
			}
		}
		foreach ( array( 'aisha', 'ben', 'chloe' ) as $k ) {
			WIC_Notify::event( self::uid( $k ), 'welcome', 0, 'Welcome. Your first-month learning path and required training are listed under My training. (Demo notification)', false );
		}
	}

	/**
	 * Certificates were issued in seeding order, not date order. Renumber the demo ones
	 * chronologically so numbers and years read naturally, continuing the real sequence.
	 */
	private static function renumber_certificates( $seq_before ) {
		global $wpdb;
		$users = array_values( array_filter( array_map( 'intval', self::$s['users'] ) ) );
		if ( ! $users ) {
			return;
		}
		$rows   = $wpdb->get_results( 'SELECT id, issued_at FROM ' . wic_table( 'certificates' ) . ' WHERE user_id IN (' . implode( ',', $users ) . ') ORDER BY issued_at ASC, id ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
		$seq    = (int) $seq_before;
		$prefix = strtoupper( sanitize_key( wic_setting( 'cert_prefix' ) ) );
		foreach ( (array) $rows as $r ) {
			++$seq;
			$wpdb->update( wic_table( 'certificates' ), array( 'cert_number' => sprintf( '%s-%s-%05d', $prefix, gmdate( 'Y', self::ts( $r->issued_at ) ), $seq ) ), array( 'id' => $r->id ) );
		}
		update_option( 'wic_cert_seq', max( $seq, (int) get_option( 'wic_cert_seq', 0 ) ) );
	}

	/** Old notifications have been read; the last few days stay unread so badges show. */
	private static function tidy_events() {
		global $wpdb;
		$users = array_values( array_filter( array_map( 'intval', self::$s['users'] ) ) );
		if ( ! $users ) {
			return;
		}
		$rows   = $wpdb->get_results( 'SELECT id, created_at FROM ' . wic_table( 'events' ) . ' WHERE user_id IN (' . implode( ',', $users ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL -- integer list.
		$cutoff = time() - 3 * DAY_IN_SECONDS;
		foreach ( (array) $rows as $r ) {
			$ts = self::ts( $r->created_at );
			if ( $ts < $cutoff ) {
				$wpdb->update( wic_table( 'events' ), array( 'read_at' => gmdate( 'Y-m-d H:i:s', $ts + HOUR_IN_SECONDS ), 'sent_at' => gmdate( 'Y-m-d H:i:s', $ts ), 'send_result' => 'sent' ), array( 'id' => $r->id ) );
			}
		}
		// Anything the seed queued for email is marked handled, so the real queue does not send it.
		$wpdb->query( 'UPDATE ' . wic_table( 'events' ) . " SET sent_at = created_at, send_result = 'demo' WHERE sent_at IS NULL AND user_id IN (" . implode( ',', $users ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
