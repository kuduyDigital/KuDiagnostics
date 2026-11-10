<?php
/**
 * Tools: kuduy-media-replace, kuduy-media-rename (decision D26 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Media;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Replace an image's file and rename an attachment's files, both with undo.
 *
 * Replace keeps the attachment id and its file name, so every link, gallery and featured image keeps working; the new
 * image comes over HTTPS through core's download_url() (no private or loopback addresses), must be the same type as the
 * old one, up to 10 MB, and core regenerates the sizes. Rename changes the file name of the original and all sizes and
 * WordPress's records, and refuses while the file is linked by URL in post content or Elementor data (links by id —
 * featured images, galleries — keep working). Before either change the current files are copied to
 * uploads/kuduy-backups/ for undo; the cleanup job removes backups after the snapshot retention.
 */
final class MediaFiles implements Tool, Snapshottable {

	public const BACKUP_DIR = 'kuduy-backups';

	private const MAX_BYTES = 10485760;
	private const IMAGES    = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );

	/**
	 * Mode.
	 *
	 * @param string $mode replace | rename.
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
		return 'replace' === $this->mode ? __( 'Replace an image', 'kuduy-mcp' ) : __( 'Rename a media file', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'replace' === $this->mode
			? 'Replaces the file of an image in the media library with a new image from an https:// URL, keeping the same attachment and file name, so every page, gallery and featured image shows the new image. The new image must be the same type (e.g. JPEG for JPEG), up to 10 MB; WordPress makes the sizes again. Snapshot first; undo with kuduy-snapshot-restore puts the old file back. Browsers and caches may show the old image until they refresh (purge with kuduy-cache-purge).'
			: 'Renames the file of a media library item (original and all sizes), e.g. for SEO: name is the new file name without extension ("red-running-shoes"). Refused while the file is linked by URL in post content or Elementor (see kuduy-media-usage); links by id such as featured images keep working. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( 'replace' === $this->mode ) {
			$properties['url'] = array(
				'type'      => 'string',
				'format'    => 'uri',
				'maxLength' => 2000,
			);
		} else {
			$properties['name'] = array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 120,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'id', 'replace' === $this->mode ? 'url' : 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'url'          => array( 'type' => 'string' ),
				'file'         => array( 'type' => 'string' ),
				'width'        => array( 'type' => array( 'integer', 'null' ) ),
				'height'       => array( 'type' => array( 'integer', 'null' ) ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'url', 'file' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'rename' === $this->mode,
			'openWorldHint'   => 'replace' === $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$post = get_post( (int) ( $input['id'] ?? 0 ) );
		return $post && 'attachment' === $post->post_type ? array( 'edit_post', $post->ID ) : 'upload_files';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * An image attachment's absolute file paths: original (if scaled), main file and sizes.
	 *
	 * @param int $id Attachment.
	 * @return array{main: string, all: list<string>}
	 * @throws ToolError When it is not an attachment with a file.
	 */
	private static function files( int $id ): array {
		$post = get_post( $id );
		$main = $post && 'attachment' === $post->post_type ? (string) get_attached_file( $id ) : '';
		if ( '' === $main || ! is_file( $main ) ) {
			throw new ToolError( 'No media library item with a file for id ' . (int) $id . '.' );
		}
		$dir  = dirname( $main );
		$meta = wp_get_attachment_metadata( $id );
		$all  = array( $main );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) ) {
				$all[] = $dir . '/' . $meta['original_image'];
			}
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$all[] = $dir . '/' . $size['file'];
				}
			}
		}
		return array(
			'main' => $main,
			'all'  => array_values( array_unique( array_filter( $all, 'is_file' ) ) ),
		);
	}

	/**
	 * Folder for this call's backup.
	 *
	 * @param int $id Attachment.
	 * @throws ToolError When it cannot be created.
	 */
	private static function backup_dir( int $id ): string {
		$uploads = wp_upload_dir();
		$base    = trailingslashit( (string) $uploads['basedir'] ) . self::BACKUP_DIR;
		$dir     = $base . '/' . $id . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new ToolError( 'The backup folder could not be created, so nothing was changed.' );
		}
		if ( ! is_file( $base . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- silence directory listing.
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the item has no file or the backup fails.
	 */
	public function snapshot( array $input ): array {
		$id    = (int) $input['id'];
		$files = self::files( $id );
		$this->precheck( $id, $input );
		$dir   = self::backup_dir( $id );
		$saved = array();
		foreach ( $files['all'] as $path ) {
			$copy = $dir . '/' . basename( $path );
			if ( ! copy( $path, $copy ) ) {
				throw new ToolError( 'The current file could not be backed up, so nothing was changed.' );
			}
			$saved[ $path ] = $copy;
		}
		return array(
			'object_type' => 'attachment',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'       => $id,
				'attached' => (string) get_post_meta( $id, '_wp_attached_file', true ),
				'meta'     => wp_get_attachment_metadata( $id ),
				'mime'     => (string) get_post_mime_type( $id ),
				'files'    => $saved,
				'backup'   => $dir,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the backup is gone.
	 */
	public function restore( array $payload ): void {
		$id = (int) ( $payload['id'] ?? 0 );
		if ( ! get_post( $id ) ) {
			throw new ToolError( 'The media item no longer exists.' );
		}
		$saved = (array) ( $payload['files'] ?? array() );
		foreach ( $saved as $copy ) {
			if ( ! is_file( (string) $copy ) ) {
				throw new ToolError( 'The backup of the old file is gone (backups are kept for the snapshot retention).' );
			}
		}
		// Remove the current files first (a rename put them under other names), then put the old ones back.
		try {
			foreach ( self::files( $id )['all'] as $path ) {
				if ( ! isset( $saved[ $path ] ) ) {
					wp_delete_file( $path );
				}
			}
		} catch ( ToolError $e ) {
			// No current file: nothing to remove.
			unset( $e );
		}
		foreach ( $saved as $path => $copy ) {
			copy( (string) $copy, (string) $path );
		}
		update_post_meta( $id, '_wp_attached_file', (string) $payload['attached'] );
		wp_update_attachment_metadata( $id, (array) $payload['meta'] );
		wp_update_post(
			array(
				'ID'             => $id,
				'post_mime_type' => (string) $payload['mime'],
			)
		);
		self::remove_backup( (string) ( $payload['backup'] ?? '' ) );
	}

	/**
	 * Delete one backup folder (only inside uploads/kuduy-backups).
	 *
	 * @param string $dir Folder.
	 */
	public static function remove_backup( string $dir ): void {
		$uploads = wp_upload_dir();
		$base    = (string) realpath( trailingslashit( (string) $uploads['basedir'] ) . self::BACKUP_DIR );
		$real    = (string) realpath( $dir );
		if ( '' === $base || '' === $real || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return;
		}
		foreach ( (array) glob( $real . '/*' ) as $file ) {
			if ( is_file( (string) $file ) ) {
				wp_delete_file( (string) $file );
			}
		}
		rmdir( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- own backup folder.
	}

	/**
	 * Remove backup folders older than the given number of days (cleanup job).
	 *
	 * @param int $days Days.
	 */
	public static function prune_backups( int $days ): void {
		$uploads = wp_upload_dir();
		$base    = trailingslashit( (string) $uploads['basedir'] ) . self::BACKUP_DIR;
		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( filemtime( (string) $dir ) < time() - $days * DAY_IN_SECONDS ) {
				self::remove_backup( (string) $dir );
			}
		}
	}

	/**
	 * Refuse before anything is backed up or changed.
	 *
	 * @param int                  $id    Attachment.
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the request would be refused.
	 */
	private function precheck( int $id, array $input ): void {
		if ( 'replace' === $this->mode ) {
			$url = (string) $input['url'];
			if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				throw new ToolError( 'url must be an absolute https:// URL. Nothing was changed.' );
			}
			if ( ! in_array( (string) get_post_mime_type( $id ), self::IMAGES, true ) ) {
				throw new ToolError( 'Only images (JPEG, PNG, GIF, WebP, AVIF) can be replaced. Nothing was changed.' );
			}
			return;
		}
		$slug = sanitize_title( (string) preg_replace( '/\.[a-z0-9]{2,5}$/i', '', (string) $input['name'] ) );
		$stem = (string) preg_replace( '/-scaled$/', '', pathinfo( self::files( $id )['main'], PATHINFO_FILENAME ) );
		if ( '' === $slug || $stem === $slug ) {
			throw new ToolError( 'The new name is empty or the same as the current one. Nothing was changed.' );
		}
		$linked = self::linked_by_url( $stem );
		if ( $linked ) {
			throw new ToolError( 'The file is linked by its address in ' . count( $linked ) . ' post(s) (' . esc_html( implode( ', ', array_slice( $linked, 0, 10 ) ) ) . '), which a rename would break. Nothing was changed.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the request is refused or WordPress fails.
	 */
	public function execute( array $input ): array {
		$id = (int) $input['id'];
		AuditLog::target( 'post', (string) $id );
		return 'replace' === $this->mode ? self::replace( $id, (string) $input['url'] ) : self::rename( $id, (string) $input['name'] );
	}

	/**
	 * Replace the image file, keeping its name.
	 *
	 * @param int    $id  Attachment.
	 * @param string $url New image URL.
	 * @return array<string, mixed>
	 * @throws ToolError When the URL, download or type is refused.
	 */
	private static function replace( int $id, string $url ): array {
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			throw new ToolError( 'url must be an absolute https:// URL. Nothing was changed.' );
		}
		$old_mime = (string) get_post_mime_type( $id );
		if ( ! in_array( $old_mime, self::IMAGES, true ) ) {
			throw new ToolError( 'Only images (JPEG, PNG, GIF, WebP, AVIF) can be replaced. Nothing was changed.' );
		}
		$files = self::files( $id );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'The image could not be downloaded: ' . $tmp->get_error_message() . ' Nothing was changed.' );
		}
		$size = (int) filesize( $tmp );
		$mime = wp_get_image_mime( $tmp );
		if ( $size <= 0 || $size > self::MAX_BYTES || $mime !== $old_mime ) {
			wp_delete_file( $tmp );
			throw new ToolError( 'The new image must be a ' . esc_html( $old_mime ) . ' file up to 10 MB, like the one it replaces. Nothing was changed.' );
		}
		$meta     = wp_get_attachment_metadata( $id );
		$original = is_array( $meta ) && ! empty( $meta['original_image'] ) ? dirname( $files['main'] ) . '/' . $meta['original_image'] : $files['main'];
		foreach ( $files['all'] as $path ) {
			wp_delete_file( $path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- move the checked download into place.
		if ( ! rename( $tmp, $original ) ) {
			wp_delete_file( $tmp );
			throw new ToolError( 'WordPress could not write the new file. Undo with kuduy-snapshot-restore to put the old one back.' );
		}
		update_attached_file( $id, $original );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $original ) );
		return self::result( $id );
	}

	/**
	 * Rename the files of an attachment.
	 *
	 * @param int    $id   Attachment.
	 * @param string $name New name without extension.
	 * @return array<string, mixed>
	 * @throws ToolError When the name is taken, the file is linked by URL, or a rename fails.
	 */
	private static function rename( int $id, string $name ): array {
		$slug = sanitize_title( preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $name ) );
		if ( '' === $slug ) {
			throw new ToolError( 'The new name has no usable characters. Nothing was changed.' );
		}
		$files = self::files( $id );
		$dir   = dirname( $files['main'] );
		$stem  = (string) preg_replace( '/-scaled$/', '', pathinfo( $files['main'], PATHINFO_FILENAME ) );
		if ( $stem === $slug ) {
			throw new ToolError( 'The file already has that name. Nothing was changed.' );
		}
		$linked = self::linked_by_url( $stem );
		if ( $linked ) {
			throw new ToolError( 'The file is linked by its address in ' . count( $linked ) . ' post(s) (' . esc_html( implode( ', ', array_slice( $linked, 0, 10 ) ) ) . '), which a rename would break. Nothing was changed.' );
		}
		$moves = array();
		foreach ( $files['all'] as $path ) {
			$target = $dir . '/' . $slug . substr( basename( $path ), strlen( $stem ) );
			if ( file_exists( $target ) ) {
				throw new ToolError( 'A file named ' . esc_html( basename( $target ) ) . ' already exists. Nothing was changed.' );
			}
			$moves[ $path ] = $target;
		}
		foreach ( $moves as $from => $to ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- renaming within the uploads folder.
			if ( ! rename( $from, $to ) ) {
				throw new ToolError( 'Renaming ' . esc_html( basename( $from ) ) . ' failed. Undo with kuduy-snapshot-restore to put the files back.' );
			}
		}
		$meta    = wp_get_attachment_metadata( $id );
		$renamed = static fn( string $file ) => $slug . substr( $file, strlen( $stem ) );
		if ( is_array( $meta ) ) {
			if ( isset( $meta['file'] ) ) {
				$meta['file'] = dirname( (string) $meta['file'] ) . '/' . $renamed( basename( (string) $meta['file'] ) );
				$meta['file'] = ltrim( str_replace( './', '', $meta['file'] ), '/' );
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$meta['original_image'] = $renamed( (string) $meta['original_image'] );
			}
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $key => $size ) {
				$meta['sizes'][ $key ]['file'] = $renamed( (string) $size['file'] );
			}
			wp_update_attachment_metadata( $id, $meta );
		}
		update_attached_file( $id, $moves[ $files['main'] ] );
		return self::result( $id );
	}

	/**
	 * Posts whose content or Elementor data contains the file name (as part of an address).
	 *
	 * @param string $stem File name without extension or size suffix.
	 * @return list<int>
	 */
	private static function linked_by_url( string $stem ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '/' . $stem ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- usage scan, as kuduy-media-usage.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND post_status <> 'auto-draft' AND post_content LIKE %s LIMIT 50", $like ) );
		$els = (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s LIMIT 50", '%' . $wpdb->esc_like( '\/' . $stem ) . '%' ) );
		// phpcs:enable
		return array_values( array_unique( array_map( 'intval', array_merge( $ids, $els ) ) ) );
	}

	/**
	 * Result shape.
	 *
	 * @param int $id Attachment.
	 * @return array<string, mixed>
	 */
	private static function result( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		return array(
			'id'     => $id,
			'url'    => (string) wp_get_attachment_url( $id ),
			'file'   => (string) get_post_meta( $id, '_wp_attached_file', true ),
			'width'  => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height' => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : null,
		);
	}
}
