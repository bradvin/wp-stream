<?php
/**
 * Plugin Name: WP Stream
 * Description: Adds plugin-side streaming support to the WordPress AI client HTTP adapter without patching WordPress core.
 * Version: 0.1.0
 * Author: bradvin
 * Text Domain: wp-stream
 */

defined( 'ABSPATH' ) || exit;

defined( 'WP_STREAM_FILE' ) || define( 'WP_STREAM_FILE', __FILE__ );
defined( 'WP_STREAM_DIR' ) || define( 'WP_STREAM_DIR', __DIR__ );
defined( 'WP_STREAM_URL' ) || define( 'WP_STREAM_URL', plugin_dir_url( __FILE__ ) );
defined( 'WP_STREAM_VERSION' ) || define( 'WP_STREAM_VERSION', '0.1.0' );

require_once __DIR__ . '/includes/class-plugin.php';

\WP_Stream\Plugin::bootstrap();
