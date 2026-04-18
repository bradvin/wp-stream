# WP Stream

`WP Stream` now ships as two pieces:

- `bradvin/wp-ai-client-streaming`: a standalone WordPress 7 streaming adapter package that mirrors core’s `WP_AI_*` integration style.
- `wp-stream`: a thin wrapper plugin that keeps the demo UI and package bootstrap.

The goal is to make the reusable package look like a small WordPress AI adapter layer that could be copied into core with minimal structural change.

## Package Layout

- `bradvin/wp-ai-client-streaming`
  Standalone Composer package with the core-style adapter files, loader, and streaming prompt helpers.
- Plugin root
  Wrapper bootstrap, admin demo, and assets.

## Wrapper Plugin Installation

1. Copy this plugin into `wp-content/plugins/wp-stream`.
2. Make sure the standalone `bradvin/wp-ai-client-streaming` package repo is available to Composer.
3. Run `composer install`.
4. Activate `WP Stream`.
5. Make sure WordPress 7 AI support is available in the runtime.

## Composer Consumption

Bootstrap the adapter early in your plugin load path:

```php
WP_AI_Client_Streaming_Discovery_Strategy::init();
```

Then use the WordPress-style streaming helper:

```php
$result = wp_ai_client_stream_prompt(
	$prompt_messages,
	array(
		'streaming_enabled' => true,
		'on_event'          => static function ( WP_AI_Client_SSE_Event $event, array $context ) {
			// Handle streamed SSE events.
		},
	)
)
	->using_model_config( $model_config )
	->generate_result();
```

If you already have a core prompt builder, wrap it directly:

```php
$builder = wp_ai_client_prompt( $prompt_messages )->using_model_config( $model_config );
$result  = wp_ai_client_stream( $builder, array( 'streaming_enabled' => true ) )->generate_result();
```

## Streaming Hooks

The runtime-facing hooks now follow the WordPress AI naming model:

- `wp_ai_client_stream_request_start`
- `wp_ai_client_stream_chunk`
- `wp_ai_client_stream_sse_event`
- `wp_ai_client_stream_complete`
- `wp_ai_client_stream_error`
- `wp_ai_client_stream_continue`

## Demo UI

After activation, open `Tools > WP Stream Chat`.

That screen uses the same `wp_ai_client_stream_prompt()` flow as external consumers, so the demo stays aligned with the package’s preferred public API.
