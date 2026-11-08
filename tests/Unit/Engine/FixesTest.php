<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit\Engine;

use WP_Static_Export\Engine\Fixes;
use WP_Static_Export\Engine\Image_Info;
use WP_Static_Export\Engine\Paths;
use WP_Static_Export\Tests\Support\EngineTestCase;

final class FixesTest extends EngineTestCase {

	private const ALL = array( 'viewport' => true, 'lang' => 'en', 'metaDescription' => true, 'noopener' => true, 'imageAlt' => true, 'lazyImages' => true, 'imageDimensions' => true, 'fontDisplay' => true );

	private function page( string $head, string $body, string $html_attrs = '' ): string {
		return "<!DOCTYPE html><html$html_attrs><head><title>T</title>$head</head><body>$body</body></html>";
	}

	/** @return array{0:string,1:array} */
	private function fix( string $html, array $opts = self::ALL, string $page_path = 'index.html' ): array {
		$titles = array( 'blog/hello-world/index.html' => 'Hello world', 'about/index.html' => 'About us', 'index.html' => 'Home' );
		$sizes  = array( 'img/hero.jpg' => array( 1200, 600 ), 'img/logo.png' => array( 80, 40 ) );

		return Fixes::fix_html(
			$html,
			array(
				'pagePath' => $page_path,
				'sizeOf'   => static function ( $p ) use ( $sizes ) { return $sizes[ $p ] ?? null; },
				'titleOf'  => static function ( $f ) use ( $titles ) { return $titles[ $f ] ?? null; },
			),
			$opts
		);
	}

	public function test_image_size_reads_png_jpeg_webp_gif_and_svg(): void {
		$img = imagecreatetruecolor( 123, 45 );
		foreach ( array( 'png' => 'imagepng', 'jpeg' => 'imagejpeg', 'webp' => 'imagewebp', 'gif' => 'imagegif' ) as $fmt => $fn ) {
			ob_start();
			$fn( $img );
			$this->assertSame( array( 123, 45 ), Image_Info::size( (string) ob_get_clean() ), $fmt );
		}
		$this->assertSame( array( 64, 32 ), Image_Info::size( '<svg xmlns="http://www.w3.org/2000/svg" width="64px" height="32"></svg>' ) );
		$this->assertSame( array( 100, 50 ), Image_Info::size( '<?xml version="1.0"?><svg viewBox="0 0 100 50" xmlns="http://www.w3.org/2000/svg"></svg>' ) );
		$this->assertNull( Image_Info::size( 'not an image at all, just text' ) );
	}

	public function test_dimensions_are_swapped_for_rotated_exif_jpegs(): void {
		$img = imagecreatetruecolor( 200, 100 );
		ob_start();
		imagejpeg( $img );
		$jpeg = (string) ob_get_clean();
		// Insert an EXIF APP1 segment with Orientation = 6 (big-endian TIFF).
		$tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
		$app1 = "Exif\0\0" . $tiff;
		$jpeg = substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1 . substr( $jpeg, 2 );

		$this->assertSame( array( 100, 200 ), Image_Info::size( $jpeg ) );
	}

	public function test_font_display_is_added_only_where_missing(): void {
		$css = '@font-face{font-family:A;src:url(a.woff2)}@font-face{font-family:B;src:url(b.woff2);font-display:optional}a{color:red}';
		list( $out, $n ) = Fixes::font_display( $css );
		$this->assertSame( 1, $n );
		$this->assertSame( '@font-face{font-family:A;src:url(a.woff2);font-display:swap}@font-face{font-family:B;src:url(b.woff2);font-display:optional}a{color:red}', $out );
		$this->assertSame( 0, Fixes::font_display( $out )[1] );

		list( $html, $counts ) = $this->fix( $this->page( '<style>@font-face{font-family:A;src:url(a.woff2);}</style>', '<p>x</p>' ) );
		$this->assertStringContainsString( 'font-display:swap', $html );
		$this->assertSame( 1, $counts['fontDisplay'] );
	}

	public function test_viewport_and_lang_only_when_missing(): void {
		list( $html ) = $this->fix( $this->page( '', '<p>x</p>' ) );
		$this->assertStringContainsString( '<head><meta name="viewport" content="width=device-width, initial-scale=1"><title>', $html );
		$this->assertStringContainsString( '<html lang="en">', $html );

		list( $already, $counts ) = $this->fix( $this->page( '<meta name="viewport" content="width=1000">', 'x', ' lang="de"' ) );
		$this->assertStringContainsString( 'width=1000', $already );
		$this->assertSame( 1, substr_count( $already, 'name="viewport"' ) );
		$this->assertStringContainsString( '<html lang="de">', $already );
		$this->assertArrayNotHasKey( 'viewport', $counts );
		$this->assertArrayNotHasKey( 'lang', $counts );
	}

	public function test_meta_description_comes_from_the_first_real_paragraph_escaped_and_capped(): void {
		$long = 'A "quoted" <b>claim</b> & ' . str_repeat( 'word ', 60 );
		list( $html ) = $this->fix( $this->page( '', "<main><p>short</p><p>$long</p></main>" ) );
		preg_match( '/<meta name="description" content="([^"]*)">/', $html, $m );
		$desc = str_replace( array( '&quot;', '&amp;' ), array( '"', '&' ), $m[1] );

		$this->assertLessThanOrEqual( 160, mb_strlen( $desc ) );
		$this->assertStringContainsString( '&quot;quoted&quot;', $m[1] );
		$this->assertStringNotContainsString( '<b>', $desc );
		$this->assertStringEndsWith( '…', $desc );
	}

	public function test_no_description_is_invented_for_noindex_existing_or_paragraph_less_pages(): void {
		$p = 'This paragraph is comfortably longer than fifty characters in total.';
		$this->assertStringNotContainsString( 'name="description"', $this->fix( $this->page( '<meta name="robots" content="noindex">', "<p>$p</p>" ) )[0] );
		$this->assertSame( 1, substr_count( $this->fix( $this->page( '<meta name="description" content="mine">', "<p>$p</p>" ) )[0], 'name="description"' ) );
		$this->assertStringNotContainsString( 'name="description"', $this->fix( $this->page( '', '<p>tiny</p>' ) )[0] );
	}

	public function test_noopener_is_added_or_merged_and_safe_links_are_left_alone(): void {
		$html = $this->page( '', '<a href="https://x.com" target="_blank">a</a><a href="https://x.com" target="_blank" rel="nofollow">b</a><a href="https://x.com" target=_blank rel="noreferrer">c</a><a href="/in">d</a><a href="x" title="a > b" target="_blank">e</a>' );
		list( $out, $counts ) = $this->fix( $html );

		$this->assertStringContainsString( '<a href="https://x.com" target="_blank" rel="noopener">a</a>', $out );
		$this->assertStringContainsString( 'rel="nofollow noopener"', $out );
		$this->assertStringContainsString( '<a href="https://x.com" target=_blank rel="noreferrer">c</a>', $out );
		$this->assertStringContainsString( '<a href="/in">d</a>', $out );
		$this->assertStringContainsString( 'title="a > b" target="_blank" rel="noopener">e</a>', $out );
		$this->assertSame( 3, $counts['noopener'] );
	}

	public function test_alt_is_added_only_when_the_attribute_is_missing(): void {
		list( $out, $counts ) = $this->fix( $this->page( '', '<img src="a.jpg"><img src="b.jpg" alt="B"><img src="c.jpg" alt="">' ), array( 'imageAlt' => true ) );
		$this->assertStringContainsString( '<img src="a.jpg" alt="">', $out );
		$this->assertStringContainsString( 'alt="B"', $out );
		$this->assertSame( 1, $counts['imageAlt'] );
	}

	public function test_intrinsic_dimensions_are_added_and_auto_height_restored(): void {
		list( $out, $counts ) = $this->fix(
			$this->page( '', '<img src="/img/hero.jpg" alt="h"><img src="/img/logo.png" alt="l" width="40"><img src="/img/hero.jpg" alt="x" width="10" height="10"><img src="/missing.jpg" alt="m">' ),
			array( 'imageDimensions' => true )
		);
		$this->assertStringContainsString( '<img src="/img/hero.jpg" alt="h" width="1200" height="600">', $out );
		$this->assertStringContainsString( 'width="40" height="20"', $out );
		$this->assertStringContainsString( 'width="10" height="10">', $out );
		$this->assertStringContainsString( '<img src="/missing.jpg" alt="m">', $out );
		$this->assertStringContainsString( '<style id="ss-dims">:where(img[width][height]){height:auto}</style></head>', $out );
		$this->assertSame( 2, $counts['imageDimensions'] );
	}

	public function test_relative_image_urls_resolve_against_the_page_directory(): void {
		list( $out ) = $this->fix( $this->page( '', '<img src="../img/hero.jpg" alt="">' ), array( 'imageDimensions' => true ), 'blog/index.html' );
		$this->assertStringContainsString( 'width="1200" height="600"', $out );
	}

	public function test_first_big_image_is_eager_and_the_rest_lazy(): void {
		$imgs = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$imgs .= "<img src=\"/i$i.jpg\" alt=\"\">";
		}
		list( $out ) = $this->fix( $this->page( '', '<img src="/logo.svg" width="40" alt="">' . $imgs . '<iframe src="https://youtube.com/embed/x"></iframe>' ), array( 'lazyImages' => true ) );

		$this->assertStringContainsString( '<img src="/logo.svg" width="40" alt="">', $out );
		$this->assertStringContainsString( '<img src="/i0.jpg" alt="" fetchpriority="high">', $out );
		$this->assertStringContainsString( '<img src="/i1.jpg" alt="" loading="lazy" decoding="async">', $out );
		$this->assertStringContainsString( '<iframe src="https://youtube.com/embed/x" loading="lazy">', $out );
		$this->assertSame( 1, substr_count( $out, 'fetchpriority' ) );
	}

	public function test_existing_loading_attributes_are_respected(): void {
		list( $out ) = $this->fix( $this->page( '', '<img src="/a.jpg" alt="" loading="eager"><img src="/b.jpg" alt="" loading="lazy"><img src="/c.jpg" alt="">' ), array( 'lazyImages' => true ) );
		$this->assertStringContainsString( '<img src="/a.jpg" alt="" loading="eager">', $out );
		$this->assertStringNotContainsString( 'fetchpriority', $out );
	}

	public function test_image_only_links_are_named_after_the_destination_page(): void {
		list( $out, $counts ) = $this->fix( $this->page( '', '<a href="/blog/hello-world/"><img src="/a.jpg" alt=""></a><a href="/about/#team"><img src="/b.jpg"></a><a href="../about"><svg></svg></a>' ), array( 'linkNames' => true ) );
		$this->assertStringContainsString( '<a href="/blog/hello-world/" aria-label="Hello world"><img', $out );
		$this->assertStringContainsString( '<a href="/about/#team" aria-label="About us">', $out );
		$this->assertStringContainsString( '<a href="../about" aria-label="About us"><svg>', $out );
		$this->assertSame( 3, $counts['linkNames'] );
	}

	public function test_links_that_already_have_a_name_or_point_elsewhere_are_left_alone(): void {
		$html = $this->page(
			'',
			'<a href="/about/" aria-label="x"><img src="/a.jpg"></a><a href="/about/" title="t"><img src="/a.jpg"></a><a href="/about/"><img src="/a.jpg"> Read more</a>'
			. '<a href="/about/"><img src="/a.jpg" alt="Team photo"></a><a href="https://example.org/about/"><img src="/a.jpg"></a><a href="/nowhere/"><img src="/a.jpg"></a><a href="/about/">Text only</a>'
		);
		list( $out, $counts ) = $this->fix( $html, array( 'linkNames' => true ) );
		$this->assertSame( $html, $out );
		$this->assertArrayNotHasKey( 'linkNames', $counts );
	}

	public function test_titles_and_hrefs(): void {
		$this->assertSame( 'About us', Fixes::clean_title( 'About us – My Site' ) );
		$this->assertSame( 'Hello', Fixes::clean_title( 'Hello | Blog' ) );
		$this->assertSame( 'Plain title', Fixes::clean_title( 'Plain title' ) );
		$this->assertSame( 'Mid-term plan', Fixes::clean_title( 'Mid-term plan' ) );
		$this->assertSame( 'a/b/index.html', Fixes::href_to_file( '/a/b', 'index.html' ) );
		$this->assertSame( 'a/b.html', Fixes::href_to_file( '/a/b.html', 'index.html' ) );
		$this->assertNull( Fixes::href_to_file( 'mailto:x@y.z', 'index.html' ) );
		$this->assertNull( Fixes::href_to_file( '//cdn.example/x', 'index.html' ) );
	}

	public function test_script_style_comment_textarea_and_noscript_contents_are_never_edited(): void {
		$body = '<script>var s = \'<img src="/x.jpg"><a href="y" target="_blank">\';</script>'
			. '<!-- <img src="/c.jpg"> --><noscript><img src="/n.jpg"></noscript><textarea><img src="/t.jpg"></textarea><template><img src="/tp.jpg"></template><img src="/real.jpg">';
		list( $out ) = $this->fix( $this->page( '', $body ) );

		$this->assertStringContainsString( '\'<img src="/x.jpg"><a href="y" target="_blank">\';', $out );
		$this->assertStringContainsString( '<!-- <img src="/c.jpg"> -->', $out );
		$this->assertStringContainsString( '<noscript><img src="/n.jpg"></noscript>', $out );
		$this->assertStringContainsString( '<textarea><img src="/t.jpg"></textarea>', $out );
		$this->assertStringContainsString( '<template><img src="/tp.jpg"></template>', $out );
		$this->assertStringContainsString( '<img src="/real.jpg" alt=', $out );
	}

	public function test_fixing_twice_changes_nothing_more(): void {
		$html = $this->page( '<style>@font-face{font-family:A;src:url(a.woff2)}</style>', '<main><p>' . str_repeat( 'A long enough paragraph for a description. ', 3 ) . '</p><img src="/img/hero.jpg"><img src="/b.jpg"><a href="x" target="_blank">l</a></main>' );
		list( $once ) = $this->fix( $html );
		list( $twice, $counts ) = $this->fix( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( array(), $counts );
	}

	public function test_fragments_are_left_untouched(): void {
		$this->assertSame( '<img src="/a.jpg">', $this->fix( '<img src="/a.jpg">' )[0] );
	}

	public function test_headers_file_covers_security_fonts_and_exporter_assets(): void {
		$h = Fixes::headers_file();
		$this->assertStringContainsString( 'X-Content-Type-Options: nosniff', $h );
		$this->assertStringContainsString( "/wp-content/uploads/*\n  Cache-Control: public, max-age=2592000", $h );
		$this->assertStringContainsString( "/*.woff2\n  Cache-Control: public, max-age=31536000, immutable", $h );
		$this->assertStringContainsString( "/ss-assets/*\n  Cache-Control: public, max-age=0, must-revalidate", $h );
	}

	public function test_paths_resolve_like_a_browser(): void {
		$this->assertSame( 'wp-content/a.jpg', Paths::resolve_site_path( '/wp-content/a.jpg', 'index.html' ) );
		$this->assertSame( 'blog/img/a.jpg', Paths::resolve_site_path( 'img/a.jpg', 'blog/index.html' ) );
		$this->assertSame( 'blog/img/a.jpg', Paths::resolve_site_path( '../img/a.jpg', 'blog/post/index.html' ) );
		$this->assertSame( 'img/a.jpg', Paths::resolve_site_path( '../../img/a.jpg', 'blog/post/index.html' ) );
		$this->assertSame( 'a/b.jpg', Paths::resolve_site_path( 'https://cdn.example.com/a/b.jpg?x=1#y', 'index.html' ) );
		$this->assertSame( 'a b.jpg', Paths::resolve_site_path( '/a%20b.jpg', 'index.html' ) );
		$this->assertNull( Paths::resolve_site_path( 'data:image/png;base64,xx', 'index.html' ) );
		$this->assertNull( Paths::resolve_site_path( 'mailto:a@b.c', 'index.html' ) );
		$this->assertSame( 'blog/', Paths::resolve_site_path( '', 'blog/index.html' ) );
	}
}
