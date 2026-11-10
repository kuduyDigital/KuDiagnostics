<?php
/**
 * Phase 7 blocks, Site Editor, Global Styles and widgets (test matrix S1–S5, decision D18 A): block types and a post's
 * block tree; templates changed and reverted through core's controller with undo; Global Styles patched with undo and
 * custom CSS behind edit_css; block widgets added, changed and moved to inactive with undo; editors refused. Uses the
 * Playground default block theme; a widget area is registered by the test.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class SiteEditorTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $editor;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$editor = self::make_user( 'editor' );
		register_sidebar(
			array(
				'id'   => 'kuduy-test-area',
				'name' => 'Kuduy test area',
			)
		);
		// Stored widget areas match the registered ones, as on a site whose theme has them. Otherwise core's
		// retrieve_widgets() maps the install's leftover sidebar-1/sidebar-2 onto the registered areas and moves new
		// widgets to "Inactive widgets" (found on WordPress 6.9 and 7.1 with the default block theme).
		update_option(
			'sidebars_widgets',
			array(
				'wp_inactive_widgets' => array(),
				'kuduy-test-area'     => array(),
				'array_version'       => 3,
			)
		);
		remove_theme_mod( 'sidebars_widgets' );
		unset( $GLOBALS['_wp_sidebars_widgets'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test setup: core's widget state after the option change.
		$GLOBALS['sidebars_widgets'] = wp_get_sidebars_widgets();
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * Skip unless the active theme is a block theme.
	 */
	private function needs_block_theme(): void {
		if ( ! wp_is_block_theme() ) {
			$this->markTestSkipped( 'The active theme is not a block theme.' );
		}
	}

	public function test_block_types_and_post_blocks(): void {
		$names = array_column( $this->ok( 'kuduy-block-types', array( 'prefix' => 'core/' ) )['block_types'], null, 'name' );
		$this->assertArrayHasKey( 'core/paragraph', $names );
		$this->assertArrayHasKey( 'content', (array) $names['core/paragraph']['attributes'] );

		$id     = (int) wp_insert_post(
			array(
				'post_title'   => 'Kuduy blocks',
				'post_status'  => 'publish',
				'post_content' => "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Hello</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>World text</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->",
			)
		);
		$tree   = $this->ok( 'kuduy-post-blocks', array( 'id' => $id ) );
		$byname = array_column( $tree['blocks'], null, 'path' );
		$this->assertTrue( $tree['has_blocks'] );
		$this->assertSame( 3, $tree['block_count'] );
		$this->assertSame( 'core/group', $byname['0']['name'] );
		$this->assertSame( 2, $byname['0']['inner_blocks'] );
		$this->assertSame( 'core/paragraph', $byname['0.1']['name'] );
		$this->assertSame( 'World text', $byname['0.1']['text'] );
	}

	public function test_template_update_undo_and_revert(): void {
		$this->needs_block_theme();
		$id   = get_stylesheet() . '//index';
		$list = array_column( $this->ok( 'kuduy-site-templates' )['templates'], null, 'id' );
		$this->assertArrayHasKey( $id, $list );
		$this->assertSame( 'theme', $list[ $id ]['source'] );
		$original = $this->ok( 'kuduy-site-template-get', array( 'id' => $id ) )['content'];

		$first = $this->ok(
			'kuduy-site-template-update',
			array(
				'id'      => $id,
				'content' => '<!-- wp:paragraph --><p>Kuduy index A</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertTrue( $first['customized'] );
		$this->assertStringContainsString( 'Kuduy index A', $first['content'] );

		$second = $this->ok(
			'kuduy-site-template-update',
			array(
				'id'      => $id,
				'content' => '<!-- wp:paragraph --><p>Kuduy index B</p><!-- /wp:paragraph -->',
			)
		);
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $second['_snapshot_id'] ) );
		$this->assertStringContainsString( 'Kuduy index A', $this->ok( 'kuduy-site-template-get', array( 'id' => $id ) )['content'], 'Undo puts the previous copy back.' );

		$revert = $this->ok( 'kuduy-site-template-revert', array( 'id' => $id ) );
		$this->assertFalse( $revert['customized'] );
		$this->assertSame( $original, $revert['content'], 'Back to the theme file.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $revert['_snapshot_id'] ) );
		$this->assertStringContainsString( 'Kuduy index A', $this->ok( 'kuduy-site-template-get', array( 'id' => $id ) )['content'], 'Undoing a revert restores the copy.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $first['_snapshot_id'] ) );
		$after = $this->ok( 'kuduy-site-template-get', array( 'id' => $id ) );
		$this->assertSame( 'theme', $after['source'], 'Undoing the first change removes the copy it made.' );

		$this->fails( 'kuduy-site-template-revert', array( 'id' => $id ), 'nothing to revert on a theme file' );
		$this->fails( 'kuduy-site-template-get', array( 'id' => get_stylesheet() . '//no-such-template' ), 'unknown template' );
		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-site-template-update',
			array(
				'id'      => $id,
				'content' => '<!-- wp:paragraph --><p>Editor</p><!-- /wp:paragraph -->',
			),
			'editor lacks edit_theme_options'
		);
	}

	public function test_global_styles_patch_undo_and_css_permission(): void {
		if ( ! wp_theme_has_theme_json() ) {
			$this->markTestSkipped( 'The active theme has no theme.json.' );
		}
		$before  = $this->ok( 'kuduy-global-styles-get' );
		$updated = $this->ok(
			'kuduy-global-styles-update',
			array( 'styles' => array( 'color' => array( 'background' => '#123456' ) ) )
		);
		$this->assertSame( '#123456', $updated['styles']['color']['background'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		$after = $this->ok( 'kuduy-global-styles-get' );
		$this->assertSame( $before['styles']['color']['background'] ?? null, $after['styles']['color']['background'] ?? null );

		$deny = static fn( $caps, $cap ) => 'edit_css' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		try {
			$this->fails(
				'kuduy-global-styles-update',
				array( 'styles' => array( 'css' => 'body{color:red}' ) ),
				'custom CSS without edit_css'
			);
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
		$this->assertArrayHasKey( 'theme_styles', $this->ok( 'kuduy-global-styles-get', array( 'include_theme' => true ) ) );
	}

	public function test_navigation_menus_with_undo(): void {
		$created = $this->ok(
			'kuduy-nav-menu-save',
			array(
				'title' => 'Kuduy main',
				'items' => array(
					array(
						'label' => 'Home',
						'url'   => home_url( '/' ),
					),
					array(
						'label'    => 'Shop',
						'url'      => home_url( '/shop/' ),
						'children' => array(
							array(
								'label' => 'Sale',
								'url'   => home_url( '/sale/' ),
							),
						),
					),
				),
			)
		);
		$this->assertTrue( $created['created'] );
		$this->assertSame( array( 'Home', 'Shop' ), array_column( $created['items'], 'label' ) );
		$this->assertSame( 'Sale', $created['items'][1]['children'][0]['label'] );
		$this->assertContains( $created['id'], array_column( $this->ok( 'kuduy-nav-menus' )['menus'], 'id' ) );

		$changed = $this->ok(
			'kuduy-nav-menu-save',
			array(
				'id'    => $created['id'],
				'items' => array(
					array(
						'label' => 'Only',
						'url'   => home_url( '/' ),
					),
				),
			)
		);
		$this->assertSame( array( 'Only' ), array_column( $changed['items'], 'label' ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertSame( array( 'Home', 'Shop' ), array_column( $this->ok( 'kuduy-nav-menu-get', array( 'id' => $created['id'] ) )['items'], 'label' ) );

		$trashed = $this->ok( 'kuduy-nav-menu-trash', array( 'id' => $created['id'] ) );
		$this->assertSame( 'trash', $trashed['status'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $trashed['_snapshot_id'] ) );
		$this->assertSame( 'publish', get_post_status( $created['id'] ) );

		$this->fails( 'kuduy-nav-menu-save', array( 'items' => array( array( 'label' => 'x' ) ) ), 'new menu without title' );
		$this->fails( 'kuduy-nav-menu-get', array( 'id' => (int) wp_insert_post( array( 'post_title' => 'not a menu' ) ) ), 'a post is not a menu' );
		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-nav-menu-trash', array( 'id' => $created['id'] ), 'editor lacks edit_theme_options' );
	}

	public function test_widgets_add_change_remove_with_undo(): void {
		$added = $this->ok(
			'kuduy-widget-save',
			array(
				'sidebar' => 'kuduy-test-area',
				'content' => '<!-- wp:paragraph --><p>Kuduy widget</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertTrue( $added['created'] );
		$wid   = $added['widget']['id'];
		$areas = array_column( $this->widgets()['sidebars'], null, 'id' );
		$this->assertContains( $wid, array_column( $areas['kuduy-test-area']['widgets'] ?? array(), 'id' ), (string) wp_json_encode( array( $areas, wp_get_sidebars_widgets() ) ) );

		$changed = $this->ok(
			'kuduy-widget-save',
			array(
				'widget_id' => $wid,
				'content'   => '<!-- wp:paragraph --><p>Kuduy widget changed</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertStringContainsString( 'changed', $changed['widget']['content'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $changed['_snapshot_id'] ) );
		$this->assertStringNotContainsString( 'changed', $this->content( $wid ) );
		$this->assertStringContainsString( 'Kuduy widget', $this->content( $wid ) );

		$removed = $this->ok( 'kuduy-widget-remove', array( 'widget_id' => $wid ) );
		$this->assertSame( 'wp_inactive_widgets', $removed['widget']['sidebar'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $removed['_snapshot_id'] ) );
		$areas = array_column( $this->widgets()['sidebars'], null, 'id' );
		$this->assertContains( $wid, array_column( $areas['kuduy-test-area']['widgets'], 'id' ), 'Undo puts it back in its area.' );

		$this->fails( 'kuduy-widget-save', array( 'sidebar' => 'kuduy-test-area' ), 'no content for a new widget' );
		wp_set_current_user( self::$editor );
		$this->fails( 'kuduy-widget-remove', array( 'widget_id' => $wid ), 'editor lacks edit_theme_options' );
	}

	/**
	 * The widgets list as a new request sees it. All tool calls of a test share one PHP request, so core's
	 * $sidebars_widgets global still holds the state before the widget was added; retrieve_widgets() would then treat
	 * the new widget as lost and move it to inactive. Each real MCP call is its own request.
	 *
	 * @return array<string, mixed>
	 */
	private function widgets(): array {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulate a fresh request.
		$GLOBALS['sidebars_widgets'] = get_option( 'sidebars_widgets' );
		return $this->ok( 'kuduy-widgets' );
	}

	/**
	 * Content of a block widget, from the widgets list.
	 *
	 * @param string $id Widget id.
	 */
	private function content( string $id ): string {
		foreach ( $this->widgets()['sidebars'] as $area ) {
			foreach ( $area['widgets'] as $widget ) {
				if ( $widget['id'] === $id ) {
					return (string) ( $widget['content'] ?? '' );
				}
			}
		}
		return '';
	}
}
