<?php
/**
 * Tool: kuduy-seo-audit.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Which posts lack their own SEO title, description or focus keyword, read through the active SEO plugin's adapter
 * (so the plugin's own storage and permission rules apply). Paged, newest first, only posts the user can edit.
 */
final class SeoAudit implements Tool {

	private const CHECKS = array( 'title', 'description', 'focus_keyword' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-seo-audit';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Find posts missing SEO data', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Lists published posts of one type that have no SEO title, description or focus keyword of their own in the active SEO plugin (the plugin\'s template then applies). Paged; fix them with kuduy-seo-update, or many at once with kuduy-job-start.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'      => 'string',
					'default'   => 'post',
					'maxLength' => 20,
				),
				'missing'   => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'string',
						'enum' => self::CHECKS,
					),
					'minItems' => 1,
					'default'  => self::CHECKS,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
				),
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
			),
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
				'plugin'      => array( 'type' => 'string' ),
				'checked'     => array( 'type' => 'integer' ),
				'posts'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array( 'type' => 'integer' ),
							'title'   => array( 'type' => 'string' ),
							'url'     => array( 'type' => 'string' ),
							'missing' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'total'       => array( 'type' => 'integer' ),
				'total_pages' => array( 'type' => 'integer' ),
				'page'        => array( 'type' => 'integer' ),
			),
			'required'   => array( 'plugin', 'checked', 'posts', 'total', 'total_pages', 'page' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Seo::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post type or SEO plugin is not usable.
	 */
	public function execute( array $input ): array {
		$type    = Content::post_type( (string) ( $input['post_type'] ?? 'post' ) );
		$adapter = Seo::adapter();
		$checks  = array_values( array_intersect( self::CHECKS, (array) ( $input['missing'] ?? self::CHECKS ), $adapter->fields() ) );
		$page    = (int) ( $input['page'] ?? 1 );
		$query   = new \WP_Query(
			array(
				'post_type'      => $type->name,
				'post_status'    => 'publish',
				'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$posts   = array();
		$checked = 0;
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			++$checked;
			$stored  = $adapter->stored( $post->ID );
			$missing = array_values( array_filter( $checks, static fn( string $field ): bool => null === ( $stored[ $field ] ?? null ) ) );
			if ( $missing ) {
				$posts[] = array(
					'id'      => $post->ID,
					'title'   => get_the_title( $post ),
					'url'     => (string) get_permalink( $post ),
					'missing' => $missing,
				);
			}
		}
		return array(
			'plugin'      => $adapter->slug(),
			'checked'     => $checked,
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}
}
