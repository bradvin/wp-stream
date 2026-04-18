<?php
/**
 * Loads the WordPress AI streaming adapter package.
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-sse-event.php';
require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-sse-parser.php';
require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-context.php';
require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-http-service.php';
require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-http-client.php';
require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-discovery-strategy.php';
require_once __DIR__ . '/includes/ai-client/adapters/class-wp-ai-client-streaming-transport-diagnostics.php';
require_once __DIR__ . '/includes/ai-client/class-wp-ai-client-streaming-prompt-builder.php';
require_once __DIR__ . '/includes/ai-client.php';
