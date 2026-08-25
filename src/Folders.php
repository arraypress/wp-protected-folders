<?php
/**
 * Folders
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders;

/**
 * The registered folders, and the hooks they share.
 */
final class Folders {

	/**
	 * The folders, by identifier.
	 *
	 * @var array<string, Folder>
	 */
	private static array $folders = [];

	/**
	 * Whether the shared hooks are attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Register a folder.
	 *
	 * @param string               $id     Its identifier, which is its directory name.
	 * @param array<string, mixed> $config Its configuration.
	 *
	 * @return Folder|null Null when the identifier is not one.
	 */
	public static function register( string $id, array $config = [] ): ?Folder {
		$folder = new Folder( $id, $config );

		if ( '' === $folder->id() ) {
			return null;
		}

		self::hook();

		self::$folders[ $folder->id() ] = $folder;

		if ( [] !== (array) $folder->get( 'upload_for', [] ) ) {
			add_filter( 'upload_dir', [ $folder, 'filter_upload_dir' ] );
		}

		return $folder;
	}

	/**
	 * Attach the hooks every folder shares, once.
	 *
	 * @return void
	 */
	private static function hook(): void {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		add_action( 'admin_init', [ __CLASS__, 'protect_all' ] );
	}

	/**
	 * Write the guard files for every folder that asked.
	 *
	 * @return void
	 */
	public static function protect_all(): void {
		foreach ( self::$folders as $folder ) {
			if ( $folder->get( 'auto_protect', true ) ) {
				$folder->protect();
			}
		}
	}

	/**
	 * A registered folder.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return Folder|null
	 */
	public static function get( string $id ): ?Folder {
		return self::$folders[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Every registered folder.
	 *
	 * @return array<string, Folder>
	 */
	public static function all(): array {
		return self::$folders;
	}

	/**
	 * Forget one, or all of them.
	 *
	 * @param string $id Its identifier, or empty for all.
	 *
	 * @return void
	 */
	public static function forget( string $id = '' ): void {
		if ( '' === $id ) {
			self::$folders = [];

			return;
		}

		unset( self::$folders[ sanitize_key( $id ) ] );
	}
}
