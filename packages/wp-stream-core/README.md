# WP Stream Core

`bradvin/wp-stream-core` is a WordPress 7 streaming adapter package designed to read like a small extension of core’s AI client layer.

It exposes core-style `WP_AI_*` classes and helper functions, while leaving initialization explicit so callers can bootstrap it early in their own plugin load order.

## Install

```bash
composer require bradvin/wp-stream-core
```

## Bootstrap

Initialize the discovery strategy during your plugin bootstrap, before you start registering or using AI providers:

```php
WP_AI_Client_Streaming_Discovery_Strategy::init();
```

## Usage

Use the streaming-aware prompt helper directly:

```php
$result = wp_ai_client_stream_prompt(
	$prompt_messages,
	array(
		'streaming_enabled' => true,
	)
)
	->using_model_config( $model_config )
	->generate_result();
```

Or wrap an existing core prompt builder:

```php
$builder = wp_ai_client_prompt( $prompt_messages )->using_model_config( $model_config );
$result  = wp_ai_client_stream( $builder, array( 'streaming_enabled' => true ) )->generate_result();
```
