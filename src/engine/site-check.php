<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks through the exported files for problems a visitor would hit: broken internal links and assets, pages that are
 * really error output, empty files, missing titles, and links that need WordPress (wp-json, wp-login) and so cannot
 * work on a static host. Runs in time-boxed steps.
 *
 * Job keys: dir, hosts (names that mean "this site" in absolute links), maxFiles, runDir.
 */
class Site_Check {

	/** Each list in the result is capped so the report stays small; the totals keep counting. */
	const LIST_CAP = 200;

	/** Things that only exist on a running WordPress and 404 or download PHP on a static host. */
	const WP_ONLY = '~^/(wp-json(/|$)|xmlrpc\.php|wp-login\.php|wp-admin(/|$)|wp-cron\.php|wp-comments-post\.php)~i';

	const ERROR_SIGNATURES = array(
		array( '/There has been a critical error on this website/i', 'WordPress critical error' ),
		array( '/Error establishing a database connection/i', 'Database connection error' ),
		array( '~<b>(Fatal error|Parse error)</b>:|(^|\n)(PHP )?(Fatal|Parse) error:~i', 'PHP error' ),
		array( '~<b>(Warning|Notice|Deprecated)</b>:\s+.*\s+in\s+<b>~i', 'PHP warning printed in the page' ),
		array( '/Briefly unavailable for scheduled maintenance/i', 'Maintenance mode page' ),
	);

	/**
	 * @param array $job Job.
	 * @return array Descriptor.
	 * @throws Deploy_Error If the folder is missing.
	 */
	public static function start( array $job ) {
		$dir = rtrim( (string) ( $job['dir'] ?? '' ), '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			throw new Deploy_Error( 'Export directory not found: ' . $dir );
		}
		$id  = bin2hex( random_bytes( 6 ) );
		$run = array( 'id' => $id, 'file' => rtrim( (string) ( $job['runDir'] ?? dirname( $dir ) ), '/\\' ) . '/site-check-' . $id . '.json' );
		Util::write_file( $run['file'], json_encode( array( 'phase' => 'list' ) ) );

		return $run;
	}

	/**
	 * @param array $run Descriptor.
	 * @return void
	 */
	public static function cleanup( array $run ) {
		if ( ! empty( $run['file'] ) && is_file( $run['file'] ) ) {
			@unlink( $run['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * @param string $html Page HTML.
	 * @return string|null Name of the error signature found.
	 */
	public static function error_signature( $html ) {
		$head = substr( $html, 0, 300000 );
		foreach ( self::ERROR_SIGNATURES as $sig ) {
			if ( preg_match( $sig[0], $head ) ) {
				return $sig[1];
			}
		}

		return null;
	}

	/**
	 * @param array $run     Descriptor.
	 * @param array $job     Job.
	 * @param float $seconds Budget.
	 * @return array state, logs, progress, result when done.
	 * @throws Deploy_Error On failure.
	 */
	public static function step( array $run, array $job, $seconds = 10.0 ) {
		$deadline = microtime( true ) + max( 0.0, (float) $seconds );
		$d        = Util::read_json( $run['file'] );
		if ( ! $d ) {
			throw new Deploy_Error( 'The site check state was lost.' );
		}
		$dir  = rtrim( (string) $job['dir'], '/\\' );
		$max  = (int) ( $job['maxFiles'] ?? 200000 );
		$prog = null;

		if ( 'list' === $d['phase'] ) {
			$d['files']   = array();
			$d['html']    = array();
			$d['result']  = array( 'checked' => 0, 'broken_links' => array(), 'empty_files' => array(), 'error_pages' => array(), 'wp_only' => array(), 'no_title' => 0, 'broken_total' => 0, 'truncated' => false );
			$d['seen_b']  = array();
			$d['seen_w']  = array();
			foreach ( Deploy_Run::list_files( $dir, array() ) as $f ) {
				$d['files'][] = $f[0];
				if ( 0 === $f[1] && count( $d['result']['empty_files'] ) < self::LIST_CAP ) {
					$d['result']['empty_files'][] = $f[0];
				}
				if ( preg_match( '/\.html?$/i', $f[0] ) ) {
					$d['html'][] = $f[0];
				}
			}
			if ( count( $d['html'] ) > $max ) {
				$d['html']                  = array_slice( $d['html'], 0, $max );
				$d['result']['truncated']   = true;
			}
			$d['cursor'] = 0;
			$d['phase']  = 'scan';
		}

		if ( 'scan' === $d['phase'] ) {
			$files = array_flip( $d['files'] );
			$hosts = array_flip( array_map( 'strtolower', (array) ( $job['hosts'] ?? array() ) ) );
			$n     = count( $d['html'] );
			for ( $i = (int) $d['cursor']; $i < $n; $i++ ) {
				self::check_page( $d, $dir, $d['html'][ $i ], $files, $hosts );
				++$d['result']['checked'];
				$d['cursor'] = $i + 1;
				$prog        = array( $i + 1, $n, 'pages checked' );
				// At least one page per step, so a run can never stall.
				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}
			if ( $d['cursor'] >= $n ) {
				self::cleanup( $run );

				return array( 'state' => 'done', 'logs' => array(), 'progress' => array( $n, $n, 'pages checked' ), 'result' => $d['result'] );
			}
		}
		Util::write_file( $run['file'], json_encode( $d ) );

		return array( 'state' => 'running', 'logs' => array(), 'progress' => $prog );
	}

	/**
	 * @param array  $d     Run data (by reference).
	 * @param string $dir   Export dir.
	 * @param string $rel   Page path.
	 * @param array  $files Set of all file paths.
	 * @param array  $hosts Set of host names that mean this site.
	 * @return void
	 */
	protected static function check_page( array &$d, $dir, $rel, array $files, array $hosts ) {
		$html = (string) file_get_contents( $dir . '/' . $rel );
		if ( '' === trim( $html ) ) {
			return;
		}
		$is_404 = (bool) preg_match( '#(^|/)404\.html$#', $rel );
		$sig    = $is_404 ? null : self::error_signature( $html );
		if ( $sig && count( $d['result']['error_pages'] ) < self::LIST_CAP ) {
			$d['result']['error_pages'][] = array( 'path' => $rel, 'signature' => $sig );
		}

		$title    = preg_match( '~<title[^>]*>(.*?)</title>~is', $html, $m ) ? trim( $m[1] ) : '';
		$redirect = (bool) preg_match( '~<meta\b[^>]*http-equiv=["\']refresh["\']~i', $html );
		if ( '' === $title && ! $redirect ) {
			++$d['result']['no_title'];
		}

		$dirname  = dirname( '/' . $rel );
		$page_url = '/' . preg_replace( '~(^|/)index\.html$~', '$1', $rel );
		if ( ! preg_match_all( '~<(a|img|script|link)\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>~i', $html, $tags ) ) {
			return;
		}
		foreach ( $tags[0] as $i => $tag ) {
			$name = strtolower( $tags[1][ $i ] );
			// <link> also carries alternate/oembed/EditURI hints that nothing needs; only assets matter.
			if ( 'link' === $name && ! preg_match( '/\b(stylesheet|icon|preload|modulepreload)\b/i', (string) Fixes::attr( $tag, 'rel' ) ) ) {
				continue;
			}
			$v = Fixes::attr( $tag, 'a' === $name || 'link' === $name ? 'href' : 'src' );
			if ( null === $v ) {
				continue;
			}
			$v = trim( $v );
			if ( '' === $v || preg_match( '/^(data:|blob:|mailto:|tel:|javascript:|sms:|#)/i', $v ) ) {
				continue;
			}
			$parts = parse_url( 0 === strpos( $v, '//' ) ? 'https:' . $v : $v );
			if ( false === $parts ) {
				continue;
			}
			if ( isset( $parts['host'] ) && ! isset( $hosts[ strtolower( $parts['host'] ) ] ) ) {
				continue; // Someone else's site.
			}
			$path = $parts['path'] ?? '';
			if ( ! isset( $parts['host'] ) ) {
				if ( '' === $path ) {
					continue; // Query-only or fragment-only link to the same page.
				}
				if ( '/' !== $path[0] ) {
					$path = ( '/' === $dirname ? '' : $dirname ) . '/' . $path;
				}
			}
			$target = rawurldecode( Paths::normalize_path( $path ) );
			$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
			if ( preg_match( self::WP_ONLY, $target . $query ) || false !== strpos( $query, 'rest_route=' ) ) {
				$key = $target . $query;
				if ( ! isset( $d['seen_w'][ $key ] ) ) {
					$d['seen_w'][ $key ] = 1;
					if ( count( $d['result']['wp_only'] ) < self::LIST_CAP ) {
						$d['result']['wp_only'][] = array( 'path' => $page_url, 'url' => $key );
					}
				}
				continue;
			}
			if ( ! self::exists( $files, $target ) ) {
				if ( isset( $d['seen_b'][ $target ] ) ) {
					continue;
				}
				$d['seen_b'][ $target ] = 1;
				++$d['result']['broken_total'];
				if ( count( $d['result']['broken_links'] ) < self::LIST_CAP ) {
					$d['result']['broken_links'][] = array( 'from' => $page_url, 'to' => $target, 'status' => 404 );
				}
			}
		}
	}

	/**
	 * Is a link target present in the export, the way a static host would serve it?
	 *
	 * @param array  $files Set of paths.
	 * @param string $path  Link path.
	 * @return bool
	 */
	protected static function exists( array $files, $path ) {
		$clean = trim( $path, '/' );
		if ( '' === $clean ) {
			return isset( $files['index.html'] );
		}

		return isset( $files[ $clean ] ) || isset( $files[ $clean . '/index.html' ] ) || isset( $files[ $clean . '.html' ] );
	}
}
