<?php
/**
 * Delivery
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders;

use ArrayPress\FileUtils\MIME;
use ArrayPress\ProtectedFolders\Utils\Runtime;
use ArrayPress\ServerUtils\Server;

/**
 * Sending a file to somebody who is allowed to have it.
 *
 * The path in here is the local one: read the bytes off disk and write them
 * to the connection, with range requests so a large download can be resumed
 * and a video can be seeked.
 *
 * It is not the only path a store needs. A file that lives in object storage
 * should be redirected to rather than proxied — the signed URL is the whole
 * point of object storage, and streaming it through PHP pays for the bytes
 * twice. So before anything is read, a filter is given the chance to answer
 * with somewhere to send the browser instead:
 *
 *     add_filter( 'protected_folders_redirect_to', function ( $url, $file ) {
 *         return $signer->url_for( $file );
 *     }, 10, 2 );
 *
 * One call site, either delivery. That is the seam, and it is here rather
 * than in the caller because the caller is a download endpoint that has
 * already done the hard part — working out whether this person may have the
 * file — and should not also have to know where it is kept.
 */
final class Delivery {

	/**
	 * How much to read at a time.
	 *
	 * A megabyte. The old version varied it by file type, which sounds like
	 * tuning and is not: the figure that matters is the socket's, and PHP
	 * writing 512KB instead of 1MB into the same buffer changes nothing
	 * anybody can measure.
	 */
	private const CHUNK = 1048576;

	/**
	 * Send a file.
	 *
	 * Exits, because a download's response has no page after it.
	 *
	 * @param string               $path    The file, already confirmed to be
	 *                                      one this person may have.
	 * @param array<string, mixed> $options filename, mime_type, force_download, ranges.
	 *
	 * @return never
	 */
	public static function send( string $path, array $options = [] ): void {
		/**
		 * Send the browser somewhere else instead of reading the file.
		 *
		 * Return a URL — a signed object-storage URL, usually — and the
		 * browser is redirected to it. Return null and the file is read from
		 * disk as normal.
		 *
		 * @param string|null          $url     Where to send them, or null.
		 * @param string               $path    The file.
		 * @param array<string, mixed> $options The delivery options.
		 *
		 * @since 2.0.0
		 */
		$elsewhere = apply_filters( Runtime::hook( 'redirect_to' ), null, $path, $options );

		if ( is_string( $elsewhere ) && '' !== $elsewhere ) {
			// Not wp_safe_redirect(): the whole point is that the file is on
			// somebody else's host, and the allowed-hosts list exists to stop
			// a *user-supplied* URL being followed. This one came from a
			// filter the site's own code registered — if that is untrusted,
			// so is everything else in the request.
			//
			// A 302 rather than a 301: a signed URL expires, and a permanent
			// redirect to one would be cached long after it stopped working.
			wp_redirect( $elsewhere, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- see above.

			exit;
		}

		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			wp_die(
				esc_html__( 'That file is not available.', 'arraypress' ),
				esc_html__( 'Download', 'arraypress' ),
				[ 'response' => 404 ]
			);
		}

		$type = (string) ( $options['mime_type'] ?? MIME::of( $path ) );
		$name = (string) ( $options['filename'] ?? basename( $path ) );

		$download = array_key_exists( 'force_download', $options )
			? (bool) $options['force_download']
			: MIME::must_download( $type );

		// Whatever anybody asked for, a type a browser would execute is
		// never rendered: shown inline it runs in the origin of the site
		// serving it, against that site's cookies.
		if ( MIME::is_dangerous_inline( $type ) ) {
			$download = true;
			$type     = 'application/octet-stream';
		}

		self::prepare();
		self::headers( $name, $type, $download );

		$size  = (int) filesize( $path );
		$range = ( $options['ranges'] ?? true ) ? self::range( $size ) : null;

		if ( false === $range ) {
			// Asked for, and not satisfiable. Said properly rather than
			// answering with the whole file, which is what happened before —
			// a 416 header was sent and then two hundred megabytes followed
			// it.
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}

		if ( self::via_server( $path, $range ) ) {
			exit;
		}

		[ $start, $end ] = $range ?? [ 0, $size - 1 ];

		if ( null !== $range ) {
			status_header( 206 );
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}

		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );

		self::read( $path, $start, $end );

		exit;
	}

	/**
	 * Give the file to the web server to send.
	 *
	 * Apache with mod_xsendfile, LiteSpeed, or nginx once somebody has added
	 * the internal location. The server sends the file itself, which frees
	 * the PHP worker for the length of a download — the difference between a
	 * site that can serve twenty concurrent downloads and one that can serve
	 * as many as it has workers.
	 *
	 * Refused when a range was asked for: the server handles ranges itself
	 * from here, and sending it a range header as well produces a response
	 * with two of them.
	 *
	 * @param string          $path  The file.
	 * @param array{0:int,1:int}|null $range The range, if one was asked for.
	 *
	 * @return bool Whether the server took it.
	 */
	private static function via_server( string $path, ?array $range ): bool {
		if ( ! class_exists( Server::class ) || ! Server::has_xsendfile() ) {
			return false;
		}

		if ( Server::is_nginx() ) {
			$uploads = wp_upload_dir();
			$base    = wp_normalize_path( (string) $uploads['basedir'] );
			$file    = wp_normalize_path( $path );

			// The whole path below the uploads directory, not basename() —
			// which is what this used to send, so every file in a dated
			// folder resolved to nothing and 404'd.
			if ( ! str_starts_with( $file, $base . '/' ) ) {
				return false;
			}

			header( 'X-Accel-Redirect: ' . Rules::internal_location() . ltrim( substr( $file, strlen( $base ) ), '/' ) );

			return true;
		}

		header( 'X-Sendfile: ' . $path );

		return true;
	}

	/**
	 * The headers every delivery sends.
	 *
	 * @param string $filename What to call it.
	 * @param string $type     What it is.
	 * @param bool   $download Whether to save it rather than show it.
	 *
	 * @return void
	 */
	private static function headers( string $filename, string $type, bool $download ): void {
		nocache_headers();

		header( 'X-Robots-Tag: noindex, nofollow', true );

		// Without this a browser may sniff the bytes and decide the file is
		// HTML whatever the Content-Type says, which turns an uploaded file
		// into a script running on the store's own domain.
		header( 'X-Content-Type-Options: nosniff' );

		header( 'Content-Type: ' . $type );
		header( 'Content-Transfer-Encoding: binary' );

		$disposition = $download ? 'attachment' : 'inline';
		$ascii       = sanitize_file_name( $filename );

		// Two filenames, because the quoted one cannot carry anything
		// outside ASCII and a customer who bought "Manuel d'utilisation.pdf"
		// should get that name rather than a mangled one. RFC 6266: a client
		// that understands filename* uses it, and one that does not falls
		// back to the plain one.
		header(
			sprintf(
				'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
				$disposition,
				str_replace( '"', '', $ascii ),
				rawurlencode( $filename )
			)
		);
	}

	/**
	 * What the request asked for, if it asked for part of the file.
	 *
	 * @param int $size The file's size.
	 *
	 * @return array{0:int,1:int}|null|false The range, null for the whole
	 *                                       file, false for one that cannot
	 *                                       be satisfied.
	 */
	private static function range( int $size ): array|null|false {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- matched against a pattern on the next line, which is stricter than any sanitizer.
		$header = isset( $_SERVER['HTTP_RANGE'] ) ? (string) $_SERVER['HTTP_RANGE'] : '';

		if ( '' === $header || 0 === $size ) {
			return null;
		}

		// One range. Several — `bytes=0-99,200-299` — need a multipart
		// response, which nothing asks for in practice and which this does
		// not pretend to do: the whole file is a valid answer to a range
		// request, and half a multipart response is not.
		if ( 1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $found ) ) {
			return null;
		}

		if ( '' === $found[1] && '' === $found[2] ) {
			return null;
		}

		// `bytes=-500` is the last five hundred, not the first.
		if ( '' === $found[1] ) {
			$start = max( 0, $size - (int) $found[2] );
			$end   = $size - 1;
		} else {
			$start = (int) $found[1];
			$end   = '' === $found[2] ? $size - 1 : (int) $found[2];
		}

		$end = min( $end, $size - 1 );

		return $start > $end || $start >= $size ? false : [ $start, $end ];
	}

	/**
	 * Get out of the way of a large response.
	 *
	 * @return void
	 */
	private static function prepare(): void {
		// Anything already buffered would be written before the file and
		// become part of it. A stray newline from a plugin's closing tag has
		// corrupted more downloads than any other single cause.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		// Compressing a file that is already compressed wastes time and,
		// worse, breaks Content-Length — which breaks resuming.
		if ( function_exists( 'apache_setenv' ) ) {
			apache_setenv( 'no-gzip', '1' );
		}

		if ( '' !== (string) ini_get( 'zlib.output_compression' ) ) {
			ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- a compressed download has the wrong Content-Length and cannot be resumed.
		}
	}

	/**
	 * Write part of a file to the connection.
	 *
	 * @param string $path  The file.
	 * @param int    $start First byte.
	 * @param int    $end   Last byte.
	 *
	 * @return void
	 */
	private static function read( string $path, int $start, int $end ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a file written to the connection a megabyte at a time; WP_Filesystem's only read is the whole file into memory, which for a download is the bug this avoids.
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			wp_die(
				esc_html__( 'That file could not be read.', 'arraypress' ),
				esc_html__( 'Download', 'arraypress' ),
				[ 'response' => 500 ]
			);
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $end - $start + 1;

		while ( $remaining > 0 && ! feof( $handle ) ) {
			// The browser went away. Without this the worker reads the rest
			// of a two gigabyte file into a socket nobody is listening to.
			if ( CONNECTION_NORMAL !== connection_status() ) {
				break;
			}

			$buffer = fread( $handle, (int) min( self::CHUNK, $remaining ) );

			if ( false === $buffer || '' === $buffer ) {
				break;
			}

			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the bytes of the file being downloaded.

			$remaining -= strlen( $buffer );

			flush();
		}

		fclose( $handle );
	}
}
