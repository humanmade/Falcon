<?php
/**
 * Custom subscriptions for bbPress
 *
 */

/*
Plugin Name: Falcon
Description: Email to posts, comments, bbPress topics - and back again!
Version: 0.5-dev
Author: Ryan McCue
Author URI: http://ryanmccue.info/
*/

if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
	echo '<p>Falcon requires PHP 5.3 or newer.</p>';
	exit;
}

register_activation_hook( __FILE__, 'falcon_activation' );
register_deactivation_hook( __FILE__, 'falcon_deactivation' );

add_action( 'plugins_loaded', 'falcon_load' );

function falcon_load() {
	define( 'FALCON_PATH', __DIR__ );
	spl_autoload_register( 'falcon_autoload' );
	Falcon::bootstrap();
}

/**
 * Register cron event on activation
 */
function falcon_activation() {
	wp_schedule_event( time(), 'falcon_minutely', 'bbsub_check_inbox' );
}

/**
 * Clear cron event on deactivation
 */
function falcon_deactivation() {
	wp_clear_scheduled_hook( 'falcon_check_inbox' );
}

function falcon_autoload($class) {
	if ( strpos( $class, 'EmailReplyParser' ) === 0 ) {
		$filename = str_replace( array( '_', '\\' ), '/', $class );
		$filename = FALCON_PATH . '/vendor/EmailReplyParser/src/' . $filename . '.php';
		if ( file_exists( $filename ) ) {
			require_once( $filename );
		}
		return;
	}
	if ( strpos( $class, 'Falcon' ) !== 0 ) {
		return;
	}

	$filename = str_replace( array( '_', '\\' ), '/', $class );
	$filename = FALCON_PATH . '/library/' . $filename . '.php';
	if ( file_exists( $filename ) ) {
		require_once( $filename );
	}
}
