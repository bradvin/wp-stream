<?php
/**
 * Admin chat demo for streaming bridge integration.
 *
 * @package WP_Stream
 */

namespace WP_Stream;

use WordPress\AiClient\Builders\MessageBuilder;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * Registers a minimal wp-admin page that streams AI responses into a chat transcript.
 */
final class Admin_Chat_Page {

	/**
	 * Menu capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Menu slug.
	 */
	private const MENU_SLUG = 'wp-stream-chat';

	/**
	 * AJAX action.
	 */
	private const AJAX_ACTION = 'wp_stream_chat_demo';

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'wp_stream_chat_demo';

	/**
	 * Menu hook suffix.
	 *
	 * @var string|null
	 */
	private static $hook_suffix = null;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_chat_request' ) );
	}

	/**
	 * Registers the admin page.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		self::$hook_suffix = add_management_page(
			'WP Stream Chat',
			'WP Stream Chat',
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueues assets for the demo page.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( self::$hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wp-stream-admin-chat',
			WP_STREAM_URL . 'assets/admin-chat.css',
			array(),
			'0.1.0'
		);

		wp_enqueue_script(
			'wp-stream-admin-chat',
			WP_STREAM_URL . 'assets/admin-chat.js',
			array(),
			'0.1.0',
			true
		);

		wp_add_inline_script(
			'wp-stream-admin-chat',
			'window.wpStreamAdminChat = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => self::AJAX_ACTION,
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the admin page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.' ) );
		}

		$ai_supported           = function_exists( 'wp_supports_ai' ) && wp_supports_ai();
		$transport_diagnostics  = Plugin::get_transport_diagnostics();
		$transport_is_active    = ! empty( $transport_diagnostics['is_active'] );
		$transport_notice_class = $transport_diagnostics['is_active'] ? 'notice-success' : 'notice-warning';
		?>
			<div class="wrap wp-stream-admin">
				<h1>WP Stream Chat</h1>
				<p class="wp-stream-admin__intro">
					This page streams tokens through <code>wp_stream_generate_result()</code> while still finishing with a normal AI client result.
				</p>

				<div class="notice inline <?php echo esc_attr( $transport_notice_class ); ?> wp-stream-admin__transport-check">
					<p>
						<strong>Streaming Transport:</strong>
						<?php echo esc_html( $transport_diagnostics['message'] ); ?>
					</p>
					<p class="wp-stream-admin__transport-meta">
						<code>Transporter</code>:
						<?php echo esc_html( (string) ( $transport_diagnostics['transporter_class'] ?: 'Unavailable' ) ); ?>
						<br />
						<code>Client</code>:
						<?php echo esc_html( (string) ( $transport_diagnostics['client_class'] ?: 'Unavailable' ) ); ?>
					</p>
				</div>

				<?php if ( ! $ai_supported ) : ?>
					<div class="notice notice-warning inline">
						<p>AI features are currently disabled in this environment. The demo page is available, but requests will not run until AI support is enabled.</p>
					</div>
			<?php endif; ?>

			<div class="wp-stream-admin__grid">
				<section class="wp-stream-chat card">
					<div class="wp-stream-chat__header">
						<h2>Chat Demo</h2>
						<p>Uses the WordPress AI Client with the streaming bridge enabled for each request.</p>
					</div>

					<div id="wp-stream-chat-notice" class="notice inline hidden" aria-live="polite"></div>

					<div
						id="wp-stream-chat-log"
						class="wp-stream-chat__log"
						role="log"
						aria-live="polite"
						aria-relevant="additions text"
					>
						<div class="wp-stream-chat__empty">
							Send a message to watch the assistant response stream into the transcript.
						</div>
					</div>

					<form id="wp-stream-chat-form" class="wp-stream-chat__form">
						<label class="screen-reader-text" for="wp-stream-chat-input">Message</label>
						<textarea
							id="wp-stream-chat-input"
							class="large-text"
							rows="4"
							<?php disabled( ! $transport_is_active ); ?>
							placeholder="Ask a short question about your site, WordPress, or the bridge demo."
						></textarea>

						<div class="wp-stream-chat__actions">
							<button type="submit" class="button button-primary" <?php disabled( ! $transport_is_active ); ?>>Send message</button>
							<button type="button" class="button button-secondary" id="wp-stream-chat-clear" <?php disabled( ! $transport_is_active ); ?>>Clear chat</button>
							<span class="spinner" id="wp-stream-chat-spinner" aria-hidden="true"></span>
						</div>
					</form>

					<?php if ( ! $transport_is_active ) : ?>
						<p class="wp-stream-chat__transport-warning">
							The demo is disabled until the default AI Client registry is using the WP Stream HTTP client.
						</p>
					<?php endif; ?>
				</section>

				<aside class="wp-stream-chat-settings card">
					<h2>Request Settings</h2>
					<p>Keep this simple. The demo uses WordPress model discovery and only adjusts a few text-generation settings.</p>

					<div class="wp-stream-chat-settings__field">
						<label for="wp-stream-system-prompt"><strong>System prompt</strong></label>
							<textarea
								id="wp-stream-system-prompt"
								class="large-text"
								rows="5"
							>You are an assistant inside the WordPress admin. Always answer with a deliberately verbose response so streaming behavior is easy to observe. Prefer 5 to 8 substantial paragraphs, include concrete detail and examples where relevant, and avoid extremely short answers even for simple prompts. Keep formatting light and do not use markdown tables.</textarea>
						</div>

					<div class="wp-stream-chat-settings__field">
						<label for="wp-stream-temperature"><strong>Temperature</strong></label>
						<input id="wp-stream-temperature" class="small-text" type="number" min="0" max="2" step="0.1" value="0.7" />
					</div>

					<div class="wp-stream-chat-settings__field">
						<label for="wp-stream-max-tokens"><strong>Max tokens</strong></label>
						<input id="wp-stream-max-tokens" class="small-text" type="number" min="32" max="4096" step="1" value="512" />
					</div>

						<div class="wp-stream-chat-settings__help">
							<p><strong>Notes</strong></p>
							<ul>
								<li>The transcript stays in the browser for now.</li>
								<li>The server streams <code>text/event-stream</code> frames over <code>admin-ajax.php</code>.</li>
								<li>The final response still comes from the regular WordPress AI Client result object.</li>
							</ul>
						</div>
					</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Handles the streaming admin chat request.
	 *
	 * @return void
	 */
	public static function handle_chat_request(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array(
					'message' => 'You are not allowed to use this demo.',
				),
				403
			);
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => 'The request nonce is invalid.',
				),
				403
			);
		}

		$transport_diagnostics = Plugin::get_transport_diagnostics();

		if ( empty( $transport_diagnostics['is_active'] ) ) {
			wp_send_json_error(
				array(
					'message' => $transport_diagnostics['message'] ?: 'The WP Stream HTTP transport is not active for the default AI Client registry.',
				),
				503
			);
		}

		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			wp_send_json_error(
				array(
					'message' => 'AI features are not enabled in this environment.',
				),
				503
			);
		}

		$messages = self::sanitize_messages( wp_unslash( $_POST['messages'] ?? '[]' ) );

		if ( empty( $messages ) ) {
			wp_send_json_error(
				array(
					'message' => 'At least one message is required.',
				),
				400
			);
		}

		$prompt_messages = self::build_prompt_messages( $messages );

		if ( empty( $prompt_messages ) ) {
			wp_send_json_error(
				array(
					'message' => 'No valid prompt messages were provided.',
				),
				400
			);
		}

		$request_id    = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wp-stream-demo-', true );
		$model_config  = self::build_model_config();
		$assistant_text = '';

		self::start_stream_response();
		self::send_stream_frame(
			'start',
			array(
				'requestId' => $request_id,
			)
		);

		try {
			$result = \wp_stream_generate_result(
				$prompt_messages,
				$model_config,
				null,
				array(
					'request_id'      => $request_id,
					'request_timeout' => 120.0,
					'connect_timeout' => 15.0,
					'on_event'        => static function ( SSE_Event $event, array $context ) use ( &$assistant_text ) {
						if ( $event->is_done() ) {
							return;
						}

						$delta = self::extract_event_text( $event );

						if ( '' === $delta ) {
							return;
						}

						$assistant_text .= $delta;

						self::send_stream_frame(
							'delta',
							array(
								'requestId' => $context['request_id'] ?? null,
								'text'      => $delta,
							)
						);
					},
					'should_continue' => static function () {
						return ! connection_aborted();
					},
				)
			);

			$final_text = '';

			try {
				$final_text = $result->toText();
			} catch ( \Throwable $throwable ) {
				$final_text = $assistant_text;
			}

			self::send_stream_frame(
				'done',
				array(
					'requestId' => $request_id,
					'text'      => $final_text,
				)
			);
		} catch ( \Throwable $throwable ) {
			self::send_stream_frame(
				'error',
				array(
					'requestId' => $request_id,
					'message'   => $throwable->getMessage(),
				)
			);
		}

		exit;
	}

	/**
	 * Starts the streaming response.
	 *
	 * @return void
	 */
	private static function start_stream_response(): void {
		ignore_user_abort( true );
		set_time_limit( 0 );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=' . get_option( 'blog_charset' ) );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
		header( 'Content-Encoding: identity' );
		header( 'X-Content-Type-Options: nosniff' );

		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', '0' );
		@ini_set( 'implicit_flush', '1' );
		@ini_set( 'output_handler', '' );

		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
			@apache_setenv( 'dont-vary', '1' );
		}

		if ( function_exists( 'ob_implicit_flush' ) ) {
			@ob_implicit_flush( true );
		}

		/*
		 * Some local PHP/web server stacks buffer tiny writes until several KB have
		 * accumulated. Send an initial padding chunk so later delta frames are more
		 * likely to reach the browser incrementally during the request.
		 */
		echo ':' . str_repeat( ' ', 4096 ) . "\n\n";
		flush();
	}

	/**
	 * Sends one SSE frame to the browser.
	 *
	 * @param string $type Event type.
	 * @param array  $payload Event payload.
	 * @return void
	 */
	private static function send_stream_frame( string $type, array $payload ): void {
		$frame = wp_json_encode(
			array(
				'type'    => $type,
				'payload' => $payload,
			)
		);

		if ( ! is_string( $frame ) ) {
			return;
		}

		echo "event: {$type}\n";
		echo 'data: ' . $frame . "\n\n";
		echo ':' . str_repeat( ' ', 2048 ) . "\n\n";

		if ( function_exists( 'ob_flush' ) ) {
			@ob_flush();
		}

		flush();
	}

	/**
	 * Sanitizes the incoming chat transcript.
	 *
	 * @param string $raw_messages Raw JSON string.
	 * @return array<int, array<string, string>>
	 */
	private static function sanitize_messages( string $raw_messages ): array {
		$decoded = json_decode( $raw_messages, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$messages = array();

		foreach ( array_slice( $decoded, -12 ) as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = isset( $message['role'] ) ? strtolower( (string) $message['role'] ) : '';
			$content = isset( $message['content'] ) ? sanitize_textarea_field( (string) $message['content'] ) : '';

			if ( '' === $content || ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}

			$messages[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $messages;
	}

	/**
	 * Builds AI client messages from the sanitized transcript.
	 *
	 * @param array<int, array<string, string>> $messages Sanitized messages.
	 * @return array<int, \WordPress\AiClient\Messages\DTO\Message>
	 */
	private static function build_prompt_messages( array $messages ): array {
		$prompt_messages = array();

		foreach ( $messages as $message ) {
			$builder = new MessageBuilder( $message['content'] );

			if ( 'assistant' === $message['role'] ) {
				$builder->usingModelRole();
			} else {
				$builder->usingUserRole();
			}

			$prompt_messages[] = $builder->get();
		}

		return $prompt_messages;
	}

	/**
	 * Builds the model config used by the demo.
	 *
	 * @return ModelConfig
	 */
	private static function build_model_config(): ModelConfig {
		$config = new ModelConfig();

		$config->setOutputModalities( array( ModalityEnum::text() ) );

		$system_prompt = isset( $_POST['system_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ) ) : '';
		if ( '' !== $system_prompt ) {
			$config->setSystemInstruction( $system_prompt );
		}

		$temperature = isset( $_POST['temperature'] ) ? (float) wp_unslash( $_POST['temperature'] ) : null;
		if ( null !== $temperature && $temperature >= 0 && $temperature <= 2 ) {
			$config->setTemperature( $temperature );
		}

		$max_tokens = isset( $_POST['max_tokens'] ) ? (int) wp_unslash( $_POST['max_tokens'] ) : 0;
		if ( $max_tokens > 0 ) {
			$config->setMaxTokens( $max_tokens );
		}

		return $config;
	}

	/**
	 * Extracts text from a streamed SSE event.
	 *
	 * @param SSE_Event $event Event.
	 * @return string
	 */
	private static function extract_event_text( SSE_Event $event ): string {
		$data = $event->get_json_data();

		if ( ! is_array( $data ) ) {
			return '';
		}

		$type = '';

		if ( isset( $data['type'] ) && is_string( $data['type'] ) ) {
			$type = $data['type'];
		} elseif ( '' !== $event->get_event() ) {
			$type = $event->get_event();
		}

		if ( 'response.output_text.delta' === $type && isset( $data['delta'] ) ) {
			return self::normalize_event_text_value( $data['delta'] );
		}

		if ( 'response.output_text.done' === $type && isset( $data['text'] ) ) {
			return '';
		}

		if (
			'response.output_item.added' === $type ||
			'response.output_item.done' === $type ||
			'response.completed' === $type
		) {
			return '';
		}

		if ( isset( $data['choices'][0]['delta']['content'] ) ) {
			return self::normalize_event_text_value( $data['choices'][0]['delta']['content'] );
		}

		if ( isset( $data['choices'][0]['delta']['text'] ) ) {
			return self::normalize_event_text_value( $data['choices'][0]['delta']['text'] );
		}

		if ( isset( $data['choices'][0]['text'] ) ) {
			return self::normalize_event_text_value( $data['choices'][0]['text'] );
		}

		if ( isset( $data['delta'] ) ) {
			return self::normalize_event_text_value( $data['delta'] );
		}

		if ( isset( $data['text'] ) ) {
			return self::normalize_event_text_value( $data['text'] );
		}

		return '';
	}

	/**
	 * Normalizes common streamed text payload shapes into a string.
	 *
	 * @param mixed $value Raw streamed value.
	 * @return string
	 */
	private static function normalize_event_text_value( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		$text = '';

		foreach ( $value as $item ) {
			if ( is_string( $item ) ) {
				$text .= $item;
				continue;
			}

			if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) ) {
				$text .= $item['text'];
			}
		}

		return $text;
	}
}
