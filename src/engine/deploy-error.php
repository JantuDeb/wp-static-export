<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A failure whose message is safe and useful to show the site owner.
 */
class Deploy_Error extends \RuntimeException {
}
