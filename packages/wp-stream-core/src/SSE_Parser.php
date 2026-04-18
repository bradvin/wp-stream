<?php
/**
 * SSE parser.
 *
 * @package WP_Stream
 */

namespace WP_Stream;

/**
 * Incrementally parses server-sent events from streaming chunks.
 */
final class SSE_Parser {

	/**
	 * Buffered data that has not formed a complete event yet.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Pushes a chunk into the parser and returns all complete events.
	 *
	 * @param string $chunk Raw chunk data.
	 * @return array<int, SSE_Event>
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
	 * Parses a single SSE block.
	 *
	 * @param string $block Event block.
	 * @return SSE_Event|null
	 */
	private function parse_block( string $block ): ?SSE_Event {
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

		return new SSE_Event( $event, implode( "\n", $data_lines ), $id, $retry );
	}
}
