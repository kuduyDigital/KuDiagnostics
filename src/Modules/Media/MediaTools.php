<?php
/**
 * Tools: kuduy-media-list, kuduy-media-get, kuduy-media-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Media;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Media library items through core APIs only (RESEARCH.md §9): alt text is the core meta `_wp_attachment_image_alt`
 * (what wp_get_attachment_image() prints, written like core's REST attachments controller), title, caption and
 * description are the attachment's post_title, post_excerpt and post_content (wp_update_post(), so kses applies to
 * users without unfiltered_html). kuduy-media-get can include a downscaled copy of an image as MCP image content, so
 * alt text is written from what the image shows.
 */
final class MediaTools implements Tool, Snapshottable {

	private const ALT        = '_wp_attachment_image_alt';
	private const MAX_SIDE   = 1024;
	private const MAX_BYTES  = 1572864;
	private const RASTER     = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	private const MIME_TYPES = array( 'image', 'video', 'audio', 'application', 'any' );

	/**
	 * Mode.
	 *
	 * @param string $mode list | get | update.
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
			'list'  => __( 'List media', 'kuduy-mcp' ),
			'get'   => __( 'Read a media item', 'kuduy-mcp' ),
			default => __( 'Change a media item\'s text', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'  => 'Lists media library items (images by default) with alt text, size and file name; filter by type, search, unattached, or images missing alt text ("missing_alt": no alt set; decorative images with an intentionally empty alt are not missing). Paged.',
			'get'   => 'Returns one media item: file, MIME type, dimensions, sizes, alt text (null = not set, "" = decorative), title, caption, description and the post it is attached to. With include_image: true, also returns the image itself (downscaled to at most 1024 px) so you can see it; look at it before writing alt text.',
			default => 'Changes a media item\'s alt text, title, caption or description. Write alt text only from what the image actually shows (use kuduy-media-get with include_image first); never invent it from the file name. decorative: true marks a purely decorative image (empty alt, so screen readers skip it). alt null removes the alt text. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id         = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$properties = match ( $this->mode ) {
			'list'  => array(
				'mime_type'   => array(
					'type'    => 'string',
					'enum'    => self::MIME_TYPES,
					'default' => 'image',
				),
				'search'      => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'missing_alt' => array( 'type' => 'boolean' ),
				'unattached'  => array( 'type' => 'boolean' ),
			) + Content::paging_input(),
			'get'   => $id + array(
				'include_image' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			default => $id + array(
				'alt'         => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 500,
				),
				'decorative'  => array( 'type' => 'boolean' ),
				'title'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'caption'     => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 10000,
				),
			),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'list' === $this->mode ? array() : array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$item = array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'file'        => array( 'type' => 'string' ),
				'mime_type'   => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'alt'         => array( 'type' => array( 'string', 'null' ) ),
				'decorative'  => array( 'type' => 'boolean' ),
				'width'       => array( 'type' => array( 'integer', 'null' ) ),
				'height'      => array( 'type' => array( 'integer', 'null' ) ),
				'filesize'    => array( 'type' => array( 'integer', 'null' ) ),
				'attached_to' => array( 'type' => array( 'integer', 'null' ) ),
				'date'        => array( 'type' => 'string' ),
			),
		);
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'items'       => array(
						'type'  => 'array',
						'items' => $item,
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'page'        => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
				),
				'required'   => array( 'items', 'total', 'total_pages', 'page', 'per_page' ),
			);
		}
		$item['properties'] += array(
			'caption'     => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'sizes'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'image'       => array( 'type' => array( 'string', 'null' ) ),
		);
		if ( 'update' === $this->mode ) {
			$item['properties']['changed']      = array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			);
			$item['properties']['_snapshot_id'] = array( 'type' => 'integer' );
		}
		$item['required'] = array( 'id', 'mime_type', 'alt', 'decorative' );
		return $item;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => 'update' !== $this->mode,
			'destructiveHint' => 'update' === $this->mode,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'update' === $this->mode ? 'kuduy:write' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( 'list' === $this->mode || ! isset( $input['id'] ) ) {
			return 'upload_files';
		}
		return array( 'get' === $this->mode ? 'read_post' : 'edit_post', (int) $input['id'] );
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
	 */
	public function snapshot( array $input ): ?array {
		$post = 'update' === $this->mode ? self::attachment( (int) ( $input['id'] ?? 0 ) ) : null;
		if ( ! $post ) {
			return null;
		}
		return array(
			'object_type' => 'post',
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'id'          => $post->ID,
				'alt'         => metadata_exists( 'post', $post->ID, self::ALT ) ? (string) get_post_meta( $post->ID, self::ALT, true ) : null,
				'title'       => $post->post_title,
				'caption'     => $post->post_excerpt,
				'description' => $post->post_content,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the item is gone.
	 */
	public function restore( array $payload ): void {
		$post = self::attachment( (int) ( $payload['id'] ?? 0 ) );
		if ( ! $post ) {
			throw new ToolError( 'The media item no longer exists.' );
		}
		self::write_text( $post->ID, (string) $payload['title'], (string) $payload['caption'], (string) $payload['description'] );
		self::write_alt( $post->ID, isset( $payload['alt'] ) ? (string) $payload['alt'] : null );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the item is missing or the input contradicts itself.
	 */
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			return $this->items( $input );
		}
		$post = self::attachment( (int) $input['id'] );
		if ( ! $post ) {
			throw new ToolError( 'No media item with this id.' );
		}
		AuditLog::target( 'post', (string) $post->ID );
		$changed = array();
		if ( 'update' === $this->mode ) {
			$changed = $this->update( $post, $input );
			$post    = self::attachment( $post->ID ) ?? $post;
		}
		$out = self::describe( $post ) + array(
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'sizes'       => array_keys( (array) ( wp_get_attachment_metadata( $post->ID )['sizes'] ?? array() ) ),
			'image'       => null,
		);
		if ( 'update' === $this->mode ) {
			$out['changed'] = $changed;
		}
		if ( 'get' === $this->mode && ! empty( $input['include_image'] ) ) {
			[ $note, $block ] = self::image_block( $post );
			$out['image']     = $note;
			if ( $block ) {
				$out['_content'] = array( $block );
			}
		}
		return $out;
	}

	/**
	 * Apply an update.
	 *
	 * @param \WP_Post             $post  Attachment.
	 * @param array<string, mixed> $input Arguments.
	 * @return list<string>
	 * @throws ToolError When nothing is sent, or decorative contradicts alt.
	 */
	private function update( \WP_Post $post, array $input ): array {
		$changed = array_values( array_intersect( array( 'alt', 'decorative', 'title', 'caption', 'description' ), array_keys( $input ) ) );
		if ( ! $changed ) {
			throw new ToolError( 'Send at least one of alt, decorative, title, caption, description.' );
		}
		$decorative = ! empty( $input['decorative'] );
		if ( $decorative && isset( $input['alt'] ) && '' !== trim( (string) $input['alt'] ) ) {
			throw new ToolError( 'A decorative image has an empty alt text; send either decorative: true or alt, not both. Nothing was changed.' );
		}
		if ( array_key_exists( 'alt', $input ) || array_key_exists( 'decorative', $input ) ) {
			if ( ! wp_attachment_is_image( $post ) ) {
				throw new ToolError( 'Alt text applies to images only. Nothing was changed.' );
			}
		}
		if ( isset( $input['title'] ) || isset( $input['caption'] ) || isset( $input['description'] ) ) {
			self::write_text(
				$post->ID,
				(string) ( $input['title'] ?? $post->post_title ),
				(string) ( $input['caption'] ?? $post->post_excerpt ),
				(string) ( $input['description'] ?? $post->post_content )
			);
		}
		if ( $decorative ) {
			self::write_alt( $post->ID, '' );
		} elseif ( array_key_exists( 'alt', $input ) ) {
			self::write_alt( $post->ID, null === $input['alt'] ? null : (string) $input['alt'] );
		} elseif ( array_key_exists( 'decorative', $input ) && '' === get_post_meta( $post->ID, self::ALT, true ) ) {
			// decorative: false on a decorative image: back to "no alt set".
			self::write_alt( $post->ID, null );
		}
		return $changed;
	}

	/**
	 * Title, caption, description through wp_update_post().
	 *
	 * @param int    $id          Attachment id.
	 * @param string $title       Title.
	 * @param string $caption     Caption.
	 * @param string $description Description.
	 * @throws ToolError When WordPress refuses.
	 */
	private static function write_text( int $id, string $title, string $caption, string $description ): void {
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_title'   => $title,
					'post_excerpt' => $caption,
					'post_content' => $description,
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WordPress refused the change: ' . $result->get_error_message() );
		}
	}

	/**
	 * Alt text: null removes it, '' marks the image decorative. Sanitized like core's REST attachments controller.
	 *
	 * @param int         $id  Attachment id.
	 * @param string|null $alt Alt text.
	 */
	private static function write_alt( int $id, ?string $alt ): void {
		if ( null === $alt ) {
			delete_post_meta( $id, self::ALT );
			return;
		}
		update_post_meta( $id, self::ALT, wp_slash( sanitize_text_field( $alt ) ) );
	}

	/**
	 * Paged list.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private function items( array $input ): array {
		$page     = (int) ( $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$mime     = (string) ( $input['mime_type'] ?? 'image' );
		$args     = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( 'any' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		if ( ! empty( $input['unattached'] ) ) {
			$args['post_parent'] = 0;
		}
		if ( ! empty( $input['missing_alt'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one meta key, admin tool.
			$args['meta_query'] = array(
				array(
					'key'     => self::ALT,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && current_user_can( 'read_post', $post->ID ) ) {
				$items[] = self::describe( $post );
			}
		}
		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Attachment by id.
	 *
	 * @param int $id Id.
	 */
	private static function attachment( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && 'attachment' === $post->post_type ? $post : null;
	}

	/**
	 * Summary of one item.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array<string, mixed>
	 */
	private static function describe( \WP_Post $post ): array {
		$meta = wp_get_attachment_metadata( $post->ID );
		$meta = is_array( $meta ) ? $meta : array();
		$alt  = metadata_exists( 'post', $post->ID, self::ALT ) ? (string) get_post_meta( $post->ID, self::ALT, true ) : null;
		$file = get_attached_file( $post->ID );
		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'file'        => $file ? wp_basename( $file ) : '',
			'mime_type'   => $post->post_mime_type,
			'url'         => (string) wp_get_attachment_url( $post->ID ),
			'alt'         => $alt,
			'decorative'  => '' === $alt,
			'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'filesize'    => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : null,
			'attached_to' => $post->post_parent ? (int) $post->post_parent : null,
			'date'        => (string) get_post_time( 'c', true, $post ),
		);
	}

	/**
	 * A copy of the image Claude can look at: the largest stored size within MAX_SIDE, else a downscaled copy.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array{0:string, 1:array<string, string>|null} Note and MCP image block (or null).
	 */
	private static function image_block( \WP_Post $post ): array {
		if ( ! in_array( $post->post_mime_type, self::RASTER, true ) ) {
			return array( 'Only JPEG, PNG, GIF and WebP images can be shown.', null );
		}
		$path = self::fitting_file( $post->ID );
		if ( null === $path ) {
			$path = self::downscaled( $post->ID );
		}
		$data = null !== $path && is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload file.
		if ( str_starts_with( (string) $path, get_temp_dir() ) ) {
			wp_delete_file( (string) $path );
		}
		if ( false === $data || '' === $data ) {
			return array( 'The image file could not be read on the server.', null );
		}
		if ( strlen( $data ) > self::MAX_BYTES ) {
			return array( 'The image is too large to show (over 1.5 MB even when downscaled).', null );
		}
		$mime = (string) wp_check_filetype( $path )['type'];
		return array(
			'Included below (' . wp_basename( $path ) . ').',
			array(
				'type'     => 'image',
				'data'     => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- MCP image content is base64.
				'mimeType' => in_array( $mime, self::RASTER, true ) ? $mime : $post->post_mime_type,
			),
		);
	}

	/**
	 * Path of the largest stored version no larger than MAX_SIDE, or null.
	 *
	 * @param int $id Attachment id.
	 */
	private static function fitting_file( int $id ): ?string {
		$file = get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );
		if ( ! $file || ! is_array( $meta ) ) {
			return null;
		}
		$best = null;
		$area = 0;
		$all  = (array) ( $meta['sizes'] ?? array() );
		if ( isset( $meta['width'], $meta['height'] ) ) {
			$all['full'] = array(
				'file'   => wp_basename( $file ),
				'width'  => $meta['width'],
				'height' => $meta['height'],
			);
		}
		foreach ( $all as $size ) {
			$w = (int) $size['width'];
			$h = (int) $size['height'];
			if ( max( $w, $h ) <= self::MAX_SIDE && $w * $h > $area ) {
				$area = $w * $h;
				$best = path_join( dirname( $file ), (string) $size['file'] );
			}
		}
		return $best;
	}

	/**
	 * A temporary downscaled copy, or null when the image editor cannot make one.
	 *
	 * @param int $id Attachment id.
	 */
	private static function downscaled( int $id ): ?string {
		$file = get_attached_file( $id );
		if ( ! $file ) {
			return null;
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) || is_wp_error( $editor->resize( self::MAX_SIDE, self::MAX_SIDE ) ) ) {
			return null;
		}
		$saved = $editor->save( trailingslashit( get_temp_dir() ) . 'kuduy-' . wp_generate_password( 12, false ) . '-' . wp_basename( $file ) );
		return is_wp_error( $saved ) ? null : $saved['path'];
	}
}
