<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The outcome for a single published post or page in a report.
 */
class Report_Item extends Model {

	protected static $table_name = 'report_items';

	protected static $columns = array(
		'id'          => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
		'report_id'   => 'BIGINT(20) UNSIGNED NOT NULL',
		'post_id'     => 'BIGINT(20) UNSIGNED NOT NULL',
		'post_type'   => 'VARCHAR(40) NOT NULL',
		'title'       => 'VARCHAR(255) NULL',
		'url'         => 'VARCHAR(700) NULL',
		'state'       => 'VARCHAR(20) NOT NULL',
		'status_code' => 'SMALLINT(6) NULL',
		'static_path' => 'VARCHAR(700) NULL',
		'file_size'   => 'BIGINT(20) NULL',
		'note'        => 'VARCHAR(255) NULL',
	);

	protected static $indexes = array(
		'PRIMARY KEY  (id)',
		'KEY report_state (report_id, state)',
		'KEY report_type (report_id, post_type)',
		'KEY report_post (report_id, post_id)',
	);

	protected static $primary_key = 'id';
}
