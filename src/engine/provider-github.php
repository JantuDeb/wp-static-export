<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commits the export to a GitHub repository with the Git Data API: one blob per changed file, then trees
 * in slices, one commit and a ref update. Resumable at every stage.
 */
class Provider_Github extends Provider {

	const TREE_SLICE = 500;

	public function id() {
		return 'github';
	}

	public function validate( array $config ) {
		Util::required( $config, array( 'token', 'repository' ), 'github' );
		if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', (string) $config['repository'] ) ) {
			throw new Deploy_Error( 'github: repository must look like owner/repo' );
		}

		return $config + array( 'branch' => 'main', 'apiUrl' => 'https://api.github.com' );
	}

	/**
	 * @param array $config Settings.
	 * @return array Headers.
	 */
	protected function headers( array $config ) {
		return array(
			'Authorization'        => 'Bearer ' . $config['token'],
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'wp-static-export',
			'Content-Type'         => 'application/json',
		);
	}

	/**
	 * @param array      $config Settings.
	 * @param string     $method Method.
	 * @param string     $path   Path below the repository.
	 * @param array|null $body   JSON body.
	 * @param int[]|null $ok     Accepted statuses.
	 * @return Response
	 */
	protected function call( array $config, $method, $path, $body = null, $ok = null ) {
		$base = rtrim( $config['apiUrl'], '/' ) . '/repos/' . $config['repository'];

		return Http::request(
			$method,
			$base . $path,
			$this->headers( $config ),
			null === $body ? null : json_encode( $body ),
			$ok ? array( 'ok' => static function ( $s ) use ( $ok ) {
				return in_array( $s, $ok, true );
			} ) : array()
		);
	}

	public function verify( array $config ) {
		$res  = Http::request( 'GET', rtrim( $config['apiUrl'], '/' ) . '/repos/' . $config['repository'], $this->headers( $config ) );
		$repo = $res->json();
		if ( isset( $repo['permissions'] ) && empty( $repo['permissions']['push'] ) ) {
			throw new Deploy_Error( 'Token can read ' . ( $repo['full_name'] ?? $config['repository'] ) . ' but cannot push to it' );
		}

		return 'Connected to ' . ( $repo['full_name'] ?? $config['repository'] );
	}

	public function advance( Context $ctx ) {
		$config = $ctx->config;
		$s      = $ctx->state + array( 'stage' => 'init', 'entries' => array(), 'blob' => 0, 'tree_at' => 0 );
		$prefix = trim( (string) ( $config['folder'] ?? '' ), '/' );
		$at     = static function ( $path ) use ( $prefix ) {
			return '' !== $prefix ? $prefix . '/' . $path : $path;
		};
		$branch = rawurlencode( $config['branch'] );

		if ( 'init' === $s['stage'] ) {
			$s['head']      = null;
			$s['base_tree'] = null;
			$ref            = $this->call( $config, 'GET', '/git/ref/heads/' . $branch, null, array( 200, 404, 409 ) );
			if ( 200 === $ref->status ) {
				$s['head']      = $ref->json()['object']['sha'];
				$s['base_tree'] = $this->call( $config, 'GET', '/git/commits/' . $s['head'] )->json()['tree']['sha'];
			} elseif ( 409 === $ref->status ) {
				// Empty repository: the Git Data API only works after the first commit.
				$ctx->log( 'Repository is empty, creating initial commit' );
				$this->call( $config, 'PUT', '/contents/.nojekyll', array( 'message' => 'Initialize repository', 'content' => '', 'branch' => $config['branch'] ) );
				$s['head']      = $this->call( $config, 'GET', '/git/ref/heads/' . $branch )->json()['object']['sha'];
				$s['base_tree'] = $this->call( $config, 'GET', '/git/commits/' . $s['head'] )->json()['tree']['sha'];
			}
			if ( ! $ctx->changed && ! $ctx->deleted ) {
				$ctx->result = array( 'uploaded' => 0, 'deleted' => 0, 'message' => 'Nothing to deploy' );

				return true;
			}
			$ctx->log( 'Uploading ' . count( $ctx->changed ) . ' blob(s)' );
			$s['stage'] = 'blobs';
		}

		if ( 'blobs' === $s['stage'] ) {
			$total = count( $ctx->changed );
			while ( $s['blob'] < $total ) {
				if ( $ctx->out_of_time() ) {
					$ctx->state = $s;

					return false;
				}
				$batch = array_slice( $ctx->changed, $s['blob'], min( $ctx->concurrency, 6 ) );
				$reqs  = array();
				foreach ( $batch as $i => $f ) {
					if ( $f['size'] > Context::MAX_FILE_BYTES ) {
						throw new Deploy_Error( $f['path'] . ' is too large for the GitHub API (limit 100 MB)' );
					}
					$reqs[ $i ] = new Request(
						'POST',
						rtrim( $config['apiUrl'], '/' ) . '/repos/' . $config['repository'] . '/git/blobs',
						$this->headers( $config ),
						json_encode( array( 'content' => base64_encode( $ctx->read( $f ) ), 'encoding' => 'base64' ) )
					);
				}
				$responses = Http::request_many( $reqs );
				foreach ( $batch as $i => $f ) {
					$s['entries'][] = array( 'path' => $at( $f['path'] ), 'mode' => '100644', 'type' => 'blob', 'sha' => $responses[ $i ]->json()['sha'] );
				}
				$s['blob'] += count( $batch );
				$ctx->progress( $s['blob'], $total, 'blobs' );
			}
			foreach ( $ctx->deleted as $d ) {
				$s['entries'][] = array( 'path' => $at( $d ), 'mode' => '100644', 'type' => 'blob', 'sha' => null );
			}
			$s['tree']  = $s['base_tree'];
			$s['stage'] = 'trees';
		}

		if ( 'trees' === $s['stage'] ) {
			// Trees are built in slices so very large exports don't exceed API payload limits.
			while ( $s['tree_at'] < count( $s['entries'] ) ) {
				if ( $ctx->out_of_time() ) {
					$ctx->state = $s;

					return false;
				}
				$part         = array_slice( $s['entries'], $s['tree_at'], self::TREE_SLICE );
				$s['tree']    = $this->call( $config, 'POST', '/git/trees', array( 'base_tree' => $s['tree'], 'tree' => $part ) )->json()['sha'];
				$s['tree_at'] += count( $part );
			}
			$s['stage'] = 'commit';
		}

		// Commit and move the branch.
		$message = ! empty( $config['commitMessage'] ) ? $config['commitMessage'] : 'Static export ' . gmdate( 'c' );
		$payload = array( 'message' => $message, 'tree' => $s['tree'], 'parents' => $s['head'] ? array( $s['head'] ) : array() );
		if ( ! empty( $config['authorName'] ) && ! empty( $config['authorEmail'] ) ) {
			$payload['author'] = array( 'name' => $config['authorName'], 'email' => $config['authorEmail'] );
		}
		$new = $this->call( $config, 'POST', '/git/commits', $payload )->json()['sha'];
		if ( $s['head'] ) {
			$this->call( $config, 'PATCH', '/git/refs/heads/' . $branch, array( 'sha' => $new ) );
		} else {
			$this->call( $config, 'POST', '/git/refs', array( 'ref' => 'refs/heads/' . $config['branch'], 'sha' => $new ) );
		}

		$ctx->result = array(
			'uploaded' => count( $ctx->changed ),
			'deleted'  => count( $ctx->deleted ),
			'url'      => 'https://github.com/' . $config['repository'] . '/commit/' . $new,
			'message'  => 'Committed ' . substr( $new, 0, 7 ) . ' to ' . $config['branch'],
		);

		return true;
	}
}
