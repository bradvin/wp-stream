<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Context class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

if ( class_exists( 'WP_AI_Client_Streaming_Context', false ) ) {
	return;
}

/**
 * Manages per-request streaming state for the WordPress AI client.
 *
 * @since 0.2.0
 * @internal Intended only to coordinate the streaming prompt helper with the HTTP adapter.
 * @access private
 */
class WP_AI_Client_Streaming_Context {

	/**
	 * Active streaming contexts keyed by a unique internal ID.
	 *
	 * @since 0.2.0
	 * @var array<string, array<string, mixed>>
	 */
	private static array $contexts = array();

	/**
	 * Runs a callback with streaming enabled for the first matching outbound AI request.
	 *
	 * @since 0.2.0
	 *
	 * @param callable             $callback    Callback to execute.
	 * @param array<string, mixed> $stream_args Streaming options.
	 * @return mixed
	 */
	public static function with_streaming( callable $callback, array $stream_args = array() ) {
		$context                          = self::normalize_stream_args( $stream_args );
		self::$contexts[ $context['id'] ] = $context;
		$listeners                        = self::register_listeners( $context );

		try {
			return $callback();
		} finally {
			self::remove_listeners( $listeners );
			unset( self::$contexts[ $context['id'] ] );
		}
	}

	/**
	 * Builds request options from streaming arguments when needed.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $stream_args Streaming options.
	 * @return RequestOptions|null
	 */
	public static function build_request_options( array $stream_args ): ?RequestOptions {
		$has_request_options = isset( $stream_args['request_options'] ) && $stream_args['request_options'] instanceof RequestOptions;
		$has_timeout         = array_key_exists( 'request_timeout', $stream_args );
		$has_connect_timeout = array_key_exists( 'connect_timeout', $stream_args );
		$has_max_redirects   = array_key_exists( 'max_redirects', $stream_args );

		if ( ! $has_request_options && ! $has_timeout && ! $has_connect_timeout && ! $has_max_redirects ) {
			return null;
		}

		$options = $has_request_options ? clone $stream_args['request_options'] : new RequestOptions();

		if ( ! $has_request_options ) {
			$default_timeout = (float) apply_filters( 'wp_ai_client_default_request_timeout', 30 );

			if ( $default_timeout > 0 ) {
				$options->setTimeout( $default_timeout );
			}
		}

		if ( $has_timeout ) {
			$timeout = (float) $stream_args['request_timeout'];

			if ( $timeout > 0 ) {
				$options->setTimeout( $timeout );
			}
		}

		if ( $has_connect_timeout ) {
			$connect_timeout = (float) $stream_args['connect_timeout'];

			if ( $connect_timeout > 0 ) {
				$options->setConnectTimeout( $connect_timeout );
			}
		}

		if ( $has_max_redirects ) {
			$max_redirects = (int) $stream_args['max_redirects'];

			if ( $max_redirects >= 0 ) {
				$options->setMaxRedirects( $max_redirects );
			}
		}

		return $options;
	}

	/**
	 * Applies the active streaming context to an outbound request analysis.
	 *
	 * @since 0.2.0
	 *
	 * @param object                $request  PSR-7 request.
	 * @param array<string, string> $headers  Request headers.
	 * @param string|null           $body     Request body.
	 * @param array<string, mixed>  $contract Existing streaming contract.
	 * @return array<string, mixed>
	 */
	public static function maybe_apply_request_context( $request, array $headers, ?string $body, array $contract ): array {
		$context = self::find_matching_context( $request, $headers, $body );

		if ( null === $context ) {
			return array(
				'headers'  => $headers,
				'body'     => $body,
				'contract' => $contract,
			);
		}

		$body    = self::prepare_streaming_body( $headers, $body, $context );
		$headers = self::remove_header( $headers, 'Content-Length' );
		$headers = self::remove_header( $headers, 'Transfer-Encoding' );

		if ( 'sse' === $context['mode'] && ! self::has_header( $headers, 'Accept' ) ) {
			$headers['Accept'] = 'text/event-stream';
		}

		$headers['X-WP-AI-Client-Stream']            = '1';
		$headers['X-WP-AI-Client-Stream-Mode']       = $context['mode'];
		$headers['X-WP-AI-Client-Stream-Request-Id'] = $context['request_id'];

		if ( empty( $context['capture_body'] ) ) {
			$headers['X-WP-AI-Client-Stream-Capture'] = '0';
		}

		$contract['enabled']      = true;
		$contract['mode']         = $context['mode'];
		$contract['capture_body'] = ! empty( $context['capture_body'] );
		$contract['request_id']   = $context['request_id'];

		return array(
			'headers'  => $headers,
			'body'     => $body,
			'contract' => $contract,
		);
	}

	/**
	 * Registers temporary listeners for one streaming run.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $context Streaming context.
	 * @return array<int, array<string, mixed>>
	 */
	private static function register_listeners( array $context ): array {
		$listeners = array();

		if ( is_callable( $context['on_chunk'] ) ) {
			$listener = static function ( string $chunk, array $stream_context ) use ( $context ) {
				if ( ! self::matches_request_id( $stream_context, $context['request_id'] ) ) {
					return;
				}

				call_user_func( $context['on_chunk'], $chunk, $stream_context );
			};

			add_action( 'wp_ai_client_stream_chunk', $listener, 10, 2 );
			$listeners[] = array(
				'type'     => 'action',
				'hook'     => 'wp_ai_client_stream_chunk',
				'callback' => $listener,
			);
		}

		if ( is_callable( $context['on_event'] ) ) {
			$listener = static function ( WP_AI_Client_SSE_Event $event, array $stream_context ) use ( $context ) {
				if ( ! self::matches_request_id( $stream_context, $context['request_id'] ) ) {
					return;
				}

				call_user_func( $context['on_event'], $event, $stream_context );
			};

			add_action( 'wp_ai_client_stream_sse_event', $listener, 10, 2 );
			$listeners[] = array(
				'type'     => 'action',
				'hook'     => 'wp_ai_client_stream_sse_event',
				'callback' => $listener,
			);
		}

		if ( is_callable( $context['on_complete'] ) ) {
			$listener = static function ( array $response, array $stream_context ) use ( $context ) {
				if ( ! self::matches_request_id( $stream_context, $context['request_id'] ) ) {
					return;
				}

				call_user_func( $context['on_complete'], $response, $stream_context );
			};

			add_action( 'wp_ai_client_stream_complete', $listener, 10, 2 );
			$listeners[] = array(
				'type'     => 'action',
				'hook'     => 'wp_ai_client_stream_complete',
				'callback' => $listener,
			);
		}

		if ( is_callable( $context['on_error'] ) ) {
			$listener = static function ( string $message, array $stream_context ) use ( $context ) {
				if ( ! self::matches_request_id( $stream_context, $context['request_id'] ) ) {
					return;
				}

				call_user_func( $context['on_error'], $message, $stream_context );
			};

			add_action( 'wp_ai_client_stream_error', $listener, 10, 2 );
			$listeners[] = array(
				'type'     => 'action',
				'hook'     => 'wp_ai_client_stream_error',
				'callback' => $listener,
			);
		}

		if ( is_callable( $context['should_continue'] ) ) {
			$listener = static function ( bool $continue, $payload, array $stream_context ) use ( $context ) {
				if ( ! self::matches_request_id( $stream_context, $context['request_id'] ) ) {
					return $continue;
				}

				return (bool) call_user_func( $context['should_continue'], $continue, $payload, $stream_context );
			};

			add_filter( 'wp_ai_client_stream_continue', $listener, 10, 3 );
			$listeners[] = array(
				'type'     => 'filter',
				'hook'     => 'wp_ai_client_stream_continue',
				'callback' => $listener,
			);
		}

		return $listeners;
	}

	/**
	 * Removes temporary listeners after one streaming run.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, mixed>> $listeners Registered listeners.
	 * @return void
	 */
	private static function remove_listeners( array $listeners ): void {
		foreach ( $listeners as $listener ) {
			if ( 'filter' === $listener['type'] ) {
				remove_filter( $listener['hook'], $listener['callback'], 10 );
				continue;
			}

			remove_action( $listener['hook'], $listener['callback'], 10 );
		}
	}

	/**
	 * Normalizes raw streaming options into a context array.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $stream_args Raw streaming options.
	 * @return array<string, mixed>
	 */
	private static function normalize_stream_args( array $stream_args ): array {
		$defaults = array(
			'mode'                    => 'sse',
			'streaming_enabled'       => true,
			'capture_body'            => true,
			'inject_stream_parameter' => true,
			'request_id'              => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wp-ai-client-stream-', true ),
			'max_requests'            => 1,
			'request_matcher'         => null,
			'payload_mutator'         => null,
			'on_chunk'                => null,
			'on_event'                => null,
			'on_complete'             => null,
			'on_error'                => null,
			'should_continue'         => null,
		);

		$context = wp_parse_args( $stream_args, $defaults );
		$mode    = strtolower( (string) $context['mode'] );

		if ( ! in_array( $mode, array( 'sse', 'raw' ), true ) ) {
			$mode = 'sse';
		}

		foreach ( array( 'request_matcher', 'payload_mutator', 'on_chunk', 'on_event', 'on_complete', 'on_error', 'should_continue' ) as $key ) {
			if ( null !== $context[ $key ] && ! is_callable( $context[ $key ] ) ) {
				throw new InvalidArgumentException( sprintf( 'The "%s" streaming option must be callable.', $key ) );
			}
		}

		$context['id']                = uniqid( 'wp-ai-client-stream-context-', true );
		$context['mode']              = $mode;
		$context['streaming_enabled'] = (bool) $context['streaming_enabled'];
		$context['capture_body']      = (bool) $context['capture_body'];
		$context['remaining_hits']    = max( 1, (int) $context['max_requests'] );
		$context['request_id']        = (string) $context['request_id'];

		return $context;
	}

	/**
	 * Finds the first active context that matches the outbound request.
	 *
	 * @since 0.2.0
	 *
	 * @param object                $request PSR-7 request.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Body.
	 * @return array<string, mixed>|null
	 */
	private static function find_matching_context( $request, array $headers, ?string $body ): ?array {
		if ( empty( self::$contexts ) ) {
			return null;
		}

		$context_ids = array_keys( self::$contexts );

		for ( $index = count( $context_ids ) - 1; $index >= 0; --$index ) {
			$context_id = $context_ids[ $index ];
			$context    = self::$contexts[ $context_id ];

			if ( empty( $context['remaining_hits'] ) ) {
				continue;
			}

			if ( ! self::context_matches_request( $context, $request, $headers, $body ) ) {
				continue;
			}

			self::$contexts[ $context_id ]['remaining_hits']--;
			$context['remaining_hits'] = self::$contexts[ $context_id ]['remaining_hits'];

			return $context;
		}

		return null;
	}

	/**
	 * Returns whether a context should attach to the request.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed>  $context Context.
	 * @param object                $request PSR-7 request.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Body.
	 * @return bool
	 */
	private static function context_matches_request( array $context, $request, array $headers, ?string $body ): bool {
		if ( empty( $context['streaming_enabled'] ) ) {
			return false;
		}

		if ( is_callable( $context['request_matcher'] ) ) {
			return true === call_user_func( $context['request_matcher'], $request, $headers, $body, $context );
		}

		return self::default_request_matcher( $request, $headers, $body );
	}

	/**
	 * Default request matcher for text-style generation requests.
	 *
	 * @since 0.2.0
	 *
	 * @param object                $request PSR-7 request.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Body.
	 * @return bool
	 */
	private static function default_request_matcher( $request, array $headers, ?string $body ): bool {
		$method = strtoupper( (string) $request->getMethod() );

		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return false;
		}

		if ( self::header_contains( $headers, 'accept', 'text/event-stream' ) ) {
			return true;
		}

		$payload = self::decode_json_body( $headers, $body );

		if ( ! is_array( $payload ) ) {
			return false;
		}

		foreach ( array( 'messages', 'input', 'contents' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				return true;
			}
		}

		return ! empty( $payload['stream'] );
	}

	/**
	 * Prepares a JSON request body for provider-side streaming.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Original body.
	 * @param array<string, mixed>  $context Streaming context.
	 * @return string|null
	 */
	private static function prepare_streaming_body( array $headers, ?string $body, array $context ): ?string {
		$payload = self::decode_json_body( $headers, $body );

		if ( ! is_array( $payload ) ) {
			return $body;
		}

		if ( is_callable( $context['payload_mutator'] ) ) {
			$mutated_payload = call_user_func( $context['payload_mutator'], $payload, $context );

			if ( is_array( $mutated_payload ) ) {
				$payload = $mutated_payload;
			}
		}

		if ( ! empty( $context['inject_stream_parameter'] ) ) {
			$payload['stream'] = true;
		}

		$encoded = wp_json_encode( $payload );

		return false === $encoded ? $body : $encoded;
	}

	/**
	 * Decodes a JSON request body when possible.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Body.
	 * @return array<string, mixed>|null
	 */
	private static function decode_json_body( array $headers, ?string $body ): ?array {
		if ( empty( $body ) || ! self::looks_like_json_request( $headers ) ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Returns whether the request looks like a JSON request.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @return bool
	 */
	private static function looks_like_json_request( array $headers ): bool {
		return self::header_contains( $headers, 'content-type', 'application/json' ) || self::header_contains( $headers, 'content-type', '+json' );
	}

	/**
	 * Returns whether the header array already contains a header.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string                $header  Header name.
	 * @return bool
	 */
	private static function has_header( array $headers, string $header ): bool {
		foreach ( $headers as $name => $value ) {
			if ( strtolower( $name ) === strtolower( $header ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Removes a header from a header array.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string                $header  Header name.
	 * @return array<string, string>
	 */
	private static function remove_header( array $headers, string $header ): array {
		foreach ( array_keys( $headers ) as $name ) {
			if ( strtolower( $name ) === strtolower( $header ) ) {
				unset( $headers[ $name ] );
			}
		}

		return $headers;
	}

	/**
	 * Returns whether a specific header contains a value fragment.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string                $header  Header name.
	 * @param string                $needle  Value fragment.
	 * @return bool
	 */
	private static function header_contains( array $headers, string $header, string $needle ): bool {
		foreach ( $headers as $name => $value ) {
			if ( strtolower( $name ) !== strtolower( $header ) ) {
				continue;
			}

			return false !== stripos( $value, $needle );
		}

		return false;
	}

	/**
	 * Returns whether the stream callback context matches the request ID.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $stream_context Stream context.
	 * @param string               $request_id     Request ID.
	 * @return bool
	 */
	private static function matches_request_id( array $stream_context, string $request_id ): bool {
		return isset( $stream_context['request_id'] ) && $request_id === $stream_context['request_id'];
	}
}
