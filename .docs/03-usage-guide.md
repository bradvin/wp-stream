# Usage Guide

Date: 2026-04-18

## What this plugin gives you

This plugin does not add a new public WordPress API by itself. It replaces the AI client's HTTP transport so that requests which opt into streaming can:

- emit raw chunk callbacks
- emit parsed SSE events
- optionally avoid buffering the full response body
- keep the rest of the WordPress AI client stack unchanged

In practice, today there are two realistic ways to use it:

1. Low-level: issue requests through `WordPress\AiClient\Providers\Http\HttpTransporter`.
2. Provider/model integration: add the plugin's internal transport headers when building a provider request, then consume the hook events.

## Streaming contract

The transport turns on streaming when either of these is true:

- the request includes `X-WP-Stream: sse` or `X-WP-Stream: raw`
- the JSON request body contains `"stream": true`

Useful internal headers:

- `X-WP-Stream: sse`
- `X-WP-Stream: raw`
- `X-WP-Stream-Request-Id: <unique-id>`
- `X-WP-Stream-Capture: none`

Notes:

- The transport strips these headers before the outbound HTTP request is sent.
- `X-WP-Stream-Capture: none` means the final PSR response body will be empty on purpose.
- If you omit `X-WP-Stream-Capture`, the plugin stores the streamed body in a temp stream so the final response can still be rebuilt.

## Hook reference

### `wp_stream_http_request_start`

Arguments:

- `array $context`

Useful keys:

- `request_id`
- `mode`
- `url`
- `method`
- `headers`

### `wp_stream_http_chunk`

Arguments:

- `string $chunk`
- `array $context`

Useful keys added here:

- `bytes_received`
- `limit_response_size`

### `wp_stream_http_sse_event`

Arguments:

- `WP_Stream\SSE_Event $event`
- `array $context`

Useful methods on `SSE_Event`:

- `get_event()`
- `get_data()`
- `get_id()`
- `get_retry()`
- `get_json_data()`
- `is_done()`

### `wp_stream_http_complete`

Arguments:

- `array $response`
- `array $context`

### `wp_stream_http_error`

Arguments:

- `string $message`
- `array $context`

### `wp_stream_http_continue`

Filter signature:

- `bool $continue`
- `$payload`
- `array $context`

Return `false` to abort the stream early.

## Example: low-level SSE request

This is the clearest way to use the plugin today because it talks directly to the shared HTTP transporter used by the AI client.

```php
<?php

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\HttpTransporter;

$request_id = wp_generate_uuid4();
$events     = array();

$event_listener = static function ( \WP_Stream\SSE_Event $event, array $context ) use ( $request_id, &$events ) {
	if ( ( $context['request_id'] ?? '' ) !== $request_id ) {
		return;
	}

	if ( $event->is_done() ) {
		return;
	}

	$decoded = $event->get_json_data();

	if ( is_array( $decoded ) ) {
		$events[] = $decoded;
	}
};

add_action( 'wp_stream_http_sse_event', $event_listener, 10, 2 );

try {
	$options = new RequestOptions();
	$options->setTimeout( 120.0 );
	$options->setMaxRedirects( 3 );

	$request = new Request(
		HttpMethodEnum::POST(),
		'https://api.openai.com/v1/chat/completions',
		array(
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . OPENAI_API_KEY,
			'X-WP-Stream' => 'sse',
			'X-WP-Stream-Request-Id' => $request_id,
			'X-WP-Stream-Capture' => 'none',
		),
		array(
			'model' => 'gpt-4.1-mini',
			'stream' => true,
			'messages' => array(
				array(
					'role' => 'user',
					'content' => 'Say hello in five short chunks.',
				),
			),
		),
		$options
	);

	$transporter = new HttpTransporter();
	$response    = $transporter->send( $request );

	// Response body is intentionally empty because capture was disabled.
} finally {
	remove_action( 'wp_stream_http_sse_event', $event_listener, 10 );
}
```

## Example: low-level raw chunk request

Use `raw` mode when you want the raw bytes exactly as received instead of parsed SSE events.

```php
<?php

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\HttpTransporter;

$request_id = wp_generate_uuid4();
$chunks     = array();

$chunk_listener = static function ( string $chunk, array $context ) use ( $request_id, &$chunks ) {
	if ( ( $context['request_id'] ?? '' ) !== $request_id ) {
		return;
	}

	$chunks[] = $chunk;
};

add_action( 'wp_stream_http_chunk', $chunk_listener, 10, 2 );

try {
	$request = new Request(
		HttpMethodEnum::GET(),
		'https://example.com/stream',
		array(
			'Accept' => 'application/octet-stream',
			'X-WP-Stream' => 'raw',
			'X-WP-Stream-Request-Id' => $request_id,
		)
	);

	$transporter = new HttpTransporter();
	$response    = $transporter->send( $request );

	$final_body = $response->getBody();
} finally {
	remove_action( 'wp_stream_http_chunk', $chunk_listener, 10 );
}
```

## Example: abort after the first useful event

If you only need the first token, first tool call, or the first matching event, abort the stream with `wp_stream_http_continue`.

```php
<?php

$request_id = wp_generate_uuid4();
$first_text = null;

$event_listener = static function ( \WP_Stream\SSE_Event $event, array $context ) use ( $request_id, &$first_text ) {
	if ( ( $context['request_id'] ?? '' ) !== $request_id ) {
		return;
	}

	$data = $event->get_json_data();

	if ( ! is_array( $data ) ) {
		return;
	}

	$text = $data['choices'][0]['delta']['content'] ?? null;

	if ( is_string( $text ) && '' !== $text && null === $first_text ) {
		$first_text = $text;
	}
};

$continue_filter = static function ( bool $continue, $payload, array $context ) use ( $request_id, &$first_text ) {
	if ( ( $context['request_id'] ?? '' ) !== $request_id ) {
		return $continue;
	}

	if ( null !== $first_text ) {
		return false;
	}

	return $continue;
};

add_action( 'wp_stream_http_sse_event', $event_listener, 10, 2 );
add_filter( 'wp_stream_http_continue', $continue_filter, 10, 3 );

try {
	// Send the request here.
} finally {
	remove_action( 'wp_stream_http_sse_event', $event_listener, 10 );
	remove_filter( 'wp_stream_http_continue', $continue_filter, 10 );
}
```

## Example: provider/model integration pattern

If you are building a custom provider for the AI client, the practical pattern is:

1. Generate a request ID in the model/provider layer.
2. Add `X-WP-Stream: sse` and `X-WP-Stream-Request-Id` to the request.
3. Keep the provider payload's own `"stream": true` flag if the upstream API expects it.
4. Register a listener on `wp_stream_http_sse_event`.
5. Filter on `request_id` so unrelated streams do not collide.

That usually looks like:

```php
$request_id = wp_generate_uuid4();

$request = $request
	->withHeader( 'X-WP-Stream', 'sse' )
	->withHeader( 'X-WP-Stream-Request-Id', $request_id );
```

From there, your provider code can map `SSE_Event` objects into whatever partial-result format you need.

## Current limitation

The plugin solves the transport layer first. The bundled `php-ai-client` in local WordPress 7.0 RC2 still turns the final PSR response body back into a string in `HttpTransporter`, and it does not yet expose a first-class public streaming result API.

So today:

- transport-level streaming works
- hook-based incremental consumption works
- provider-level integrations are possible
- prompt-builder-level streaming is still an upstream task

That is why the examples above work directly with the transporter and hooks instead of `wp_ai_client_prompt()` alone.

## Bridge API: drop-in replacement for `AiClient::generateResult()`

The plugin now includes a bridge that keeps the stock `generateResult()` path, but activates transport streaming for the matching provider request during that call.

You can use either:

- `WP_Stream\Ai_Client_Bridge::generateResult()`
- `wp_stream_generate_result()`

Both still return the final `GenerativeAiResult`. The difference is that streaming callbacks can run while the request is in flight.

### Example: class-based bridge

```php
<?php

use WP_Stream\Ai_Client_Bridge;

$result = Ai_Client_Bridge::generateResult(
	$prompt,
	$model_config,
	null,
	array(
		'on_event' => static function ( \WP_Stream\SSE_Event $event, array $context ) {
			if ( $event->is_done() ) {
				return;
			}

			$payload = $event->get_json_data();
			$text    = $payload['choices'][0]['delta']['content'] ?? null;

			if ( is_string( $text ) && '' !== $text ) {
				echo $text;
			}
		},
	)
);
```

### Example: function-based bridge

```php
<?php

$result = wp_stream_generate_result(
	$prompt,
	$model_config,
	null,
	array(
		'on_chunk' => static function ( string $chunk, array $context ) {
			error_log( sprintf( 'Chunk for %s: %d bytes', $context['request_id'], strlen( $chunk ) ) );
		},
	)
);
```

### Bridge options

Supported bridge options:

- `mode`: `sse` or `raw`. Defaults to `sse`.
- `streaming_enabled`: master on/off switch for whether the bridge should attach to matching requests. Defaults to `true`.
- `capture_body`: whether the final body should still be buffered so the wrapped call can finish normally. Defaults to `true`. `Ai_Client_Bridge::generateResult()` forces this on because it still has to build a final `GenerativeAiResult`.
- `inject_stream_parameter`: whether to inject `"stream": true` into matching JSON payloads. Defaults to `true`.
- `request_id`: explicit request ID if you want stable correlation across your own logs.
- `request_options`: a `RequestOptions` object to apply when the bridge builds the underlying `PromptBuilder`.
- `request_timeout`: shorthand timeout in seconds for `generateResult()` bridge calls. If omitted, the bridge mirrors WordPress's default AI timeout filter instead of the SDK's shorter fallback.
- `connect_timeout`: shorthand connection timeout in seconds for `generateResult()` bridge calls.
- `max_redirects`: shorthand redirect limit for `generateResult()` bridge calls.
- `on_event`: callback for parsed SSE events.
- `on_chunk`: callback for raw chunks.
- `on_complete`: callback after the HTTP response finishes.
- `on_error`: callback for streaming transport errors.
- `should_continue`: callback with the same shape as `wp_stream_http_continue`; return `false` to abort early.
- `request_matcher`: callback to decide whether the active bridge should attach to a given PSR request.
- `payload_mutator`: callback to adjust the decoded JSON payload before it is re-encoded and sent.
- `max_requests`: how many matching requests inside the wrapped call should be forced into streaming mode. Defaults to `1`.

### Example: generic wrapper for non-drop-in paths

If you are not calling `AiClient::generateResult()` directly, but you still want the same bridge behavior, wrap your own callback:

```php
<?php

use WP_Stream\Ai_Client_Bridge;

$result = Ai_Client_Bridge::with_streaming(
	static function () use ( $prompt_builder ) {
		return $prompt_builder->generate_text_result();
	},
	array(
		'on_event' => static function ( \WP_Stream\SSE_Event $event ) {
			$payload = $event->get_json_data();
			$text    = $payload['choices'][0]['delta']['content'] ?? null;

			if ( is_string( $text ) ) {
				echo $text;
			}
		},
	)
);
```

### Bridge limits

- The bridge still depends on the provider endpoint supporting streaming in the first place.
- The default matcher only auto-targets text-generation style JSON bodies (`messages`, `input`, or `contents`).
- The bridge streams during the request, but the return value is still the final `GenerativeAiResult`, not a live iterator.

## Admin demo page

The plugin now includes a minimal admin demo at `Tools > WP Stream Chat`.

It is intentionally small:

- the transcript lives in the browser only
- the UI posts the transcript to `admin-ajax.php`
- the server rebuilds that transcript into AI client `Message` objects
- the browser reads `text/event-stream` frames from the response body and paints the assistant text incrementally
- the server-side delta extractor checks both the JSON `type` field and the SSE `event:` name for OpenAI Responses text delta events

This page is useful as a working reference because it shows the exact bridge pattern in a real WordPress admin request:

1. Build a normal `ModelConfig`.
2. Convert the chat transcript into AI client messages.
3. Call `wp_stream_generate_result()` with an `on_event` callback.
4. Extract text deltas from `SSE_Event`.
5. Flush those deltas to the browser as they arrive.
6. Replace or confirm the final text with the completed `GenerativeAiResult`.
