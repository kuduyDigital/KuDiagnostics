<?php
/**
 * Tools: kuduy-theme-list, kuduy-theme-install, kuduy-theme-switch, kuduy-theme-update (one class, four modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Installs come from wordpress.org by slug only. Switching and updating are confirm-first. Deleting is not offered.
 */
final class Themes implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode list | install | switch | update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-theme-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'    => __( 'List themes', 'kuduy-mcp' ),
			'install' => __( 'Install a theme', 'kuduy-mcp' ),
			'switch'  => __( 'Switch the active theme', 'kuduy-mcp' ),
			default   => __( 'Update a theme', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'    => 'Lists installed themes: stylesheet, name, version, active, parent, block theme or classic, available update.',
			'install' => 'Installs a theme from wordpress.org by slug (never from other URLs). Does not activate it.',
			'switch'  => 'Activates another installed theme. Confirm-first: without confirm: true it only reports the impact (classic/block change, menu locations and widgets that will not carry over). Undo switches back.',
			default   => 'Updates a theme to the version WordPress offers. Confirm-first. Not undoable (files are replaced).',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$stylesheet = array(
			'type'        => 'string',
			'description' => 'Theme folder name, e.g. "twentytwentyfive" (see kuduy-theme-list).',
		);
		$schema     = match ( $this->mode ) {
			'list'    => array( 'properties' => array() ),
			'install' => array(
				'properties' => array( 'slug' => array( 'type' => 'string' ) ),
				'required'   => array( 'slug' ),
			),
			default   => array(
				'properties' => array(
					'stylesheet' => $stylesheet,
					'confirm'    => Packages::confirm_input(),
				),
				'required'   => array( 'stylesheet' ),
			),
		};
		return array( 'type' => 'object' ) + $schema + array( 'additionalProperties' => false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$theme = array(
			'type'       => 'object',
			'properties' => array(
				'stylesheet'  => array( 'type' => 'string' ),
				'name'        => array( 'type' => 'string' ),
				'version'     => array( 'type' => 'string' ),
				'active'      => array( 'type' => 'boolean' ),
				'parent'      => array( 'type' => array( 'string', 'null' ) ),
				'block_theme' => array( 'type' => 'boolean' ),
				'update'      => array( 'type' => array( 'string', 'null' ) ),
			),
			'required'   => array( 'stylesheet', 'name', 'version', 'active', 'block_theme' ),
		);
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'themes' => array(
						'type'  => 'array',
						'items' => $theme,
					),
				),
				'required'   => array( 'themes' ),
			);
		}
		$theme['properties'] += array(
			'done'         => array( 'type' => 'boolean' ),
			'impact'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'_snapshot_id' => array( 'type' => 'integer' ),
		);
		$theme['required'][]  = 'done';
		return $theme;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'list' === $this->mode ? Content::read_only() : array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'install' !== $this->mode,
			'idempotentHint'  => 'install' !== $this->mode,
			'openWorldHint'   => 'switch' !== $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'list' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return match ( $this->mode ) {
			'install' => 'install_themes',
			'update'  => 'update_themes',
			default   => 'switch_themes',
		};
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
	 */
	public function snapshot( array $input ): ?array {
		return 'switch' === $this->mode && ! empty( $input['confirm'] ) ? array(
			'object_type' => 'theme',
			'object_id'   => (string) $input['stylesheet'],
			'payload'     => array( 'stylesheet' => get_stylesheet() ),
		) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the previous theme is gone.
	 */
	public function restore( array $payload ): void {
		$theme = wp_get_theme( (string) ( $payload['stylesheet'] ?? '' ) );
		if ( ! $theme->exists() ) {
			throw new ToolError( 'The previous theme is no longer installed.' );
		}
		switch_theme( $theme->get_stylesheet() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the theme or request is invalid or WordPress refuses.
	 */
	public function execute( array $input ): array {
		Packages::load_admin();
		if ( 'list' === $this->mode ) {
			$out = array();
			foreach ( wp_get_themes() as $theme ) {
				$out[] = self::shape( $theme );
			}
			return array( 'themes' => $out );
		}
		if ( 'install' === $this->mode ) {
			return $this->install( Packages::slug( (string) $input['slug'] ) );
		}

		$theme = wp_get_theme( (string) $input['stylesheet'] );
		if ( ! $theme->exists() ) {
			throw new ToolError( 'Theme not installed: use the stylesheet from kuduy-theme-list.' );
		}
		AuditLog::target( 'theme', $theme->get_stylesheet() );

		if ( 'switch' === $this->mode ) {
			if ( $theme->get_stylesheet() === get_stylesheet() ) {
				return self::shape( $theme ) + array(
					'done'   => true,
					'impact' => array( 'Already the active theme; nothing changed.' ),
				);
			}
			if ( ! $theme->is_allowed() || $theme->errors() ) {
				throw new ToolError( 'This theme cannot be activated (it is broken or not allowed on this site).' );
			}
			$impact = self::switch_impact( $theme );
			if ( empty( $input['confirm'] ) ) {
				return self::shape( $theme ) + array(
					'done'   => false,
					'impact' => $impact,
				);
			}
			switch_theme( $theme->get_stylesheet() );
			return self::shape( wp_get_theme( $theme->get_stylesheet() ) ) + array(
				'done'   => true,
				'impact' => $impact,
			);
		}//end if

		// Update.
		$offer = get_site_transient( 'update_themes' );
		$new   = is_object( $offer ) && isset( $offer->response[ $theme->get_stylesheet() ]['new_version'] ) ? (string) $offer->response[ $theme->get_stylesheet() ]['new_version'] : '';
		if ( '' === $new ) {
			throw new ToolError( 'WordPress has no update for this theme right now (see kuduy-theme-list).' );
		}
		$impact = array( 'Updates ' . $theme->get( 'Name' ) . ' from ' . $theme->get( 'Version' ) . ' to ' . $new . '. Files are replaced; this cannot be undone here. Changes made directly in the theme files are lost (use a child theme for those).' );
		if ( empty( $input['confirm'] ) ) {
			return self::shape( $theme ) + array(
				'done'   => false,
				'impact' => $impact,
			);
		}
		$stylesheet = $theme->get_stylesheet();
		Packages::run( static fn( \Automatic_Upgrader_Skin $skin ) => ( new \Theme_Upgrader( $skin ) )->upgrade( $stylesheet ) );
		wp_clean_themes_cache();
		return self::shape( wp_get_theme( $stylesheet ) ) + array(
			'done'   => true,
			'impact' => $impact,
		);
	}

	/**
	 * Install from wordpress.org.
	 *
	 * @param string $slug Slug.
	 * @return array<string, mixed>
	 * @throws ToolError When the theme is unknown, installed already, or the install fails.
	 */
	private function install( string $slug ): array {
		if ( wp_get_theme( $slug )->exists() ) {
			throw new ToolError( 'Already installed.' );
		}
		$info = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $info ) || ! is_object( $info ) || empty( $info->download_link ) ) {
			throw new ToolError( 'wordpress.org has no theme with this slug.' );
		}
		$link = (string) $info->download_link;
		Packages::run( static fn( \Automatic_Upgrader_Skin $skin ) => ( new \Theme_Upgrader( $skin ) )->install( $link ) );
		wp_clean_themes_cache();
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			throw new ToolError( 'The theme was downloaded but WordPress cannot find it.' );
		}
		AuditLog::target( 'theme', $slug );
		return self::shape( $theme ) + array( 'done' => true );
	}

	/**
	 * What switching would change.
	 *
	 * @param \WP_Theme $target Theme to activate.
	 * @return list<string>
	 */
	private static function switch_impact( \WP_Theme $target ): array {
		$current = wp_get_theme();
		$impact  = array( 'Switches the whole site design from ' . $current->get( 'Name' ) . ' to ' . $target->get( 'Name' ) . '.' );
		if ( $current->is_block_theme() !== $target->is_block_theme() ) {
			$impact[] = $target->is_block_theme()
				? 'The new theme is a block theme: classic menus and widgets are replaced by the Site Editor\'s templates and navigation.'
				: 'The new theme is a classic theme: Site Editor templates and block navigation are not used.';
		}
		$locations = array_filter( (array) get_theme_mod( 'nav_menu_locations', array() ) );
		if ( $locations ) {
			$impact[] = 'Menu locations assigned now (' . implode( ', ', array_keys( $locations ) ) . ') may need to be assigned again in the new theme.';
		}
		$widgets = array_filter( (array) get_option( 'sidebars_widgets', array() ), static fn( $w, $k ) => is_array( $w ) && $w && 'wp_inactive_widgets' !== $k, ARRAY_FILTER_USE_BOTH );
		if ( $widgets ) {
			$impact[] = 'Widgets in ' . count( $widgets ) . ' widget area(s) may move to "Inactive widgets".';
		}
		return $impact;
	}

	/**
	 * Theme for output.
	 *
	 * @param \WP_Theme $theme Theme.
	 * @return array<string, mixed>
	 */
	private static function shape( \WP_Theme $theme ): array {
		$offer  = get_site_transient( 'update_themes' );
		$parent = $theme->parent();
		return array(
			'stylesheet'  => $theme->get_stylesheet(),
			'name'        => (string) $theme->get( 'Name' ),
			'version'     => (string) $theme->get( 'Version' ),
			'active'      => $theme->get_stylesheet() === get_stylesheet(),
			'parent'      => $parent ? $parent->get_stylesheet() : null,
			'block_theme' => $theme->is_block_theme(),
			'update'      => is_object( $offer ) && isset( $offer->response[ $theme->get_stylesheet() ]['new_version'] ) ? (string) $offer->response[ $theme->get_stylesheet() ]['new_version'] : null,
		);
	}
}
