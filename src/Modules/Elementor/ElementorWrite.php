<?php
/**
 * Tools: kuduy-elementor-edit, kuduy-elementor-create, kuduy-elementor-kit-update (one class, three modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Every save goes through Elementor's Document::save() (permission check, kses for users without unfiltered_html,
 * hooks, version, CSS reset). Edits are patches: settings not named stay as they are, other breakpoints included.
 * All operations of a call are checked first and saved once; one failing operation saves nothing.
 */
final class ElementorWrite implements Tool, Snapshottable {

	private const MAX_OPERATIONS = 50;

	/**
	 * Mode.
	 *
	 * @param string $mode edit | create | kit-update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-elementor-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'edit'   => __( 'Edit an Elementor page', 'kuduy-mcp' ),
			'create' => __( 'Create an Elementor page', 'kuduy-mcp' ),
			default  => __( 'Update Elementor global styles', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'edit'   => 'Applies 1–50 operations to one Elementor page and saves once: update (set settings, unset keys; only the named keys change, so other breakpoints keep their values), add (new element or widget under a parent or at the top, at an index), move, duplicate (fresh ids), remove. Settings are checked against the widget schema first (kuduy-elementor-widget-schema). If any operation fails, nothing is saved. Refuses pages with widgets whose plugin is inactive (Elementor would drop them). Snapshot first; undo with kuduy-snapshot-restore. Check the result with kuduy-elementor-verify.',
			'create' => 'Creates a page or post built with Elementor (draft by default) from an element tree, e.g. a container with heading, text and button widgets. Optional page template "elementor_canvas" (no header/footer) or "elementor_header_footer". With template_type (needs Elementor Pro) it creates a Theme Builder template instead (header, footer, single-post, archive, error-404, …); set where it appears with kuduy-elementor-theme-conditions.',
			default  => 'Changes the active Kit\'s global colors and fonts: update existing ones by id (see kuduy-elementor-kit), add custom ones (no id), or remove custom ones. Elementor then rebuilds the site\'s CSS files. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$element  = array(
			'type'        => 'object',
			'description' => 'Element: {"elType": "container"|"section"|"column"|"widget"|v4 type, "widgetType": for widgets, "settings": {...}, "elements": [children]}.',
			'properties'  => array(
				'elType'     => array( 'type' => 'string' ),
				'widgetType' => array( 'type' => 'string' ),
				'settings'   => array( 'type' => 'object' ),
				'elements'   => array( 'type' => 'array' ),
			),
			'required'    => array( 'elType' ),
		);
		$elements = array(
			'type'     => 'array',
			'items'    => $element,
			'maxItems' => 200,
		);
		$schema   = match ( $this->mode ) {
			'edit'   => array(
				'properties' => array(
					'id'         => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'operations' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => self::MAX_OPERATIONS,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'action'     => array(
									'type' => 'string',
									'enum' => array( 'update', 'add', 'move', 'duplicate', 'remove' ),
								),
								'element_id' => array(
									'type'        => 'string',
									'description' => 'Target element (update, move, duplicate, remove).',
								),
								'settings'   => array(
									'type'        => 'object',
									'description' => 'update: keys to set, e.g. {"title": "Hi", "align_mobile": "center"}.',
								),
								'unset'      => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => 'update: keys to remove (falls back to the default / inherited value).',
								),
								'parent_id'  => array(
									'type'        => 'string',
									'description' => 'add/move: parent element id, or "document" for the top level.',
								),
								'index'      => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
								'element'    => $element,
							),
							'required'   => array( 'action' ),
						),
					),
				),
				'required'   => array( 'id', 'operations' ),
			),
			'create' => array(
				'properties' => array(
					'title'         => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'post_type'     => array(
						'type'    => 'string',
						'enum'    => array( 'page', 'post' ),
						'default' => 'page',
					),
					'status'        => array(
						'type'    => 'string',
						'enum'    => array( 'draft', 'publish', 'pending', 'private' ),
						'default' => 'draft',
					),
					'template'      => array(
						'type' => 'string',
						'enum' => array( 'default', 'elementor_canvas', 'elementor_header_footer' ),
					),
					'template_type' => array(
						'type'        => 'string',
						'enum'        => ThemeBuilder::TYPES,
						'description' => 'Elementor Pro Theme Builder template type; post_type and template are then ignored.',
					),
					'elements'      => $elements,
				),
				'required'   => array( 'title' ),
			),
			default  => array(
				'properties' => array(
					'colors' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'    => array( 'type' => 'string' ),
								'title' => array( 'type' => 'string' ),
								'color' => array(
									'type'    => 'string',
									'pattern' => '^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$',
								),
							),
						),
					),
					'fonts'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'     => array( 'type' => 'string' ),
								'title'  => array( 'type' => 'string' ),
								'family' => array( 'type' => 'string' ),
								'weight' => array( 'type' => 'string' ),
								'size'   => array(
									'type'        => 'number',
									'minimum'     => 1,
									'description' => 'Font size in px.',
								),
							),
						),
					),
					'remove' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Ids of custom colors or fonts to remove (system ones cannot be removed).',
					),
				),
			),
		};//end match
		return array( 'type' => 'object' ) + $schema + array( 'additionalProperties' => false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'            => array( 'type' => 'integer' ),
				'element_count' => array( 'type' => 'integer' ),
				'changed'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'link'          => array( 'type' => 'string' ),
				'edit_url'      => array( 'type' => 'string' ),
				'_snapshot_id'  => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'changed' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'create' !== $this->mode,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kit-update' === $this->mode ? 'kuduy:admin' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		return match ( $this->mode ) {
			'edit'   => (int) ( $input['id'] ?? 0 ) > 0 && get_post( (int) $input['id'] ) ? array( 'edit_post', (int) $input['id'] ) : 'edit_posts',
			'create' => ! empty( $input['template_type'] ) ? 'edit_theme_options' : ( Content::post_types()[ $input['post_type'] ?? 'page' ] ?? null )?->cap->create_posts ?? 'edit_posts',
			default  => 'manage_options',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Elementor::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'create' === $this->mode ) {
			return null;
		}
		$id = 'edit' === $this->mode ? (int) $input['id'] : (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		if ( $id <= 0 || ! get_post( $id ) ) {
			return null;
		}
		return array(
			'object_type' => 'edit' === $this->mode ? 'post' : 'elementor_kit',
			'object_id'   => (string) $id,
			'payload'     => array(
				'id'       => $id,
				'data'     => (string) get_post_meta( $id, '_elementor_data', true ),
				'settings' => get_post_meta( $id, '_elementor_page_settings', true ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the document is gone or Elementor refuses.
	 */
	public function restore( array $payload ): void {
		$doc      = Elementor::document( (int) ( $payload['id'] ?? 0 ), false );
		$data     = array();
		$elements = json_decode( (string) ( $payload['data'] ?? '' ), true );
		if ( is_array( $elements ) ) {
			$data['elements'] = $elements;
		}
		$settings         = $payload['settings'] ?? array();
		$data['settings'] = is_array( $settings ) ? $settings : array();
		if ( ! $doc->save( $data ) ) {
			throw new ToolError( 'Elementor refused to save the previous version.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When an operation is invalid or Elementor refuses.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'edit'   => $this->edit( $input ),
			'create' => $this->create( $input ),
			default  => $this->kit( $input ),
		};
	}

	/**
	 * Apply operations and save once.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When an operation is invalid or Elementor refuses.
	 */
	private function edit( array $input ): array {
		$doc = Elementor::document( (int) $input['id'] );
		AuditLog::target( 'post', (string) $doc->get_main_id() );
		$elements = Elementor::elements( $doc );
		$missing  = Elementor::unregistered( $elements );
		if ( $missing ) {
			throw new ToolError( 'This page uses widgets whose plugin is not active (' . esc_html( implode( ', ', $missing ) ) . '). Elementor would delete them on save, so nothing was changed. Activate the plugin first.' );
		}
		$tree    = new Tree( $elements );
		$changed = array();
		foreach ( array_values( (array) $input['operations'] ) as $n => $op ) {
			try {
				$changed[] = $this->apply( $tree, (array) $op );
			} catch ( ToolError $e ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON text; the inner message is escaped where it is built.
				throw new ToolError( sprintf( 'Operation %d (%s): %s Nothing was saved.', $n + 1, esc_html( (string) ( $op['action'] ?? '' ) ), $e->getMessage() ) );
			}
		}
		return $this->save( $doc, $tree, $changed );
	}

	/**
	 * One operation.
	 *
	 * @param Tree                 $tree Tree.
	 * @param array<string, mixed> $op   Operation.
	 * @return string What changed.
	 * @throws ToolError When the operation is invalid.
	 */
	private function apply( Tree $tree, array $op ): string {
		$action = (string) ( $op['action'] ?? '' );
		$id     = (string) ( $op['element_id'] ?? '' );
		if ( 'add' !== $action && '' === $id ) {
			throw new ToolError( 'element_id is required.' );
		}
		$parent = static fn(): ?string => isset( $op['parent_id'] ) && 'document' !== $op['parent_id'] ? (string) $op['parent_id'] : null;
		$index  = isset( $op['index'] ) ? (int) $op['index'] : null;

		switch ( $action ) {
			case 'update':
				$element = $tree->get( $id );
				$set     = (array) ( $op['settings'] ?? array() );
				$type    = Elementor::type_object( Elementor::type( $element ) );
				if ( null !== $type && $set ) {
					Elementor::check_settings( $type, $set );
				}
				$settings = array_merge( (array) ( $element['settings'] ?? array() ), $set );
				foreach ( (array) ( $op['unset'] ?? array() ) as $key ) {
					unset( $settings[ (string) $key ] );
				}
				// Elementor's sanitize_settings() requires an array (an empty object is refused).
				$element['settings'] = $settings;
				$tree->replace( $element );
				return 'updated ' . $id;
			case 'add':
				$new = $this->build( $tree, (array) ( $op['element'] ?? array() ) );
				$tree->insert( $new, $parent(), $index );
				return 'added ' . $new['id'];
			case 'move':
				[ $old_parent ] = $tree->locate( $id );
				$element        = $tree->remove( $id );
				$tree->insert( $element, isset( $op['parent_id'] ) ? $parent() : $old_parent, $index );
				return 'moved ' . $id;
			case 'duplicate':
				[ $at, $position ] = $tree->locate( $id );
				$copy              = $tree->with_new_ids( $tree->get( $id ) );
				$tree->insert( $copy, $at, $position + 1 );
				return 'duplicated ' . $id . ' as ' . $copy['id'];
			case 'remove':
				$tree->remove( $id );
				return 'removed ' . $id;
		}//end switch
		throw new ToolError( 'Unknown action.' );
	}

	/**
	 * A new element from input: registered type, valid settings and children, fresh ids.
	 *
	 * @param Tree                 $tree  Tree (for unique ids).
	 * @param array<string, mixed> $input Element input.
	 * @return array<string, mixed>
	 * @throws ToolError When the type or settings are invalid.
	 */
	private function build( Tree $tree, array $input ): array {
		$el_type = (string) ( $input['elType'] ?? '' );
		$element = array(
			'id'       => $tree->new_id(),
			'elType'   => $el_type,
			'settings' => (array) ( $input['settings'] ?? array() ),
			'elements' => array(),
			'isInner'  => false,
		);
		if ( 'widget' === $el_type ) {
			$element['widgetType'] = (string) ( $input['widgetType'] ?? '' );
		}
		if ( ! Elementor::registered( $element ) ) {
			throw new ToolError( 'Element type "' . esc_html( Elementor::type( $element ) ) . '" is not available on this site. See kuduy-elementor-widgets.' );
		}
		$type = Elementor::type_object( Elementor::type( $element ) );
		if ( null !== $type && $element['settings'] ) {
			Elementor::check_settings( $type, $element['settings'] );
		}
		foreach ( (array) ( $input['elements'] ?? array() ) as $child_input ) {
			$child = $this->build( $tree, (array) $child_input );
			Tree::check_nesting( $el_type, (string) $child['elType'] );
			if ( 'column' === $el_type && 'section' === $child['elType'] ) {
				$child['isInner'] = true;
			}
			$element['elements'][] = $child;
		}
		return $element;
	}

	/**
	 * Save through Elementor and confirm nothing was dropped.
	 *
	 * @param object       $doc     Document.
	 * @param Tree         $tree    Tree.
	 * @param list<string> $changed Changes.
	 * @return array<string, mixed>
	 * @throws ToolError When Elementor refuses or drops elements.
	 */
	private function save( object $doc, Tree $tree, array $changed ): array {
		$expected = $tree->count();
		if ( ! $doc->save( array( 'elements' => $tree->elements ) ) ) {
			throw new ToolError( 'Elementor refused to save this page (your account may not be allowed to edit it with Elementor).' );
		}
		$fresh = Elementor::document( (int) $doc->get_main_id() );
		$after = ( new Tree( Elementor::elements( $fresh ) ) )->count();
		if ( $after !== $expected ) {
			throw new ToolError( sprintf( 'Saved, but Elementor kept %d of %d elements. Undo with kuduy-snapshot-restore and check the page.', (int) $after, (int) $expected ) );
		}
		$post = $fresh->get_post();
		return array(
			'id'            => $post->ID,
			'element_count' => $after,
			'changed'       => $changed,
			'link'          => (string) get_permalink( $post ),
			'edit_url'      => (string) $fresh->get_edit_url(),
		);
	}

	/**
	 * Create a page built with Elementor.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the post or tree is invalid.
	 */
	private function create( array $input ): array {
		if ( ! empty( $input['template_type'] ) ) {
			return $this->create_template( $input );
		}
		$type = Content::post_type( (string) ( $input['post_type'] ?? 'page' ) );
		if ( in_array( $input['status'] ?? 'draft', array( 'publish', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			throw new ToolError( 'Your account cannot publish this post type; create it as a draft.' );
		}
		$tree = new Tree( array() );
		foreach ( (array) ( $input['elements'] ?? array() ) as $element_input ) {
			$tree->insert( $this->build( $tree, (array) $element_input ), null, null );
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => $type->name,
					'post_title'  => (string) $input['title'],
					'post_status' => (string) ( $input['status'] ?? 'draft' ),
					'post_author' => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress refused the post: ' . esc_html( $id->get_error_message() ) );
		}
		AuditLog::target( 'post', (string) $id );
		if ( ! empty( $input['template'] ) && 'default' !== $input['template'] ) {
			update_post_meta( $id, '_wp_page_template', (string) $input['template'] );
		}
		$doc = \Elementor\Plugin::$instance->documents->get( $id, false );
		if ( ! $doc ) {
			throw new ToolError( 'Elementor does not support this post type on this site (Elementor > Settings > Post Types).' );
		}
		$doc->set_is_built_with_elementor( true );
		return $this->save( $doc, $tree, array( 'created ' . $id ) );
	}

	/**
	 * Create a Theme Builder template through Elementor's documents manager (it sets the template type and the
	 * builder flag; Pro supplies the document class).
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When Pro is missing, the type is not registered or the tree is invalid.
	 */
	private function create_template( array $input ): array {
		ThemeBuilder::module();
		$type   = (string) $input['template_type'];
		$status = (string) ( $input['status'] ?? 'draft' );
		$cpt    = get_post_type_object( 'elementor_library' );
		if ( ! $cpt || ! \Elementor\Plugin::$instance->documents->get_document_type( $type, false ) ) {
			throw new ToolError( 'Elementor Pro on this site has no "' . esc_html( $type ) . '" template type.' );
		}
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $cpt->cap->publish_posts ) ) {
			throw new ToolError( 'Your account cannot publish templates; create it as a draft.' );
		}
		$tree = new Tree( array() );
		foreach ( (array) ( $input['elements'] ?? array() ) as $element_input ) {
			$tree->insert( $this->build( $tree, (array) $element_input ), null, null );
		}
		$doc = \Elementor\Plugin::$instance->documents->create(
			$type,
			wp_slash(
				array(
					'post_title'  => (string) $input['title'],
					'post_status' => $status,
					'post_author' => get_current_user_id(),
				)
			)
		);
		if ( is_wp_error( $doc ) || ! is_object( $doc ) || (int) $doc->get_main_id() <= 0 ) {
			throw new ToolError( 'Elementor could not create the template.' );
		}
		AuditLog::target( 'post', (string) $doc->get_main_id() );
		return $this->save( $doc, $tree, array( 'created ' . $type . ' template ' . $doc->get_main_id() ) );
	}

	/**
	 * Update Kit colors and fonts.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When an id is unknown or Elementor refuses.
	 */
	private function kit( array $input ): array {
		$kit_id = (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
		$kit    = Elementor::document( $kit_id, false );
		AuditLog::target( 'elementor_kit', (string) $kit_id );
		$stored   = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$settings = is_array( $stored ) ? $stored : array();
		$defaults = (array) $kit->get_settings();
		$changed  = array();

		foreach ( array(
			'colors' => 'colors',
			'fonts'  => 'typography',
		) as $field => $suffix ) {
			$lists = array(
				'system' => array_values( (array) ( $settings[ 'system_' . $suffix ] ?? $defaults[ 'system_' . $suffix ] ?? array() ) ),
				'custom' => array_values( (array) ( $settings[ 'custom_' . $suffix ] ?? $defaults[ 'custom_' . $suffix ] ?? array() ) ),
			);
			foreach ( (array) ( $input[ $field ] ?? array() ) as $item ) {
				$values = 'colors' === $field ? self::color_values( (array) $item ) : self::font_values( (array) $item );
				$id     = (string) ( $item['id'] ?? '' );
				if ( '' === $id ) {
					$values['_id']     = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
					$lists['custom'][] = $values + array( 'title' => 'Custom' );
					$changed[]         = $field . ' ' . $values['_id'];
					continue;
				}
				$hit = false;
				foreach ( $lists as $group => $rows ) {
					foreach ( $rows as $i => $row ) {
						if ( ( $row['_id'] ?? '' ) === $id ) {
							$lists[ $group ][ $i ] = array_merge( (array) $row, $values );
							$hit                   = true;
						}
					}
				}
				if ( ! $hit ) {
					throw new ToolError( 'No global ' . esc_html( rtrim( $field, 's' ) ) . ' with id "' . esc_html( $id ) . '". See kuduy-elementor-kit.' );
				}
				$changed[] = $field . ' ' . $id;
			}//end foreach
			$before          = count( $lists['custom'] );
			$lists['custom'] = array_values( array_filter( $lists['custom'], static fn( $row ) => ! in_array( $row['_id'] ?? '', (array) ( $input['remove'] ?? array() ), true ) ) );
			if ( count( $lists['custom'] ) !== $before ) {
				$changed[] = 'removed ' . ( $before - count( $lists['custom'] ) ) . ' custom ' . $field;
			}
			$settings[ 'system_' . $suffix ] = $lists['system'];
			$settings[ 'custom_' . $suffix ] = $lists['custom'];
		}//end foreach
		if ( ! $changed ) {
			throw new ToolError( 'Nothing to change: give colors, fonts or remove.' );
		}
		if ( ! $kit->save( array( 'settings' => $settings ) ) ) {
			throw new ToolError( 'Elementor refused to save the Kit.' );
		}
		return array(
			'id'      => $kit_id,
			'changed' => $changed,
		);
	}

	/**
	 * Kit color fields.
	 *
	 * @param array<string, mixed> $item Input.
	 * @return array<string, string>
	 */
	private static function color_values( array $item ): array {
		return array_filter(
			array(
				'title' => isset( $item['title'] ) ? (string) $item['title'] : null,
				'color' => isset( $item['color'] ) ? strtoupper( (string) $item['color'] ) : null,
			),
			static fn( $v ) => null !== $v
		);
	}

	/**
	 * Kit typography fields (Elementor's group control keys).
	 *
	 * @param array<string, mixed> $item Input.
	 * @return array<string, mixed>
	 */
	private static function font_values( array $item ): array {
		$values = array_filter(
			array(
				'title'                  => isset( $item['title'] ) ? (string) $item['title'] : null,
				'typography_font_family' => isset( $item['family'] ) ? (string) $item['family'] : null,
				'typography_font_weight' => isset( $item['weight'] ) ? (string) $item['weight'] : null,
				'typography_font_size'   => isset( $item['size'] ) ? array(
					'unit'  => 'px',
					'size'  => $item['size'],
					'sizes' => array(),
				) : null,
			),
			static fn( $v ) => null !== $v
		);
		if ( array_diff_key( $values, array( 'title' => true ) ) ) {
			// The typography group applies its values only when switched to "custom".
			$values['typography_typography'] = 'custom';
		}
		return $values;
	}
}
