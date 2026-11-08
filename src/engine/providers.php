<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of deployment targets.
 */
class Providers {

	/**
	 * @param string $id Provider id.
	 * @return Provider
	 * @throws Deploy_Error For an unknown id.
	 */
	public static function get( $id ) {
		switch ( $id ) {
			case 'github':
				return new Provider_Github();
			case 'netlify':
				return new Provider_Netlify();
			case 'cloudflare-pages':
				return new Provider_Cloudflare_Pages();
			case 'cloudflare-workers':
				return new Provider_Cloudflare_Workers();
			case 's3':
				return new Provider_S3();
			case 'bunny':
				return new Provider_Bunny();
			case 'sftp':
				return new Provider_Sftp();
		}
		throw new Deploy_Error( 'Unknown provider: ' . $id );
	}
}
