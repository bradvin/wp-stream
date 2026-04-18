<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Transport_Diagnostics class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Transport_Diagnostics', false ) ) {
	return;
}

/**
 * Inspects the active AI client transport and reports whether the streaming adapter is active.
 *
 * @since 0.2.0
 */
class WP_AI_Client_Streaming_Transport_Diagnostics {

	/**
	 * Returns diagnostics for the default AI client registry.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	public static function get_default_registry_diagnostics(): array {
		$diagnostics = self::get_empty_diagnostics();

		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$diagnostics['message'] = __( 'WordPress AI Client is not available.' );
			return $diagnostics;
		}

		try {
			return self::get_registry_diagnostics( \WordPress\AiClient\AiClient::defaultRegistry() );
		} catch ( \Throwable $throwable ) {
			$diagnostics['message'] = $throwable->getMessage();

			return $diagnostics;
		}
	}

	/**
	 * Returns diagnostics for a specific AI registry.
	 *
	 * @since 0.2.0
	 *
	 * @param object $registry AI registry object.
	 * @return array<string, mixed>
	 */
	public static function get_registry_diagnostics( $registry ): array {
		$diagnostics = self::get_empty_diagnostics();

		if ( ! is_object( $registry ) ) {
			$diagnostics['message'] = __( 'The AI registry is invalid.' );
			return $diagnostics;
		}

		$diagnostics['registry_class'] = get_class( $registry );

		if ( ! method_exists( $registry, 'getHttpTransporter' ) ) {
			$diagnostics['message'] = __( 'The AI registry does not expose an HTTP transporter.' );
			return $diagnostics;
		}

		$transporter = $registry->getHttpTransporter();

		if ( ! is_object( $transporter ) ) {
			$diagnostics['message'] = __( 'The AI registry returned an invalid transporter.' );
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
			$diagnostics['message'] = __( 'The streaming HTTP adapter is active for the AI registry.' );
		} elseif ( $diagnostics['client_class'] ) {
			$diagnostics['message'] = sprintf(
				/* translators: %s: Active HTTP client class name. */
				__( 'The streaming HTTP adapter is not active. The AI registry is currently using %s.' ),
				$diagnostics['client_class']
			);
		} else {
			$diagnostics['message'] = __( 'The active AI client HTTP adapter could not be confirmed.' );
		}

		return $diagnostics;
	}

	/**
	 * Returns the default diagnostics payload shape.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>
	 */
	private static function get_empty_diagnostics(): array {
		return array(
			'registry_class'           => null,
			'transporter_class'        => null,
			'client_class'             => null,
			'is_streaming_client'      => false,
			'is_streaming_transporter' => false,
			'is_active'                => false,
			'message'                  => '',
		);
	}

	/**
	 * Reads an object property via reflection.
	 *
	 * @since 0.2.0
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
