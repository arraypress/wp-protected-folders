# Protected Folders

An upload folder the web server refuses to serve, and a delivery path that
hands off to object storage when there is one.

## What it does

Putting a paid file under `wp-content/uploads` means anyone who guesses the
URL has it. The usual fix — an `.htaccess` deny rule — is a file you write
once, never verify, and which does nothing at all on nginx.

This registers the folder, writes the rules the server actually understands,
and then proves the protection works by asking the site for the file over
HTTP. Delivery goes through a call that resolves the path inside the folder,
so a value out of a request cannot be made to name `wp-config.php`.

## Features

* Register a folder whose files the web server will not serve
* Keep chosen extensions public, so previews and thumbnails still load
* Send a file to somebody your own code has already authorised
* Redirect to a signed object-storage URL instead of streaming through PHP
* Verify the protection by fetching a canary file over HTTP, rather than assuming
* Get the nginx rules as text, for a server you cannot write to
* Route a post type's uploads into the folder automatically

## Installation

```bash
composer require arraypress/wp-protected-folders
```

## Quick start

Register the folder once:

```php
add_action( 'init', function () {
	register_protected_folder( 'downloads', [
		'public_extensions' => [ 'jpg', 'png' ],  // previews stay public
		'upload_for'        => [ 'product' ],     // product uploads land here
	] );
} );
```

Then, from whatever endpoint has already decided this person may have the file:

```php
deliver_protected_file( 'downloads', $order->file_path, [
	'filename' => $product->name . '.zip',
] );
```

## What it does not do

It does not decide **whether this person** may have the file. That is your
question, and it has to be answered before the call above.

## Object storage

A file in S3, R2 or B2 should be redirected to rather than proxied — streaming
it through PHP pays for the bytes twice. One filter, and the same call site
serves both:

```php
add_filter( 'protected_folders_redirect_to', function ( $url, $path ) {
	return $signer->presign_get( $bucket, key_for( $path ), 60 );
}, 10, 2 );
```

Return null and the file is read from disk as normal.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
