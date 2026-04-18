<?php
/**
 * WP AI Client: WP_AI_Client_Streaming_Prompt_Builder class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( class_exists( 'WP_AI_Client_Streaming_Prompt_Builder', false ) ) {
	return;
}

/**
 * Streaming-aware wrapper around WP_AI_Client_Prompt_Builder.
 *
 * @since 0.2.0
 */
class WP_AI_Client_Streaming_Prompt_Builder {

	/**
	 * Wrapped core prompt builder.
	 *
	 * @since 0.2.0
	 * @var WP_AI_Client_Prompt_Builder
	 */
	private WP_AI_Client_Prompt_Builder $builder;

	/**
	 * Streaming options applied to generating calls.
	 *
	 * @since 0.2.0
	 * @var array<string, mixed>
	 */
	private array $stream_args = array();

	/**
	 * List of generating methods.
	 *
	 * @since 0.2.0
	 * @var array<string, bool>
	 */
	private static array $generating_methods = array(
		'generate_result'               => true,
		'generate_text_result'          => true,
		'generate_image_result'         => true,
		'generate_speech_result'        => true,
		'convert_text_to_speech_result' => true,
		'generate_video_result'         => true,
		'generate_text'                 => true,
		'generate_texts'                => true,
		'generate_image'                => true,
		'generate_images'               => true,
		'convert_text_to_speech'        => true,
		'convert_text_to_speeches'      => true,
		'generate_speech'               => true,
		'generate_speeches'             => true,
		'generate_video'                => true,
		'generate_videos'               => true,
	);

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_AI_Client_Prompt_Builder $builder     Core prompt builder.
	 * @param array<string, mixed>        $stream_args Optional streaming options.
	 */
	public function __construct( WP_AI_Client_Prompt_Builder $builder, array $stream_args = array() ) {
		$this->builder     = $builder;
		$this->stream_args = $stream_args;
	}

	/**
	 * Creates a streaming wrapper around an existing prompt builder.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_AI_Client_Prompt_Builder $builder     Core prompt builder.
	 * @param array<string, mixed>        $stream_args Optional streaming options.
	 * @return self
	 */
	public static function from_prompt_builder( WP_AI_Client_Prompt_Builder $builder, array $stream_args = array() ): self {
		return new self( $builder, $stream_args );
	}

	/**
	 * Merges new streaming options into the builder.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $stream_args Streaming options.
	 * @return self
	 */
	public function using_streaming( array $stream_args ): self {
		$this->stream_args = array_merge( $this->stream_args, $stream_args );

		return $this;
	}

	/**
	 * Returns the wrapped core prompt builder.
	 *
	 * @since 0.2.0
	 *
	 * @return WP_AI_Client_Prompt_Builder
	 */
	public function get_prompt_builder(): WP_AI_Client_Prompt_Builder {
		return $this->builder;
	}

	/**
	 * Proxies fluent builder calls and wraps generating methods in streaming context.
	 *
	 * @since 0.2.0
	 *
	 * @param string            $name      Method name.
	 * @param array<int, mixed> $arguments Method arguments.
	 * @return mixed
	 */
	public function __call( string $name, array $arguments ) {
		if ( isset( self::$generating_methods[ $name ] ) ) {
			try {
				$request_options = WP_AI_Client_Streaming_Context::build_request_options( $this->stream_args );

				if ( null !== $request_options ) {
					$this->builder->using_request_options( $request_options );
				}

				return WP_AI_Client_Streaming_Context::with_streaming(
					function () use ( $name, $arguments ) {
						return $this->builder->$name( ...$arguments );
					},
					$this->stream_args
				);
			} catch ( \Throwable $throwable ) {
				return new \WP_Error(
					'wp_ai_client_stream_error',
					$throwable->getMessage(),
					array(
						'status' => 500,
					)
				);
			}
		}

		$result = $this->builder->$name( ...$arguments );

		if ( $result instanceof WP_AI_Client_Prompt_Builder ) {
			return $this;
		}

		return $result;
	}
}
