<?php
/**
 * Core-scoped discovery strategy.
 *
 * @package WP_Stream
 */

namespace WP_Stream\Core;

use WordPress\AiClient\Providers\Http\Abstracts\AbstractClientDiscoveryStrategy;
use WordPress\AiClientDependencies\Nyholm\Psr7\Factory\Psr17Factory;
use WordPress\AiClientDependencies\Psr\Http\Client\ClientInterface;

/**
 * Registers the streaming-aware client ahead of core's default client.
 */
final class Discovery_Strategy extends AbstractClientDiscoveryStrategy {

	/**
	 * Creates the client instance.
	 *
	 * @param Psr17Factory $psr17_factory PSR-17 factory.
	 * @return ClientInterface
	 */
	protected static function createClient( Psr17Factory $psr17_factory ): ClientInterface {
		return new HTTP_Client( $psr17_factory, $psr17_factory );
	}
}
