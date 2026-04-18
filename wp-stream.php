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

if ( ! file_exists( $autoload_file ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			?>
			<div class="notice notice-error"><p><?php echo esc_html__( 'WP Stream requires Composer dependencies. Run composer install for the plugin before activating it.', 'wp-stream' ); ?></p></div>
			<?php
		}
	);

	return;
}

require_once $autoload_file;

if ( ! function_exists( 'wp_ai_client_stream_prompt' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			?>
			<div class="notice notice-error"><p><?php echo esc_html__( 'WP Stream could not load the wp-ai-client-streaming package from Composer.', 'wp-stream' ); ?></p></div>
			<?php
		}
	);

	return;
}

require_once __DIR__ . '/includes/class-plugin.php';

\WP_Stream\Plugin::bootstrap( __FILE__, '0.1.0' );
