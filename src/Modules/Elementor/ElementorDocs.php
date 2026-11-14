<?php
/**
 * Tools: kuduy-elementor-duplicate, kuduy-elementor-document-settings, kuduy-elementor-document-settings-update,
 * kuduy-elementor-dynamic-tags, kuduy-elementor-dynamic-set (one class, five modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Whole-document work (upgrade plan phase F, D36). Everything is saved with Document::save(). A copy is a new draft
 * made the way Elementor makes documents (wp_insert_post for pages and posts, Documents_Manager::create() for library
 * templates) and filled through save(). Document settings are the document's own controls (Document is a
 * Controls_Stack); the post fields Elementor's page-settings manager writes through wp_update_post are left to the
 * post tools. Dynamic tags are the "__dynamic__" map of an element's settings, written as the tag text Elementor's
 * dynamic tags manager builds (tag_data_to_tag_text) and read back with tag_text_to_tag_data (Elementor 4.3.6 source).
 */
final class ElementorDocs implements Tool, Snapshottable {

	/**
	 * Document settings that are post fields, not stored page settings (Page settings manager, special settings).
	 */
	private const POST_FIELDS = array( 'id', 'post_title', 'post_status', 'template', 'post_excerpt', 'post_featured_image', 'menu_order', 'comment_status' );

	private const DYNAMIC = '__dynamic__';

	/**
	 * Mode.
	 *
	 * @param string $mode duplicate | document-settings | document-settings-update | dynamic-tags | dynamic-set.
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
			'duplicate'                => __( 'Duplicate an Elementor page', 'kuduy-mcp' ),
			'document-settings'        => __( 'Elementor page settings', 'kuduy-mcp' ),
			'document-settings-update' => __( 'Change Elementor page settings', 'kuduy-mcp' ),
			'dynamic-tags'             => __( 'Elementor dynamic tags', 'kuduy-mcp' ),
			default                    => __( 'Set an Elementor dynamic tag', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'duplicate'                => 'Copies a whole Elementor page, post or library template into a new draft: same elements and page settings, same page template, your account as author. The copy is never published and a template copy gets no display conditions. Refuses pages with widgets whose plugin is inactive (the copy would lose them).',
			'document-settings'        => 'Returns the page-level settings of one Elementor document (the gear icon in the editor: hide title, background, padding, custom CSS, …): the stored values and every setting the document accepts with type, default and options. Title, status, excerpt, featured image and page template are post fields: use kuduy-post-get.',
			'document-settings-update' => 'Changes page-level settings of one Elementor document: set keys and unset keys; settings not named stay as they are. Names come from kuduy-elementor-document-settings. Title, status, excerpt and featured image are refused (use kuduy-post-update); global styles are changed with kuduy-elementor-kit-update. Snapshot first; undo with kuduy-snapshot-restore.',
			'dynamic-tags'             => 'Lists the dynamic tags registered on this site (post title, featured image, custom fields, … — most come from Elementor Pro or add-ons): name, title, group, categories and the settings each accepts. With id it also lists where that page uses dynamic tags (element, setting, tag, tag settings). Settings inside repeater rows are not listed.',
			default                    => 'Connects one setting of one element to a dynamic tag (e.g. a heading\'s title to the post title), or clears it with clear: true so the stored static value shows again. The setting must accept dynamic tags and the tag must fit its kind (text, url, image, …); see kuduy-elementor-widget-schema ("dynamic": true) and kuduy-elementor-dynamic-tags. Classic widgets only, top-level settings only. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id    = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$props = match ( $this->mode ) {
			'duplicate'                => array(
				'id'    => $id,
				'title' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'Title of the copy; default is the original title with " (copy)".',
				),
			),
			'document-settings'        => array( 'id' => $id ),
			'document-settings-update' => array(
				'id'       => $id,
				'settings' => array(
					'type'        => 'object',
					'description' => 'Settings to set, e.g. {"hide_title": "yes"}.',
				),
				'unset'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'dynamic-tags'             => array(
				'id'     => $id + array( 'description' => 'Also list where this Elementor page uses dynamic tags.' ),
				'search' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			default                    => array(
				'id'         => $id,
				'element_id' => array( 'type' => 'string' ),
				'control'    => array(
					'type'        => 'string',
					'description' => 'Setting name of the element, e.g. "title".',
				),
				'tag'        => array(
					'type'        => 'string',
					'description' => 'Dynamic tag name from kuduy-elementor-dynamic-tags.',
				),
				'settings'   => array(
					'type'        => 'object',
					'description' => 'Settings of the tag itself (e.g. {"fallback": "Hello"}).',
				),
				'clear'      => array(
					'type'        => 'boolean',
					'description' => 'Remove the dynamic tag from this setting.',
				),
			),
		};//end match
		$required = match ( $this->mode ) {
			'dynamic-tags' => array(),
			'dynamic-set'  => array( 'id', 'element_id', 'control' ),
			default        => array( 'id' ),
		};
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list  = array( 'type' => 'array' );
		$props = match ( $this->mode ) {
			'document-settings' => array(
				'id'       => array( 'type' => 'integer' ),
				'type'     => array( 'type' => 'string' ),
				'settings' => array( 'type' => 'object' ),
				'controls' => $list,
			),
			'dynamic-tags'      => array(
				'tags'  => $list,
				'usage' => $list,
			),
			default             => array(
				'id'            => array( 'type' => 'integer' ),
				'source_id'     => array( 'type' => 'integer' ),
				'element_count' => array( 'type' => 'integer' ),
				'changed'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'note'          => array( 'type' => 'string' ),
				'link'          => array( 'type' => 'string' ),
				'edit_url'      => array( 'type' => 'string' ),
				'_snapshot_id'  => array( 'type' => 'integer' ),
			),
		};//end match
		return array(
			'type'       => 'object',
			'properties' => $props,
		);
	}

	/**
	 * Whether the mode only reads.
	 */
	private function reads(): bool {
		return in_array( $this->mode, array( 'document-settings', 'dynamic-tags' ), true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => $this->reads(),
			'destructiveHint' => ! $this->reads() && 'duplicate' !== $this->mode,
			'idempotentHint'  => $this->reads(),
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return $this->reads() ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return $id > 0 && get_post( $id ) ? array( 'edit_post', $id ) : 'edit_posts';
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
		$id = (int) ( $input['id'] ?? 0 );
		if ( $this->reads() || 'duplicate' === $this->mode || $id <= 0 || ! get_post( $id ) ) {
			return null;
		}
		return array(
			'object_type' => 'post',
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
		$settings = is_array( $payload['settings'] ?? null ) ? $payload['settings'] : array();
		if ( $settings ) {
			$data['settings'] = $settings;
		}
		if ( ! $doc->save( $data ) ) {
			throw new ToolError( 'Elementor refused to save the previous version.' );
		}
		if ( ! $settings ) {
			// Document::save() skips empty settings; the page settings manager removes the stored ones.
			\Elementor\Core\Settings\Manager::get_settings_managers( 'page' )->save_settings( array(), (int) $doc->get_main_id() );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the input is invalid or Elementor refuses.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'duplicate'                => self::duplicate( $input ),
			'document-settings'        => self::settings( (int) $input['id'] ),
			'document-settings-update' => self::settings_update( $input ),
			'dynamic-tags'             => self::tags( $input ),
			default                    => self::dynamic_set( $input ),
		};
	}

	/**
	 * Stored page settings of a post.
	 *
	 * @param int $id Post id.
	 * @return array<string, mixed>
	 */
	private static function stored( int $id ): array {
		$settings = get_post_meta( $id, '_elementor_page_settings', true );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Refuse documents with element types that are not registered.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 * @param string                     $what     What would happen.
	 * @throws ToolError When types are missing.
	 */
	private static function guard( array $elements, string $what ): void {
		$missing = Elementor::unregistered( $elements );
		if ( $missing ) {
			throw new ToolError( 'This page uses widgets whose plugin is not active (' . esc_html( implode( ', ', $missing ) ) . '). ' . esc_html( $what ) . ' Activate the plugin first.' );
		}
	}

	/**
	 * Result of a save.
	 *
	 * @param int          $id      Post id.
	 * @param list<string> $changed Changes.
	 * @return array<string, mixed>
	 */
	private static function result( int $id, array $changed ): array {
		$doc = Elementor::document( $id, false );
		return array(
			'id'            => $id,
			'element_count' => ( new Tree( Elementor::elements( $doc ) ) )->count(),
			'changed'       => $changed,
			'link'          => (string) get_permalink( $id ),
			'edit_url'      => (string) $doc->get_edit_url(),
		);
	}

	/**
	 * Copy a document into a new draft.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the document cannot be copied.
	 */
	private static function duplicate( array $input ): array {
		$doc  = Elementor::document( (int) $input['id'] );
		$post = $doc->get_post();
		if ( 'kit' === $doc->get_name() ) {
			throw new ToolError( 'The Kit (global styles) is not a page and cannot be duplicated.' );
		}
		$cpt = get_post_type_object( $post->post_type );
		if ( ! $cpt || ! current_user_can( $cpt->cap->create_posts ) ) {
			throw new ToolError( 'Your account cannot create new items of this type.' );
		}
		$elements = Elementor::elements( $doc );
		self::guard( $elements, 'The copy would lose them, so nothing was created.' );
		$title = (string) ( $input['title'] ?? '' );
		if ( '' === $title ) {
			/* translators: %s: title of the original page. */
			$title = sprintf( __( '%s (copy)', 'kuduy-mcp' ), $post->post_title );
		}
		$fields = array(
			'post_title'  => $title,
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
		);
		$note   = '';
		if ( 'elementor_library' === $post->post_type ) {
			$copy = \Elementor\Plugin::$instance->documents->create( $doc->get_name(), wp_slash( $fields ) );
			if ( is_wp_error( $copy ) || ! is_object( $copy ) || (int) $copy->get_main_id() <= 0 ) {
				throw new ToolError( 'Elementor could not create the template copy.' );
			}
			$new_id = (int) $copy->get_main_id();
			$note   = 'Template copy: it has no display conditions and appears nowhere until you set them.';
		} else {
			$new_id = wp_insert_post(
				wp_slash(
					$fields + array(
						'post_type'    => $post->post_type,
						'post_excerpt' => $post->post_excerpt,
						'post_parent'  => $post->post_parent,
						'menu_order'   => $post->menu_order,
					)
				),
				true
			);
			if ( is_wp_error( $new_id ) ) {
				throw new ToolError( 'WordPress refused the copy: ' . esc_html( $new_id->get_error_message() ) );
			}
			$template = (string) get_post_meta( $post->ID, '_wp_page_template', true );
			if ( '' !== $template && 'default' !== $template ) {
				update_post_meta( $new_id, '_wp_page_template', wp_slash( $template ) );
			}
			$copy = \Elementor\Plugin::$instance->documents->get( $new_id, false );
			if ( ! $copy ) {
				throw new ToolError( 'Elementor does not support this post type on this site (Elementor > Settings > Post Types).' );
			}
			$copy->set_is_built_with_elementor( true );
		}//end if
		AuditLog::target( 'post', (string) $new_id );
		$data     = array( 'elements' => $elements );
		$settings = self::stored( $post->ID );
		if ( $settings ) {
			$data['settings'] = $settings;
		}
		if ( ! $copy->save( $data ) ) {
			throw new ToolError( 'Elementor refused to save the copy (draft ' . (int) $new_id . ' was created empty).' );
		}
		$expected = ( new Tree( $elements ) )->count();
		$out      = self::result( $new_id, array( 'created ' . $new_id . ' as a copy of ' . $post->ID ) );
		if ( $out['element_count'] !== $expected ) {
			throw new ToolError( sprintf( 'Copied to draft %d, but Elementor kept %d of %d elements. Check the copy.', (int) $new_id, (int) $out['element_count'], (int) $expected ) );
		}
		$out['source_id'] = $post->ID;
		if ( '' !== $note ) {
			$out['note'] = $note;
		}
		return $out;
	}

	/**
	 * Settings a document accepts, by name (post fields and layout-only controls left out).
	 *
	 * @param object $doc Document.
	 * @return array<string, array<string, mixed>>
	 */
	private static function controls( object $doc ): array {
		$out   = array();
		$stack = (array) $doc->get_stack();
		$all   = (array) ( $stack['controls'] ?? array() ) + (array) ( $stack['style_controls'] ?? array() );
		foreach ( $all as $control ) {
			$name = is_array( $control ) ? (string) ( $control['name'] ?? '' ) : '';
			if ( '' === $name || in_array( $name, self::POST_FIELDS, true ) || in_array( $control['type'] ?? '', ElementorRead::UI_ONLY, true ) ) {
				continue;
			}
			if ( Elementor::is_device_copy( $name, $control, $all ) ) {
				continue;
			}
			$out[ $name ] = $control;
		}
		return $out;
	}

	/**
	 * Document settings: stored values and what can be set.
	 *
	 * @param int $id Post id.
	 * @return array<string, mixed>
	 */
	private static function settings( int $id ): array {
		$doc     = Elementor::document( $id );
		$devices = array_values( (array) \Elementor\Plugin::$instance->breakpoints->get_active_devices_list( array( 'reverse' => true ) ) );
		$rows    = array();
		foreach ( self::controls( $doc ) as $name => $control ) {
			$row = array(
				'name'    => $name,
				'type'    => (string) ( $control['type'] ?? '' ),
				'label'   => wp_strip_all_tags( (string) ( $control['label'] ?? '' ) ),
				'default' => $control['default'] ?? null,
			);
			if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
				$row['options'] = array_map( static fn( $o ) => is_array( $o ) ? ( $o['title'] ?? '' ) : $o, $control['options'] );
			}
			if ( ! empty( $control['is_responsive'] ) || isset( $control['responsive'] ) ) {
				$row['device_keys'] = array_map( static fn( string $d ): string => 'desktop' === $d ? $name : $name . '_' . $d, $devices );
			}
			$rows[] = $row;
		}
		$stored = self::stored( $id );
		return array(
			'id'       => $id,
			'type'     => (string) $doc->get_name(),
			'settings' => $stored ? $stored : new \stdClass(),
			'controls' => $rows,
		);
	}

	/**
	 * Patch document settings.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When a setting is unknown or Elementor refuses.
	 */
	private static function settings_update( array $input ): array {
		$id  = (int) $input['id'];
		$doc = Elementor::document( $id );
		if ( 'kit' === $doc->get_name() ) {
			throw new ToolError( 'Global styles live in the Kit: use kuduy-elementor-kit-update.' );
		}
		AuditLog::target( 'post', (string) $id );
		$set   = (array) ( $input['settings'] ?? array() );
		$unset = array_map( 'strval', (array) ( $input['unset'] ?? array() ) );
		if ( ! $set && ! $unset ) {
			throw new ToolError( 'Nothing to change: give settings or unset.' );
		}
		$fields = array_intersect( array_map( 'strval', array_keys( $set ) ), self::POST_FIELDS );
		if ( $fields ) {
			throw new ToolError( esc_html( implode( ', ', $fields ) ) . ': these are post fields, not page settings. Use kuduy-post-update. Nothing was changed.' );
		}
		Elementor::check_settings( $doc, $set );
		$before = self::stored( $id );
		$after  = array_merge( $before, $set );
		foreach ( $unset as $key ) {
			unset( $after[ $key ] );
		}
		if ( $after ) {
			if ( ! $doc->save( array( 'settings' => $after ) ) ) {
				throw new ToolError( 'Elementor refused to save this page (your account may not be allowed to edit it with Elementor).' );
			}
		} else {
			if ( ! $doc->is_editable_by_current_user() ) {
				throw new ToolError( 'Elementor does not allow your account to edit this page.' );
			}
			// Document::save() skips empty settings; the page settings manager removes the stored ones.
			\Elementor\Core\Settings\Manager::get_settings_managers( 'page' )->save_settings( array(), $id );
		}
		$changed = array();
		foreach ( array_keys( $set ) as $key ) {
			$changed[] = 'set ' . $key;
		}
		foreach ( $unset as $key ) {
			$changed[] = 'unset ' . $key;
		}
		return self::result( $id, $changed );
	}

	/**
	 * Registered tags and, for one page, where they are used.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function tags( array $input ): array {
		$manager = \Elementor\Plugin::$instance->dynamic_tags;
		$search  = strtolower( (string) ( $input['search'] ?? '' ) );
		$tags    = array();
		foreach ( (array) $manager->get_tags() as $name => $info ) {
			$tag = is_array( $info ) ? ( $info['instance'] ?? null ) : null;
			if ( ! is_object( $tag ) ) {
				continue;
			}
			$title = wp_strip_all_tags( (string) $tag->get_title() );
			if ( '' !== $search && ! str_contains( strtolower( $name . ' ' . $title ), $search ) ) {
				continue;
			}
			$settings = array();
			foreach ( (array) $tag->get_controls() as $control ) {
				if ( is_array( $control ) && ! in_array( $control['type'] ?? '', ElementorRead::UI_ONLY, true ) && '' !== (string) ( $control['name'] ?? '' ) ) {
					$settings[] = array(
						'name'  => (string) $control['name'],
						'type'  => (string) ( $control['type'] ?? '' ),
						'label' => wp_strip_all_tags( (string) ( $control['label'] ?? '' ) ),
					);
				}
			}
			$tags[] = array(
				'name'       => (string) $name,
				'title'      => $title,
				'group'      => implode( ', ', (array) $tag->get_group() ),
				'categories' => array_values( array_map( 'strval', (array) $tag->get_categories() ) ),
				'settings'   => $settings,
				'source'     => Elementor::source( $tag ),
			);
		}//end foreach
		$out = array( 'tags' => $tags );
		$id  = (int) ( $input['id'] ?? 0 );
		if ( $id > 0 ) {
			$usage = array();
			Elementor::walk(
				Elementor::elements( Elementor::document( $id ) ),
				static function ( array $element ) use ( &$usage, $manager ): void {
					foreach ( (array) ( $element['settings'][ self::DYNAMIC ] ?? array() ) as $control => $text ) {
						$data    = is_string( $text ) ? $manager->tag_text_to_tag_data( $text ) : null;
						$usage[] = array(
							'element_id' => (string) ( $element['id'] ?? '' ),
							'type'       => Elementor::type( $element ),
							'control'    => (string) $control,
							'tag'        => is_array( $data ) ? (string) $data['name'] : '',
							'settings'   => is_array( $data ) && is_array( $data['settings'] ) && $data['settings'] ? $data['settings'] : new \stdClass(),
							'registered' => is_array( $data ) && null !== $manager->get_tag_info( (string) $data['name'] ),
						);
					}
				}
			);
			$out['usage'] = $usage;
		}
		return $out;
	}

	/**
	 * Tag kinds a control accepts: its own "dynamic" categories, else those of its control type.
	 *
	 * @param array<string, mixed> $control Control.
	 * @return list<string>
	 */
	private static function categories( array $control ): array {
		$own = $control['dynamic']['categories'] ?? null;
		if ( ! is_array( $own ) ) {
			$type = \Elementor\Plugin::$instance->controls_manager->get_control( (string) ( $control['type'] ?? '' ) );
			$own  = is_object( $type ) ? ( ( (array) $type->get_settings( 'dynamic' ) )['categories'] ?? array() ) : array();
		}
		return array_values( array_map( 'strval', (array) $own ) );
	}

	/**
	 * Set or clear a dynamic tag on one setting of one element.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the element, setting or tag does not fit, or Elementor refuses.
	 */
	private static function dynamic_set( array $input ): array {
		$id  = (int) $input['id'];
		$doc = Elementor::document( $id );
		AuditLog::target( 'post', (string) $id );
		$elements = Elementor::elements( $doc );
		self::guard( $elements, 'Elementor would delete them on save, so nothing was changed.' );
		$tree     = new Tree( $elements );
		$element  = $tree->get( (string) $input['element_id'] );
		$name     = (string) $input['control'];
		$settings = (array) ( $element['settings'] ?? array() );
		$dynamic  = (array) ( $settings[ self::DYNAMIC ] ?? array() );

		if ( ! empty( $input['clear'] ) ) {
			if ( ! isset( $dynamic[ $name ] ) ) {
				throw new ToolError( 'This setting has no dynamic tag. Nothing was changed.' );
			}
			unset( $dynamic[ $name ] );
			$changed = 'cleared the dynamic tag of ' . $name . ' on ' . $element['id'];
		} else {
			$type = Elementor::type_object( Elementor::type( $element ) );
			if ( null === $type || Elementor::is_atomic( $type ) ) {
				throw new ToolError( 'Dynamic tags can be set on classic widgets only (v4 elements store them differently). Nothing was changed.' );
			}
			$stack   = (array) $type->get_stack();
			$control = ( (array) ( $stack['controls'] ?? array() ) + (array) ( $stack['style_controls'] ?? array() ) )[ $name ] ?? null;
			if ( ! is_array( $control ) || empty( $control['dynamic']['active'] ) ) {
				throw new ToolError( '"' . esc_html( $name ) . '" does not accept dynamic tags on this widget. See kuduy-elementor-widget-schema ("dynamic": true). Nothing was changed.' );
			}
			$manager  = \Elementor\Plugin::$instance->dynamic_tags;
			$tag_name = (string) ( $input['tag'] ?? '' );
			$info     = '' !== $tag_name ? $manager->get_tag_info( $tag_name ) : null;
			$tag      = is_array( $info ) ? ( $info['instance'] ?? null ) : null;
			if ( ! is_object( $tag ) ) {
				throw new ToolError( 'Unknown dynamic tag. See kuduy-elementor-dynamic-tags. Nothing was changed.' );
			}
			$accepts = self::categories( $control );
			if ( $accepts && ! array_intersect( $accepts, array_map( 'strval', (array) $tag->get_categories() ) ) ) {
				throw new ToolError( 'This setting takes ' . esc_html( implode( ' / ', $accepts ) ) . ' tags; "' . esc_html( $tag_name ) . '" is a ' . esc_html( implode( ' / ', (array) $tag->get_categories() ) ) . ' tag. Nothing was changed.' );
			}
			$tag_settings = (array) ( $input['settings'] ?? array() );
			$unknown      = array_diff( array_map( 'strval', array_keys( $tag_settings ) ), array_map( 'strval', array_keys( (array) $tag->get_controls() ) ) );
			if ( $unknown ) {
				throw new ToolError( 'Unknown settings for this tag: ' . esc_html( implode( ', ', array_slice( $unknown, 0, 10 ) ) ) . '. See kuduy-elementor-dynamic-tags. Nothing was changed.' );
			}
			$text = (string) $manager->tag_data_to_tag_text( substr( bin2hex( random_bytes( 4 ) ), 0, 7 ), $tag_name, $tag_settings );
			if ( '' === $text ) {
				throw new ToolError( 'Elementor could not build this dynamic tag. Nothing was changed.' );
			}
			$dynamic[ $name ] = $text;
			$changed          = 'set ' . $name . ' on ' . $element['id'] . ' to the dynamic tag ' . $tag_name;
		}//end if

		if ( $dynamic ) {
			$settings[ self::DYNAMIC ] = $dynamic;
		} else {
			unset( $settings[ self::DYNAMIC ] );
		}
		$element['settings'] = $settings;
		$tree->replace( $element );
		$expected = $tree->count();
		if ( ! $doc->save( array( 'elements' => $tree->elements ) ) ) {
			throw new ToolError( 'Elementor refused to save this page (your account may not be allowed to edit it with Elementor).' );
		}
		$out = self::result( $id, array( $changed ) );
		if ( $out['element_count'] !== $expected ) {
			throw new ToolError( sprintf( 'Saved, but Elementor kept %d of %d elements. Undo with kuduy-snapshot-restore and check the page.', (int) $out['element_count'], (int) $expected ) );
		}
		return $out;
	}
}
