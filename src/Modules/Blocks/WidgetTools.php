<?php
/**
 * Tools: kuduy-widgets, kuduy-widget-save, kuduy-widget-remove.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Widget areas and widgets through core's sidebars and widgets controllers (D18 A; edit_theme_options). Settings are
 * written as raw instances only, which core allows for widget types with show_instance_in_rest (block widgets and most
 * core widgets). Remove moves a widget to "Inactive widgets" (core's non-forced delete); nothing is deleted.
 */
final class WidgetTools implements Tool, Snapshottable {

	private const INACTIVE = 'wp_inactive_widgets';

	/**
	 * Mode.
	 *
	 * @param string $mode list | save | remove.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list' === $this->mode ? 'kuduy-widgets' : 'kuduy-widget-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'  => __( 'Widgets', 'kuduy-mcp' ),
			'save'  => __( 'Add or change a widget', 'kuduy-mcp' ),
			default => __( 'Remove a widget', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'  => 'Lists the theme\'s widget areas (sidebars: id, name, description) with their widgets in order: id, type (id_base), settings (for types that expose them; block widgets have content = block markup). Inactive widgets are listed too.',
			'save'  => 'Adds a block widget (no widget_id: sidebar + content as block markup, e.g. "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->") or changes one (widget_id: content for block widgets, settings for other types that expose them, sidebar to move it). Snapshot first for changes; undo with kuduy-snapshot-restore. An added widget is undone with kuduy-widget-remove.',
			default => 'Moves a widget to "Inactive widgets" (it keeps its settings and can be put back). Widgets are never deleted. Snapshot first; undo with kuduy-snapshot-restore puts it back in its area and position.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id         = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
		);
		$properties = array();
		$required   = array();
		if ( 'save' === $this->mode ) {
			$properties = array(
				'widget_id' => $id,
				'sidebar'   => $id,
				'content'   => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
				'settings'  => CoreRest::object_input( 'Widget settings (raw instance), for widget types that expose them.' ),
			);
		} elseif ( 'remove' === $this->mode ) {
			$properties = array( 'widget_id' => $id );
			$required   = array( 'widget_id' );
		}
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
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'sidebars' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
				'required'   => array( 'sidebars' ),
			);
		}
		return array(
			'type'       => 'object',
			'properties' => array(
				'widget'       => array( 'type' => 'object' ),
				'created'      => array( 'type' => 'boolean' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'widget' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'list' === $this->mode ? Content::read_only() : array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'remove' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'list' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_theme_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		global $wp_registered_sidebars;
		$areas = array_diff( array_keys( (array) $wp_registered_sidebars ), array( self::INACTIVE ) );
		return $areas ? true : 'The active theme has no widget areas (block themes use templates and template parts instead)';
	}

	/**
	 * One widget from core, or a ToolError.
	 *
	 * @param string $id Widget id.
	 * @return array<string, mixed>
	 */
	private static function widget( string $id ): array {
		return (array) json_decode( (string) wp_json_encode( CoreRest::call( 'GET', '/wp/v2/widgets/' . rawurlencode( $id ), array( 'context' => 'edit' ) ) ), true );
	}

	/**
	 * A widget in the tool's shape.
	 *
	 * @param array<string, mixed> $widget Core's widget data.
	 * @return array<string, mixed>
	 */
	private static function shape( array $widget ): array {
		$raw = $widget['instance']['raw'] ?? null;
		$out = array(
			'id'      => (string) ( $widget['id'] ?? '' ),
			'type'    => (string) ( $widget['id_base'] ?? '' ),
			'sidebar' => (string) ( $widget['sidebar'] ?? '' ),
		);
		if ( 'block' === $out['type'] && is_array( $raw ) ) {
			$out['content'] = (string) ( $raw['content'] ?? '' );
		} elseif ( is_array( $raw ) ) {
			$out['settings'] = (object) $raw;
		} else {
			$out['settings'] = null;
			$out['note']     = 'This widget type does not expose its settings; change it in Appearance → Widgets.';
		}
		return $out;
	}

	/**
	 * Widget ids of a sidebar, in order.
	 *
	 * @param string $sidebar Sidebar id.
	 * @return list<string>
	 */
	private static function order( string $sidebar ): array {
		$all = wp_get_sidebars_widgets();
		return array_values( array_map( 'strval', (array) ( $all[ $sidebar ] ?? array() ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$id = (string) ( $input['widget_id'] ?? '' );
		if ( 'list' === $this->mode || '' === $id ) {
			// Listing, or adding a widget (undo with kuduy-widget-remove).
			return null;
		}
		$widget  = self::widget( $id );
		$sidebar = (string) ( $widget['sidebar'] ?? '' );
		return array(
			'object_type' => 'widget',
			'object_id'   => $id,
			'payload'     => array(
				'id'       => $id,
				'sidebar'  => $sidebar,
				'order'    => self::order( $sidebar ),
				'instance' => $widget['instance']['raw'] ?? null,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When core refuses.
	 */
	public function restore( array $payload ): void {
		$id      = (string) ( $payload['id'] ?? '' );
		$sidebar = (string) ( $payload['sidebar'] ?? '' );
		$body    = array( 'sidebar' => $sidebar );
		if ( is_array( $payload['instance'] ?? null ) ) {
			$body['instance'] = array( 'raw' => (object) $payload['instance'] );
		}
		CoreRest::call( 'POST', '/wp/v2/widgets/' . rawurlencode( $id ), $body );
		$order = array_values( array_intersect( (array) ( $payload['order'] ?? array() ), array_merge( self::order( $sidebar ), array( $id ) ) ) );
		CoreRest::call( 'POST', '/wp/v2/sidebars/' . rawurlencode( $sidebar ), array( 'widgets' => $order ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When input is incomplete or core refuses.
	 */
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			$widgets = array();
			foreach ( (array) json_decode( (string) wp_json_encode( CoreRest::call( 'GET', '/wp/v2/widgets', array( 'context' => 'edit' ) ) ), true ) as $widget ) {
				$widgets[ (string) ( $widget['id'] ?? '' ) ] = self::shape( (array) $widget );
			}
			$out = array();
			foreach ( (array) CoreRest::call( 'GET', '/wp/v2/sidebars', array( 'context' => 'edit' ) ) as $sidebar ) {
				$sidebar = (array) $sidebar;
				$items   = array();
				foreach ( (array) ( $sidebar['widgets'] ?? array() ) as $wid ) {
					if ( isset( $widgets[ (string) $wid ] ) ) {
						$items[] = $widgets[ (string) $wid ];
					}
				}
				$out[] = array(
					'id'          => (string) ( $sidebar['id'] ?? '' ),
					'name'        => (string) ( $sidebar['name'] ?? '' ),
					'description' => (string) ( $sidebar['description'] ?? '' ),
					'widgets'     => $items,
				);
			}
			return array( 'sidebars' => $out );
		}//end if
		$id = (string) ( $input['widget_id'] ?? '' );
		if ( 'remove' === $this->mode ) {
			AuditLog::target( 'widget', $id );
			self::widget( $id );
			CoreRest::call( 'DELETE', '/wp/v2/widgets/' . rawurlencode( $id ) );
			return array( 'widget' => self::shape( self::widget( $id ) ) );
		}
		if ( '' === $id ) {
			if ( empty( $input['sidebar'] ) || ! isset( $input['content'] ) ) {
				throw new ToolError( 'A new widget needs sidebar and content (block markup). Nothing was added.' );
			}
			$created = (array) json_decode(
				(string) wp_json_encode(
					CoreRest::call(
						'POST',
						'/wp/v2/widgets',
						array(
							'id_base'  => 'block',
							'sidebar'  => (string) $input['sidebar'],
							'instance' => array( 'raw' => array( 'content' => (string) $input['content'] ) ),
						)
					)
				),
				true
			);
			AuditLog::target( 'widget', (string) ( $created['id'] ?? '' ) );
			return array(
				'widget'  => self::shape( $created ),
				'created' => true,
			);
		}//end if
		AuditLog::target( 'widget', $id );
		$current = self::widget( $id );
		$body    = array();
		if ( isset( $input['sidebar'] ) ) {
			$body['sidebar'] = (string) $input['sidebar'];
		}
		if ( isset( $input['content'] ) ) {
			if ( 'block' !== ( $current['id_base'] ?? '' ) ) {
				throw new ToolError( 'content is for block widgets; use settings for this widget type. Nothing was changed.' );
			}
			$body['instance'] = array( 'raw' => array( 'content' => (string) $input['content'] ) );
		} elseif ( isset( $input['settings'] ) ) {
			$body['instance'] = array( 'raw' => (object) array_merge( (array) ( $current['instance']['raw'] ?? array() ), (array) $input['settings'] ) );
		}
		if ( ! $body ) {
			throw new ToolError( 'Give content, settings or sidebar. Nothing was changed.' );
		}
		CoreRest::call( 'POST', '/wp/v2/widgets/' . rawurlencode( $id ), $body );
		return array(
			'widget'  => self::shape( self::widget( $id ) ),
			'created' => false,
		);
	}
}
