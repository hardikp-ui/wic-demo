<?php
/**
 * Activation: tables, roles, pages, sample content, scheduled job.
 */

defined( 'ABSPATH' ) || exit;

class WIC_Install {

	const PAGES = array(
		'portal'      => array( 'Training Portal', '[wic_portal]' ),
		'register'    => array( 'Register', '[wic_register]' ),
		'learn'       => array( 'Lesson', '[wic_player]' ),
		'certificate' => array( 'Certificate', '[wic_certificate]' ),
		'verify'      => array( 'Verify a Certificate', '[wic_verify]' ),
	);

	/** Pages the platform needs. Modules add theirs through the `wic_pages` filter. */
	public static function page_defs() {
		return apply_filters( 'wic_pages', self::PAGES );
	}

	public static function activate() {
		self::tables();
		WIC_Roles::add_roles();
		WIC_Content::register_types();
		/** Modules register their content types here too, so seeding can use them. */
		do_action( 'wic_register_types' );
		self::pages();
		WIC_Sample::seed();
		if ( ! wp_next_scheduled( 'wic_cron_tick' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'wic_cron_tick' );
		}
		/** Module seeding: sample records, default options. Must be idempotent. */
		do_action( 'wic_install' );
		update_option( 'wic_db_version', self::schema_version() );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'wic_cron_tick' );
		flush_rewrite_rules();
	}

	/**
	 * The stored version is the plugin DB version plus a hash of every table definition,
	 * so a module adding a table or column upgrades the schema without a version bump.
	 */
	public static function schema_version() {
		return WIC_TP_DB_VERSION . '-' . substr( md5( implode( '', self::table_sql() ) . implode( ',', array_keys( self::page_defs() ) ) ), 0, 10 );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'wic_db_version' ) !== self::schema_version() ) {
			self::tables();
			WIC_Roles::add_roles();
			add_action(
				'init',
				function () {
					do_action( 'wic_register_types' );
					self::pages();
					do_action( 'wic_install' );
					flush_rewrite_rules();
				},
				99
			);
			update_option( 'wic_db_version', self::schema_version() );
		}
	}

	/**
	 * Positions, attempts and completions are individual rows from day one —
	 * resume, progress, time spent, most-missed and every report are queries over them.
	 */
	public static function tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::table_sql() as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Every CREATE TABLE statement, core first, then modules via `wic_table_sql`.
	 * dbDelta rules apply: two spaces after PRIMARY KEY, one column per line.
	 */
	public static function table_sql() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();

		$sql = array();

		$sql[] = 'CREATE TABLE ' . wic_table( 'assignments' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  course_version int(11) NOT NULL DEFAULT 1,
  source varchar(20) NOT NULL DEFAULT 'manual',
  assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
  assigned_at datetime NOT NULL,
  due_at datetime NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  note text NULL,
  PRIMARY KEY  (id),
  KEY user_course (user_id,course_id),
  KEY course (course_id)
) $c;";

		$sql[] = 'CREATE TABLE ' . wic_table( 'positions' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  run int(11) NOT NULL DEFAULT 1,
  current_slide bigint(20) unsigned NOT NULL DEFAULT 0,
  seen longtext NULL,
  first_access datetime NOT NULL,
  last_access datetime NOT NULL,
  time_spent int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY user_course_run (user_id,course_id,run)
) $c;";

		$sql[] = 'CREATE TABLE ' . wic_table( 'attempts' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  run int(11) NOT NULL DEFAULT 1,
  module_id bigint(20) unsigned NOT NULL DEFAULT 0,
  slide_id bigint(20) unsigned NOT NULL,
  attempt_no tinyint(3) unsigned NOT NULL DEFAULT 1,
  answer longtext NULL,
  shown_order text NULL,
  correct tinyint(1) NOT NULL DEFAULT 0,
  points int(11) NOT NULL DEFAULT 0,
  max_points int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_course (user_id,course_id,run),
  KEY slide (slide_id)
) $c;";

		$sql[] = 'CREATE TABLE ' . wic_table( 'completions' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  course_version int(11) NOT NULL DEFAULT 1,
  run int(11) NOT NULL DEFAULT 1,
  score int(11) NOT NULL DEFAULT 0,
  time_spent int(10) unsigned NOT NULL DEFAULT 0,
  completed_at datetime NOT NULL,
  source varchar(20) NOT NULL DEFAULT 'online',
  PRIMARY KEY  (id),
  KEY user_course (user_id,course_id)
) $c;";

		$sql[] = 'CREATE TABLE ' . wic_table( 'certificates' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  completion_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  course_id bigint(20) unsigned NOT NULL,
  cert_number varchar(40) NOT NULL,
  verify_code varchar(20) NOT NULL,
  learner_name varchar(200) NOT NULL,
  course_title varchar(255) NOT NULL,
  score int(11) NOT NULL DEFAULT 0,
  hours decimal(6,2) NOT NULL DEFAULT 0,
  credit_type varchar(100) NOT NULL DEFAULT '',
  issued_at datetime NOT NULL,
  expires_at datetime NULL,
  status varchar(20) NOT NULL DEFAULT 'valid',
  template_version int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY verify_code (verify_code),
  KEY user_id (user_id)
) $c;";

		$sql[] = 'CREATE TABLE ' . wic_table( 'events' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  type varchar(40) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message text NULL,
  send_email tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  read_at datetime NULL,
  sent_at datetime NULL,
  send_result varchar(20) NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY type_object (type,object_id)
) $c;";

		$sql[] = 'CREATE TABLE ' . wic_table( 'audit' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(60) NOT NULL,
  object_type varchar(40) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  details text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY action (action)
) $c;";

		return apply_filters( 'wic_table_sql', $sql, $c );
	}

	public static function pages() {
		foreach ( self::page_defs() as $key => $page ) {
			$existing = (int) get_option( 'wic_page_' . $key );
			if ( $existing && get_post( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page[0],
					'post_content' => $page[1],
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( 'wic_page_' . $key, $id );
			}
		}
	}
}
