<?php

namespace WP_Static_Export;

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
			'zip'              => __( 'ZIP archive', 'wp-static-export' ),
			'local'            => __( 'Local directory', 'wp-static-export' ),
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
				'label'  => __( 'Deployment', 'wp-static-export' ),
				'fields' => array(
					self::f( 'delivery_method', __( 'Deliver the export to', 'wp-static-export' ), 'select', array( 'options' => $methods ) ),
					self::f( 'node_path', __( 'Node.js path', 'wp-static-export' ), 'text', array( 'help' => __( 'Only needed if "node" is not on the PATH (Node 18+).', 'wp-static-export' ), 'show_if' => array( 'delivery_method' => Deploy_Config::methods() ) ) ),

					// GitHub.
					self::f( 'github_personal_access_token', __( 'Personal access token', 'wp-static-export' ), 'password', array( 'secret' => true, 'help' => __( 'Fine-grained token with Contents: read & write on the repository.', 'wp-static-export' ), 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_repository', __( 'Repository (owner/repo)', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_branch', __( 'Branch', 'wp-static-export' ), 'text', array( 'default' => 'main', 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_folder', __( 'Sub-folder (optional)', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_commit_message', __( 'Commit message', 'wp-static-export' ), 'text', array( 'help' => __( 'Defaults to "Static export <timestamp>".', 'wp-static-export' ), 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_user', __( 'Commit author name', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'github' ) ) ),
					self::f( 'github_email', __( 'Commit author email', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'github' ) ) ),

					// Netlify.
					self::f( 'netlify_token', __( 'Personal access token', 'wp-static-export' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'netlify' ) ) ),
					self::f( 'netlify_site_id', __( 'Site ID', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'netlify' ) ) ),
					self::f( 'netlify_draft', __( 'Publish as draft (preview) deploy', 'wp-static-export' ), 'checkbox', array( 'show_if' => array( 'delivery_method' => 'netlify' ) ) ),

					// Cloudflare Pages.
					self::f( 'cloudflare_api_token', __( 'API token', 'wp-static-export' ), 'password', array( 'secret' => true, 'help' => __( 'Needs "Cloudflare Pages: Edit".', 'wp-static-export' ), 'show_if' => array( 'delivery_method' => 'cloudflare-pages' ) ) ),
					self::f( 'cloudflare_account_id', __( 'Account ID', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'cloudflare-pages' ) ) ),
					self::f( 'cloudflare_project', __( 'Pages project name', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'cloudflare-pages' ) ) ),
					self::f( 'cloudflare_branch', __( 'Branch', 'wp-static-export' ), 'text', array( 'help' => __( 'Leave empty for the production branch.', 'wp-static-export' ), 'show_if' => array( 'delivery_method' => 'cloudflare-pages' ) ) ),

					// S3.
					self::f( 'aws_access_key', __( 'Access key ID', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_secret_key', __( 'Secret access key', 'wp-static-export' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_region', __( 'Region', 'wp-static-export' ), 'text', array( 'default' => 'us-east-1', 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_bucket', __( 'Bucket', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_prefix', __( 'Key prefix (optional)', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_endpoint', __( 'Custom endpoint', 'wp-static-export' ), 'text', array( 'help' => __( 'For R2, MinIO, DigitalOcean Spaces, etc.', 'wp-static-export' ), 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_cache_control', __( 'Cache-Control header', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 's3' ) ) ),
					self::f( 'aws_distribution_id', __( 'CloudFront distribution ID', 'wp-static-export' ), 'text', array( 'help' => __( 'Invalidated after each deploy.', 'wp-static-export' ), 'show_if' => array( 'delivery_method' => 's3' ) ) ),

					// Bunny.
					self::f( 'cdn_access_key', __( 'Storage zone password', 'wp-static-export' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),
					self::f( 'cdn_storage_zone', __( 'Storage zone name', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),
					self::f( 'cdn_storage_region', __( 'Storage region code', 'wp-static-export' ), 'text', array( 'help' => __( 'Empty for Falkenstein; otherwise ny, la, sg, syd, uk, se, br, jh.', 'wp-static-export' ), 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),
					self::f( 'cdn_api_key', __( 'Account API key (for cache purge)', 'wp-static-export' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),
					self::f( 'cdn_pull_zone', __( 'Pull zone ID', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'bunny' ) ) ),

					// SFTP.
					self::f( 'sftp_host', __( 'Host', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_port', __( 'Port', 'wp-static-export' ), 'number', array( 'default' => 22, 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_user', __( 'Username', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_pass', __( 'Password', 'wp-static-export' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_private_key', __( 'Private key', 'wp-static-export' ), 'textarea', array( 'secret' => true, 'help' => __( 'Paste an OpenSSH/PEM key instead of a password.', 'wp-static-export' ), 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_passphrase', __( 'Key passphrase', 'wp-static-export' ), 'password', array( 'secret' => true, 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
					self::f( 'sftp_folder', __( 'Remote folder', 'wp-static-export' ), 'text', array( 'show_if' => array( 'delivery_method' => 'sftp' ) ) ),
				),
			),
			array(
				'id'          => 'report',
				'label'       => __( 'Export report', 'wp-static-export' ),
				'description' => __( 'After each export every published post and page is compared with the exported files, so you can see what is missing before the site goes live. Open the report under "Export Report".', 'wp-static-export' ),
				'fields'      => array(
					self::f( 'report_enabled', __( 'Verify every export', 'wp-static-export' ), 'checkbox', array( 'default' => true ) ),
					self::f( 'report_block_delivery', __( 'Stop before delivery if published items are missing or broken', 'wp-static-export' ), 'checkbox', array( 'help' => __( 'Nothing is uploaded or deployed when the check finds problems. The files stay in the export folder.', 'wp-static-export' ), 'show_if' => array( 'report_enabled' => true ) ) ),
					self::f( 'report_keep', __( 'Reports to keep', 'wp-static-export' ), 'number', array( 'default' => 5, 'show_if' => array( 'report_enabled' => true ) ) ),
				),
			),
			array(
				'id'          => 'instant',
				'label'       => __( 'Instant publishing', 'wp-static-export' ),
				'description' => __( 'Publish one post to the static site without exporting everything. Use "Publish to static site" in the post list, "wp static-export run --post=ID", or turn on automatic publishing. Single exports never delete anything and do not refresh the search index, archives or home page: run a full export for those.', 'wp-static-export' ),
				'fields'      => array(
					self::f( 'single_auto_export', __( 'Publish automatically when a post is published or updated', 'wp-static-export' ), 'checkbox' ),
					self::f( 'single_auto_export_delay', __( 'Wait this many seconds before exporting', 'wp-static-export' ), 'number', array( 'default' => 10, 'help' => __( 'Groups quick successive saves into one export.', 'wp-static-export' ), 'show_if' => array( 'single_auto_export' => true ) ) ),
				),
			),
			array(
				'id'          => 'forms',
				'label'       => __( 'Forms', 'wp-static-export' ),
				'description' => __( 'Static sites have no PHP, so form submissions are sent to a service instead. Each rule maps forms to a destination.', 'wp-static-export' ),
				'fields'      => array(
					self::f( 'forms_enabled', __( 'Rewrite forms in the export', 'wp-static-export' ), 'checkbox' ),
					self::f(
						'forms_rules',
						__( 'Form rules', 'wp-static-export' ),
						'repeater',
						array(
							'show_if' => array( 'forms_enabled' => true ),
							'help'    => __( 'First matching rule wins. "Match" is a form id, a CSS class, or * for every form. For Google Forms, field map lines look like: email=entry.123456', 'wp-static-export' ),
							'columns' => array(
								self::f( 'match', __( 'Match', 'wp-static-export' ), 'text', array( 'default' => '*' ) ),
								self::f(
									'provider',
									__( 'Send to', 'wp-static-export' ),
									'select',
									array(
										'options' => array(
											'google-form'  => 'Google Form',
											'google-sheet' => 'Google Sheet (Apps Script)',
											'netlify'      => 'Netlify Forms',
											'formspree'    => 'Formspree',
											'webhook'      => __( 'Webhook (JSON)', 'wp-static-export' ),
											'mailto'       => 'mailto:',
										),
									)
								),
								self::f( 'endpoint', __( 'URL / ID / address', 'wp-static-export' ), 'text' ),
								self::f( 'fields', __( 'Field map', 'wp-static-export' ), 'textarea' ),
								self::f( 'success_message', __( 'Success message', 'wp-static-export' ), 'text', array( 'default' => 'Thank you! Your message has been sent.' ) ),
							),
						)
					),
				),
			),
			array(
				'id'          => 'search',
				'label'       => __( 'Search', 'wp-static-export' ),
				'description' => __( 'Adds client-side search. Place <div data-ss-search></div> in a page, or enable the automatic replacement of the WordPress search form.', 'wp-static-export' ),
				'fields'      => array(
					self::f( 'static_search_enabled', __( 'Enable static search', 'wp-static-export' ), 'checkbox' ),
					self::f( 'static_search_engine', __( 'Engine', 'wp-static-export' ), 'select', array( 'options' => array( 'fuse' => 'Fuse.js (built-in index)', 'algolia' => 'Algolia' ), 'show_if' => array( 'static_search_enabled' => true ) ) ),
					self::f( 'static_search_replace_forms', __( 'Replace WordPress search forms', 'wp-static-export' ), 'checkbox', array( 'show_if' => array( 'static_search_enabled' => true ) ) ),
					self::f( 'static_search_include_archives', __( 'Also index category, tag, author and date archives', 'wp-static-export' ), 'checkbox', array( 'help' => __( 'Off by default: archives repeat other pages\' excerpts and push real content down the results.', 'wp-static-export' ), 'show_if' => array( 'static_search_enabled' => true ) ) ),
					self::f( 'static_search_exclude', __( 'Exclude URLs (one per line, * wildcard)', 'wp-static-export' ), 'textarea', array( 'show_if' => array( 'static_search_enabled' => true ) ) ),
					self::f( 'static_search_algolia_app_id', __( 'Algolia application ID', 'wp-static-export' ), 'text', array( 'show_if' => array( 'static_search_engine' => 'algolia' ) ) ),
					self::f( 'static_search_algolia_search_key', __( 'Algolia search-only API key', 'wp-static-export' ), 'text', array( 'help' => __( 'Public: it ships in the page. Never use the admin key here.', 'wp-static-export' ), 'show_if' => array( 'static_search_engine' => 'algolia' ) ) ),
					self::f( 'static_search_algolia_admin_key', __( 'Algolia admin API key', 'wp-static-export' ), 'password', array( 'secret' => true, 'help' => __( 'Used only on the server to push records.', 'wp-static-export' ), 'show_if' => array( 'static_search_engine' => 'algolia' ) ) ),
					self::f( 'static_search_algolia_index', __( 'Algolia index name', 'wp-static-export' ), 'text', array( 'show_if' => array( 'static_search_engine' => 'algolia' ) ) ),
				),
			),
			array(
				'id'          => 'optimize',
				'label'       => __( 'Optimization', 'wp-static-export' ),
				'description' => __( 'Runs on the exported files with Node.js before delivery. Originals on your WordPress site are never touched.', 'wp-static-export' ),
				'fields'      => array(
					self::f( 'optimize_html', __( 'Minify HTML', 'wp-static-export' ), 'checkbox' ),
					self::f( 'optimize_css', __( 'Minify CSS', 'wp-static-export' ), 'checkbox' ),
					self::f( 'optimize_js', __( 'Minify JavaScript', 'wp-static-export' ), 'checkbox' ),
					self::f( 'optimize_images', __( 'Compress images', 'wp-static-export' ), 'checkbox', array( 'help' => __( 'Needs the optional "sharp" module in the deployer folder.', 'wp-static-export' ) ) ),
					self::f( 'optimize_images_webp', __( 'Also create WebP versions and serve them via <picture>', 'wp-static-export' ), 'checkbox', array( 'show_if' => array( 'optimize_images' => true ) ) ),
					self::f( 'optimize_images_quality', __( 'Image quality (1-100)', 'wp-static-export' ), 'number', array( 'default' => 80, 'show_if' => array( 'optimize_images' => true ) ) ),
					self::f( 'optimize_exclude', __( 'Skip files matching (one per line, * wildcard)', 'wp-static-export' ), 'textarea' ),
				),
			),
			array(
				'id'          => 'quality',
				'label'       => __( 'Quality (Lighthouse)', 'wp-static-export' ),
				'description' => __( 'Safe automatic fixes for common Lighthouse findings, applied to the exported HTML/CSS (needs Node.js). Run "npm run lighthouse" in the deployer folder to measure the effect.', 'wp-static-export' ),
				'fields'      => array(
					self::f( 'quality_enabled', __( 'Apply automatic fixes', 'wp-static-export' ), 'checkbox' ),
					self::f( 'quality_viewport', __( 'Add a viewport meta tag when missing', 'wp-static-export' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_lang', __( 'Language for <html> when missing', 'wp-static-export' ), 'text', array( 'help' => __( 'Empty uses the site language.', 'wp-static-export' ), 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_image_alt', __( 'Mark images without alt text as decorative (alt="")', 'wp-static-export' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_link_names', __( 'Name image-only links after the page they point to', 'wp-static-export' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_image_dimensions', __( 'Add width/height to images (prevents layout shift)', 'wp-static-export' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_lazy_images', __( 'Lazy-load images and iframes below the first one', 'wp-static-export' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_font_display', __( 'Add font-display: swap to web fonts', 'wp-static-export' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_noopener', __( 'Add rel="noopener" to links opening in a new tab', 'wp-static-export' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_headers', __( 'Create a _headers file with caching and security headers (Netlify, Cloudflare Pages)', 'wp-static-export' ), 'checkbox', array( 'default' => true, 'show_if' => array( 'quality_enabled' => true ) ) ),
					self::f( 'quality_meta_description', __( 'Generate missing meta descriptions from the first paragraph', 'wp-static-export' ), 'checkbox', array( 'help' => __( 'A guess: review the result. An SEO plugin is the better source.', 'wp-static-export' ), 'show_if' => array( 'quality_enabled' => true ) ) ),
				),
			),
			array(
				'id'          => 'comments',
				'label'       => __( 'Comments', 'wp-static-export' ),
				'description' => __( 'Existing comments stay in the export as static HTML. The comment form is replaced by a hosted service.', 'wp-static-export' ),
				'fields'      => array(
					self::f( 'comments_provider', __( 'Provider', 'wp-static-export' ), 'select', array( 'options' => array( '' => __( 'Keep WordPress comment form', 'wp-static-export' ), 'giscus' => 'Giscus (GitHub Discussions)', 'utterances' => 'Utterances (GitHub Issues)', 'disqus' => 'Disqus', 'remove' => __( 'Remove comment form', 'wp-static-export' ) ) ) ),
					self::f( 'comments_giscus_repo', __( 'Repository (owner/repo)', 'wp-static-export' ), 'text', array( 'show_if' => array( 'comments_provider' => array( 'giscus', 'utterances' ) ) ) ),
					self::f( 'comments_giscus_repo_id', __( 'Repository ID', 'wp-static-export' ), 'text', array( 'show_if' => array( 'comments_provider' => 'giscus' ) ) ),
					self::f( 'comments_giscus_category', __( 'Category', 'wp-static-export' ), 'text', array( 'show_if' => array( 'comments_provider' => 'giscus' ) ) ),
					self::f( 'comments_giscus_category_id', __( 'Category ID', 'wp-static-export' ), 'text', array( 'show_if' => array( 'comments_provider' => 'giscus' ) ) ),
					self::f( 'comments_disqus_shortname', __( 'Disqus shortname', 'wp-static-export' ), 'text', array( 'show_if' => array( 'comments_provider' => 'disqus' ) ) ),
					self::f( 'comments_theme', __( 'Theme', 'wp-static-export' ), 'text', array( 'default' => 'preferred_color_scheme', 'show_if' => array( 'comments_provider' => array( 'giscus', 'utterances' ) ) ) ),
				),
			),
			array(
				'id'          => 'multilingual',
				'label'       => __( 'Multilingual', 'wp-static-export' ),
				'description' => __( 'Works with WPML, Polylang and TranslatePress (directory-based language URLs like /fr/). Their own hreflang tags are kept in the export.', 'wp-static-export' ),
				'fields'      => array(
					self::f( 'ml_enabled', __( 'Export every language', 'wp-static-export' ), 'checkbox' ),
					self::f( 'ml_default_redirect', __( 'Redirect the root to the visitor\'s language', 'wp-static-export' ), 'checkbox', array( 'show_if' => array( 'ml_enabled' => true ) ) ),
				),
			),
		);

		return apply_filters( 'wpse_features_schema', $sections );
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
