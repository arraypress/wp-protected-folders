<?php
/**
 * Folder
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders;

use ArrayPress\FileUtils\Path;
use ArrayPress\ProtectedFolders\Utils\Runtime;
use WP_Error;

/**
 * A directory under uploads that the web server is told to refuse.
 *
 * Two things it does that are worth stating.
 *
 * **It resolves a file rather than trusting one.** `file( $relative )` puts
 * the candidate through realpath and confirms it is inside this folder, so
 * the ordinary way to serve a download cannot be made to serve wp-config.php.
 * The unsafe call has to be written out in full and says so.
 *
 * **It does not claim to have protected an nginx site.** There is no
 * per-directory configuration file on nginx, so writing an .htaccess there
 * achieves nothing — and reporting success is worse than reporting nothing,
 * because somebody stops looking. `rules_for_server()` hands back the text to
 * paste in, and `is_protected()` asks the site over HTTP rather than checking
 * that a file exists.
 */
final class Folder {

	/**
	 * Its identifier, which is also its directory name.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Its configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Construct.
	 *
	 * @param string               $id     Its identifier.
	 * @param array<string, mixed> $config Its configuration.
	 */
	public function __construct( string $id, array $config = [] ) {
		// sanitize_key(), so a folder called '../../..' is a folder called
		// nothing rather than the site root.
		$this->id     = sanitize_key( $id );
		$this->config = array_merge(
			[
				// Extensions the server keeps serving directly. A store that
				// sells images wants the preview public and the full-size
				// file behind the download endpoint.
				'public_extensions' => [],

				// Organise uploads into year/month, as WordPress does.
				'dated'             => true,

				// Write the guard files on admin_init.
				'auto_protect'      => true,

				// Post types whose uploads land here.
				'upload_for'        => [],
			],
			$config
		);
	}

	/**
	 * Its identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * One configuration value.
	 *
	 * @param string $key      The key.
	 * @param mixed  $fallback Returned when it is not set.
	 *
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->config[ $key ] ?? $fallback;
	}

	/**
	 * Where it is on disk.
	 *
	 * @param bool $dated Whether to include this month's subdirectory.
	 *
	 * @return string
	 */
	public function path( bool $dated = false ): string {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( (string) $uploads['basedir'] ) . $this->id;

		return $dated ? $path . '/' . $this->period() : $path;
	}

	/**
	 * Where it is as a URL.
	 *
	 * Not a URL anything can be fetched from — that is the point of the
	 * folder — but the base a server rule is written against.
	 *
	 * @param bool $dated Whether to include this month's subdirectory.
	 *
	 * @return string
	 */
	public function url( bool $dated = false ): string {
		$uploads = wp_upload_dir();
		$url     = trailingslashit( (string) $uploads['baseurl'] ) . $this->id;

		return $dated ? $url . '/' . $this->period() : $url;
	}

	/**
	 * This month, as WordPress writes it.
	 *
	 * @return string
	 */
	private function period(): string {
		return (string) current_time( 'Y/m' );
	}

	/**
	 * Resolve a file inside this folder.
	 *
	 * The only way anything should be turning a request into a path. The
	 * candidate goes through realpath and is confirmed to be inside here,
	 * so `../../../wp-config.php`, a symlink out, a null byte and `phar://`
	 * all come back as null rather than as a file.
	 *
	 * @param string $relative Where the file is, relative to this folder.
	 *
	 * @return string|null The resolved path, or null when it is not in here.
	 */
	public function file( string $relative ): ?string {
		return Path::within( $this->path(), $relative );
	}

	/**
	 * Make the directory, if it is not there.
	 *
	 * @param bool $dated Whether to make this month's subdirectory too.
	 *
	 * @return bool
	 */
	public function create( bool $dated = false ): bool {
		$path = $this->path( $dated && (bool) $this->config['dated'] );

		return is_dir( $path ) || wp_mkdir_p( $path );
	}

	/**
	 * Write the guard files.
	 *
	 * @param bool $force Rewrite them even if they look current.
	 *
	 * @return bool|WP_Error True when they are there, an error saying why not.
	 */
	public function protect( bool $force = false ): bool|WP_Error {
		return Guard::apply( $this, $force );
	}

	/**
	 * Take them away again. For uninstalling.
	 *
	 * @return bool
	 */
	public function unprotect(): bool {
		return Guard::remove( $this );
	}

	/**
	 * Whether the guard files are there.
	 *
	 * Which is not the same as whether the folder is protected — see
	 * is_protected(), which asks the site.
	 *
	 * @return bool
	 */
	public function has_guard_files(): bool {
		return Guard::present( $this );
	}

	/**
	 * Whether the server actually refuses a file in here.
	 *
	 * Asked over HTTP, against a file written for the purpose, because that
	 * is the only question that matters and the only one with a true answer.
	 * Checking that an .htaccess exists tells you an .htaccess exists.
	 *
	 * The answer is cached: it is a request to the site's own front end, and
	 * making one on every admin page load would be its own problem.
	 *
	 * @param bool $force Ask again rather than using the cached answer.
	 *
	 * @return bool
	 */
	public function is_protected( bool $force = false ): bool {
		$key = Runtime::key( 'protected_' . $this->id );

		if ( ! $force ) {
			$cached = get_transient( $key );

			if ( false !== $cached ) {
				return '1' === $cached;
			}
		}

		$protected = Guard::verify( $this );

		set_transient( $key, $protected ? '1' : '0', HOUR_IN_SECONDS );

		return $protected;
	}

	/**
	 * The server configuration this folder needs, for a server that cannot
	 * be configured from PHP.
	 *
	 * @return string
	 */
	public function rules_for_server(): string {
		return Rules::nginx(
			$this->path(),
			(string) wp_parse_url( $this->url(), PHP_URL_PATH ),
			(array) $this->config['public_extensions']
		);
	}

	/**
	 * Send uploads for the configured post types in here.
	 *
	 * @param array<string, string> $uploads What WordPress was going to do.
	 *
	 * @return array<string, string>
	 */
	public function filter_upload_dir( array $uploads ): array {
		if ( ! $this->wants_this_upload() ) {
			return $uploads;
		}

		$subdir = '/' . $this->id . ( (bool) $this->config['dated'] ? '/' . $this->period() : '' );

		$uploads['subdir'] = $subdir;
		$uploads['path']   = (string) $uploads['basedir'] . $subdir;
		$uploads['url']    = (string) $uploads['baseurl'] . $subdir;

		return $uploads;
	}

	/**
	 * Whether the upload happening now belongs in here.
	 *
	 * @return bool
	 */
	private function wants_this_upload(): bool {
		$wanted = $this->config['upload_for'];

		if ( is_callable( $wanted ) ) {
			return (bool) call_user_func( $wanted );
		}

		$wanted = array_filter( (array) $wanted );

		if ( [] === $wanted ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to an integer on this line, which is stricter than any sanitizer; core verified the upload before this filter runs.
		$post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;

		return 0 !== $post_id && in_array( get_post_type( $post_id ), $wanted, true );
	}
}
