<?php
/**
 * WordPress Protected Folders
 *
 * Simple file and directory protection for WordPress plugins.
 * Handles .htaccess rules, index files, and protection verification.
 *
 * @package     ArrayPress\ProtectedFolders
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders;

use ArrayPress\ServerUtils\Server;
use ArrayPress\ServerUtils\Environment;

/**
 * Protector Class
 *
 * Manages file and directory protection for WordPress uploads.
 */
class Protector {

	/**
	 * Plugin prefix for the upload directory.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Allowed file extensions for public access.
	 *
	 * @var array
	 */
	private array $allowed_extensions = [];

	/**
	 * Whether to use dated folders (year/month).
	 *
	 * @var bool
	 */
	private bool $use_dated_folders = true;

	/**
	 * Configuration array
	 *
	 * @var array
	 */
	private array $config = [];

	/**
	 * Constructor.
	 *
	 * @param string $prefix             Plugin prefix for upload directory.
	 * @param array  $allowed_extensions File extensions allowed for public access.
	 * @param bool   $use_dated_folders  Whether to organize by year/month.
	 */
	public function __construct(
		string $prefix,
		array $allowed_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ],
		bool $use_dated_folders = true
	) {
		$this->prefix             = $prefix;
		$this->allowed_extensions = $allowed_extensions;
		$this->use_dated_folders  = $use_dated_folders;
	}

	/**
	 * Set configuration
	 *
	 * Stores configuration for later use by registry.
	 *
	 * @param array $config Configuration array
	 *
	 * @return void
	 */
	public function set_config( array $config ): void {
		$this->config = $config;
	}

	/**
	 * Get configuration
	 *
	 * Returns stored configuration array.
	 *
	 * @return array Configuration array
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * Create all protection files in the upload directory.
	 *
	 * @param bool $force Force creation even if recently checked.
	 *
	 * @return bool True if successful.
	 */
	public function protect( bool $force = false ): bool {
		$transient_key = $this->prefix . '_protection_check';

		// Check if we need to verify/update existing protection
		if ( ! $force ) {
			// Check if protection files need updating
			if ( $this->needs_protection_update() ) {
				$force = true; // Force update if files are incomplete
			} elseif ( get_transient( $transient_key ) ) {
				return true; // Files are good and recently checked
			}
		}

		$upload_path = $this->get_upload_path();

		// Create directory if it doesn't exist
		wp_mkdir_p( $upload_path );

		// Now check if writable
		if ( ! wp_is_writable( $upload_path ) ) {
			return false;
		}

		// Create protection files with force parameter
		$created = $this->create_protection_files( $upload_path, $force );

		// Protect subdirectories if they exist
		if ( $this->use_dated_folders ) {
			$this->protect_subdirectories( $upload_path );
		}

		if ( $created ) {
			set_transient( $transient_key, true, DAY_IN_SECONDS );
		}

		return $created;
	}

	/**
	 * Check if protection files need updating.
	 *
	 * @return bool True if files need updating.
	 */
	private function needs_protection_update(): bool {
		$upload_path   = $this->get_upload_path();
		$htaccess_path = trailingslashit( $upload_path ) . '.htaccess';

		// No .htaccess file exists
		if ( ! file_exists( $htaccess_path ) ) {
			return true;
		}

		// Check if the file has proper content
		$content = file_get_contents( $htaccess_path );

		// File is suspiciously small or missing our marker
		if ( strlen( $content ) < 100 || ! str_contains( $content, 'Protected Folders Rules' ) ) {
			return true;
		}

		// For Apache servers, check if it has the module checks
		if ( Server::is_apache() && ! str_contains( $content, 'IfModule' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Create protection files in a directory.
	 *
	 * @param string $path  Directory path.
	 * @param bool   $force Force overwrite of .htaccess even if it exists.
	 *
	 * @return bool True if all files created successfully.
	 */
	private function create_protection_files( string $path, bool $force = false ): bool {
		$files = [
			'index.php'  => '<?php // Silence is golden.',
			'index.html' => '',
		];

		// Only add .htaccess for Apache/LiteSpeed servers
		if ( Server::is_apache() || Server::is_litespeed() ) {
			$files['.htaccess'] = $this->get_htaccess_rules();
		}

		$success = true;

		foreach ( $files as $filename => $content ) {
			$filepath = trailingslashit( $path ) . $filename;

			// For .htaccess, always overwrite when forcing to ensure rules are current
			// For other files, only create if they don't exist
			$should_write = ! file_exists( $filepath ) || ( $force && $filename === '.htaccess' );

			if ( $should_write ) {
				$result = file_put_contents( $filepath, $content );
				if ( false === $result ) {
					$success = false;
				}
			}
		}

		return $success;
	}

	/**
	 * Protect all subdirectories.
	 *
	 * @param string $base_path Base upload path.
	 *
	 * @return void
	 */
	private function protect_subdirectories( string $base_path ): void {
		// Get all subdirectories (year/month folders)
		$pattern = trailingslashit( $base_path ) . '*/*';
		$subdirs = glob( $pattern, GLOB_ONLYDIR );

		if ( ! empty( $subdirs ) ) {
			foreach ( $subdirs as $dir ) {
				if ( wp_is_writable( $dir ) ) {
					$index_file = trailingslashit( $dir ) . 'index.php';
					if ( ! file_exists( $index_file ) ) {
						file_put_contents( $index_file, '<?php // Silence is golden.' );
					}
				}
			}
		}
	}

	/**
	 * Get .htaccess rules for Apache.
	 *
	 * @return string
	 */
	public function get_htaccess_rules(): string {
		$rules = "# Protected Folders Rules - " . ucfirst( $this->prefix ) . "\n";
		$rules .= "# Disable directory browsing\n";
		$rules .= "Options -Indexes\n\n";

		// Apache 2.4+ (current standard)
		$rules .= "# Apache 2.4+\n";
		$rules .= "<IfModule mod_authz_core.c>\n";
		$rules .= "    Require all denied\n";

		if ( ! empty( $this->allowed_extensions ) ) {
			$extensions = implode( '|', $this->allowed_extensions );
			$rules      .= "    <FilesMatch '\.(" . $extensions . ")$'>\n";
			$rules      .= "        Require all granted\n";
			$rules      .= "    </FilesMatch>\n";
		}

		$rules .= "</IfModule>\n\n";

		// Apache 2.2 fallback
		$rules .= "# Apache 2.2 fallback\n";
		$rules .= "<IfModule !mod_authz_core.c>\n";
		$rules .= "    Order Deny,Allow\n";
		$rules .= "    Deny from all\n";

		if ( ! empty( $this->allowed_extensions ) ) {
			$extensions = implode( '|', $this->allowed_extensions );
			$rules      .= "    <FilesMatch '\.(" . $extensions . ")$'>\n";
			$rules      .= "        Order Allow,Deny\n";
			$rules      .= "        Allow from all\n";
			$rules      .= "    </FilesMatch>\n";
		}

		$rules .= "</IfModule>\n";

		return apply_filters( $this->prefix . '_htaccess_rules', $rules );
	}

	/**
	 * Get Nginx configuration rules.
	 *
	 * @return string
	 */
	public function get_nginx_rules(): string {
		$location = '/' . $this->prefix . '/';
		$rules    = "location ~ ^$location {\n";
		$rules    .= "    deny all;\n";

		if ( ! empty( $this->allowed_extensions ) ) {
			$extensions = implode( '|', $this->allowed_extensions );
			$rules      .= "    location ~ \\.($extensions)$ {\n";
			$rules      .= "        allow all;\n";
			$rules      .= "    }\n";
		}

		$rules .= "}\n";

		return apply_filters( $this->prefix . '_nginx_rules', $rules );
	}

	/**
	 * Get IIS web.config rules.
	 *
	 * @return string
	 */
	public function get_iis_rules(): string {
		$rules = "<configuration>\n";
		$rules .= "  <system.webServer>\n";
		$rules .= "    <authorization>\n";
		$rules .= "      <deny users=\"*\" />\n";
		$rules .= "    </authorization>\n";

		if ( ! empty( $this->allowed_extensions ) ) {
			$extensions = implode( '|', $this->allowed_extensions );
			$rules      .= "    <security>\n";
			$rules      .= "      <requestFiltering>\n";
			$rules      .= "        <fileExtensions allowUnlisted=\"false\">\n";
			foreach ( $this->allowed_extensions as $ext ) {
				$rules .= "          <add fileExtension=\".$ext\" allowed=\"true\" />\n";
			}
			$rules .= "        </fileExtensions>\n";
			$rules .= "      </requestFiltering>\n";
			$rules .= "    </security>\n";
		}

		$rules .= "  </system.webServer>\n";
		$rules .= "</configuration>";

		return apply_filters( $this->prefix . '_iis_rules', $rules );
	}

	/**
	 * Get the upload directory path.
	 *
	 * @param bool $dated Whether to include year/month subdirectory.
	 *
	 * @return string
	 */
	public function get_upload_path( bool $dated = false ): string {
		$wp_upload_dir = wp_upload_dir();
		$path          = trailingslashit( $wp_upload_dir['basedir'] ) . $this->prefix;

		if ( $dated && $this->use_dated_folders ) {
			$time = current_time( 'mysql' );
			$y    = substr( $time, 0, 4 );
			$m    = substr( $time, 5, 2 );
			$path .= "/$y/$m";
		}

		return $path;
	}

	/**
	 * Get the upload directory URL.
	 *
	 * @param bool $dated Whether to include year/month subdirectory.
	 *
	 * @return string
	 */
	public function get_upload_url( bool $dated = false ): string {
		$wp_upload_dir = wp_upload_dir();
		$url           = trailingslashit( $wp_upload_dir['baseurl'] ) . $this->prefix;

		if ( $dated && $this->use_dated_folders ) {
			$time = current_time( 'mysql' );
			$y    = substr( $time, 0, 4 );
			$m    = substr( $time, 5, 2 );
			$url  .= "/$y/$m";
		}

		return $url;
	}

	/**
	 * Check if the upload directory is protected.
	 *
	 * @param bool $force Force recheck even if cached.
	 *
	 * @return bool
	 */
	public function is_protected( bool $force = false ): bool {
		// Skip test in local development if configured
		if ( Environment::is_localhost() && apply_filters( $this->prefix . '_skip_local_protection_test', true ) ) {
			return $this->has_protection_files();
		}

		$transient_key = $this->prefix . '_uploads_protected';

		if ( ! $force ) {
			$cached = get_transient( $transient_key );
			if ( false !== $cached ) {
				return (bool) $cached;
			}
		}

		$upload_path = $this->get_upload_path();

		// For non-Apache servers, just check if protection files exist
		if ( ! Server::is_apache() && ! Server::is_litespeed() ) {
			$protected = $this->has_protection_files();
			set_transient( $transient_key, $protected ? 1 : 0, 12 * HOUR_IN_SECONDS );

			return $protected;
		}

		$htaccess = trailingslashit( $upload_path ) . '.htaccess';

		// Quick check - if no .htaccess, not protected
		if ( ! file_exists( $htaccess ) ) {
			set_transient( $transient_key, 0, HOUR_IN_SECONDS );

			return false;
		}

		// Test with a temporary file that shouldn't be accessible
		$test_file = $this->prefix . '-test-' . wp_generate_password( 8, false ) . '.txt';
		$test_path = trailingslashit( $upload_path ) . $test_file;

		// Create test file
		file_put_contents( $test_path, 'This file should not be accessible' );

		// Try to access it
		$test_url = trailingslashit( $this->get_upload_url() ) . $test_file;

		// Use more robust request with proper headers
		$response = wp_remote_get( $test_url, [
			'timeout'     => 5,
			'sslverify'   => false,
			'redirection' => 0,
			'headers'     => [
				'Cache-Control' => 'no-cache',
			]
		] );

		$code = wp_remote_retrieve_response_code( $response );

		// File should return 403 Forbidden or 401 Unauthorized
		// Some servers return 404 when denying access
		$protected = ! in_array( $code, [ 200, 201, 202, 203, 204, 205, 206 ], true );

		// Clean up
		@unlink( $test_path );

		// Log for debugging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'Protected Folders test for %s: HTTP %d = %s',
				$this->prefix,
				$code,
				$protected ? 'protected' : 'NOT protected'
			) );
		}

		// Cache result
		set_transient( $transient_key, $protected ? 1 : 0, 12 * HOUR_IN_SECONDS );

		return $protected;
	}

	/**
	 * Check if basic protection files exist.
	 *
	 * @return bool
	 */
	public function has_protection_files(): bool {
		$upload_path = $this->get_upload_path();

		// Check for index files
		$has_index = file_exists( trailingslashit( $upload_path ) . 'index.php' )
		             || file_exists( trailingslashit( $upload_path ) . 'index.html' );

		// For Apache, also check .htaccess
		if ( Server::is_apache() || Server::is_litespeed() ) {
			$htaccess = trailingslashit( $upload_path ) . '.htaccess';
			if ( file_exists( $htaccess ) ) {
				$content = file_get_contents( $htaccess );

				// Check if it has our marker
				return $has_index && str_contains( $content, 'Protected Folders Rules' );
			}

			return false;
		}

		return $has_index;
	}

	/**
	 * Get server configuration instructions based on server type.
	 *
	 * @return array Configuration instructions with 'type' and 'instructions' keys.
	 */
	public function get_server_instructions(): array {
		$upload_path = $this->get_upload_path();

		if ( Server::is_nginx() ) {
			return [
				'type'         => 'nginx',
				'title'        => __( 'Nginx Configuration Required', 'arraypress' ),
				'instructions' => __( 'Add the following rules to your Nginx configuration:', 'arraypress' ),
				'code'         => $this->get_nginx_rules(),
				'notes'        => __( 'After adding these rules, reload Nginx: nginx -s reload', 'arraypress' )
			];
		}

		if ( Server::is_iis() ) {
			return [
				'type'         => 'iis',
				'title'        => __( 'IIS Configuration Required', 'arraypress' ),
				'instructions' => sprintf(
					__( 'Add the following to your web.config in %s:', 'arraypress' ),
					$upload_path
				),
				'code'         => $this->get_iis_rules(),
				'notes'        => __( 'Restart IIS after adding the configuration.', 'arraypress' )
			];
		}

		// Default to Apache/LiteSpeed
		return [
			'type'         => 'apache',
			'title'        => __( 'Apache Configuration Issue', 'arraypress' ),
			'instructions' => __( 'The .htaccess file exists but may not be working. Check:', 'arraypress' ),
			'checklist'    => [
				__( 'Apache mod_rewrite is enabled', 'arraypress' ),
				__( 'AllowOverride is set to All in Apache configuration', 'arraypress' ),
				__( 'File permissions allow reading .htaccess files', 'arraypress' ),
				sprintf(
					__( '.htaccess file exists at: %s', 'arraypress' ),
					trailingslashit( $upload_path ) . '.htaccess'
				)
			]
		];
	}

	/**
	 * Get comprehensive server information for debugging.
	 *
	 * @return array Server and protection information.
	 */
	public function get_debug_info(): array {
		$upload_path     = $this->get_upload_path();
		$htaccess_path   = trailingslashit( $upload_path ) . '.htaccess';
		$index_php_path  = trailingslashit( $upload_path ) . 'index.php';
		$index_html_path = trailingslashit( $upload_path ) . 'index.html';

		$info = [
			'server_type'          => $this->get_server_type_string(),
			'server_software'      => Server::get_software(),
			'is_local_development' => Environment::is_localhost(),
			'environment_type'     => Environment::get_type(),
			'host'                 => $_SERVER['HTTP_HOST'] ?? 'Unknown',
			'upload_path'          => $upload_path,
			'upload_url'           => $this->get_upload_url(),
			'upload_path_exists'   => file_exists( $upload_path ),
			'upload_path_writable' => wp_is_writable( $upload_path ),
			'protection_files'     => [
				'htaccess_exists'   => file_exists( $htaccess_path ),
				'index_php_exists'  => file_exists( $index_php_path ),
				'index_html_exists' => file_exists( $index_html_path ),
			],
			'is_protected'         => $this->is_protected(),
			'has_protection_files' => $this->has_protection_files(),
			'needs_update'         => $this->needs_protection_update(),
			'allowed_extensions'   => $this->allowed_extensions,
			'use_dated_folders'    => $this->use_dated_folders,
		];

		// Add permissions info if files exist
		if ( file_exists( $upload_path ) ) {
			$info['permissions'] = [
				'folder' => substr( sprintf( '%o', fileperms( $upload_path ) ), - 4 ),
			];

			if ( file_exists( $htaccess_path ) ) {
				$info['permissions']['htaccess'] = substr( sprintf( '%o', fileperms( $htaccess_path ) ), - 4 );
			}

			if ( file_exists( $index_php_path ) ) {
				$info['permissions']['index_php'] = substr( sprintf( '%o', fileperms( $index_php_path ) ), - 4 );
			}
		}

		// Add dated folder info if using dated folders
		if ( $this->use_dated_folders ) {
			$dated_path            = $this->get_upload_path( true );
			$info['dated_folders'] = [
				'current_path'      => $dated_path,
				'current_exists'    => file_exists( $dated_path ),
				'current_protected' => file_exists( trailingslashit( $dated_path ) . '.htaccess' ),
				'year_month'        => date( 'Y/m' ),
			];
		}

		// Add protection test results if available
		$test_result             = $this->test_protection();
		$info['protection_test'] = [
			'status'  => $test_result['protected'] ?? false,
			'message' => $test_result['message'] ?? '',
			'method'  => $test_result['method'] ?? 'http_request',
		];

		// Add delivery/server capabilities
		$info['server_capabilities'] = [
			'xsendfile_supported' => Server::has_xsendfile(),
			'mod_rewrite'         => Server::has_mod_rewrite(),
			'supports_htaccess'   => Server::supports_htaccess(),
			'supports_gzip'       => Server::supports_gzip(),
			'server_api'          => php_sapi_name(),
			'max_upload_size'     => wp_max_upload_size(),
			'max_upload_size_mb'  => size_format( wp_max_upload_size() ),
		];

		// Add Apache modules if detectable
		$apache_modules = Server::get_apache_modules();
		if ( $apache_modules !== null ) {
			$info['server_capabilities']['apache_modules'] = [
				'mod_rewrite'    => in_array( 'mod_rewrite', $apache_modules ),
				'mod_xsendfile'  => in_array( 'mod_xsendfile', $apache_modules ),
				'mod_headers'    => in_array( 'mod_headers', $apache_modules ),
				'mod_authz_core' => in_array( 'mod_authz_core', $apache_modules ),
			];
		}

		// Add .htaccess content preview if it exists
		if ( file_exists( $htaccess_path ) ) {
			$content                  = file_get_contents( $htaccess_path );
			$info['htaccess_content'] = [
				'preview' => substr( $content, 0, 200 ) . ( strlen( $content ) > 200 ? '...' : '' ),
				'size'    => filesize( $htaccess_path ),
				'lines'   => substr_count( $content, "\n" ) + 1,
				'hash'    => md5( $content ),
			];
		}

		// Add folder statistics
		if ( file_exists( $upload_path ) && is_dir( $upload_path ) ) {
			$files                = glob( trailingslashit( $upload_path ) . '*' );
			$info['folder_stats'] = [
				'total_files'     => count( $files ),
				'protected_files' => count( array_filter( $files, 'is_file' ) ),
				'subdirectories'  => count( array_filter( $files, 'is_dir' ) ),
			];
		}

		return $info;
	}

	/**
	 * Get server type as string for debug info.
	 *
	 * @return string Server type string.
	 */
	private function get_server_type_string(): string {
		if ( Server::is_nginx() ) {
			return 'nginx';
		}
		if ( Server::is_iis() ) {
			return 'iis';
		}
		if ( Server::is_litespeed() ) {
			return 'litespeed';
		}
		if ( Server::is_apache() ) {
			return 'apache';
		}

		return 'unknown';
	}

	/**
	 * Test file protection manually.
	 *
	 * @return array Test results with 'success' and 'message' keys.
	 */
	public function test_protection(): array {
		// Force creation of protection files
		$created = $this->protect( true );

		if ( ! $created ) {
			return [
				'success'   => false,
				'message'   => __( 'Failed to create protection files. Check directory permissions.', 'arraypress' ),
				'protected' => false
			];
		}

		// Check if we're in local development
		if ( Environment::is_localhost() ) {
			if ( $this->has_protection_files() ) {
				return [
					'success'   => true,
					'message'   => __( 'Protection files created. Note: Direct access test may fail in local development but will work on production servers.', 'arraypress' ),
					'is_local'  => true,
					'protected' => true
				];
			}
		}

		// Test actual protection
		$is_protected = $this->is_protected( true );

		if ( $is_protected ) {
			return [
				'success'   => true,
				'message'   => __( 'Files are successfully protected from direct access.', 'arraypress' ),
				'protected' => true
			];
		}

		if ( Server::is_nginx() ) {
			return [
				'success'     => false,
				'message'     => __( 'Nginx detected. Manual configuration required.', 'arraypress' ),
				'server_type' => 'nginx',
				'protected'   => false
			];
		}

		if ( Server::is_iis() ) {
			return [
				'success'     => false,
				'message'     => __( 'IIS detected. Manual configuration required.', 'arraypress' ),
				'server_type' => 'iis',
				'protected'   => false
			];
		}

		return [
			'success'     => false,
			'message'     => __( 'Protection files created but direct access is still possible. Check server configuration.', 'arraypress' ),
			'server_type' => $this->get_server_type_string(),
			'protected'   => false
		];
	}

	/**
	 * Filter upload directory for specific post types.
	 *
	 * @param array $upload Upload directory array.
	 *
	 * @return array
	 */
	public function filter_upload_dir( array $upload ): array {
		if ( $this->use_dated_folders ) {
			$time   = current_time( 'mysql' );
			$y      = substr( $time, 0, 4 );
			$m      = substr( $time, 5, 2 );
			$subdir = "/{$this->prefix}/$y/$m";
		} else {
			$subdir = "/{$this->prefix}";
		}

		$upload['subdir'] = $subdir;
		$upload['path']   = $upload['basedir'] . $subdir;
		$upload['url']    = $upload['baseurl'] . $subdir;

		// Ensure directory exists
		wp_mkdir_p( $upload['path'] );

		return $upload;
	}

	/**
	 * Setup automatic upload directory filtering.
	 *
	 * @param callable|string|array $condition Condition to check:
	 *                                         - Callable: Custom function returning bool
	 *                                         - String/Array: Post type(s) to check
	 *                                         - 'admin:page_slug': Admin page check
	 *
	 * @return void
	 */
	public function setup_upload_filter( $condition ): void {
		add_filter( 'wp_handle_upload_prefilter', function ( $file ) use ( $condition ) {
			$should_filter = false;

			// Custom callable
			if ( is_callable( $condition ) ) {
				$should_filter = call_user_func( $condition );
			} // Admin page check (format: "admin:page_slug")
			elseif ( is_string( $condition ) && str_starts_with( $condition, 'admin:' ) ) {
				$page          = substr( $condition, 6 );
				$should_filter = is_admin() && isset( $_GET['page'] ) && $_GET['page'] === $page;
			} // Post type check
			elseif ( ! empty( $_REQUEST['post_id'] ) ) {
				$post_types    = (array) $condition;
				$post_type     = get_post_type( absint( $_REQUEST['post_id'] ) );
				$should_filter = in_array( $post_type, $post_types, true );
			}

			if ( $should_filter ) {
				add_filter( 'upload_dir', [ $this, 'filter_upload_dir' ] );
			}

			return $file;
		}, 5 );
	}

	/**
	 * Schedule automatic protection checks.
	 *
	 * @param string $hook WordPress admin hook to run on (default: admin_init).
	 *
	 * @return void
	 */
	public function schedule_protection( string $hook = 'admin_init' ): void {
		add_action( $hook, [ $this, 'protect' ] );
	}

	/**
	 * Delete all protection files.
	 *
	 * @return bool True if successful.
	 */
	public function unprotect(): bool {
		$upload_path = $this->get_upload_path();
		$files       = [ '.htaccess', 'index.php', 'index.html' ];
		$success     = true;

		foreach ( $files as $file ) {
			$filepath = trailingslashit( $upload_path ) . $file;
			if ( file_exists( $filepath ) ) {
				if ( ! @unlink( $filepath ) ) {
					$success = false;
				}
			}
		}

		// Clear transients
		delete_transient( $this->prefix . '_protection_check' );
		delete_transient( $this->prefix . '_uploads_protected' );

		return $success;
	}

}