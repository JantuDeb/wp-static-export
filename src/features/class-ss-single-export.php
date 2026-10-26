<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export (and deliver) just one or a few posts, and optionally do so automatically when a post
 * is published ("instant publishing"). The page is built through the normal pipeline and sent to
 * the configured delivery method as a partial export: nothing is deleted and the search index is
 * left alone, since those need the whole site.
 */
class Single_Export {

	const FLAG        = 'wp-static-export-use-single';
	const CRON_HOOK   = 'ss_single_auto_export';
	const MAX_RETRIES = 20;

	public static function init() {
		// The flag is one-shot state. Core's wrap-up does not clear it, so an export that left it
		// behind would turn every later export into a single-page export.
		add_action( 'wpse_completed', array( __CLASS__, 'clear_flag' ), 5 );

		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_ss_single_export', array( __CLASS__, 'handle_row_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );

		add_action( 'transition_post_status', array( __CLASS__, 'maybe_schedule' ), 10, 3 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ), 10, 2 );
	}

	public static function clear_flag() {
		delete_option( self::FLAG );
	}

	/**
	 * Keep only positive, distinct integers.
	 *
	 * @param mixed $ids IDs as array or comma separated string.
	 * @return int[]
	 */
	public static function normalize_ids( $ids ) {
		if ( is_string( $ids ) ) {
			$ids = explode( ',', $ids );
		}
		$clean = array();
		foreach ( (array) $ids as $id ) {
			// absint() would turn "-3" into post 3; anything that is not a plain positive integer is dropped.
			if ( ( is_int( $id ) || ( is_string( $id ) && ctype_digit( trim( $id ) ) ) ) && (int) $id > 0 ) {
				$clean[] = (int) $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Start a single-page export.
	 *
	 * @param mixed $ids Post IDs.
	 * @return true|\WP_Error
	 */
	public static function start( $ids ) {
		$ids = self::normalize_ids( $ids );
		if ( ! $ids ) {
			return new \WP_Error( 'ss_single_no_ids', __( 'No post given.', 'wp-static-export' ) );
		}
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
				return new \WP_Error( 'ss_single_not_public', sprintf( /* translators: %d: post ID */ __( 'Post %d is not published.', 'wp-static-export' ), $id ) );
			}
		}

		$job = Plugin::instance()->get_archive_creation_job();
		if ( $job->is_running() || $job->is_paused() ) {
			return new \WP_Error( 'ss_single_busy', __( 'Another export is running.', 'wp-static-export' ) );
		}

		$previous = get_option( self::FLAG, null );
		$prepare  = static function () use ( $ids ) {
			// Only after Core owns the export start gate, so a refused start leaves no flag behind.
			foreach ( $ids as $id ) {
				self::ensure_page_record( $id );
			}
			delete_option( 'wp-static-export-404-only' );
			delete_option( 'wp-static-export-use-build' );
			delete_option( 'wp-static-export-use-language' );
			update_option( self::FLAG, implode( ',', $ids ), false );

			return 'export';
		};
		$restore = static function () use ( $previous ) {
			null === $previous ? delete_option( self::FLAG ) : update_option( self::FLAG, $previous, false );
		};

		return Plugin::instance()->run_static_export( 0, 'export', $prepare, $restore )
			? true
			: new \WP_Error( 'ss_single_not_started', __( 'The export could not be started.', 'wp-static-export' ) );
	}

	/**
	 * The fetch step only processes page records that carry the post ID. A post that was never part
	 * of a full export has none yet, so create it.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	protected static function ensure_page_record( $post_id ) {
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return;
		}
		$page = Page::query()->find_or_initialize_by( 'url', $url );
		if ( (int) $page->post_id !== (int) $post_id ) {
			$page->post_id = $post_id;
			$page->save();
		}
	}

	// ---- admin row action ------------------------------------------------------------------

	/**
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( 'publish' === $post->post_status && current_user_can( 'edit_post', $post->ID ) && is_post_type_viewable( $post->post_type ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=ss_single_export&post=' . (int) $post->ID ), 'ss_single_export_' . $post->ID );

			$actions['ss_single_export'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Publish to static site', 'wp-static-export' ) . '</a>';
		}

		return $actions;
	}

	public static function handle_row_action() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'ss_single_export_' . $id );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-static-export' ), 403 );
		}

		$result = self::start( array( $id ) );
		$state  = is_wp_error( $result ) ? 'error' : 'started';
		$back   = add_query_arg(
			array(
				'ss_single' => $state,
				'ss_msg'    => is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : '',
			),
			wp_get_referer() ?: admin_url( 'edit.php' )
		);
		wp_safe_redirect( $back );
		exit;
	}

	public static function notice() {
		if ( empty( $_GET['ss_single'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$ok = 'started' === $_GET['ss_single']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $ok ? 'success' : 'error' ),
			esc_html( $ok ? __( 'Publishing to the static site has started.', 'wp-static-export' ) : sanitize_text_field( rawurldecode( (string) ( $_GET['ss_msg'] ?? '' ) ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	// ---- instant publishing ----------------------------------------------------------------

	/**
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function maybe_schedule( $new_status, $old_status, $post ) {
		if ( ! self::should_auto_export( $new_status, $post ) ) {
			return;
		}
		$delay = max( 1, (int) ( Options::instance()->get( 'single_auto_export_delay' ) ?: 10 ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( (int) $post->ID, 0 ) ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( (int) $post->ID, 0 ) );
		}
	}

	/**
	 * Publishing or updating a public post, with the feature on and not during an autosave/revision.
	 *
	 * @param string   $new_status New status.
	 * @param \WP_Post $post       Post.
	 * @return bool
	 */
	public static function should_auto_export( $new_status, $post ) {
		return 'publish' === $new_status
			&& is_object( $post )
			&& ! wp_is_post_revision( $post->ID )
			&& ! wp_is_post_autosave( $post->ID )
			&& is_post_type_viewable( $post->post_type )
			&& (bool) Options::instance()->get( 'single_auto_export' );
	}

	/**
	 * Cron callback. If an export is already running, try again shortly instead of dropping the update.
	 *
	 * @param int $post_id Post ID.
	 * @param int $attempt Number of earlier attempts.
	 * @return void
	 */
	public static function run_scheduled( $post_id, $attempt = 0 ) {
		$result = self::start( array( $post_id ) );
		if ( is_wp_error( $result ) && 'ss_single_busy' === $result->get_error_code() && $attempt < self::MAX_RETRIES ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK, array( (int) $post_id, (int) $attempt + 1 ) );
		}
	}
}
