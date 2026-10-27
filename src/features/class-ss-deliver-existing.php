<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deliver the export that is already on disk (ZIP, local folder or any deploy target) without crawling
 * the site again. Useful after a failed or mistaken deploy, or to push the same files somewhere else.
 */
class Deliver_Existing {

	const FLAG = 'wp-static-export-deliver-only';

	/** The flag only counts for this long, so a start that never completed cannot affect a later export. */
	const FLAG_TTL = 120;

	public static function init() {
		add_action( 'wpse_completed', array( __CLASS__, 'clear_flag' ), 5 );
	}

	public static function clear_flag() {
		delete_option( self::FLAG );
	}

	/**
	 * @return bool Whether the export being planned should only deliver.
	 */
	public static function active() {
		$armed = (int) get_option( self::FLAG, 0 );

		return $armed > 0 && ( time() - $armed ) <= self::FLAG_TTL;
	}

	/**
	 * Tasks for a deliver-only run.
	 *
	 * @param string $delivery_method Delivery method.
	 * @return string[]
	 */
	public static function task_list( $delivery_method ) {
		$tasks = array();
		if ( 'zip' === $delivery_method ) {
			$tasks[] = 'create_zip_archive';
		} elseif ( 'local' === $delivery_method ) {
			$tasks[] = 'transfer_files_locally';
		} elseif ( Deploy_Config::is_deploy_method( $delivery_method ) ) {
			$tasks[] = 'deploy';
		}
		$tasks[] = 'wrapup';

		return $tasks;
	}

	/**
	 * What is on disk from the last export.
	 *
	 * @return array{exists:bool,files:int,modified:int,truncated:bool}
	 */
	public static function info() {
		$dir   = untrailingslashit( Options::instance()->get_archive_dir() );
		$info  = array( 'exists' => false, 'files' => 0, 'modified' => 0, 'truncated' => false );
		$limit = 500000;
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return $info;
		}
		try {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				++$info['files'];
				$info['modified'] = max( $info['modified'], (int) $file->getMTime() );
				if ( $info['files'] >= $limit ) {
					$info['truncated'] = true;
					break;
				}
			}
		} catch ( \Exception $e ) {
			return $info;
		}
		$info['exists'] = $info['files'] > 0;

		return $info;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function start() {
		$info = self::info();
		if ( ! $info['exists'] ) {
			return new \WP_Error( 'ss_deliver_nothing', __( 'There is no export on disk to deliver. Run an export first.', 'wp-static-export' ) );
		}
		$method = Options::instance()->get( 'delivery_method' );
		if ( ! in_array( $method, array_merge( array( 'zip', 'local' ), Deploy_Config::methods() ), true ) ) {
			return new \WP_Error( 'ss_deliver_method', __( 'Choose a delivery method first.', 'wp-static-export' ) );
		}
		$job = Plugin::instance()->get_archive_creation_job();
		if ( $job->is_running() || $job->is_paused() ) {
			return new \WP_Error( 'ss_deliver_busy', __( 'Another export is running.', 'wp-static-export' ) );
		}

		$prepare = static function () {
			foreach ( array( '404-only', 'use-build', 'use-language', 'use-single' ) as $flag ) {
				delete_option( 'wp-static-export-' . $flag );
			}
			update_option( self::FLAG, time(), false );

			return 'export';
		};
		$restore = static function () {
			delete_option( self::FLAG );
		};

		return Plugin::instance()->run_static_export( 0, 'export', $prepare, $restore )
			? true
			: new \WP_Error( 'ss_deliver_not_started', __( 'The delivery could not be started.', 'wp-static-export' ) );
	}
}
