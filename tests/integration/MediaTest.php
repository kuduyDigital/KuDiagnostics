<?php
/**
 * Phase 6 media (test matrix S5, S6): list with filters, one item with its image as MCP image content, alt text,
 * decorative images, title/caption/description with kses, undo, and permissions.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class MediaTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $author;
	private static int $contributor;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		require_once ABSPATH . 'wp-admin/includes/image.php';
		self::$admin       = self::make_user( 'administrator' );
		self::$author      = self::make_user( 'author' );
		self::$contributor = self::make_user( 'contributor' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * A 1600 × 900 PNG in the media library, with generated sizes.
	 *
	 * @param int $owner Uploader.
	 */
	private function image( int $owner ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available in this PHP build.' );
		}
		$gd = imagecreatetruecolor( 1600, 900 );
		imagefill( $gd, 0, 0, (int) imagecolorallocate( $gd, 20, 120, 200 ) );
		ob_start();
		imagepng( $gd );
		$png = (string) ob_get_clean();
		$up  = wp_upload_bits( 'kuduy-test-' . wp_generate_password( 6, false ) . '.png', null, $png );
		$this->assertEmpty( $up['error'] );
		$id = (int) wp_insert_attachment(
			array(
				'post_title'     => 'Blue banner',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
				'post_author'    => $owner,
			),
			$up['file']
		);
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		return $id;
	}

	/**
	 * Ids in a list result.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return list<int>
	 */
	private function listed( array $args ): array {
		return array_column( $this->ok( 'kuduy-media-list', $args + array( 'per_page' => 100 ) )['items'], 'id' );
	}

	public function test_list_get_with_image_and_alt_text_lifecycle(): void {
		$id = $this->image( self::$admin );
		$this->assertContains( $id, $this->listed( array() ) );
		$this->assertContains( $id, $this->listed( array( 'missing_alt' => true ) ) );
		$this->assertNotContains( $id, $this->listed( array( 'mime_type' => 'video' ) ) );

		// Claude gets the image itself, downscaled, before writing alt text.
		$result = $this->call(
			'kuduy-media-get',
			array(
				'id'            => $id,
				'include_image' => true,
			)
		);
		$this->assertFalse( $result['isError'] );
		$this->assertSame( 1600, $result['structuredContent']['width'] );
		$this->assertNull( $result['structuredContent']['alt'] );
		$this->assertArrayNotHasKey( '_content', $result['structuredContent'] );
		$this->assertSame( 'image', $result['content'][1]['type'] );
		$bytes = base64_decode( $result['content'][1]['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test decodes image content.
		$size  = getimagesizefromstring( (string) $bytes );
		$this->assertIsArray( $size );
		$this->assertLessThanOrEqual( 1024, max( $size[0], $size[1] ) );
		$this->assertSame( $result['content'][1]['mimeType'], $size['mime'] );

		$updated = $this->ok(
			'kuduy-media-update',
			array(
				'id'      => $id,
				'alt'     => 'Plain blue banner',
				'caption' => 'Our banner',
			)
		);
		$this->assertSame( 'Plain blue banner', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Our banner', get_post( $id )->post_excerpt );
		$this->assertSame( 'Blue banner', get_post( $id )->post_title, 'untouched title kept' );
		$this->assertNotContains( $id, $this->listed( array( 'missing_alt' => true ) ) );

		// Decorative: empty alt, not "missing".
		$this->ok(
			'kuduy-media-update',
			array(
				'id'         => $id,
				'decorative' => true,
			)
		);
		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertTrue( $this->ok( 'kuduy-media-get', array( 'id' => $id ) )['decorative'] );
		$this->assertNotContains( $id, $this->listed( array( 'missing_alt' => true ) ) );
		$this->fails(
			'kuduy-media-update',
			array(
				'id'         => $id,
				'decorative' => true,
				'alt'        => 'Something',
			),
			'decorative and alt contradict'
		);

		// Undo of the first update: no alt, no caption.
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_wp_attachment_image_alt' ) );
		$this->assertSame( '', get_post( $id )->post_excerpt );

		// alt null removes it.
		$this->ok(
			'kuduy-media-update',
			array(
				'id'  => $id,
				'alt' => 'Again',
			)
		);
		$this->ok(
			'kuduy-media-update',
			array(
				'id'  => $id,
				'alt' => null,
			)
		);
		$this->assertFalse( metadata_exists( 'post', $id, '_wp_attachment_image_alt' ) );
		$this->fails( 'kuduy-media-update', array( 'id' => $id ), 'nothing to change' );
	}

	public function test_permissions_and_kses(): void {
		$admins = $this->image( self::$admin );
		$own    = $this->image( self::$author );

		wp_set_current_user( self::$author );
		$this->ok(
			'kuduy-media-update',
			array(
				'id'          => $own,
				'alt'         => 'Mine',
				'description' => 'Safe <script>alert(1)</script><strong>text</strong>',
			)
		);
		$description = get_post( $own )->post_content;
		$this->assertStringNotContainsString( '<script', $description, 'kses for users without unfiltered_html' );
		$this->assertStringContainsString( '<strong>text</strong>', $description );
		$this->fails(
			'kuduy-media-update',
			array(
				'id'  => $admins,
				'alt' => 'Not mine',
			),
			'authors cannot edit others\' media'
		);
		$this->assertFalse( metadata_exists( 'post', $admins, '_wp_attachment_image_alt' ) );

		wp_set_current_user( self::$contributor );
		$this->fails( 'kuduy-media-list', array(), 'contributors cannot use the media library' );
		$this->fails( 'kuduy-media-get', array( 'id' => 999999 ), 'unknown id' );
	}
}
