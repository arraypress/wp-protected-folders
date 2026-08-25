# Protected Folders

An upload folder the web server refuses to serve, and a local delivery path
that hands off to object storage when there is one.

## Install

```bash
composer require arraypress/wp-protected-folders
```

Requires PHP 8.3.

## Use

```php
add_action( 'init', function () {
	register_protected_folder( 'downloads', [
		'public_extensions' => [ 'jpg', 'png' ],  // previews stay public
		'upload_for'        => [ 'product' ],     // product uploads land here
	] );
} );
```

Then, from whatever endpoint has already decided this person may have the
file:

```php
deliver_protected_file( 'downloads', $order->file_path, [
	'filename' => $product->name . '.zip',
] );
```

The path is resolved *inside* the folder, so a value that came from a request
cannot be made to name `wp-config.php`. Whether **this person** may have the
file is your question, and this does not ask it.

## Object storage

A file that lives in S3, R2 or B2 should be redirected to rather than
proxied — the signed URL is the point of object storage, and streaming it
through PHP pays for the bytes twice. One filter, and the same call site
serves both:

```php
add_filter( 'protected_folders_redirect_to', function ( $url, $path ) {
	return $signer->presign_get( $bucket, key_for( $path ), 60 );
}, 10, 2 );
```

Return null and the file is read from disk as normal.

## Whether it is actually protected

```php
is_folder_protected( 'downloads' );          // asks the site
get_protected_folder_server_rules( 'downloads' );  // text for nginx
```

`is_folder_protected()` writes a file with contents nobody could guess, asks
the site's own front end for it over HTTP, and removes it. Getting those bytes
back is the only thing that means "not protected" — a 403, a 404, a redirect
to a login page and a firewall's block page are all the folder doing its job.

That matters because **nginx has no per-directory configuration**. An
.htaccess there achieves nothing, and a library that writes one and reports
success has told an nginx site it is protected when it is not.
`get_protected_folder_server_rules()` hands back the `location` blocks to give
whoever administers the server.

## What changed

**A file is resolved, not trusted.** `deliver_protected_file()` took an
absolute path and streamed whatever was at it, so a plugin passing a request
parameter through had an arbitrary file read. It now takes a folder and a
relative path, and resolves one inside the other. The unsafe call still
exists, is called `deliver_file_at_path()`, and says why in its docblock.

**An unsatisfiable range is refused.** `bytes=2000-3000` against a 1KB file
sent a 416 header and then the entire file after it.

**`bytes=-500` is the last five hundred bytes**, which is what it means. It
was read as the first five hundred, so a player seeking to the end of a video
got the beginning of it.

**X-Accel-Redirect keeps the directories.** It sent `basename( $path )`, so
every file in a dated folder resolved to nothing and 404'd — on nginx sites
with X-Accel-Redirect turned on, which is why it lasted.

**The .htaccess is written whatever the server says it is.** Detecting Apache
from `SERVER_SOFTWARE` is wrong behind a proxy, and the two mistakes do not
cost the same: a stray .htaccess on nginx is ignored, and a missing one on a
misdetected Apache is a public folder full of paid downloads.

**Extensions cannot break the rules file.** They go into a regular expression
inside a server configuration file, and one containing a bracket used to break
it — which on Apache means a 500 until somebody finds the .htaccess, and an
unprotected folder until they do.

**An .htaccess somebody else wrote is left alone**, and `unprotect()` only
removes the ones this library wrote.

**A folder identifier cannot escape uploads.** It was used as a directory name
unaltered.

## Upgrading from 1.x

| Was                                       | Now                                       |
| ----------------------------------------- | ----------------------------------------- |
| `deliver_protected_file( $absolute )`     | `deliver_protected_file( $folder, $rel )` |
| `get_protected_folder_path( $id, $dated )`| `get_protected_folder( $id )->path( $dated )` |
| `get_protected_folder_url( $id, $dated )` | `get_protected_folder( $id )->url( $dated )`  |
| `Protector`                               | `Folder`                                  |

`allowed_types` is `public_extensions`, `dated_folders` is `dated`, and
`upload_filter` is `upload_for` and takes a list of post types or a callable.

## Testing

```bash
composer test          # phpunit
composer lint          # phpcs, defect sniffs
composer format:check  # phpcs, formatting
```
