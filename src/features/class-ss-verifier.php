<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares every published post/page with what the export produced and records one row per item.
 *
 * Works in time-boxed batches so it finishes on sites with hundreds of thousands of posts; the state
 * lives in an option, so the export task and the "Verify again" button share the same code.
 */
class Verifier {

	const STATE_OPTION = 'wpse_verify_state';
	const BATCH        = 200;

	/**
	 * @return array|null Running state.
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * @return void
	 */
	public static function reset() {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * Start a verification run.
	 *
	 * @param int[] $post_ids Only these posts (single export), empty for everything.
	 * @return array State.
	 */
	public static function begin( array $post_ids = array() ) {
		global $wpdb;
		Report_Service::maybe_update_schema();

		$options = Options::instance();
		$partial = ! empty( $post_ids );
		$report  = $partial ? Report_Service::get() : null;
		$now     = current_time( 'mysql', true );

		if ( $partial && $report ) {
			$report_id = (int) $report['id'];
			$wpdb->query( $wpdb->prepare( 'DELETE FROM `' . Report_Item::table_name() . '` WHERE report_id = %d AND post_id IN (' . implode( ',', array_map( 'intval', $post_ids ) ) . ')', $report_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$wpdb->insert(
				Report::table_name(),
				array(
					'deploy_id'       => (string) $options->get( 'archive_deploy_id' ),
					'site_id'         => get_current_blog_id(),
					'status'          => 'running',
					'scope'           => $partial ? 'partial' : 'full',
					'delivery_method' => (string) $options->get( 'delivery_method' ),
					'started_at'      => $now,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
			$report_id = (int) $wpdb->insert_id;
		}

		$state = array(
			'report_id' => $report_id,
			'ids'       => $post_ids,
			'cursor'    => 0,
			'offset'    => 0,
			'done'      => 0,
			'total'     => self::count_posts( $post_ids ),
			'partial'   => $partial,
		);
		update_option( self::STATE_OPTION, $state, false );

		return $state;
	}

	/**
	 * @param array|null $report Report row.
	 * @return bool
	 */
	public static function was_partial( $report ) {
		return is_array( $report ) && 'partial' === ( $report['scope'] ?? '' );
	}

	/**
	 * @param int[] $post_ids Restrict to these.
	 * @return int
	 */
	protected static function count_posts( array $post_ids ) {
		global $wpdb;
		$types = Report_Service::post_types();
		if ( ! $types ) {
			return 0;
		}
		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in)";
		if ( $post_ids ) {
			$sql .= ' AND ID IN (' . implode( ',', array_map( 'intval', $post_ids ) ) . ')';
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Process batches for up to $seconds.
	 *
	 * @param float $seconds Time budget.
	 * @return array{status:string,report_id:int,done:int,total:int}
	 */
	public static function step( $seconds = 8.0 ) {
		global $wpdb;
		$state = self::state();
		if ( ! $state ) {
			$state = self::begin();
		}
		$started = microtime( true );
		$types   = Report_Service::post_types();
		$archive = Options::instance()->get_archive_dir();
		$pages   = Page::table_name();

		do {
			$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$sql = "SELECT ID, post_type, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND ID > %d";
			if ( $state['ids'] ) {
				$sql .= ' AND ID IN (' . implode( ',', array_map( 'intval', $state['ids'] ) ) . ')';
			}
			$sql .= ' ORDER BY ID ASC LIMIT %d';
			$posts = $types ? $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $types, array( (int) $state['cursor'], self::BATCH ) ) ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $posts ) {
				return self::finish( $state );
			}

			// One lookup for the whole batch; each permalink in both slash forms.
			$urls = array();
			foreach ( $posts as $i => $p ) {
				$url             = (string) get_permalink( (int) $p['ID'] );
				$posts[ $i ]['url'] = $url;
				$urls[]          = $url;
				$urls[]          = untrailingslashit( $url ) === $url ? trailingslashit( $url ) : untrailingslashit( $url );
			}
			$urls = array_values( array_unique( array_filter( $urls ) ) );
			$map  = array();
			if ( $urls ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT url, file_path, http_status_code, status_message FROM `{$pages}` WHERE url IN (" . implode( ',', array_fill( 0, count( $urls ), '%s' ) ) . ')', $urls ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( (array) $rows as $r ) {
					$map[ $r['url'] ] = $r;
				}
			}

			$items = array();
			foreach ( $posts as $p ) {
				$url  = $p['url'];
				$alt  = untrailingslashit( $url ) === $url ? trailingslashit( $url ) : untrailingslashit( $url );
				$page = $map[ $url ] ?? ( $map[ $alt ] ?? null );
				$size = null;
				if ( $page && ! empty( $page['file_path'] ) ) {
					$file = Util::combine_path( $archive, $page['file_path'] );
					$size = is_file( $file ) ? (int) filesize( $file ) : null;
				}
				list( $st, $note, $code ) = Report_Service::classify( $page, '' !== $url && Util::is_url_excluded( $url ), $size );
				$items[] = array(
					'post_id'     => (int) $p['ID'],
					'post_type'   => $p['post_type'],
					'title'       => '' !== $p['post_title'] ? $p['post_title'] : __( '(no title)', 'wp-static-export' ),
					'url'         => $url,
					'state'       => $st,
					'status_code' => $code,
					'static_path' => $page['file_path'] ?? '',
					'file_size'   => $size,
					'note'        => $note,
				);
			}
			Report_Service::insert_items( (int) $state['report_id'], $items );

			$state['cursor'] = (int) end( $posts )['ID'];
			$state['done']  += count( $posts );
			update_option( self::STATE_OPTION, $state, false );
		} while ( microtime( true ) - $started < $seconds );

		return array(
			'status'    => 'running',
			'report_id' => (int) $state['report_id'],
			'done'      => (int) $state['done'],
			'total'     => (int) $state['total'],
		);
	}

	/**
	 * @param array $state State.
	 * @return array
	 */
	protected static function finish( array $state ) {
		global $wpdb;
		$report_id = (int) $state['report_id'];
		$totals    = Report_Service::totals( $report_id );
		$wpdb->update(
			Report::table_name(),
			array(
				'status'      => 'done',
				'finished_at' => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
				'totals'      => wp_json_encode( $totals ),
			),
			array( 'id' => $report_id )
		);
		Report_Service::prune( (int) ( Options::instance()->get( 'report_keep' ) ?: 5 ) );
		self::reset();

		return array(
			'status'    => 'done',
			'report_id' => $report_id,
			'done'      => (int) $state['done'],
			'total'     => (int) $state['total'],
		);
	}
}
