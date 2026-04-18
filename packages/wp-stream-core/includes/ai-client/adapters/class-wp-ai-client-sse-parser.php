<?php
/**
 * WP AI Client: WP_AI_Client_SSE_Parser class
 *
 * @package WordPress
 * @subpackage AI
 * @since 0.2.0
 */

if ( class_exists( 'WP_AI_Client_SSE_Parser', false ) ) {
	return;
}

/**
 * Incrementally parses server-sent events from a streamed response.
 *
 * @since 0.2.0
 * @internal Intended only to support the streaming HTTP adapter.
 * @access private
 */
class WP_AI_Client_SSE_Parser {

	/**
	 * Buffered data that has not formed a complete event yet.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Pushes a raw chunk into the parser and returns complete events.
	 *
	 * @since 0.2.0
	 *
	 * @param string $chunk Raw chunk data.
	 * @return array<int, WP_AI_Client_SSE_Event>
	 */
	public function push( string $chunk ): array {
		$this->buffer .= $chunk;
		$this->buffer  = str_replace( array( "\r\n", "\r" ), "\n", $this->buffer );

		$events = array();

		while ( false !== strpos( $this->buffer, "\n\n" ) ) {
			list( $block, $remaining ) = explode( "\n\n", $this->buffer, 2 );
			$this->buffer              = $remaining;

			$event = $this->parse_block( $block );

			if ( null !== $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Parses a single SSE event block.
	 *
	 * @since 0.2.0
	 *
	 * @param string $block Event block.
	 * @return WP_AI_Client_SSE_Event|null
	 */
	private function parse_block( string $block ): ?WP_AI_Client_SSE_Event {
		$event      = 'message';
		$id         = '';
		$retry      = null;
		$data_lines = array();

		foreach ( explode( "\n", $block ) as $line ) {
			if ( '' === $line || ':' === substr( $line, 0, 1 ) ) {
				continue;
			}

			if ( false !== strpos( $line, ':' ) ) {
				list( $field, $value ) = explode( ':', $line, 2 );
				if ( ' ' === substr( $value, 0, 1 ) ) {
					$value = substr( $value, 1 );
				}
			} else {
				$field = $line;
				$value = '';
			}

			switch ( $field ) {
				case 'event':
					if ( '' !== $value ) {
						$event = $value;
					}
					break;

				case 'data':
					$data_lines[] = $value;
					break;

				case 'id':
					$id = $value;
					break;

				case 'retry':
					if ( is_numeric( $value ) ) {
						$retry = (int) $value;
					}
					break;
			}
		}

		if ( empty( $data_lines ) && '' === $id && null === $retry ) {
			return null;
		}

		return new WP_AI_Client_SSE_Event( $event, implode( "\n", $data_lines ), $id, $retry );
	}
}
