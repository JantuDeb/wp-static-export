<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit;

use WP_Static_Export\Tests\Support\UnitTestCase;
use WP_Static_Export\View;

require_once dirname( __DIR__, 2 ) . '/src/class-ss-view.php';

final class ViewRuntimePathTest extends UnitTestCase {

	public function test_redirect_view_renders_from_the_runtime_plugin_path(): void {
		$target = 'https://static.example/new-page';
		$view   = new View();
		$html   = $view->set_template( 'redirect' )
			->assign( 'redirect_url', $target )
			->render_to_string();

		self::assertIsString( $html );
		self::assertStringContainsString( 'window.location.assign(' . wp_json_encode( $target ) . ')', $html );
	}
}
