<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a provider gets for one step of a deploy: the files, the plan, its own saved state and a clock.
 */
class Context {

	/** @var string Absolute export folder, no trailing slash. */
	public $dir = '';

	/** @var array[] Every file: path, abs, size, sha256. */
	public $all = array();

	/** @var array[] Files that are new or changed (all of them on a full deploy). */
	public $changed = array();

	/** @var string[] Paths that existed in the previous deploy and are gone now. */
	public $deleted = array();

	/** @var array Validated provider settings. */
	public $config = array();

	/** @var int Parallel requests. */
	public $concurrency = 8;

	/** @var array Provider state; persisted between steps, so keep it small and JSON-safe. */
	public $state = array();

	/** @var array Set by the provider when it finishes: uploaded, deleted, url, message. */
	public $result = array();

	/** @var string File the provider may use for a cache that outlives one deploy. */
	public $cache_file = '';

	/** @var float Unix time (microtime) after which the provider should save its state and return. */
	public $deadline = 0.0;

	/** @var callable|null function ( $level, $message ) */
	public $logger = null;

	/** @var callable|null function ( $done, $total, $label ) */
	public $progress_cb = null;

	/** @var bool Whether out_of_time() has been asked yet in this step. */
	private $asked = false;

	/**
	 * Whether this step has used its time. The first question of a step always gets "no", so every step does at
	 * least one unit of work and a run can never stall on a tiny time budget.
	 *
	 * @return bool
	 */
	public function out_of_time() {
		if ( ! $this->asked ) {
			$this->asked = true;

			return false;
		}

		return microtime( true ) >= $this->deadline;
	}

	/**
	 * @param string $message Message.
	 * @param string $level   info|warn.
	 * @return void
	 */
	public function log( $message, $level = 'info' ) {
		if ( $this->logger ) {
			call_user_func( $this->logger, $level, $message );
		}
	}

	/**
	 * @param int    $done  Done.
	 * @param int    $total Total.
	 * @param string $label What.
	 * @return void
	 */
	public function progress( $done, $total, $label = '' ) {
		if ( $this->progress_cb ) {
			call_user_func( $this->progress_cb, $done, $total, $label );
		}
	}

	/**
	 * @param array $entry File entry.
	 * @return string Contents.
	 * @throws Deploy_Error If unreadable.
	 */
	public function read( array $entry ) {
		$data = @file_get_contents( $entry['abs'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $data ) {
			throw new Deploy_Error( 'Could not read ' . $entry['path'] );
		}

		return $data;
	}

	/**
	 * Largest file the engine reads into memory to upload it.
	 */
	const MAX_FILE_BYTES = 100 * 1024 * 1024;
}
