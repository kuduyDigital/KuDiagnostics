<?php
/**
 * Tools: kuduy-cache-status, kuduy-cache-purge.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Cache;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Page-cache plugins and the object cache (D19 A). Purging removes cached copies only, never content, so there is
 * no snapshot. Purging a whole site or a URL needs manage_options (the cache plugins' own admin screens do); purging
 * one post needs edit_post on it. Flushing the persistent object cache (wp_cache_flush()) needs manage_options.
 */
final class CacheTools implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode status | purge.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-cache-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'status' === $this->mode ? __( 'Cache status', 'kuduy-mcp' ) : __( 'Purge cache', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'status' === $this->mode
			? 'Shows the page-cache plugins found (LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, WP Rocket) with versions, whether a persistent object cache is in use, and whether page caching is on in wp-config (WP_CACHE).'
			: 'Purges cached pages so visitors see a change: target "all" (whole site), "post" (one post: post_id) or "url" (one address on this site), in every cache plugin found or in plugin only. object: true also flushes the persistent object cache (whole site). Purging removes cached copies only, never content. Whole-site and URL purges and object-cache flushes need site-admin rights; one post needs permission to edit it.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		if ( 'status' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'target'  => array(
					'type' => 'string',
					'enum' => array( 'all', 'post', 'url' ),
				),
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'url'     => array(
					'type'      => 'string',
					'format'    => 'uri',
					'maxLength' => 2000,
				),
				'plugin'  => array(
					'type' => 'string',
					'enum' => array_keys( Cache::PLUGINS ),
				),
				'object'  => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( 'target' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		return 'status' === $this->mode
			? array(
				'type'       => 'object',
				'properties' => array(
					'plugins'      => $list,
					'object_cache' => array( 'type' => 'boolean' ),
					'wp_cache'     => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'plugins', 'object_cache', 'wp_cache' ),
			)
			: array(
				'type'       => 'object',
				'properties' => array(
					'purged'         => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'object_flushed' => array( 'type' => 'boolean' ),
					'note'           => array( 'type' => 'string' ),
				),
				'required'   => array( 'purged', 'object_flushed' ),
			);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'status' === $this->mode ? Content::read_only() : array(
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'status' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( 'status' === $this->mode ) {
			return 'edit_posts';
		}
		$post = Content::post( (int) ( $input['post_id'] ?? 0 ) );
		if ( 'post' === ( $input['target'] ?? '' ) && empty( $input['object'] ) && null !== $post ) {
			return array( 'edit_post', $post->ID );
		}
		return 'manage_options';
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
	 * @throws ToolError When the target is incomplete, outside the site, or nothing can be purged.
	 */
	public function execute( array $input ): array {
		if ( 'status' === $this->mode ) {
			$plugins = array();
			foreach ( Cache::detected() as $id ) {
				$plugins[] = array(
					'plugin'  => $id,
					'name'    => Cache::PLUGINS[ $id ],
					'version' => Cache::version( $id ),
				);
			}
			return array(
				'plugins'      => $plugins,
				'object_cache' => (bool) wp_using_ext_object_cache(),
				'wp_cache'     => defined( 'WP_CACHE' ) && WP_CACHE,
			);
		}
		$target = (string) $input['target'];
		$post   = 0;
		$url    = '';
		if ( 'post' === $target ) {
			$found = Content::post( (int) ( $input['post_id'] ?? 0 ) );
			if ( null === $found ) {
				throw new ToolError( 'target "post" needs the post_id of an existing post.' );
			}
			$post = $found->ID;
			AuditLog::target( 'post', (string) $post );
		} elseif ( 'url' === $target ) {
			$url  = esc_url_raw( (string) ( $input['url'] ?? '' ) );
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== $home ) {
				throw new ToolError( 'target "url" needs an address on this site (' . esc_html( (string) $home ) . ').' );
			}
		}
		$plugins = isset( $input['plugin'] ) ? array( (string) $input['plugin'] ) : Cache::detected();
		$purged  = array();
		foreach ( $plugins as $id ) {
			if ( ! Cache::active( $id ) ) {
				throw new ToolError( esc_html( Cache::PLUGINS[ $id ] ?? $id ) . ' is not active on this site. Nothing was purged.' );
			}
			Cache::purge( $id, $target, $post, $url );
			$purged[] = $id;
		}
		$flushed = false;
		if ( ! empty( $input['object'] ) ) {
			$flushed = wp_cache_flush();
		}
		if ( ! $purged && ! $flushed ) {
			throw new ToolError( 'No page-cache plugin is active on this site, so there is nothing to purge (use object: true to flush the object cache).' );
		}
		$out = array(
			'purged'         => $purged,
			'object_flushed' => $flushed,
		);
		if ( in_array( 'litespeed', $purged, true ) ) {
			$out['note'] = 'LiteSpeed Cache purges through the web server at the end of this request; on hosts without a LiteSpeed server nothing is cached by it.';
		}
		return $out;
	}
}
