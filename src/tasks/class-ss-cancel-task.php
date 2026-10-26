<?php
namespace WP_Static_Export;

/**
 * Class which handles cancelling tasks.
 */
class Cancel_Task extends Task {

	/**
	 * Current task name.
	 *
	 * @var string
	 */
	protected static $task_name = 'cancel';

	/**
	 * Perform action to run on cancel task.
	 *
	 * @return bool
	 */
	public function perform() {
		$this->save_status_message( __( 'Cancelling job', 'wp-static-export' ) );

		$wrapup_task = new Wrapup_Task();
		$wrapup_task->perform();

		return true;
	}
}
