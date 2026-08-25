<?php
/**
 * Registration
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

use ArrayPress\ProtectedFolders\Delivery;
use ArrayPress\ProtectedFolders\Folder;
use ArrayPress\ProtectedFolders\Folders;

if ( ! function_exists( 'register_protected_folder' ) ) {
	/**
	 * Register a folder under uploads that the web server should refuse.
	 *
	 *     register_protected_folder( 'downloads', [
	 *         'public_extensions' => [ 'jpg', 'png' ],
	 *         'upload_for'        => [ 'product' ],
	 *     ] );
	 *
	 * @param string               $id     Its identifier, which is its directory name.
	 * @param array<string, mixed> $config public_extensions, dated, auto_protect, upload_for.
	 *
	 * @return Folder|null
	 */
	function register_protected_folder( string $id, array $config = [] ): ?Folder {
		return Folders::register( $id, $config );
	}
}

if ( ! function_exists( 'get_protected_folder' ) ) {
	/**
	 * A registered folder.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return Folder|null
	 */
	function get_protected_folder( string $id ): ?Folder {
		return Folders::get( $id );
	}
}

if ( ! function_exists( 'deliver_protected_file' ) ) {
	/**
	 * Send a file out of a protected folder.
	 *
	 *     deliver_protected_file( 'downloads', $order->file_path, [
	 *         'filename' => $product->name . '.zip',
	 *     ] );
	 *
	 * The path is resolved inside the folder, so a value that came from a
	 * request cannot be made to point at wp-config.php. Whether *this person*
	 * may have the file is the caller's question and this does not ask it.
	 *
	 * Exits when it finds the file. Returns false when it does not, so a
	 * caller can say something better than a 404.
	 *
	 * @param string               $folder   The folder's identifier.
	 * @param string               $relative Where the file is inside it.
	 * @param array<string, mixed> $options  filename, mime_type, force_download, ranges.
	 *
	 * @return false
	 */
	function deliver_protected_file( string $folder, string $relative, array $options = [] ): bool {
		$registered = Folders::get( $folder );

		if ( null === $registered ) {
			return false;
		}

		$path = $registered->file( $relative );

		if ( null === $path ) {
			return false;
		}

		Delivery::send( $path, $options );
	}
}

if ( ! function_exists( 'deliver_file_at_path' ) ) {
	/**
	 * Send a file this code already knows the path of.
	 *
	 * Named the long way round on purpose. It does no containment check, so
	 * a path that came from anywhere near a request must go through
	 * `get_protected_folder( … )->file( … )` first — which is what
	 * deliver_protected_file() above does for you.
	 *
	 * @param string               $path    An absolute path this code chose.
	 * @param array<string, mixed> $options filename, mime_type, force_download, ranges.
	 *
	 * @return never
	 */
	function deliver_file_at_path( string $path, array $options = [] ): void {
		Delivery::send( $path, $options );
	}
}

if ( ! function_exists( 'is_folder_protected' ) ) {
	/**
	 * Whether the server actually refuses files in a folder.
	 *
	 * Asked over HTTP against a file written for the purpose, and cached for
	 * an hour. Checking that an .htaccess exists tells you an .htaccess
	 * exists.
	 *
	 * @param string $id    The folder's identifier.
	 * @param bool   $force Ask again rather than using the cached answer.
	 *
	 * @return bool
	 */
	function is_folder_protected( string $id, bool $force = false ): bool {
		return (bool) Folders::get( $id )?->is_protected( $force );
	}
}

if ( ! function_exists( 'get_protected_folder_server_rules' ) ) {
	/**
	 * The configuration a server needs that cannot be configured from PHP.
	 *
	 * nginx has no per-directory file, so an .htaccess achieves nothing
	 * there. This is the text to give whoever administers the server.
	 *
	 * @param string $id The folder's identifier.
	 *
	 * @return string
	 */
	function get_protected_folder_server_rules( string $id ): string {
		return Folders::get( $id )?->rules_for_server() ?? '';
	}
}
