<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which post-processing the current export needs and builds the Node job for it.
 */
class Process_Config {

	/**
	 * A full export contains the whole site; single, build, update and 404-only exports a subset.
	 *
	 * @return string 'full'|'partial'
	 */
	public static function scope() {
		$type = Options::instance()->get( 'generate_type' ) ?: 'export';

		return ( 'export' === $type
			&& ! get_option( 'simply-static-use-single' )
			&& ! get_option( 'simply-static-use-build' )
			&& ! get_option( 'simply-static-404-only' ) ) ? 'full' : 'partial';
	}

	/**
	 * @param string $text Multiline option.
	 * @return string[]
	 */
	protected static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $text ) ), 'strlen' ) );
	}

	/**
	 * Operations that need Node (everything else is plain PHP file copying).
	 *
	 * @param Options|null $o Options.
	 * @return array{fixes?:array,optimize?:array,search?:array}
	 */
	public static function node_ops( $o = null ) {
		$o   = $o ?: Options::instance();
		$ops = array();

		if ( $o->get( 'quality_enabled' ) ) {
			// Individual switches default to on once the feature is enabled (the meta description guess is opt-in).
			$flag = static function ( $key, $default = true ) use ( $o ) {
				$value = $o->get( $key );

				return null === $value ? $default : (bool) $value;
			};
			$lang = trim( (string) $o->get( 'quality_lang' ) );
			if ( '' === $lang ) {
				$lang = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '';
			}
			$ops['fixes'] = array(
				'viewport'        => $flag( 'quality_viewport' ),
				'lang'            => '' !== $lang ? $lang : 'en',
				'noopener'        => $flag( 'quality_noopener' ),
				'imageAlt'        => $flag( 'quality_image_alt' ),
				'linkNames'       => $flag( 'quality_link_names' ),
				'imageDimensions' => $flag( 'quality_image_dimensions' ),
				'lazyImages'      => $flag( 'quality_lazy_images' ),
				'fontDisplay'     => $flag( 'quality_font_display' ),
				'metaDescription' => $flag( 'quality_meta_description', false ),
				// _headers is only understood by these two hosts; elsewhere it would be a stray file.
				'headers'         => $flag( 'quality_headers' ) && in_array( $o->get( 'delivery_method' ), array( 'netlify', 'cloudflare-pages' ), true ),
			);
		}

		if ( $o->get( 'optimize_html' ) || $o->get( 'optimize_css' ) || $o->get( 'optimize_js' ) || $o->get( 'optimize_images' ) ) {
			$ops['optimize'] = array(
				'html'    => (bool) $o->get( 'optimize_html' ),
				'css'     => (bool) $o->get( 'optimize_css' ),
				'js'      => (bool) $o->get( 'optimize_js' ),
				'images'  => (bool) $o->get( 'optimize_images' ),
				'webp'    => (bool) $o->get( 'optimize_images' ) && (bool) $o->get( 'optimize_images_webp' ),
				'quality' => max( 1, min( 100, (int) ( $o->get( 'optimize_images_quality' ) ?: 80 ) ) ),
				'exclude' => self::lines( $o->get( 'optimize_exclude' ) ),
			);
		}

		if ( $o->get( 'static_search_enabled' ) ) {
			$engine = 'algolia' === $o->get( 'static_search_engine' ) ? 'algolia' : 'fuse';
			$search = array(
				'engine'  => $engine,
				'exclude' => self::lines( $o->get( 'static_search_exclude' ) ),
			);
			if ( 'algolia' === $engine ) {
				$search['algolia'] = array(
					'appId'    => (string) $o->get( 'static_search_algolia_app_id' ),
					'adminKey' => (string) $o->get( 'static_search_algolia_admin_key' ),
					'index'    => (string) $o->get( 'static_search_algolia_index' ),
				);
			}
			$ops['search'] = $search;
		}

		return $ops;
	}

	/**
	 * Browser scripts the enabled features need, as export-relative path => plugin-relative source.
	 *
	 * @param Options|null $o Options.
	 * @return array<string,string>
	 */
	public static function assets( $o = null ) {
		$o      = $o ?: Options::instance();
		$assets = array();
		if ( $o->get( 'forms_enabled' ) && is_array( $o->get( 'forms_rules' ) ) && $o->get( 'forms_rules' ) ) {
			$assets[ Html_Enhancer::ASSET_DIR . '/forms.js' ] = 'deployer/dist/web/forms.js';
		}
		if ( $o->get( 'static_search_enabled' ) ) {
			$assets[ Html_Enhancer::ASSET_DIR . '/search.js' ] = 'deployer/dist/web/search.js';
		}

		return $assets;
	}

	/**
	 * Whether the "process" task has anything to do.
	 *
	 * @return bool
	 */
	public static function needed() {
		return (bool) ( self::assets() || self::node_ops() );
	}

	/**
	 * Copy the browser scripts into the export.
	 *
	 * @param string $archive_dir Archive directory.
	 * @return string[]|\WP_Error Export-relative paths written.
	 */
	public static function copy_assets( $archive_dir ) {
		$written = array();
		foreach ( self::assets() as $target => $source ) {
			$from = SIMPLY_STATIC_PATH . $source;
			if ( ! is_readable( $from ) ) {
				return new \WP_Error( 'ss_asset_missing', sprintf( /* translators: %s: file */ __( 'Missing script %s. Run "npm run build" in the deployer folder.', 'simply-static' ), $source ) );
			}
			$to = trailingslashit( $archive_dir ) . $target;
			wp_mkdir_p( dirname( $to ) );
			if ( ! copy( $from, $to ) ) {
				return new \WP_Error( 'ss_asset_copy', sprintf( /* translators: %s: file */ __( 'Could not write %s.', 'simply-static' ), $target ) );
			}
			$written[] = $target;
		}

		return $written;
	}

	/**
	 * Copy files created by processing (WebP twins, search index, scripts) to the local directory,
	 * since local transfer only copies files tracked as pages.
	 *
	 * @param string $destination_dir Destination.
	 * @param string $archive_dir     Archive.
	 * @return void
	 */
	public static function transfer_extra_files( $destination_dir, $archive_dir ) {
		$extra = Options::instance()->get( 'process_extra_files' );
		if ( ! is_array( $extra ) ) {
			return;
		}
		foreach ( $extra as $rel ) {
			$rel = ltrim( Util::normalize_slashes( (string) $rel ), '/' );
			if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
				continue; // Never let a crafted path escape the export.
			}
			$from = trailingslashit( $archive_dir ) . $rel;
			$to   = trailingslashit( $destination_dir ) . $rel;
			if ( is_file( $from ) ) {
				wp_mkdir_p( dirname( $to ) );
				copy( $from, $to );
			}
		}
	}
}
