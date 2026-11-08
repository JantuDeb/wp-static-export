<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uploads over SFTP with phpseclib (no PHP extension needed). The connection cannot outlive one PHP request, so
 * every step reconnects and continues from the saved position.
 */
class Provider_Sftp extends Provider {

	/**
	 * Builds the SFTP client; replaced in tests.
	 *
	 * @var callable|null function ( $host, $port ) => object with login, mkdir, put, rename, delete, is_dir
	 */
	public static $factory = null;

	public function id() {
		return 'sftp';
	}

	public function validate( array $config ) {
		Util::required( $config, array( 'host', 'username', 'folder' ), 'sftp' );
		if ( empty( $config['password'] ) && empty( $config['privateKey'] ) ) {
			throw new Deploy_Error( 'sftp: provide a password or privateKey' );
		}

		return $config;
	}

	/**
	 * @param array $config Settings.
	 * @return object Logged-in client.
	 * @throws Deploy_Error When it cannot connect or log in.
	 */
	protected function connect( array $config ) {
		$port = (int) ( $config['port'] ?? 22 );
		if ( self::$factory ) {
			$client = call_user_func( self::$factory, $config['host'], $port );
			$key    = ! empty( $config['privateKey'] ) ? $config['privateKey'] : $config['password'];
		} else {
			list( $sftp_class, $loader_class ) = $this->library();
			$client                            = new $sftp_class( $config['host'], $port, 15 );
			$key                               = ! empty( $config['privateKey'] )
				? $loader_class::load( $config['privateKey'], ! empty( $config['passphrase'] ) ? $config['passphrase'] : false )
				: $config['password'];
		}
		try {
			$ok = $client->login( $config['username'], $key );
		} catch ( \Exception $e ) {
			throw new Deploy_Error( 'SFTP connection to ' . $config['host'] . ' failed: ' . $e->getMessage() );
		}
		if ( ! $ok ) {
			throw new Deploy_Error( 'SFTP login to ' . $config['host'] . ' failed: check the username and password or key' );
		}

		return $client;
	}

	/**
	 * @return string[] SFTP and PublicKeyLoader class names.
	 * @throws Deploy_Error When phpseclib is not installed.
	 */
	protected function library() {
		Libraries::load();
		foreach ( array( 'WP_Static_Export\\Vendor\\phpseclib3', 'phpseclib3' ) as $ns ) {
			if ( class_exists( $ns . '\\Net\\SFTP' ) ) {
				return array( $ns . '\\Net\\SFTP', $ns . '\\Crypt\\PublicKeyLoader' );
			}
		}
		throw new Deploy_Error( 'The SFTP library (phpseclib) is not installed.' );
	}

	public function verify( array $config ) {
		$client = $this->connect( $config );
		if ( ! $client->is_dir( $config['folder'] ) ) {
			throw new Deploy_Error( 'Connected, but ' . $config['folder'] . ' does not exist on the server' );
		}

		return 'Connected to ' . $config['host'] . ', ' . $config['folder'] . ' exists';
	}

	public function advance( Context $ctx ) {
		$config = $ctx->config;
		$root   = rtrim( $config['folder'], '/' );
		$root   = '' === $root ? '/' : $root;
		$join   = static function ( $path ) use ( $root ) {
			return rtrim( $root, '/' ) . '/' . $path;
		};
		$s      = $ctx->state + array( 'dirs' => null, 'dir_at' => 0, 'up' => 0, 'del' => 0 );
		$total  = count( $ctx->changed );

		$client = $this->connect( $config );

		// Create every needed directory once, up front.
		if ( null === $s['dirs'] ) {
			$dirs = array();
			foreach ( $ctx->changed as $f ) {
				$dirs[ dirname( $join( $f['path'] ) ) ] = true;
			}
			$dirs         = array_keys( $dirs );
			sort( $dirs );
			$s['dirs']    = $dirs;
		}
		while ( $s['dir_at'] < count( $s['dirs'] ) ) {
			if ( $ctx->out_of_time() ) {
				$ctx->state = $s;

				return false;
			}
			$dir = $s['dirs'][ $s['dir_at'] ];
			if ( ! $client->is_dir( $dir ) && ! $client->mkdir( $dir, -1, true ) ) {
				throw new Deploy_Error( 'Could not create the folder ' . $dir . ' on the SFTP server' );
			}
			++$s['dir_at'];
		}

		while ( $s['up'] < $total ) {
			if ( $ctx->out_of_time() ) {
				$ctx->state = $s;

				return false;
			}
			$f      = $ctx->changed[ $s['up'] ];
			$remote = $join( $f['path'] );
			$tmp    = $remote . '.part';
			// Upload to a temp name then rename, so visitors never see a half-written file.
			if ( ! $client->put( $tmp, $ctx->read( $f ) ) ) {
				throw new Deploy_Error( 'Could not upload ' . $f['path'] . ' to the SFTP server' );
			}
			if ( ! $client->rename( $tmp, $remote ) ) {
				$client->delete( $remote );
				if ( ! $client->rename( $tmp, $remote ) ) {
					throw new Deploy_Error( 'Could not move ' . $f['path'] . ' into place on the SFTP server' );
				}
			}
			++$s['up'];
			if ( 0 === $s['up'] % 10 ) {
				$ctx->progress( $s['up'], $total, 'files' );
			}
		}
		$ctx->progress( $total, $total, 'files' );

		$deleted = $ctx->deleted;
		while ( $s['del'] < count( $deleted ) ) {
			if ( $ctx->out_of_time() ) {
				$ctx->state = $s;

				return false;
			}
			$client->delete( $join( $deleted[ $s['del'] ] ) );
			++$s['del'];
		}

		$ctx->result = array( 'uploaded' => $total, 'deleted' => count( $deleted ), 'message' => 'Uploaded to ' . $config['host'] . ':' . $root );

		return true;
	}
}
