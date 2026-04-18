<?php
/**
 * WordPress AI streaming API helpers.
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;

if ( ! function_exists( 'wp_ai_client_stream_prompt' ) ) {
	/**
	 * Creates a streaming-aware prompt builder.
	 *
	 * @since 0.2.0
	 *
	 * @param string|object|array|null      $prompt      Optional initial prompt content.
	 * @param array<string, mixed>          $stream_args Optional streaming options.
	 * @param ProviderRegistry|null         $registry    Optional provider registry.
	 * @return WP_AI_Client_Streaming_Prompt_Builder
	 */
	function wp_ai_client_stream_prompt( $prompt = null, array $stream_args = array(), ?ProviderRegistry $registry = null ): WP_AI_Client_Streaming_Prompt_Builder {
		return new WP_AI_Client_Streaming_Prompt_Builder(
			new WP_AI_Client_Prompt_Builder( $registry ?? AiClient::defaultRegistry(), $prompt ),
			$stream_args
		);
	}
}

if ( ! function_exists( 'wp_ai_client_stream' ) ) {
	/**
	 * Wraps an existing core prompt builder with streaming support.
	 *
	 * @since 0.2.0
	 *
	 * @param WP_AI_Client_Prompt_Builder $builder     Core prompt builder.
	 * @param array<string, mixed>        $stream_args Optional streaming options.
	 * @return WP_AI_Client_Streaming_Prompt_Builder
	 */
	function wp_ai_client_stream( WP_AI_Client_Prompt_Builder $builder, array $stream_args = array() ): WP_AI_Client_Streaming_Prompt_Builder {
		return WP_AI_Client_Streaming_Prompt_Builder::from_prompt_builder( $builder, $stream_args );
	}
}
