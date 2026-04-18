<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_HTTP_Service class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

use WordPress\AiClient\Providers\Http\Exception\NetworkException;

if ( class_exists( 'WP_AI_Client_Streaming_HTTP_Service', false ) ) {
	return;
}

/**
 * Shared HTTP transport service for the streaming adapter.
 *
 * @since 0.2.0
 * @internal Intended only to support WP_AI_Client_Streaming_HTTP_Client.
 * @access private
 */
class WP_AI_Client_Streaming_HTTP_Service {

	/**
	 * Response factory instance.
	 *
	 * @since 0.2.0
	 * @var object
	 */
	private $response_factory;

	/**
	 * Stream factory instance.
	 *
	 * @since 0.2.0
	 * @var object
	 */
	private $stream_factory;

	/**
	 * Base HTTP client for non-streaming requests.
	 *
	 * @since 0.2.0
	 * @var object
	 */
	private $base_client;

	/**
	 * Whether to reject unsafe URLs.
	 *
	 * @since 0.2.0
	 * @var bool
	 */
	private bool $safe_remote;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param object $response_factory PSR-17 response factory.
	 * @param object $stream_factory   PSR-17 stream factory.
	 * @param object $base_client      Base HTTP client.
	 * @param bool   $safe_remote      Whether unsafe URLs should be rejected.
	 */
	public function __construct( $response_factory, $stream_factory, $base_client, bool $safe_remote ) {
		$this->response_factory = $response_factory;
		$this->stream_factory   = $stream_factory;
		$this->base_client      = $base_client;
		$this->safe_remote      = $safe_remote;
	}

	/**
	 * Sends a request, intercepting only requests that opt into streaming.
	 *
	 * @since 0.2.0
	 *
	 * @param object      $request PSR-7 request.
	 * @param object|null $options Optional request options.
	 * @return object
	 */
	public function sendRequest( $request, $options = null ) {
		$analysis = $this->inspectRequest( $request );

		if ( ! $analysis['contract']['enabled'] || ! function_exists( 'curl_init' ) ) {
			return $this->delegateRequest( $request, $options );
		}

		return $this->sendStreamingRequest( $request, $options, $analysis );
	}

	/**
	 * Delegates a request to the base WordPress HTTP adapter.
	 *
	 * @since 0.2.0
	 *
	 * @param object      $request PSR-7 request.
	 * @param object|null $options Optional request options.
	 * @return object
	 */
	private function delegateRequest( $request, $options = null ) {
		if ( null !== $options && method_exists( $this->base_client, 'sendRequestWithOptions' ) ) {
			return $this->base_client->sendRequestWithOptions( $request, $options );
		}

		return $this->base_client->sendRequest( $request );
	}

	/**
	 * Sends a streaming request.
	 *
	 * @since 0.2.0
	 *
	 * @param object               $request  PSR-7 request.
	 * @param object|null          $options  Optional request options.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return object
	 */
	private function sendStreamingRequest( $request, $options, array $analysis ) {
		$url         = (string) $request->getUri();
		$contract    = $analysis['contract'];
		$parsed_args = $this->prepareWpArgs( $request, $options, $url, $analysis );

		$pre = apply_filters( 'pre_http_request', false, $parsed_args, $url );
		if ( false !== $pre ) {
			do_action( 'http_api_debug', $pre, 'response', 'WP_AI_Client_Streaming_HTTP_Client', $parsed_args, $url );

			if ( is_wp_error( $pre ) ) {
				throw $this->createNetworkException( $request, $url, $pre );
			}

			$pre = apply_filters( 'http_response', $pre, $parsed_args, $url );

			if ( is_wp_error( $pre ) ) {
				throw $this->createNetworkException( $request, $url, $pre );
			}

			return $this->createPsrResponse( $pre, $contract );
		}

		if ( function_exists( 'wp_kses_bad_protocol' ) ) {
			if ( ! empty( $parsed_args['reject_unsafe_urls'] ) ) {
				$url = wp_http_validate_url( $url );
			}

			if ( $url ) {
				$url = wp_kses_bad_protocol( $url, array( 'http', 'https', 'ssl' ) );
			}
		}

		$parsed_url = parse_url( $url );

		if ( empty( $url ) || empty( $parsed_url['scheme'] ) ) {
			$error = new WP_Error( 'http_request_failed', __( 'A valid URL was not provided.' ) );
			do_action( 'http_api_debug', $error, 'response', 'WP_AI_Client_Streaming_HTTP_Client', $parsed_args, $url );
			throw $this->createNetworkException( $request, $url, $error );
		}

		$http = new WP_Http();

		if ( $http->block_request( $url ) ) {
			$error = new WP_Error(
				'http_request_not_executed',
				sprintf(
					/* translators: %s: Blocked URL. */
					__( 'User has blocked requests through HTTP to the URL: %s.' ),
					$url
				)
			);
			do_action( 'http_api_debug', $error, 'response', 'WP_AI_Client_Streaming_HTTP_Client', $parsed_args, $url );
			throw $this->createNetworkException( $request, $url, $error );
		}

		$response = $this->executeStreamingRequestLoop( $url, $parsed_args, $contract );

		do_action( 'http_api_debug', $response, 'response', 'WP_AI_Client_Streaming_HTTP_Client', $parsed_args, $url );

		if ( is_wp_error( $response ) ) {
			throw $this->createNetworkException( $request, $url, $response );
		}

		$response = apply_filters( 'http_response', $response, $parsed_args, $url );

		if ( is_wp_error( $response ) ) {
			throw $this->createNetworkException( $request, $url, $response );
		}

		return $this->createPsrResponse( $response, $contract );
	}

	/**
	 * Prepares WordPress HTTP request arguments.
	 *
	 * @since 0.2.0
	 *
	 * @param object               $request  PSR-7 request.
	 * @param object|null          $options  Optional request options.
	 * @param string               $url      Request URL.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return array<string, mixed>
	 */
	private function prepareWpArgs( $request, $options, string $url, array $analysis ): array {
		$default_version = apply_filters( 'http_request_version', '1.0', $url );
		$http_version    = (string) $request->getProtocolVersion();

		$args = array(
			'method'              => $request->getMethod(),
			'timeout'             => apply_filters( 'http_request_timeout', 5, $url ),
			'redirection'         => apply_filters( 'http_request_redirection_count', 5, $url ),
			'httpversion'         => '' !== $http_version ? $http_version : $default_version,
			'user-agent'          => apply_filters( 'http_headers_useragent', 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ), $url ),
			'reject_unsafe_urls'  => apply_filters( 'http_request_reject_unsafe_urls', $this->safe_remote, $url ),
			'blocking'            => true,
			'headers'             => $analysis['headers'],
			'cookies'             => array(),
			'body'                => $analysis['body'],
			'compress'            => false,
			'decompress'          => true,
			'sslverify'           => true,
			'sslcertificates'     => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'stream'              => false,
			'filename'            => null,
			'limit_response_size' => null,
		);

		if ( 'HEAD' === strtoupper( $args['method'] ) ) {
			$args['redirection'] = 0;
		}

		if ( ! empty( $analysis['contract']['enabled'] ) ) {
			$args['httpversion'] = '1.1';
		}

		if ( null !== $options ) {
			if ( method_exists( $options, 'getTimeout' ) && null !== $options->getTimeout() ) {
				$args['timeout'] = $options->getTimeout();
			}

			if ( method_exists( $options, 'getMaxRedirects' ) && null !== $options->getMaxRedirects() ) {
				$args['redirection'] = $options->getMaxRedirects();
			}

			if ( method_exists( $options, 'getConnectTimeout' ) && null !== $options->getConnectTimeout() ) {
				$args['connect_timeout'] = $options->getConnectTimeout();
			}
		}

		$args = apply_filters( 'http_request_args', $args, $url );

		if ( ! isset( $args['_redirection'] ) ) {
			$args['_redirection'] = $args['redirection'];
		}

		if ( is_null( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		if ( ! is_array( $args['headers'] ) ) {
			$processed_headers = WP_Http::processHeaders( $args['headers'], $url );
			$args['headers']   = $processed_headers['headers'];
		}

		WP_Http::buildCookieHeader( $args );

		return $args;
	}

	/**
	 * Executes a streaming request loop and follows redirects when needed.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $url         Request URL.
	 * @param array<string, mixed> $parsed_args Parsed request arguments.
	 * @param array<string, mixed> $contract    Streaming contract.
	 * @return array<string, mixed>|WP_Error
	 */
	private function executeStreamingRequestLoop( string $url, array $parsed_args, array $contract ) {
		$current_url  = $url;
		$current_args = $parsed_args;

		while ( true ) {
			$response = $this->executeSingleStreamingRequest( $current_url, $current_args, $contract );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$redirect_location = $this->resolveRedirectLocation( $current_url, $current_args, $response );

			if ( is_wp_error( $redirect_location ) ) {
				return $redirect_location;
			}

			if ( false === $redirect_location ) {
				return $response;
			}

			if ( empty( $current_args['redirection'] ) ) {
				return new WP_Error( 'http_request_failed', __( 'Too many redirects.' ) );
			}

			$current_args['redirection']--;
			$current_args['_redirection'] = $current_args['redirection'];
			$current_args                 = $this->prepareRedirectArgs( $current_args, $response );
			$current_url                  = $redirect_location;
		}
	}

	/**
	 * Executes a single cURL streaming request.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $url         Request URL.
	 * @param array<string, mixed> $parsed_args Parsed request arguments.
	 * @param array<string, mixed> $contract    Streaming contract.
	 * @return array<string, mixed>|WP_Error
	 */
	private function executeSingleStreamingRequest( string $url, array $parsed_args, array $contract ) {
		$handle              = curl_init();
		$raw_headers         = '';
		$bytes_written_total = 0;
		$limit               = isset( $parsed_args['limit_response_size'] ) ? (int) $parsed_args['limit_response_size'] : 0;
		$body_handle         = ! empty( $contract['capture_body'] ) ? fopen( 'php://temp', 'w+' ) : false;
		$last_write_error    = '';
		$parser              = 'sse' === $contract['mode'] ? new WP_AI_Client_SSE_Parser() : null;
		$context             = array(
			'request_id' => $contract['request_id'],
			'mode'       => $contract['mode'],
			'url'        => $url,
			'method'     => $parsed_args['method'],
			'headers'    => $parsed_args['headers'],
		);

		if ( false === $handle ) {
			if ( is_resource( $body_handle ) ) {
				fclose( $body_handle );
			}

			return new WP_Error( 'http_request_failed', __( 'Unable to initialize cURL.' ) );
		}

		do_action( 'wp_ai_client_stream_request_start', $context );

		curl_setopt( $handle, CURLOPT_URL, $url );
		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $handle, CURLOPT_HEADER, false );
		curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
		curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, (int) ceil( $parsed_args['connect_timeout'] ?? $parsed_args['timeout'] ) );
		curl_setopt( $handle, CURLOPT_TIMEOUT, (int) ceil( $parsed_args['timeout'] ) );
		curl_setopt( $handle, CURLOPT_USERAGENT, $parsed_args['user-agent'] );
		curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
		curl_setopt( $handle, CURLOPT_BUFFERSIZE, 1160 );

		if ( ! empty( $parsed_args['sslverify'] ) ) {
			$is_local   = function_exists( 'wp_is_local_url' ) && wp_is_local_url( $url );
			$ssl_verify = $parsed_args['sslcertificates'];

			if ( $is_local ) {
				$ssl_verify = apply_filters( 'https_local_ssl_verify', $ssl_verify, $url );
			} else {
				$ssl_verify = apply_filters( 'https_ssl_verify', $ssl_verify, $url );
			}

			if ( false === $ssl_verify ) {
				curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, false );
				curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
			} else {
				curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 2 );
				curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );

				if ( is_string( $ssl_verify ) && '' !== $ssl_verify ) {
					curl_setopt( $handle, CURLOPT_CAINFO, $ssl_verify );
				}
			}
		} else {
			curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, false );
			curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
		}

		switch ( strtoupper( $parsed_args['method'] ) ) {
			case 'HEAD':
				curl_setopt( $handle, CURLOPT_NOBODY, true );
				break;

			case 'POST':
				curl_setopt( $handle, CURLOPT_POST, true );
				curl_setopt( $handle, CURLOPT_POSTFIELDS, $parsed_args['body'] );
				break;

			case 'PUT':
				curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, 'PUT' );
				curl_setopt( $handle, CURLOPT_POSTFIELDS, $parsed_args['body'] );
				break;

			default:
				curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, strtoupper( $parsed_args['method'] ) );
				if ( null !== $parsed_args['body'] ) {
					curl_setopt( $handle, CURLOPT_POSTFIELDS, $parsed_args['body'] );
				}
		}

		if ( ! empty( $parsed_args['headers'] ) ) {
			$curl_request_headers = $parsed_args['headers'];

			if (
				null !== $parsed_args['body'] &&
				'' !== $parsed_args['body'] &&
				! $this->hasHeaderNamed( $curl_request_headers, 'Expect' )
			) {
				$curl_request_headers['Expect'] = '';
			}

			if ( ! $this->hasHeaderNamed( $curl_request_headers, 'Accept-Encoding' ) ) {
				$curl_request_headers['Accept-Encoding'] = 'identity';
			}

			$curl_headers = array();

			foreach ( $curl_request_headers as $name => $value ) {
				$curl_headers[] = '' === (string) $value ? "{$name}:" : "{$name}: {$value}";
			}

			curl_setopt( $handle, CURLOPT_HTTPHEADER, $curl_headers );
		}

		if ( '1.0' === (string) $parsed_args['httpversion'] ) {
			curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0 );
		} elseif ( defined( 'CURL_HTTP_VERSION_2_0' ) && in_array( (string) $parsed_args['httpversion'], array( '2', '2.0' ), true ) ) {
			curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0 );
		} else {
			curl_setopt( $handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
		}

		$proxy = new WP_HTTP_Proxy();

		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
			curl_setopt( $handle, CURLOPT_PROXY, $proxy->host() );
			curl_setopt( $handle, CURLOPT_PROXYPORT, $proxy->port() );

			if ( $proxy->use_authentication() ) {
				curl_setopt( $handle, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
				curl_setopt( $handle, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
			}
		}

		curl_setopt(
			$handle,
			CURLOPT_HEADERFUNCTION,
			static function ( $curl_handle, string $header_line ) use ( &$raw_headers ): int {
				$raw_headers .= $header_line;
				return strlen( $header_line );
			}
		);

		curl_setopt(
			$handle,
			CURLOPT_WRITEFUNCTION,
			function ( $curl_handle, string $data ) use ( &$bytes_written_total, $limit, $body_handle, $parser, $context, &$last_write_error ) {
				$data_length = strlen( $data );

				if ( $limit > 0 && ( $bytes_written_total + $data_length ) > $limit ) {
					$data_length = max( 0, $limit - $bytes_written_total );
					$data        = substr( $data, 0, $data_length );
				}

				if ( 0 === $data_length ) {
					return 0;
				}

				$bytes_written = $data_length;

				if ( is_resource( $body_handle ) ) {
					$bytes_written = fwrite( $body_handle, $data );

					if ( false === $bytes_written ) {
						$last_write_error = __( 'Failed to write the streamed response body.' );
						return 0;
					}
				}

				$bytes_written_total += (int) $bytes_written;

				$chunk_context = array_merge(
					$context,
					array(
						'bytes_received'      => $bytes_written_total,
						'limit_response_size' => $limit > 0 ? $limit : null,
					)
				);

				do_action( 'requests-request.progress', $data, $bytes_written_total, $limit > 0 ? $limit : null );
				do_action( 'wp_ai_client_stream_chunk', $data, $chunk_context );

				if ( $parser instanceof WP_AI_Client_SSE_Parser ) {
					foreach ( $parser->push( $data ) as $event ) {
						do_action( 'wp_ai_client_stream_sse_event', $event, $chunk_context );

						if ( true !== apply_filters( 'wp_ai_client_stream_continue', true, $event, $chunk_context ) ) {
							$last_write_error = __( 'Streaming request aborted by wp_ai_client_stream_continue.' );
							return 0;
						}
					}
				} elseif ( true !== apply_filters( 'wp_ai_client_stream_continue', true, $data, $chunk_context ) ) {
					$last_write_error = __( 'Streaming request aborted by wp_ai_client_stream_continue.' );
					return 0;
				}

				return (int) $bytes_written;
			}
		);

		do_action_ref_array( 'http_api_curl', array( &$handle, $parsed_args, $url ) );

		$executed   = curl_exec( $handle );
		$curl_errno = curl_errno( $handle );
		$curl_error = curl_error( $handle );

		$processed_headers = WP_Http::processHeaders( $raw_headers, $url );

		if ( $curl_errno ) {
			$within_limit = CURLE_WRITE_ERROR === $curl_errno && $limit > 0 && $bytes_written_total === $limit;

			if ( ! $within_limit ) {
				if ( is_resource( $body_handle ) ) {
					fclose( $body_handle );
				}

				curl_close( $handle );

				$error = $last_write_error ? $last_write_error : $curl_error;
				do_action(
					'wp_ai_client_stream_error',
					$error,
					array_merge(
						$context,
						array(
							'curl_errno' => $curl_errno,
						)
					)
				);

				return new WP_Error( 'http_request_failed', $error );
			}
		}

		if ( false === $executed && empty( $processed_headers['headers'] ) ) {
			if ( is_resource( $body_handle ) ) {
				fclose( $body_handle );
			}

			curl_close( $handle );
			do_action( 'wp_ai_client_stream_error', $curl_error, $context );

			return new WP_Error( 'http_request_failed', $curl_error );
		}

		curl_close( $handle );

		$response = array(
			'headers'                    => $processed_headers['headers'],
			'body'                       => '',
			'response'                   => $processed_headers['response'],
			'cookies'                    => $processed_headers['cookies'],
			'filename'                   => null,
			'_body_resource'             => false,
			'_wp_ai_client_stream_id'    => $contract['request_id'],
		);

		$response['headers']['x-wp-ai-client-stream-request-id'] = $contract['request_id'];
		$response['headers']['x-wp-ai-client-stream-mode']       = $contract['mode'];

		if ( is_resource( $body_handle ) ) {
			rewind( $body_handle );
			$response['_body_resource'] = $body_handle;
		}

		do_action(
			'wp_ai_client_stream_complete',
			$response,
			array_merge(
				$context,
				array(
					'status_code' => (int) $response['response']['code'],
				)
			)
		);

		return $response;
	}

	/**
	 * Resolves the redirect target for a response, if any.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $url         Request URL.
	 * @param array<string, mixed> $parsed_args Parsed request arguments.
	 * @param array<string, mixed> $response    Response.
	 * @return string|false|WP_Error
	 */
	private function resolveRedirectLocation( string $url, array $parsed_args, array $response ) {
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! in_array( $status_code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return false;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( empty( $location ) ) {
			return false;
		}

		$location = WP_Http::make_absolute_url( $location, $url );

		if ( ! empty( $parsed_args['reject_unsafe_urls'] ) && ! wp_http_validate_url( $location ) ) {
			return new WP_Error( 'http_request_failed', __( 'A valid URL was not provided.' ) );
		}

		return $location;
	}

	/**
	 * Adjusts request arguments for a redirect hop.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $parsed_args Parsed request arguments.
	 * @param array<string, mixed> $response    Response.
	 * @return array<string, mixed>
	 */
	private function prepareRedirectArgs( array $parsed_args, array $response ): array {
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$method      = strtoupper( $parsed_args['method'] );

		if ( 302 === $status_code && 'POST' === $method ) {
			$parsed_args['method'] = 'GET';
			$parsed_args['body']   = null;
		}

		if ( 303 === $status_code && 'HEAD' !== $method ) {
			$parsed_args['method'] = 'GET';
			$parsed_args['body']   = null;
		}

		if ( 'GET' === strtoupper( $parsed_args['method'] ) ) {
			foreach ( array_keys( $parsed_args['headers'] ) as $header_name ) {
				if ( in_array( strtolower( $header_name ), array( 'content-length', 'content-type' ), true ) ) {
					unset( $parsed_args['headers'][ $header_name ] );
				}
			}
		}

		return $parsed_args;
	}

	/**
	 * Inspects the request and derives a streaming contract.
	 *
	 * @since 0.2.0
	 *
	 * @param object $request PSR-7 request.
	 * @return array<string, mixed>
	 */
	private function inspectRequest( $request ): array {
		$headers  = array();
		$body     = $this->prepareBody( $request );
		$contract = array(
			'enabled'      => false,
			'mode'         => null,
			'capture_body' => true,
			'request_id'   => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wp-ai-client-stream-', true ),
		);

		foreach ( $request->getHeaders() as $name => $values ) {
			$header_name  = (string) $name;
			$normalized   = strtolower( $header_name );
			$header_value = implode( ', ', $values );

			if ( $this->isStreamControlHeader( $normalized ) ) {
				$contract = $this->applyStreamControlHeader( $contract, $normalized, $header_value );
				continue;
			}

			$headers[ $header_name ] = $header_value;
		}

		$context_analysis = WP_AI_Client_Streaming_Context::maybe_apply_request_context( $request, $headers, $body, $contract );
		$headers          = $context_analysis['headers'];
		$body             = $context_analysis['body'];
		$contract         = $context_analysis['contract'];

		if ( ! $contract['enabled'] ) {
			$detected_mode = $this->detectStreamingMode( $headers, $body );

			if ( null !== $detected_mode ) {
				$contract['enabled'] = true;
				$contract['mode']    = $detected_mode;
			}
		}

		if ( $contract['enabled'] && null === $contract['mode'] ) {
			$contract['mode'] = 'sse';
		}

		return array(
			'headers'  => $headers,
			'body'     => $body,
			'contract' => $contract,
		);
	}

	/**
	 * Returns whether a header is an internal streaming control header.
	 *
	 * @since 0.2.0
	 *
	 * @param string $header Normalized header name.
	 * @return bool
	 */
	private function isStreamControlHeader( string $header ): bool {
		return 0 === strpos( $header, 'x-wp-ai-client-stream' ) || 0 === strpos( $header, 'x-ai-stream' );
	}

	/**
	 * Applies an internal streaming control header to the contract.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $contract Current contract.
	 * @param string               $header   Header name.
	 * @param string               $value    Header value.
	 * @return array<string, mixed>
	 */
	private function applyStreamControlHeader( array $contract, string $header, string $value ): array {
		$value = trim( strtolower( $value ) );

		if ( in_array( $header, array( 'x-ai-stream', 'x-wp-ai-client-stream', 'x-ai-stream-mode', 'x-wp-ai-client-stream-mode' ), true ) ) {
			$contract['enabled'] = true;

			if ( in_array( $value, array( 'raw', 'sse' ), true ) ) {
				$contract['mode'] = $value;
			}
		}

		if ( in_array( $header, array( 'x-ai-stream-request-id', 'x-wp-ai-client-stream-request-id' ), true ) && '' !== $value ) {
			$contract['request_id'] = $value;
		}

		if ( in_array( $header, array( 'x-ai-stream-capture', 'x-wp-ai-client-stream-capture' ), true ) ) {
			$contract['capture_body'] = ! in_array( $value, array( '0', 'false', 'no', 'off', 'none', 'discard' ), true );
		}

		return $contract;
	}

	/**
	 * Detects streaming mode from headers or request body.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Body.
	 * @return string|null
	 */
	private function detectStreamingMode( array $headers, ?string $body ): ?string {
		foreach ( $headers as $name => $value ) {
			if ( 'accept' === strtolower( $name ) && false !== stripos( $value, 'text/event-stream' ) ) {
				return 'sse';
			}
		}

		if ( empty( $body ) || ! $this->looksLikeJsonRequest( $headers ) ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		if ( ! empty( $decoded['stream'] ) ) {
			return 'sse';
		}

		return null;
	}

	/**
	 * Returns whether the request looks like JSON.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @return bool
	 */
	private function looksLikeJsonRequest( array $headers ): bool {
		foreach ( $headers as $name => $value ) {
			if ( 'content-type' !== strtolower( $name ) ) {
				continue;
			}

			return false !== stripos( $value, 'application/json' ) || false !== stripos( $value, '+json' );
		}

		return false;
	}

	/**
	 * Returns whether the header array already contains the requested header.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string                $header  Header name.
	 * @return bool
	 */
	private function hasHeaderNamed( array $headers, string $header ): bool {
		foreach ( $headers as $name => $value ) {
			if ( strtolower( $name ) === strtolower( $header ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts a PSR-7 request body into a string.
	 *
	 * @since 0.2.0
	 *
	 * @param object $request PSR-7 request.
	 * @return string|null
	 */
	private function prepareBody( $request ): ?string {
		$body = $request->getBody();

		if ( method_exists( $body, 'getSize' ) && 0 === $body->getSize() ) {
			return null;
		}

		if ( method_exists( $body, 'isSeekable' ) && $body->isSeekable() ) {
			$body->rewind();
		}

		$body_string = (string) $body;

		return '' === $body_string ? null : $body_string;
	}

	/**
	 * Creates a PSR-7 response from a WordPress HTTP response array.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $response Response array.
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return object
	 */
	private function createPsrResponse( array $response, array $contract ) {
		$status_code   = wp_remote_retrieve_response_code( $response );
		$reason_phrase = wp_remote_retrieve_response_message( $response );
		$headers       = wp_remote_retrieve_headers( $response );
		$body          = wp_remote_retrieve_body( $response );

		$psr_response = $this->response_factory->createResponse( (int) $status_code, $reason_phrase );

		if ( $headers instanceof WP_HTTP_Requests_Response ) {
			$headers = $headers->get_headers();
		}

		if ( is_array( $headers ) || $headers instanceof Traversable ) {
			foreach ( $headers as $name => $value ) {
				$psr_response = $psr_response->withHeader( $name, $value );
			}
		}

		if ( ! empty( $response['_body_resource'] ) && is_resource( $response['_body_resource'] ) ) {
			$resource_body = $this->readResponseBodyResource( $response['_body_resource'] );
			fclose( $response['_body_resource'] );

			$resource_body = $this->normalizeStreamedResponseBody( $resource_body, $contract );

			if ( '' !== $resource_body ) {
				$psr_response = $psr_response->withBody( $this->stream_factory->createStream( $resource_body ) );
			}
		} elseif ( is_string( $body ) && '' !== $body ) {
			$body         = $this->normalizeStreamedResponseBody( $body, $contract );
			$psr_response = $psr_response->withBody( $this->stream_factory->createStream( $body ) );
		}

		return $psr_response;
	}

	/**
	 * Reads a captured response body resource into a string.
	 *
	 * @since 0.2.0
	 *
	 * @param resource $resource Response body resource.
	 * @return string
	 */
	private function readResponseBodyResource( $resource ): string {
		rewind( $resource );

		$body = stream_get_contents( $resource );

		if ( false === $body ) {
			return '';
		}

		return (string) $body;
	}

	/**
	 * Normalizes captured SSE output back into a final JSON response body.
	 *
	 * @since 0.2.0
	 *
	 * @param string               $body     Raw captured response body.
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return string
	 */
	private function normalizeStreamedResponseBody( string $body, array $contract ): string {
		if ( '' === $body || 'sse' !== ( $contract['mode'] ?? null ) ) {
			return $body;
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $body;
		}

		$parser            = new WP_AI_Client_SSE_Parser();
		$terminal_response = null;
		$latest_response   = null;
		$normalized_body   = substr( $body, -2 ) === "\n\n" ? $body : $body . "\n\n";
		$events            = $parser->push( $normalized_body );

		foreach ( $events as $event ) {
			if ( ! $event instanceof WP_AI_Client_SSE_Event || $event->is_done() ) {
				continue;
			}

			$data = $event->get_json_data();

			if ( ! is_array( $data ) || empty( $data['response'] ) || ! is_array( $data['response'] ) ) {
				continue;
			}

			$type            = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : $event->get_event();
			$latest_response = $data['response'];

			if ( in_array( $type, array( 'response.completed', 'response.failed', 'response.incomplete' ), true ) ) {
				$terminal_response = $data['response'];
			}
		}

		$normalized = is_array( $terminal_response ) ? $terminal_response : $latest_response;

		if ( ! is_array( $normalized ) ) {
			return $body;
		}

		$json = wp_json_encode( $normalized );

		return false !== $json && '' !== $json ? $json : $body;
	}

	/**
	 * Creates the network exception expected by the PHP AI client.
	 *
	 * @since 0.2.0
	 *
	 * @param object   $request Request object.
	 * @param string   $url     URL.
	 * @param WP_Error $error   Error instance.
	 * @return NetworkException
	 */
	private function createNetworkException( $request, string $url, WP_Error $error ): NetworkException {
		$message = sprintf(
			/* translators: 1: HTTP method. 2: URL. 3: Error message. */
			__( 'Network error occurred while sending %1$s request to %2$s: %3$s' ),
			$request->getMethod(),
			$url,
			$error->get_error_message()
		);

		return new NetworkException(
			$message, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			$error->get_error_code() ? (int) $error->get_error_code() : 0
		);
	}
}
