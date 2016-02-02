<?php

abstract class Falcon_Connector {
	/**
	 * Add a notification hook.
	 *
	 * Basically `add_action` but specifically for actions to send
	 * notifications on. Automatically registers cron tasks if running in
	 * asynchronous mode.
	 *
	 * @param string $hook Name of the action to register for.
	 * @param callback $callback Callback to register.
	 * @param int $priority Priority to register at, larger numbers = later run.
	 * @param int $accepted_args Number of arguments to pass through, default 0.
	 */
	protected function add_notify_action( $hook, $callback, $priority = 10, $accepted_args = 0 ) {
		if ( Falcon::should_send_async() ) {
			add_action( $hook, array( $this, 'schedule_async_action' ), $priority, $accepted_args );
			add_action( 'falcon_async-' . $hook, $callback, 10, $accepted_args );
		} else {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
	}

	/**
	 * Run the current action asynchronously.
	 */
	public function schedule_async_action() {
		$hook = current_action();
		$args = func_get_args();

		wp_schedule_single_event( time(), 'falcon_async-' . $hook, $args );
	}
}