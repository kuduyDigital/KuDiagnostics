<?php
/**
 * Phase 6 media library (test matrix S7, S8): where an image is used, adding an image from a URL (download supplied
 * through pre_http_request: Playground has no outbound network for PHP), refusing non-images and plain HTTP,
 * regenerating sizes, permissions.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class MediaLibraryTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $author;

	/**
	 * Bytes the mocked download returns.
	 *
	 * @var string
	 */
	private string $download = '';

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		require_once ABSPATH . 'wp-admin/includes/image.php';
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
	}

	protected function setUp(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available in this PHP build.' );
		}
		wp_set_current_user( self::$admin );
		add_filter( 'pre_http_request', array( $this, 'serve' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'serve' ), 10 );
	}

	/**
	 * Mocked download: writes $this->download to the stream file core asked for.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  URL.
	 * @return mixed
	 */
	public function serve( mixed $pre, array $args, string $url ): mixed {
		if ( ! str_starts_with( $url, 'https://images.example.com/' ) ) {
			return $pre;
		}
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( (string) $args['filename'], $this->download ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test mock of a download.
		}
		return array(
			'headers'  => array( 'content-type' => 'image/png' ),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
	}

	/**
	 * PNG bytes.
	 *
	 * @param int $w Width.
	 * @param int $h Height.
	 */
	private static function png( int $w, int $h ): string {
		$gd = imagecreatetruecolor( $w, $h );
		imagefill( $gd, 0, 0, (int) imagecolorallocate( $gd, 200, 60, 20 ) );
		ob_start();
		imagepng( $gd );
		return (string) ob_get_clean();
	}

	public function test_upload_usage_and_regenerate(): void {
		$page           = (int) wp_insert_post(
			array(
				'post_title'  => 'Gallery page',
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		$this->download = self::png( 1200, 800 );
		$uploaded       = $this->ok(
			'kuduy-media-upload',
			array(
				'url'       => 'https://images.example.com/photos/sunset.png',
				'title'     => 'Sunset',
				'alt'       => 'Orange sky over the sea',
				'attach_to' => $page,
			)
		);
		$id             = $uploaded['id'];
		$this->assertSame( 'image/png', $uploaded['mime_type'] );
		$this->assertSame( 1200, $uploaded['width'] );
		$this->assertSame( $page, wp_get_post_parent_id( $id ) );
		$this->assertSame( 'Orange sky over the sea', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Sunset', get_the_title( $id ) );

		// Used as featured image, in block content, in an Elementor tree.
		set_post_thumbnail( $page, $id );
		$post    = (int) wp_insert_post(
			array(
				'post_title'   => 'Uses it in content',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->',
			)
		);
		$builder = (int) wp_insert_post(
			array(
				'post_title'  => 'Elementor page',
				'post_status' => 'draft',
			)
		);
		update_post_meta(
			$builder,
			'_elementor_data',
			wp_slash(
				(string) wp_json_encode(
					array(
						array(
							'settings' => array(
								'image' => array(
									'url' => wp_get_attachment_url( $id ),
									'id'  => $id,
								),
							),
						),
					)
				)
			)
		);
		$uses  = $this->ok( 'kuduy-media-usage', array( 'id' => $id ) )['uses'];
		$where = array_map( static fn( $u ) => $u['where'] . ':' . $u['post_id'], $uses );
		$this->assertContains( 'featured_image:' . $page, $where );
		$this->assertContains( 'content:' . $post, $where );
		$this->assertContains( 'elementor:' . $builder, $where );

		// Regenerate after a size was lost.
		$meta = wp_get_attachment_metadata( $id );
		$this->assertArrayHasKey( 'thumbnail', $meta['sizes'] );
		unset( $meta['sizes']['thumbnail'] );
		wp_update_attachment_metadata( $id, $meta );
		$regen = $this->ok( 'kuduy-media-regenerate', array( 'id' => $id ) );
		$this->assertNotContains( 'thumbnail', $regen['before'] );
		$this->assertContains( 'thumbnail', $regen['after'] );

		// Authors only see uses in posts they can read (the Elementor draft is the admin's).
		wp_set_current_user( self::$author );
		$author_where = array_column( $this->ok( 'kuduy-media-usage', array( 'id' => $id ) )['uses'], 'post_id' );
		$this->assertNotContains( $builder, $author_where );
		$this->fails( 'kuduy-media-regenerate', array( 'id' => $id ), 'authors cannot regenerate others\' images' );
	}

	public function test_upload_refusals(): void {
		$this->download = self::png( 10, 10 );
		$this->fails( 'kuduy-media-upload', array( 'url' => 'http://images.example.com/a.png' ), 'plain HTTP refused' );
		$this->fails( 'kuduy-media-upload', array( 'url' => 'not a url' ), 'not a URL' );
		$this->download = '<?php echo "hi"; ?>';
		$before         = (int) wp_count_posts( 'attachment' )->inherit;
		$message        = $this->fails( 'kuduy-media-upload', array( 'url' => 'https://images.example.com/evil.png' ), 'not an image' );
		$this->assertStringContainsString( 'Nothing was saved', $message );
		$this->assertSame( $before, (int) wp_count_posts( 'attachment' )->inherit );

		$contributor = self::make_user( 'contributor' );
		wp_set_current_user( $contributor );
		$this->download = self::png( 10, 10 );
		$this->fails( 'kuduy-media-upload', array( 'url' => 'https://images.example.com/b.png' ), 'contributors cannot upload' );
	}
}
