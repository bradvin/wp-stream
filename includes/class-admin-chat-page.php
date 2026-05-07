<?php
/**
 * Admin chat demo for streaming bridge integration.
 *
 * @package WP_Stream
 */

namespace WP_Stream;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\MessageBuilder;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

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
	 * Default max token value used by the demo form.
	 */
	private const DEFAULT_MAX_TOKENS = 512;

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
			__( 'WP Stream Chat', 'wp-stream' ),
			__( 'WP Stream Chat', 'wp-stream' ),
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
			Plugin::get_asset_url( 'assets/admin-chat.css' ),
			array(),
			Plugin::get_version()
		);

		wp_enqueue_script(
			'wp-stream-admin-chat',
			Plugin::get_asset_url( 'assets/admin-chat.js' ),
			array(),
			Plugin::get_version(),
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
			wp_die( esc_html__( 'You are not allowed to view this page.', 'wp-stream' ) );
		}

		$ai_supported             = function_exists( 'wp_supports_ai' ) && wp_supports_ai();
		$transport_diagnostics    = Plugin::get_transport_diagnostics();
		$transport_is_active      = ! empty( $transport_diagnostics['is_active'] );
		$transport_notice_class   = $transport_is_active ? 'notice-success' : 'notice-warning';
		$transport_status_message = $transport_is_active ? __( '✅ Streaming is available.', 'wp-stream' ) : __( '❌ Streaming is unavailable.', 'wp-stream' );
		$default_system_prompt    = self::get_default_system_prompt();
		$loaded_model             = $ai_supported
			? self::get_loaded_model_details(
				self::build_default_model_config(),
				self::build_model_preview_messages()
			)
			: self::get_unavailable_model_details( __( 'AI support is disabled.', 'wp-stream' ) );
		$loaded_model_class       = empty( $loaded_model['isAvailable'] ) ? 'is-unavailable' : '';
		?>
		<div class="wrap wp-stream-admin">
			<h1><?php esc_html_e( 'WP Stream Chat', 'wp-stream' ); ?></h1>

			<div class="notice inline <?php echo esc_attr( $transport_notice_class ); ?> wp-stream-admin__transport-check">
				<p><?php echo esc_html( $transport_status_message ); ?></p>
			</div>

			<?php if ( ! $ai_supported ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'AI support is disabled.', 'wp-stream' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="wp-stream-admin__grid">
				<section class="wp-stream-chat card">
					<div class="wp-stream-chat__header">
						<div class="wp-stream-chat__heading">
							<h2><?php esc_html_e( 'Chat', 'wp-stream' ); ?></h2>
							<p class="wp-stream-chat__model">
								<span><?php esc_html_e( 'Loaded model:', 'wp-stream' ); ?></span>
								<code
									id="wp-stream-chat-model"
									class="<?php echo esc_attr( $loaded_model_class ); ?>"
								><?php echo esc_html( $loaded_model['label'] ); ?></code>
							</p>
						</div>
						<label class="wp-stream-chat__toggle" for="wp-stream-enable-streaming">
							<input id="wp-stream-enable-streaming" type="checkbox" <?php echo checked( true, true, false ); ?> />
							<span><?php esc_html_e( 'Enable streaming', 'wp-stream' ); ?></span>
						</label>
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
							<?php esc_html_e( 'No messages yet.', 'wp-stream' ); ?>
						</div>
					</div>

					<form id="wp-stream-chat-form" class="wp-stream-chat__form">
						<label class="screen-reader-text" for="wp-stream-chat-input"><?php esc_html_e( 'Message', 'wp-stream' ); ?></label>
						<textarea
							id="wp-stream-chat-input"
							class="large-text"
							rows="4"
							placeholder="<?php echo esc_attr__( 'Ask something', 'wp-stream' ); ?>"
						></textarea>

						<textarea id="wp-stream-system-prompt" hidden><?php echo esc_textarea( $default_system_prompt ); ?></textarea>
						<input id="wp-stream-max-tokens" type="hidden" value="<?php echo esc_attr( (string) self::DEFAULT_MAX_TOKENS ); ?>" />

						<div class="wp-stream-chat__actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Send message', 'wp-stream' ); ?></button>
							<button type="button" class="button button-secondary" id="wp-stream-chat-clear"><?php esc_html_e( 'Clear chat', 'wp-stream' ); ?></button>
							<span class="spinner" id="wp-stream-chat-spinner" aria-hidden="true"></span>
						</div>
					</form>
				</section>
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
					'message' => __( 'You are not allowed to use this demo.', 'wp-stream' ),
				),
				403
			);
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The request nonce is invalid.', 'wp-stream' ),
				),
				403
			);
		}

		$streaming_enabled     = ! isset( $_POST['streaming_enabled'] ) || filter_var( wp_unslash( $_POST['streaming_enabled'] ), FILTER_VALIDATE_BOOLEAN );
		$transport_diagnostics = Plugin::get_transport_diagnostics();

		if ( $streaming_enabled && empty( $transport_diagnostics['is_active'] ) ) {
			wp_send_json_error(
				array(
					'message' => $transport_diagnostics['message'] ?: __( 'The streaming HTTP adapter is not active for the default AI Client registry.', 'wp-stream' ),
				),
				503
			);
		}

		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			wp_send_json_error(
				array(
					'message' => __( 'AI features are not enabled in this environment.', 'wp-stream' ),
				),
				503
			);
		}

		$messages = self::sanitize_messages( wp_unslash( $_POST['messages'] ?? '[]' ) );

		if ( empty( $messages ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'At least one message is required.', 'wp-stream' ),
				),
				400
			);
		}

		$prompt_messages = self::build_prompt_messages( $messages );

		if ( empty( $prompt_messages ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No valid prompt messages were provided.', 'wp-stream' ),
				),
				400
			);
		}

		$request_id     = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'wp-stream-demo-', true );
		$model_config   = self::build_model_config();
		$loaded_model   = self::get_loaded_model_details( $model_config, $prompt_messages );
		$assistant_text = '';

		self::start_stream_response();
		self::send_stream_frame(
			'start',
			array(
				'requestId' => $request_id,
				'streaming' => $streaming_enabled,
				'model'     => $loaded_model,
			)
		);

		try {
			$result = wp_ai_client_stream_prompt(
				$prompt_messages,
				array(
					'request_id'        => $request_id,
					'request_timeout'   => 120.0,
					'connect_timeout'   => 15.0,
					'streaming_enabled' => $streaming_enabled,
					'on_event'          => static function ( \WP_AI_Client_SSE_Event $event, array $context ) use ( &$assistant_text ) {
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
			)
				->using_model_config( $model_config )
				->generate_result();

			if ( is_wp_error( $result ) ) {
				self::send_stream_frame(
					'error',
					array(
						'requestId' => $request_id,
						'message'   => $result->get_error_message(),
					)
				);

				exit;
			}

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
					'model'     => self::get_result_model_details( $result ),
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
	 * Returns the default system prompt used by the demo UI.
	 *
	 * @return string
	 */
	private static function get_default_system_prompt(): string {
		return __(
			'You are an assistant inside the WordPress admin. Always answer with a deliberately verbose response so streaming behavior is easy to observe. Prefer 5 to 8 substantial paragraphs, include concrete detail and examples where relevant, and avoid extremely short answers even for simple prompts. Keep formatting light and do not use markdown tables.',
			'wp-stream'
		);
	}

	/**
	 * Sends one SSE frame to the browser.
	 *
	 * @param string               $type    Event type.
	 * @param array<string, mixed> $payload Event payload.
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
		$system_prompt = isset( $_POST['system_prompt'] )
			? sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ) )
			: self::get_default_system_prompt();
		$max_tokens    = isset( $_POST['max_tokens'] )
			? (int) wp_unslash( $_POST['max_tokens'] )
			: self::DEFAULT_MAX_TOKENS;

		return self::build_model_config_from_values( $system_prompt, $max_tokens );
	}

	/**
	 * Builds the default model config used before a chat request is submitted.
	 *
	 * @return ModelConfig
	 */
	private static function build_default_model_config(): ModelConfig {
		return self::build_model_config_from_values(
			self::get_default_system_prompt(),
			self::DEFAULT_MAX_TOKENS
		);
	}

	/**
	 * Builds the model config from sanitized values.
	 *
	 * @param string $system_prompt System prompt.
	 * @param int    $max_tokens    Maximum tokens.
	 * @return ModelConfig
	 */
	private static function build_model_config_from_values(
		string $system_prompt,
		int $max_tokens
	): ModelConfig {
		$config = new ModelConfig();

		$config->setOutputModalities( array( ModalityEnum::text() ) );

		if ( '' !== $system_prompt ) {
			$config->setSystemInstruction( $system_prompt );
		}

		if ( $max_tokens > 0 ) {
			$config->setMaxTokens( $max_tokens );
		}

		return $config;
	}

	/**
	 * Builds a text-only preview prompt so model discovery matches the chat request.
	 *
	 * @return array<int, \WordPress\AiClient\Messages\DTO\Message>
	 */
	private static function build_model_preview_messages(): array {
		$builder = new MessageBuilder( __( 'Preview the loaded chat model.', 'wp-stream' ) );
		$builder->usingUserRole();

		return array( $builder->get() );
	}

	/**
	 * Resolves the model the chat will use for text generation.
	 *
	 * @param ModelConfig                                          $model_config    Model config.
	 * @param array<int, \WordPress\AiClient\Messages\DTO\Message> $prompt_messages Prompt messages.
	 * @return array<string, mixed>
	 */
	private static function get_loaded_model_details( ModelConfig $model_config, array $prompt_messages ): array {
		if (
			! class_exists( AiClient::class ) ||
			! class_exists( ModelRequirements::class ) ||
			! class_exists( CapabilityEnum::class )
		) {
			return self::get_unavailable_model_details( __( 'WordPress AI Client is unavailable.', 'wp-stream' ) );
		}

		try {
			$requirements             = ModelRequirements::fromPromptData(
				CapabilityEnum::textGeneration(),
				$prompt_messages,
				$model_config
			);
			$provider_models_metadata = AiClient::defaultRegistry()->findModelsMetadataForSupport( $requirements );

			foreach ( $provider_models_metadata as $provider_models ) {
				$models = $provider_models->getModels();

				if ( empty( $models ) ) {
					continue;
				}

				$model = reset( $models );

				if ( $model instanceof ModelMetadata ) {
					return self::format_model_details( $provider_models->getProvider(), $model );
				}
			}
		} catch ( \Throwable $throwable ) {
			return self::get_unavailable_model_details( $throwable->getMessage() );
		}

		return self::get_unavailable_model_details( __( 'No text generation model is currently loaded.', 'wp-stream' ) );
	}

	/**
	 * Gets model details from the generated result.
	 *
	 * @param GenerativeAiResult $result Generated result.
	 * @return array<string, mixed>
	 */
	private static function get_result_model_details( GenerativeAiResult $result ): array {
		return self::format_model_details( $result->getProviderMetadata(), $result->getModelMetadata() );
	}

	/**
	 * Formats provider and model metadata for the chat UI.
	 *
	 * @param ProviderMetadata $provider Provider metadata.
	 * @param ModelMetadata    $model    Model metadata.
	 * @return array<string, mixed>
	 */
	private static function format_model_details( ProviderMetadata $provider, ModelMetadata $model ): array {
		$provider_name = $provider->getName();
		$provider_id   = $provider->getId();
		$model_name    = $model->getName();
		$model_id      = $model->getId();

		return array(
			'isAvailable'  => true,
			'providerId'   => $provider_id,
			'providerName' => $provider_name,
			'id'           => $model_id,
			'name'         => $model_name,
			'label'        => sprintf(
				/* translators: 1: AI provider name. 2: AI model name. */
				__( '%1$s / %2$s', 'wp-stream' ),
				$provider_name ?: $provider_id,
				$model_name ?: $model_id
			),
		);
	}

	/**
	 * Formats an unavailable model state for the chat UI.
	 *
	 * @param string $message Status message.
	 * @return array<string, mixed>
	 */
	private static function get_unavailable_model_details( string $message ): array {
		return array(
			'isAvailable' => false,
			'message'     => $message,
			'label'       => $message,
		);
	}

	/**
	 * Extracts text from a streamed SSE event.
	 *
	 * @param \WP_AI_Client_SSE_Event $event Event.
	 * @return string
	 */
	private static function extract_event_text( \WP_AI_Client_SSE_Event $event ): string {
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
