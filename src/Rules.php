<?php
/**
 * Rules
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders;

/**
 * The text a web server needs in order to refuse a directory.
 *
 * Written rather than assembled from the caller's strings. Extensions go into
 * a regular expression inside a server configuration file, and one containing
 * a bracket or a newline used to break the file or add a line to it — which
 * on Apache means the site returns a 500 until somebody finds the .htaccess,
 * and the folder is unprotected until they do.
 */
final class Rules {

	/**
	 * The marker every generated file carries.
	 *
	 * How a file this library wrote is told from one somebody else did, so
	 * a hand-written .htaccess is left alone rather than overwritten.
	 */
	public const MARKER = '# Protected Folders';

	/**
	 * Extensions, reduced to what can safely go in a pattern.
	 *
	 * Letters and digits, which is every real extension. Anything else is
	 * dropped rather than escaped: an extension containing a bracket is a
	 * mistake in the configuration, and quietly making it work would mean
	 * generating a rule nobody can read.
	 *
	 * @param string[] $extensions The extensions.
	 *
	 * @return string[]
	 */
	public static function safe_extensions( array $extensions ): array {
		$safe = [];

		foreach ( $extensions as $extension ) {
			$extension = strtolower( ltrim( trim( (string) $extension ), '.' ) );

			if ( 1 === preg_match( '/^[a-z0-9]{1,10}$/', $extension ) ) {
				$safe[] = $extension;
			}
		}

		return array_values( array_unique( $safe ) );
	}

	/**
	 * An .htaccess for Apache and LiteSpeed.
	 *
	 * Both the 2.4 and the 2.2 form, because a shared host running the older
	 * one silently ignores `Require` and serves the directory.
	 *
	 * @param string   $id         The folder's identifier.
	 * @param string[] $extensions Extensions that stay public.
	 *
	 * @return string
	 */
	public static function htaccess( string $id, array $extensions = [] ): string {
		$extensions = self::safe_extensions( $extensions );
		$pattern    = implode( '|', $extensions );

		$rules  = sprintf( "%s — %s\n", self::MARKER, $id );
		$rules .= "# Generated. Edits are overwritten; filter the rules instead.\n\n";
		$rules .= "Options -Indexes\n\n";

		$rules .= "<IfModule mod_authz_core.c>\n";
		$rules .= "\tRequire all denied\n";

		if ( '' !== $pattern ) {
			$rules .= sprintf( "\t<FilesMatch \"\\.(%s)$\">\n", $pattern );
			$rules .= "\t\tRequire all granted\n";
			$rules .= "\t</FilesMatch>\n";
		}

		$rules .= "</IfModule>\n\n";

		$rules .= "<IfModule !mod_authz_core.c>\n";
		$rules .= "\tOrder Deny,Allow\n";
		$rules .= "\tDeny from all\n";

		if ( '' !== $pattern ) {
			$rules .= sprintf( "\t<FilesMatch \"\\.(%s)$\">\n", $pattern );
			$rules .= "\t\tOrder Allow,Deny\n";
			$rules .= "\t\tAllow from all\n";
			$rules .= "\t</FilesMatch>\n";
		}

		$rules .= "</IfModule>\n";

		/**
		 * Change the .htaccess a folder is given.
		 *
		 * @param string   $rules      The file's contents.
		 * @param string   $id         The folder's identifier.
		 * @param string[] $extensions Extensions that stay public.
		 *
		 * @since 2.0.0
		 */
		return (string) apply_filters( Utils\Runtime::hook( 'htaccess' ), $rules, $id, $extensions );
	}

	/**
	 * What to paste into an nginx configuration.
	 *
	 * nginx has no per-directory file, so this cannot be written — it is
	 * shown to whoever administers the server. Saying so is the whole reason
	 * it exists: a plugin that writes an .htaccess and reports success has
	 * told an nginx site it is protected when it is not.
	 *
	 * @param string   $path       The folder, on disk.
	 * @param string   $url_path   The folder, as a URL path.
	 * @param string[] $extensions Extensions that stay public.
	 *
	 * @return string
	 */
	public static function nginx( string $path, string $url_path, array $extensions = [] ): string {
		$extensions = self::safe_extensions( $extensions );

		$rules  = sprintf( "location ^~ %s {\n", '/' . trim( $url_path, '/' ) . '/' );
		$rules .= "\tdeny all;\n";

		if ( [] !== $extensions ) {
			$rules .= sprintf( "\n\tlocation ~* \\.(%s)$ {\n", implode( '|', $extensions ) );
			$rules .= "\t\tallow all;\n";
			$rules .= "\t}\n";
		}

		$rules .= "}\n\n";

		// The internal location X-Accel-Redirect needs. Without it, telling
		// nginx to serve a file that way produces a 404 and nothing says why.
		$rules .= sprintf( "# Needed only if X-Accel-Redirect delivery is turned on.\n" );
		$rules .= sprintf( "location %s {\n", self::internal_location() );
		$rules .= "\tinternal;\n";
		$rules .= sprintf( "\talias %s;\n", untrailingslashit( $path ) );
		$rules .= "}\n";

		return $rules;
	}

	/**
	 * The internal location nginx serves protected files through.
	 *
	 * @return string
	 */
	public static function internal_location(): string {
		/**
		 * Change the internal nginx location.
		 *
		 * It has to match the `location ... { internal; }` block in the
		 * server's configuration, and there is no way for this library to
		 * find out what that is.
		 *
		 * @param string $location The location, with slashes at both ends.
		 *
		 * @since 2.0.0
		 */
		$location = (string) apply_filters( Utils\Runtime::hook( 'nginx_internal_location' ), '/protected-files/' );

		return '/' . trim( $location, '/' ) . '/';
	}
}
