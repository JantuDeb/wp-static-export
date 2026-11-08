<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit\Engine;

use WP_Static_Export\Engine\Html_Minify;
use WP_Static_Export\Engine\Http;
use WP_Static_Export\Engine\Image_Optimizer;
use WP_Static_Export\Engine\Minifiers;
use WP_Static_Export\Engine\Response;
use WP_Static_Export\Engine\Search_Index;
use WP_Static_Export\Engine\Util;
use WP_Static_Export\Tests\Support\EngineTestCase;
use WP_Static_Export\Tests\Support\FakeTransport;

final class ProcessTest extends EngineTestCase {

	/** A photo-like JPEG: smooth gradient plus light noise, so a low quality is clearly smaller than q100. */
	private function photo( int $w, int $h, int $quality ): string {
		$img = imagecreatetruecolor( $w, $h );
		mt_srand( 5 );
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$v = (int) ( ( $x + $y ) / 3 ) + mt_rand( 0, 6 );
				imagesetpixel( $img, $x, $y, imagecolorallocate( $img, $v % 256, ( $v * 2 ) % 256, ( $v + 40 ) % 256 ) );
			}
		}
		ob_start();
		imagejpeg( $img, null, $quality );

		return (string) ob_get_clean();
	}

	private function sha_all( string $dir, array $files ): array {
		return array_map( static function ( $f ) use ( $dir ) { return hash_file( 'sha256', "$dir/$f" ); }, $files );
	}

	public function test_glob_patterns_match_with_or_without_a_leading_slash(): void {
		$this->assertTrue( Util::matches_any( 'blog/post/index.html', array( '/blog/*' ) ) );
		$this->assertFalse( Util::matches_any( 'about/index.html', array( '/blog/*' ) ) );
		$this->assertTrue( Util::matches_any( 'a.min.js', array( '*.min.js' ) ) );
		$this->assertFalse( Util::matches_any( 'x', null ) );
	}

	public function test_html_is_minified_without_gluing_inline_elements_or_touching_pre(): void {
		$out = Html_Minify::minify( "<p>Hello   <b>big</b>  <i>world</i></p>\n<!-- c -->\n<pre>a   b\n  c</pre><script>var   x = 1 ;  console.log( x )</script>" );
		$this->assertStringNotContainsString( '<!--', $out );
		$this->assertStringContainsString( '<b>big</b> <i>world</i>', $out );
		$this->assertStringContainsString( "<pre>a   b\n  c</pre>", $out );
		$this->assertLessThan( 120, strlen( $out ) );
	}

	public function test_conditional_comments_and_json_scripts_survive_minification(): void {
		$in  = "<head><!--[if IE]><link rel=\"stylesheet\" href=\"ie.css\"><![endif]-->\n<script type=\"application/ld+json\">{ \"a\":  1 }</script></head>";
		$out = Html_Minify::minify( $in );
		$this->assertStringContainsString( '<!--[if IE]>', $out );
		$this->assertStringContainsString( '{ "a":  1 }', $out );
	}

	public function test_css_and_js_are_minified(): void {
		$this->assertSame( 'a{color:red}b{margin:0}', Minifiers::css( "a {  color : red ; }\n\nb { margin: 0px }" ) );
		$this->assertLessThan( 70, strlen( Minifiers::js( "function add(first, second) {\n  return first + second;\n}\nconsole.log(add(1, 2));" ) ) );
	}

	public function test_css_minification_never_reads_other_files(): void {
		$dir = $this->site( array( 'other.css' => 'p{color:blue}' ) );
		$cwd = getcwd();
		chdir( $dir );
		try {
			$out = Minifiers::css( "@import 'other.css';\na { color: red }" );
		} finally {
			chdir( $cwd );
		}
		$this->assertStringNotContainsString( 'color:blue', $out );
	}

	public function test_looks_minified_separates_readable_source_from_minified_output(): void {
		$this->assertTrue( Minifiers::looks_minified( str_repeat( 'a', 1000 ) ) );
		$this->assertFalse( Minifiers::looks_minified( implode( "\n", array_fill( 0, 50, 'var x = 1; // readable' ) ) ) );
		$this->assertFalse( Minifiers::looks_minified( 'tiny' ) );
		// Multi-byte text: 60 readable Hindi lines are ~3 bytes per character but still not minified.
		$this->assertFalse( Minifiers::looks_minified( implode( "\n", array_fill( 0, 60, str_repeat( 'पढ़ाई ', 20 ) ) ) ) );
	}

	public function test_picture_rewrite_keeps_srcset_descriptors_and_sizes(): void {
		$webp = array( 'wp-content/uploads/a.jpg.webp' => true, 'wp-content/uploads/a-300.jpg.webp' => true );
		$html = '<img src="/wp-content/uploads/a.jpg" srcset="/wp-content/uploads/a-300.jpg 300w, /wp-content/uploads/a.jpg 600w" sizes="100vw" alt="x">';
		$out  = Image_Optimizer::add_picture_sources( $html, 'index.html', $webp );

		$this->assertStringContainsString( '<picture><source type="image/webp" srcset="/wp-content/uploads/a-300.jpg.webp 300w, /wp-content/uploads/a.jpg.webp 600w" sizes="100vw">', $out );
		$this->assertStringEndsWith( '</picture>', $out );
	}

	public function test_picture_rewrite_leaves_images_without_a_twin_or_already_wrapped_alone(): void {
		$webp = array( 'wp-content/uploads/a.jpg.webp' => true );
		$miss = '<img src="/wp-content/uploads/missing.jpg">';
		$this->assertSame( $miss, Image_Optimizer::add_picture_sources( $miss, 'index.html', $webp ) );
		$wrapped = '<picture><img src="/wp-content/uploads/a.jpg"></picture>';
		$this->assertSame( $wrapped, Image_Optimizer::add_picture_sources( $wrapped, 'index.html', $webp ) );
		$this->assertStringContainsString( 'type="image/webp"', Image_Optimizer::add_picture_sources( '<img src="../../wp-content/uploads/a.jpg">', 'blog/post/index.html', $webp ) );
		// A second image after a finished <picture> is still wrapped.
		$this->assertSame( 2, substr_count( Image_Optimizer::add_picture_sources( '<picture><img src="/wp-content/uploads/a.jpg"></picture><img src="/wp-content/uploads/a.jpg">', 'index.html', $webp ), '<picture>' ) );
	}

	public function test_search_records_drop_navigation_scripts_and_footers_and_honour_noindex(): void {
		$html = '<html lang="de"><head><title>Über uns</title></head><body><nav>MENU</nav><main><h1>Team</h1><p>Wir bauen   Seiten.</p><script>var x=1</script></main><footer>FOOT</footer></body></html>';
		$this->assertSame( array( 'url' => '/ueber/', 'title' => 'Über uns', 'text' => 'Team Wir bauen Seiten.', 'lang' => 'de' ), Search_Index::extract_record( $html, 'ueber/index.html' ) );
		$this->assertNull( Search_Index::extract_record( '<html><head><meta name="robots" content="noindex,follow"><title>x</title></head><body>y</body></html>', 'x.html' ) );
		$this->assertSame( '/', Search_Index::file_path_to_url( 'index.html' ) );
		$this->assertSame( '/a/b/', Search_Index::file_path_to_url( 'a/b/index.html' ) );
		$this->assertSame( '/page.html', Search_Index::file_path_to_url( 'page.html' ) );
		$this->assertSame( 20, strlen( Search_Index::object_id( '/a/' ) ) );
	}

	public function test_archive_urls_are_recognised_but_not_real_content(): void {
		foreach ( array( '/category/news/', '/blog/category/news/page/2/', '/tag/x/', '/author/admin/', '/page/3/', '/blog/page/2/', '/2024/', '/2024/05/', '/2024/05/17/' ) as $u ) {
			$this->assertTrue( Search_Index::is_archive_url( $u ), $u );
		}
		foreach ( array( '/', '/about/', '/category-guide/', '/pages/', '/hiking-2024/', '/2024-plan/', '/blog/hello/' ) as $u ) {
			$this->assertFalse( Search_Index::is_archive_url( $u ), $u );
		}
	}

	public function test_rerunning_after_the_state_file_is_deleted_leaves_minified_files_byte_identical(): void {
		$dir = $this->site();
		mkdir( "$dir/a" );
		$js = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$js[] = "function handler$i(element, options) {\n  var settings = options || {};\n  if (!element) { return null; }\n  return element.getAttribute(\"data-$i\") + settings.value;\n}";
		}
		file_put_contents( "$dir/a/app.js", implode( "\n\n", $js ) );
		$css = array();
		for ( $i = 0; $i < 80; $i++ ) {
			$css[] = ".c$i {\n  margin: {$i}px;\n  color: #abc;\n}";
		}
		file_put_contents( "$dir/a/s.css", implode( "\n\n", $css ) );
		$p = array();
		for ( $i = 0; $i < 80; $i++ ) {
			$p[] = "  <p>Paragraph $i with   <b>bold</b>  text.</p>";
		}
		file_put_contents( "$dir/index.html", "<html><head><title>T</title></head><body>\n" . implode( "\n", $p ) . "\n</body></html>" );
		$job = array( 'dir' => $dir, 'ops' => array( 'optimize' => array( 'html' => true, 'css' => true, 'js' => true ) ) );

		$this->process( $job );
		$first = $this->sha_all( $dir, array( 'a/app.js', 'a/s.css', 'index.html' ) );
		@unlink( \WP_Static_Export\Engine\Process_Run::state_file( $dir ) );
		$this->process( $job );

		$this->assertSame( $first, $this->sha_all( $dir, array( 'a/app.js', 'a/s.css', 'index.html' ) ) );
		@unlink( \WP_Static_Export\Engine\Process_Run::state_file( $dir ) );
	}

	public function test_images_are_not_re_encoded_even_when_the_state_file_is_gone(): void {
		$dir = $this->site( array() );
		mkdir( "$dir/u" );
		file_put_contents( "$dir/u/a.jpg", $this->photo( 300, 200, 100 ) );
		$job = array( 'dir' => $dir, 'ops' => array( 'optimize' => array( 'images' => true, 'webp' => true, 'quality' => 60 ) ) );
		$this->process( $job );
		$names = array( 'u/a.jpg', 'u/a.jpg.webp' );
		$first = $this->sha_all( $dir, $names );

		for ( $i = 0; $i < 3; $i++ ) {
			@unlink( \WP_Static_Export\Engine\Process_Run::state_file( $dir ) );
			$this->process( $job );
		}
		$this->assertSame( $first, $this->sha_all( $dir, $names ) );
		$this->assertTrue( Image_Optimizer::is_optimized( (string) file_get_contents( "$dir/u/a.jpg" ) ) );
		@unlink( \WP_Static_Export\Engine\Process_Run::state_file( $dir ) );
	}

	public function test_png_gets_a_marker_and_stays_a_valid_image(): void {
		$img = imagecreatetruecolor( 120, 80 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 80, 80, 110 ) );
		ob_start();
		imagepng( $img, null, 0 );
		$png = (string) ob_get_clean();
		$res = Image_Optimizer::optimize( $png, 'png', 80, false );

		$this->assertNotNull( $res['image'] );
		$this->assertTrue( Image_Optimizer::is_optimized( $res['image'] ) );
		$this->assertSame( array( 120, 80 ), array_slice( getimagesizefromstring( $res['image'] ), 0, 2 ) );
		$this->assertNotFalse( imagecreatefromstring( $res['image'] ), 'the file with the extra chunk must still decode' );
		$this->assertNull( Image_Optimizer::optimize( $res['image'], 'png', 80, false ), 'already optimized' );
	}

	public function test_rotated_jpegs_keep_their_orientation_after_re_encoding(): void {
		$img = imagecreatetruecolor( 200, 100 );
		ob_start();
		imagejpeg( $img, null, 100 );
		$jpeg = (string) ob_get_clean();
		$tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
		$app1 = "Exif\0\0" . $tiff;
		$jpeg = substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1 . substr( $jpeg, 2 );
		$res  = Image_Optimizer::optimize( $jpeg, 'jpg', 50, false );

		$this->assertNotNull( $res );
		$this->assertNotNull( $res['image'] ?? $res['reason'] );
		if ( null !== $res['image'] ) {
			$this->assertSame( array( 100, 200 ), array_slice( getimagesizefromstring( $res['image'] ), 0, 2 ), 'pixels are rotated so the file shows upright without EXIF' );
		}
	}

	public function test_pages_are_wrapped_with_existing_webp_twins_on_a_later_partial_run(): void {
		$dir = $this->site( array() );
		mkdir( "$dir/u" );
		file_put_contents( "$dir/u/a.jpg", $this->photo( 300, 200, 100 ) );
		$job = array( 'dir' => $dir, 'ops' => array( 'optimize' => array( 'images' => true, 'webp' => true, 'quality' => 60 ) ) );
		$this->process( $job );
		file_put_contents( "$dir/p.html", '<html><body><img src="/u/a.jpg" alt=""></body></html>' );
		$this->process( array( 'scope' => 'partial' ) + $job );

		$this->assertStringContainsString( '<picture><source type="image/webp" srcset="/u/a.jpg.webp">', (string) file_get_contents( "$dir/p.html" ) );
		@unlink( \WP_Static_Export\Engine\Process_Run::state_file( $dir ) );
	}

	public function test_images_are_compressed_with_a_webp_twin_and_not_touched_on_a_second_run(): void {
		$dir = $this->site( array( 'index.html' => '<html><body><img src="/wp-content/uploads/photo.jpg" alt=""></body></html>' ) );
		mkdir( "$dir/wp-content/uploads", 0777, true );
		$big = $this->photo( 400, 400, 100 );
		file_put_contents( "$dir/wp-content/uploads/photo.jpg", $big );
		$job = array( 'dir' => $dir, 'ops' => array( 'optimize' => array( 'images' => true, 'webp' => true, 'quality' => 60 ) ) );

		$res   = $this->process( $job );
		$after = filesize( "$dir/wp-content/uploads/photo.jpg" );
		$this->assertLessThan( strlen( $big ), $after );
		$this->assertLessThan( $after, filesize( "$dir/wp-content/uploads/photo.jpg.webp" ) );
		$this->assertStringContainsString( '<picture><source type="image/webp" srcset="/wp-content/uploads/photo.jpg.webp">', (string) file_get_contents( "$dir/index.html" ) );
		$this->assertStringContainsString( 'WebP', $res['result']['message'] );

		clearstatcache();
		$mtime = filemtime( "$dir/wp-content/uploads/photo.jpg" );
		sleep( 1 );
		$this->process( $job );
		clearstatcache();
		$this->assertSame( $mtime, filemtime( "$dir/wp-content/uploads/photo.jpg" ) );
		@unlink( \WP_Static_Export\Engine\Process_Run::state_file( $dir ) );
	}

	public function test_ss_assets_are_never_touched(): void {
		$dir = $this->site( array() );
		mkdir( "$dir/ss-assets" );
		$src = str_repeat( "function   a ( x ) {\n  return x ;\n}\n", 40 );
		file_put_contents( "$dir/ss-assets/search.js", $src );
		$this->process( array( 'dir' => $dir, 'ops' => array( 'optimize' => array( 'js' => true ) ) ) );

		$this->assertSame( $src, file_get_contents( "$dir/ss-assets/search.js" ) );
		@unlink( \WP_Static_Export\Engine\Process_Run::state_file( $dir ) );
	}

	public function test_minifies_builds_the_search_index_with_exclusions_and_skips_unchanged_files_on_rerun(): void {
		$dir = $this->site(
			array(
				'index.html'         => '<html><head><title>Home</title></head><body><main>  Welcome   home </main></body></html>',
				'private/index.html' => '<html><head><title>Secret</title></head><body>no</body></html>',
				'404.html'           => '<html><head><title>404</title></head><body>nope</body></html>',
				's.css'              => 'a  {  color : red }',
			)
		);
		$job = array( 'dir' => $dir, 'ops' => array( 'optimize' => array( 'html' => true, 'css' => true ), 'search' => array( 'engine' => 'fuse', 'exclude' => array( '/private/*' ) ) ) );
		$r   = $this->process( $job );

		$this->assertStringContainsString( 'search index with 1 page', $r['result']['message'] );
		$this->assertSame( 'a{color:red}', file_get_contents( "$dir/s.css" ) );
		$index = json_decode( (string) file_get_contents( "$dir/ss-assets/search-index.json" ), true );
		$this->assertCount( 1, $index );
		$this->assertSame( 'Home', $index[0]['title'] );
		$this->assertSame( 'Welcome home', $index[0]['text'] );
		$this->assertContains( 'ss-assets/search-index.json', $r['result']['created'] );
		@unlink( \WP_Static_Export\Engine\Process_Run::state_file( $dir ) );
	}

	public function test_archive_pages_are_left_out_of_the_index_unless_asked(): void {
		$page = static function ( $t ) { return "<html><head><title>$t</title></head><body><main>$t words words</main></body></html>"; };
		$dir  = $this->site( array( 'post/index.html' => $page( 'Post' ), 'category/news/index.html' => $page( 'News archive' ) ) );
		$this->process( array( 'dir' => $dir, 'ops' => array( 'search' => array( 'engine' => 'fuse' ) ) ) );
		$urls = array_column( json_decode( (string) file_get_contents( "$dir/ss-assets/search-index.json" ), true ), 'url' );
		$this->assertSame( array( '/post/' ), $urls );

		$this->process( array( 'dir' => $dir, 'ops' => array( 'search' => array( 'engine' => 'fuse', 'includeArchives' => true ) ) ) );
		$urls = array_column( json_decode( (string) file_get_contents( "$dir/ss-assets/search-index.json" ), true ), 'url' );
		$this->assertSame( array( '/category/news/', '/post/' ), $urls );
	}

	public function test_the_index_is_not_rebuilt_for_partial_exports(): void {
		$dir = $this->site( array( 'index.html' => '<html><title>x</title><body>y</body></html>' ) );
		$r   = $this->process( array( 'dir' => $dir, 'scope' => 'partial', 'ops' => array( 'search' => array( 'engine' => 'fuse' ) ) ) );

		$this->assertFileDoesNotExist( "$dir/ss-assets/search-index.json" );
		$this->assertStringContainsString( 'only some pages', $r['logs'][0][1] );
	}

	public function test_records_are_pushed_to_algolia_settings_clear_then_batched_updates(): void {
		$dir  = $this->site( array( 'index.html' => '<html><head><title>Home</title></head><body>hello</body></html>' ) );
		$seen = array();
		$batch = null;
		Http::set_transport(
			new FakeTransport(
				static function ( $r ) use ( &$seen, &$batch ) {
					$seen[] = $r->method . ' ' . parse_url( $r->url, PHP_URL_PATH );
					\PHPUnit\Framework\Assert::assertSame( 'ADMIN', $r->headers['X-Algolia-API-Key'] );
					if ( substr( $r->url, -6 ) === '/batch' ) {
						$batch = json_decode( $r->body, true );
					}

					return new Response( 200, '{}' );
				}
			)
		);
		$this->process( array( 'dir' => $dir, 'ops' => array( 'search' => array( 'engine' => 'algolia', 'algolia' => array( 'appId' => 'APP', 'adminKey' => 'ADMIN', 'index' => 'site' ) ) ) ) );

		$this->assertSame( array( 'PUT /1/indexes/site/settings', 'POST /1/indexes/site/clear', 'POST /1/indexes/site/batch' ), $seen );
		$this->assertSame( 'updateObject', $batch['requests'][0]['action'] );
		$this->assertSame( 'Home', $batch['requests'][0]['body']['title'] );
	}

	public function test_lighthouse_fixes_are_applied_to_a_real_export_and_a_users_headers_file_is_kept(): void {
		$page = '<!DOCTYPE html><html><head><title>T</title><link rel="stylesheet" href="/style.css"></head><body><main><img src="/img/hero.jpg"></main></body></html>';
		$dir  = $this->site( array( 'style.css' => '@font-face{font-family:A;src:url(a.woff2)}', 'index.html' => $page, '_headers' => "custom\n" ) );
		mkdir( "$dir/img" );
		$img = imagecreatetruecolor( 300, 150 );
		ob_start();
		imagejpeg( $img );
		file_put_contents( "$dir/img/hero.jpg", (string) ob_get_clean() );
		$all = array( 'viewport' => true, 'lang' => 'en', 'metaDescription' => true, 'noopener' => true, 'imageAlt' => true, 'lazyImages' => true, 'imageDimensions' => true, 'fontDisplay' => true, 'headers' => true );
		$job = array( 'dir' => $dir, 'ops' => array( 'fixes' => $all ) );

		$r = $this->process( $job );
		$this->assertMatchesRegularExpression( '/fixed 2 file\(s\).*imageDimensions 1/', $r['result']['message'] );
		$this->assertStringContainsString( 'width="300" height="150"', (string) file_get_contents( "$dir/index.html" ) );
		$this->assertStringContainsString( 'font-display:swap', (string) file_get_contents( "$dir/style.css" ) );
		$this->assertSame( "custom\n", file_get_contents( "$dir/_headers" ) );
		$this->assertSame( array(), $r['result']['created'] );

		$again = $this->process( $job );
		$this->assertSame( 'Processed: no Lighthouse fixes needed', $again['result']['message'] );
	}

	public function test_the_search_index_warns_when_it_gets_too_large_for_the_browser(): void {
		$words = str_repeat( implode( ' ', array_map( static function ( $i ) { return "word$i"; }, range( 0, 699 ) ) ) . ' ', 30 );
		$files = array();
		for ( $i = 0; $i < 1100; $i++ ) {
			$files[ "p$i/index.html" ] = "<html><head><title>P$i</title></head><body><main>$words</main></body></html>";
		}
		$dir = $this->site( $files );
		$r   = $this->process( array( 'dir' => $dir, 'ops' => array( 'search' => array( 'engine' => 'fuse' ) ) ), 5.0, 2000 );

		$this->assertStringContainsString( 'consider Algolia', $r['result']['message'] );
	}

	public function test_processing_works_in_many_tiny_steps(): void {
		$files = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$files[ "p$i/index.html" ] = "<html><head><title>P$i</title></head><body><main>text $i</main></body></html>";
		}
		$dir = $this->site( $files );
		$r   = $this->process( array( 'dir' => $dir, 'ops' => array( 'fixes' => array( 'imageAlt' => true ), 'search' => array( 'engine' => 'fuse' ) ) ), 0.0, 5000 );

		$this->assertCount( 30, json_decode( (string) file_get_contents( "$dir/ss-assets/search-index.json" ), true ) );
		$this->assertGreaterThan( 3, $r['steps'] );
	}
}
