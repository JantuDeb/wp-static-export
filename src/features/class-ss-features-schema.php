<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes every setting of the deploy / forms / search / optimize / comments / multilingual
 * features. The admin UI renders itself from this schema and the REST controller validates
 * against it, so adding a setting means adding one line here.
 *
 * Field keys:
 *  key      option name
 *  type     text | password | textarea | number | checkbox | select | repeater
 *  secret   true => never sent back to the browser; empty submit keeps the stored value
 *  show_if  [ option => value | [values] ] => only shown/used when the condition holds
 *  columns  (repeater) list of sub-fields (same shape, no nesting)
 */
class Features_Schema {

	/**
	 * @return array[]
	 */
	public static function sections() {
		$methods = array(
			'zip'              => __( 'ZIP archive', 'simply-static' ),
			'local'            => __( 'Local directory', 'simply-static' ),
			'github'           => 'GitHub',
			'netlify'          => 'Netlify',
			'cloudflare-pages' => 'Cloudflare Pages',
			's3'               => 'Amazon S3 / compatible',
			'bunny'            => 'BunnyCDN',
			'sftp'             => 'SFTP',
		);

		$sections = array(
			array(
				'id'     => 'deploy',
				'label'  => __( 'Deployment', 'simply-static' ),
				'fields' => array(
					self::f( 'delivery_method', __( 'Deliver the export to', 'simply-static' ), 'select', array( 'options' => $methods ) ),
					self::f( 'node_path', __( 'Node.js path', 'simply-static' ), 'text', array( 'help' => __( 'Only needed if "node" is not on the PATH (Node 18+).', 'simply-static' ), 'show_if' => array( 'delivery_method' => Deploy_Config::methods() ) ) ),

					// GitHub.
					self::f( 'github_personal_access_token', __( 'Personal access token', 'simply-static' ), 'password', array( 'secret' => true, 'help' => __( 'Fine-grained token with Contents: read & write on the repository.', 'simply-static' ), 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_repository', __( 'Repository (owner/repo)', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_branch', __( 'Branch', 'simply-static' ), 'text', array( 'default' => 'main', 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_folder', __( 'Sub-folder (optional)', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_commit_message', __( 'Commit message', 'simply-static' ), 'text', array( 'help' => __( 'Defaults to "Static export <timestamp>".', 'simply-static' ), 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_user', __( 'Commit author name', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_email', __( 'Commit author email', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'github' ) ) ),

					// Netlify.
					self::f( 'netlify_token', __( 'Personal access token', 'simply-static' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'netlify' ) ) ),
					self::f( 'netlify_site_id', __( 'Site ID', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'netlify' ) ) ),
					self::f( 'netlify_draft', __( 'Publish as draft (preview) deploy', 'simply-static' ), 'checkbox', array( 'show_if' => array( 'delivery_method' => 'netlify' ) ) ),

					// Cloudflare Pages.
					self::f( 'cloudflare_api_token', __( 'API token', 'simply-static' ), 'password', array( 'secret' => true, 'help' => __( 'Needs "Cloudflare Pages: Edit".', 'simply-static' ), 'show_if' => array( 'delivery_method' => 'cloudflare-pages' ) ) ),
					self::f( 'cloudflare_account_id', __( 'Account ID', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'cloudflare-pages' ) ) ),
					self::f( 'cloudflare_project', __( 'Pages project name', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'cloudflare-pages' ) ) ),
					self::f( 'cloudflare_branch', __( 'Branch', 'simply-static' ), 'text', array( 'help' => __( 'Leave empty for the production branch.', 'simply-static' ), 'show_if' => array( 'delivery_method' => 'cloudflare-pages' ) ) ),

					// S3.
					self::f( 'aws_access_key', __( 'Access key ID', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_secret_key', __( 'Secret access key', 'simply-static' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_region', __( 'Region', 'simply-static' ), 'text', array( 'default' => 'us-east-1', 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_bucket', __( 'Bucket', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_prefix', __( 'Key prefix (optional)', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_endpoint', __( 'Custom endpoint', 'simply-static' ), 'text', array( 'help' => __( 'For R2, MinIO, DigitalOcean Spaces, etc.', 'simply-static' ), 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_cache_control', __( 'Cache-Control header', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_distribution_id', __( 'CloudFront distribution ID', 'simply-static' ), 'text', array( 'help' => __( 'Invalidated after each deploy.', 'simply-static' ), 'show_if' => array( 'delivery_method' => 's3' ) ) ),

					// Bunny.
					self::f( 'cdn_access_key', __( 'Storage zone password', 'simply-static' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),
					self::f( 'cdn_storage_zone', __( 'Storage zone name', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),
					self::f( 'cdn_storage_region', __( 'Storage region code', 'simply-static' ), 'text', array( 'help' => __( 'Empty for Falkenstein; otherwise ny, la, sg, syd, uk, se, br, jh.', 'simply-static' ), 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),
					self::f( 'cdn_api_key', __( 'Account API key (for cache purge)', 'simply-static' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),
					self::f( 'cdn_pull_zone', __( 'Pull zone ID', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),

					// SFTP.
					self::f( 'sftp_host', __( 'Host', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_port', __( 'Port', 'simply-static' ), 'number', array( 'default' => 22, 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_user', __( 'Username', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_pass', __( 'Password', 'simply-static' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_private_key', __( 'Private key', 'simply-static' ), 'textarea', array( 'secret' => true, 'help' => __( 'Paste an OpenSSH/PEM key instead of a password.', 'simply-static' ), 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_passphrase', __( 'Key passphrase', 'simply-static' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_folder', __( 'Remote folder', 'simply-static' ), 'text', array( 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
				),
			),
			array(
				'id'          => 'forms',
				'label'       => __( 'Forms', 'simply-static' ),
				'description' => __( 'Static sites have no PHP, so form submissions are sent to a service instead. Each rule maps forms to a destination.', 'simply-static' ),
				'fields'      => array(
					self::f( 'forms_enabled', __( 'Rewrite forms in the export', 'simply-static' ), 'checkbox' ),
					self::f(
						'forms_rules',
						__( 'Form rules', 'simply-static' ),
						'repeater',
						array(
							'show_if' => array( 'forms_enabled' => true ),
							'help'    => __( 'First matching rule wins. "Match" is a form id, a CSS class, or * for every form. For Google Forms, field map lines look like: email=entry.123456', 'simply-static' ),
							'columns' => array(
								self::f( 'match', __( 'Match', 'simply-static' ), 'text', array( 'default' => '*' ) ),
								self::f(
									'provider',
									__( 'Send to', 'simply-static' ),
									'select',
									array(
										'options' => array(
											'google-form'  => 'Google Form',
											'google-sheet' => 'Google Sheet (Apps Script)',
											'netlify'      => 'Netlify Forms',
											'formspree'    => 'Formspree',
											'webhook'      => __( 'Webhook (JSON)', 'simply-static' ),
											'mailto'       => 'mailto:',
										),
									)
								),
								self::f( 'endpoint', __( 'URL / ID / address', 'simply-static' ), 'text' ),
								self::f( 'fields', __( 'Field map', 'simply-static' ), 'textarea' ),
								self::f( 'success_message', __( 'Success message', 'simply-static' ), 'text', array( 'default' => 'Thank you! Your message has been sent.' ) ),
							),
						)
					),
				),
			),
			array(
				'id'          => 'search',
				'label'       => __( 'Search', 'simply-static' ),
				'description' => __( 'Adds client-side search. Place <div data-ss-search></div> in a page, or enable the automatic replacement of the WordPress search form.', 'simply-static' ),
				'fields'      => array(
					self::f( 'static_search_enabled', __( 'Enable static search', 'simply-static' ), 'checkbox' ),
					self::f( 'static_search_engine', __( 'Engine', 'simply-static' ), 'select', array( 'options' => array( 'fuse' => 'Fuse.js (built-in index)', 'algolia' => 'Algolia' ), 'show_if' => array( 'static_search_enabled' => true ) ) ),
					self::f( 'static_search_replace_forms', __( 'Replace WordPress search forms', 'simply-static' ), 'checkbox', array( 'show_if' => array( 'static_search_enabled' => true ) ) ),
					self::f( 'static_search_exclude', __( 'Exclude URLs (one per line, * wildcard)', 'simply-static' ), 'textarea', array( 'show_if' => array( 'static_search_enabled' => true ) ) ),
					self::f( 'static_search_algolia_app_id', __( 'Algolia application ID', 'simply-static' ), 'text', array( 'show_if' => array( 'static_search_engine' => 'algolia' ) ) ),
					self::f( 'static_search_algolia_search_key', __( 'Algolia search-only API key', 'simply-static' ), 'text', array( 'help' => __( 'Public: it ships in the page. Never use the admin key here.', 'simply-static' ), 'show_if' => array( 'static_search_engine' => 'algolia' ) ) ),
					self::f( 'static_search_algolia_admin_key', __( 'Algolia admin API key', 'simply-static' ), 'password', array( 'secret' => true, 'help' => __( 'Used only on the server to push records.', 'simply-static' ), 'show_if' => array( 'static_search_engine' => 'algolia' ) ) ),
					self::f( 'static_search_algolia_index', __( 'Algolia index name', 'simply-static' ), 'text', array( 'show_if' => array( 'static_search_engine' => 'algolia' ) ) ),
				),
			),
			array(
				'id'          => 'optimize',
				'label'       => __( 'Optimization', 'simply-static' ),
				'description' => __( 'Runs on the exported files with Node.js before delivery. Originals on your WordPress site are never touched.', 'simply-static' ),
				'fields'      => array(
					self::f( 'optimize_html', __( 'Minify HTML', 'simply-static' ), 'checkbox' ),
					self::f( 'optimize_css', __( 'Minify CSS', 'simply-static' ), 'checkbox' ),
					self::f( 'optimize_js', __( 'Minify JavaScript', 'simply-static' ), 'checkbox' ),
					self::f( 'optimize_images', __( 'Compress images', 'simply-static' ), 'checkbox', array( 'help' => __( 'Needs the optional "sharp" module in the deployer folder.', 'simply-static' ) ) ),
					self::f( 'optimize_images_webp', __( 'Also create WebP versions and serve them via <picture>', 'simply-static' ), 'checkbox', array( 'show_if' => array( 'optimize_images' => true ) ) ),
					self::f( 'optimize_images_quality', __( 'Image quality (1-100)', 'simply-static' ), 'number', array( 'default' => 80, 'show_if' => array( 'optimize_images' => true ) ) ),
					self::f( 'optimize_exclude', __( 'Skip files matching (one per line, * wildcard)', 'simply-static' ), 'textarea' ),
				),
			),
			array(
				'id'          => 'quality',
				'label'       => __( 'Quality (Lighthouse)', 'simply-static' ),
				'description' => __( 'Safe automatic fixes for common Lighthouse findings, applied to the exported HTML/CSS (needs Node.js). Run "npm run lighthouse" in the deployer folder to measure the effect.', 'simply-static' ),
				'fields'      => array(
					self::f( 'quality_enabled', __( 'Apply automatic fixes', 'simply-static' ), 'checkbox' ),
					self::f( 'quality_viewport', __( 'Add a viewport meta tag when missing', 'simply-static' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_lang', __( 'Language for <html> when missing', 'simply-static' ), 'text', array( 'help' => __( 'Empty uses the site language.', 'simply-static' ), 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_image_alt', __( 'Mark images without alt text as decorative (alt="")', 'simply-static' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_link_names', __( 'Name image-only links after the page they point to', 'simply-static' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_image_dimensions', __( 'Add width/height to images (prevents layout shift)', 'simply-static' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_lazy_images', __( 'Lazy-load images and iframes below the first one', 'simply-static' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_font_display', __( 'Add font-display: swap to web fonts', 'simply-static' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_noopener', __( 'Add rel="noopener" to links opening in a new tab', 'simply-static' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_headers', __( 'Create a _headers file with caching and security headers (Netlify, Cloudflare Pages)', 'simply-static' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_meta_description', __( 'Generate missing meta descriptions from the first paragraph', 'simply-static' ), 'checkbox', array( 'help' => __( 'A guess: review the result. An SEO plugin is the better source.', 'simply-static' ), 'show_if' => array( 'quality_enabled' => true ) ) ),
				),
			),
			array(
				'id'          => 'comments',
				'label'       => __( 'Comments', 'simply-static' ),
				'description' => __( 'Existing comments stay in the export as static HTML. The comment form is replaced by a hosted service.', 'simply-static' ),
				'fields'      => array(
					self::f( 'comments_provider', __( 'Provider', 'simply-static' ), 'select', array( 'options' => array( '' => __( 'Keep WordPress comment form', 'simply-static' ), 'giscus' => 'Giscus (GitHub Discussions)', 'utterances' => 'Utterances (GitHub Issues)', 'disqus' => 'Disqus', 'remove' => __( 'Remove comment form', 'simply-static' ) ) ) ),
					self::f( 'comments_giscus_repo', __( 'Repository (owner/repo)', 'simply-static' ), 'text', array( 'show_if' => array( 'comments_provider' => array( 'giscus', 'utterances' ) ) ) ),
					self::f( 'comments_giscus_repo_id', __( 'Repository ID', 'simply-static' ), 'text', array( 'show_if' => array( 'comments_provider' => 'giscus' ) ) ),
					self::f( 'comments_giscus_category', __( 'Category', 'simply-static' ), 'text', array( 'show_if' => array( 'comments_provider' => 'giscus' ) ) ),
					self::f( 'comments_giscus_category_id', __( 'Category ID', 'simply-static' ), 'text', array( 'show_if' => array( 'comments_provider' => 'giscus' ) ) ),
					self::f( 'comments_disqus_shortname', __( 'Disqus shortname', 'simply-static' ), 'text', array( 'show_if' => array( 'comments_provider' => 'disqus' ) ) ),
					self::f( 'comments_theme', __( 'Theme', 'simply-static' ), 'text', array( 'default' => 'preferred_color_scheme', 'show_if' => array( 'comments_provider' => array( 'giscus', 'utterances' ) ) ) ),
				),
			),
			array(
				'id'          => 'multilingual',
				'label'       => __( 'Multilingual', 'simply-static' ),
				'description' => __( 'Works with WPML, Polylang and TranslatePress (directory-based language URLs like /fr/). Their own hreflang tags are kept in the export.', 'simply-static' ),
				'fields'      => array(
					self::f( 'ml_enabled', __( 'Export every language', 'simply-static' ), 'checkbox' ),
					self::f( 'ml_default_redirect', __( 'Redirect the root to the visitor\'s language', 'simply-static' ), 'checkbox', array( 'show_if' => array( 'ml_enabled' => true ) ) ),
				),
			),
		);

		return apply_filters( 'ss_features_schema', $sections );
	}

	/**
	 * @param string $key   Option name.
	 * @param string $label Label.
	 * @param string $type  Field type.
	 * @param array  $extra Extra keys.
	 * @return array
	 */
	protected static function f( $key, $label, $type, array $extra = array() ) {
		return array_merge(
			array(
				'key'    => $key,
				'label'  => $label,
				'type'   => $type,
				'secret' => false,
			),
			$extra
		);
	}

	/**
	 * Flatten the schema to key => field.
	 *
	 * @return array<string,array>
	 */
	public static function fields() {
		$out = array();
		foreach ( self::sections() as $section ) {
			foreach ( $section['fields'] as $field ) {
				$out[ $field['key'] ] = $field;
			}
		}

		return $out;
	}

	/**
	 * Coerce a submitted value according to its field definition.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function sanitize( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'checkbox':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			case 'number':
				return is_numeric( $value ) ? (int) $value : ( $field['default'] ?? 0 );
			case 'textarea':
				// Keep newlines (PEM keys, field maps) and strip tags.
				return is_string( $value ) ? str_replace( "\r\n", "\n", sanitize_textarea_field( $value ) ) : '';
			case 'select':
				$allowed = array_map( 'strval', array_keys( $field['options'] ?? array() ) );
				return in_array( (string) $value, $allowed, true ) ? (string) $value : (string) ( $field['default'] ?? '' );
			case 'repeater':
				$rows = array();
				if ( is_array( $value ) ) {
					foreach ( array_slice( array_values( $value ), 0, 100 ) as $row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}
						$clean = array();
						foreach ( $field['columns'] as $col ) {
							$clean[ $col['key'] ] = self::sanitize( $col, $row[ $col['key'] ] ?? ( $col['default'] ?? '' ) );
						}
						$rows[] = $clean;
					}
				}
				return $rows;
			case 'password':
			case 'text':
			default:
				// Tokens may contain characters sanitize_text_field would mangle only by trimming/stripping tags.
				return is_string( $value ) || is_numeric( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
		}
	}
}
