<?php
/**
 * WP Stream plugin bootstrap.
 *
 * @package WP_Stream
 */

namespace WP_Stream;

/**
 * Bootstraps the plugin and registers the appropriate discovery strategy.
 */
final class Plugin {

	/**
	 * Whether bootstrapping already happened.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	/**
	 * Whether the discovery strategy was registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Bootstraps the plugin.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		require_once __DIR__ . '/class-sse-event.php';
		require_once __DIR__ . '/class-sse-parser.php';
		require_once __DIR__ . '/class-ai-client-bridge.php';
		require_once __DIR__ . '/class-admin-chat-page.php';
		require_once __DIR__ . '/class-streaming-http-client-service.php';
		require_once __DIR__ . '/functions.php';

		if ( is_admin() ) {
			Admin_Chat_Page::init();
		}

		if ( class_exists( '\WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface' ) ) {
			self::register_discovery_strategy();
		}

		add_action( 'plugins_loaded', array( __CLASS__, 'register_discovery_strategy' ), 0 );
	}

	/**
	 * Registers the best discovery strategy for the current environment.
	 *
	 * @return void
	 */
	public static function register_discovery_strategy(): void {
		if ( self::$registered ) {
			return;
		}

		if ( class_exists( '\WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface' ) ) {
			require_once __DIR__ . '/core/class-http-client.php';
			require_once __DIR__ . '/core/class-discovery-strategy.php';

			Core\Discovery_Strategy::init();
			self::$registered = true;
			return;
		}

		if (
			class_exists( '\WordPress\AI_Client\HTTP\WordPress_HTTP_Client' ) &&
			interface_exists( '\Psr\Http\Message\RequestInterface' )
		) {
			require_once __DIR__ . '/legacy/class-http-client.php';
			require_once __DIR__ . '/legacy/class-discovery-strategy.php';

			Legacy\Discovery_Strategy::init();
			self::$registered = true;
		}
	}
}
