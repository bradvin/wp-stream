<?php
/**
 * Runtime bootstrap for the WP Stream core library.
 *
 * @package WP_Stream
 */

namespace WP_Stream\Integration;

use WP_Stream\Core\Discovery_Strategy as Core_Discovery_Strategy;
use WP_Stream\Core\HTTP_Client as Core_HTTP_Client;
use WP_Stream\Legacy\Discovery_Strategy as Legacy_Discovery_Strategy;

/**
 * Registers the streaming runtime with the active WordPress AI client.
 */
final class Runtime {

	/**
	 * Whether runtime registration already happened.
	 *
	 * @var bool
	 */
	private static $registered_runtime = false;

	/**
	 * Whether the late bootstrap hook has been registered.
	 *
	 * @var bool
	 */
	private static $late_bootstrap_hooked = false;

	/**
	 * Whether the discovery strategy was registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Whether the default AI registry transporter was synchronized.
	 *
	 * @var bool
	 */
	private static $transport_synced = false;

	/**
	 * Registers the runtime once for the current request.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! self::$registered_runtime ) {
			self::$registered_runtime = true;

			if ( ! self::$late_bootstrap_hooked && ! did_action( 'plugins_loaded' ) ) {
				add_action( 'plugins_loaded', array( __CLASS__, 'late_register' ), 0 );
				self::$late_bootstrap_hooked = true;
			}
		}

		self::late_register();
	}

	/**
	 * Runs the registration work that depends on other plugins being loaded.
	 *
	 * @return void
	 */
	public static function late_register(): void {
		self::register_discovery_strategy();
		self::synchronize_default_registry_transport();
	}

	/**
	 * Returns diagnostics for the currently active AI Client HTTP transport.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_transport_diagnostics(): array {
		$diagnostics = array(
			'registered'               => self::$registered,
			'transport_synced'         => self::$transport_synced,
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
				$diagnostics['is_streaming_client'] = $client instanceof Core_HTTP_Client || $client instanceof \WP_Stream\Legacy\HTTP_Client;
			}

			$diagnostics['is_active'] = $diagnostics['is_streaming_client'];

			if ( $diagnostics['is_active'] ) {
				$diagnostics['message'] = __( 'WP Stream transport is active for the default AI Client registry.', 'wp-stream' );
			} elseif ( $diagnostics['client_class'] ) {
				$diagnostics['message'] = sprintf(
					/* translators: %s: Active HTTP client class name. */
					__( 'WP Stream transport is not active. The default AI Client registry is currently using %s.', 'wp-stream' ),
					$diagnostics['client_class']
				);
			} else {
				$diagnostics['message'] = __( 'WP Stream could not confirm the active AI Client HTTP client.', 'wp-stream' );
			}
		} catch ( \Throwable $throwable ) {
			$diagnostics['message'] = $throwable->getMessage();
		}

		return $diagnostics;
	}

	/**
	 * Registers the best discovery strategy for the current environment.
	 *
	 * @return void
	 */
	private static function register_discovery_strategy(): void {
		if ( self::$registered ) {
			return;
		}

		if ( interface_exists( '\WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface' ) ) {
			Core_Discovery_Strategy::init();
			self::$registered = true;
			return;
		}

		if (
			class_exists( '\WordPress\AI_Client\HTTP\WordPress_HTTP_Client' ) &&
			interface_exists( '\Psr\Http\Message\RequestInterface' )
		) {
			Legacy_Discovery_Strategy::init();
			self::$registered = true;
		}
	}

	/**
	 * Forces the default AI Client registry to use the streaming-aware transporter.
	 *
	 * @return void
	 */
	private static function synchronize_default_registry_transport(): void {
		if ( self::$transport_synced ) {
			return;
		}

		if (
			! class_exists( '\WordPress\AiClient\AiClient' ) ||
			! class_exists( '\WordPress\AiClient\Providers\Http\HttpTransporter' ) ||
			! class_exists( '\WordPress\AiClientDependencies\Nyholm\Psr7\Factory\Psr17Factory' ) ||
			! class_exists( '\WP_AI_Client_HTTP_Client' )
		) {
			return;
		}

		$psr17_factory = new \WordPress\AiClientDependencies\Nyholm\Psr7\Factory\Psr17Factory();
		$client        = new Core_HTTP_Client( $psr17_factory, $psr17_factory );
		$transporter   = new \WordPress\AiClient\Providers\Http\HttpTransporter(
			$client,
			$psr17_factory,
			$psr17_factory
		);

		\WordPress\AiClient\AiClient::defaultRegistry()->setHttpTransporter( $transporter );
		self::$transport_synced = true;
	}

	/**
	 * Reads a property from an object or one of its parents via reflection.
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
