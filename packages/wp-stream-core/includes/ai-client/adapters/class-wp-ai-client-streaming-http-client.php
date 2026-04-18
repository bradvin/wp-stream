<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_HTTP_Client class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

use WordPress\AiClient\Providers\Http\Contracts\ClientWithOptionsInterface;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClientDependencies\Psr\Http\Client\ClientInterface;
use WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface;
use WordPress\AiClientDependencies\Psr\Http\Message\ResponseFactoryInterface;
use WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface;
use WordPress\AiClientDependencies\Psr\Http\Message\StreamFactoryInterface;

if ( class_exists( 'WP_AI_Client_Streaming_HTTP_Client', false ) ) {
	return;
}

/**
 * Streaming-aware HTTP client that delegates normal traffic to core's adapter.
 *
 * @since 0.2.0
 * @internal Intended only to wire up streaming support to the WordPress AI client.
 * @access private
 */
class WP_AI_Client_Streaming_HTTP_Client implements ClientInterface, ClientWithOptionsInterface {

	/**
	 * Shared streaming HTTP service.
	 *
	 * @since 0.2.0
	 * @var WP_AI_Client_Streaming_HTTP_Service
	 */
	private WP_AI_Client_Streaming_HTTP_Service $service;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param ResponseFactoryInterface $response_factory PSR-17 response factory.
	 * @param StreamFactoryInterface   $stream_factory   PSR-17 stream factory.
	 */
	public function __construct( ResponseFactoryInterface $response_factory, StreamFactoryInterface $stream_factory ) {
		$this->service = new WP_AI_Client_Streaming_HTTP_Service(
			$response_factory,
			$stream_factory,
			new WP_AI_Client_HTTP_Client( $response_factory, $stream_factory ),
			true
		);
	}

	/**
	 * Sends a PSR-7 request.
	 *
	 * @since 0.2.0
	 *
	 * @param RequestInterface $request Request.
	 * @return ResponseInterface
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		return $this->service->sendRequest( $request );
	}

	/**
	 * Sends a PSR-7 request with request options.
	 *
	 * @since 0.2.0
	 *
	 * @param RequestInterface $request Request.
	 * @param RequestOptions   $options Request options.
	 * @return ResponseInterface
	 */
	public function sendRequestWithOptions( RequestInterface $request, RequestOptions $options ): ResponseInterface {
		return $this->service->sendRequest( $request, $options );
	}
}
