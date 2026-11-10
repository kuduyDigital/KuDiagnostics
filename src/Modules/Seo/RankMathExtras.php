<?php
/**
 * Tools: kuduy-seo-redirects, kuduy-seo-redirect-save, kuduy-seo-redirect-trash, kuduy-seo-schema-get,
 * kuduy-seo-schema-save, kuduy-seo-schema-delete, kuduy-seo-sitemap.
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
 * Rank Math redirects and schema, and sitemap status for every supported SEO plugin (decision D25 A; RESEARCH.md §9).
 * Redirects go through Rank Math's own Redirection class and its DB class (the path its Redirections screen uses), need
 * its Redirections module and the rank_math_redirections capability, and are trashed rather than deleted. Schema goes
 * through Rank Math's updateSchemas route (its schema module and the rank_math_onpage_snippet capability are checked
 * there) plus edit_post; undo puts the post's schema rows back by meta id.
 */
final class RankMathExtras implements Tool, Snapshottable {

	private const COMPARISONS = array( 'exact', 'contains', 'start', 'end', 'regex' );
	private const CODES       = array( 301, 302, 307, 410, 451 );

	/**
	 * Mode.
	 *
	 * @param string $mode redirects | redirect-save | redirect-trash | schema-get | schema-save | schema-delete | sitemap.
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
			'redirects'      => __( 'Redirects', 'kuduy-mcp' ),
			'redirect-save'  => __( 'Save a redirect', 'kuduy-mcp' ),
			'redirect-trash' => __( 'Trash a redirect', 'kuduy-mcp' ),
			'schema-get'     => __( 'Read schema of a post', 'kuduy-mcp' ),
			'schema-save'    => __( 'Save schema of a post', 'kuduy-mcp' ),
			'schema-delete'  => __( 'Delete schema of a post', 'kuduy-mcp' ),
			default          => __( 'Sitemap status', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'redirects'      => 'Lists Rank Math redirects: id, sources (pattern + comparison), destination, type (301, 302, 307, 410, 451), status, hits. Filter by status (active, inactive, trashed) or search text.',
			'redirect-save'  => 'Creates a Rank Math redirect (no id) or changes one: sources (list of {pattern, comparison: exact, contains, start, end or regex}; patterns are paths such as "/old-page/"), url_to (destination; not needed for 410/451), header_code, status (active or inactive). Rank Math merges a new redirect with an existing one for the same source. Snapshot first for changes; undo with kuduy-snapshot-restore; undo a new one with kuduy-seo-redirect-trash.',
			'redirect-trash' => 'Moves a Rank Math redirect to its trash (Rank Math empties it after 30 days). Snapshot first; undo with kuduy-snapshot-restore.',
			'schema-get'     => 'Returns the Rank Math schema (structured data) entries of a post: schema id, @type and data.',
			'schema-save'    => 'Adds a Rank Math schema entry to a post (no schema_id) or replaces one (schema_id from kuduy-seo-schema-get). schema is the JSON-LD object with "@type" (e.g. Article, Product, FAQPage) plus a "metadata" object Rank Math uses (e.g. {"title": "Article", "type": "template", "isPrimary": true}). Snapshot first; undo with kuduy-snapshot-restore.',
			'schema-delete'  => 'Deletes one Rank Math schema entry of a post. Snapshot first; undo with kuduy-snapshot-restore.',
			default          => 'Reports the XML sitemap of the active SEO plugin (or WordPress\'s own): whether it is on, its index URL, and for Rank Math the included post types and taxonomies.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id         = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$properties = array();
		$required   = array();
		switch ( $this->mode ) {
			case 'redirects':
				$properties = array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'active', 'inactive', 'trashed', 'any' ),
					),
					'search' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				);
				break;
			case 'redirect-save':
				$properties = array(
					'id'          => $id,
					'sources'     => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 50,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'pattern'    => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 2000,
								),
								'comparison' => array(
									'type' => 'string',
									'enum' => self::COMPARISONS,
								),
							),
							'required'   => array( 'pattern' ),
						),
					),
					'url_to'      => array(
						'type'      => 'string',
						'maxLength' => 2000,
					),
					'header_code' => array(
						'type' => 'integer',
						'enum' => self::CODES,
					),
					'status'      => array(
						'type' => 'string',
						'enum' => array( 'active', 'inactive' ),
					),
				);
				break;
			case 'redirect-trash':
				$properties = array( 'id' => $id );
				$required   = array( 'id' );
				break;
			case 'schema-get':
				$properties = array( 'post_id' => $id );
				$required   = array( 'post_id' );
				break;
			case 'schema-save':
				$properties = array(
					'post_id'   => $id,
					'schema_id' => array(
						'type'    => 'string',
						'pattern' => '^schema-\d+$',
					),
					'schema'    => array( 'type' => 'object' ),
				);
				$required   = array( 'post_id', 'schema' );
				break;
			case 'schema-delete':
				$properties = array(
					'post_id'   => $id,
					'schema_id' => array(
						'type'    => 'string',
						'pattern' => '^schema-\d+$',
					),
				);
				$required   = array( 'post_id', 'schema_id' );
				break;
		}//end switch
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
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
				'redirects'    => array( 'type' => 'array' ),
				'redirect'     => array( 'type' => 'object' ),
				'schemas'      => array( 'type' => 'array' ),
				'total'        => array( 'type' => 'integer' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'redirects', 'schema-get', 'sitemap' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'redirect-trash' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return match ( $this->mode ) {
			'redirects', 'schema-get', 'sitemap' => 'kuduy:read',
			'redirect-save', 'redirect-trash'    => 'kuduy:admin',
			default                              => 'kuduy:write',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( str_starts_with( $this->mode, 'redirect' ) ) {
			return 'rank_math_redirections';
		}
		if ( str_starts_with( $this->mode, 'schema' ) ) {
			$post = Content::post( (int) ( $input['post_id'] ?? 0 ) );
			if ( null === $post ) {
				return 'edit_posts';
			}
			return 'schema-get' === $this->mode ? array( 'read_post', $post->ID ) : array( 'edit_post', $post->ID );
		}
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( 'sitemap' === $this->mode ) {
			return true;
		}
		if ( ! defined( 'RANK_MATH_VERSION' ) || ! class_exists( '\RankMath\Helper' ) ) {
			return 'Rank Math is not active';
		}
		if ( str_starts_with( $this->mode, 'redirect' ) && ( ! class_exists( '\RankMath\Redirections\DB' ) || ! \RankMath\Helper::is_module_active( 'redirections' ) ) ) {
			return 'Rank Math\'s Redirections module is off (Rank Math → Dashboard → Modules)';
		}
		if ( str_starts_with( $this->mode, 'schema' ) && ! \RankMath\Helper::is_module_active( 'rich-snippet' ) ) {
			return 'Rank Math\'s Schema module is off (Rank Math → Dashboard → Modules)';
		}
		return true;
	}

	/**
	 * One redirect row in the tool's shape.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function redirect( array $row ): array {
		$sources = maybe_unserialize( (string) ( $row['sources'] ?? '' ) );
		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'sources'     => is_array( $sources ) ? array_values( $sources ) : array(),
			'url_to'      => (string) ( $row['url_to'] ?? '' ),
			'header_code' => (int) ( $row['header_code'] ?? 301 ),
			'status'      => (string) ( $row['status'] ?? '' ),
			'hits'        => (int) ( $row['hits'] ?? 0 ),
		);
	}

	/**
	 * A redirect row by id, or a ToolError.
	 *
	 * @param int $id Redirect id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is none.
	 */
	private static function find_redirect( int $id ): array {
		$row = \RankMath\Redirections\DB::get_redirection_by_id( $id, 'all' );
		if ( ! is_array( $row ) || ! $row ) {
			throw new ToolError( 'No Rank Math redirect with id ' . (int) $id . '.' );
		}
		return $row;
	}

	/**
	 * Rank Math schema rows of a post: meta id => [ key, value ].
	 *
	 * @param int $post_id Post.
	 * @return array<int, array{0: string, 1: mixed}>
	 */
	private static function schema_rows( int $post_id ): array {
		global $wpdb;
		$like = $wpdb->esc_like( 'rank_math_schema_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading meta ids for an exact undo; values go through maybe_unserialize.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $like ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['meta_id'] ] = array( (string) $row['meta_key'], maybe_unserialize( (string) $row['meta_value'] ) );
		}
		return $out;
	}

	/**
	 * Schemas of a post in the tool's shape.
	 *
	 * @param int $post_id Post.
	 * @return list<array<string, mixed>>
	 */
	private static function schemas( int $post_id ): array {
		$out = array();
		foreach ( self::schema_rows( $post_id ) as $mid => [ $key, $value ] ) {
			$out[] = array(
				'schema_id' => 'schema-' . $mid,
				'type'      => substr( $key, strlen( 'rank_math_schema_' ) ),
				'schema'    => $value,
			);
		}
		return $out;
	}

	/**
	 * Run a Rank Math REST route as the current user.
	 *
	 * @param string               $route  Route under rankmath/v1.
	 * @param array<string, mixed> $params Body.
	 * @return mixed
	 * @throws ToolError When Rank Math refuses.
	 */
	private static function rank_math( string $route, array $params ): mixed {
		$request = new \WP_REST_Request( 'POST', '/rankmath/v1/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'Rank Math refused: ' . wp_strip_all_tags( $response->as_error()->get_error_message() ) . ' Nothing was changed.' );
		}
		return $response->get_data();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'redirect-save' === $this->mode || 'redirect-trash' === $this->mode ) {
			$id = (int) ( $input['id'] ?? 0 );
			if ( $id <= 0 ) {
				return null;
			}
			return array(
				'object_type' => 'rank_math_redirect',
				'object_id'   => (string) $id,
				'payload'     => array(
					'kind' => 'redirect',
					'row'  => self::redirect( self::find_redirect( $id ) ),
				),
			);
		}
		if ( 'schema-save' === $this->mode || 'schema-delete' === $this->mode ) {
			$post_id = (int) $input['post_id'];
			return array(
				'object_type' => 'post',
				'object_id'   => (string) $post_id,
				'payload'     => array(
					'kind'    => 'schema',
					'post_id' => $post_id,
					'rows'    => array_map( static fn( $r ) => array( $r[0], $r[1] ), self::schema_rows( $post_id ) ),
					// Rank Math turns rank_math_rich_snippet off when a schema is deleted; undo puts it back.
					'snippet' => metadata_exists( 'post', $post_id, 'rank_math_rich_snippet' ) ? get_post_meta( $post_id, 'rank_math_rich_snippet', true ) : null,
				),
			);
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		if ( 'redirect' === ( $payload['kind'] ?? '' ) ) {
			$row = (array) $payload['row'];
			\RankMath\Redirections\DB::update(
				array(
					'id'          => (int) $row['id'],
					'sources'     => (array) $row['sources'],
					'url_to'      => (string) $row['url_to'],
					'header_code' => (int) $row['header_code'],
					'status'      => (string) $row['status'],
				)
			);
			return;
		}
		$post_id = (int) ( $payload['post_id'] ?? 0 );
		$saved   = (array) ( $payload['rows'] ?? array() );
		if ( null === ( $payload['snippet'] ?? null ) ) {
			delete_post_meta( $post_id, 'rank_math_rich_snippet' );
		} else {
			update_post_meta( $post_id, 'rank_math_rich_snippet', $payload['snippet'] );
		}
		foreach ( self::schema_rows( $post_id ) as $mid => $row ) {
			if ( ! isset( $saved[ $mid ] ) ) {
				delete_metadata_by_mid( 'post', $mid );
			}
		}
		$current = self::schema_rows( $post_id );
		foreach ( $saved as $mid => [ $key, $value ] ) {
			if ( isset( $current[ (int) $mid ] ) ) {
				update_metadata_by_mid( 'post', (int) $mid, $value, (string) $key );
			} else {
				add_post_meta( $post_id, (string) $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the input is incomplete or Rank Math refuses.
	 */
	public function execute( array $input ): array {
		switch ( $this->mode ) {
			case 'redirects':
				$result = \RankMath\Redirections\DB::get_redirections(
					array(
						'status' => (string) ( $input['status'] ?? 'any' ),
						'search' => (string) ( $input['search'] ?? '' ),
						'paged'  => (int) ( $input['page'] ?? 1 ),
						'limit'  => 50,
					)
				);
				return array(
					'redirects' => array_map( array( self::class, 'redirect' ), $result['redirections'] ),
					'total'     => (int) $result['count'],
				);
			case 'redirect-save':
				return self::save_redirect( $input );
			case 'redirect-trash':
				$id = (int) $input['id'];
				self::find_redirect( $id );
				AuditLog::target( 'rank_math_redirect', (string) $id );
				\RankMath\Redirections\DB::change_status( $id, 'trashed' );
				return array( 'redirect' => self::redirect( self::find_redirect( $id ) ) );
			case 'schema-get':
				return array( 'schemas' => self::schemas( (int) $input['post_id'] ) );
			case 'schema-save':
				$post_id = (int) $input['post_id'];
				$schema  = (array) $input['schema'];
				if ( empty( $schema['@type'] ) ) {
					throw new ToolError( 'The schema needs an "@type" (e.g. Article, Product, FAQPage). Nothing was saved.' );
				}
				$key = isset( $input['schema_id'] ) ? (string) $input['schema_id'] : 'new-' . wp_generate_password( 6, false );
				if ( isset( $input['schema_id'] ) && ! in_array( $key, array_column( self::schemas( $post_id ), 'schema_id' ), true ) ) {
					throw new ToolError( 'Post ' . (int) $post_id . ' has no schema ' . esc_html( $key ) . '. Nothing was saved.' );
				}
				AuditLog::target( 'post', (string) $post_id );
				self::rank_math(
					'updateSchemas',
					array(
						'objectID'   => $post_id,
						'objectType' => 'post',
						'schemas'    => array( $key => $schema ),
					)
				);
				return array( 'schemas' => self::schemas( $post_id ) );
			case 'schema-delete':
				$post_id = (int) $input['post_id'];
				$key     = (string) $input['schema_id'];
				if ( ! in_array( $key, array_column( self::schemas( $post_id ), 'schema_id' ), true ) ) {
					throw new ToolError( 'Post ' . (int) $post_id . ' has no schema ' . esc_html( $key ) . '. Nothing was deleted.' );
				}
				AuditLog::target( 'post', (string) $post_id );
				self::rank_math(
					'updateMeta',
					array(
						'objectID'   => $post_id,
						'objectType' => 'post',
						'meta'       => array( 'rank_math_delete_' . $key => '' ),
					)
				);
				return array( 'schemas' => self::schemas( $post_id ) );
			default:
				return self::sitemap();
		}//end switch
	}

	/**
	 * Create or change a redirect with Rank Math's Redirection class.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the input is incomplete or would loop.
	 */
	private static function save_redirect( array $input ): array {
		$id   = (int) ( $input['id'] ?? 0 );
		$data = $id > 0 ? self::redirect( self::find_redirect( $id ) ) : array(
			'sources'     => array(),
			'url_to'      => '',
			'header_code' => 301,
			'status'      => 'active',
		);
		foreach ( array( 'sources', 'url_to', 'header_code', 'status' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$data[ $key ] = $input[ $key ];
			}
		}
		$code = (int) $data['header_code'];
		if ( ! in_array( $code, array( 410, 451 ), true ) && '' === trim( (string) $data['url_to'] ) ) {
			throw new ToolError( 'A ' . (int) $code . ' redirect needs url_to. Nothing was saved.' );
		}
		$redirection = \RankMath\Redirections\Redirection::from(
			array(
				'id'          => $id,
				'sources'     => (array) $data['sources'],
				'url_to'      => (string) $data['url_to'],
				'header_code' => $code,
				'status'      => (string) $data['status'],
			)
		);
		if ( ! $redirection->has_sources() ) {
			throw new ToolError( 'Give at least one source pattern. Nothing was saved.' );
		}
		if ( $redirection->is_infinite_loop() ) {
			throw new ToolError( 'The destination is the same as a source, which would loop. Nothing was saved.' );
		}
		$saved = (int) $redirection->save();
		if ( $saved <= 0 ) {
			throw new ToolError( 'Rank Math did not save the redirect.' );
		}
		AuditLog::target( 'rank_math_redirect', (string) $saved );
		return array( 'redirect' => self::redirect( self::find_redirect( $saved ) ) );
	}

	/**
	 * Sitemap status of the active SEO plugin or WordPress.
	 *
	 * @return array<string, mixed>
	 */
	private static function sitemap(): array {
		$plugins = Seo::detected();
		if ( isset( $plugins['rank-math'] ) && function_exists( 'wp_get_ability' ) && wp_get_ability( 'rank-math/get-sitemap-status' ) ) {
			return array( 'plugin' => 'rank-math' ) + Seo::ability( 'rank-math/get-sitemap-status', array() );
		}
		if ( isset( $plugins['yoast'] ) && class_exists( 'WPSEO_Options' ) ) {
			$on = (bool) \WPSEO_Options::get( 'enable_xml_sitemap' );
			return array(
				'plugin'  => 'yoast',
				'enabled' => $on,
				'url'     => $on ? home_url( '/sitemap_index.xml' ) : null,
			);
		}
		if ( $plugins ) {
			return array(
				'plugin'  => (string) array_key_first( $plugins ),
				'enabled' => null,
				'note'    => 'This SEO plugin\'s sitemap settings are not read by KuDiagnostics; check its sitemap screen.',
			);
		}
		$server = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server() : null;
		$on     = $server && $server->sitemaps_enabled();
		return array(
			'plugin'  => 'core',
			'enabled' => $on,
			'url'     => $on ? home_url( '/wp-sitemap.xml' ) : null,
		);
	}
}
