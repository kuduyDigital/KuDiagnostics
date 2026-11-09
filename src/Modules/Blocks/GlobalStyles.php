<?php
/**
 * Tools: kuduy-global-styles-get, kuduy-global-styles-update.
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
 * The site's Global Styles (the user layer of theme.json, one wp_global_styles post per theme) through core's
 * global-styles controller (D18 A): core checks edit_post on the styles post, and edit_css plus its CSS validation
 * for styles.css. Updates are patches: KUDUY merges them into the current styles and settings, then core saves.
 */
final class GlobalStyles implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode get | update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-global-styles-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'get' === $this->mode ? __( 'Read Global Styles', 'kuduy-mcp' ) : __( 'Change Global Styles', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'get' === $this->mode
			? 'Returns the site\'s Global Styles (what was changed in Styles in the Site Editor): styles (colors, typography, spacing, elements, blocks, css) and settings, in theme.json shape. include_theme: true adds the theme\'s own defaults.'
			: 'Changes Global Styles like the Site Editor\'s Styles panel. styles and settings are patches in theme.json shape, merged into the current values (a key set to null is removed), e.g. {"styles": {"color": {"background": "#ffffff"}}}. Custom CSS (styles.css) needs permission to edit CSS. Snapshot first; undo with kuduy-snapshot-restore.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = 'get' === $this->mode
			? array(
				'include_theme' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
			: array(
				'styles'   => CoreRest::object_input( 'Patch for styles (theme.json shape).' ),
				'settings' => CoreRest::object_input( 'Patch for settings (theme.json shape).' ),
			);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array(),
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
				'theme'        => array( 'type' => 'string' ),
				'styles'       => array( 'type' => 'object' ),
				'settings'     => array( 'type' => 'object' ),
				'theme_styles' => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'theme', 'styles', 'settings' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'get' === $this->mode ? Content::read_only() : array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'get' === $this->mode ? 'kuduy:read' : 'kuduy:write';
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
		return wp_theme_has_theme_json() ? true : 'The active theme has no theme.json, so it has no Global Styles';
	}

	/**
	 * Id of the styles post (core creates it when missing).
	 *
	 * @throws ToolError When core has none.
	 */
	private static function id(): int {
		$id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( $id <= 0 ) {
			throw new ToolError( 'WordPress has no Global Styles for the active theme.' );
		}
		return $id;
	}

	/**
	 * Current styles and settings as arrays.
	 *
	 * @param int $id Styles post.
	 * @return array{styles: array<string, mixed>, settings: array<string, mixed>}
	 */
	private static function current( int $id ): array {
		$data = (array) json_decode( (string) wp_json_encode( CoreRest::call( 'GET', '/wp/v2/global-styles/' . $id, array( 'context' => 'edit' ) ) ), true );
		return array(
			'styles'   => (array) ( $data['styles'] ?? array() ),
			'settings' => (array) ( $data['settings'] ?? array() ),
		);
	}

	/**
	 * Merge a patch: nested objects merge, lists and scalars replace, null removes.
	 *
	 * @param array<string, mixed> $base  Current.
	 * @param array<string, mixed> $patch Patch.
	 * @return array<string, mixed>
	 */
	private static function merge( array $base, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( null === $value ) {
				unset( $base[ $key ] );
			} elseif ( is_array( $value ) && ! array_is_list( $value ) && is_array( $base[ $key ] ?? null ) && ! array_is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Whether a styles patch sets custom CSS anywhere (site-wide styles.css or a block's css).
	 *
	 * @param array<int|string, mixed> $styles Styles patch.
	 */
	private static function has_css( array $styles ): bool {
		foreach ( $styles as $key => $value ) {
			if ( 'css' === $key || ( is_array( $value ) && self::has_css( $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Core's REST route validates custom CSS but leaves the edit_css check to the Site Editor screen; KUDUY applies it.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When CSS is set without edit_css.
	 */
	private static function assert_css_allowed( array $input ): void {
		if ( is_array( $input['styles'] ?? null ) && self::has_css( $input['styles'] ) && ! current_user_can( 'edit_css' ) ) {
			throw new ToolError( 'Custom CSS needs permission to edit CSS (edit_css). Nothing was changed.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When CSS is set without edit_css.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'update' !== $this->mode ) {
			return null;
		}
		self::assert_css_allowed( $input );
		$id = self::id();
		return array(
			'object_type' => 'wp_global_styles',
			'object_id'   => (string) $id,
			'payload'     => array( 'id' => $id ) + self::current( $id ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When core refuses.
	 */
	public function restore( array $payload ): void {
		CoreRest::call(
			'POST',
			'/wp/v2/global-styles/' . (int) ( $payload['id'] ?? 0 ),
			array(
				'styles'   => (object) (array) ( $payload['styles'] ?? array() ),
				'settings' => (object) (array) ( $payload['settings'] ?? array() ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When nothing was asked for or core refuses.
	 */
	public function execute( array $input ): array {
		$id = self::id();
		AuditLog::target( 'wp_global_styles', (string) $id );
		if ( 'update' === $this->mode ) {
			if ( ! isset( $input['styles'] ) && ! isset( $input['settings'] ) ) {
				throw new ToolError( 'Give styles and/or settings. Nothing was changed.' );
			}
			self::assert_css_allowed( $input );
			$current = self::current( $id );
			$body    = array();
			foreach ( array( 'styles', 'settings' ) as $key ) {
				if ( isset( $input[ $key ] ) ) {
					$body[ $key ] = (object) self::merge( $current[ $key ], (array) $input[ $key ] );
				}
			}
			CoreRest::call( 'POST', '/wp/v2/global-styles/' . $id, $body );
		}
		$now = self::current( $id );
		$out = array(
			'id'       => $id,
			'theme'    => get_stylesheet(),
			'styles'   => (object) $now['styles'],
			'settings' => (object) $now['settings'],
		);
		if ( ! empty( $input['include_theme'] ) ) {
			$theme               = (array) json_decode( (string) wp_json_encode( CoreRest::call( 'GET', '/wp/v2/global-styles/themes/' . get_stylesheet() ) ), true );
			$out['theme_styles'] = (object) array(
				'styles'   => (object) (array) ( $theme['styles'] ?? array() ),
				'settings' => (object) (array) ( $theme['settings'] ?? array() ),
			);
		}
		return $out;
	}
}
