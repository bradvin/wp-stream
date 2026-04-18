# Research Notes

Date: 2026-04-18

## Goal

Add streaming support to the WordPress AI client HTTP adapter with a plugin-first approach and without modifying WordPress core.

## Sources Reviewed

- `WordPress/wp-ai-client#11`
- `WordPress/php-ai-client#100`
- `Sarai-Chinwag/wordpress-core-docs#1`
- `Sarai-Chinwag/wordpress-core-docs/streaming-analysis.md`
- `felixarntz/ai-services/includes/Services/HTTP/HTTP_With_Streams.php`
- `felixarntz/ai-services/includes/Services/HTTP/Stream_Response.php`
- `felixarntz/ai-services/includes/Services/Traits/Generative_AI_API_Client_Trait.php`
- `felixarntz/wp-oop-plugin-lib/src/HTTP/HTTP.php`
- Local WordPress 7.0 RC2 core AI client adapter:
  - `wp-includes/ai-client/adapters/class-wp-ai-client-http-client.php`
  - `wp-includes/ai-client/adapters/class-wp-ai-client-discovery-strategy.php`
  - `wp-includes/php-ai-client/src/Providers/Http/HttpTransporter.php`

## Findings

1. `wp_remote_request()` and the bundled `Requests` stack still assume a complete response body. They can stream to a file, but not to an in-memory callback that userland can process incrementally.
2. The AI client transport override point already exists: HTTPlug discovery is registered through `AbstractClientDiscoveryStrategy::init()` and uses `prependStrategy()`. A plugin can register its own strategy after core boot and clear the discovery cache cleanly.
3. The current AI client HTTP adapter is intentionally thin. It only converts PSR requests into `wp_remote_request()` or `wp_safe_remote_request()` calls and then rebuilds a PSR response. That means a plugin can replace only the HTTP adapter without changing the higher-level AI client API.
4. `php-ai-client` does not yet expose a first-class streaming transport contract in the local WordPress 7.0 RC2 code. The plugin therefore needs a transport-side opt-in contract for now.
5. Felix Arntz's `ai-services` implementation proves the pragmatic route: use a separate streaming transport under the hood, but keep WordPress defaults, proxy support, SSL handling, and hooks aligned with core.
6. Sarai Chinwag's analysis is directionally correct for core work: true callback-based streaming in core would require changes across `WP_Http`, `Requests`, and the transport layers. That is a larger change than this plugin task.

## Decision

Ship a plugin-side discovery strategy that:

- Delegates normal requests to the stock AI client adapter.
- Uses a custom cURL transport only for requests that explicitly opt into streaming.
- Preserves the most important WordPress HTTP semantics:
  - `http_request_args`
  - `pre_http_request`
  - `http_api_curl`
  - `http_api_debug`
  - `http_response`
  - proxy handling
  - SSL certificate handling
  - redirect handling
- Exposes chunk and SSE event hooks from the plugin while leaving the rest of WordPress untouched.
