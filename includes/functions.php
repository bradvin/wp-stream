<?php
/**
 * Public helper functions for WP Stream.
 *
 * @package WP_Stream
 */

use WP_Stream\Ai_Client_Bridge;

if ( ! function_exists( 'wp_stream_generate_result' ) ) {
	/**
	 * Drop-in wrapper for AiClient::generateResult() with streaming callbacks.
	 *
	 * @param mixed $prompt          Prompt passed to the AI client.
	 * @param mixed $model_or_config Model or config passed to the AI client.
	 * @param mixed $registry        Optional provider registry.
	 * @param array $stream_args     Streaming bridge options.
	 * @return mixed
	 */
	function wp_stream_generate_result( $prompt, $model_or_config, $registry = null, array $stream_args = array() ) {
		return Ai_Client_Bridge::generateResult( $prompt, $model_or_config, $registry, $stream_args );
	}
}
