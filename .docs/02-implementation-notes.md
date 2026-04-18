# Implementation Notes

Date: 2026-04-18

## What Changed

See also: `03-usage-guide.md` for concrete integration examples.

### Plugin bootstrap

- Added a standalone plugin entrypoint in `wp-stream.php`.
- Added environment detection in `includes/class-plugin.php`.
- The plugin now registers a higher-priority HTTPlug discovery strategy on `plugins_loaded`.
- The transport diagnostics now treat the active HTTP client as the source of truth for the admin demo health check.
- A false warning on the demo page was fixed by checking the core PSR request dependency with `interface_exists()` instead of `class_exists()`.

### Discovery override

- Added a core-scoped discovery strategy for WordPress 7.0+.
- Added a legacy discovery strategy for the pre-core `wp-ai-client` package.
- Both strategies return a streaming-aware client wrapper that sits in front of the stock WordPress AI HTTP client.

### Shared transport logic

- Added `Streaming_HTTP_Client_Service` as the shared implementation.
- Non-streaming requests are delegated to the stock client unchanged.
- Streaming requests are executed through a custom cURL path that mirrors key `WP_Http` behavior.
- The transport now checks `Ai_Client_Bridge` before auto-detection so a wrapper call can force one matching AI request into streaming mode without changing core code.

### AiClient bridge

- Added `WP_Stream\Ai_Client_Bridge::generateResult()` as a class-level replacement for `WordPress\AiClient\AiClient::generateResult()`.
- Added `WP_Stream\Ai_Client_Bridge::with_streaming()` to wrap arbitrary AI-client work in the same transport context.
- Added a global helper `wp_stream_generate_result()` for theme/plugin code that prefers functions over static class calls.
- The bridge registers temporary callbacks, assigns a request ID, injects `stream: true` into matching JSON payloads by default, and still returns the final `GenerativeAiResult`.
- Request matching is intentionally conservative by default. It looks for text-generation style JSON bodies (`messages`, `input`, or `contents`) and can be overridden with a custom `request_matcher`.
- `generateResult()` bridge calls now also attach HTTP request options so they do not fall back to the SDK's shorter 5-second timeout. By default the bridge mirrors WordPress's 30-second AI timeout, and callers can override it with `request_options`, `request_timeout`, `connect_timeout`, and `max_redirects`.

### Admin demo page

- Added a minimal wp-admin chat page under `Tools > WP Stream Chat`.
- The page uses a small browser-side transcript only, with no persistence layer yet.
- Requests are sent to `admin-ajax.php` and streamed back as `text/event-stream` frames.
- The server converts the browser transcript into real AI client `Message` objects, runs `wp_stream_generate_result()`, forwards streamed deltas to the browser, and still finishes with the final `GenerativeAiResult`.
- The demo now explicitly uses a longer request timeout budget so longer streamed generations do not fail after the SDK's fallback 5-second timeout.
- After comparing the behavior with a simpler direct streaming implementation, the demo was updated to stream back to the browser as real `text/event-stream` frames rather than NDJSON, because that is more likely to flush incrementally in local PHP/web server stacks.
- The admin response path now also disables gzip more aggressively, forces identity encoding headers, and pads each SSE flush because some local PHP stacks still buffer small writes.

### Streaming transport follow-up

- After comparing the plugin transport against a simpler direct cURL streaming setup, the streaming client now suppresses `Expect: 100-Continue` for streaming requests and forces `Accept-Encoding: identity` instead of opting into transparent decompression.
- The streaming request path also now forces HTTP/1.1 for opted-in streaming requests instead of inheriting WordPress's usual HTTP/1.0 fallback.
- Those changes keep the streaming request path closer to the simple cURL setup that is known to flush incrementally.
- For OpenAI Responses streaming, the transport now converts the captured SSE transcript back into the final JSON response object by extracting the terminal `response.completed` payload before the provider parser runs.
- That normalization is necessary because the OpenAI provider expects the final response body to contain the standard top-level `output` array, not a raw `text/event-stream` transcript.

### Temporary plugin-side streaming contract

Until `php-ai-client` grows a first-class streaming transport API, this plugin recognizes opt-in transport headers and provider-style `stream: true` JSON bodies.

Supported control headers:

- `X-WP-Stream: sse` or `X-WP-Stream: raw`
- `X-WP-Stream-Request-Id: <id>`
- `X-WP-Stream-Capture: body|none`

Legacy aliases are also accepted:

- `X-Stream`
- `X-Stream-Request-Id`
- `X-Stream-Capture`

These headers are internal control headers only. They are stripped before the outbound HTTP request is sent.

### Hooks exposed by the plugin

- `wp_stream_http_request_start`
- `wp_stream_http_chunk`
- `wp_stream_http_sse_event`
- `wp_stream_http_complete`
- `wp_stream_http_error`
- `wp_stream_http_continue` filter

The plugin also emits `requests-request.progress` so code already listening for chunk progress has a familiar integration point.

## Known Limits

1. The local `php-ai-client` build in WordPress 7.0 RC2 still converts the final PSR response body back to a string in `HttpTransporter`. This plugin therefore solves transport-side streaming first, but a future upstream change is still needed for a clean public streaming API.
2. The plugin uses cURL for the streaming path. If cURL is unavailable, the request falls back to the stock client and therefore loses streaming behavior.
3. Redirect behavior is handled in the plugin, but it is intentionally narrower than WordPress core's full `Requests` stack.

## Why This Shape

This is the smallest plugin-only change that actually works:

- no WordPress core patch
- no Requests patch
- minimal impact on ordinary requests
- clear upgrade path once upstream adds a formal streaming interface
