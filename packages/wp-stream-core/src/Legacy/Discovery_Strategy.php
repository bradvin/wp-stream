<?php
/**
 * Legacy discovery strategy.
 *
 * @package WP_Stream
 */

namespace WP_Stream\Legacy;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use WordPress\AiClient\Providers\Http\Abstracts\AbstractClientDiscoveryStrategy;

/**
 * Registers the legacy streaming-aware client ahead of the stock client.
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
