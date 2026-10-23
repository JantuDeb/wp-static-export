<?php

declare(strict_types=1);

namespace Simply_Static\Tests\Unit;

use Simply_Static\Comments_Enhancer;
use Simply_Static\Deploy_Config;
use Simply_Static\Features_Schema;
use Simply_Static\Forms_Enhancer;
use Simply_Static\Html_Enhancer;
use Simply_Static\Options;
use Simply_Static\Process_Config;
use Simply_Static\Search_Enhancer;
use Simply_Static\Single_Export;
use Simply_Static\Tests\Support\UnitTestCase;
use Simply_Static\Tests\Support\WpTestEnvironment as WpEnv;

final class FeaturesTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		foreach ( array(
			'src/class-ss-plugin.php',
			'src/class-ss-util.php',
			'src/class-ss-options.php',
			'src/deploy/class-ss-deploy-config.php',
			'src/features/class-ss-features-schema.php',
			'src/features/class-ss-process-config.php',
			'src/features/class-ss-html-enhancer.php',
			'src/features/class-ss-forms-enhancer.php',
			'src/features/class-ss-comments-enhancer.php',
			'src/features/class-ss-search-enhancer.php',
			'src/features/class-ss-single-export.php',
		) as $file ) {
			$this->requireSource( $file );
		}
	}

	private function configure( array $options ): void {
		WpEnv::$options['simply-static'] = $options + array(
			'destination_url_type' => 'absolute',
			'destination_scheme'   => 'https://',
			'destination_host'     => 'static.example.com',
		);
		Options::reinstance();
	}

	private function page( string $url = 'https://wp.example.test/contact/' ): object {
		return (object) array( 'url' => $url );
	}

	private function doc( string $body ): string {
		return '<!DOCTYPE html><html><head><title>t</title></head><body>' . $body . '</body></html>';
	}

	// ---- forms ----------------------------------------------------------------

	public function test_forms_are_detached_marked_and_get_runtime_script(): void {
		$this->configure(
			array(
				'forms_enabled' => true,
				'forms_rules'   => array(
					array( 'match' => 'cf7', 'provider' => 'google-form', 'endpoint' => 'FORMID', 'fields' => 'a=entry.1', 'success_message' => 'Thanks' ),
				),
			)
		);
		$html = $this->doc( '<form action="/contact/#wpcf7" method="post" class="wpcf7-form init" id="f1"><input name="a"></form>' );
		$out  = Html_Enhancer::enhance( $html, $this->page() );

		$this->assertStringNotContainsString( 'action=', $out );
		$this->assertStringContainsString( 'data-ss-form="0"', $out );
		$this->assertStringContainsString( 'data-ss-name="f1"', $out );
		$this->assertStringContainsString( '<script src="https://static.example.com/ss-assets/forms.js" defer></script>', $out );
		$this->assertMatchesRegularExpression( '#<script id="ss-forms-config" type="application/json">\{.*"provider":"google-form".*\}</script>#', $out );
		$this->assertLessThan( strpos( $out, '</body>' ), strpos( $out, 'ss-forms-config' ) );
	}

	public function test_cf7_scripts_are_dropped_only_when_every_cf7_form_was_taken_over(): void {
		$scripts = '<script id="swv-js" src="/wp-content/plugins/contact-form-7/includes/swv/js/index.js?ver=6"></script>'
			. '<script id="contact-form-7-js-before">var wpcf7 = {"api":{"root":"\/wp-json\/"}};</script>'
			. '<script id="contact-form-7-js" src="/wp-content/plugins/contact-form-7/includes/js/index.js?ver=6"></script>'
			. '<script id="theme-js" src="/wp-content/themes/t/app.js"></script>';

		$this->configure( array( 'forms_enabled' => true, 'forms_rules' => array( array( 'match' => '*', 'provider' => 'webhook', 'endpoint' => 'https://h/x' ) ) ) );
		$out = Html_Enhancer::enhance( $this->doc( '<form class="wpcf7-form" action="/x"></form>' . $scripts ), $this->page() );
		$this->assertStringNotContainsString( 'contact-form-7', $out );
		$this->assertStringNotContainsString( 'wpcf7 =', $out );
		$this->assertStringContainsString( 'id="theme-js"', $out, 'unrelated scripts stay' );
		$this->assertStringContainsString( 'ss-assets/forms.js', $out );

		// A second CF7 form that no rule matches still needs CF7's scripts.
		$this->configure( array( 'forms_enabled' => true, 'forms_rules' => array( array( 'match' => '#mine', 'provider' => 'webhook', 'endpoint' => 'https://h/x' ) ) ) );
		$out = Html_Enhancer::enhance( $this->doc( '<form id="mine" class="wpcf7-form"></form><form id="other" class="wpcf7-form"></form>' . $scripts ), $this->page() );
		$this->assertStringContainsString( 'contact-form-7-js', $out );

		// The plugin list is extensible.
		add_filter( 'ss_forms_script_patterns', static function ( $p ) {
			$p['frm-fluent-form'] = array( 'fluentform' );
			return $p;
		} );
		$this->configure( array( 'forms_enabled' => true, 'forms_rules' => array( array( 'match' => '*', 'provider' => 'webhook', 'endpoint' => 'https://h/x' ) ) ) );
		$out = Html_Enhancer::enhance( $this->doc( '<form class="frm-fluent-form"></form><script id="fluentform-js" src="/x/fluentform/app.js"></script>' ), $this->page() );
		$this->assertStringNotContainsString( 'fluentform', $out );
	}

	public function test_search_comment_and_login_forms_are_left_alone(): void {
		$this->configure( array( 'forms_enabled' => true, 'forms_rules' => array( array( 'match' => '*', 'provider' => 'webhook', 'endpoint' => 'https://h/x' ) ) ) );
		$html = $this->doc(
			'<form role="search" action="/"></form><form id="commentform" action="/wp-comments-post.php"></form>'
			. '<form class="search-form" action="/"></form><form action="/wp-login.php" id="loginform"></form><form data-ss-skip action="/keep"></form>'
		);
		$out = Html_Enhancer::enhance( $html, $this->page() );
		$this->assertSame( $html, $out );
	}

	public function test_netlify_rule_adds_detection_attributes_and_form_name(): void {
		$this->configure( array( 'forms_enabled' => true, 'forms_rules' => array( array( 'match' => '#contact', 'provider' => 'netlify', 'endpoint' => '' ) ) ) );
		$out = Html_Enhancer::enhance( $this->doc( '<form id="contact" action="/x"><input name="m"></form>' ), $this->page() );
		$this->assertStringContainsString( 'data-netlify="true"', $out );
		$this->assertStringContainsString( 'name="contact"', $out );
		$this->assertStringContainsString( '<input type="hidden" name="form-name" value="contact">', $out );
	}

	public function test_rule_matching_precedence_and_shortcuts(): void {
		$rules = array(
			array( 'match' => 'gravityforms' ),
			array( 'match' => '.newsletter' ),
			array( 'match' => '*' ),
		);
		$this->assertSame( 0, Forms_Enhancer::match_rule( array( 'id' => 'gform_3' ), $rules ) );
		$this->assertSame( 1, Forms_Enhancer::match_rule( array( 'class' => 'x newsletter' ), $rules ) );
		$this->assertSame( 2, Forms_Enhancer::match_rule( array( 'id' => 'other' ), $rules ) );
		$this->assertNull( Forms_Enhancer::match_rule( array( 'id' => 'other' ), array( array( 'match' => '#nope' ) ) ) );
	}

	public function test_forms_disabled_or_without_rules_is_a_noop(): void {
		$html = $this->doc( '<form action="/x"></form>' );
		$this->configure( array( 'forms_enabled' => false, 'forms_rules' => array( array( 'match' => '*', 'provider' => 'webhook', 'endpoint' => 'x' ) ) ) );
		$this->assertSame( $html, Html_Enhancer::enhance( $html, $this->page() ) );
		$this->configure( array( 'forms_enabled' => true, 'forms_rules' => array() ) );
		$this->assertSame( $html, Html_Enhancer::enhance( $html, $this->page() ) );
	}

	public function test_config_json_cannot_break_out_of_the_script_element(): void {
		$this->configure( array( 'forms_enabled' => true, 'forms_rules' => array( array( 'match' => '*', 'provider' => 'webhook', 'endpoint' => '</script><script>alert(1)</script>' ) ) ) );
		$out = Html_Enhancer::enhance( $this->doc( '<form action="/x"></form>' ), $this->page() );
		$this->assertStringNotContainsString( '<script>alert(1)', $out );
	}

	// ---- asset urls -----------------------------------------------------------

	public function test_asset_urls_for_each_destination_type_and_page_depth(): void {
		$this->configure( array( 'destination_url_type' => 'absolute' ) );
		$this->assertSame( 'https://static.example.com/ss-assets/a.js', Html_Enhancer::asset_url( null, 'ss-assets/a.js' ) );

		$this->configure( array( 'destination_url_type' => 'relative', 'relative_path' => '/blog' ) );
		$this->assertSame( '/blog/ss-assets/a.js', Html_Enhancer::asset_url( null, '/ss-assets/a.js' ) );

		$this->configure( array( 'destination_url_type' => 'offline' ) );
		$this->assertSame( './ss-assets/a.js', Html_Enhancer::asset_url( $this->page( 'https://wp.example.test/' ), 'ss-assets/a.js' ) );
		$this->assertSame( '../../ss-assets/a.js', Html_Enhancer::asset_url( $this->page( 'https://wp.example.test/blog/post/' ), 'ss-assets/a.js' ) );
		$this->assertSame( '../ss-assets/a.js', Html_Enhancer::asset_url( $this->page( 'https://wp.example.test/blog/page.html' ), 'ss-assets/a.js' ) );
	}

	// ---- comments -------------------------------------------------------------

	public function test_comment_form_is_replaced_with_giscus_even_with_nested_divs(): void {
		$this->configure(
			array(
				'comments_provider'           => 'giscus',
				'comments_giscus_repo'        => 'me/repo',
				'comments_giscus_repo_id'     => 'R_1',
				'comments_giscus_category'    => 'General',
				'comments_giscus_category_id' => 'DIC_1',
			)
		);
		$html = $this->doc( '<ol><li>old comment<div class="reply"><a href="?replytocom=1" class="comment-reply-link">Reply</a></div></li></ol>'
			. '<div id="respond" class="comment-respond"><h3>Leave</h3><div class="inner"><form id="commentform"></form></div></div><footer>keep</footer>' );
		$out = Comments_Enhancer::process( $html );

		$this->assertStringNotContainsString( 'commentform', $out );
		$this->assertStringNotContainsString( 'comment-reply-link', $out );
		$this->assertStringContainsString( 'old comment', $out );
		$this->assertStringContainsString( 'src="https://giscus.app/client.js"', $out );
		$this->assertStringContainsString( 'data-repo="me/repo"', $out );
		$this->assertStringContainsString( '<footer>keep</footer>', $out );
	}

	public function test_comments_invalid_config_or_unknown_provider_leaves_page_untouched(): void {
		$html = $this->doc( '<div id="respond"><form id="commentform"></form></div>' );
		$this->configure( array( 'comments_provider' => 'giscus', 'comments_giscus_repo' => 'not a repo' ) );
		$this->assertSame( $html, Comments_Enhancer::process( $html ) );
		$this->configure( array( 'comments_provider' => 'disqus', 'comments_disqus_shortname' => 'bad"name' ) );
		$this->assertSame( $html, Comments_Enhancer::process( $html ) );
		$this->configure( array( 'comments_provider' => '' ) );
		$this->assertSame( $html, Comments_Enhancer::process( $html ) );
	}

	public function test_disqus_utterances_and_remove(): void {
		$html = $this->doc( '<div id="respond"><form></form></div>' );
		$this->configure( array( 'comments_provider' => 'disqus', 'comments_disqus_shortname' => 'my-site' ) );
		$this->assertStringContainsString( 'https://my-site.disqus.com/embed.js', Comments_Enhancer::process( $html ) );
		$this->configure( array( 'comments_provider' => 'utterances', 'comments_giscus_repo' => 'me/r' ) );
		$this->assertStringContainsString( 'https://utteranc.es/client.js', Comments_Enhancer::process( $html ) );
		$this->configure( array( 'comments_provider' => 'remove' ) );
		$this->assertStringNotContainsString( 'respond', Comments_Enhancer::process( $html ) );
	}

	// ---- search ---------------------------------------------------------------

	public function test_search_script_and_config_are_injected_per_engine(): void {
		$this->configure( array( 'static_search_enabled' => true, 'static_search_engine' => 'fuse', 'static_search_replace_forms' => true ) );
		$out = Search_Enhancer::process( $this->doc( 'x' ), $this->page() );
		$this->assertStringContainsString( '"indexUrl":"https://static.example.com/ss-assets/search-index.json"', $out );
		$this->assertStringContainsString( 'ss-assets/search.js', $out );

		$this->configure( array( 'static_search_enabled' => true, 'static_search_engine' => 'algolia', 'static_search_algolia_app_id' => 'APP', 'static_search_algolia_search_key' => 'PUB', 'static_search_algolia_index' => 'idx', 'static_search_algolia_admin_key' => 'ADMIN-SECRET' ) );
		$out = Search_Enhancer::process( $this->doc( 'x' ), $this->page() );
		$this->assertStringContainsString( '"searchKey":"PUB"', $out );
		$this->assertStringNotContainsString( 'ADMIN-SECRET', $out, 'the admin key must never reach the page' );

		$this->configure( array( 'static_search_enabled' => true, 'static_search_engine' => 'algolia' ) );
		$html = $this->doc( 'x' );
		$this->assertSame( $html, Search_Enhancer::process( $html, $this->page() ), 'unconfigured Algolia ships nothing' );
	}

	// ---- schema ---------------------------------------------------------------

	public function test_schema_sanitizes_by_type(): void {
		$fields = Features_Schema::fields();
		$this->assertTrue( Features_Schema::sanitize( $fields['forms_enabled'], 'true' ) );
		$this->assertFalse( Features_Schema::sanitize( $fields['forms_enabled'], '0' ) );
		$this->assertSame( 'zip', Features_Schema::sanitize( $fields['delivery_method'], 'zip' ) );
		$this->assertSame( '', Features_Schema::sanitize( $fields['delivery_method'], 'rm -rf' ), 'unknown select values are rejected' );
		$this->assertSame( 22, Features_Schema::sanitize( $fields['sftp_port'], 'abc' ) );
		$this->assertSame( 'main', Features_Schema::sanitize( $fields['github_branch'], ' <b>main</b> ' ) );

		$rows = Features_Schema::sanitize(
			$fields['forms_rules'],
			array(
				array( 'match' => '*', 'provider' => 'webhook', 'endpoint' => ' https://h/x ', 'evil' => 'dropped' ),
				array( 'provider' => 'not-a-provider' ),
				'garbage',
			)
		);
		$this->assertCount( 2, $rows );
		$this->assertSame( 'https://h/x', $rows[0]['endpoint'] );
		$this->assertArrayNotHasKey( 'evil', $rows[0] );
		$this->assertSame( '', $rows[1]['provider'] );
	}

	public function test_secret_fields_are_flagged_and_every_deploy_option_has_a_field(): void {
		$fields = Features_Schema::fields();
		foreach ( array( 'github_personal_access_token', 'netlify_token', 'cloudflare_api_token', 'aws_secret_key', 'cdn_access_key', 'cdn_api_key', 'sftp_pass', 'sftp_private_key', 'static_search_algolia_admin_key' ) as $key ) {
			$this->assertTrue( $fields[ $key ]['secret'], "$key must be a write-only secret" );
		}
	}

	public function test_every_option_read_by_deploy_config_is_editable_in_the_ui(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/deploy/class-ss-deploy-config.php' );
		preg_match_all( "/\\\$get\( '([a-z_]+)'/", $source, $m );
		$this->assertNotEmpty( $m[1] );
		$fields = Features_Schema::fields();
		foreach ( array_unique( $m[1] ) as $key ) {
			$this->assertArrayHasKey( $key, $fields, "$key is read by Deploy_Config but has no settings field" );
		}
	}

	// ---- deploy / process config ---------------------------------------------

	public function test_deploy_config_maps_options_and_drops_empty_values(): void {
		$this->configure( array( 'github_personal_access_token' => 't', 'github_repository' => 'o/r', 'github_branch' => '', 'github_user' => '' ) );
		$cfg = Deploy_Config::build( 'github' );
		$this->assertSame( array( 'token' => 't', 'repository' => 'o/r', 'branch' => 'main' ), $cfg );

		$this->configure( array( 'aws_access_key' => 'k', 'aws_secret_key' => 's', 'aws_bucket' => 'b' ) );
		$s3 = Deploy_Config::build( 's3' );
		$this->assertSame( 'us-east-1', $s3['region'] );
		$this->assertArrayNotHasKey( 'endpoint', $s3 );
	}

	public function test_target_key_differs_per_target_so_incremental_state_is_not_shared(): void {
		$a = Deploy_Config::target_key( 'github', array( 'repository' => 'o/a', 'branch' => 'main' ) );
		$b = Deploy_Config::target_key( 'github', array( 'repository' => 'o/b', 'branch' => 'main' ) );
		$this->assertNotSame( $a, $b );
		$this->assertSame( $a, Deploy_Config::target_key( 'github', array( 'repository' => 'o/a', 'branch' => 'main' ) ) );
	}

	public function test_process_config_decides_what_runs(): void {
		$this->configure( array() );
		$this->assertFalse( Process_Config::needed() );

		$this->configure( array( 'optimize_css' => true, 'optimize_exclude' => "/a/*\n\n  b.css " ) );
		$ops = Process_Config::node_ops();
		$this->assertSame( array( '/a/*', 'b.css' ), $ops['optimize']['exclude'] );
		$this->assertFalse( $ops['optimize']['webp'] );
		$this->assertTrue( Process_Config::needed() );

		$this->configure( array( 'forms_enabled' => true, 'forms_rules' => array( array( 'match' => '*' ) ) ) );
		$this->assertSame( array( 'ss-assets/forms.js' => 'deployer/dist/web/forms.js' ), Process_Config::assets() );
		$this->assertSame( array(), Process_Config::node_ops(), 'forms need no Node' );

		$this->configure( array( 'static_search_enabled' => true, 'static_search_engine' => 'algolia', 'static_search_algolia_admin_key' => 'k' ) );
		$this->assertSame( 'k', Process_Config::node_ops()['search']['algolia']['adminKey'] );
	}

	public function test_quality_fixes_default_on_once_enabled_with_description_guess_opt_in(): void {
		$this->configure( array() );
		$this->assertArrayNotHasKey( 'fixes', Process_Config::node_ops() );

		$this->configure( array( 'quality_enabled' => true, 'delivery_method' => 'netlify' ) );
		$fixes = Process_Config::node_ops()['fixes'];
		$this->assertTrue( $fixes['lazyImages'] );
		$this->assertTrue( $fixes['imageDimensions'] );
		$this->assertTrue( $fixes['linkNames'] );
		$this->assertFalse( $fixes['metaDescription'], 'invented descriptions must be opt-in' );
		$this->assertTrue( $fixes['headers'] );
		$this->assertTrue( Process_Config::needed() );

		$this->configure( array( 'quality_enabled' => true, 'delivery_method' => 's3', 'quality_lazy_images' => false, 'quality_lang' => 'fr-FR', 'quality_meta_description' => true ) );
		$fixes = Process_Config::node_ops()['fixes'];
		$this->assertFalse( $fixes['lazyImages'] );
		$this->assertFalse( $fixes['headers'], '_headers is only for Netlify / Cloudflare Pages' );
		$this->assertSame( 'fr-FR', $fixes['lang'] );
		$this->assertTrue( $fixes['metaDescription'] );
	}

	public function test_deploy_state_dir_is_excluded_from_the_uploads_crawl_and_the_deploy(): void {
		$this->requireSource( 'src/deploy/class-ss-deploy-runner.php' );
		\Simply_Static\Deploy_Runner::init();

		$skip = apply_filters( 'ss_skip_crawl_uploads_directories', array( 'tmp' ) );
		$this->assertContains( 'simply-static/deploy-state', $skip );
		$this->assertContains( 'tmp', $skip, 'existing entries are kept' );

		$ignore = \Simply_Static\Deploy_Runner::ignore_patterns();
		$this->assertContains( '*/simply-static/deploy-state/*', $ignore );
	}

	public function test_single_export_normalizes_ids_and_clears_its_one_shot_flag(): void {
		$this->assertSame( array( 5, 7 ), Single_Export::normalize_ids( '5, 7,x,0,5,-3' ) );
		$this->assertSame( array( 9 ), Single_Export::normalize_ids( array( '9', 0, null ) ) );
		$this->assertSame( array(), Single_Export::normalize_ids( '' ) );

		// Core's wrap-up never clears this flag; leaving it would make every later export a single-page export.
		WpEnv::$options['simply-static-use-single'] = '12';
		Single_Export::clear_flag();
		$this->assertArrayNotHasKey( 'simply-static-use-single', WpEnv::$options );

	}

	public function test_single_export_settings_exist_in_the_ui(): void {
		$fields = Features_Schema::fields();
		$this->assertSame( 'checkbox', $fields['single_auto_export']['type'] );
		$this->assertSame( 10, $fields['single_auto_export_delay']['default'] );
	}

	public function test_stale_node_run_state_is_cleared_so_a_crashed_export_cannot_poison_the_next(): void {
		$this->requireSource( 'src/deploy/class-ss-deploy-runner.php' );
		$log = tempnam( sys_get_temp_dir(), 'ssl' );
		$this->configure( array( 'deploy_run' => array( 'pid' => 0, 'log_file' => $log, 'job_file' => '' ), 'process_run' => array( 'pid' => 0, 'log_file' => '', 'job_file' => '' ), 'keep' => 'me' ) );

		\Simply_Static\Deploy_Runner::reset_runs();

		$o = Options::reinstance();
		$this->assertEmpty( $o->get( 'deploy_run' ) );
		$this->assertEmpty( $o->get( 'process_run' ) );
		$this->assertSame( 'me', $o->get( 'keep' ) );
		$this->assertFileDoesNotExist( $log, 'the stale log file is removed' );
	}

	public function test_scope_is_partial_for_single_update_and_404_exports(): void {
		$this->configure( array( 'generate_type' => 'export' ) );
		$this->assertSame( 'full', Process_Config::scope() );
		$this->configure( array( 'generate_type' => 'update' ) );
		$this->assertSame( 'partial', Process_Config::scope() );
		$this->configure( array( 'generate_type' => 'export' ) );
		WpEnv::$options['simply-static-use-single'] = '12';
		$this->assertSame( 'partial', Process_Config::scope() );
	}

	public function test_extra_files_are_copied_but_cannot_escape_the_export(): void {
		$base = sys_get_temp_dir() . '/ss-extra-' . bin2hex( random_bytes( 4 ) );
		mkdir( $base . '/archive/ss-assets', 0777, true );
		mkdir( $base . '/dest', 0777, true );
		file_put_contents( $base . '/archive/ss-assets/search-index.json', '[]' );
		file_put_contents( $base . '/secret.txt', 'top secret' );
		$this->configure( array( 'process_extra_files' => array( 'ss-assets/search-index.json', '../secret.txt', '' ) ) );

		Process_Config::transfer_extra_files( $base . '/dest', $base . '/archive' );

		$this->assertFileExists( $base . '/dest/ss-assets/search-index.json' );
		$this->assertFileDoesNotExist( $base . '/dest/secret.txt' );
		$this->assertSame( 'top secret', file_get_contents( $base . '/secret.txt' ) );
	}
}
