<?php
/**
 * Tools: kuduy-media-usage, kuduy-media-upload, kuduy-media-regenerate.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Media;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Where an image is used, adding an image from an HTTPS URL, and regenerating its sizes — core APIs only:
 * download_url() (wp_safe_remote_get: no private or loopback addresses) + media_handle_sideload() (core's upload
 * checks and allowed MIME types), wp_generate_attachment_metadata() for sizes.
 */
final class MediaLibrary implements Tool {

	private const MAX_UPLOAD = 10485760;
	private const IMAGES     = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );

	/**
	 * Mode.
	 *
	 * @param string $mode usage | upload | regenerate.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-media-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'usage'  => __( 'Find where an image is used', 'kuduy-mcp' ),
			'upload' => __( 'Add an image from a URL', 'kuduy-mcp' ),
			default  => __( 'Regenerate image sizes', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'usage'  => 'Lists where a media item is used: as featured image, in post content (image block class or file URL), in Elementor data (by id or URL), in WooCommerce product galleries, as site icon or logo. Only posts your account can read are listed. Matching by URL is a text search and can miss images resized through other URLs.',
			'upload' => 'Downloads an image (JPEG, PNG, GIF, WebP, AVIF; at most 10 MB) from a public HTTPS URL into the media library, optionally attached to a post, with title and alt text. The site refuses private and local addresses. Only upload images the user is allowed to use.',
			default  => 'Regenerates the thumbnail sizes of one image from its original file (after changing image sizes or when a size is missing). The original file is kept.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = 'upload' === $this->mode
			? array(
				'url'       => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'title'     => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'alt'       => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'attach_to' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			)
			: array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'upload' === $this->mode ? array( 'url' ) : array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return match ( $this->mode ) {
			'usage'  => array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array( 'type' => 'integer' ),
					'uses' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'where'   => array( 'type' => 'string' ),
								'post_id' => array( 'type' => array( 'integer', 'null' ) ),
								'title'   => array( 'type' => 'string' ),
								'type'    => array( 'type' => 'string' ),
								'status'  => array( 'type' => 'string' ),
							),
						),
					),
				),
				'required'   => array( 'id', 'uses' ),
			),
			'upload' => array(
				'type'       => 'object',
				'properties' => array(
					'id'        => array( 'type' => 'integer' ),
					'url'       => array( 'type' => 'string' ),
					'mime_type' => array( 'type' => 'string' ),
					'width'     => array( 'type' => array( 'integer', 'null' ) ),
					'height'    => array( 'type' => array( 'integer', 'null' ) ),
				),
				'required'   => array( 'id', 'url', 'mime_type' ),
			),
			default  => array(
				'type'       => 'object',
				'properties' => array(
					'id'     => array( 'type' => 'integer' ),
					'before' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'after'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'id', 'before', 'after' ),
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => 'usage' === $this->mode,
			'destructiveHint' => false,
			'idempotentHint'  => 'upload' !== $this->mode,
			'openWorldHint'   => 'upload' === $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'usage' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( 'upload' === $this->mode ) {
			return isset( $input['attach_to'] ) ? array( 'edit_post', (int) $input['attach_to'] ) : 'upload_files';
		}
		if ( ! isset( $input['id'] ) ) {
			return 'upload_files';
		}
		return array( 'usage' === $this->mode ? 'read_post' : 'edit_post', (int) $input['id'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the item, URL or file is not usable.
	 */
	public function execute( array $input ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( 'upload' === $this->mode ) {
			return $this->upload( $input );
		}
		$post = get_post( (int) $input['id'] );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			throw new ToolError( 'No media item with this id.' );
		}
		AuditLog::target( 'post', (string) $post->ID );
		return 'usage' === $this->mode ? $this->usage( $post ) : $this->regenerate( $post );
	}

	/**
	 * Places that use the item.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array<string, mixed>
	 */
	private function usage( \WP_Post $post ): array {
		global $wpdb;
		$id    = $post->ID;
		$file  = (string) get_post_meta( $id, '_wp_attached_file', true );
		$stem  = '' === $file ? '' : preg_replace( '/\.[A-Za-z0-9]+$/', '', $file );
		$found = array();
		$add   = static function ( string $where, int $post_id ) use ( &$found ): void {
			$found[ $where . ':' . $post_id ] = array( $where, $post_id );
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only, prepared, capped searches; no core API searches content.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 200", (string) $id ) ) as $pid ) {
			$add( 'featured_image', (int) $pid );
		}
		$like_class = '%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%';
		$like_file  = '' === $stem ? $like_class : '%' . $wpdb->esc_like( '/' . $stem ) . '%';
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_status <> 'auto-draft' AND (post_content LIKE %s OR post_content LIKE %s) LIMIT 200", $like_class, $like_file ) ) as $pid ) {
			$add( 'content', (int) $pid );
		}
		$like_id     = '%' . $wpdb->esc_like( '"id":' . $id . ',' ) . '%';
		$like_id_end = '%' . $wpdb->esc_like( '"id":' . $id . '}' ) . '%';
		$like_url    = '' === $stem ? $like_id : '%' . $wpdb->esc_like( str_replace( '/', '\\/', '/' . $stem ) ) . '%';
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s) LIMIT 200", $like_id, $like_id_end, $like_url ) ) as $pid ) {
			$add( 'elementor', (int) $pid );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value LIKE %s LIMIT 500", '%' . $wpdb->esc_like( (string) $id ) . '%' ) ) as $row ) {
			if ( in_array( (string) $id, array_map( 'trim', explode( ',', (string) $row->meta_value ) ), true ) ) {
				$add( 'product_gallery', (int) $row->post_id );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		$uses = array();
		foreach ( $found as [ $where, $pid ] ) {
			$item = get_post( $pid );
			if ( $item instanceof \WP_Post && current_user_can( 'read_post', $pid ) ) {
				$uses[] = array(
					'where'   => $where,
					'post_id' => $pid,
					'title'   => get_the_title( $item ),
					'type'    => $item->post_type,
					'status'  => $item->post_status,
				);
			}
		}
		foreach ( array(
			'site_icon'   => (int) get_option( 'site_icon' ),
			'custom_logo' => (int) get_theme_mod( 'custom_logo' ),
		) as $where => $value ) {
			if ( $value === $id ) {
				$uses[] = array(
					'where'   => $where,
					'post_id' => null,
					'title'   => '',
					'type'    => 'site',
					'status'  => '',
				);
			}
		}
		return array(
			'id'   => $id,
			'uses' => $uses,
		);
	}

	/**
	 * Sideload an image from an HTTPS URL.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the URL, download or file is refused.
	 */
	private function upload( array $input ): array {
		$url = (string) $input['url'];
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			throw new ToolError( 'url must be an absolute https:// URL.' );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new ToolError( 'Your account cannot upload files.' );
		}
		$parent = (int) ( $input['attach_to'] ?? 0 );
		if ( $parent && ! get_post( $parent ) ) {
			throw new ToolError( 'attach_to is not an existing post.' );
		}
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'The image could not be downloaded: ' . $tmp->get_error_message() );
		}
		$size = (int) filesize( $tmp );
		$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$type = wp_check_filetype_and_ext( $tmp, '' === $name ? 'image' : $name );
		$mime = (string) $type['type'];
		if ( '' === $mime ) {
			$real = wp_get_image_mime( $tmp );
			$mime = is_string( $real ) ? $real : '';
		}
		if ( $size <= 0 || $size > self::MAX_UPLOAD || ! in_array( $mime, self::IMAGES, true ) ) {
			wp_delete_file( $tmp );
			throw new ToolError( 'Only JPEG, PNG, GIF, WebP and AVIF images up to 10 MB can be added. Nothing was saved.' );
		}
		$ext = (string) wp_get_default_extension_for_mime_type( $mime );
		if ( '' === $name || ! preg_match( '/\.' . preg_quote( $ext, '/' ) . '$/i', $name ) ) {
			$name = ( '' === $name ? 'image' : preg_replace( '/\.[^.]*$/', '', $name ) ) . '.' . $ext;
		}
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			$parent,
			isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : null
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WordPress refused the file: ' . $id->get_error_message() );
		}
		AuditLog::target( 'post', (string) $id );
		if ( isset( $input['alt'] ) && '' !== trim( (string) $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $input['alt'] ) ) );
		}
		$meta = wp_get_attachment_metadata( $id );
		return array(
			'id'        => $id,
			'url'       => (string) wp_get_attachment_url( $id ),
			'mime_type' => (string) get_post_mime_type( $id ),
			'width'     => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'    => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : null,
		);
	}

	/**
	 * Regenerate sizes from the original.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array<string, mixed>
	 * @throws ToolError When it is not an image or the file is missing.
	 */
	private function regenerate( \WP_Post $post ): array {
		if ( ! wp_attachment_is_image( $post ) ) {
			throw new ToolError( 'Only images have sizes to regenerate.' );
		}
		$file = wp_get_original_image_path( $post->ID );
		if ( ! $file || ! file_exists( $file ) ) {
			throw new ToolError( 'The original image file is missing on the server.' );
		}
		$before = array_keys( (array) ( wp_get_attachment_metadata( $post->ID )['sizes'] ?? array() ) );
		$meta   = wp_generate_attachment_metadata( $post->ID, $file );
		if ( ! $meta ) {
			throw new ToolError( 'WordPress could not regenerate the image sizes.' );
		}
		wp_update_attachment_metadata( $post->ID, $meta );
		return array(
			'id'     => $post->ID,
			'before' => $before,
			'after'  => array_keys( (array) ( $meta['sizes'] ?? array() ) ),
		);
	}
}
