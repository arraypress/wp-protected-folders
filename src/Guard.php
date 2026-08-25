<?php
/**
 * Guard
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders;

use WP_Error;

/**
 * The files that make a directory refuse to be served, and the test that says
 * whether they worked.
 *
 * The test is the important half. A plugin that writes an .htaccess and
 * reports success has told an nginx site it is protected when it is not —
 * nginx has no per-directory configuration and ignores the file entirely. So
 * this writes a file it knows the contents of, asks the site's own front end
 * for it, and believes the answer.
 */
final class Guard {

	/**
	 * The files written into every protected directory.
	 *
	 * index.php and index.html both, because which one a server treats as a
	 * directory index depends on its configuration, and neither costs
	 * anything.
	 *
	 * @var array<string, string>
	 */
	private const INDEXES = [
		'index.php'  => "<?php\n// Silence is golden.\n",
		'index.html' => '',
	];

	/**
	 * Write a folder's guard files.
	 *
	 * The .htaccess is written whatever the server appears to be. Detecting
	 * Apache from SERVER_SOFTWARE is wrong behind a proxy and wrong on
	 * LiteSpeed in some configurations, and the two outcomes are not
	 * symmetrical: a stray .htaccess on nginx is ignored, and a missing one
	 * on a misdetected Apache is a public folder full of paid downloads.
	 *
	 * @param Folder $folder The folder.
	 * @param bool   $force  Rewrite even if the file looks current.
	 *
	 * @return bool|WP_Error
	 */
	public static function apply( Folder $folder, bool $force = false ): bool|WP_Error {
		if ( ! $folder->create() ) {
			return new WP_Error(
				'no_directory',
				sprintf(
					/* translators: %s: a directory path. */
					__( 'The directory %s could not be created.', 'arraypress' ),
					$folder->path()
				)
			);
		}

		$path = trailingslashit( $folder->path() );

		foreach ( self::INDEXES as $name => $contents ) {
			if ( ! file_exists( $path . $name ) && ! self::write( $path . $name, $contents ) ) {
				return new WP_Error(
					'not_written',
					sprintf(
						/* translators: %s: a file path. */
						__( 'The file %s could not be written.', 'arraypress' ),
						$path . $name
					)
				);
			}
		}

		$htaccess = $path . '.htaccess';
		$rules    = Rules::htaccess( $folder->id(), (array) $folder->get( 'public_extensions', [] ) );

		if ( self::needs_writing( $htaccess, $rules, $force ) && ! self::write( $htaccess, $rules ) ) {
			return new WP_Error(
				'not_written',
				sprintf(
					/* translators: %s: a file path. */
					__( 'The file %s could not be written.', 'arraypress' ),
					$htaccess
				)
			);
		}

		return true;
	}

	/**
	 * Whether the .htaccess wants rewriting.
	 *
	 * A file somebody else wrote is left alone. Overwriting a hand-written
	 * .htaccess would be this library deciding it knows better than the
	 * person administering the server, and it does not.
	 *
	 * @param string $path  The file.
	 * @param string $rules What it should say.
	 * @param bool   $force Rewrite regardless, unless it is somebody else's.
	 *
	 * @return bool
	 */
	private static function needs_writing( string $path, string $rules, bool $force ): bool {
		if ( ! file_exists( $path ) ) {
			return true;
		}

		$existing = (string) file_get_contents( $path );

		if ( ! str_contains( $existing, Rules::MARKER ) ) {
			return false;
		}

		return $force || $existing !== $rules;
	}

	/**
	 * Take a folder's guard files away.
	 *
	 * Only the ones this library wrote — an .htaccess without the marker
	 * belongs to somebody else.
	 *
	 * @param Folder $folder The folder.
	 *
	 * @return bool
	 */
	public static function remove( Folder $folder ): bool {
		$path     = trailingslashit( $folder->path() );
		$htaccess = $path . '.htaccess';

		if ( file_exists( $htaccess ) && str_contains( (string) file_get_contents( $htaccess ), Rules::MARKER ) ) {
			wp_delete_file( $htaccess );
		}

		foreach ( array_keys( self::INDEXES ) as $name ) {
			if ( file_exists( $path . $name ) ) {
				wp_delete_file( $path . $name );
			}
		}

		return true;
	}

	/**
	 * Whether the guard files are present.
	 *
	 * @param Folder $folder The folder.
	 *
	 * @return bool
	 */
	public static function present( Folder $folder ): bool {
		$path = trailingslashit( $folder->path() );

		return file_exists( $path . '.htaccess' ) && file_exists( $path . 'index.php' );
	}

	/**
	 * Ask the site whether a file in the folder can be fetched.
	 *
	 * A file is written with contents nobody could guess, requested over
	 * HTTP, and removed. Anything other than getting those bytes back means
	 * the folder is refusing them, which is what protection means.
	 *
	 * @param Folder $folder The folder.
	 *
	 * @return bool
	 */
	public static function verify( Folder $folder ): bool {
		if ( ! $folder->create() ) {
			return false;
		}

		$name   = 'protection-test-' . wp_generate_password( 12, false ) . '.txt';
		$secret = wp_generate_password( 32, false );
		$path   = trailingslashit( $folder->path() ) . $name;

		if ( ! self::write( $path, $secret ) ) {
			// Nothing could be written, so nothing can be concluded. Said as
			// "not protected" because that is the answer that makes somebody
			// look, and the other one makes them stop.
			return false;
		}

		$response = wp_remote_get(
			trailingslashit( $folder->url() ) . $name,
			[
				'timeout'   => 10,
				'sslverify' => false,
				'headers'   => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		wp_delete_file( $path );

		if ( is_wp_error( $response ) ) {
			// The request failed rather than the file being refused, which
			// is not an answer either way. Local sites with no resolvable
			// hostname land here constantly.
			return false;
		}

		// The bytes coming back is the only thing that means "not protected".
		// A 403, a 404, a redirect to a login page and a WAF's block page are
		// all the folder doing its job.
		return trim( (string) wp_remote_retrieve_body( $response ) ) !== $secret;
	}

	/**
	 * Write a file.
	 *
	 * @param string $path     Where.
	 * @param string $contents What.
	 *
	 * @return bool
	 */
	private static function write( string $path, string $contents ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem needs credentials, and this runs from admin_init where there is nobody to ask for them; the paths are the library's own and the contents are generated.
		return false !== file_put_contents( $path, $contents );
	}
}
