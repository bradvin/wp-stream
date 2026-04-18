<?php
/**
 * Streaming bridge for AiClient::generateResult().
 *
 * @package WP_Stream
 */

namespace WP_Stream;

/**
 * Wraps AI client calls so the existing generateResult() path can stream via transport hooks.
 */
final class Ai_Client_Bridge {

	/**
	 * Active bridge contexts.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $contexts = array();

	/**
	 * Executes AiClient::generateResult() with streaming callbacks enabled.
	 *
	 * @param mixed $prompt          Prompt passed to the AI client.
	 * @param mixed $model_or_config Model or config passed to the AI client.
	 * @param mixed $registry        Optional provider registry.
	 * @param array $stream_args     Streaming bridge options.
	 * @return mixed
	 */
	public static function generateResult( $prompt, $model_or_config, $registry = null, array $stream_args = array() ) {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			throw new \RuntimeException( 'WordPress\\AiClient\\AiClient is not available in this environment.' );
		}

		$stream_args['capture_body'] = true;
		$request_options            = self::build_generate_result_request_options( $stream_args );

		return self::with_streaming(
			static function () use ( $prompt, $model_or_config, $registry, $request_options ) {
				$builder = \WordPress\AiClient\AiClient::prompt( $prompt, $registry );

				if ( $model_or_config instanceof \WordPress\AiClient\Providers\Models\Contracts\ModelInterface ) {
					$builder->usingModel( $model_or_config );
				} elseif ( $model_or_config instanceof \WordPress\AiClient\Providers\Models\DTO\ModelConfig ) {
					$builder->usingModelConfig( $model_or_config );
				} elseif ( null !== $model_or_config ) {
					throw new \InvalidArgumentException(
						sprintf(
							'Model or config must be an instance of %1$s, %2$s, or null.',
							'\WordPress\AiClient\Providers\Models\Contracts\ModelInterface',
							'\WordPress\AiClient\Providers\Models\DTO\ModelConfig'
						)
					);
				}

				if ( null !== $request_options ) {
					$builder->usingRequestOptions( $request_options );
				}

				return $builder->generateResult();
			},
			$stream_args
		);
	}

	/**
	 * Runs an arbitrary callback with the bridge active.
	 *
	 * @param callable $callback    Callback to execute.
	 * @param array    $stream_args Streaming bridge options.
	 * @return mixed
	 */
	public static function with_streaming( callable $callback, array $stream_args = array() ) {
		$context                         = self::normalize_stream_args( $stream_args );
		self::$contexts[ $context['id'] ] = $context;
		$listeners                       = self::register_listeners( $context );

		try {
			return $callback();
		} finally {
			self::remove_listeners( $listeners );
			unset( self::$contexts[ $context['id'] ] );
		}
	}

	/**
	 * Applies the active bridge context to a PSR request analysis.
	 *
	 * @param object               $request  PSR-7 request.
	 * @param array<string, string> $headers Headers.
	 * @param string|null          $body     Request body.
	 * @param array<string, mixed> $contract Current contract.
	 * @return array<string, mixed>
	 */
	public static function maybe_apply_request_bridge( $request, array $headers, ?string $body, array $contract ): array {
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
	 * Registers temporary callbacks for one bridge run.
	 *
	 * @param array<string, mixed> $context Bridge context.
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

			add_action( 'wp_stream_http_chunk', $listener, 10, 2 );
			$listeners[] = array(
				'type'     => 'action',
				'hook'     => 'wp_stream_http_chunk',
				'callback' => $listener,
			);
		}

		if ( is_callable( $context['on_event'] ) ) {
			$listener = static function ( $event, array $stream_context ) use ( $context ) {
				if ( ! self::matches_request_id( $stream_context, $context['request_id'] ) ) {
					return;
				}

				call_user_func( $context['on_event'], $event, $stream_context );
			};

			add_action( 'wp_stream_http_sse_event', $listener, 10, 2 );
			$listeners[] = array(
				'type'     => 'action',
				'hook'     => 'wp_stream_http_sse_event',
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

			add_action( 'wp_stream_http_complete', $listener, 10, 2 );
			$listeners[] = array(
				'type'     => 'action',
				'hook'     => 'wp_stream_http_complete',
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

			add_action( 'wp_stream_http_error', $listener, 10, 2 );
			$listeners[] = array(
				'type'     => 'action',
				'hook'     => 'wp_stream_http_error',
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

			add_filter( 'wp_stream_http_continue', $listener, 10, 3 );
			$listeners[] = array(
				'type'     => 'filter',
				'hook'     => 'wp_stream_http_continue',
				'callback' => $listener,
			);
		}

		return $listeners;
	}

	/**
	 * Removes temporary callbacks after a bridge run.
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
	 * Normalizes stream bridge options.
	 *
	 * @param array<string, mixed> $stream_args Raw stream args.
	 * @return array<string, mixed>
	 */
	private static function normalize_stream_args( array $stream_args ): array {
		$defaults = array(
			'mode'                    => 'sse',
			'capture_body'            => true,
			'inject_stream_parameter' => true,
			'request_id'              => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wp-stream-', true ),
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

		$callable_keys = array(
			'request_matcher',
			'payload_mutator',
			'on_chunk',
			'on_event',
			'on_complete',
			'on_error',
			'should_continue',
		);

		foreach ( $callable_keys as $key ) {
			if ( null !== $context[ $key ] && ! is_callable( $context[ $key ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'The "%s" stream bridge option must be callable.', $key ) );
			}
		}

		$context['id']              = uniqid( 'wp-stream-bridge-', true );
		$context['mode']            = $mode;
		$context['capture_body']    = (bool) $context['capture_body'];
		$context['remaining_hits']  = max( 1, (int) $context['max_requests'] );
		$context['request_id']      = (string) $context['request_id'];

		return $context;
	}

	/**
	 * Builds request options for generateResult() wrapper calls.
	 *
	 * The core WordPress wrapper sets a 30-second timeout by default, but direct
	 * AiClient::generateResult() calls do not. Mirror that behavior here so the
	 * bridge is a safer drop-in for longer streamed responses.
	 *
	 * @param array<string, mixed> $stream_args Stream args.
	 * @return \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null
	 */
	private static function build_generate_result_request_options( array $stream_args ) {
		if ( isset( $stream_args['request_options'] ) && $stream_args['request_options'] instanceof \WordPress\AiClient\Providers\Http\DTO\RequestOptions ) {
			return clone $stream_args['request_options'];
		}

		$timeout         = isset( $stream_args['request_timeout'] ) ? (float) $stream_args['request_timeout'] : null;
		$connect_timeout = isset( $stream_args['connect_timeout'] ) ? (float) $stream_args['connect_timeout'] : null;
		$max_redirects   = isset( $stream_args['max_redirects'] ) ? (int) $stream_args['max_redirects'] : null;

		if ( null === $timeout ) {
			$timeout = (float) apply_filters( 'wp_ai_client_default_request_timeout', 30 );
		}

		if ( $timeout <= 0 ) {
			return null;
		}

		$options = new \WordPress\AiClient\Providers\Http\DTO\RequestOptions();
		$options->setTimeout( $timeout );

		if ( null !== $connect_timeout && $connect_timeout > 0 ) {
			$options->setConnectTimeout( $connect_timeout );
		}

		if ( null !== $max_redirects && $max_redirects >= 0 ) {
			$options->setMaxRedirects( $max_redirects );
		}

		return $options;
	}

	/**
	 * Finds the first active context that matches the request.
	 *
	 * @param object               $request PSR-7 request.
	 * @param array<string, string> $headers Headers.
	 * @param string|null          $body    Request body.
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
	 * Whether the context should attach to the current request.
	 *
	 * @param array<string, mixed> $context Context.
	 * @param object               $request PSR-7 request.
	 * @param array<string, string> $headers Headers.
	 * @param string|null          $body    Request body.
	 * @return bool
	 */
	private static function context_matches_request( array $context, $request, array $headers, ?string $body ): bool {
		if ( is_callable( $context['request_matcher'] ) ) {
			return true === call_user_func( $context['request_matcher'], $request, $headers, $body, $context );
		}

		return self::default_request_matcher( $request, $headers, $body );
	}

	/**
	 * Default request matching heuristic for text-generation requests.
	 *
	 * @param object               $request PSR-7 request.
	 * @param array<string, string> $headers Headers.
	 * @param string|null          $body    Request body.
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
	 * Prepares the outbound request body for provider-side streaming.
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Original body.
	 * @param array<string, mixed>  $context Bridge context.
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

		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );

		return false === $encoded ? $body : $encoded;
	}

	/**
	 * Decodes a JSON request body when possible.
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    Request body.
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
	 * Whether the request looks like JSON.
	 *
	 * @param array<string, string> $headers Headers.
	 * @return bool
	 */
	private static function looks_like_json_request( array $headers ): bool {
		return self::header_contains( $headers, 'content-type', 'application/json' ) || self::header_contains( $headers, 'content-type', '+json' );
	}

	/**
	 * Whether the given header exists.
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string               $header  Header name.
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
	 * Whether a header contains the requested fragment.
	 *
	 * @param array<string, string> $headers  Headers.
	 * @param string               $header   Header name.
	 * @param string               $fragment Header fragment.
	 * @return bool
	 */
	private static function header_contains( array $headers, string $header, string $fragment ): bool {
		foreach ( $headers as $name => $value ) {
			if ( strtolower( $name ) !== strtolower( $header ) ) {
				continue;
			}

			return false !== stripos( (string) $value, $fragment );
		}

		return false;
	}

	/**
	 * Removes a header by name.
	 *
	 * @param array<string, string> $headers Headers.
	 * @param string               $header  Header name.
	 * @return array<string, string>
	 */
	private static function remove_header( array $headers, string $header ): array {
		foreach ( $headers as $name => $value ) {
			if ( strtolower( $name ) === strtolower( $header ) ) {
				unset( $headers[ $name ] );
			}
		}

		return $headers;
	}

	/**
	 * Whether the hook context belongs to this bridge run.
	 *
	 * @param array<string, mixed> $stream_context Stream context.
	 * @param string               $request_id     Expected request ID.
	 * @return bool
	 */
	private static function matches_request_id( array $stream_context, string $request_id ): bool {
		return isset( $stream_context['request_id'] ) && $request_id === (string) $stream_context['request_id'];
	}
}
