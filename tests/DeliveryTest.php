<?php
/**
 * Delivery tests.
 *
 * @package ArrayPress\ProtectedFolders
 */

declare( strict_types=1 );

namespace ArrayPress\ProtectedFolders\Tests;

use ArrayPress\ProtectedFolders\Delivery;
use ArrayPress\ProtectedFolders\Utils\Runtime;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Working out what to send.
 *
 * Delivery ends in exit() and writes headers, so what is exercised here is
 * the deciding rather than the sending: which byte range was asked for, what
 * a browser is told the file is, and whether the file is read at all or the
 * request is handed to object storage.
 *
 * The range parser is the part with the bug in it. An unsatisfiable range
 * used to send a 416 header and then two hundred megabytes of file after it.
 */
final class DeliveryTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		pf_reset_globals();
	}

	/**
	 * Ask the range parser what a request means.
	 *
	 * @param string|null $header    The Range header, or null for none.
	 * @param int         $size      The file's size.
	 *
	 * @return array{0:int,1:int}|null|false
	 */
	private function range( ?string $header, int $size = 1000 ): array|null|false {
		if ( null === $header ) {
			unset( $_SERVER['HTTP_RANGE'] );
		} else {
			$_SERVER['HTTP_RANGE'] = $header;
		}

		$method = new ReflectionMethod( Delivery::class, 'range' );

		return $method->invoke( null, $size );
	}

	/**
	 * No Range header means the whole file.
	 */
	public function test_no_range_means_the_whole_file(): void {
		$this->assertNull( $this->range( null ) );
	}

	/**
	 * A range is read.
	 *
	 * @dataProvider rangeProvider
	 *
	 * @param string                        $header   The Range header.
	 * @param array{0:int,1:int}|null|false $expected What it means.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'rangeProvider' )]
	public function test_a_range_is_read( string $header, array|null|false $expected ): void {
		$this->assertSame( $expected, $this->range( $header ) );
	}

	/**
	 * One row per shape of Range header.
	 *
	 * @return array<string, array{0: string, 1: array{0:int,1:int}|null|false}>
	 */
	public static function rangeProvider(): array {
		return [
			'the first hundred bytes' => [ 'bytes=0-99', [ 0, 99 ] ],
			'from halfway on'         => [ 'bytes=500-', [ 500, 999 ] ],

			// `bytes=-500` is the *last* five hundred bytes, not the first.
			// Reading it the other way hands back the beginning of the file
			// to a player that asked for the end, which looks like a corrupt
			// download.
			'the last five hundred'   => [ 'bytes=-500', [ 500, 999 ] ],

			'more than there is'      => [ 'bytes=0-99999', [ 0, 999 ] ],
			'past the end'            => [ 'bytes=2000-3000', false ],
			'backwards'               => [ 'bytes=900-100', false ],

			// Several ranges need a multipart response, which nothing asks
			// for in practice. The whole file is a valid answer to a range
			// request; half a multipart response is not.
			'several ranges'          => [ 'bytes=0-99,200-299', null ],

			'not a range at all'      => [ 'items=0-99', null ],
			'empty'                   => [ 'bytes=-', null ],
		];
	}

	/**
	 * An unsatisfiable range is refused rather than answered with the file.
	 *
	 * It used to send the 416 header and then the entire file after it.
	 */
	public function test_an_unsatisfiable_range_is_a_refusal(): void {
		$this->assertFalse( $this->range( 'bytes=2000-3000' ) );
	}

	/**
	 * A range against an empty file is no range.
	 */
	public function test_a_range_against_an_empty_file_is_no_range(): void {
		$this->assertNull( $this->range( 'bytes=0-99', 0 ) );
	}

	/**
	 * A file in object storage is redirected to rather than read.
	 *
	 * The signed URL is the point of object storage, and proxying the bytes
	 * through PHP pays for them twice. One call site, either delivery.
	 */
	public function test_object_storage_is_redirected_to(): void {
		$asked = null;

		add_filter(
			Runtime::hook( 'redirect_to' ),
			static function ( $url, string $path ) use ( &$asked ): string {
				$asked = $path;

				return 'https://files.example.test/signed?sig=abc';
			},
			10,
			2
		);

		$this->assertSame(
			'https://files.example.test/signed?sig=abc',
			apply_filters( Runtime::hook( 'redirect_to' ), null, '/uploads/downloads/manual.pdf', [] )
		);

		$this->assertSame( '/uploads/downloads/manual.pdf', $asked );
	}

	/**
	 * With nobody listening, the filter leaves the file to be read locally.
	 */
	public function test_with_no_object_storage_the_file_is_read_locally(): void {
		$this->assertNull( apply_filters( Runtime::hook( 'redirect_to' ), null, '/uploads/downloads/manual.pdf', [] ) );
	}

	/**
	 * The nginx internal path keeps the whole path below uploads.
	 *
	 * It used to be basename(), so every file in a dated folder resolved to
	 * nothing and 404'd — and only on nginx sites with X-Accel-Redirect
	 * turned on, which is why it lasted.
	 */
	public function test_the_nginx_path_keeps_the_directories(): void {
		$uploads = wp_upload_dir();
		$file    = $uploads['basedir'] . '/downloads/2026/08/manual.pdf';

		$base = wp_normalize_path( (string) $uploads['basedir'] );
		$path = wp_normalize_path( $file );

		$this->assertSame(
			'downloads/2026/08/manual.pdf',
			ltrim( substr( $path, strlen( $base ) ), '/' ),
			'The path below uploads is what X-Accel-Redirect needs.'
		);
	}
}
