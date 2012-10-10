<?php
/**
 * Custom subscriptions for bbPress
 *
 */

/*
Plugin Name: bbPress Subscriptions
Description: Custom subscriptions for bbPress
Version: 0.3-dev
Author: Ryan McCue
Author URI: http://ryanmccue.info/
*/

class bbSub {
	public static $path;

	public static function verify() {
		remove_action('all_admin_notices', array('bbSub', 'report_error'));
		//Sputnik::check(__FILE__, array('bbSub', 'load'));
		bbSub::load();
	}

	public static function load() {
		self::$path = __DIR__;
		spl_autoload_register(array(get_called_class(), 'autoload'));
		bbSubscriptions::bootstrap();
	}

	public static function report_error() {
		echo '<div class="error"><p>Please install &amp; activate Sputnik to enable bbSubscriptions.</p></div>';
	}

	/**
	 * Register cron event on activation
	 */
	public static function activation() {
		wp_schedule_event(time(), 'bbsub_minutely', 'bbsub_check_inbox');	
	}

	/**
	 * Clear cron event on deactivation
	 */
	public static function deactivation() {
		wp_clear_scheduled_hook('bbsub_check_inbox');
	}

	public static function autoload($class) {
		if (strpos($class, 'EmailReplyParser') === 0) {
			$filename = str_replace(array('_', '\\'), '/', $class);
			$filename = self::$path . '/vendor/EmailReplyParser/src/' . $filename . '.php';
			if (file_exists($filename)) {
				require_once($filename);
			}
			return;
		}
		if (strpos($class, 'bbSubscriptions') !== 0) {
			return;
		}

		$filename = str_replace(array('_', '\\'), '/', $class);
		$filename = self::$path . '/library/' . $filename . '.php';
		if (file_exists($filename)) {
			require_once($filename);
		}
	}
}

register_activation_hook(__FILE__, array('bbSub', 'activation'));
register_deactivation_hook(__FILE__, array('bbSub', 'deactivation'));

//add_action('sputnik_loaded', array('bbSub', 'verify'));
add_action('plugins_loaded', array('bbSub', 'verify'));
add_action('all_admin_notices', array('bbSub', 'report_error'));