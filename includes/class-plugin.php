<?php
/**
 * WP Stream plugin bootstrap.
 *
 * @package WP_Stream
 */

namespace WP_Stream;

/**
 * Bootstraps the thin plugin wrapper around the streaming adapter package.
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

		require_once __DIR__ . '/class-admin-chat-page.php';

		if ( class_exists( '\WP_AI_Client_Streaming_Discovery_Strategy' ) ) {
			\WP_AI_Client_Streaming_Discovery_Strategy::init();
		}

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
		$diagnostics = array(
			'registry_class'           => null,
			'transporter_class'        => null,
			'client_class'             => null,
			'is_streaming_client'      => false,
			'is_streaming_transporter' => false,
			'is_active'                => false,
			'message'                  => '',
		);

		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$diagnostics['message'] = __( 'WordPress AI Client is not available.', 'wp-stream' );
			return $diagnostics;
		}

		try {
			$registry                      = \WordPress\AiClient\AiClient::defaultRegistry();
			$diagnostics['registry_class'] = get_class( $registry );

			if ( ! method_exists( $registry, 'getHttpTransporter' ) ) {
				$diagnostics['message'] = __( 'The default AI registry does not expose an HTTP transporter.', 'wp-stream' );
				return $diagnostics;
			}

			$transporter = $registry->getHttpTransporter();

			if ( ! is_object( $transporter ) ) {
				$diagnostics['message'] = __( 'The default AI registry returned an invalid transporter.', 'wp-stream' );
				return $diagnostics;
			}

			$diagnostics['transporter_class']        = get_class( $transporter );
			$diagnostics['is_streaming_transporter'] = $transporter instanceof \WordPress\AiClient\Providers\Http\HttpTransporter;

			$client = self::read_object_property( $transporter, 'client' );

			if ( is_object( $client ) ) {
				$diagnostics['client_class']        = get_class( $client );
				$diagnostics['is_streaming_client'] = $client instanceof \WP_AI_Client_Streaming_HTTP_Client;
			}

			$diagnostics['is_active'] = $diagnostics['is_streaming_client'];

			if ( $diagnostics['is_active'] ) {
				$diagnostics['message'] = __( 'The streaming HTTP adapter is active for the default AI Client registry.', 'wp-stream' );
			} elseif ( $diagnostics['client_class'] ) {
				$diagnostics['message'] = sprintf(
					/* translators: %s: Active HTTP client class name. */
					__( 'The streaming HTTP adapter is not active. The default AI Client registry is currently using %s.', 'wp-stream' ),
					$diagnostics['client_class']
				);
			} else {
				$diagnostics['message'] = __( 'The active AI Client HTTP client could not be confirmed.', 'wp-stream' );
			}
		} catch ( \Throwable $throwable ) {
			$diagnostics['message'] = $throwable->getMessage();
		}

		return $diagnostics;
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

	/**
	 * Reads an object property via reflection.
	 *
	 * @param object $object Object instance.
	 * @param string $name   Property name.
	 * @return mixed|null
	 */
	private static function read_object_property( $object, string $name ) {
		$reflection = new \ReflectionObject( $object );

		while ( $reflection ) {
			if ( $reflection->hasProperty( $name ) ) {
				$property = $reflection->getProperty( $name );
				$property->setAccessible( true );

				return $property->getValue( $object );
			}

			$reflection = $reflection->getParentClass();
		}

		return null;
	}
}
