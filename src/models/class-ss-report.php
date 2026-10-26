<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One verification report per export: did every published item make it into the static site?
 */
class Report extends Model {

	protected static $table_name = 'reports';

	protected static $columns = array(
		'id'              => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
		'deploy_id'       => 'VARCHAR(80) NOT NULL',
		'site_id'         => 'BIGINT(20) UNSIGNED NULL',
		'status'          => 'VARCHAR(20) NOT NULL',
		'scope'           => 'VARCHAR(10) NOT NULL',
		'delivery_method' => 'VARCHAR(40) NULL',
		'started_at'      => "DATETIME NULL DEFAULT '0000-00-00 00:00:00'",
		'finished_at'     => "DATETIME NULL DEFAULT '0000-00-00 00:00:00'",
		'totals'          => 'LONGTEXT NULL',
		'deep'            => 'LONGTEXT NULL',
		'error'           => 'TEXT NULL',
		'created_at'      => "DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'",
		'updated_at'      => "DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00'",
	);

	protected static $indexes = array(
		'PRIMARY KEY  (id)',
		'KEY deploy_site (deploy_id, site_id)',
		'KEY status (status)',
	);

	protected static $primary_key = 'id';
}
