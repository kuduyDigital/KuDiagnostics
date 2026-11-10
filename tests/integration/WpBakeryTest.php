<?php
/**
 * WPBakery Page Builder tools (test matrix WB4–WB12, decision D29): element discovery from the registry, page
 * structure and tree, creating pages, adding rows, columns and elements, updating, moving, duplicating and deleting
 * with undo, full replacement with validation, permissions, invalid ids, third-party elements kept, and Elementor
 * pages left alone. WPBakery is a paid plugin: this runs against a stand-in for its element registry
 * (KUDUY_FAKES=wpbakery), so it proves KuDiagnostics' side, not WPBakery's.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\WpBakery\WpBakery;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WpBakeryTest extends TestCase {

	use CallsTools;

	private const PAGE = '[vc_row el_id="hero"][vc_column width="1/2"][vc_custom_heading text="Old hero heading"][vc_column_text]<p>Intro text</p>[/vc_column_text][/vc_column][vc_column width="1/2"][vc_single_image image="7" img_size="large"][acme_pricing plan="pro"][mystery_widget foo="bar"]keep me[/mystery_widget][/vc_column][/vc_row]';

	private static int $admin;
	private static int $author;
	private static int $subscriber;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WPB_VC_VERSION' ) ) {
			self::markTestSkipped( 'The WPBakery stand-in is not loaded in this run (KUDUY_FAKES=wpbakery).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin      = self::make_user( 'administrator' );
		self::$author     = self::make_user( 'author' );
		self::$subscriber = self::make_user( 'subscriber' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
		WpBakery::reset();
	}

	/**
	 * A page with a WPBakery layout.
	 *
	 * @param string $content Layout.
	 */
	private static function page( string $content = self::PAGE ): int {
		return (int) wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Kuduy WPBakery ' . wp_generate_password( 6, false ),
					'post_content' => $content,
				)
			)
		);
	}

	/**
	 * Stored content of a post.
	 *
	 * @param int $id Post id.
	 */
	private static function content( int $id ): string {
		clean_post_cache( $id );
		return (string) get_post( $id )->post_content;
	}

	public function test_elements_come_from_the_registry(): void {
		$list = $this->ok( 'kuduy-wpbakery-elements' );
		$this->assertSame( '8.0-fake', $list['wpbakery_version'] );
		$by = array_column( $list['elements'], null, 'tag' );
		$this->assertArrayHasKey( 'vc_row', $by );
		$this->assertArrayHasKey( 'acme_pricing', $by, 'Add-on elements are discovered, not hardcoded.' );
		$this->assertSame( 'Acme, Content', $by['acme_pricing']['category'] );
		$this->assertTrue( $by['vc_row']['container'] );
		$this->assertSame( 'vc_tta_section', $by['vc_tta_tabs']['holds_only'] );
		$this->assertTrue( $by['vc_column_text']['has_content'] );
		$this->assertContains( 'link', $by['vc_btn']['params'] );

		$found = $this->ok( 'kuduy-wpbakery-elements', array( 'search' => 'pricing' ) );
		$this->assertSame( array( 'acme_pricing' ), array_column( $found['elements'], 'tag' ) );

		$button = $this->ok( 'kuduy-wpbakery-elements', array( 'tag' => 'vc_btn' ) )['element'];
		$params = array_column( $button['params'], null, 'name' );
		$this->assertSame( 'vc_link', $params['link']['type'] );
		$this->assertSame( 'grey', $params['color']['default'] );
		$this->assertSame( array( 'grey', 'blue', 'danger' ), array_column( $params['color']['options'], 'value' ) );
		$this->assertSame( 'align', $params['button_block']['shown_when']['element'] );
		$this->assertTrue( array_column( $this->ok( 'kuduy-wpbakery-elements', array( 'tag' => 'vc_column_text' ) )['element']['params'], null, 'name' )['content']['is_content'] );
		$this->fails( 'kuduy-wpbakery-elements', array( 'tag' => 'nope_nothing' ), 'unknown element' );
	}

	public function test_structure_tree_and_content(): void {
		$id   = self::page();
		$tree = $this->ok( 'kuduy-wpbakery-tree', array( 'post_id' => $id ) );
		$this->assertSame( 'wpbakery', $tree['builder'] );
		$this->assertSame( 8, $tree['elements'] );
		$this->assertSame(
			array( '0', '0.0', '0.0.0', '0.0.1', '0.1', '0.1.0', '0.1.1', '0.1.2' ),
			array_column( $tree['items'], 'id' )
		);
		$this->assertStringContainsString( 'Column (1/2)', $tree['tree'] );
		$this->assertStringContainsString( 'Custom Heading: text=Old hero heading  [0.0.0]', $tree['tree'] );
		$this->assertStringContainsString( 'Text Block: Intro text', $tree['tree'] );
		$this->assertStringStartsWith( "Page\n└── Row  [0]", $tree['tree'] );

		$structure = $this->ok( 'kuduy-wpbakery-structure', array( 'post_id' => $id ) )['structure'];
		$row       = $structure[0];
		$this->assertSame( 'row', $row['type'] );
		$this->assertSame( 'hero', $row['attrs']['el_id'] );
		$this->assertSame( array( 'column', 'column' ), array_column( $row['children'], 'type' ) );
		$this->assertSame( '1/2', $row['children'][0]['width'] );
		$text = $row['children'][0]['children'][1];
		$this->assertSame( 'vc_column_text', $text['tag'] );
		$this->assertSame( 'Text Block', $text['title'] );
		$this->assertSame( '<p>Intro text</p>', $text['content'] );
		$unknown = $row['children'][1]['children'][2];
		$this->assertSame( 'mystery_widget', $unknown['tag'] );
		$this->assertFalse( $unknown['registered'] );
		$this->assertSame( 'keep me', $unknown['content'] );

		$part = $this->ok(
			'kuduy-wpbakery-structure',
			array(
				'post_id'    => $id,
				'element_id' => '0.1',
			)
		)['structure'];
		$this->assertCount( 1, $part );
		$this->assertSame( '0.1', $part[0]['id'] );
		$this->assertSame( array( '0.1.0', '0.1.1', '0.1.2' ), array_column( $part[0]['children'], 'id' ) );

		$raw = $this->ok( 'kuduy-wpbakery-content', array( 'post_id' => $id ) );
		$this->assertSame( self::PAGE, $raw['content'] );
		$this->assertSame( $tree['hash'], $raw['hash'] );
	}

	public function test_create_a_page_from_rows(): void {
		$image   = self::image();
		$created = $this->ok(
			'kuduy-wpbakery-create',
			array(
				'title' => 'Kuduy WPBakery landing',
				'slug'  => 'kuduy-wpb-landing',
				'rows'  => array(
					array(
						'layout' => '1/1',
						'attrs'  => array( 'full_width' => 'stretch_row' ),
						'design' => array(
							'background_color' => '#101820',
							'padding_top'      => 80,
							'padding_bottom'   => '80px',
							'background_image' => $image,
						),
					),
					array(
						'columns' => array(
							array(
								'width'      => '1/3',
								'responsive' => array(
									'sm'   => '1/1',
									'hide' => array( 'xs' ),
								),
								'elements'   => array(
									array(
										'tag'   => 'vc_custom_heading',
										'attrs' => array( 'text' => 'Fast "setup" [really]' ),
									),
								),
							),
							array(
								'width'    => '2/3',
								'elements' => array(
									array(
										'tag'     => 'vc_column_text',
										'content' => '<p>Feature copy</p>',
									),
									array(
										'tag'   => 'vc_btn',
										'attrs' => array(
											'title'        => 'Start',
											'color'        => 'blue',
											'button_block' => true,
											'link'         => array(
												'url'    => 'https://example.test/start?a=1&b=2',
												'title'  => 'Start now',
												'target' => '_blank',
											),
										),
									),
									array(
										'tag'   => 'vc_progress_bar',
										'attrs' => array(
											'values' => array(
												array(
													'label' => 'Speed',
													'value' => '90',
												),
											),
										),
									),
								),
							),
						),
					),
				),
			)
		);
		$this->assertTrue( $created['created'] );
		$this->assertSame( 'draft', $created['status'] );
		$this->assertSame( array(), $created['warnings'] );
		$id      = $created['post_id'];
		$content = self::content( $id );
		$this->assertStringStartsWith( '[vc_row full_width="stretch_row" css=".vc_custom_', $content );
		$this->assertStringContainsString( 'padding-top: 80px !important;', $content );
		$this->assertStringContainsString( '?id=' . $image . ') !important;', $content );
		$this->assertStringContainsString( '[vc_column width="1/3" offset="vc_col-sm-12 vc_hidden-xs"]', $content );
		$this->assertStringContainsString( '[vc_custom_heading text="Fast ``setup`` `{`really`}`"]', $content );
		$this->assertStringContainsString( 'link="url:https%3A%2F%2Fexample.test%2Fstart%3Fa%3D1%26b%3D2|title:Start%20now|target:_blank"', $content );
		$this->assertStringContainsString( 'button_block="true"', $content, 'A checkbox takes its registered value.' );
		$this->assertStringContainsString( '[vc_column][/vc_column]', $content, 'The empty 1/1 column is written without a width and with its closing tag.' );
		$this->assertSame( 'true', get_post_meta( $id, WpBakery::META_STATUS, true ) );
		$this->assertStringContainsString( 'background-color: #101820 !important;', (string) get_post_meta( $id, WpBakery::META_CSS, true ), 'Design Options CSS is stored for the page.' );
		$this->assertSame( 'kuduy-wpb-landing', get_post( $id )->post_name );

		$structure = $this->ok( 'kuduy-wpbakery-structure', array( 'post_id' => $id ) )['structure'];
		$button    = $structure[1]['children'][1]['children'][1];
		$this->assertSame( 'https://example.test/start?a=1&b=2', $button['attrs']['link']['url'], 'Links come back as objects.' );
		$this->assertSame( 'Speed', $structure[1]['children'][1]['children'][2]['attrs']['values'][0]['label'], 'Repeating groups come back as lists.' );
		$this->assertSame( 'Fast "setup" [really]', $structure[1]['children'][0]['children'][0]['attrs']['text'] );
		$this->assertTrue( $this->ok( 'kuduy-wpbakery-validate', array( 'post_id' => $id ) )['valid'] );

		$this->fails(
			'kuduy-wpbakery-create',
			array(
				'title'   => 'Broken',
				'content' => '[vc_row][vc_column][vc_column_text]x[/vc_column_text][/vc_row][/vc_column]',
			),
			'malformed nesting'
		);
		$this->fails(
			'kuduy-wpbakery-create',
			array(
				'title'    => 'Bad template',
				'template' => 'no-such-template.php',
				'rows'     => array( array( 'layout' => '1/1' ) ),
			),
			'unknown template'
		);
	}

	/**
	 * An image attachment.
	 */
	private static function image(): int {
		$upload = wp_upload_bits( 'kuduy-wpb-' . wp_generate_password( 6, false ) . '.png', null, (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 1x1 PNG fixture.
		return (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Kuduy WPB image',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
	}

	public function test_add_rows_columns_and_elements(): void {
		$id  = self::page();
		$row = $this->ok(
			'kuduy-wpbakery-add-row',
			array(
				'post_id' => $id,
				'layout'  => '1/4 + 1/4 + 1/2',
				'index'   => 0,
			)
		);
		$this->assertSame( '0', $row['element_id'] );
		$this->assertStringStartsWith( '[vc_row][vc_column width="1/4"][/vc_column][vc_column width="1/4"][/vc_column][vc_column width="1/2"][/vc_column][/vc_row][vc_row el_id="hero"]', self::content( $id ) );
		$this->assertStringEndsWith( substr( self::PAGE, 20 ), self::content( $id ), 'The existing row keeps its exact text.' );

		$column = $this->ok(
			'kuduy-wpbakery-add-column',
			array(
				'post_id'  => $id,
				'row_id'   => '0',
				'index'    => 1,
				'width'    => '1/6',
				'elements' => array(
					array(
						'tag'     => 'vc_column_text',
						'content' => 'New column',
					),
				),
			)
		);
		$this->assertSame( '0.1', $column['element_id'] );
		$this->assertStringContainsString( '[vc_column width="1/4"][/vc_column][vc_column width="1/6"][vc_column_text]New column[/vc_column_text][/vc_column][vc_column width="1/4"]', self::content( $id ) );

		$button = $this->ok(
			'kuduy-wpbakery-add-element',
			array(
				'post_id'   => $id,
				'parent_id' => '1.0',
				'index'     => 2,
				'element'   => array(
					'tag'   => 'vc_btn',
					'attrs' => array( 'title' => 'Call to action' ),
				),
			)
		);
		$this->assertSame( '1.0.2', $button['element_id'] );
		$this->assertStringContainsString( '[/vc_column_text][vc_btn title="Call to action"][/vc_column]', self::content( $id ), 'Added below the introduction.' );

		$inner = $this->ok(
			'kuduy-wpbakery-add-row',
			array(
				'post_id'   => $id,
				'parent_id' => '1.1',
				'layout'    => '1/2 + 1/2',
			)
		);
		$this->assertSame( '1.1.3', $inner['element_id'] );
		$this->assertStringContainsString( '[vc_row_inner][vc_column_inner width="1/2"][/vc_column_inner][vc_column_inner width="1/2"][/vc_column_inner][/vc_row_inner][/vc_column][/vc_row]', self::content( $id ), 'A row inside a column becomes an inner row.' );

		$loose = $this->ok(
			'kuduy-wpbakery-add-element',
			array(
				'post_id' => $id,
				'element' => array(
					'tag'      => 'vc_tta_tabs',
					'children' => array(
						array(
							'tag'      => 'vc_tta_section',
							'attrs'    => array( 'title' => 'One' ),
							'children' => array(
								array(
									'tag'     => 'vc_column_text',
									'content' => 'Tab text',
								),
							),
						),
					),
				),
			)
		);
		$this->assertSame( '2.0.0', $loose['element_id'], 'An element at the top level is wrapped in a row and column.' );
		$this->assertStringEndsWith( '[vc_row][vc_column][vc_tta_tabs][vc_tta_section title="One"][vc_column_text]Tab text[/vc_column_text][/vc_tta_section][/vc_tta_tabs][/vc_column][/vc_row]', self::content( $id ) );
		$this->assertTrue( $this->ok( 'kuduy-wpbakery-validate', array( 'post_id' => $id ) )['valid'] );

		$before = self::content( $id );
		$this->fails(
			'kuduy-wpbakery-add-element',
			array(
				'post_id'   => $id,
				'parent_id' => '1',
				'element'   => array( 'tag' => 'vc_btn' ),
			),
			'element straight into a row'
		);
		$this->fails(
			'kuduy-wpbakery-add-row',
			array(
				'post_id'   => $id,
				'parent_id' => '1.1.3.0',
				'layout'    => '1/1',
			),
			'row inside an inner column'
		);
		$this->fails(
			'kuduy-wpbakery-add-row',
			array(
				'post_id' => $id,
				'layout'  => '1/2 + banana',
			),
			'bad width'
		);
		$this->assertSame( $before, self::content( $id ), 'Refused calls change nothing.' );
	}

	public function test_update_touches_only_the_addressed_element_and_can_be_undone(): void {
		$id      = self::page();
		$heading = $this->ok(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0',
				'tag'        => 'vc_custom_heading',
				'attrs'      => array( 'text' => 'Building Better Digital Experiences' ),
			)
		);
		$this->assertSame(
			str_replace( 'text="Old hero heading"', 'text="Building Better Digital Experiences"', self::PAGE ),
			self::content( $id ),
			'Only the heading changed; third-party and unknown shortcodes are byte for byte the same.'
		);
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $heading['_snapshot_id'] ) );
		$this->assertSame( self::PAGE, self::content( $id ), 'Undo restores the exact content.' );

		$this->ok(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.1',
				'content'    => '<p>New intro with a <a href="https://example.test/">link</a></p>',
				'attrs'      => array( 'el_class' => 'lead' ),
			)
		);
		$this->assertStringContainsString( '[vc_column_text el_class="lead"]<p>New intro with a <a href="https://example.test/">link</a></p>[/vc_column_text]', self::content( $id ) );

		$this->ok(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.1.0',
				'attrs'      => array(
					'img_size'  => null,
					'alignment' => 'center',
				),
			)
		);
		$this->assertStringContainsString( '[vc_single_image image="7" alignment="center"]', self::content( $id ), 'null removes a setting.' );

		$row     = $this->ok(
			'kuduy-wpbakery-update-row',
			array(
				'post_id' => $id,
				'row_id'  => '0',
				'attrs'   => array( 'equal_height' => true ),
				'design'  => array(
					'margin_bottom'    => 40,
					'background_color' => 'rgba(0,0,0,0.5)',
				),
				'layout'  => '1/3 + 2/3',
			)
		);
		$content = self::content( $id );
		$this->assertMatchesRegularExpression( '/^\[vc_row el_id="hero" equal_height="yes" css="\.vc_custom_\d+\{margin-bottom: 40px !important;background-color: rgba\(0,0,0,0\.5\) !important;\}"\]\[vc_column width="1\/3"\]/', $content );
		$this->assertStringContainsString( '[vc_column width="2/3"][vc_single_image', $content );
		$this->assertSame( array( '0.1.2' ), array_column( $row['warnings'], 'at' ), 'Only the unknown third-party element is worth a warning.' );

		$this->ok(
			'kuduy-wpbakery-update-row',
			array(
				'post_id' => $id,
				'row_id'  => '0',
				'design'  => array(
					'margin_bottom' => null,
					'padding_top'   => '2em',
				),
			)
		);
		$this->assertMatchesRegularExpression( '/css="\.vc_custom_\d+\{background-color: rgba\(0,0,0,0\.5\) !important;padding-top: 2em !important;\}"/', self::content( $id ), 'A design change keeps the other values and the class.' );
		$this->assertStringContainsString( 'padding-top: 2em', (string) get_post_meta( $id, WpBakery::META_CSS, true ) );

		$this->ok(
			'kuduy-wpbakery-update-column',
			array(
				'post_id'    => $id,
				'column_id'  => '0.1',
				'width'      => '1/2',
				'responsive' => array( 'md' => '1/1' ),
				'attrs'      => array( 'el_class' => 'side' ),
			)
		);
		$this->assertStringContainsString( '[vc_column width="1/2" el_class="side" offset="vc_col-md-12"]', self::content( $id ) );

		$before = self::content( $id );
		$this->fails(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0',
				'tag'        => 'vc_btn',
				'attrs'      => array( 'title' => 'x' ),
			),
			'tag check'
		);
		$this->fails(
			'kuduy-wpbakery-update-row',
			array(
				'post_id' => $id,
				'row_id'  => '0.0',
				'attrs'   => array( 'gap' => '10' ),
			),
			'a column is not a row'
		);
		$this->fails(
			'kuduy-wpbakery-update-row',
			array(
				'post_id' => $id,
				'row_id'  => '0',
				'layout'  => '1/3 + 1/3 + 1/3',
			),
			'layout with a different number of columns'
		);
		$this->fails(
			'kuduy-wpbakery-update-row',
			array(
				'post_id' => $id,
				'row_id'  => '0',
				'design'  => array( 'background_color' => 'red; } body { display:none' ),
			),
			'CSS injection through a design value'
		);
		$this->fails(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0',
				'if_hash'    => 'abcabcabcabc',
				'attrs'      => array( 'text' => 'x' ),
			),
			'stale hash'
		);
		$this->assertSame( $before, self::content( $id ) );
	}

	public function test_move_duplicate_and_delete(): void {
		$id    = self::page();
		$moved = $this->ok(
			'kuduy-wpbakery-move-element',
			array(
				'post_id'      => $id,
				'element_id'   => '0.0.0',
				'to_parent_id' => '0.1',
				'index'        => 1,
			)
		);
		$this->assertSame( '0.1.1', $moved['element_id'] );
		$this->assertStringContainsString( '[vc_column width="1/2"][vc_column_text]<p>Intro text</p>[/vc_column_text][/vc_column][vc_column width="1/2"][vc_single_image image="7" img_size="large"][vc_custom_heading text="Old hero heading"][acme_pricing plan="pro"]', self::content( $id ) );

		$reordered = $this->ok(
			'kuduy-wpbakery-move-element',
			array(
				'post_id'      => $id,
				'element_id'   => '0.1.3',
				'to_parent_id' => '0.1',
				'index'        => 0,
			)
		);
		$this->assertSame( '0.1.0', $reordered['element_id'] );
		$this->assertStringContainsString( '[vc_column width="1/2"][mystery_widget foo="bar"]keep me[/mystery_widget][vc_single_image', self::content( $id ), 'An unknown element moves with its content intact.' );

		$copy = $this->ok(
			'kuduy-wpbakery-duplicate-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0',
			)
		);
		$this->assertSame( '0.1', $copy['element_id'] );
		$this->assertSame( 2, substr_count( self::content( $id ), '[vc_column width="1/2"][vc_column_text]<p>Intro text</p>[/vc_column_text][/vc_column]' ), 'The column is copied with what is inside it.' );

		$deleted = $this->ok(
			'kuduy-wpbakery-delete-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.1',
				'tag'        => 'vc_column',
			)
		);
		$this->assertSame( 1, substr_count( self::content( $id ), '<p>Intro text</p>' ) );
		$this->assertTrue( $this->ok( 'kuduy-wpbakery-validate', array( 'post_id' => $id ) )['valid'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $deleted['_snapshot_id'] ) );
		$this->assertSame( 2, substr_count( self::content( $id ), '<p>Intro text</p>' ), 'Undo brings the column back.' );

		$row = $this->ok(
			'kuduy-wpbakery-duplicate-element',
			array(
				'post_id'    => $id,
				'element_id' => '0',
			)
		);
		$this->assertSame( '1', $row['element_id'] );
		$top = $this->ok(
			'kuduy-wpbakery-move-element',
			array(
				'post_id'    => $id,
				'element_id' => '1',
				'index'      => 0,
			)
		);
		$this->assertSame( '0', $top['element_id'] );

		$single = self::page( '[vc_row][vc_column][vc_btn title="Only"][/vc_column][/vc_row]' );
		$this->fails(
			'kuduy-wpbakery-delete-element',
			array(
				'post_id'    => $single,
				'element_id' => '0.0',
			),
			'the only column of a row'
		);
		$this->fails(
			'kuduy-wpbakery-move-element',
			array(
				'post_id'      => $single,
				'element_id'   => '0',
				'to_parent_id' => '0.0',
			),
			'into itself'
		);
		$this->fails(
			'kuduy-wpbakery-move-element',
			array(
				'post_id'      => $single,
				'element_id'   => '0.0',
				'to_parent_id' => '',
			),
			'a column at the top level would break the layout'
		);
		$this->assertSame( '[vc_row][vc_column][vc_btn title="Only"][/vc_column][/vc_row]', self::content( $single ) );
		$this->fails(
			'kuduy-wpbakery-delete-element',
			array(
				'post_id'    => $single,
				'element_id' => '4.2',
			),
			'no such item'
		);
		$this->ok(
			'kuduy-wpbakery-delete-element',
			array(
				'post_id'    => $single,
				'element_id' => '0',
			)
		);
		$this->assertSame( '', self::content( $single ) );
	}

	public function test_replace_and_validate(): void {
		$id       = self::page();
		$replaced = $this->ok(
			'kuduy-wpbakery-replace',
			array(
				'post_id' => $id,
				'content' => '[vc_row][vc_column width="1/2"][vc_column_text]Left[/vc_column_text][/vc_column][vc_column width="1/2"][vc_btn title="Right" color="purple" madeup="1"][/vc_column][/vc_row]',
			)
		);
		$this->assertStringContainsString( 'Text Block: Left', $replaced['tree'] );
		$problems = implode( ' ', array_column( $replaced['warnings'], 'problem' ) );
		$this->assertStringContainsString( '"color" of vc_btn is "purple"', $problems, 'An option value that is not registered is a warning.' );
		$this->assertStringContainsString( '"madeup" is not a registered setting', $problems );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $replaced['_snapshot_id'] ) );
		$this->assertSame( self::PAGE, self::content( $id ) );

		$this->fails(
			'kuduy-wpbakery-replace',
			array(
				'post_id' => $id,
				'content' => '[vc_row][vc_column_text]No column[/vc_column_text][/vc_row]',
			),
			'element straight inside a row'
		);
		$this->fails(
			'kuduy-wpbakery-replace',
			array(
				'post_id' => $id,
				'content' => '[vc_row][vc_column][vc_row][vc_column][/vc_column][/vc_row][/vc_column][/vc_row]',
			),
			'a row nested in a column'
		);
		$this->fails( 'kuduy-wpbakery-replace', array( 'post_id' => $id ), 'no layout given' );
		$this->assertSame( self::PAGE, self::content( $id ), 'An invalid layout is never saved.' );

		$report = $this->ok(
			'kuduy-wpbakery-validate',
			array( 'content' => '[vc_row][vc_column][vc_column_text]Hi[/vc_column][/vc_row][/vc_column_text] loose [ghost_element x="1"]boo[/ghost_element][vc_row_inner][/vc_row_inner]' )
		);
		$this->assertFalse( $report['valid'] );
		$errors = implode( ' ', array_column( $report['errors'], 'problem' ) );
		$this->assertStringContainsString( 'Closing tag without a matching opening tag: [/vc_column_text]', $errors );
		$this->assertStringContainsString( 'An inner row (vc_row_inner) must sit directly inside a column', $errors );
		$warnings = implode( ' ', array_column( $report['warnings'], 'problem' ) );
		$this->assertStringContainsString( 'Unknown element "ghost_element"', $warnings );
		$this->assertStringContainsString( 'Text outside a column', $warnings );

		$good = $this->ok( 'kuduy-wpbakery-validate', array( 'post_id' => $id ) );
		$this->assertTrue( $good['valid'] );
		$this->assertStringContainsString( 'Unknown element "mystery_widget"', implode( ' ', array_column( $good['warnings'], 'problem' ) ) );
		$this->fails( 'kuduy-wpbakery-validate', array(), 'neither a page nor content' );
	}

	public function test_raw_html_is_decoded_and_needs_unfiltered_html(): void {
		$id = self::page( '[vc_row][vc_column][vc_raw_html]' . base64_encode( rawurlencode( '<p class="x">Raw</p>' ) ) . '[/vc_raw_html][/vc_column][/vc_row]' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WPBakery's storage format.
		$this->assertSame( '<p class="x">Raw</p>', $this->ok( 'kuduy-wpbakery-structure', array( 'post_id' => $id ) )['structure'][0]['children'][0]['children'][0]['content'] );
		$this->ok(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0',
				'content'    => '<p>Changed</p>',
			)
		);
		$this->assertStringContainsString( '[vc_raw_html]' . base64_encode( rawurlencode( '<p>Changed</p>' ) ) . '[/vc_raw_html]', self::content( $id ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WPBakery's storage format.

		wp_set_current_user( self::$author );
		$own = self::page( '[vc_row][vc_column][vc_column_text]Mine[/vc_column_text][/vc_column][/vc_row]' );
		wp_update_post(
			array(
				'ID'          => $own,
				'post_type'   => 'post',
				'post_author' => self::$author,
			)
		);
		$this->fails(
			'kuduy-wpbakery-add-element',
			array(
				'post_id'   => $own,
				'parent_id' => '0.0',
				'element'   => array(
					'tag'     => 'vc_raw_html',
					'content' => '<script>alert(1)</script>',
				),
			),
			'raw HTML from an account without unfiltered_html'
		);
		$this->ok(
			'kuduy-wpbakery-add-element',
			array(
				'post_id'   => $own,
				'parent_id' => '0.0',
				'element'   => array(
					'tag'     => 'vc_column_text',
					'content' => '<p>ok</p><script>alert(1)</script>',
				),
			)
		);
		$this->assertStringNotContainsString( '<script>', self::content( $own ), 'WordPress filters the content of accounts without unfiltered_html.' );
	}

	public function test_permissions_and_invalid_ids(): void {
		$id = self::page();
		$this->fails( 'kuduy-wpbakery-tree', array( 'post_id' => 99999999 ), 'invalid page id' );
		$this->fails(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => 99999999,
				'element_id' => '0',
				'attrs'      => array( 'a' => 'b' ),
			),
			'invalid page id'
		);
		$this->fails(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => $id,
				'element_id' => 'row-1',
				'attrs'      => array( 'a' => 'b' ),
			),
			'malformed element id'
		);
		$this->fails(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0',
				'attrs'      => array( 'bad name!' => 'b' ),
			),
			'malformed attribute name'
		);

		wp_set_current_user( self::$author );
		$this->fails( 'kuduy-wpbakery-structure', array( 'post_id' => $id ), 'an author cannot read the layout of someone else\'s page' );
		$this->fails(
			'kuduy-wpbakery-update-element',
			array(
				'post_id'    => $id,
				'element_id' => '0.0.0',
				'attrs'      => array( 'text' => 'Hacked' ),
			),
			'an author cannot edit someone else\'s page'
		);
		$this->fails(
			'kuduy-wpbakery-create',
			array(
				'title' => 'Author page',
				'rows'  => array( array( 'layout' => '1/1' ) ),
			),
			'an author cannot create pages'
		);
		wp_set_current_user( self::$subscriber );
		$this->fails( 'kuduy-wpbakery-elements', array(), 'a subscriber cannot list elements' );
		$this->fails( 'kuduy-wpbakery-content', array( 'post_id' => $id ), 'a subscriber cannot read the layout' );
		$this->assertSame( self::PAGE, self::content( $id ) );
	}

	public function test_elementor_pages_and_plain_updates_are_left_alone(): void {
		$id = self::page( '<p>Elementor page</p>' );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_data', '[{"id":"abc1234","elType":"container","elements":[]}]' );
		$this->assertSame( 'elementor', $this->ok( 'kuduy-wpbakery-tree', array( 'post_id' => $id ) )['builder'] );
		foreach ( array(
			'kuduy-wpbakery-add-row' => array( 'layout' => '1/1' ),
			'kuduy-wpbakery-replace' => array( 'content' => '[vc_row][vc_column][/vc_column][/vc_row]' ),
		) as $tool => $args ) {
			$message = $this->fails( $tool, array( 'post_id' => $id ) + $args, 'WPBakery write on an Elementor page' );
			$this->assertStringContainsString( 'Elementor', $message );
		}
		$this->assertSame( '<p>Elementor page</p>', self::content( $id ) );
		$this->assertSame( '[{"id":"abc1234","elType":"container","elements":[]}]', get_post_meta( $id, '_elementor_data', true ), 'Elementor data is untouched.' );
		$this->assertSame( '', (string) get_post_meta( $id, WpBakery::META_STATUS, true ) );

		$page    = self::page();
		$message = $this->fails(
			'kuduy-post-update',
			array(
				'id'      => $page,
				'content' => '<p>Overwritten</p>',
			),
			'a plain content update on a WPBakery page'
		);
		$this->assertStringContainsString( 'kuduy-wpbakery', $message );
		$this->ok(
			'kuduy-post-update',
			array(
				'id'    => $page,
				'title' => 'Kuduy WPBakery renamed',
			)
		);
		$this->assertSame( self::PAGE, self::content( $page ), 'Other fields can still be changed; the layout stays.' );
	}
}
