<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Discovery_Strategy class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

use WordPress\AiClient\Providers\Http\Abstracts\AbstractClientDiscoveryStrategy;
use WordPress\AiClientDependencies\Nyholm\Psr7\Factory\Psr17Factory;
use WordPress\AiClientDependencies\Psr\Http\Client\ClientInterface;

if ( class_exists( 'WP_AI_Client_Streaming_Discovery_Strategy', false ) ) {
	return;
}

/**
 * Discovery strategy that prepends the streaming-aware WordPress HTTP adapter.
 *
 * @since 0.2.0
 * @internal Intended only to register the streaming adapter with HTTPlug discovery.
 * @access private
 */
class WP_AI_Client_Streaming_Discovery_Strategy extends AbstractClientDiscoveryStrategy {

	/**
	 * Whether the discovery strategy has already been registered.
	 *
	 * @since 0.2.0
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Registers the discovery strategy once per request.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		parent::init();
	}

	/**
	 * Creates the streaming-aware PSR-18 client.
	 *
	 * @since 0.2.0
	 *
	 * @param Psr17Factory $psr17_factory PSR-17 factory.
	 * @return ClientInterface
	 */
	protected static function createClient( Psr17Factory $psr17_factory ): ClientInterface {
		return new WP_AI_Client_Streaming_HTTP_Client( $psr17_factory, $psr17_factory );
	}
}
