<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-processes the exported files before delivery, in time-boxed steps like the deploy:
 * Lighthouse fixes, minification, image optimization with WebP twins, and the search index.
 *
 * Job keys: dir, scope (full|partial), ops { fixes, optimize, search }, runDir (where the run file lives).
 */
class Process_Run {

	/** Beyond this the in-browser search is slower to start than a hosted one is worth. */
	const SEARCH_INDEX_WARN_BYTES = 5242880;

	/** Beyond this the records are not even collected for the in-browser index. */
	const SEARCH_RECORDS_MAX_BYTES = 31457280;

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
		$run = array( 'id' => $id, 'file' => rtrim( (string) ( $job['runDir'] ?? dirname( $dir ) ), '/\\' ) . '/process-run-' . $id . '.json' );
		Util::write_file( $run['file'], json_encode( array( 'phase' => 'init', 'cursor' => 0 ) ) );

		return $run;
	}

	/**
	 * @param array $run Descriptor.
	 * @return void
	 */
	public static function cleanup( array $run ) {
		foreach ( array( $run['file'] ?? '', preg_replace( '/\.json$/', '', $run['file'] ?? '' ) . '.records.jsonl' ) as $file ) {
			if ( $file && is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * @param string $dir Export dir.
	 * @return string Where results of earlier optimization are remembered.
	 */
	public static function state_file( $dir ) {
		return dirname( $dir ) . '/' . basename( $dir ) . '.process-state.json';
	}

	/**
	 * Advance for up to $seconds.
	 *
	 * @param array $run     Descriptor.
	 * @param array $job     Same job.
	 * @param float $seconds Budget.
	 * @return array state (running|done), logs, progress, result when done.
	 * @throws Deploy_Error On failure.
	 */
	public static function step( array $run, array $job, $seconds = 10.0 ) {
		$deadline = microtime( true ) + max( 0.0, (float) $seconds );
		$data     = Util::read_json( $run['file'] );
		if ( ! $data ) {
			throw new Deploy_Error( 'The processing state was lost. Start the export again.' );
		}
		$dir  = rtrim( (string) $job['dir'], '/\\' );
		$ops  = (array) ( $job['ops'] ?? array() );
		$logs = array();
		$prog = null;
		$ctx  = (object) array( 'dir' => $dir, 'deadline' => $deadline, 'job' => $job, 'ops' => $ops, 'logs' => &$logs, 'progress' => &$prog, 'run' => $run );

		// Phases run until one has to wait for the next step.
		do {
			$before = $data['phase'] . ':' . ( $data['cursor'] ?? 0 );
			self::phase( $data, $ctx );
			if ( $before === $data['phase'] . ':' . ( $data['cursor'] ?? 0 ) && 'done' !== $data['phase'] ) {
				break; // No progress possible right now.
			}
		} while ( 'done' !== $data['phase'] && microtime( true ) < $deadline );

		if ( 'done' === $data['phase'] ) {
			self::cleanup( $run );
			$parts = $data['parts'] ?? array();

			return array(
				'state'    => 'done',
				'logs'     => $logs,
				'progress' => $prog,
				'result'   => array(
					'uploaded' => 0,
					'deleted'  => 0,
					'created'  => array_values( array_unique( $data['created'] ?? array() ) ),
					'message'  => $parts ? 'Processed: ' . implode( '; ', $parts ) : 'Nothing to process',
				),
			);
		}
		Util::write_file( $run['file'], json_encode( $data ) );

		return array( 'state' => 'running', 'logs' => $logs, 'progress' => $prog );
	}

	/**
	 * Run one phase as far as time allows and set the next phase.
	 *
	 * @param array  $d   Run data (by reference).
	 * @param object $ctx Context.
	 * @return void
	 */
	protected static function phase( array &$d, $ctx ) {
		$ops = $ctx->ops;
		$dir = $ctx->dir;
		$log = static function ( $level, $message ) use ( $ctx ) {
			$ctx->logs[] = array( $level, $message );
		};
		$out_of_time = static function () use ( $ctx ) {
			return microtime( true ) >= $ctx->deadline;
		};

		switch ( $d['phase'] ) {
			case 'init':
				$d += array( 'parts' => array(), 'created' => array(), 'counts' => array(), 'fixed' => 0, 'saved' => 0, 'optimized' => 0, 'webp' => array(), 'titles' => array() );
				$d['files']  = Deploy_Run::list_files( $dir, array() );
				$d['cursor'] = 0;
				if ( ! empty( $ops['fixes'] ) ) {
					$d['phase'] = ! empty( $ops['fixes']['linkNames'] ) ? 'titles' : 'fixes';
				} elseif ( ! empty( $ops['optimize'] ) ) {
					$d['phase'] = 'hash';
				} elseif ( ! empty( $ops['search'] ) ) {
					$d['phase'] = 'search_prep';
				} else {
					$d['phase'] = 'done';
				}

				return;

			case 'titles':
				$n = count( $d['files'] );
				for ( $i = (int) $d['cursor']; $i < $n; $i++ ) {
					$path = $d['files'][ $i ][0];
					if ( preg_match( '/\.html?$/i', $path ) ) {
						$head = (string) file_get_contents( $dir . '/' . $path, false, null, 0, 65536 );
						if ( preg_match( '~<title[^>]*>(.*?)</title>~is', $head, $m ) ) {
							$t = Fixes::clean_title( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
							if ( '' !== $t ) {
								$d['titles'][ $path ] = $t;
							}
						}
					}
					$d['cursor'] = $i + 1;
					if ( $out_of_time() ) {
						return;
					}
				}
				$d['phase']  = 'fixes';
				$d['cursor'] = 0;

				return;

			case 'fixes':
				$fixes   = $ops['fixes'];
				$titles  = $d['titles'];
				$sizes   = array();
				$size_of = static function ( $site_path ) use ( $dir, &$sizes ) {
					if ( ! array_key_exists( $site_path, $sizes ) ) {
						$file            = $dir . '/' . $site_path;
						$sizes[ $site_path ] = is_file( $file ) ? Image_Info::size( (string) file_get_contents( $file, false, null, 0, Image_Info::HEAD_BYTES ) ) : null;
					}

					return $sizes[ $site_path ];
				};
				$n = count( $d['files'] );
				for ( $i = (int) $d['cursor']; $i < $n; $i++ ) {
					$path = $d['files'][ $i ][0];
					try {
						if ( preg_match( '/\.html?$/i', $path ) ) {
							$src = (string) file_get_contents( $dir . '/' . $path );
							Fixes::$failures = 0;
							list( $html, $counts ) = Fixes::fix_html( $src, array( 'pagePath' => $path, 'sizeOf' => $size_of, 'titleOf' => static function ( $f ) use ( $titles ) {
								return $titles[ $f ] ?? null;
							} ), $fixes );
							if ( Fixes::$failures && empty( $d['pcre_warned'] ) ) {
								$d['pcre_warned'] = true;
								$log( 'warn', 'Some fixes were skipped on ' . $path . ' (and possibly other pages) because the page is too large for PHP\'s pattern matcher. Raise pcre.backtrack_limit if you need them.' );
							}
							if ( $html !== $src ) {
								file_put_contents( $dir . '/' . $path, $html );
								++$d['fixed'];
							}
							foreach ( $counts as $k => $v ) {
								$d['counts'][ $k ] = ( $d['counts'][ $k ] ?? 0 ) + $v;
							}
						} elseif ( ! empty( $fixes['fontDisplay'] ) && preg_match( '/\.css$/i', $path ) ) {
							$src = (string) file_get_contents( $dir . '/' . $path );
							list( $css, $count ) = Fixes::font_display( $src );
							if ( $count ) {
								file_put_contents( $dir . '/' . $path, $css );
								++$d['fixed'];
								$d['counts']['fontDisplay'] = ( $d['counts']['fontDisplay'] ?? 0 ) + $count;
							}
						}
					} catch ( \Throwable $e ) {
						$log( 'warn', 'Could not apply fixes to ' . $path . ': ' . $e->getMessage() );
					}
					$d['cursor'] = $i + 1;
					if ( $i % 25 === 0 ) {
						$ctx->progress = array( $i + 1, $n, 'files checked' );
					}
					if ( $out_of_time() ) {
						return;
					}
				}

				if ( ! empty( $fixes['headers'] ) && ! is_file( $dir . '/_headers' ) ) {
					file_put_contents( $dir . '/_headers', Fixes::headers_file() );
					$d['created'][] = '_headers';
				}
				$summary = array();
				foreach ( $d['counts'] as $k => $v ) {
					$summary[] = $k . ' ' . $v;
				}
				$d['parts'][] = $summary ? 'fixed ' . $d['fixed'] . ' file(s) (' . implode( ', ', $summary ) . ')' : 'no Lighthouse fixes needed';
				$d['files']   = Deploy_Run::list_files( $dir, array() );
				$d['cursor']  = 0;
				$d['phase']   = ! empty( $ops['optimize'] ) ? 'hash' : ( ! empty( $ops['search'] ) ? 'search_prep' : 'done' );

				return;

			case 'hash':
			case 'hash2':
				$n = count( $d['files'] );
				for ( $i = (int) $d['cursor']; $i < $n; $i++ ) {
					$h = @hash_file( 'sha256', $dir . '/' . $d['files'][ $i ][0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$d['files'][ $i ][2] = false === $h ? '' : $h;
					$d['cursor']         = $i + 1;
					if ( 0 === ( $i + 1 ) % 40 && $out_of_time() ) {
						$ctx->progress = array( $i + 1, $n, 'files scanned' );

						return;
					}
				}
				$d['cursor'] = 0;
				if ( 'hash' === $d['phase'] ) {
					$d['state']       = Util::read_json( self::state_file( $dir ) );
					$d['phase']       = 'optimize';
					$d['opt_warned']  = false;
				} else {
					$state = array();
					foreach ( $d['files'] as $f ) {
						$state[ $f[0] ] = $f[2];
					}
					Util::write_file( self::state_file( $dir ), json_encode( (object) $state ) );
					$d['phase'] = ! empty( $ops['search'] ) ? 'search_prep' : 'done';
				}

				return;

			case 'optimize':
				$o        = $ops['optimize'];
				$quality  = max( 1, min( 100, (int) ( $o['quality'] ?? 80 ) ) );
				$images   = ! empty( $o['images'] ) && Image_Optimizer::available();
				if ( ! empty( $o['images'] ) && ! $images && empty( $d['opt_warned'] ) ) {
					$log( 'warn', 'Image optimization skipped: the PHP GD extension is not available.' );
					$d['opt_warned'] = true;
				}
				if ( $images && ! empty( $o['webp'] ) && ! Image_Optimizer::webp_available() && empty( $d['opt_warned'] ) ) {
					$log( 'warn', 'WebP images skipped: this PHP GD build has no WebP support.' );
					$d['opt_warned'] = true;
				}
				$n = count( $d['files'] );
				for ( $i = (int) $d['cursor']; $i < $n; $i++ ) {
					$f = $d['files'][ $i ];
					$d['cursor'] = $i + 1;
					if ( 0 === strpos( $f[0], 'ss-assets/' ) || Util::matches_any( $f[0], (array) ( $o['exclude'] ?? array() ) ) || ( ( $d['state'][ $f[0] ] ?? null ) === $f[2] ) ) {
						continue;
					}
					try {
						self::optimize_file( $d, $dir, $f, $o, $images, $quality );
					} catch ( \Throwable $e ) {
						$log( 'warn', 'Could not optimize ' . $f[0] . ': ' . $e->getMessage() );
					}
					if ( $i % 10 === 0 ) {
						$ctx->progress = array( $i + 1, $n, 'files optimized' );
					}
					if ( $out_of_time() ) {
						return;
					}
				}
				$d['files']  = Deploy_Run::list_files( $dir, array() );
				$d['cursor'] = 0;
				$d['phase']  = 'picture';

				return;

			case 'picture':
				$o = $ops['optimize'];
				// Wrap with every WebP twin that exists, not just those made in this run: a later partial export
				// re-fetches pages but keeps the twins, and its pages must be wrapped the same way.
				$twins = array_fill_keys( $d['webp'], true );
				foreach ( $d['files'] as $f ) {
					if ( preg_match( '/\.(jpe?g|png)\.webp$/i', $f[0] ) ) {
						$twins[ $f[0] ] = true;
					}
				}
				$n = count( $d['files'] );
				if ( ! empty( $o['webp'] ) && $twins ) {
					for ( $i = (int) $d['cursor']; $i < $n; $i++ ) {
						$path = $d['files'][ $i ][0];
						if ( preg_match( '/\.html?$/i', $path ) ) {
							$html = (string) file_get_contents( $dir . '/' . $path );
							$next = Image_Optimizer::add_picture_sources( $html, $path, $twins );
							if ( $next !== $html ) {
								file_put_contents( $dir . '/' . $path, $next );
							}
						}
						$d['cursor'] = $i + 1;
						if ( $out_of_time() ) {
							return;
						}
					}
				}
				$d['parts'][] = sprintf( 'optimized %d file(s), saved %d KB%s', $d['optimized'], round( $d['saved'] / 1024 ), $d['webp'] ? ', ' . count( $d['webp'] ) . ' WebP' : '' );
				$d['files']   = Deploy_Run::list_files( $dir, array() );
				$d['cursor']  = 0;
				$d['phase']   = 'hash2';

				return;

			case 'search_prep':
				$s = $ops['search'];
				if ( 'partial' === ( $ctx->job['scope'] ?? 'full' ) ) {
					// A partial export only holds some pages, so rebuilding would drop everything else from search.
					$log( 'warn', 'Search index not rebuilt: this export contains only some pages. Run a full export to refresh it.' );
					$d['phase'] = 'done';

					return;
				}
				$d['files']   = Deploy_Run::list_files( $dir, array() );
				$d['cursor']  = 0;
				$d['records'] = 0;
				@unlink( preg_replace( '/\.json$/', '', $ctx->run['file'] ) . '.records.jsonl' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$d['phase']   = 'search_scan';

				return;

			case 'search_scan':
				$s       = $ops['search'];
				$records = preg_replace( '/\.json$/', '', $ctx->run['file'] ) . '.records.jsonl';
				$fh      = fopen( $records, 'ab' );
				$n       = count( $d['files'] );
				for ( $i = (int) $d['cursor']; $i < $n; $i++ ) {
					$path = $d['files'][ $i ][0];
					$d['cursor'] = $i + 1;
					if ( ! preg_match( '/\.html?$/i', $path ) || preg_match( '#(^|/)404\.html$#', $path ) || Util::matches_any( $path, (array) ( $s['exclude'] ?? array() ) ) || Util::matches_any( Search_Index::file_path_to_url( $path ), (array) ( $s['exclude'] ?? array() ) ) ) {
						continue;
					}
					if ( empty( $s['includeArchives'] ) && Search_Index::is_archive_url( Search_Index::file_path_to_url( $path ) ) ) {
						continue;
					}
					$rec = Search_Index::extract_record( (string) file_get_contents( $dir . '/' . $path ), $path );
					if ( $rec ) {
						// "url<TAB>json": the index writer can sort by url without decoding every record.
						fwrite( $fh, $rec['url'] . "\t" . json_encode( $rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
						++$d['records'];
					}
					if ( 'fuse' === ( $s['engine'] ?? 'fuse' ) && fstat( $fh )['size'] > self::SEARCH_RECORDS_MAX_BYTES ) {
						fclose( $fh );
						$log( 'warn', 'The search content is too large for the built-in search. Use Algolia for a site this large.' );
						$d['parts'][] = 'WARNING: search index skipped (too large), use Algolia';
						$d['phase']   = 'done';

						return;
					}
					if ( 0 === $d['cursor'] % 20 && $out_of_time() ) {
						fclose( $fh );
						$ctx->progress = array( $d['cursor'], $n, 'pages indexed' );

						return;
					}
				}
				fclose( $fh );
				$d['cursor'] = 0;
				$d['phase']  = 'fuse' === ( $s['engine'] ?? 'fuse' ) ? 'search_write' : 'algolia';
				$d['alg_offset'] = 0;

				return;

			case 'search_write':
				$s       = $ops['search'];
				$index   = $s['indexPath'] ?? 'ss-assets/search-index.json';
				$source  = preg_replace( '/\.json$/', '', $ctx->run['file'] ) . '.records.jsonl';
				// Sort by url without holding the records in memory: keep only where each line starts.
				$lines = array();
				$in    = fopen( $source, 'rb' );
				$pos   = 0;
				while ( false !== ( $line = fgets( $in ) ) ) {
					$tab = strpos( $line, "\t" );
					$lines[] = array( substr( $line, 0, $tab ), $pos + $tab + 1, strlen( $line ) - $tab - 2 );
					$pos    += strlen( $line );
				}
				usort(
					$lines,
					static function ( $a, $b ) {
						return strcmp( $a[0], $b[0] );
					}
				);
				$target = $dir . '/' . $index;
				if ( ! is_dir( dirname( $target ) ) ) {
					mkdir( dirname( $target ), 0755, true );
				}
				$out = fopen( $target . '.tmp', 'wb' );
				fwrite( $out, '[' );
				foreach ( $lines as $i => $l ) {
					fseek( $in, $l[1] );
					fwrite( $out, ( $i ? ',' : '' ) . fread( $in, $l[2] ) );
				}
				fwrite( $out, ']' );
				fclose( $out );
				fclose( $in );
				rename( $target . '.tmp', $target );
				$d['created'][] = $index;
				$d['parts'][]   = 'search index with ' . count( $lines ) . ' page(s)';
				// Visitors download the whole index before their first search, so it has to stay small.
				$bytes = (int) filesize( $target );
				if ( $bytes > self::SEARCH_INDEX_WARN_BYTES ) {
					$mb = number_format( $bytes / 1048576, 1 );
					$log( 'warn', 'The search index is ' . $mb . ' MB, which every visitor downloads before searching. Use Algolia for a site this large.' );
					$d['parts'][] = 'WARNING: search index is ' . $mb . ' MB, consider Algolia';
				}
				$d['phase'] = 'done';

				return;

			case 'algolia':
				$s   = $ops['search'];
				$cfg = (array) ( $s['algolia'] ?? array() );
				if ( 0 === (int) $d['alg_offset'] ) {
					Search_Index::algolia_begin( $cfg );
				}
				$fh = fopen( preg_replace( '/\.json$/', '', $ctx->run['file'] ) . '.records.jsonl', 'rb' );
				fseek( $fh, (int) $d['alg_offset'] );
				$batch = array();
				while ( count( $batch ) < 500 && false !== ( $line = fgets( $fh ) ) ) {
					$batch[] = json_decode( substr( $line, strpos( $line, "\t" ) + 1 ), true );
				}
				$d['alg_offset'] = ftell( $fh );
				$eof             = feof( $fh ) || false === fgets( $fh );
				fclose( $fh );
				if ( $batch ) {
					Search_Index::algolia_batch( $cfg, $batch );
					$d['cursor'] = (int) $d['cursor'] + count( $batch );
					$ctx->progress = array( $d['cursor'], (int) $d['records'], 'records' );
				}
				if ( $eof || ! $batch ) {
					$d['parts'][] = $d['records'] . ' page(s) sent to Algolia';
					$d['phase']   = 'done';
				}

				return;
		}
		$d['phase'] = 'done';
	}

	/**
	 * Minify or recompress one file in place.
	 *
	 * @param array  $d       Run data.
	 * @param string $dir     Export dir.
	 * @param array  $f       File entry [ path, size, sha256 ].
	 * @param array  $o       Optimize options.
	 * @param bool   $images  Whether image optimization is possible.
	 * @param int    $quality Quality.
	 * @return void
	 */
	protected static function optimize_file( array &$d, $dir, array $f, array $o, $images, $quality ) {
		$path   = $f[0];
		$abs    = $dir . '/' . $path;
		$ext    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$is_min = (bool) preg_match( '/\.min\.(js|css)$/', $path );
		$before = (int) $f[1];
		$after  = null;

		if ( in_array( $ext, array( 'html', 'htm' ), true ) && ! empty( $o['html'] ) ) {
			$src = (string) file_get_contents( $abs );
			$out = Minifiers::looks_minified( $src ) ? $src : Html_Minify::minify( $src );
			if ( strlen( $out ) < strlen( $src ) ) {
				file_put_contents( $abs, $out );
				$after = strlen( $out );
			}
		} elseif ( 'css' === $ext && ! empty( $o['css'] ) && ! $is_min ) {
			$src = (string) file_get_contents( $abs );
			$out = Minifiers::looks_minified( $src ) ? $src : Minifiers::css( $src );
			if ( '' !== $out && strlen( $out ) < strlen( $src ) ) {
				file_put_contents( $abs, $out );
				$after = strlen( $out );
			}
		} elseif ( 'js' === $ext && ! empty( $o['js'] ) && ! $is_min ) {
			$src = (string) file_get_contents( $abs );
			$out = Minifiers::looks_minified( $src ) ? $src : Minifiers::js( $src );
			if ( '' !== $out && strlen( $out ) < strlen( $src ) ) {
				file_put_contents( $abs, $out );
				$after = strlen( $out );
			}
		} elseif ( $images && in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			if ( $before > 64 * 1024 * 1024 ) {
				return;
			}
			$res = Image_Optimizer::optimize( (string) file_get_contents( $abs ), $ext, $quality, ! empty( $o['webp'] ) );
			if ( null === $res ) {
				return;
			}
			if ( null !== $res['image'] ) {
				file_put_contents( $abs, $res['image'] );
				$after = strlen( $res['image'] );
			}
			if ( null !== $res['webp'] ) {
				file_put_contents( $abs . '.webp', $res['webp'] );
				$d['webp'][] = $path . '.webp';
				$d['created'][] = $path . '.webp';
			}
		}

		if ( null !== $after ) {
			++$d['optimized'];
			$d['saved'] += $before - $after;
		}
	}
}
