<?php
/**
 * Custom subscriptions for bbPress
 *
 */

/*
Plugin Name: bbPress Subscriptions
Description: Custom subscriptions for bbPress
Version: 0.4-dev
Author: Ryan McCue
Author URI: http://ryanmccue.info/
*/

if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
	echo '<p>bbPress Subscriptions requires PHP 5.3 or newer.</p>';
	exit;
}

register_activation_hook( __FILE__, 'bbsub_activation' );
register_deactivation_hook( __FILE__, 'bbsub_deactivation' );

add_action( 'plugins_loaded', 'bbsub_load' );

function bbsub_load() {
	define( 'BBSUB_PATH', __DIR__ );
	spl_autoload_register( 'bbsub_autoload' );
	bbSubscriptions::bootstrap();
}

/**
 * Register cron event on activation
 */
function bbsub_activation() {
	wp_schedule_event( time(), 'bbsub_minutely', 'bbsub_check_inbox' );
}

/**
 * Clear cron event on deactivation
 */
function bbsub_deactivation() {
	wp_clear_scheduled_hook( 'bbsub_check_inbox' );
}

function bbsub_autoload($class) {
	if ( strpos( $class, 'EmailReplyParser' ) === 0 ) {
		$filename = str_replace( array( '_', '\\' ), '/', $class );
		$filename = self::$path . '/vendor/EmailReplyParser/src/' . $filename . '.php';
		if ( file_exists( $filename ) ) {
			require_once( $filename );
		}
		return;
	}
	if ( strpos( $class, 'bbSubscriptions' ) !== 0 ) {
		return;
	}

	$filename = str_replace( array( '_', '\\' ), '/', $class );
	$filename = self::$path . '/library/' . $filename . '.php';
	if ( file_exists( $filename ) ) {
		require_once( $filename );
	}
}
