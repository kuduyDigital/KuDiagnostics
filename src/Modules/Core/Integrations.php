<?php
/**
 * Tool: kuduy-integrations.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolRegistry;

/**
 * One place to ask what KuDiagnostics can do with the builders, themes and plugins on this site (decision D30):
 * the catalogue joined with what is installed and active here and with the tools that are really available right
 * now. The support class comes from the catalogue and reflects tests, never the mere presence of a plugin.
 */
final class Integrations implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-integrations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Supported builders, themes and plugins', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Says what KuDiagnostics can do with the page builders, themes and plugins on this site: for each one whether it is installed and active, how far it is supported (full, partial, detection only, none), which tools manage it and whether they are available now, and what is not covered. By default only what is installed here; all: true lists the whole catalogue. Filter with category or search. Call this before working with a builder, theme or plugin you have not used on this site.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'category' => array(
					'type' => 'string',
					'enum' => IntegrationCatalog::CATEGORIES,
				),
				'search'   => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'all'      => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Also list integrations that are not installed on this site.',
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
				'integrations' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'        => array( 'type' => 'string' ),
							'name'      => array( 'type' => 'string' ),
							'category'  => array( 'type' => 'string' ),
							'installed' => array( 'type' => 'boolean' ),
							'active'    => array( 'type' => 'boolean' ),
							'version'   => array( 'type' => 'string' ),
							'support'   => array(
								'type' => 'string',
								'enum' => IntegrationCatalog::SUPPORT,
							),
							'tools'     => array( 'type' => 'object' ),
							'note'      => array( 'type' => 'string' ),
						),
						'required'   => array( 'id', 'name', 'category', 'installed', 'active', 'support' ),
					),
				),
				'summary'      => array( 'type' => 'object' ),
			),
			'required'   => array( 'integrations', 'summary' ),
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
	public function is_available(): bool {
		return true;
	}

	/**
	 * Installed plugins by folder: folder => [ file, version ].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function plugin_folders(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$folder = dirname( (string) $file );
			if ( '.' !== $folder && ! isset( $out[ $folder ] ) ) {
				$out[ $folder ] = array( (string) $file, (string) ( $data['Version'] ?? '' ) );
			}
		}
		return $out;
	}

	/**
	 * Whether, and in which version, a catalogue row is installed and active here.
	 *
	 * @param array<string, mixed>                       $row     Catalogue row.
	 * @param array<string, array{0: string, 1: string}> $plugins Installed plugins by folder.
	 * @return array{installed: bool, active: bool, version: string}
	 */
	private static function detect( array $row, array $plugins ): array {
		if ( 'core' === $row['kind'] ) {
			return array(
				'installed' => true,
				'active'    => true,
				'version'   => (string) get_bloginfo( 'version' ),
			);
		}
		$found = array(
			'installed' => false,
			'active'    => false,
			'version'   => '',
		);
		foreach ( $row['folders'] as $folder ) {
			if ( 'theme' === $row['kind'] ) {
				$theme = wp_get_theme( $folder );
				if ( ! $theme->exists() ) {
					continue;
				}
				$active = in_array( $folder, array( get_stylesheet(), get_template() ), true );
				if ( ! $found['installed'] || $active ) {
					$found = array(
						'installed' => true,
						'active'    => $active,
						'version'   => (string) $theme->get( 'Version' ),
					);
				}
			} elseif ( isset( $plugins[ $folder ] ) ) {
				$active = is_plugin_active( $plugins[ $folder ][0] );
				if ( ! $found['installed'] || $active ) {
					$found = array(
						'installed' => true,
						'active'    => $active,
						'version'   => $plugins[ $folder ][1],
					);
				}
			}//end if
		}//end foreach
		return $found;
	}

	/**
	 * The tools behind a row: how many there are, and whether they can be called right now.
	 *
	 * @param list<string>        $prefixes  Tool name prefixes.
	 * @param array<string, bool> $available Tool name => available now.
	 * @return array{count: int, available: bool, names: string}|null
	 */
	private static function tools( array $prefixes, array $available ): ?array {
		if ( ! $prefixes ) {
			return null;
		}
		$count = 0;
		$ready = false;
		foreach ( $available as $name => $is ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $name, $prefix ) ) {
					++$count;
					$ready = $ready || $is;
					break;
				}
			}
		}
		return array(
			'count'     => $count,
			'available' => $ready,
			'names'     => implode( ', ', array_map( static fn( string $p ) => $p . '*', $prefixes ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function execute( array $input ): array {
		$plugins   = self::plugin_folders();
		$versions  = current_user_can( 'activate_plugins' );
		$category  = (string) ( $input['category'] ?? '' );
		$search    = strtolower( (string) ( $input['search'] ?? '' ) );
		$all       = ! empty( $input['all'] );
		$available = array();
		foreach ( ToolRegistry::create()->all() as $tool ) {
			$available[ $tool->name() ] = true === $tool->is_available();
		}
		$out     = array();
		$summary = array(
			'catalogue' => 0,
			'installed' => 0,
			'active'    => 0,
			'support'   => array_fill_keys( IntegrationCatalog::SUPPORT, 0 ),
		);
		foreach ( IntegrationCatalog::all() as $row ) {
			++$summary['catalogue'];
			$state = self::detect( $row, $plugins );
			if ( $state['installed'] ) {
				++$summary['installed'];
				++$summary['support'][ $row['support'] ];
			}
			if ( $state['active'] ) {
				++$summary['active'];
			}
			if ( ( ! $all && ! $state['installed'] ) || ( '' !== $category && $category !== $row['category'] ) ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( strtolower( $row['id'] . ' ' . $row['name'] ), $search ) ) {
				continue;
			}
			$item = array(
				'id'        => $row['id'],
				'name'      => $row['name'],
				'category'  => $row['category'],
				'installed' => $state['installed'],
				'active'    => $state['active'],
				'support'   => $row['support'],
			);
			if ( $versions && '' !== $state['version'] ) {
				$item['version'] = $state['version'];
			}
			$tools = self::tools( $row['prefixes'], $available );
			if ( null !== $tools ) {
				$item['tools'] = $tools;
			}
			if ( '' !== $row['note'] ) {
				$item['note'] = $row['note'];
			}
			$out[] = $item;
		}//end foreach
		$summary['support_means'] = 'full: tested against the real product. partial: some operations, or not verified against it. detection: recognised only. none: not supported. Counts are for what is installed here.';
		return array(
			'integrations' => $out,
			'summary'      => $summary,
		);
	}
}
