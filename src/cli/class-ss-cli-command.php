<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage static exports and deployments from the command line.
 *
 * ## EXAMPLES
 *
 *     wp static-export run --deploy-to=netlify
 *     wp static-export run --type=update
 *     wp static-export status
 */
class CLI_Command {

	/**
	 * Run an export and wait for it to finish.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Export type.
	 * ---
	 * default: export
	 * options:
	 *   - export
	 *   - update
	 * ---
	 *
	 * [--deploy-to=<target>]
	 * : Override the delivery method for this run (github, netlify, cloudflare-pages, s3, bunny, sftp, zip, local).
	 *
	 * [--post=<ids>]
	 * : Export only these posts (comma separated IDs) and deliver them: "instant publishing".
	 *
	 * [--force-full]
	 * : Ignore incremental deploy state and upload every file.
	 *
	 * [--timeout=<seconds>]
	 * : Give up waiting after this many seconds.
	 * ---
	 * default: 3600
	 * ---
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function run( $args, $assoc_args ) {
		$options = Options::instance();

		if ( ! empty( $assoc_args['deploy-to'] ) ) {
			$target  = (string) $assoc_args['deploy-to'];
			$allowed = array_merge( Deploy_Config::methods(), array( 'zip', 'local' ) );
			if ( ! in_array( $target, $allowed, true ) ) {
				\WP_CLI::error( 'Unknown target "' . $target . '". Use one of: ' . implode( ', ', $allowed ) );
			}
			$options->set( 'delivery_method', $target );
		}
		$options->set( 'deploy_force_full', isset( $assoc_args['force-full'] ) )->save();

		$type = isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : 'export';
		$job  = Plugin::instance()->get_archive_creation_job();

		if ( ! empty( $assoc_args['post'] ) ) {
			$started = Single_Export::start( $assoc_args['post'] );
			if ( is_wp_error( $started ) ) {
				\WP_CLI::error( $started->get_error_message() );
			}
		} elseif ( ! Plugin::instance()->run_static_export( 0, $type ) ) {
			\WP_CLI::error( 'Could not start the export (is one already running?).' );
		}

		$deadline = time() + max( 30, (int) ( $assoc_args['timeout'] ?? 3600 ) );
		$last     = '';

		// There is no loopback request in WP-CLI, so drive the queue in this process.
		// handle() processes one time-boxed window and is protected, hence reflection.
		$handle = new \ReflectionMethod( $job, 'handle' );
		$handle->setAccessible( true );
		$identifier = new \ReflectionProperty( $job, 'identifier' );
		$identifier->setAccessible( true );
		add_filter( $identifier->getValue( $job ) . '_wp_die', '__return_false' );

		while ( $job->is_running() ) {
			if ( time() > $deadline ) {
				\WP_CLI::error( 'Timed out waiting for the export.' );
			}
			$task = $job->get_current_task();
			if ( $task && $task !== $last ) {
				\WP_CLI::log( 'Running task: ' . $task );
				$last = $task;
			}
			$handle->invoke( $job );
			Options::reinstance();
		}

		$messages = Options::instance()->get( 'archive_status_messages' );
		$errors   = is_array( $messages ) && isset( $messages['error'] ) ? $messages['error']['message'] : '';
		if ( $errors ) {
			\WP_CLI::error( $errors );
		}
		\WP_CLI::success( 'Export finished.' );
	}

	/**
	 * Show the status of the current or last export.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		$job      = Plugin::instance()->get_archive_creation_job();
		$messages = Options::instance()->get( 'archive_status_messages' );

		\WP_CLI::log( $job->is_running() ? 'Running: ' . $job->get_current_task() : 'Idle' );
		foreach ( (array) $messages as $key => $m ) {
			\WP_CLI::log( sprintf( '[%s] %s', $key, $m['message'] ?? '' ) );
		}
	}

	/**
	 * Cancel the running export.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function cancel( $args, $assoc_args ) {
		Plugin::instance()->get_archive_creation_job()->cancel();
		\WP_CLI::success( 'Export cancelled.' );
	}

	/**
	 * Check that the deployer can run on this server.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function doctor( $args, $assoc_args ) {
		$node = Deploy_Runner::find_node();
		if ( is_wp_error( $node ) ) {
			\WP_CLI::error( $node->get_error_message(), false );
		} else {
			\WP_CLI::success( 'Node.js found: ' . $node );
		}
		$cli = Deploy_Runner::cli_path();
		is_readable( $cli ) ? \WP_CLI::success( 'Deployer bundle present.' ) : \WP_CLI::error( 'Deployer bundle missing: ' . $cli, false );
	}
}
