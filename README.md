# WP Stream

`WP Stream` is the thin wrapper plugin for `bradvin/wp-ai-client-streaming`.

The plugin exists to do two things:

- bootstrap the standalone Composer package inside WordPress
- provide the `Tools > WP Stream Chat` admin demo UI

## Wrapper Plugin Installation

1. Copy this plugin into `wp-content/plugins/wp-stream`.
2. Make sure the standalone `bradvin/wp-ai-client-streaming` package repo is available to Composer.
3. Run `composer install`.
4. Activate `WP Stream`.
5. Make sure WordPress 7 AI support is available in the runtime.

If Composer dependencies are missing, the plugin will not bootstrap and will show an admin notice instead.

## What Lives Here

- wrapper bootstrap in `wp-stream.php`
- admin demo screen in `includes/class-admin-chat-page.php`
- wrapper diagnostics delegation in `includes/class-plugin.php`

## Package Docs

Core-facing architecture notes and the actual streaming integration guidance now live with the package:

- `bradvin/wp-ai-client-streaming/README.md`
- `bradvin/wp-ai-client-streaming/docs/core-review-notes.md`
- `bradvin/wp-ai-client-streaming/docs/integration-guide.md`

The plugin README intentionally stays focused on the wrapper so the package remains the source of truth for reusable runtime behavior.

## Demo UI

After activation, open `Tools > WP Stream Chat`.

That screen exercises the same `wp_ai_client_stream_prompt()` flow external consumers use through the package.

## License

`WP Stream` is licensed under `GPL-2.0-or-later`.
