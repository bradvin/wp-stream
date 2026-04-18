# WP Stream

`WP Stream` is a WordPress plugin that adds plugin-side streaming support to the WordPress AI client HTTP transport without patching WordPress core.

It installs a streaming-aware HTTP client into the AI client stack, keeps normal requests on the standard path, and only switches to the custom cURL transport when a request opts into streaming.

## What It Includes

- A transport layer that can handle streamed SSE responses.
- A bridge helper for `AiClient::generateResult()`-style usage.
- A simple admin demo at `Tools > WP Stream Chat`.
- Plugin-side integration only. No WordPress core edits are required.

## Installation

1. Copy this plugin into `wp-content/plugins/wp-stream`.
2. Activate `WP Stream`.
3. Make sure the WordPress AI client is available in the site runtime.

## Quick Start

### Admin Demo

After activation, open `Tools > WP Stream Chat`.

That screen uses the bridge helper and lets you toggle streaming on and off while testing the same request flow.

### PHP Usage

Use `wp_stream_generate_result()` as a drop-in wrapper around the normal AI client prompt flow. It still returns the final result object, but it can also forward streaming events while the request is running.

```php
use WordPress\AiClient\Builders\MessageBuilder;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WP_Stream\SSE_Event;

$prompt_messages = array(
	( new MessageBuilder( 'Write a short post about WordPress performance.' ) )
		->usingUserRole()
		->get(),
);

$model_config = new ModelConfig();
$model_config->setOutputModalities( array( ModalityEnum::text() ) );

$events = array();

$result = wp_stream_generate_result(
	$prompt_messages,
	$model_config,
	null,
	array(
		'streaming_enabled' => true,
		'on_event'          => static function ( SSE_Event $event ) use ( &$events ) {
			$events[] = $event->to_array();
		},
	)
);

$final_text = $result->toText();
```

## Common Options

- `streaming_enabled`:
  Set to `true` to allow the bridge to attach to the outbound request.
  Set to `false` to keep the same wrapper call but skip streaming.
- `request_timeout`:
  Overrides the AI request timeout.
- `connect_timeout`:
  Overrides the connection timeout.
- `on_event`:
  Receives parsed `SSE_Event` objects while the response is streaming.
- `on_chunk`:
  Receives raw streamed chunks.
- `should_continue`:
  Lets you abort a running stream.

## How It Works

The plugin synchronizes the default AI client registry with a streaming-aware HTTP client. When a request is matched for streaming, the bridge marks that request as stream-enabled, the transport sends it as an SSE-capable cURL request, and the plugin emits chunk and event callbacks while the response is still in flight.

If a request is not matched for streaming, it falls back to the normal AI client transport path.

## Notes

- This plugin is intended to extend the WordPress AI client, not replace it.
- The bridge keeps `generateResult()`-style ergonomics, but streaming happens through callbacks during the request.
- The admin chat screen is intentionally minimal and is meant as a working streaming example, not a production chat UI.
