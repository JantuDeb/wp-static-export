<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Housekeeping for the deploy engine (src/engine): where deploy state lives, what is never deployed, clearing runs
 * left by an interrupted export, and what this server can do.
 */
class Deploy_Runner {

	/** Directory (relative to uploads) holding incremental deploy state. */
	const STATE_DIR = 'wp-static-export/deploy-state';

	/** Options holding the descriptor of a running engine job between task calls, with the engine that owns each. */
	const RUN_OPTIONS = array(
		'deploy_run'  => 'deploy',
		'process_run' => 'process',
		'verify_run'  => 'site',
	);

	public static function init() {
		// A hard-killed export (PHP crash, worker restart) leaves its run descriptor behind, and the next
		// export would mistake it for its own job. Start every export, and end every export, clean.
		add_action( 'wpse_after_setup_task', array( __CLASS__, 'reset_runs' ) );
		add_action( 'wpse_completed', array( __CLASS__, 'reset_runs' ), 6 );

		// The state directory lives in uploads, which the crawler scans, and its files list every deployed path.
		add_filter(
			'wpse_skip_crawl_uploads_directories',
			static function ( $dirs ) {
				return array_merge( (array) $dirs, array( self::STATE_DIR ) );
			}
		);
	}

	/**
	 * Forget any engine job recorded by an earlier export, and delete its files.
	 *
	 * @return void
	 */
	public static function reset_runs() {
		$o       = Options::instance();
		$changed = false;
		$classes = array(
			'deploy'  => '\\WP_Static_Export\\Engine\\Deploy_Run',
			'process' => '\\WP_Static_Export\\Engine\\Process_Run',
			'site'    => '\\WP_Static_Export\\Engine\\Site_Check',
		);
		foreach ( self::RUN_OPTIONS as $key => $engine ) {
			$run = $o->get( $key );
			if ( ! empty( $run ) ) {
				if ( is_array( $run ) ) {
					$classes[ $engine ]::cleanup( $run );
				}
				$o->set( $key, null );
				$changed = true;
			}
		}
		if ( $changed ) {
			$o->save();
		}
	}

	/**
	 * Path globs (relative to the export) that must never be deployed even if they end up in it.
	 *
	 * @return string[]
	 */
	public static function ignore_patterns() {
		return apply_filters(
			'wpse_deploy_ignore',
			array(
				'*/' . self::STATE_DIR . '/*',
				'*/wp-static-export/temp-files/*',
				'*/ss-deploy-state/*',
			)
		);
	}

	/**
	 * Directory where incremental deploy state is kept between exports.
	 *
	 * @return string
	 */
	public static function state_dir() {
		$upload = wp_upload_dir( null, false );
		$dir    = trailingslashit( $upload['basedir'] ) . self::STATE_DIR . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// State only holds paths and hashes, but there is no reason to serve it.
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $dir . '.htaccess', "Require all denied\n" );
		}

		return $dir;
	}

	/**
	 * What this server can do for the features that need more than plain PHP.
	 *
	 * @return array{gd:bool,webp:bool,dom:bool,sftp:bool,minify:bool}
	 */
	public static function capabilities() {
		\WP_Static_Export\Engine\Libraries::load();
		$has = static function ( $names ) {
			foreach ( $names as $name ) {
				if ( class_exists( $name ) ) {
					return true;
				}
			}

			return false;
		};

		return array(
			'gd'     => \WP_Static_Export\Engine\Image_Optimizer::available(),
			'webp'   => \WP_Static_Export\Engine\Image_Optimizer::webp_available(),
			'dom'    => class_exists( '\\DOMDocument' ),
			'sftp'   => $has( array( 'WP_Static_Export\\Vendor\\phpseclib3\\Net\\SFTP', 'phpseclib3\\Net\\SFTP' ) ),
			'minify' => $has( array( 'WP_Static_Export\\Vendor\\MatthiasMullie\\Minify\\CSS', 'MatthiasMullie\\Minify\\CSS' ) ),
		);
	}

	/**
	 * Things the saved settings ask for that this server cannot do, in plain words.
	 *
	 * @param Options|null $o Options.
	 * @return string[]
	 */
	public static function warnings( $o = null ) {
		$o    = $o ?: Options::instance();
		$cap  = self::capabilities();
		$out  = array();
		if ( $o->get( 'optimize_images' ) && ! $cap['gd'] ) {
			$out[] = __( 'Image optimization is turned on, but this server\'s PHP has no GD extension. Ask your host to enable it.', 'wp-static-export' );
		} elseif ( $o->get( 'optimize_images' ) && $o->get( 'optimize_images_webp' ) && ! $cap['webp'] ) {
			$out[] = __( 'WebP images are turned on, but this server\'s PHP GD has no WebP support. Images are still compressed.', 'wp-static-export' );
		}
		if ( ( $o->get( 'optimize_html' ) || $o->get( 'optimize_css' ) || $o->get( 'optimize_js' ) ) && ! $cap['minify'] ) {
			$out[] = __( 'Minification is turned on, but the minifier library is missing from the plugin files.', 'wp-static-export' );
		}
		if ( 'sftp' === $o->get( 'delivery_method' ) && ! $cap['sftp'] ) {
			$out[] = __( 'SFTP is selected, but the SFTP library is missing from the plugin files.', 'wp-static-export' );
		}
		if ( ! $cap['dom'] ) {
			$out[] = __( 'The PHP DOM extension is missing. Search indexing and some Lighthouse fixes need it.', 'wp-static-export' );
		}

		return $out;
	}
}
