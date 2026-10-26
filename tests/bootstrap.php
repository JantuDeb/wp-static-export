<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/Support/WpTestEnvironment.php';
require_once __DIR__ . '/Support/wordpress-stubs.php';
require_once __DIR__ . '/Support/UnitTestCase.php';

\WP_Static_Export\Tests\Support\WpTestEnvironment::reset();
