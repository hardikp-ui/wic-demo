<?php
/**
 * Shared helpers. Anything more than one class needs lives here.
 */

defined( 'ABSPATH' ) || exit;

function wic_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'wic_' . $name;
}

function wic_now() {
	return current_time( 'mysql', true );
}

/** Agency configuration: one record, read by the portal, player, certificates and emails. */
function wic_agency_defaults() {
	/** Modules add their own settings (with defaults) through `wic_agency_defaults`. */
	return apply_filters( 'wic_agency_defaults', array(
		'name'             => get_bloginfo( 'name' ),
		'logo_url'         => '',
		'color_primary'    => '#1f5f8b',
		'color_accent'     => '#e0a100',
		'color_ink'        => '#1d2430',
		'color_surface'    => '#ffffff',
		'cert_prefix'      => 'WIC',
		'signatory_name'   => '',
		'signatory_title'  => '',
		'sender_name'      => get_bloginfo( 'name' ),
		'pass_mark'        => 80,
		'escalation_days'  => 5,
		'reminder_days'    => 3,
		'idle_cap_seconds' => 300,
		'announcement'     => '',
		'announce_from'    => '',
		'announce_until'   => '',
	) );
}

/**
 * One setting from the agency record. The `wic_setting` filter lets the multi-agency
 * module answer with the current agency's own value.
 */
function wic_setting( $key ) {
	$opts     = get_option( 'wic_agency', array() );
	$defaults = wic_agency_defaults();
	if ( isset( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
		$value = $opts[ $key ];
	} else {
		$value = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}
	return apply_filters( 'wic_setting', $value, $key );
}

/** Clinic name for a person. The clinics module turns the stored value into a real record. */
function wic_user_clinic_name( $user_id ) {
	return (string) apply_filters( 'wic_user_clinic_name', (string) get_user_meta( $user_id, 'wic_clinic', true ), $user_id );
}

/** Clinic record ID for a person (0 = none). Owned by the clinics module. */
function wic_user_clinic_id( $user_id ) {
	return (int) apply_filters( 'wic_user_clinic_id', (int) get_user_meta( $user_id, 'wic_clinic_id', true ), $user_id );
}

/**
 * Clinics as id => name, for filters and assignment targets. The clinics module answers
 * from its table; before it exists this falls back to the distinct free-text values.
 */
function wic_clinic_options() {
	$options = apply_filters( 'wic_clinic_options', null );
	if ( is_array( $options ) ) {
		return $options;
	}
	global $wpdb;
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value", 'wic_clinic' ) );
	return array_combine( $names, $names );
}

/** The agency the current request belongs to (0 = the single default agency). Owned by the agencies module. */
function wic_current_agency_id() {
	return (int) apply_filters( 'wic_current_agency_id', 0 );
}

/** The agency a person belongs to (0 = the default agency). */
function wic_user_agency_id( $user_id ) {
	return (int) apply_filters( 'wic_user_agency_id', (int) get_user_meta( $user_id, 'wic_agency_id', true ), $user_id );
}

/** Output a portal URL for a view, e.g. wic_portal_url( 'library' ). */
function wic_portal_url( $view, $args = array() ) {
	return wic_page_url( 'portal', array_merge( array( 'view' => $view ), $args ) );
}

/** Neutralise spreadsheet formula injection in one CSV cell. */
function wic_csv_cell( $v ) {
	$v = (string) $v;
	return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
}

/** Stream a CSV download and exit. $rows is an array of arrays; the first row is the header. */
function wic_send_csv( $filename, $rows ) {
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	foreach ( $rows as $row ) {
		fputcsv( $out, array_map( 'wic_csv_cell', (array) $row ) );
	}
	fclose( $out );
	exit;
}

/** Fold accents and case so "nutricion" finds "Nutrición". */
function wic_fold( $text ) {
	$text = remove_accents( wp_strip_all_tags( (string) $text ) );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
}

function wic_page_url( $key, $args = array() ) {
	$id  = (int) get_option( 'wic_page_' . $key );
	$url = $id ? get_permalink( $id ) : home_url( '/' );
	return $args ? add_query_arg( $args, $url ) : $url;
}

function wic_page_id( $key ) {
	return (int) get_option( 'wic_page_' . $key );
}

/** Account status. People are deactivated, never deleted. */
function wic_user_status( $user_id ) {
	$status = get_user_meta( $user_id, 'wic_status', true );
	return $status ? $status : 'active';
}

function wic_user_group( $user_id ) {
	$group = get_user_meta( $user_id, 'wic_group', true );
	return $group ? $group : 'staff';
}

function wic_group_label( $group ) {
	$labels = array(
		'staff'  => __( 'Staff', 'wic-tp' ),
		'intern' => __( 'Intern', 'wic-tp' ),
	);
	return isset( $labels[ $group ] ) ? $labels[ $group ] : ucfirst( (string) $group );
}

function wic_reports_to( $user_id ) {
	return (int) get_user_meta( $user_id, 'wic_reports_to', true );
}

function wic_is_wic_user( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}
	if ( array_intersect( (array) $user->roles, WIC_Roles::all_roles() ) ) {
		return true;
	}
	return '' !== get_user_meta( $user_id, 'wic_status', true );
}

/**
 * The one scoping rule every team view and report uses:
 * administrators see everyone, supervisors see their own people, learners see themselves.
 */
function wic_scope_user_ids( $viewer_id ) {
	static $cache = array();
	if ( isset( $cache[ $viewer_id ] ) ) {
		return $cache[ $viewer_id ];
	}
	if ( user_can( $viewer_id, 'wic_view_all' ) ) {
		$ids = get_users(
			array(
				'role__in' => apply_filters( 'wic_learning_roles', array( 'wic_learner', 'wic_supervisor', 'wic_admin' ) ),
				'fields'   => 'ID',
			)
		);
	} elseif ( user_can( $viewer_id, 'wic_view_team' ) ) {
		$ids = get_users(
			array(
				'meta_key'   => 'wic_reports_to',
				'meta_value' => (int) $viewer_id,
				'fields'     => 'ID',
			)
		);
	} else {
		$ids = array( (int) $viewer_id );
	}
	/**
	 * Modules narrow or widen scope here: the agency module restricts to one agency,
	 * the local-administrator role widens to a clinic, mentors see their mentees.
	 */
	$ids                  = array_values( array_unique( array_map( 'intval', apply_filters( 'wic_scope_user_ids', $ids, (int) $viewer_id ) ) ) );
	$cache[ $viewer_id ] = $ids;
	return $ids;
}

function wic_can_see_user( $viewer_id, $user_id ) {
	return (int) $viewer_id === (int) $user_id || in_array( (int) $user_id, wic_scope_user_ids( $viewer_id ), true );
}

/** Audit log: written from one place, never edited, never deleted. */
function wic_audit( $action, $object_type = '', $object_id = 0, $details = array() ) {
	global $wpdb;
	$wpdb->insert(
		wic_table( 'audit' ),
		array(
			'actor_id'    => get_current_user_id(),
			'action'      => $action,
			'object_type' => $object_type,
			'object_id'   => (int) $object_id,
			'details'     => $details ? wp_json_encode( $details ) : '',
			'created_at'  => wic_now(),
		)
	);
}

function wic_format_date( $mysql_gmt, $with_time = false ) {
	if ( ! $mysql_gmt ) {
		return '—';
	}
	$format = get_option( 'date_format' ) . ( $with_time ? ' ' . get_option( 'time_format' ) : '' );
	return get_date_from_gmt( $mysql_gmt, $format );
}

function wic_status_label( $status ) {
	$labels = array(
		'not_started' => __( 'Not started', 'wic-tp' ),
		'in_progress' => __( 'In progress', 'wic-tp' ),
		'complete'    => __( 'Complete', 'wic-tp' ),
		'overdue'        => __( 'Overdue', 'wic-tp' ),
		'expired'        => __( 'Expired', 'wic-tp' ),
		'coming_due'     => __( 'Coming due', 'wic-tp' ),
		'not_applicable' => __( 'Not applicable', 'wic-tp' ),
		'never_assigned' => __( 'Never assigned', 'wic-tp' ),
	);
	$labels = apply_filters( 'wic_status_labels', $labels );
	return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
}

function wic_random_code( $length = 10 ) {
	$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	$code     = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
	}
	return $code;
}

/** Alt text must be plausible: not empty and not shaped like a filename. */
function wic_alt_is_plausible( $alt ) {
	$alt = trim( (string) $alt );
	if ( '' === $alt || strlen( $alt ) < 3 ) {
		return false;
	}
	if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|bmp|tiff?)$/i', $alt ) ) {
		return false;
	}
	if ( preg_match( '/^[\w\-]+$/', $alt ) && preg_match( '/[-_]/', $alt ) ) {
		return false;
	}
	return true;
}
