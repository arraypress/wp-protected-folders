<?php
/**
 * Folder and guard tests.
 *
 * @package ArrayPress\ProtectedFolders
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders\Tests;

use ArrayPress\ProtectedFolders\Folder;
use ArrayPress\ProtectedFolders\Folders;
use ArrayPress\ProtectedFolders\Guard;
use ArrayPress\ProtectedFolders\Rules;
use PHPUnit\Framework\TestCase;

/**
 * A directory under uploads the web server is told to refuse.
 *
 * The two things worth pinning are the two the previous version got wrong:
 * a file is *resolved* inside the folder rather than trusted, and "protected"
 * means the server refuses it rather than that an .htaccess exists.
 */
final class FolderTest extends TestCase {

	/**
	 * Where uploads are pretended to be.
	 *
	 * @var string
	 */
	private string $uploads = '';

	/**
	 * A clean uploads directory per test.
	 */
	protected function setUp(): void {
		pf_reset_globals();

		$this->uploads       = (string) realpath( sys_get_temp_dir() ) . '/pf-' . bin2hex( random_bytes( 4 ) );
		$GLOBALS['pf_uploads'] = $this->uploads;

		mkdir( $this->uploads, 0o777, true );
	}

	/**
	 * Take it away again.
	 */
	protected function tearDown(): void {
		$this->remove( $this->uploads );
	}

	/**
	 * Remove a directory and everything in it.
	 *
	 * @param string $path The directory.
	 *
	 * @return void
	 */
	private function remove( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( (array) scandir( $path ) as $entry ) {
			if ( in_array( $entry, [ '.', '..' ], true ) ) {
				continue;
			}

			$child = $path . '/' . $entry;

			is_dir( $child ) ? $this->remove( $child ) : unlink( $child );
		}

		rmdir( $path );
	}

	/**
	 * A registered folder.
	 *
	 * @param array<string, mixed> $config Overrides.
	 *
	 * @return Folder
	 */
	private function folder( array $config = [] ): Folder {
		$folder = Folders::register( 'downloads', $config );

		$this->assertInstanceOf( Folder::class, $folder );

		return $folder;
	}

	/* ---------------------------------------------------------------------
	 * Where it is
	 * ------------------------------------------------------------------ */

	/**
	 * An identifier that is a path is not a path.
	 *
	 * The directory name came straight from the caller, so a folder called
	 * '../../..' was the site root and everything below it "protected".
	 */
	public function test_an_identifier_cannot_escape_uploads(): void {
		// Nothing usable left, so there is no folder.
		$this->assertNull( Folders::register( '../../..' ) );
		$this->assertNull( Folders::register( '/' ) );

		// And anything that does survive is a directory name, in uploads.
		foreach ( [ '/etc', 'down/loads', '..\\windows', 'Down Loads' ] as $given ) {
			$folder = Folders::register( $given );

			$this->assertNotNull( $folder, sprintf( '%s produced nothing at all.', $given ) );
			$this->assertStringStartsWith(
				$this->uploads . '/',
				$folder->path(),
				sprintf( '%s escaped uploads.', $given )
			);
			$this->assertStringNotContainsString( '..', $folder->path() );

			Folders::forget( $folder->id() );
		}
	}

	/**
	 * It sits under uploads, dated if it asked.
	 */
	public function test_it_sits_under_uploads(): void {
		$folder = $this->folder();

		$this->assertSame( $this->uploads . '/downloads', $folder->path() );
		$this->assertStringStartsWith( $this->uploads . '/downloads/', $folder->path( true ) );
		$this->assertMatchesRegularExpression( '#/downloads/\d{4}/\d{2}$#', $folder->path( true ) );
	}

	/* ---------------------------------------------------------------------
	 * Resolving a file
	 * ------------------------------------------------------------------ */

	/**
	 * A file inside it resolves.
	 */
	public function test_a_file_inside_resolves(): void {
		$folder = $this->folder();

		$folder->create();
		mkdir( $folder->path() . '/2026/08', 0o777, true );
		file_put_contents( $folder->path() . '/2026/08/manual.pdf', 'pdf' );

		$this->assertSame(
			wp_normalize_path( $folder->path() . '/2026/08/manual.pdf' ),
			$folder->file( '2026/08/manual.pdf' )
		);
	}

	/**
	 * A path pointing out of it does not.
	 *
	 * This is the one that matters: a download endpoint takes a path from a
	 * request, and the ordinary way to turn that into a file must not be
	 * able to name wp-config.php.
	 *
	 * @dataProvider escapeProvider
	 *
	 * @param string $relative The path to try.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'escapeProvider' )]
	public function test_a_path_out_of_it_does_not_resolve( string $relative ): void {
		$folder = $this->folder();

		$folder->create();
		file_put_contents( $this->uploads . '/secrets.txt', 'secret' );

		$this->assertNull( $folder->file( $relative ), sprintf( '%s escaped.', $relative ) );
	}

	/**
	 * One row per way out.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function escapeProvider(): array {
		return [
			'traversal'         => [ '../secrets.txt' ],
			'doubled traversal' => [ '....//secrets.txt' ],
			'backslashes'       => [ '..\\secrets.txt' ],
			'a stream wrapper'  => [ 'php://filter/resource=/etc/passwd' ],
			'nothing'           => [ '' ],
		];
	}

	/* ---------------------------------------------------------------------
	 * The guard files
	 * ------------------------------------------------------------------ */

	/**
	 * Protecting writes the files, whatever the server appears to be.
	 *
	 * Detecting Apache from SERVER_SOFTWARE is wrong behind a proxy, and the
	 * two outcomes are not symmetrical: a stray .htaccess on nginx is
	 * ignored, and a missing one on a misdetected Apache is a public folder
	 * full of paid downloads.
	 */
	public function test_protecting_writes_the_files(): void {
		$folder = $this->folder();

		$this->assertTrue( $folder->protect() );

		foreach ( [ '.htaccess', 'index.php', 'index.html' ] as $file ) {
			$this->assertFileExists( $folder->path() . '/' . $file );
		}

		$this->assertTrue( $folder->has_guard_files() );
	}

	/**
	 * Including on a server that says it is nginx.
	 *
	 * The old version asked SERVER_SOFTWARE and only wrote the file for
	 * Apache. That detection is wrong behind a proxy and wrong on LiteSpeed
	 * in some configurations, and the two mistakes do not cost the same: a
	 * stray .htaccess on nginx is ignored, and a missing one on a
	 * misdetected Apache is a public folder full of paid downloads.
	 */
	public function test_the_htaccess_is_written_whatever_the_server_says(): void {
		$was = $_SERVER['SERVER_SOFTWARE'] ?? null;

		foreach ( [ 'nginx/1.24.0', 'Apache/2.4.58', 'LiteSpeed', 'Caddy', '' ] as $software ) {
			$_SERVER['SERVER_SOFTWARE'] = $software;

			$folder = Folders::register( 'downloads-' . md5( $software ) );

			$this->assertNotNull( $folder );
			$this->assertTrue( $folder->protect() );
			$this->assertFileExists(
				$folder->path() . '/.htaccess',
				sprintf( 'No .htaccess on a server calling itself "%s".', $software )
			);
		}

		if ( null === $was ) {
			unset( $_SERVER['SERVER_SOFTWARE'] );
		} else {
			$_SERVER['SERVER_SOFTWARE'] = $was;
		}
	}

	/**
	 * An .htaccess somebody else wrote is left alone.
	 *
	 * Overwriting one would be this library deciding it knows better than
	 * whoever administers the server.
	 */
	public function test_someone_elses_htaccess_is_left_alone(): void {
		$folder = $this->folder();

		$folder->create();
		file_put_contents( $folder->path() . '/.htaccess', "# mine\nDeny from all\n" );

		$folder->protect( true );

		$this->assertSame( "# mine\nDeny from all\n", file_get_contents( $folder->path() . '/.htaccess' ) );
	}

	/**
	 * And one this library wrote is brought up to date.
	 */
	public function test_its_own_htaccess_is_updated(): void {
		$folder = $this->folder();

		$folder->protect();
		file_put_contents( $folder->path() . '/.htaccess', Rules::MARKER . "\n# stale\n" );

		$folder->protect();

		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $folder->path() . '/.htaccess' ) );
	}

	/**
	 * Unprotecting removes what this library wrote and nothing else.
	 */
	public function test_unprotecting_removes_only_its_own(): void {
		$folder = $this->folder();

		$folder->protect();
		file_put_contents( $folder->path() . '/keep.txt', 'keep' );

		$folder->unprotect();

		$this->assertFileDoesNotExist( $folder->path() . '/.htaccess' );
		$this->assertFileExists( $folder->path() . '/keep.txt' );
	}

	/* ---------------------------------------------------------------------
	 * The rules
	 * ------------------------------------------------------------------ */

	/**
	 * An extension that would break the rules file is dropped.
	 *
	 * They go into a regular expression inside a server configuration file.
	 * One containing a bracket used to break the file, which on Apache means
	 * the site returns 500 until somebody finds the .htaccess — and the
	 * folder is unprotected until they do.
	 */
	public function test_an_extension_that_would_break_the_file_is_dropped(): void {
		$rules = Rules::htaccess( 'downloads', [ 'jpg', 'png)$/ Require all granted #', "gif\nRequire all granted", '' ] );

		// Dropped rather than escaped: an extension containing a bracket is
		// a mistake in the configuration, and quietly making it work would
		// mean generating a rule nobody can read.
		$this->assertStringContainsString( '\.(jpg)$', $rules );
		$this->assertStringNotContainsString( 'png', $rules );
		$this->assertStringNotContainsString( 'gif', $rules );
		$this->assertStringNotContainsString( '#', substr( $rules, strpos( $rules, 'Options -Indexes' ) ?: 0 ) );
	}

	/**
	 * Both Apache forms are written.
	 *
	 * A shared host running 2.2 ignores `Require` entirely and serves the
	 * directory.
	 */
	public function test_both_apache_forms_are_written(): void {
		$rules = Rules::htaccess( 'downloads' );

		$this->assertStringContainsString( '<IfModule mod_authz_core.c>', $rules );
		$this->assertStringContainsString( '<IfModule !mod_authz_core.c>', $rules );
		$this->assertStringContainsString( 'Require all denied', $rules );
		$this->assertStringContainsString( 'Deny from all', $rules );
		$this->assertStringContainsString( 'Options -Indexes', $rules );
	}

	/**
	 * nginx gets text to paste rather than a file that does nothing.
	 */
	public function test_nginx_gets_rules_to_paste(): void {
		$rules = $this->folder()->rules_for_server();

		$this->assertStringContainsString( 'deny all;', $rules );
		$this->assertStringContainsString( 'internal;', $rules );
		$this->assertStringContainsString( Rules::internal_location(), $rules );
	}

	/* ---------------------------------------------------------------------
	 * Whether it is actually protected
	 * ------------------------------------------------------------------ */

	/**
	 * Protection is what the server does, not what files exist.
	 *
	 * Getting the bytes back is the only thing that means "not protected".
	 */
	public function test_getting_the_bytes_back_means_it_is_not_protected(): void {
		$folder = $this->folder();

		$folder->protect();

		// The server hands the file straight over.
		$GLOBALS['pf_http'] = static fn( string $url ): array => [
			'body' => file_get_contents( str_replace( 'https://example.test/wp-content/uploads', $GLOBALS['pf_uploads'], $url ) ) ?: '',
		];

		$this->assertFalse( $folder->is_protected( true ) );
	}

	/**
	 * Anything else means it is.
	 *
	 * A 403, a 404, a redirect to a login page and a firewall's block page
	 * are all the folder doing its job.
	 */
	public function test_anything_but_the_bytes_means_it_is_protected(): void {
		$folder = $this->folder();

		$folder->protect();

		$GLOBALS['pf_http'] = [ 'body' => '<html><body>403 Forbidden</body></html>' ];

		$this->assertTrue( $folder->is_protected( true ) );
	}

	/**
	 * The test file does not stay behind.
	 */
	public function test_the_test_file_is_removed(): void {
		$folder = $this->folder();

		$folder->protect();
		$GLOBALS['pf_http'] = [ 'body' => 'nope' ];

		$folder->is_protected( true );

		$this->assertSame( [], (array) glob( $folder->path() . '/protection-test-*' ) );
	}

	/**
	 * The answer is cached, because it is a request to the site's own front end.
	 */
	public function test_the_answer_is_cached(): void {
		$folder = $this->folder();

		$folder->protect();

		$asked = 0;

		$GLOBALS['pf_http'] = static function () use ( &$asked ): array {
			++$asked;

			return [ 'body' => 'refused' ];
		};

		$folder->is_protected( true );
		$folder->is_protected();
		$folder->is_protected();

		$this->assertSame( 1, $asked );
	}

	/* ---------------------------------------------------------------------
	 * Uploads
	 * ------------------------------------------------------------------ */

	/**
	 * An upload for a named post type lands in the folder.
	 */
	public function test_an_upload_for_a_named_post_type_lands_here(): void {
		$folder = $this->folder( [ 'upload_for' => [ 'product' ] ] );

		$_REQUEST['post_id']    = 12;
		$GLOBALS['pf_post_type'] = 'product';

		$uploads = $folder->filter_upload_dir(
			[ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/u', 'subdir' => '/2026/08', 'path' => '', 'url' => '' ]
		);

		$this->assertStringContainsString( '/downloads/', $uploads['path'] );
		$this->assertStringContainsString( '/downloads/', $uploads['url'] );
	}

	/**
	 * And one for anything else does not.
	 */
	public function test_another_post_types_upload_is_left_alone(): void {
		$folder = $this->folder( [ 'upload_for' => [ 'product' ] ] );

		$_REQUEST['post_id']    = 12;
		$GLOBALS['pf_post_type'] = 'post';

		$before  = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/u', 'subdir' => '/2026/08', 'path' => 'x', 'url' => 'y' ];
		$uploads = $folder->filter_upload_dir( $before );

		$this->assertSame( $before, $uploads );
	}

	/**
	 * A folder that names no post type does not touch uploads at all.
	 */
	public function test_a_folder_with_no_post_types_does_not_filter_uploads(): void {
		$this->folder();

		$this->assertArrayNotHasKey( 'upload_dir', $GLOBALS['pf_filters'] );
	}
}
