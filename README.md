# WP Stream

`WP Stream` is now split into two parts:

- `bradvin/wp-stream-core`: a Composer library with the reusable streaming runtime.
- `wp-stream`: a thin WordPress plugin wrapper that ships the admin demo and backward-compatible global helper.

The runtime installs a streaming-aware HTTP client into the AI client stack, keeps normal requests on the standard path, and only switches to the custom cURL transport when a request opts into streaming.

## Packages

- `packages/wp-stream-core/`: Composer package for reuse inside other plugins.
- Plugin root: wrapper plugin, admin demo, assets, and `wp_stream_generate_result()`.

## Wrapper Plugin Installation

1. Copy this plugin into `wp-content/plugins/wp-stream`.
2. Run `composer install` when building a distributable plugin, or rely on the included source fallback in local development.
3. Activate `WP Stream`.
4. Make sure the WordPress AI client is available in the site runtime.

## Composer Consumption

Require the runtime package from another plugin and register it during your bootstrap:

```php
use WP_Stream\Ai_Client_Bridge;
use WP_Stream\Integration\Runtime;

Runtime::register();

$result = Ai_Client_Bridge::generateResult(
	$prompt_messages,
	$model_config,
	null,
	array(
		'streaming_enabled' => true,
	)
);
```

This is the preferred integration path when you want to ship the streaming runtime inside another plugin.

## Wrapper Plugin Quick Start

### Admin Demo

After activation, open `Tools > WP Stream Chat`.

That screen uses the Composer runtime through the thin wrapper and lets you toggle streaming on and off while testing the same request flow.

### Backward-Compatible PHP Helper

The wrapper still exposes `wp_stream_generate_result()` as a compatibility helper. It forwards directly to `WP_Stream\Ai_Client_Bridge::generateResult()`.

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

`WP_Stream\Integration\Runtime::register()` synchronizes the default AI client registry with a streaming-aware HTTP client. When a request is matched for streaming, the bridge marks that request as stream-enabled, the transport sends it as an SSE-capable cURL request, and the runtime emits chunk and event callbacks while the response is still in flight.

If a request is not matched for streaming, it falls back to the normal AI client transport path.

## Notes

- The Composer runtime is the source of truth for reusable streaming behavior.
- The wrapper plugin is intentionally thin and only exists for standalone plugin usage and the demo UI.
- The admin chat screen is intentionally minimal and is meant as a working streaming example, not a production chat UI.
