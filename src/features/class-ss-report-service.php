<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage, classification and queries for export verification reports.
 */
class Report_Service {

	const SCHEMA_VERSION = '1';

	/** State of one published item after an export. */
	const OK       = 'ok';
	const MISSING  = 'missing';
	const ERROR    = 'error';
	const REDIRECT = 'redirect';
	const EXCLUDED = 'excluded';
	const EMPTY_   = 'empty';

	/** States that mean "look at this". */
	const PROBLEM_STATES = array( self::MISSING, self::ERROR, self::EMPTY_ );

	public static function create_or_update_tables() {
		Report::create_or_update_table();
		Report_Item::create_or_update_table();
	}

	/**
	 * Create the tables once per schema version (the plugin version may not change when this ships).
	 */
	public static function maybe_update_schema() {
		$options = Options::instance();
		if ( $options->get( 'report_schema_version' ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::create_or_update_tables();
		$options->set( 'report_schema_version', self::SCHEMA_VERSION )->save();
	}

	public static function enabled() {
		$value = Options::instance()->get( 'report_enabled' );

		return null === $value ? true : (bool) $value;
	}

	public static function deep_enabled() {
		$value = Options::instance()->get( 'report_deep' );

		return null === $value ? true : (bool) $value;
	}

	/**
	 * Post types whose published items must appear in the export. Media attachment pages are not content.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

		return array_values( (array) apply_filters( 'wpse_report_post_types', $types ) );
	}

	/**
	 * Decide what happened to one published item.
	 *
	 * @param array|null $page        Row from the pages table for the item's URL, or null if there is none.
	 * @param bool       $excluded    Whether the URL is excluded by the user's settings.
	 * @param int|null   $file_size   Size of the exported file, null if it does not exist.
	 * @return array{0:string,1:string,2:int|null} State, note, HTTP status.
	 */
	public static function classify( $page, $excluded, $file_size ) {
		if ( ! is_array( $page ) ) {
			return $excluded
				? array( self::EXCLUDED, __( 'Excluded by your settings.', 'wp-static-export' ), null )
				: array( self::MISSING, __( 'Not found in the export: it was not discovered or not fetched.', 'wp-static-export' ), null );
		}

		$code = isset( $page['http_status_code'] ) && '' !== $page['http_status_code'] ? (int) $page['http_status_code'] : 0;
		$note = isset( $page['status_message'] ) ? (string) $page['status_message'] : '';

		if ( $code >= 300 && $code < 400 ) {
			return array( self::REDIRECT, __( 'The page redirects elsewhere.', 'wp-static-export' ), $code );
		}
		if ( 200 === $code ) {
			if ( empty( $page['file_path'] ) ) {
				return array( self::ERROR, __( 'The page loaded but no file was written.', 'wp-static-export' ), $code );
			}
			if ( null === $file_size ) {
				return array( self::ERROR, __( 'The file is missing from the export folder.', 'wp-static-export' ), $code );
			}
			if ( 0 === $file_size ) {
				return array( self::EMPTY_, __( 'The exported file is empty.', 'wp-static-export' ), $code );
			}

			return array( self::OK, '', $code );
		}
		if ( 0 === $code ) {
			return array( self::ERROR, '' !== $note ? $note : __( 'The page was never fetched.', 'wp-static-export' ), null );
		}

		/* translators: %d: HTTP status code. */
		return array( self::ERROR, sprintf( __( 'The page answered HTTP %d.', 'wp-static-export' ), $code ) . ( '' !== $note ? ' ' . $note : '' ), $code );
	}

	/**
	 * Insert items in bulk.
	 *
	 * @param int     $report_id Report ID.
	 * @param array[] $items     Items with keys post_id, post_type, title, url, state, status_code, static_path, file_size, note.
	 * @return void
	 */
	public static function insert_items( $report_id, array $items ) {
		global $wpdb;
		if ( ! $items ) {
			return;
		}
		$table  = Report_Item::table_name();
		$values = array();
		foreach ( $items as $i ) {
			$code = null === $i['status_code'] ? 'NULL' : (string) (int) $i['status_code'];
			$size = null === $i['file_size'] ? 'NULL' : (string) (int) $i['file_size'];
			// NULL must stay NULL: prepare() would turn it into an empty string, which a number column stores as 0.
			$values[] = $wpdb->prepare(
				'(%d, %d, %s, %s, %s, %s, ' . $code . ', %s, ' . $size . ', %s)',
				$report_id,
				(int) $i['post_id'],
				(string) $i['post_type'],
				self::cut( (string) $i['title'], 255 ),
				self::cut( (string) $i['url'], 700 ),
				(string) $i['state'],
				self::cut( (string) $i['static_path'], 700 ),
				self::cut( (string) $i['note'], 255 )
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every row above was prepared.
		$wpdb->query( "INSERT INTO `{$table}` (report_id, post_id, post_type, title, url, state, status_code, static_path, file_size, note) VALUES " . implode( ', ', $values ) );
	}

	/**
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	protected static function cut( $text, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Counts per state and per post type for a report.
	 *
	 * @param int $report_id Report ID.
	 * @return array{published:int,states:array<string,int>,by_type:array<string,array<string,int>>}
	 */
	public static function totals( $report_id ) {
		global $wpdb;
		$table  = Report_Item::table_name();
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, state, COUNT(*) AS n FROM `{$table}` WHERE report_id = %d GROUP BY post_type, state", $report_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$states = array_fill_keys( array( self::OK, self::MISSING, self::ERROR, self::REDIRECT, self::EXCLUDED, self::EMPTY_ ), 0 );
		$types  = array();
		$total  = 0;
		foreach ( (array) $rows as $r ) {
			$n                                   = (int) $r['n'];
			$states[ $r['state'] ]               = ( $states[ $r['state'] ] ?? 0 ) + $n;
			$types[ $r['post_type'] ]            = $types[ $r['post_type'] ] ?? array_fill_keys( array_keys( $states ), 0 );
			$types[ $r['post_type'] ][ $r['state'] ] = $n;
			$total                              += $n;
		}
		ksort( $types );

		return array(
			'published' => $total,
			'states'    => $states,
			'by_type'   => $types,
		);
	}

	/**
	 * @param int $keep Number of reports to keep for this site.
	 * @return void
	 */
	public static function prune( $keep = 5 ) {
		global $wpdb;
		$reports = Report::table_name();
		$items   = Report_Item::table_name();
		$keep    = max( 1, (int) $keep );
		$old     = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$reports}` WHERE site_id = %d ORDER BY id DESC LIMIT 1000 OFFSET %d", get_current_blog_id(), $keep ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $old as $id ) {
			$wpdb->delete( $items, array( 'report_id' => (int) $id ), array( '%d' ) );
			$wpdb->delete( $reports, array( 'id' => (int) $id ), array( '%d' ) );
		}
	}

	/**
	 * @param int $id Report ID, 0 for the latest.
	 * @return array|null Report row with decoded JSON.
	 */
	public static function get( $id = 0 ) {
		global $wpdb;
		$table = Report::table_name();
		$row   = $id
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND site_id = %d", $id, get_current_blog_id() ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE site_id = %d ORDER BY id DESC LIMIT 1", get_current_blog_id() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? self::decode( $row ) : null;
	}

	/**
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function list_reports( $limit = 20 ) {
		global $wpdb;
		$table = Report::table_name();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, deploy_id, status, scope, delivery_method, started_at, finished_at, totals FROM `{$table}` WHERE site_id = %d ORDER BY id DESC LIMIT %d", get_current_blog_id(), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function decode( array $row ) {
		foreach ( array( 'totals', 'deep' ) as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$decoded    = is_string( $row[ $key ] ) && '' !== $row[ $key ] ? json_decode( $row[ $key ], true ) : null;
				$row[ $key ] = is_array( $decoded ) ? $decoded : null;
			}
		}
		$row['id'] = (int) $row['id'];

		return $row;
	}

	/**
	 * Page through a report's items.
	 *
	 * @param int   $report_id Report ID.
	 * @param array $args      state ('problems' = missing+error+empty), post_type, search, orderby, order, page, per_page.
	 * @return array{items:array[],total:int,total_pages:int,page:int,per_page:int}
	 */
	public static function query_items( $report_id, array $args = array() ) {
		global $wpdb;
		$table    = Report_Item::table_name();
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$where    = array( 'report_id = %d' );
		$params   = array( (int) $report_id );

		$state = (string) ( $args['state'] ?? '' );
		if ( 'problems' === $state ) {
			$where[] = 'state IN (' . implode( ',', array_fill( 0, count( self::PROBLEM_STATES ), '%s' ) ) . ')';
			$params  = array_merge( $params, self::PROBLEM_STATES );
		} elseif ( in_array( $state, array( self::OK, self::MISSING, self::ERROR, self::REDIRECT, self::EXCLUDED, self::EMPTY_ ), true ) ) {
			$where[]  = 'state = %s';
			$params[] = $state;
		}
		if ( ! empty( $args['post_type'] ) ) {
			$where[]  = 'post_type = %s';
			$params[] = sanitize_key( (string) $args['post_type'] );
		}
		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[]  = '(title LIKE %s OR url LIKE %s OR static_path LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like ) );
		}

		$columns = array( 'id', 'title', 'post_type', 'state', 'status_code', 'file_size', 'url' );
		$orderby = in_array( $args['orderby'] ?? '', $columns, true ) ? $args['orderby'] : 'id';
		$order   = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) ) ? 'DESC' : 'ASC';
		$sql     = 'WHERE ' . implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` {$sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` {$sql} ORDER BY `{$orderby}` {$order}, id ASC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items'       => array_map( array( __CLASS__, 'format_item' ), (array) $rows ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * @param array $row Raw item row.
	 * @return array
	 */
	public static function format_item( array $row ) {
		$post_id = (int) $row['post_id'];
		$parts   = wp_parse_url( (string) $row['url'] );
		$path    = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		return array(
			'id'          => (int) $row['id'],
			'post_id'     => $post_id,
			'post_type'   => (string) $row['post_type'],
			'title'       => (string) $row['title'],
			'url'         => (string) $row['url'],
			'path'        => $path,
			'state'       => (string) $row['state'],
			'status_code' => null === $row['status_code'] ? null : (int) $row['status_code'],
			'static_path' => (string) $row['static_path'],
			'file_size'   => null === $row['file_size'] ? null : (int) $row['file_size'],
			'note'        => (string) $row['note'],
			'edit_url'    => $post_id && current_user_can( 'edit_post', $post_id ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
		);
	}
}
