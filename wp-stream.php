<?php
/**
 * Plugin Name: WP Stream
 * Description: Thin wrapper plugin and demo UI for the WordPress AI streaming adapter package.
 * Version: 0.1.0
 * Author: bradvin
 * Text Domain: wp-stream
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

$autoload_file = __DIR__ . '/vendor/autoload.php';
$package_file  = __DIR__ . '/vendor/bradvin/wp-stream-core/load.php';

if ( file_exists( $autoload_file ) ) {
	require_once $autoload_file;
}

if ( ! function_exists( 'wp_ai_client_stream_prompt' ) ) {
	if ( file_exists( $package_file ) ) {
		require_once $package_file;
	} else {
		require_once __DIR__ . '/packages/wp-stream-core/load.php';
	}
}

require_once __DIR__ . '/includes/class-plugin.php';

\WP_Stream\Plugin::bootstrap( __FILE__, '0.1.0' );
