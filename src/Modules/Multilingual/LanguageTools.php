<?php
/**
 * Tools: kuduy-lang-status, kuduy-lang-get, kuduy-lang-set, kuduy-lang-link, kuduy-lang-translate.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Multilingual;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Multilingual content with Polylang (D20 A): languages, an object's language and translation group, setting a
 * language, linking translations, and creating a translation as a draft with text Claude provides. Every change has
 * an undo except creating a translation (undo with kuduy-post-trash, as for other creates).
 */
final class LanguageTools implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode status | get | set | link | translate.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-lang-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'status'    => __( 'Languages', 'kuduy-mcp' ),
			'get'       => __( 'Language and translations', 'kuduy-mcp' ),
			'set'       => __( 'Set a language', 'kuduy-mcp' ),
			'link'      => __( 'Link translations', 'kuduy-mcp' ),
			default     => __( 'Create a translation', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'status'    => 'Polylang version, the site\'s languages (slug, name, locale, default, number of posts) and which post types and taxonomies are translated.',
			'get'       => 'Returns the language of a post or term and its translations (language → id, title, status).',
			'set'       => 'Sets the language of a post or term (Polylang). Snapshot first; undo with kuduy-snapshot-restore.',
			'link'      => 'Links posts (or terms) as translations of each other: translations maps language → id, e.g. {"en": 12, "de": 34}. Each object must already have that language and you must be able to edit it. Snapshot first; undo restores the previous groups.',
			default     => 'Creates a translation of a post as a draft in another language, with the title, content and excerpt you give (write the translated text yourself), and links it to the original. Undo with kuduy-post-trash on the new draft.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$object     = array(
			'object' => array(
				'type'    => 'string',
				'enum'    => Polylang::OBJECTS,
				'default' => 'post',
			),
			'id'     => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$lang       = array(
			'type'      => 'string',
			'minLength' => 2,
			'maxLength' => 20,
		);
		$properties = array();
		$required   = array();
		switch ( $this->mode ) {
			case 'get':
				$properties = $object;
				$required   = array( 'id' );
				break;
			case 'set':
				$properties = $object + array( 'language' => $lang );
				$required   = array( 'id', 'language' );
				break;
			case 'link':
				$properties = array(
					'object'       => $object['object'],
					'translations' => array(
						'type'                 => 'object',
						'minProperties'        => 2,
						'maxProperties'        => 50,
						'additionalProperties' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				);
				$required   = array( 'translations' );
				break;
			case 'translate':
				$properties = array(
					'id'       => $object['id'],
					'language' => $lang,
					'title'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 500,
					),
					'content'  => array(
						'type'      => 'string',
						'maxLength' => 1000000,
					),
					'excerpt'  => array(
						'type'      => 'string',
						'maxLength' => 10000,
					),
				);
				$required   = array( 'id', 'language', 'title' );
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
		if ( 'status' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'plugin'     => array( 'type' => 'string' ),
					'version'    => array( 'type' => 'string' ),
					'languages'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'post_types' => array( 'type' => 'array' ),
					'taxonomies' => array( 'type' => 'array' ),
				),
				'required'   => array( 'plugin', 'version', 'languages' ),
			);
		}
		if ( 'link' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'object'       => array( 'type' => 'string' ),
					'translations' => array( 'type' => 'object' ),
					'_snapshot_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'object', 'translations' ),
			);
		}
		return array(
			'type'       => 'object',
			'properties' => array(
				'object'       => array( 'type' => 'string' ),
				'id'           => array( 'type' => 'integer' ),
				'language'     => array( 'type' => array( 'string', 'null' ) ),
				'translations' => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'object', 'id', 'language', 'translations' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'status', 'get' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'translate' !== $this->mode,
			'idempotentHint'  => 'translate' !== $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'status', 'get' ), true ) ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$kind = (string) ( $input['object'] ?? 'post' );
		$id   = (int) ( $input['id'] ?? 0 );
		if ( in_array( $this->mode, array( 'get', 'set' ), true ) && $id > 0 && in_array( $kind, Polylang::OBJECTS, true ) ) {
			return Polylang::capability( $kind, $id, 'set' === $this->mode );
		}
		if ( 'translate' === $this->mode && $id > 0 ) {
			// Plus the post type's create capability, checked in execute().
			return array( 'edit_post', $id );
		}
		// Link: edit permission on every object is checked in execute().
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Polylang::available();
	}

	/**
	 * Objects of a link request, checked.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array{0: string, 1: array<string, int>}
	 * @throws ToolError When an object, its language or the permission does not fit.
	 */
	private static function link_request( array $input ): array {
		$kind = (string) ( $input['object'] ?? 'post' );
		$map  = array();
		foreach ( (array) $input['translations'] as $lang => $id ) {
			$lang = (string) $lang;
			$id   = (int) $id;
			Polylang::assert_language( $lang );
			Polylang::assert_object( $kind, $id );
			if ( ! current_user_can( ...Polylang::capability( $kind, $id, true ) ) ) {
				throw new ToolError( 'Your account cannot edit ' . esc_html( $kind ) . ' ' . (int) $id . '. Nothing was linked.' );
			}
			$has = Polylang::language( $kind, $id );
			if ( $has !== $lang ) {
				throw new ToolError( esc_html( ucfirst( $kind ) ) . ' ' . (int) $id . ' is in ' . esc_html( $has ?? 'no language' ) . ', not ' . esc_html( $lang ) . ' (set it with kuduy-lang-set first). Nothing was linked.' );
			}
			$map[ $lang ] = $id;
		}
		if ( count( array_unique( $map ) ) !== count( $map ) ) {
			throw new ToolError( 'The same object is listed for two languages. Nothing was linked.' );
		}
		return array( $kind, $map );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the request does not fit.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'set' === $this->mode ) {
			$kind = (string) ( $input['object'] ?? 'post' );
			$id   = (int) $input['id'];
			Polylang::assert_object( $kind, $id );
			Polylang::assert_language( (string) $input['language'] );
			return array(
				'object_type' => $kind,
				'object_id'   => (string) $id,
				'payload'     => array(
					'object' => $kind,
					'groups' => array( $id => Polylang::translations( $kind, $id ) ),
					'langs'  => array( $id => Polylang::language( $kind, $id ) ),
				),
			);
		}
		if ( 'link' === $this->mode ) {
			[ $kind, $map ] = self::link_request( $input );
			$groups         = array();
			$langs          = array();
			foreach ( $map as $id ) {
				$groups[ $id ] = Polylang::translations( $kind, $id );
				$langs[ $id ]  = Polylang::language( $kind, $id );
			}
			return array(
				'object_type' => $kind,
				'object_id'   => (string) reset( $map ),
				'payload'     => array(
					'object' => $kind,
					'groups' => $groups,
					'langs'  => $langs,
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
		$kind  = (string) ( $payload['object'] ?? 'post' );
		$langs = (array) ( $payload['langs'] ?? array() );
		foreach ( $langs as $id => $lang ) {
			if ( ! is_string( $lang ) || '' === $lang ) {
				// The object had no language before: take it out of any group and remove its language
				// (found on staging 2026-11-10: undo used to leave the new language in place).
				Polylang::clear_language( $kind, (int) $id );
			} elseif ( Polylang::language( $kind, (int) $id ) !== $lang ) {
				Polylang::set_language( $kind, (int) $id, $lang );
			}
		}
		foreach ( (array) ( $payload['groups'] ?? array() ) as $id => $group ) {
			$lang = $langs[ $id ] ?? null;
			if ( ! is_string( $lang ) || '' === $lang ) {
				continue;
			}
			$group = array_map( 'intval', (array) $group );
			// A group of one means the object was not linked: save it alone, which unlinks it.
			Polylang::save_group( $kind, count( $group ) > 1 ? $group : array( $lang => (int) $id ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the request does not fit or WordPress refuses.
	 */
	public function execute( array $input ): array {
		if ( 'status' === $this->mode ) {
			$languages = array();
			$default   = (string) pll_default_language();
			foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $language ) {
				$languages[] = array(
					'slug'    => (string) $language->slug,
					'name'    => (string) $language->name,
					'locale'  => (string) $language->locale,
					'default' => $default === (string) $language->slug,
					'posts'   => (int) pll_count_posts( (string) $language->slug ),
				);
			}
			return array(
				'plugin'     => 'polylang',
				'version'    => defined( 'POLYLANG_VERSION' ) ? (string) POLYLANG_VERSION : '',
				'languages'  => $languages,
				'post_types' => array_values( array_filter( array_keys( Content::post_types() ), 'pll_is_translated_post_type' ) ),
				'taxonomies' => array_values( array_filter( get_taxonomies( array( 'show_ui' => true ) ), 'pll_is_translated_taxonomy' ) ),
			);
		}
		if ( 'link' === $this->mode ) {
			[ $kind, $map ] = self::link_request( $input );
			AuditLog::target( $kind, (string) reset( $map ) );
			Polylang::save_group( $kind, $map );
			$first = (int) reset( $map );
			return array(
				'object'       => $kind,
				'translations' => Polylang::describe( $kind, $first )['translations'],
			);
		}
		$kind = 'translate' === $this->mode ? 'post' : (string) ( $input['object'] ?? 'post' );
		$id   = (int) $input['id'];
		Polylang::assert_object( $kind, $id );
		AuditLog::target( $kind, (string) $id );
		if ( 'get' === $this->mode ) {
			return Polylang::describe( $kind, $id );
		}
		$lang = (string) $input['language'];
		Polylang::assert_language( $lang );
		if ( 'set' === $this->mode ) {
			Polylang::set_language( $kind, $id, $lang );
			return Polylang::describe( $kind, $id );
		}
		return self::translate( $id, $lang, $input );
	}

	/**
	 * Create a translation draft and link it.
	 *
	 * @param int                  $source Original post.
	 * @param string               $lang   Target language.
	 * @param array<string, mixed> $input  Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the translation exists, the user cannot create posts, or WordPress refuses.
	 */
	private static function translate( int $source, string $lang, array $input ): array {
		$post  = get_post( $source );
		$group = Polylang::translations( 'post', $source );
		if ( null === Polylang::language( 'post', $source ) ) {
			throw new ToolError( 'The original has no language yet; set it with kuduy-lang-set first.' );
		}
		if ( isset( $group[ $lang ] ) ) {
			throw new ToolError( 'There is already a ' . esc_html( $lang ) . ' translation: post ' . (int) $group[ $lang ] . '. Change it with kuduy-post-update.' );
		}
		$type = Content::post_type( (string) $post->post_type );
		if ( ! current_user_can( $type->cap->create_posts ) ) {
			throw new ToolError( 'Your account cannot create ' . esc_html( $type->labels->name ) . '.' );
		}
		Content::assert_writable( $type->name );
		$new = pll_insert_post(
			array(
				'post_type'    => $type->name,
				'post_status'  => 'draft',
				'post_title'   => wp_slash( (string) $input['title'] ),
				'post_content' => wp_slash( (string) ( $input['content'] ?? '' ) ),
				'post_excerpt' => wp_slash( (string) ( $input['excerpt'] ?? '' ) ),
				'post_parent'  => (int) $post->post_parent,
			),
			$lang
		);
		if ( is_wp_error( $new ) || ! $new ) {
			throw new ToolError( 'WordPress did not create the translation' . ( is_wp_error( $new ) ? ': ' . esc_html( $new->get_error_message() ) : '.' ) );
		}
		$group[ $lang ] = (int) $new;
		Polylang::save_group( 'post', $group );
		return Polylang::describe( 'post', (int) $new );
	}
}
