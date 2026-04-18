<?php
/**
 * Shared streaming HTTP client service.
 *
 * @package WP_Stream
 */

namespace WP_Stream;

/**
 * Provides a transport that delegates normal requests and only intercepts streaming ones.
 */
final class Streaming_HTTP_Client_Service {

	/**
	 * Response factory.
	 *
	 * @var object
	 */
	private $response_factory;

	/**
	 * Stream factory.
	 *
	 * @var object
	 */
	private $stream_factory;

	/**
	 * Base client for non-streaming requests.
	 *
	 * @var object
	 */
	private $base_client;

	/**
	 * Whether safe-remote semantics should be used.
	 *
	 * @var bool
	 */
	private $safe_remote;

	/**
	 * Constructor.
	 *
	 * @param object $response_factory PSR-17 response factory.
	 * @param object $stream_factory   PSR-17 stream factory.
	 * @param object $base_client      Existing WordPress AI HTTP client.
	 * @param bool   $safe_remote      Whether unsafe URLs should be rejected by default.
	 */
	public function __construct( $response_factory, $stream_factory, $base_client, bool $safe_remote ) {
		$this->response_factory = $response_factory;
		$this->stream_factory   = $stream_factory;
		$this->base_client      = $base_client;
		$this->safe_remote      = $safe_remote;
	}

	/**
	 * Sends a request, using the streaming transport only when the request opts in.
	 *
	 * @param object      $request PSR-7 request.
	 * @param object|null $options Optional request options.
	 * @return object
	 */
	public function send_request( $request, $options = null ) {
		$analysis = $this->inspect_request( $request );

		if ( ! $analysis['contract']['enabled'] || ! function_exists( 'curl_init' ) ) {
			return $this->delegate_request( $request, $options );
		}

		return $this->send_streaming_request( $request, $options, $analysis );
	}

	/**
	 * Delegates a request to the existing client.
	 *
	 * @param object      $request PSR-7 request.
	 * @param object|null $options Optional request options.
	 * @return object
	 */
	private function delegate_request( $request, $options = null ) {
		if ( null !== $options && method_exists( $this->base_client, 'sendRequestWithOptions' ) ) {
			return $this->base_client->sendRequestWithOptions( $request, $options );
		}

		return $this->base_client->sendRequest( $request );
	}

	/**
	 * Sends a streaming request.
	 *
	 * @param object               $request  PSR-7 request.
	 * @param object|null          $options  Optional request options.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return object
	 */
	private function send_streaming_request( $request, $options, array $analysis ) {
		$url         = (string) $request->getUri();
		$contract    = $analysis['contract'];
		$parsed_args = $this->build_parsed_args( $request, $options, $url, $analysis );

		$pre = apply_filters( 'pre_http_request', false, $parsed_args, $url );
		if ( false !== $pre ) {
			do_action( 'http_api_debug', $pre, 'response', 'WP_Stream_HTTP_Client', $parsed_args, $url );

			if ( is_wp_error( $pre ) ) {
				throw $this->create_network_exception( $request, $url, $pre );
			}

			$pre = apply_filters( 'http_response', $pre, $parsed_args, $url );

			if ( is_wp_error( $pre ) ) {
				throw $this->create_network_exception( $request, $url, $pre );
			}

			return $this->create_psr_response( $pre, $contract );
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
			$error = new \WP_Error( 'http_request_failed', __( 'A valid URL was not provided.', 'wp-stream' ) );
			do_action( 'http_api_debug', $error, 'response', 'WP_Stream_HTTP_Client', $parsed_args, $url );
			throw $this->create_network_exception( $request, $url, $error );
		}

		$http = new \WP_Http();

		if ( $http->block_request( $url ) ) {
			$error = new \WP_Error(
				'http_request_not_executed',
				sprintf(
					/* translators: %s: Blocked URL. */
					__( 'User has blocked requests through HTTP to the URL: %s.', 'wp-stream' ),
					$url
				)
			);
			do_action( 'http_api_debug', $error, 'response', 'WP_Stream_HTTP_Client', $parsed_args, $url );
			throw $this->create_network_exception( $request, $url, $error );
		}

		$response = $this->execute_streaming_request_loop( $url, $parsed_args, $contract );

		do_action( 'http_api_debug', $response, 'response', 'WP_Stream_HTTP_Client', $parsed_args, $url );

		if ( is_wp_error( $response ) ) {
			throw $this->create_network_exception( $request, $url, $response );
		}

		$response = apply_filters( 'http_response', $response, $parsed_args, $url );

		if ( is_wp_error( $response ) ) {
			throw $this->create_network_exception( $request, $url, $response );
		}

		return $this->create_psr_response( $response, $contract );
	}

	/**
	 * Builds request arguments that match WordPress's HTTP defaults closely.
	 *
	 * @param object               $request  PSR-7 request.
	 * @param object|null          $options  Optional request options.
	 * @param string               $url      Request URL.
	 * @param array<string, mixed> $analysis Request analysis.
	 * @return array<string, mixed>
	 */
	private function build_parsed_args( $request, $options, string $url, array $analysis ): array {
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

		/*
		 * Streaming requests should prefer HTTP/1.1. WordPress defaults many HTTP
		 * calls to 1.0, but streamed SSE responses are materially more reliable
		 * when the upstream request uses normal HTTP/1.1 transfer semantics.
		 */
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
			$processed_headers = \WP_Http::processHeaders( $args['headers'], $url );
			$args['headers']   = $processed_headers['headers'];
		}

		\WP_Http::buildCookieHeader( $args );

		return $args;
	}

	/**
	 * Executes the streaming request and handles redirects.
	 *
	 * @param string               $url         Request URL.
	 * @param array<string, mixed> $parsed_args Request args.
	 * @param array<string, mixed> $contract    Streaming contract.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_streaming_request_loop( string $url, array $parsed_args, array $contract ) {
		$current_url  = $url;
		$current_args = $parsed_args;

		while ( true ) {
			$response = $this->execute_single_streaming_request( $current_url, $current_args, $contract );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$redirect_location = $this->resolve_redirect_location( $current_url, $current_args, $response );

			if ( is_wp_error( $redirect_location ) ) {
				return $redirect_location;
			}

			if ( false === $redirect_location ) {
				return $response;
			}

			if ( empty( $current_args['redirection'] ) ) {
				return new \WP_Error( 'http_request_failed', __( 'Too many redirects.', 'wp-stream' ) );
			}

			$current_args['redirection']--;
			$current_args['_redirection'] = $current_args['redirection'];
			$current_args                 = $this->prepare_redirect_args( $current_args, $response );
			$current_url                  = $redirect_location;
		}
	}

	/**
	 * Executes one cURL streaming request.
	 *
	 * @param string               $url         Request URL.
	 * @param array<string, mixed> $parsed_args Request args.
	 * @param array<string, mixed> $contract    Streaming contract.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute_single_streaming_request( string $url, array $parsed_args, array $contract ) {
		$handle              = curl_init();
		$raw_headers         = '';
		$bytes_written_total = 0;
		$limit               = isset( $parsed_args['limit_response_size'] ) ? (int) $parsed_args['limit_response_size'] : 0;
		$body_handle         = ! empty( $contract['capture_body'] ) ? fopen( 'php://temp', 'w+' ) : false;
		$last_write_error    = '';
		$parser              = 'sse' === $contract['mode'] ? new SSE_Parser() : null;
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

			return new \WP_Error( 'http_request_failed', __( 'Unable to initialize cURL.', 'wp-stream' ) );
		}

		do_action( 'wp_stream_http_request_start', $context );

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

			/*
			 * Streaming requests should avoid the Expect: 100-Continue preflight
			 * and avoid transparent compression. Both can delay or batch small
			 * chunks in ways that make token streaming appear non-incremental.
			 */
			if (
				null !== $parsed_args['body'] &&
				'' !== $parsed_args['body'] &&
				! $this->has_header_named( $curl_request_headers, 'Expect' )
			) {
				$curl_request_headers['Expect'] = '';
			}

			if ( ! $this->has_header_named( $curl_request_headers, 'Accept-Encoding' ) ) {
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

		$proxy = new \WP_HTTP_Proxy();

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
							$last_write_error = __( 'Failed to write the streamed response body.', 'wp-stream' );
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
					do_action( 'wp_stream_http_chunk', $data, $chunk_context );

					if ( $parser instanceof SSE_Parser ) {
						foreach ( $parser->push( $data ) as $event ) {
							do_action( 'wp_stream_http_sse_event', $event, $chunk_context );

							if ( true !== apply_filters( 'wp_stream_http_continue', true, $event, $chunk_context ) ) {
								$last_write_error = __( 'Streaming request aborted by wp_stream_http_continue.', 'wp-stream' );
								return 0;
							}
						}
					} elseif ( true !== apply_filters( 'wp_stream_http_continue', true, $data, $chunk_context ) ) {
						$last_write_error = __( 'Streaming request aborted by wp_stream_http_continue.', 'wp-stream' );
						return 0;
					}

					return (int) $bytes_written;
				}
			);

		do_action_ref_array( 'http_api_curl', array( &$handle, $parsed_args, $url ) );

		$executed   = curl_exec( $handle );
		$curl_errno = curl_errno( $handle );
		$curl_error = curl_error( $handle );

		$processed_headers = \WP_Http::processHeaders( $raw_headers, $url );

		if ( $curl_errno ) {
			$within_limit = CURLE_WRITE_ERROR === $curl_errno && $limit > 0 && $bytes_written_total === $limit;

			if ( ! $within_limit ) {
				if ( is_resource( $body_handle ) ) {
					fclose( $body_handle );
				}

				curl_close( $handle );

				$error = $last_write_error ? $last_write_error : $curl_error;
				do_action(
					'wp_stream_http_error',
					$error,
					array_merge(
						$context,
						array(
							'curl_errno' => $curl_errno,
						)
					)
				);

				return new \WP_Error( 'http_request_failed', $error );
			}
		}

		if ( false === $executed && empty( $processed_headers['headers'] ) ) {
			if ( is_resource( $body_handle ) ) {
				fclose( $body_handle );
			}

			curl_close( $handle );
			do_action( 'wp_stream_http_error', $curl_error, $context );

			return new \WP_Error( 'http_request_failed', $curl_error );
		}

		curl_close( $handle );

		$response = array(
			'headers'             => $processed_headers['headers'],
			'body'                => '',
			'response'            => $processed_headers['response'],
			'cookies'             => $processed_headers['cookies'],
			'filename'            => null,
			'_body_resource'      => false,
			'_wp_stream_request_id' => $contract['request_id'],
		);

		$response['headers']['x-wp-stream-request-id'] = $contract['request_id'];
		$response['headers']['x-wp-stream-mode']       = $contract['mode'];

		if ( is_resource( $body_handle ) ) {
			rewind( $body_handle );
			$response['_body_resource'] = $body_handle;
		}

		do_action(
			'wp_stream_http_complete',
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
	 * Resolves the redirect location for a response.
	 *
	 * @param string               $url         Request URL.
	 * @param array<string, mixed> $parsed_args Request args.
	 * @param array<string, mixed> $response    Response.
	 * @return string|false|\WP_Error
	 */
	private function resolve_redirect_location( string $url, array $parsed_args, array $response ) {
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! in_array( $status_code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return false;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( empty( $location ) ) {
			return false;
		}

		$location = \WP_Http::make_absolute_url( $location, $url );

		if ( ! empty( $parsed_args['reject_unsafe_urls'] ) && ! wp_http_validate_url( $location ) ) {
				return new \WP_Error( 'http_request_failed', __( 'A valid URL was not provided.', 'wp-stream' ) );
		}

		return $location;
	}

	/**
	 * Adjusts arguments for a redirect hop.
	 *
	 * @param array<string, mixed> $parsed_args Request args.
	 * @param array<string, mixed> $response    Response.
	 * @return array<string, mixed>
	 */
	private function prepare_redirect_args( array $parsed_args, array $response ): array {
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
	 * Inspects the PSR request and extracts a streaming contract.
	 *
	 * @param object $request PSR-7 request.
	 * @return array<string, mixed>
	 */
	private function inspect_request( $request ): array {
		$headers  = array();
		$body     = $this->prepare_body( $request );
		$contract = array(
			'enabled'      => false,
			'mode'         => null,
			'capture_body' => true,
			'request_id'   => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wp-stream-', true ),
		);

		foreach ( $request->getHeaders() as $name => $values ) {
			$header_name  = (string) $name;
			$normalized   = strtolower( $header_name );
			$header_value = implode( ', ', $values );

			if ( $this->is_stream_control_header( $normalized ) ) {
				$contract = $this->apply_stream_control_header( $contract, $normalized, $header_value );
				continue;
			}

			$headers[ $header_name ] = $header_value;
		}

		$bridge_analysis = Ai_Client_Bridge::maybe_apply_request_bridge( $request, $headers, $body, $contract );
		$headers         = $bridge_analysis['headers'];
		$body            = $bridge_analysis['body'];
		$contract        = $bridge_analysis['contract'];

		if ( ! $contract['enabled'] ) {
			$detected_mode = $this->detect_streaming_mode( $headers, $body );

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
	 * Whether the header is an internal streaming control header.
	 *
	 * @param string $header Normalized header name.
	 * @return bool
	 */
	private function is_stream_control_header( string $header ): bool {
		return 0 === strpos( $header, 'x-wp-stream' ) || 0 === strpos( $header, 'x-stream' );
	}

	/**
	 * Applies a streaming control header to the contract.
	 *
	 * @param array<string, mixed> $contract Current contract.
	 * @param string               $header   Normalized header name.
	 * @param string               $value    Header value.
	 * @return array<string, mixed>
	 */
	private function apply_stream_control_header( array $contract, string $header, string $value ): array {
		$value = trim( strtolower( $value ) );

		if ( in_array( $header, array( 'x-stream', 'x-wp-stream', 'x-stream-mode', 'x-wp-stream-mode' ), true ) ) {
			$contract['enabled'] = true;

			if ( in_array( $value, array( 'raw', 'sse' ), true ) ) {
				$contract['mode'] = $value;
			}
		}

		if ( in_array( $header, array( 'x-stream-request-id', 'x-wp-stream-request-id' ), true ) && '' !== $value ) {
			$contract['request_id'] = $value;
		}

		if ( in_array( $header, array( 'x-stream-capture', 'x-wp-stream-capture' ), true ) ) {
			$contract['capture_body'] = ! in_array( $value, array( '0', 'false', 'no', 'off', 'none', 'discard' ), true );
		}

		return $contract;
	}

	/**
	 * Detects whether the request body or headers indicate provider-side streaming.
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Request body.
	 * @return string|null
	 */
	private function detect_streaming_mode( array $headers, ?string $body ): ?string {
		foreach ( $headers as $name => $value ) {
			if ( 'accept' === strtolower( $name ) && false !== stripos( $value, 'text/event-stream' ) ) {
				return 'sse';
			}
		}

		if ( empty( $body ) || ! $this->looks_like_json_request( $headers ) ) {
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
	 * Whether the request looks like a JSON API request.
	 *
	 * @param array<string, string> $headers Headers.
	 * @return bool
	 */
	private function looks_like_json_request( array $headers ): bool {
		foreach ( $headers as $name => $value ) {
			if ( 'content-type' !== strtolower( $name ) ) {
				continue;
			}

			return false !== stripos( $value, 'application/json' ) || false !== stripos( $value, '+json' );
		}

		return false;
	}

	/**
	 * Whether the header array already contains the requested header name.
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string               $header  Header name.
	 * @return bool
	 */
	private function has_header_named( array $headers, string $header ): bool {
		foreach ( $headers as $name => $value ) {
			if ( strtolower( $name ) === strtolower( $header ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converts a PSR-7 request body to a string.
	 *
	 * @param object $request PSR-7 request.
	 * @return string|null
	 */
	private function prepare_body( $request ): ?string {
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
	 * Converts a WordPress-style response array into a PSR response.
	 *
	 * @param array<string, mixed> $response WordPress response array.
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return object
	 */
	private function create_psr_response( array $response, array $contract ) {
		$status_code   = wp_remote_retrieve_response_code( $response );
		$reason_phrase = wp_remote_retrieve_response_message( $response );
		$headers       = wp_remote_retrieve_headers( $response );
		$body          = wp_remote_retrieve_body( $response );

		$psr_response = $this->response_factory->createResponse( (int) $status_code, $reason_phrase );

		if ( $headers instanceof \WP_HTTP_Requests_Response ) {
			$headers = $headers->get_headers();
		}

		if ( is_array( $headers ) || $headers instanceof \Traversable ) {
			foreach ( $headers as $name => $value ) {
				$psr_response = $psr_response->withHeader( $name, $value );
			}
		}

		if ( ! empty( $response['_body_resource'] ) && is_resource( $response['_body_resource'] ) ) {
			$resource_body = $this->read_response_body_resource( $response['_body_resource'] );
			fclose( $response['_body_resource'] );

			$resource_body = $this->normalize_streamed_response_body( $resource_body, $contract );

			if ( '' !== $resource_body ) {
				$psr_response = $psr_response->withBody( $this->stream_factory->createStream( $resource_body ) );
			}
		} elseif ( is_string( $body ) && '' !== $body ) {
			$body         = $this->normalize_streamed_response_body( $body, $contract );
			$psr_response = $psr_response->withBody( $this->stream_factory->createStream( $body ) );
		}

		return $psr_response;
	}

	/**
	 * Reads a response body resource into a string.
	 *
	 * @param resource $resource Response body resource.
	 * @return string
	 */
	private function read_response_body_resource( $resource ): string {
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
	 * OpenAI's Responses API streams server-sent events such as
	 * `response.output_text.delta` and ends with a `response.completed` event
	 * that contains the full response object. The provider parser expects the
	 * final non-streamed response shape, so we extract that completed response
	 * object and re-encode it as plain JSON for the PSR response body.
	 *
	 * @param string               $body     Raw captured response body.
	 * @param array<string, mixed> $contract Streaming contract.
	 * @return string
	 */
	private function normalize_streamed_response_body( string $body, array $contract ): string {
		if ( '' === $body || 'sse' !== ( $contract['mode'] ?? null ) ) {
			return $body;
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $body;
		}

		$parser            = new SSE_Parser();
		$terminal_response = null;
		$latest_response   = null;
		$normalized_body   = substr( $body, -2 ) === "\n\n" ? $body : $body . "\n\n";
		$events            = $parser->push( $normalized_body );

		foreach ( $events as $event ) {
			if ( ! $event instanceof SSE_Event || $event->is_done() ) {
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
	 * Creates the network exception used by the PHP AI client.
	 *
	 * @param object    $request Request object.
	 * @param string    $url     URL.
	 * @param \WP_Error $error   Error instance.
	 * @return \WordPress\AiClient\Providers\Http\Exception\NetworkException
	 */
	private function create_network_exception( $request, string $url, \WP_Error $error ) {
		$message = sprintf(
			'Network error occurred while sending %1$s request to %2$s: %3$s',
			$request->getMethod(),
			$url,
			$error->get_error_message()
		);

		return new \WordPress\AiClient\Providers\Http\Exception\NetworkException(
			$message,
			$error->get_error_code() ? (int) $error->get_error_code() : 0
		);
	}
}
