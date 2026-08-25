<?php
/**
 * WordPress stubs.
 *
 * @package ArrayPress\ProtectedFolders
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/pf-site/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function pf_reset_globals(): void {
	$GLOBALS['pf_transients'] = [];
	$GLOBALS['pf_hooks']      = [];
	$GLOBALS['pf_filters']    = [];
	$GLOBALS['pf_http']       = null;
	$GLOBALS['pf_uploads']    = sys_get_temp_dir() . '/pf-uploads';
	$GLOBALS['pf_post_type']  = '';

	$_SERVER = array_diff_key( $_SERVER, [ 'HTTP_RANGE' => 1 ] );
	$_REQUEST = [];

	if ( class_exists( 'ArrayPress\\ProtectedFolders\\Folders' ) ) {
		ArrayPress\ProtectedFolders\Folders::forget();
	}
}

pf_reset_globals();

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		$filename = str_replace( [ "\0", '/', '\\' ], '', (string) $filename );
		$filename = preg_replace( '/[^A-Za-z0-9 _.-]/', '', $filename ) ?? '';

		return trim( preg_replace( '/[\s-]+/', '-', $filename ) ?? '', '.-' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		return preg_replace( '|(?<=.)/+|', '/', $path ) ?? $path;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return [
			'basedir' => $GLOBALS['pf_uploads'],
			'baseurl' => 'https://example.test/wp-content/uploads',
		];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $path ) {
		return is_dir( $path ) || mkdir( $path, 0o777, true );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $path ) {
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return 'timestamp' === $type ? time() : gmdate( (string) $type );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( bin2hex( random_bytes( (int) $length ) ), 0, (int) $length );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, (int) $component );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['pf_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiry = 0 ) {
		$GLOBALS['pf_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['pf_hooks'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['pf_filters'][ $hook ][] = $callback;
		$GLOBALS['pf_hooks'][ $hook ][]   = $callback;

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['pf_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = [] ) {
		$answer = $GLOBALS['pf_http'];

		return is_callable( $answer ) ? $answer( $url ) : ( $answer ?? new WP_Error( 'no_answer', 'nothing configured' ) );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		return $GLOBALS['pf_post_type'] ?: false;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * The parts of WP_Error this library uses.
	 */
	class WP_Error {

		/**
		 * Build one.
		 *
		 * @param string $code    Its code.
		 * @param string $message Its message.
		 * @param mixed  $data    Anything else.
		 */
		public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}

		/**
		 * Its code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Its message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
