<?php
/**
 * WP Stream plugin bootstrap.
 *
 * @package WP_Stream
 */

namespace WP_Stream;

use WP_Stream\Integration\Runtime;

/**
 * Bootstraps the thin plugin wrapper around the core package.
 */
final class Plugin {

	/**
	 * Whether bootstrapping already happened.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	private static $plugin_url = '';

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private static $plugin_version = '';

	/**
	 * Bootstraps the wrapper plugin.
	 *
	 * @param string $plugin_file Main plugin file.
	 * @param string $version     Plugin version.
	 * @return void
	 */
	public static function bootstrap( string $plugin_file, string $version ): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped   = true;
		self::$plugin_url     = plugin_dir_url( $plugin_file );
		self::$plugin_version = $version;

		require_once __DIR__ . '/functions.php';
		require_once __DIR__ . '/class-admin-chat-page.php';

		Runtime::register();

		if ( is_admin() ) {
			Admin_Chat_Page::init();
		}
	}

	/**
	 * Returns diagnostics for the active streaming transport.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_transport_diagnostics(): array {
		return Runtime::get_transport_diagnostics();
	}

	/**
	 * Builds a plugin asset URL.
	 *
	 * @param string $path Relative asset path.
	 * @return string
	 */
	public static function get_asset_url( string $path ): string {
		return self::$plugin_url . ltrim( $path, '/' );
	}

	/**
	 * Returns the plugin version.
	 *
	 * @return string
	 */
	public static function get_version(): string {
		return self::$plugin_version;
	}
}
