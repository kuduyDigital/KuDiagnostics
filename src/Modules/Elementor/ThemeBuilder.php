<?php
/**
 * Tools: kuduy-elementor-theme-templates, kuduy-elementor-theme-conditions (Elementor Pro Theme Builder).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Only Pro's public Theme Builder API (RESEARCH.md §8): Module::instance(), its conditions manager
 * (get_document_conditions, get_conditions_config, get_condition, get_conditions_conflicts, save_conditions) and
 * Theme_Document::get_location(). Where a header or footer appears changes the whole site, so writes need
 * edit_theme_options plus edit_post on the template.
 */
final class ThemeBuilder implements Tool, Snapshottable {

	/**
	 * Theme Builder template types in Elementor Pro 4.3.2 (modules/theme-builder/documents).
	 */
	public const TYPES = array( 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'section' );

	private const MODULE = '\ElementorPro\Modules\ThemeBuilder\Module';

	/**
	 * Mode.
	 *
	 * @param string $mode templates | conditions.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * Elementor Pro's Theme Builder module.
	 *
	 * @return \ElementorPro\Modules\ThemeBuilder\Module
	 * @throws ToolError When Elementor Pro is not active.
	 */
	public static function module(): object {
		if ( true !== self::ready() ) {
			throw new ToolError( 'Theme templates need Elementor Pro (Theme Builder), which is not active on this site.' );
		}
		return \ElementorPro\Modules\ThemeBuilder\Module::instance();
	}

	/**
	 * True when Elementor and Pro's Theme Builder are loaded, else the reason.
	 */
	public static function ready(): bool|string {
		$base = Elementor::available();
		if ( true !== $base ) {
			return $base;
		}
		return class_exists( self::MODULE ) ? true : 'Needs Elementor Pro (Theme Builder).';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-elementor-theme-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'templates' === $this->mode
			? __( 'List Elementor theme templates', 'kuduy-mcp' )
			: __( 'Set where an Elementor theme template appears', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'templates' === $this->mode
			? 'Lists Elementor Pro Theme Builder templates (header, footer, single, archive, 404, …) with their location, display conditions, whether they are live (published with an include condition) and conflicts with other templates of the same location. Create one with kuduy-elementor-create and template_type; edit its content with kuduy-elementor-edit.'
			: 'Replaces the display conditions of a Theme Builder template, e.g. ["include/general"] (entire site), ["include/singular/page"], ["include/singular/page/42"], ["exclude/archive/category/5"]; [] removes them all. Format: include|exclude / condition / sub-condition / object id. Refuses a condition another template of the same location already uses unless allow_conflicts is true. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = 'templates' === $this->mode
			? array(
				'type' => array(
					'type' => 'string',
					'enum' => self::TYPES,
				),
			)
			: array(
				'id'              => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'conditions'      => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array(
						'type'    => 'string',
						'pattern' => '^(include|exclude)/[a-z0-9_-]+(/[a-z0-9_-]+(/[0-9]+)?)?$',
					),
				),
				'allow_conflicts' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'templates' === $this->mode ? array() : array( 'id', 'conditions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$conflicts = array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'template_id'    => array( 'type' => 'integer' ),
					'template_title' => array( 'type' => 'string' ),
					'condition'      => array( 'type' => 'string' ),
				),
			),
		);
		$template  = array(
			'id'         => array( 'type' => 'integer' ),
			'title'      => array( 'type' => 'string' ),
			'type'       => array( 'type' => 'string' ),
			'status'     => array( 'type' => 'string' ),
			'location'   => array( 'type' => 'string' ),
			'conditions' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'live'       => array( 'type' => 'boolean' ),
			'conflicts'  => $conflicts,
			'edit_url'   => array( 'type' => 'string' ),
		);
		if ( 'templates' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'templates' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => $template,
						),
					),
				),
				'required'   => array( 'templates' ),
			);
		}
		return array(
			'type'       => 'object',
			'properties' => $template + array( '_snapshot_id' => array( 'type' => 'integer' ) ),
			'required'   => array( 'id', 'conditions', 'live' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => 'templates' === $this->mode,
			'destructiveHint' => 'conditions' === $this->mode,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'templates' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'templates' === $this->mode ? 'edit_posts' : 'edit_theme_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return self::ready();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$id = (int) ( $input['id'] ?? 0 );
		if ( 'conditions' !== $this->mode || $id <= 0 || ! get_post( $id ) ) {
			return null;
		}
		$saved = get_post_meta( $id, '_elementor_conditions', true );
		return array(
			'object_type' => 'post',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'         => $id,
				'conditions' => is_array( $saved ) ? array_values( array_map( 'strval', $saved ) ) : array(),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the template is gone.
	 */
	public function restore( array $payload ): void {
		$id = (int) ( $payload['id'] ?? 0 );
		if ( ! self::module()->get_document( $id ) ) {
			throw new ToolError( 'The template no longer exists.' );
		}
		$this->store( $id, array_map( 'strval', (array) ( $payload['conditions'] ?? array() ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the template or a condition is invalid, or conflicts.
	 */
	public function execute( array $input ): array {
		return 'templates' === $this->mode ? $this->templates( $input ) : $this->conditions( $input );
	}

	/**
	 * Theme templates the user can edit.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private function templates( array $input ): array {
		$module = self::module();
		$query  = new \WP_Query(
			array(
				'post_type'      => 'elementor_library',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 100,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed meta key, admin tool.
				'meta_query'     => array(
					array(
						'key'     => '_elementor_template_type',
						'value'   => isset( $input['type'] ) ? array( (string) $input['type'] ) : self::TYPES,
						'compare' => 'IN',
					),
				),
			)
		);
		$rows = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$doc = $module->get_document( $post->ID );
			if ( $doc ) {
				$rows[] = $this->describe( $doc );
			}
		}
		return array( 'templates' => $rows );
	}

	/**
	 * Validate, check conflicts, save.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the template or a condition is invalid, or conflicts.
	 */
	private function conditions( array $input ): array {
		$id  = (int) $input['id'];
		$doc = self::module()->get_document( $id );
		if ( ! $doc ) {
			throw new ToolError( 'This post is not an Elementor Theme Builder template. See kuduy-elementor-theme-templates.' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new ToolError( 'Your account cannot edit this template.' );
		}
		AuditLog::target( 'post', (string) $id );
		$conditions = array_values( array_unique( array_map( 'strval', (array) $input['conditions'] ) ) );
		foreach ( $conditions as $condition ) {
			$this->check( $condition );
		}
		$conflicts = $this->conflicts( $id, $conditions );
		if ( $conflicts && empty( $input['allow_conflicts'] ) ) {
			$names = array_map( static fn( $c ) => '"' . $c['template_title'] . '" (id ' . $c['template_id'] . ', ' . $c['condition'] . ')', $conflicts );
			throw new ToolError( 'Another template of this location already uses these conditions: ' . esc_html( implode( '; ', $names ) ) . '. Nothing was changed. Change or clear its conditions first, or pass allow_conflicts: true (Elementor then shows one of them).' );
		}
		$this->store( $id, $conditions );
		$fresh = self::module()->get_document( $id );
		return $this->describe( $fresh ? $fresh : $doc );
	}

	/**
	 * Refuse a condition that Pro does not know: type include/exclude, a top-level condition (general or one of its
	 * sub-conditions), a sub-condition registered under it, a numeric object id.
	 *
	 * @param string $condition e.g. include/singular/page/42.
	 * @throws ToolError When invalid.
	 */
	private function check( string $condition ): void {
		[ $type, $name, $sub_name, $sub_id ] = array_pad( explode( '/', $condition ), 4, '' );
		$config                              = (array) self::module()->get_conditions_manager()->get_conditions_config();
		$top                                 = array_merge( array( 'general' ), array_map( 'strval', (array) ( $config['general']['sub_conditions'] ?? array() ) ) );
		$fail                                = static function ( string $why ) use ( $condition ): void {
			throw new ToolError( 'Condition "' . esc_html( $condition ) . '": ' . esc_html( $why ) . ' Known conditions: ' . esc_html( implode( ', ', array_keys( (array) self::module()->get_conditions_manager()->get_conditions_config() ) ) ) . '.' );
		};
		if ( ! in_array( $type, array( 'include', 'exclude' ), true ) ) {
			$fail( 'must start with include or exclude.' );
		}
		if ( ! in_array( $name, $top, true ) ) {
			$fail( 'unknown condition "' . $name . '".' );
		}
		if ( 'general' === $name && '' !== $sub_name ) {
			$fail( '"general" (entire site) takes no sub-condition; use e.g. include/singular/page.' );
		}
		if ( '' !== $sub_name && ! in_array( $sub_name, self::descendants( $config, $name ), true ) ) {
			$fail( '"' . $sub_name . '" is not a sub-condition of "' . $name . '".' );
		}
		if ( '' !== $sub_id && ( '' === $sub_name || ! ctype_digit( $sub_id ) ) ) {
			$fail( 'the object id must be a number after a sub-condition.' );
		}
	}

	/**
	 * All conditions registered below one condition.
	 *
	 * @param array<string, mixed> $config Conditions config.
	 * @param string               $name   Condition.
	 * @return list<string>
	 */
	private static function descendants( array $config, string $name ): array {
		$found = array();
		$queue = array_map( 'strval', (array) ( $config[ $name ]['sub_conditions'] ?? array() ) );
		while ( $queue ) {
			$next = (string) array_shift( $queue );
			if ( in_array( $next, $found, true ) ) {
				continue;
			}
			$found[] = $next;
			$queue   = array_merge( $queue, array_map( 'strval', (array) ( $config[ $next ]['sub_conditions'] ?? array() ) ) );
		}
		return $found;
	}

	/**
	 * Other templates of the same location using one of these include conditions (Pro's own check).
	 *
	 * @param int          $id         Template id.
	 * @param list<string> $conditions Conditions.
	 * @return list<array{template_id:int, template_title:string, condition:string}>
	 */
	private function conflicts( int $id, array $conditions ): array {
		$manager = self::module()->get_conditions_manager();
		$found   = array();
		foreach ( $conditions as $condition ) {
			if ( ! str_starts_with( $condition, 'include/' ) ) {
				continue;
			}
			foreach ( (array) $manager->get_conditions_conflicts( $id, $condition ) as $conflict ) {
				$found[] = array(
					'template_id'    => (int) $conflict['template_id'],
					'template_title' => wp_strip_all_tags( html_entity_decode( (string) $conflict['template_title'], ENT_QUOTES ) ),
					'condition'      => $condition,
				);
			}
		}
		return $found;
	}

	/**
	 * Save through Pro (it stores the strings and regenerates its conditions cache).
	 *
	 * @param int          $id         Template id.
	 * @param list<string> $conditions Conditions.
	 * @throws ToolError When the stored value differs afterwards.
	 */
	private function store( int $id, array $conditions ): void {
		$rows = array_map( static fn( $c ) => array_pad( explode( '/', $c ), 4, '' ), $conditions );
		// save_conditions() returns false for an unchanged value too, so the stored result is compared instead.
		self::module()->get_conditions_manager()->save_conditions( $id, $rows );
		$saved = get_post_meta( $id, '_elementor_conditions', true );
		$saved = is_array( $saved ) ? array_values( array_map( 'strval', $saved ) ) : array();
		if ( $saved !== $conditions ) {
			throw new ToolError( 'Elementor Pro did not save the conditions.' );
		}
	}

	/**
	 * One template as the tools return it.
	 *
	 * @param object $doc Theme document.
	 * @return array<string, mixed>
	 */
	private function describe( object $doc ): array {
		$post       = $doc->get_post();
		$conditions = array();
		foreach ( (array) self::module()->get_conditions_manager()->get_document_conditions( $doc ) as $row ) {
			$conditions[] = rtrim( implode( '/', array( $row['type'], $row['name'], $row['sub_name'], $row['sub_id'] ) ), '/' );
		}
		$includes = array_values( array_filter( $conditions, static fn( $c ) => str_starts_with( $c, 'include/' ) ) );
		return array(
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'type'       => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
			'status'     => $post->post_status,
			'location'   => (string) $doc->get_location(),
			'conditions' => $conditions,
			'live'       => 'publish' === $post->post_status && (bool) $includes,
			'conflicts'  => $this->conflicts( $post->ID, $includes ),
			'edit_url'   => (string) $doc->get_edit_url(),
		);
	}
}
