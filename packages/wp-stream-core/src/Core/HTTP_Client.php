<?php
/**
 * Core-scoped streaming client wrapper.
 *
 * @package WP_Stream
 */

namespace WP_Stream\Core;

use WordPress\AiClient\Providers\Http\Contracts\ClientWithOptionsInterface;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClientDependencies\Psr\Http\Client\ClientInterface;
use WordPress\AiClientDependencies\Psr\Http\Message\RequestInterface;
use WordPress\AiClientDependencies\Psr\Http\Message\ResponseFactoryInterface;
use WordPress\AiClientDependencies\Psr\Http\Message\ResponseInterface;
use WordPress\AiClientDependencies\Psr\Http\Message\StreamFactoryInterface;
use WP_Stream\Streaming_HTTP_Client_Service;

/**
 * Core-scoped client implementation.
 */
final class HTTP_Client implements ClientInterface, ClientWithOptionsInterface {

	/**
	 * Shared service.
	 *
	 * @var Streaming_HTTP_Client_Service
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param ResponseFactoryInterface $response_factory Response factory.
	 * @param StreamFactoryInterface   $stream_factory   Stream factory.
	 */
	public function __construct( ResponseFactoryInterface $response_factory, StreamFactoryInterface $stream_factory ) {
		$this->service = new Streaming_HTTP_Client_Service(
			$response_factory,
			$stream_factory,
			new \WP_AI_Client_HTTP_Client( $response_factory, $stream_factory ),
			true
		);
	}

	/**
	 * Sends a request.
	 *
	 * @param RequestInterface $request Request.
	 * @return ResponseInterface
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		return $this->service->send_request( $request );
	}

	/**
	 * Sends a request with options.
	 *
	 * @param RequestInterface $request Request.
	 * @param RequestOptions   $options Options.
	 * @return ResponseInterface
	 */
	public function sendRequestWithOptions( RequestInterface $request, RequestOptions $options ): ResponseInterface {
		return $this->service->send_request( $request, $options );
	}
}
