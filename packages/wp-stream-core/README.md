# WP Stream Core

`bradvin/wp-stream-core` is the reusable Composer runtime behind the `WP Stream` plugin.

It exposes the streaming transport, SSE parsing, and `Ai_Client_Bridge` helpers without any plugin-specific UI or asset concerns.

## Install

```bash
composer require bradvin/wp-stream-core
```

## Usage

Call `WP_Stream\Integration\Runtime::register()` during your plugin bootstrap, then use `WP_Stream\Ai_Client_Bridge::generateResult()` in the normal AI client flow.

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
