<?php
/**
 * Tools: kuduy-seo-status, kuduy-seo-get, kuduy-seo-update.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Post SEO data in one normalized shape whichever SEO plugin the site uses (decision D8). Writes go through the
 * active plugin's own API, need edit_post plus the plugin's own SEO capabilities, are refused when two SEO plugins
 * are active, and can be undone.
 */
final class SeoTools implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode status | get | update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-seo-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'status' => __( 'SEO plugin status', 'kuduy-mcp' ),
			'get'    => __( 'Read a post\'s SEO data', 'kuduy-mcp' ),
			default  => __( 'Change a post\'s SEO data', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'status' => 'Which SEO plugin the site uses (Rank Math, Yoast SEO, All in One SEO, SEOPress), its version, whether several are active (then SEO changes are refused) and which SEO fields KUDUY can change through it.',
			'get'    => 'Returns a post\'s SEO data through the active SEO plugin: "stored" = what was set for this post (null = not set, the plugin\'s default or template applies) and "rendered" = what the plugin outputs (title, description, canonical, robots, Open Graph, X), when the plugin lets your account read it.',
			default  => 'Changes a post\'s SEO data through the active SEO plugin\'s own API: title, description, focus_keyword, additional_keywords, canonical, cornerstone, robots (noindex true/false/null = post type default; nofollow, noarchive, nosnippet, noimageindex), og and twitter title/description. Only the fields you send change; null clears a field so the plugin\'s default applies. Refused when several SEO plugins are active. With Rank Math, setting any robots flag replaces the global robots defaults for that post (as in its editor). Snapshot first; undo with kuduy-snapshot-restore. Check the result with kuduy-seo-get.',
		};
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
		$properties = array(
			'post_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		if ( 'update' === $this->mode ) {
			$text       = static fn( int $max ) => array(
				'type'      => array( 'string', 'null' ),
				'maxLength' => $max,
			);
			$social     = array(
				'type'                 => 'object',
				'properties'           => array(
					'title'       => $text( 200 ),
					'description' => $text( 500 ),
				),
				'additionalProperties' => false,
			);
			$properties = array_merge(
				$properties,
				array(
					'title'               => $text( 200 ),
					'description'         => $text( 500 ),
					'focus_keyword'       => $text( 100 ),
					'additional_keywords' => array(
						'type'     => 'array',
						'maxItems' => 20,
						'items'    => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
					),
					'canonical'           => array(
						'type'      => array( 'string', 'null' ),
						'format'    => 'uri',
						'maxLength' => 2000,
					),
					'cornerstone'         => array( 'type' => 'boolean' ),
					'robots'              => array(
						'type'                 => 'object',
						'properties'           => array(
							'noindex'      => array( 'type' => array( 'boolean', 'null' ) ),
							'nofollow'     => array( 'type' => 'boolean' ),
							'noarchive'    => array( 'type' => 'boolean' ),
							'nosnippet'    => array( 'type' => 'boolean' ),
							'noimageindex' => array( 'type' => 'boolean' ),
						),
						'additionalProperties' => false,
					),
					'og'                  => $social,
					'twitter'             => $social,
				)
			);
		}//end if
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		if ( 'status' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'plugins'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slug'    => array( 'type' => 'string' ),
								'name'    => array( 'type' => 'string' ),
								'version' => array( 'type' => 'string' ),
							),
						),
					),
					'active'   => array( 'type' => array( 'string', 'null' ) ),
					'conflict' => array( 'type' => 'boolean' ),
					'fields'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'notes'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'   => array( 'plugins', 'active', 'conflict', 'fields', 'notes' ),
			);
		}//end if
		$properties = array(
			'post_id'  => array( 'type' => 'integer' ),
			'plugin'   => array( 'type' => 'string' ),
			'stored'   => array( 'type' => 'object' ),
			'rendered' => array( 'type' => array( 'object', 'null' ) ),
		);
		if ( 'update' === $this->mode ) {
			$properties['changed']      = array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			);
			$properties['_snapshot_id'] = array( 'type' => 'integer' );
		}
		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => array( 'post_id', 'plugin', 'stored', 'rendered' ),
		);
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
		if ( 'status' === $this->mode || ! isset( $input['post_id'] ) ) {
			return 'edit_posts';
		}
		return array( 'edit_post', (int) $input['post_id'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return 'status' === $this->mode ? true : Seo::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$id = (int) ( $input['post_id'] ?? 0 );
		if ( 'update' !== $this->mode || ! Content::post( $id ) ) {
			return null;
		}
		try {
			$adapter = Seo::adapter();
		} catch ( ToolError ) {
			// No snapshot: execute() refuses with the reason.
			return null;
		}
		return array(
			'object_type' => 'post',
			'object_id'   => (string) $id,
			'payload'     => array(
				'post_id' => $id,
				'plugin'  => $adapter->slug(),
				'state'   => $adapter->snapshot( $id ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the SEO plugin changed or the post is gone.
	 */
	public function restore( array $payload ): void {
		$adapter = Seo::adapter();
		if ( $adapter->slug() !== ( $payload['plugin'] ?? '' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'This change was made with ' . Seo::name( (string) ( $payload['plugin'] ?? '' ) ) . ', which is no longer the active SEO plugin.' );
		}
		$id = (int) ( $payload['post_id'] ?? 0 );
		if ( ! Content::post( $id ) ) {
			throw new ToolError( 'The post no longer exists.' );
		}
		$adapter->restore( $id, (array) ( $payload['state'] ?? array() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post, plugin or fields are not usable.
	 */
	public function execute( array $input ): array {
		if ( 'status' === $this->mode ) {
			return $this->status();
		}
		$id   = (int) $input['post_id'];
		$post = Content::post( $id );
		if ( ! $post ) {
			throw new ToolError( 'No post, page or custom post type entry with this id.' );
		}
		AuditLog::target( 'post', (string) $id );
		$adapter = Seo::adapter();
		$changed = array();
		if ( 'update' === $this->mode ) {
			$changed = $this->update( $adapter, $id, $input );
		}
		$result             = array(
			'post_id'  => $id,
			'plugin'   => $adapter->slug(),
			'stored'   => Seo::nest( $adapter->stored( $id ) ),
			'rendered' => null,
		);
		$rendered           = $adapter->rendered( $id );
		$result['rendered'] = null === $rendered ? null : Seo::nest( $rendered );
		if ( 'update' === $this->mode ) {
			$result['changed'] = $changed;
		}
		return $result;
	}

	/**
	 * Validate and apply a patch.
	 *
	 * @param SeoAdapter           $adapter Adapter.
	 * @param int                  $id      Post id.
	 * @param array<string, mixed> $input   Arguments.
	 * @return list<string> Changed fields.
	 * @throws ToolError When nothing is sent, a field is unsupported or a capability is missing.
	 */
	private function update( SeoAdapter $adapter, int $id, array $input ): array {
		$patch = Seo::flatten( $input );
		if ( ! $patch ) {
			throw new ToolError( 'Send at least one SEO field to change.' );
		}
		$canonical = $patch['canonical'] ?? null;
		// Core's schema validator does not check format "uri".
		if ( null !== $canonical && ( false === filter_var( $canonical, FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( (string) $canonical, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
			throw new ToolError( 'canonical must be an absolute http(s) URL. Nothing was changed.' );
		}
		$unsupported = array_diff( array_keys( $patch ), $adapter->fields() );
		if ( $unsupported ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( Seo::name( $adapter->slug() ) . ' cannot store: ' . implode( ', ', $unsupported ) . '. Nothing was changed.' );
		}
		$missing = $adapter->missing_caps( $patch );
		if ( $missing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( Seo::name( $adapter->slug() ) . ' does not let your account change these fields (missing capability: ' . implode( ', ', $missing ) . '). Nothing was changed.' );
		}
		$adapter->write( $id, $patch );
		return array_keys( $patch );
	}

	/**
	 * Active SEO plugins.
	 *
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$found   = Seo::detected();
		$plugins = array();
		foreach ( $found as $slug => $plugin ) {
			$plugins[] = array( 'slug' => $slug ) + $plugin;
		}
		$notes  = array();
		$active = null;
		$fields = array();
		if ( ! $found ) {
			$notes[] = 'No supported SEO plugin is active.';
		} elseif ( count( $found ) > 1 ) {
			$notes[] = 'Several SEO plugins are active, so SEO changes are refused until only one is active.';
		} else {
			try {
				$adapter = Seo::adapter();
				$active  = $adapter->slug();
				$fields  = $adapter->fields();
			} catch ( ToolError $e ) {
				$notes[] = $e->getMessage();
			}
		}
		$visibility = Seo::visibility_note();
		if ( null !== $visibility ) {
			$notes[] = $visibility;
		}
		return array(
			'plugins'  => $plugins,
			'active'   => $active,
			'conflict' => count( $found ) > 1,
			'fields'   => $fields,
			'notes'    => $notes,
		);
	}
}
