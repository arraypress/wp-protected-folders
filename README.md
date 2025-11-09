# WordPress Protected Folders

Simple folder protection for WordPress with automatic upload organization and .htaccess management. Protects upload directories with .htaccess rules, index files, and verifies protection is working.

## Install

```bash
composer require arraypress/wp-protected-folders
```

## Basic Usage

```php
// Register a protected folder
register_protected_folder( 'my-downloads', [
	'allowed_types'      => [ 'jpg', 'png', 'mp3' ],  // Files accessible publicly
	'dated_folders'      => true,                   // Organize by year/month
	'auto_protect'       => true,                    // Auto-protect on admin_init
	'upload_filter'      => 'download',             // Auto-organize uploads for post type
	'admin_notice_pages' => [ 'my-settings' ]    // Show notices on these pages
] );

// Get paths and URLs
$path = get_protected_folder_path( 'my-downloads' );
$url  = get_protected_folder_url( 'my-downloads' );

// Check protection
if ( is_folder_protected( 'my-downloads' ) ) {
	echo "Folder is protected!";
}

// Advanced operations
$protector = get_protected_folder( 'my-downloads' );
if ( $protector ) {
	$protector->protect( true );           // Force protection
	$result = $protector->test_protection();
	$info   = $protector->get_debug_info();
}
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `allowed_types` | array | `['jpg', 'jpeg', 'png', 'gif', 'webp']` | File extensions allowed for public access |
| `dated_folders` | bool | `true` | Organize uploads by year/month |
| `auto_protect` | bool | `false` | Automatically protect on admin_init |
| `upload_filter` | mixed | `null` | Post types, admin pages, or callback for upload filtering |
| `admin_notice_pages` | array | `[]` | Admin page slugs to show protection notices |

## Upload Filtering

The `upload_filter` option is extremely flexible:

```php
// Single post type
register_protected_folder( 'downloads', [
	'upload_filter' => 'download'
] );

// Multiple post types
register_protected_folder( 'media', [
	'upload_filter' => [ 'product', 'download' ]
] );

// Admin page
register_protected_folder( 'settings-uploads', [
	'upload_filter' => 'admin:my-settings-page'
] );

// Custom logic
register_protected_folder( 'conditional', [
	'upload_filter' => function () {
		return isset( $_GET['special_upload'] );
	}
] );

// Mix everything
register_protected_folder( 'complex', [
	'upload_filter' => [
		'product',
		'download',
		'admin:product-settings',
		function () {
			return current_user_can( 'manage_downloads' );
		}
	]
] );
```

## Core Functions

```php
// Register a protected folder
register_protected_folder( string $id, array $config = [] ): ?Protector

// Get file system path
get_protected_folder_path( string $id, bool $dated = false ): string

// Get public URL
get_protected_folder_url( string $id, bool $dated = false ): string

// Check if protected (tests actual file access)
is_folder_protected( string $id, bool $force = false ): bool

// Get protector instance for advanced operations
get_protected_folder( string $id ): ?Protector
```

## Advanced Operations

For operations beyond the basic helper functions, retrieve the Protector instance:

```php
$protector = get_protected_folder( 'downloads' );

if ( $protector ) {
	// Force protection
	$protector->protect( true );

	// Test protection
	$result = $protector->test_protection();
	echo $result['message'];

	// Get debug info
	$info = $protector->get_debug_info();

	// Get server rules
	$nginx_rules = $protector->get_nginx_rules();
	$htaccess    = $protector->get_htaccess_rules();

	// Remove protection
	$protector->unprotect();

	// Check server type
	$server = $protector->get_server_type();
}

// Or use the Registry directly for management operations
use ArrayPress\ProtectedFolders\Registry;

$registry    = Registry::get_instance();
$all_folders = $registry->get_ids();  // ['downloads', 'media', ...]
$registry->remove( 'downloads' );     // Unregister a folder
```

## Complete Examples

### Digital Download Plugin
```php
// Register on plugin activation or init
add_action( 'init', function () {
	register_protected_folder( 'digital-downloads', [
		'allowed_types'      => [ 'jpg', 'png' ],      // Allow preview images
		'dated_folders'      => true,                // Organize by date
		'auto_protect'       => true,                 // Auto-protect
		'upload_filter'      => [
			'download',                         // Download post type
			'admin:download-settings'           // Settings page
		],
		'admin_notice_pages' => [ 'download-settings' ]
	] );
} );

// Use anywhere in your plugin
function save_download_file( $file ) {
	$upload_dir = get_protected_folder_path( 'digital-downloads', true );
	$file_path  = $upload_dir . '/' . $file['name'];
	move_uploaded_file( $file['tmp_name'], $file_path );

	$file_url = get_protected_folder_url( 'digital-downloads', true );

	return $file_url . '/' . $file['name'];
}
```

### E-Commerce Plugin
```php
// Multiple protected folders for different purposes
register_protected_folder( 'invoices', [
	'allowed_types' => [],                      // No public access
	'dated_folders' => true,
	'auto_protect'  => true
] );

register_protected_folder( 'product-files', [
	'allowed_types' => [ 'jpg', 'png', 'mp4' ],   // Preview files
	'dated_folders' => false,                   // All in one folder
	'auto_protect'  => true,
	'upload_filter' => 'product'
] );

register_protected_folder( 'customer-uploads', [
	'allowed_types' => [],
	'dated_folders' => true,
	'auto_protect'  => true,
	'upload_filter' => function () {
		return isset( $_POST['customer_upload'] );
	}
] );
```

### Membership Site
```php
class Membership_Plugin {
	public function __construct() {
		add_action( 'init', [ $this, 'setup_protection' ] );
		add_action( 'template_redirect', [ $this, 'serve_protected_file' ] );
	}

	public function setup_protection() {
		register_protected_folder( 'member-content', [
			'allowed_types'      => [],  // No direct access
			'dated_folders'      => true,
			'auto_protect'       => true,
			'upload_filter'      => [ 'lesson', 'resource' ],
			'admin_notice_pages' => [ 'membership-settings' ]
		] );
	}

	public function serve_protected_file() {
		if ( ! isset( $_GET['download'] ) ) {
			return;
		}

		if ( ! current_user_can( 'access_member_content' ) ) {
			wp_die( 'Access denied' );
		}

		$file_path = get_protected_folder_path( 'member-content' );
		$file      = $file_path . '/' . sanitize_file_name( $_GET['download'] );

		if ( file_exists( $file ) ) {
			// Serve the file
			readfile( $file );
			exit;
		}
	}
}
```

## How It Works

1. **Registry Pattern** - Single source of truth for all protected folders
2. **Auto-Protection** - Optionally creates protection files on `admin_init`
3. **Upload Filtering** - Automatically organizes uploads to protected folders
4. **Smart Testing** - Actually tests file access, not just file presence
5. **Multi-Server** - Works with Apache, Nginx, IIS, and LiteSpeed

## Protection Layers

Each protected folder gets three layers of protection:

1. **`.htaccess`** - Apache/LiteSpeed deny rules with optional exceptions
2. **`index.php`** - PHP silence file as fallback
3. **`index.html`** - Empty HTML as additional fallback

## Server Support

### Apache/LiteSpeed
Works automatically with .htaccess files.

### Nginx
```php
$protector = get_protected_folder( 'downloads' );
if ( $protector ) {
    echo $protector->get_nginx_rules();
}
```

### IIS
```php
$protector = get_protected_folder( 'downloads' );
if ( $protector ) {
    echo $protector->get_iis_rules();
}
```

## Requirements

- PHP 7.4 or later
- WordPress 5.0 or later

## Why Use This?

- **Zero Boilerplate** - One function call replaces entire classes
- **Minimal API** - Only 5 essential functions for 90% of use cases
- **Smart Defaults** - Works out of the box with sensible defaults
- **Battle-Tested** - Protection actually verified, not just assumed
- **Flexible** - Full access to Protector class for advanced needs
- **WordPress Native** - Follows WordPress coding standards

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the GPL-2.0-or-later License.

## Support

- [Documentation](https://github.com/arraypress/wp-protected-folders)
- [Issue Tracker](https://github.com/arraypress/wp-protected-folders/issues)