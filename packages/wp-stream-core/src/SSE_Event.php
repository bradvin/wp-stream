<?php
/**
 * SSE event value object.
 *
 * @package WP_Stream
 */

namespace WP_Stream;

/**
 * Represents a single parsed server-sent event.
 */
final class SSE_Event {

	/**
	 * Event name.
	 *
	 * @var string
	 */
	private $event;

	/**
	 * Event payload.
	 *
	 * @var string
	 */
	private $data;

	/**
	 * Event ID.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Retry timeout in milliseconds.
	 *
	 * @var int|null
	 */
	private $retry;

	/**
	 * Constructor.
	 *
	 * @param string   $event Event name.
	 * @param string   $data  Event data.
	 * @param string   $id    Event identifier.
	 * @param int|null $retry Event retry value.
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
	 * @return string
	 */
	public function get_event(): string {
		return $this->event;
	}

	/**
	 * Gets the event payload.
	 *
	 * @return string
	 */
	public function get_data(): string {
		return $this->data;
	}

	/**
	 * Gets the event identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Gets the retry value.
	 *
	 * @return int|null
	 */
	public function get_retry(): ?int {
		return $this->retry;
	}

	/**
	 * Whether this event is the OpenAI-style end marker.
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return '[DONE]' === $this->data;
	}

	/**
	 * Returns JSON-decoded data if the payload is valid JSON.
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
