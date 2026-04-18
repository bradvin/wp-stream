<?php
/**
 * Development autoloader for the WP Stream core package.
 *
 * @package WP_Stream
 */

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WP_Stream\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', '/', $relative_class ) . '.php';
		$file           = __DIR__ . '/src/' . $relative_path;

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
