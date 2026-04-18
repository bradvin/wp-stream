<?php
/**
 * WP AI Client: WP_AI_Client_SSE_Event class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( class_exists( 'WP_AI_Client_SSE_Event', false ) ) {
	return;
}

/**
 * Value object representing a parsed server-sent event.
 *
 * @since 0.2.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_SSE_Event {

	/**
	 * Event name.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $event;

	/**
	 * Event payload.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $data;

	/**
	 * Event identifier.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $id;

	/**
	 * Retry timeout in milliseconds.
	 *
	 * @since 0.2.0
	 * @var int|null
	 */
	private ?int $retry;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $event Event name.
	 * @param string   $data  Event payload.
	 * @param string   $id    Event identifier.
	 * @param int|null $retry Retry timeout in milliseconds.
	 */
	public function __construct( string $event, string $data, string $id = '', ?int $retry = null ) {
		$this->event = $event;
		$this->data  = $data;
		$this->id    = $id;
		$this->retry = $retry;
	}

	/**
	 * Gets the event name.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function get_event(): string {
		return $this->event;
	}

	/**
	 * Gets the event payload.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function get_data(): string {
		return $this->data;
	}

	/**
	 * Gets the event identifier.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Gets the retry timeout.
	 *
	 * @since 0.2.0
	 *
	 * @return int|null
	 */
	public function get_retry(): ?int {
		return $this->retry;
	}

	/**
	 * Returns whether the event is a terminal [DONE] marker.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return '[DONE]' === $this->data;
	}

	/**
	 * Returns JSON-decoded event data when available.
	 *
	 * @since 0.2.0
	 *
	 * @return mixed
	 */
	public function get_json_data() {
		$decoded = json_decode( $this->data, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Converts the event to an array.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, int|string|null>
	 */
	public function to_array(): array {
		return array(
			'event' => $this->event,
			'data'  => $this->data,
			'id'    => $this->id,
			'retry' => $this->retry,
		);
	}
}
