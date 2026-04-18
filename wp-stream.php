<?php
/**
 * Plugin Name: WP Stream
 * Description: Adds plugin-side streaming support to the WordPress AI client HTTP adapter without patching WordPress core.
 * Version: 0.1.0
 * Author: bradvin
 * Text Domain: wp-stream
 */

defined( 'ABSPATH' ) || exit;

$autoload_file = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $autoload_file ) ) {
	require_once $autoload_file;
} else {
	require_once __DIR__ . '/packages/wp-stream-core/autoload.php';
}

require_once __DIR__ . '/includes/class-plugin.php';

\WP_Stream\Plugin::bootstrap( __FILE__, '0.1.0' );
