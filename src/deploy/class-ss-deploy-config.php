<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps plugin options to the JSON settings each TypeScript deploy provider expects.
 */
class Deploy_Config {

	/**
	 * Delivery methods handled by the Node deployer. Keys match the provider ids in deployer/src/types.ts.
	 *
	 * @return string[]
	 */
	public static function methods() {
		return array( 'github', 'netlify', 'cloudflare-pages', 's3', 'bunny', 'sftp' );
	}

	/**
	 * @param string $method Delivery method.
	 * @return bool
	 */
	public static function is_deploy_method( $method ) {
		return in_array( $method, self::methods(), true );
	}

	/**
	 * Build the provider config for a delivery method.
	 *
	 * Values missing here are reported by the provider's own validation, so the PHP side
	 * stays a thin mapping and the rules live in one place.
	 *
	 * @param string       $method  Delivery method.
	 * @param Options|null $options Options instance.
	 * @return array
	 */
	public static function build( $method, $options = null ) {
		$o   = $options ?: Options::instance();
		$get = static function ( $key, $default = '' ) use ( $o ) {
			$value = $o->get( $key );
			return ( null === $value || '' === $value ) ? $default : $value;
		};

		switch ( $method ) {
			case 'github':
				$config = array(
					'token'         => $get( 'github_personal_access_token' ),
					'repository'    => $get( 'github_repository' ),
					'branch'        => $get( 'github_branch', 'main' ),
					'folder'        => $get( 'github_folder' ),
					'commitMessage' => $get( 'github_commit_message' ),
					'authorName'    => $get( 'github_user' ),
					'authorEmail'   => $get( 'github_email' ),
				);
				break;

			case 'netlify':
				$config = array(
					'token'  => $get( 'netlify_token' ),
					'siteId' => $get( 'netlify_site_id' ),
					'draft'  => (bool) $get( 'netlify_draft', false ),
				);
				break;

			case 'cloudflare-pages':
				$config = array(
					'apiToken'  => $get( 'cloudflare_api_token' ),
					'accountId' => $get( 'cloudflare_account_id' ),
					'project'   => $get( 'cloudflare_project' ),
					'branch'    => $get( 'cloudflare_branch' ),
				);
				break;

			case 's3':
				$config = array(
					'accessKeyId'              => $get( 'aws_access_key' ),
					'secretAccessKey'          => $get( 'aws_secret_key' ),
					'region'                   => $get( 'aws_region', 'us-east-1' ),
					'bucket'                   => $get( 'aws_bucket' ),
					'endpoint'                 => $get( 'aws_endpoint' ),
					'prefix'                   => $get( 'aws_prefix' ),
					'cacheControl'             => $get( 'aws_cache_control' ),
					'cloudfrontDistributionId' => $get( 'aws_distribution_id' ),
				);
				break;

			case 'bunny':
				$config = array(
					'storageKey'  => $get( 'cdn_access_key' ),
					'storageZone' => $get( 'cdn_storage_zone' ),
					'region'      => $get( 'cdn_storage_region' ),
					'apiKey'      => $get( 'cdn_api_key' ),
					'pullZoneId'  => $get( 'cdn_pull_zone' ),
				);
				break;

			case 'sftp':
				$config = array(
					'host'       => $get( 'sftp_host' ),
					'port'       => (int) $get( 'sftp_port', 22 ),
					'username'   => $get( 'sftp_user' ),
					'password'   => $get( 'sftp_pass' ),
					'privateKey' => $get( 'sftp_private_key' ),
					'passphrase' => $get( 'sftp_passphrase' ),
					'folder'     => $get( 'sftp_folder' ),
				);
				break;

			default:
				$config = array();
		}

		// Empty strings mean "not set"; drop them so provider defaults apply.
		$config = array_filter(
			$config,
			static function ( $v ) {
				return '' !== $v && null !== $v;
			}
		);

		/**
		 * Filter the provider settings passed to the deployer.
		 *
		 * @param array  $config Provider settings.
		 * @param string $method Delivery method.
		 */
		return apply_filters( 'ss_deploy_config', $config, $method );
	}

	/**
	 * Stable identifier for the remote target so incremental state is not shared between targets.
	 *
	 * @param string $method Delivery method.
	 * @param array  $config Provider settings.
	 * @return string
	 */
	public static function target_key( $method, array $config ) {
		$identity = array(
			$method,
			$config['repository'] ?? '',
			$config['branch'] ?? '',
			$config['folder'] ?? '',
			$config['siteId'] ?? '',
			$config['project'] ?? '',
			$config['bucket'] ?? '',
			$config['prefix'] ?? '',
			$config['storageZone'] ?? '',
			$config['host'] ?? '',
		);

		return $method . '-' . substr( md5( wp_json_encode( $identity ) ), 0, 12 );
	}
}
