<?php
/**
 * Avada tools (test matrix AV4–AV12, decision D31): detection, element discovery, tree and structure, creating pages,
 * adding containers, columns, nested columns and elements, updating with responsive values, moving, duplicating and
 * deleting with dry run, change report, verification and undo, full replacement with validation, page CSS, shared
 * Library items, permissions, and Elementor / WPBakery pages left alone. Runs against a stand-in for Avada Builder's
 * registry (KUDUY_FAKES=avada) with Elementor active; proves KuDiagnostics' side, not Avada's.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Avada\Avada;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class AvadaTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $author;
	private static int $global;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'FUSION_BUILDER_VERSION' ) ) {
			self::markTestSkipped( 'The Avada Builder stand-in is not loaded in this run (KUDUY_FAKES=avada).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin  = self::make_user( 'administrator' );
		self::$author = self::make_user( 'author' );
		wp_set_current_user( self::$admin );
		self::$global = (int) wp_insert_post(
			array(
				'post_type'    => 'fusion_element',
				'post_status'  => 'publish',
				'post_title'   => 'FAQ accordion',
				'post_content' => '[fusion_text]Shared answers[/fusion_text]',
			)
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
		Avada::reset();
	}

	/**
	 * The fixture page with the global element id pointed at the one created here.
	 */
	private static function fixture(): string {
		$page = rtrim( (string) file_get_contents( __DIR__ . '/fixtures/avada-page.txt' ), "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		return str_replace( '[fusion_global id="6054"]', '[fusion_global id="' . self::$global . '"]', $page );
	}

	/**
	 * A page with an Avada layout.
	 *
	 * @param string|null $content Layout.
	 */
	private static function page( ?string $content = null ): int {
		$id = (int) wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Kuduy Avada ' . wp_generate_password( 6, false ),
					'post_content' => $content ?? self::fixture(),
				)
			)
		);
		update_post_meta( $id, Avada::META_STATUS, 'active' );
		return $id;
	}

	/**
	 * Stored content.
	 *
	 * @param int $id Post id.
	 */
	private static function content( int $id ): string {
		clean_post_cache( $id );
		return (string) get_post( $id )->post_content;
	}

	public function test_status_and_elements(): void {
		$status = $this->ok( 'kuduy-avada-status' );
		$this->assertSame( 'builder_with_other_theme', $status['combination'], 'The Avada theme is not installed in the test site.' );
		$this->assertSame( '3.16.4-fake', $status['builder']['version'] );
		$this->assertTrue( $status['library'] );
		$this->assertTrue( $status['layouts'] );
		$this->assertContains( 'elementor', $status['other_builders'] );
		$this->assertSame( 'all', $status['elements']['enabled'] );

		$list = array_column( $this->ok( 'kuduy-avada-elements' )['elements'], null, 'tag' );
		$this->assertArrayHasKey( 'acme_avada_pricing', $list, 'Third-party elements are discovered.' );
		$this->assertSame( 'fusion_tab', $list['fusion_tabs']['child'] );
		$this->assertTrue( $list['fusion_title']['has_content'] );
		$button = array_column( $this->ok( 'kuduy-avada-elements', array( 'tag' => 'fusion_button' ) )['element']['params'], null, 'name' );
		$this->assertSame( array( '_self', '_blank' ), array_column( $button['target']['options'], 'value' ), 'Options are value => label in Avada.' );
		$this->assertSame( '_self', $button['target']['default'] );
		$this->assertTrue( $button['margin_bottom']['responsive'] );
		$this->assertTrue( $button['element_content']['is_content'] );
		$this->assertSame( array( 'fusion_button' ), array_column( $this->ok( 'kuduy-avada-elements', array( 'search' => 'button' ) )['elements'], 'tag' ) );
		$this->fails( 'kuduy-avada-elements', array( 'tag' => 'fusion_nothing' ), 'unknown element' );
	}

	public function test_tree_structure_content_and_library(): void {
		$id   = self::page();
		$tree = $this->ok( 'kuduy-avada-tree', array( 'post_id' => $id ) );
		$this->assertSame( 'avada', $tree['builder'] );
		$this->assertStringStartsWith( "Page\n├── Container \"Hero\"  [0]", $tree['tree'] );
		$this->assertStringContainsString( 'Column (1/2)  [0.0.0]', $tree['tree'] );
		$this->assertStringContainsString( 'Title: FAQ  [0.0.0.0]', $tree['tree'] );
		$this->assertStringContainsString( 'Global element #' . self::$global . '  [0.0.0.2]', $tree['tree'] );
		$this->assertStringContainsString( 'Nested columns  [0.0.1.1]', $tree['tree'] );
		$this->assertStringContainsString( 'Button: Request Quote  [1.0.0.0]', $tree['tree'] );

		$structure = $this->ok( 'kuduy-avada-structure', array( 'post_id' => $id ) )['structure'];
		$column    = $structure[0]['children'][0]['children'][1];
		$this->assertSame( '0.0.1', $column['id'] );
		$this->assertSame( '0.0', $column['parent_id'] );
		$this->assertSame( '4_5', $column['responsive']['medium']['type'] );
		$image = $column['children'][0];
		$this->assertSame( 'fusion_imageframe', $image['tag'] );
		$this->assertSame( 'Image', $image['title'] );
		$this->assertSame( '44581|full', $image['attrs']['image_id'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/faqs.png', $image['content'] );
		$this->assertContains( 'bordersize', $image['unregistered_attrs'], 'Settings the stand-in does not register are listed, and kept.' );
		$this->assertSame( self::$global, $structure[0]['children'][0]['children'][0]['children'][2]['global_id'] );
		$part = $this->ok(
			'kuduy-avada-structure',
			array(
				'post_id'    => $id,
				'element_id' => '1.0.0',
			)
		)['structure'];
		$this->assertSame( '1.0.0', $part[0]['id'] );
		$this->assertSame( '1.0.0.0', $part[0]['children'][0]['id'] );

		$raw = $this->ok( 'kuduy-avada-content', array( 'post_id' => $id ) );
		$this->assertSame( self::fixture(), $raw['content'] );
		$this->assertTrue( $raw['builder_active'] );

		$library = array_column( $this->ok( 'kuduy-avada-library' )['items'], null, 'id' );
		$this->assertSame( 'elements', $library[ self::$global ]['kind'] );
		$this->assertTrue( $this->ok( 'kuduy-avada-validate', array( 'post_id' => $id ) )['valid'] );
	}

	public function test_create_page_and_add_containers_columns_elements(): void {
		$dry = $this->ok(
			'kuduy-avada-create',
			array(
				'title'      => 'Kuduy Avada landing',
				'dry_run'    => true,
				'containers' => array( array( 'layout' => '1_1' ) ),
			)
		);
		$this->assertFalse( $dry['saved'] );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'page', 'title' => 'Kuduy Avada landing', 'post_status' => 'any' ) ), 'A dry run creates nothing.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- lookup.

		$created = $this->ok(
			'kuduy-avada-create',
			array(
				'title'      => 'Kuduy Avada landing',
				'containers' => array(
					array(
						'attrs'      => array(
							'admin_label'      => 'Hero',
							'background_color' => '#101820',
							'padding_top'      => '80px',
						),
						'responsive' => array( 'small' => array( 'padding_top' => '40px' ) ),
						'columns'    => array(
							array(
								'width'    => '1/2',
								'elements' => array(
									array(
										'tag'     => 'fusion_title',
										'attrs'   => array( 'size' => '1' ),
										'content' => 'Building Better "Digital" Experiences',
									),
								),
							),
							array(
								'width'    => '1_2',
								'elements' => array(
									array(
										'tag'     => 'fusion_button',
										'attrs'   => array(
											'link'   => 'https://example.test/start?a=1&b=2',
											'target' => '_blank',
										),
										'content' => 'Start',
									),
								),
							),
						),
					),
				),
			)
		);
		$this->assertTrue( $created['created'] );
		$this->assertTrue( $created['verified'] );
		$id      = $created['post_id'];
		$content = self::content( $id );
		$this->assertSame( '[fusion_builder_container admin_label="Hero" background_color="#101820" padding_top="80px" padding_top_small="40px"][fusion_builder_row][fusion_builder_column type="1_2" layout="1_2"][fusion_title size="1"]Building Better "Digital" Experiences[/fusion_title][/fusion_builder_column][fusion_builder_column type="1_2" layout="1_2"][fusion_button link="https://example.test/start?a=1&b=2" target="_blank"]Start[/fusion_button][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]', $content );
		$this->assertSame( 'active', get_post_meta( $id, Avada::META_STATUS, true ) );
		$this->assertSame( 'draft', get_post_status( $id ) );

		$container = $this->ok(
			'kuduy-avada-add-container',
			array(
				'post_id' => $id,
				'layout'  => '1_3 + 2_3',
				'index'   => 0,
			)
		);
		$this->assertSame( '0', $container['element_id'] );
		$this->assertStringStartsWith( '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_3" layout="1_3"][/fusion_builder_column][fusion_builder_column type="2_3" layout="2_3"][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container][fusion_builder_container admin_label="Hero"', self::content( $id ) );

		$column = $this->ok(
			'kuduy-avada-add-column',
			array(
				'post_id'   => $id,
				'parent_id' => '0',
				'width'     => '1_4',
				'index'     => 1,
			)
		);
		$this->assertSame( '0.0.1', $column['element_id'], 'A container id picks its row.' );
		$nested = $this->ok(
			'kuduy-avada-add-column',
			array(
				'post_id'   => $id,
				'parent_id' => '1.0.0',
				'layout'    => '1_2 + 1_2',
			)
		);
		$this->assertSame( '1.0.0.1', $nested['element_id'], 'Columns inside a column become nested columns.' );
		$this->assertStringContainsString( '[/fusion_title][fusion_builder_row_inner][fusion_builder_column_inner type="1_2" layout="1_2"][/fusion_builder_column_inner][fusion_builder_column_inner type="1_2" layout="1_2"][/fusion_builder_column_inner][/fusion_builder_row_inner][/fusion_builder_column]', self::content( $id ) );

		$text = $this->ok(
			'kuduy-avada-add-element',
			array(
				'post_id'   => $id,
				'parent_id' => '1.0.0.1.0',
				'element'   => array(
					'tag'     => 'fusion_text',
					'content' => '<p>Intro</p>',
				),
			)
		);
		$this->assertSame( '1.0.0.1.0.0', $text['element_id'] );
		$global = $this->ok(
			'kuduy-avada-add-element',
			array(
				'post_id' => $id,
				'element' => array(
					'tag'   => 'fusion_global',
					'attrs' => array( 'id' => self::$global ),
				),
			)
		);
		$this->assertSame( '2.0.0.0', $global['element_id'] );
		$this->assertStringEndsWith( '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1" layout="1_1"][fusion_global id="' . self::$global . '"][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]', self::content( $id ), 'A global element is a single tag.' );
		$this->assertTrue( $this->ok( 'kuduy-avada-validate', array( 'post_id' => $id ) )['valid'] );

		$before = self::content( $id );
		$this->fails(
			'kuduy-avada-add-element',
			array(
				'post_id'   => $id,
				'parent_id' => '1.0',
				'element'   => array( 'tag' => 'fusion_text' ),
			),
			'element straight into a row'
		);
		$this->fails(
			'kuduy-avada-add-column',
			array(
				'post_id'   => $id,
				'parent_id' => '1.0.0.1.0',
				'width'     => '1_2',
			),
			'columns inside a nested column'
		);
		$this->fails(
			'kuduy-avada-add-container',
			array(
				'post_id' => $id,
				'layout'  => '1_2 + 7_8',
			),
			'unknown width'
		);
		$this->assertSame( $before, self::content( $id ), 'Refused calls change nothing.' );
	}

	public function test_update_with_dry_run_change_report_and_undo(): void {
		$id  = self::page();
		$dry = $this->ok(
			'kuduy-avada-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0.1',
				'tag'        => 'fusion_title',
				'content'    => 'Building Better Digital Experiences',
				'dry_run'    => true,
			)
		);
		$this->assertFalse( $dry['saved'] );
		$this->assertTrue( $dry['change']['changed'] );
		$this->assertStringContainsString( '<i>Common Questions</i>', $dry['change']['before'] );
		$this->assertStringContainsString( 'Building Better Digital Experiences', $dry['change']['after'] );
		$this->assertSame( self::fixture(), self::content( $id ), 'A dry run changes nothing.' );

		$done = $this->ok(
			'kuduy-avada-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0.1',
				'tag'        => 'fusion_title',
				'content'    => 'Building Better Digital Experiences',
				'attrs'      => array( 'size' => '1' ),
				'responsive' => array( 'small' => array( 'margin_bottom' => null ) ),
				'if_hash'    => $this->ok( 'kuduy-avada-tree', array( 'post_id' => $id ) )['hash'],
			)
		);
		$this->assertTrue( $done['saved'] );
		$this->assertTrue( $done['verified'] );
		$expected = str_replace(
			'[fusion_title title_type="text" content_align="left" size="2" fusion_font_variant_title_font="700" fusion_font_family_title_font="Montserrat" margin_bottom="40px" margin_bottom_small="20px"]<i>Common Questions</i>[/fusion_title]',
			'[fusion_title title_type="text" content_align="left" size="1" fusion_font_variant_title_font="700" fusion_font_family_title_font="Montserrat" margin_bottom="40px"]Building Better Digital Experiences[/fusion_title]',
			self::fixture()
		);
		$this->assertSame( $expected, self::content( $id ), 'Only that title changed; everything else is byte for byte the same.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $done['_snapshot_id'] ) );
		$this->assertSame( self::fixture(), self::content( $id ), 'Undo restores the exact page.' );

		$this->ok(
			'kuduy-avada-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '1.0.0.0',
				'attrs'      => array(
					'link'   => 'https://example.test/book/',
					'target' => '_blank',
				),
				'content'    => 'Book now',
			)
		);
		$this->assertStringContainsString( '[fusion_button target="_blank" color="custom" stretch="default" icon_position="left" link="https://example.test/book/" border_color="var(--awb-color8)" border_top="3px" alignment="center" margin_bottom="0.5em"]Book now[/fusion_button]', self::content( $id ), 'Existing settings change in place; their order stays.' );

		$this->ok(
			'kuduy-avada-update-container',
			array(
				'post_id'      => $id,
				'container_id' => '0',
				'attrs'        => array( 'background_color' => '#f2f0e7' ),
				'responsive'   => array( 'small' => array( 'padding_top' => '30px' ) ),
			)
		);
		$this->assertStringContainsString( 'padding_top_small="30px" link_color="var(--awb-color5)" background_color="#f2f0e7"]', self::content( $id ) );
		$this->ok(
			'kuduy-avada-update-column',
			array(
				'post_id'    => $id,
				'column_id'  => '0.0.1',
				'width'      => '1/3',
				'responsive' => array( 'medium' => array( 'type' => '1_1' ) ),
			)
		);
		$this->assertStringContainsString( '[fusion_builder_column type="1_3" type="1_3" layout="1_3" align_self="auto" content_layout="column" hide_on_mobile="small-visibility,medium-visibility,large-visibility" order_medium="2" order_small="0" last="true" border_position="all" type_medium="1_1"', self::content( $id ) );

		$before = self::content( $id );
		$this->fails(
			'kuduy-avada-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0.0',
				'tag'        => 'fusion_button',
				'attrs'      => array( 'size' => '1' ),
			),
			'tag check'
		);
		$this->fails(
			'kuduy-avada-update-container',
			array(
				'post_id'      => $id,
				'container_id' => '0.0.0',
				'attrs'        => array( 'a' => 'b' ),
			),
			'a column is not a container'
		);
		$this->fails(
			'kuduy-avada-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0.2',
				'content'    => 'x',
			),
			'a global element has no content on the page'
		);
		$this->fails(
			'kuduy-avada-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0.0',
				'attrs'      => array( 'size' => '1' ),
				'if_hash'    => 'abcabcabcabc',
			),
			'stale hash'
		);
		$this->assertSame( $before, self::content( $id ) );
	}

	public function test_move_duplicate_delete_replace_and_page_css(): void {
		$id    = self::page();
		$moved = $this->ok(
			'kuduy-avada-move-element',
			array(
				'post_id'      => $id,
				'element_id'   => '0.0.1.0',
				'tag'          => 'fusion_imageframe',
				'to_parent_id' => '0.0.0',
				'index'        => 0,
			)
		);
		$this->assertSame( '0.0.0.0', $moved['element_id'] );
		$this->assertStringContainsString( 'first="true" min_height="" link=""][fusion_imageframe', self::content( $id ) );
		$this->assertStringContainsString( '[acme_slider speed=300 autoplay]<b>x</b>[/acme_slider][mystery_box a="1"]', self::content( $id ), 'Unknown and third-party shortcodes are untouched.' );

		$copy = $this->ok(
			'kuduy-avada-duplicate-element',
			array(
				'post_id'    => $id,
				'element_id' => '1',
			)
		);
		$this->assertSame( '2', $copy['element_id'] );
		$this->assertSame( 2, substr_count( self::content( $id ), 'Request Quote' ) );
		$deleted = $this->ok(
			'kuduy-avada-delete-element',
			array(
				'post_id'    => $id,
				'element_id' => '2',
				'tag'        => 'fusion_builder_container',
			)
		);
		$this->assertSame( 1, substr_count( self::content( $id ), 'Request Quote' ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$this->assertSame( 2, substr_count( self::content( $id ), 'Request Quote' ) );
		$this->fails(
			'kuduy-avada-delete-element',
			array(
				'post_id'    => $id,
				'element_id' => '1.0.0',
			),
			'the only column of a row'
		);
		$this->fails(
			'kuduy-avada-delete-element',
			array(
				'post_id'    => $id,
				'element_id' => '1.0',
			),
			'the row of a container'
		);

		$replaced = $this->ok(
			'kuduy-avada-replace',
			array(
				'post_id' => $id,
				'content' => '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_button color="purple"]Go[/fusion_button][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]',
			)
		);
		$this->assertStringContainsString( '"color" of fusion_button is "purple"', implode( ' ', array_column( $replaced['warnings'], 'problem' ) ) );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $replaced['_snapshot_id'] ) );
		$before = self::content( $id );
		foreach ( array(
			'[fusion_builder_container][fusion_builder_column][/fusion_builder_column][/fusion_builder_container]' => 'a column without a row',
			'[fusion_builder_container][fusion_builder_row][fusion_text]x[/fusion_text][/fusion_builder_row][/fusion_builder_container]' => 'an element straight in a row',
			'[fusion_builder_container][fusion_builder_row][fusion_builder_column][fusion_text]x[/fusion_builder_column][/fusion_text][/fusion_builder_row][/fusion_builder_container]' => 'tags closed in the wrong order',
		) as $content => $because ) {
			$this->fails(
				'kuduy-avada-replace',
				array(
					'post_id' => $id,
					'content' => $content,
				),
				$because
			);
		}
		$this->assertSame( $before, self::content( $id ), 'An invalid layout is never saved.' );
		$report = $this->ok( 'kuduy-avada-validate', array( 'content' => '[fusion_global id="99999999"] loose text [ghost_el]x[/ghost_el]' ) );
		$this->assertTrue( $report['valid'] );
		$warnings = implode( ' ', array_column( $report['warnings'], 'problem' ) );
		$this->assertStringContainsString( 'Global element #99999999 does not exist', $warnings );
		$this->assertStringContainsString( 'Unknown element "ghost_el"', $warnings );
		$this->assertStringContainsString( 'Text outside a column', $warnings );

		$css = $this->ok(
			'kuduy-avada-page-css',
			array(
				'post_id' => $id,
				'css'     => '.hero{color:red}</style><script>x</script>',
			)
		);
		$this->assertTrue( $css['verified'] );
		$this->assertSame( '.hero{color:red}', get_post_meta( $id, Avada::META_CSS, true ), 'Tags and script blocks are stripped.' );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $css['_snapshot_id'] ) );
		$this->assertSame( '', (string) get_post_meta( $id, Avada::META_CSS, true ) );
	}

	public function test_shared_items_permissions_and_other_builders(): void {
		$message = $this->fails(
			'kuduy-avada-update-element',
			array(
				'post_id'    => self::$global,
				'element_id' => '0',
				'content'    => 'Changed for everyone',
			),
			'a global element without confirm_shared'
		);
		$this->assertStringContainsString( 'confirm_shared', $message );
		$this->assertSame( '[fusion_text]Shared answers[/fusion_text]', self::content( self::$global ) );

		$id = self::page();
		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-avada-tree', array( 'post_id' => $id ), 'an author cannot read someone else\'s page layout' );
		$this->fails(
			'kuduy-avada-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0.0',
				'content'    => 'Hacked',
			),
			'an author cannot edit someone else\'s page'
		);
		update_option(
			'fusion_options',
			array(
				'primary_color'         => '#65bc7b',
				'body_typography'       => array( 'font-family' => 'Inter' ),
				'grid_main_break_point' => '1000',
				'google_maps_api_key'   => 'AIza-secret-key',
				'recaptcha_private'     => 'secret',
				'header_layout'         => 'v1',
				'unrelated_flag'        => '1',
			)
		);
		$this->fails( 'kuduy-avada-global-options', array(), 'an author cannot read Global Options' );
		wp_set_current_user( self::$admin );
		$options = $this->ok( 'kuduy-avada-global-options' )['options'];
		$this->assertSame( '#65bc7b', $options['primary_color'] );
		$this->assertSame( 'Inter', $options['body_typography']['font-family'] );
		$this->assertSame( '1000', $options['grid_main_break_point'] );
		$this->assertArrayNotHasKey( 'google_maps_api_key', $options, 'Secret settings are never returned.' );
		$this->assertArrayNotHasKey( 'recaptcha_private', $options );
		$this->assertArrayNotHasKey( 'unrelated_flag', $options, 'Only design settings.' );
		delete_option( 'fusion_options' );
		$this->fails( 'kuduy-avada-tree', array( 'post_id' => 99999999 ), 'invalid page id' );
		$this->fails(
			'kuduy-avada-update-element',
			array(
				'post_id'    => $id,
				'element_id' => 'hero',
				'content'    => 'x',
			),
			'malformed element id'
		);

		$elementor = self::page( '<p>Elementor page</p>' );
		delete_post_meta( $elementor, Avada::META_STATUS );
		update_post_meta( $elementor, '_elementor_edit_mode', 'builder' );
		update_post_meta( $elementor, '_elementor_data', '[{"id":"abc1234","elType":"container","elements":[]}]' );
		$this->assertStringContainsString( 'Elementor', $this->fails( 'kuduy-avada-add-container', array( 'post_id' => $elementor, 'layout' => '1_1' ), 'Avada write on an Elementor page' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two arguments.
		$this->assertSame( '[{"id":"abc1234","elType":"container","elements":[]}]', get_post_meta( $elementor, '_elementor_data', true ) );
		$wpbakery = self::page( '[vc_row][vc_column][vc_column_text]x[/vc_column_text][/vc_column][/vc_row]' );
		delete_post_meta( $wpbakery, Avada::META_STATUS );
		$this->assertStringContainsString( 'WPBakery', $this->fails( 'kuduy-avada-replace', array( 'post_id' => $wpbakery, 'content' => '[fusion_builder_container][/fusion_builder_container]' ), 'Avada write on a WPBakery page' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two arguments.

		$message = $this->fails(
			'kuduy-post-update',
			array(
				'id'      => $id,
				'content' => '<p>Overwritten</p>',
			),
			'a plain content update on an Avada page'
		);
		$this->assertStringContainsString( 'kuduy-avada', $message );
		$this->assertSame( self::fixture(), self::content( $id ) );
	}
}
