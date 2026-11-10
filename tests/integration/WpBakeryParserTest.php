<?php
/**
 * WPBakery shortcode parser and serializer (test matrix WB1–WB3, decision D29): nested rows and columns, several
 * levels, self-closing tags, special characters, third-party and unknown shortcodes, bracket text that is not a
 * shortcode, and byte-identical round trips. Needs no plugin, so it runs in every job.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\WpBakery\Shortcodes;
use PHPUnit\Framework\TestCase;

final class WpBakeryParserTest extends TestCase {

	/**
	 * Parse with vc_* and two third-party tags known.
	 *
	 * @param string $content Content.
	 * @return list<array<string, mixed>>
	 */
	private static function parse( string $content ): array {
		return Shortcodes::parse( $content, static fn( string $name ) => str_starts_with( $name, 'vc_' ) || in_array( $name, array( 'gallery', 'contact-form-7' ), true ) );
	}

	/**
	 * Tags of the element children of a node.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return list<string>
	 */
	private static function tags( array $node ): array {
		return array_column( array_filter( $node['children'], static fn( array $c ) => 'element' === $c['type'] ), 'tag' );
	}

	/**
	 * Layouts that must survive parse → serialize unchanged.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function layouts(): array {
		return array(
			'basic page'              => array( '[vc_row][vc_column][vc_column_text]Hello <b>world</b>[/vc_column_text][/vc_column][/vc_row]' ),
			'nested rows and columns' => array( '[vc_row full_width="stretch_row"][vc_column width="1/2"][vc_row_inner][vc_column_inner width="1/2"][vc_btn title="Go"][/vc_column_inner][vc_column_inner width="1/2"][/vc_column_inner][/vc_row_inner][/vc_column][vc_column width="1/2"][vc_single_image image="12"][/vc_column][/vc_row]' ),
			'several levels'          => array( '[vc_section][vc_row][vc_column][vc_tta_tabs][vc_tta_section title="One" tab_id="1"][vc_column_text]A[/vc_column_text][/vc_tta_section][vc_tta_section title="Two" tab_id="2"][vc_row_inner][vc_column_inner][vc_column_text]B[/vc_column_text][/vc_column_inner][/vc_row_inner][/vc_tta_section][/vc_tta_tabs][/vc_column][/vc_row][/vc_section]' ),
			'self-closing'            => array( '[vc_row][vc_column][vc_single_image image="3" /][vc_empty_space height="20px"/][vc_separator][/vc_column][/vc_row]' ),
			'special characters'      => array( '[vc_row][vc_column][vc_custom_heading text="Say ``hi`` `{`now`}` &amp; <more>" font_container=\'tag:h2|text_align:left\' css=".vc_custom_1700000000000{padding-top: 10px !important;background-image: url(https://x.test/a.jpg?id=5) !important;}"][vc_column_text]5 > 3 & "quotes" \'single\' ünïcödé 日本語 [1] [citation needed][/vc_column_text][/vc_column][/vc_row]' ),
			'whitespace and newlines' => array( "\n[vc_row]\n\t[vc_column]\n\t\t[vc_column_text]\n<p>Para one</p>\n\n<p>Para two</p>\n[/vc_column_text]\n\t[/vc_column]\n[/vc_row]\n\n" ),
			'third-party shortcodes'  => array( '[vc_row][vc_column][contact-form-7 id="12" title="Contact"][gallery ids="1,2,3"][acme_slider speed=300 autoplay]<b>x</b>[/acme_slider][/vc_column][/vc_row]' ),
			'unknown and loose text'  => array( 'Intro text [not a shortcode] [[vc_row]] [vc_row][vc_column][mystery_box a="1"][/vc_column][/vc_row] outro [/vc_column] [' ),
			'raw html element'        => array( '[vc_row][vc_column][vc_raw_html]JTNDcCUzRWhpJTNDJTJGcCUzRQ==[/vc_raw_html][/vc_column][/vc_row]' ),
			'empty'                   => array( '' ),
			'no shortcodes'           => array( '<p>Just a classic post with [brackets].</p>' ),
		);
	}

	/**
	 * @dataProvider layouts
	 *
	 * @param string $content Layout.
	 */
	public function test_round_trip_is_byte_identical( string $content ): void {
		$this->assertSame( $content, Shortcodes::serialize( self::parse( $content ) ) );
	}

	public function test_nested_rows_and_columns_become_a_tree(): void {
		$nodes = self::parse( self::layouts()['nested rows and columns'][0] );
		$this->assertCount( 1, $nodes );
		$row = $nodes[0];
		$this->assertSame( 'vc_row', $row['tag'] );
		$this->assertSame( 'stretch_row', Shortcodes::att( $row, 'full_width' ) );
		$this->assertSame( array( 'vc_column', 'vc_column' ), self::tags( $row ) );
		$inner = $row['children'][0]['children'][0];
		$this->assertSame( 'vc_row_inner', $inner['tag'] );
		$this->assertSame( array( 'vc_column_inner', 'vc_column_inner' ), self::tags( $inner ) );
		$this->assertSame( 'vc_btn', $inner['children'][0]['children'][0]['tag'] );
		$this->assertFalse( $inner['children'][0]['children'][0]['closed'], 'A tag without a closing tag is a leaf.' );
		$this->assertTrue( $inner['children'][1]['closed'], 'An empty column still has its closing tag.' );
	}

	public function test_several_levels(): void {
		$nodes = self::parse( self::layouts()['several levels'][0] );
		$tabs  = $nodes[0]['children'][0]['children'][0]['children'][0];
		$this->assertSame( 'vc_tta_tabs', $tabs['tag'] );
		$this->assertSame( array( 'vc_tta_section', 'vc_tta_section' ), self::tags( $tabs ) );
		$deep = $tabs['children'][1]['children'][0]['children'][0]['children'][0];
		$this->assertSame( 'vc_column_text', $deep['tag'] );
		$this->assertSame( 'B', Shortcodes::serialize( $deep['children'] ) );
	}

	public function test_self_closing_and_attribute_forms(): void {
		$column = self::parse( self::layouts()['self-closing'][0] )[0]['children'][0];
		$this->assertSame( array( 'vc_single_image', 'vc_empty_space', 'vc_separator' ), self::tags( $column ) );
		$this->assertTrue( $column['children'][0]['self'] );
		$this->assertSame( '20px', Shortcodes::att( $column['children'][1], 'height' ) );

		$third = self::parse( self::layouts()['third-party shortcodes'][0] )[0]['children'][0];
		$this->assertSame( array( 'contact-form-7', 'gallery', 'acme_slider' ), self::tags( $third ), 'A tag with a closing tag is a shortcode even when the site does not know it.' );
		$slider = $third['children'][2];
		$this->assertSame( '300', Shortcodes::att( $slider, 'speed' ), 'Unquoted value.' );
		$this->assertNull( $slider['atts'][1]['name'], 'A bare word is a nameless attribute.' );
		$this->assertSame( 'autoplay', $slider['atts'][1]['value'] );
		$this->assertSame( '<b>x</b>', Shortcodes::serialize( $slider['children'] ) );
	}

	public function test_special_characters_and_encoding(): void {
		$column  = self::parse( self::layouts()['special characters'][0] )[0]['children'][0];
		$heading = $column['children'][0];
		$this->assertSame( 'Say "hi" [now] &amp; <more>', Shortcodes::decode( (string) Shortcodes::att( $heading, 'text' ) ) );
		$this->assertSame( 'tag:h2|text_align:left', Shortcodes::att( $heading, 'font_container' ), 'Single-quoted value.' );
		$this->assertStringStartsWith( '.vc_custom_1700000000000{', (string) Shortcodes::att( $heading, 'css' ) );
		$text = $column['children'][1];
		$this->assertCount( 1, $text['children'], 'Bracket words in text are not shortcodes.' );
		$this->assertStringContainsString( '[citation needed]', $text['children'][0]['raw'] );
		$this->assertSame( 'a ``b`` `{`c`}`', Shortcodes::encode( 'a "b" [c]' ) );
		$this->assertSame( 'a "b" [c]', Shortcodes::decode( Shortcodes::encode( 'a "b" [c]' ) ) );
	}

	public function test_unknown_elements_and_loose_text_are_kept(): void {
		$nodes = self::parse( self::layouts()['unknown and loose text'][0] );
		$this->assertSame( 'text', $nodes[0]['type'] );
		$this->assertStringContainsString( '[[vc_row]]', $nodes[0]['raw'], 'An escaped shortcode stays text.' );
		$this->assertSame( 'vc_row', $nodes[1]['tag'] );
		$column = $nodes[1]['children'][0];
		$this->assertSame( array(), self::tags( $column ), 'A tag nobody knows and nobody closes stays text, untouched.' );
		$this->assertSame( '[mystery_box a="1"]', $column['children'][0]['raw'] );
		$this->assertTrue( $nodes[2]['orphan'], 'A closing tag without an opening tag is flagged.' );
	}

	public function test_same_tag_pairs_like_wordpress(): void {
		// WordPress pairs an opening tag with the first closing tag of that name; that is why WPBakery has vc_row_inner.
		$nodes = self::parse( '[vc_row][vc_row]x[/vc_row][/vc_row]' );
		$this->assertSame( 'vc_row', $nodes[0]['tag'] );
		$this->assertSame( '[vc_row]x', Shortcodes::serialize( $nodes[0]['children'] ) );
		$this->assertTrue( $nodes[1]['orphan'] );
	}

	public function test_an_edit_rewrites_only_the_changed_tag(): void {
		$content = "[vc_row el_class='keep  me']\n[vc_column][vc_btn title='Old' color=blue][acme_slider speed=300]x[/acme_slider][/vc_column]\n[/vc_row]";
		$nodes   = self::parse( $content );
		$button  = $nodes[0]['children'][1]['children'][0];
		$this->assertSame( 'vc_btn', $button['tag'] );
		$nodes[0]['children'][1]['children'][0] = Shortcodes::with_atts(
			$button,
			array(
				'title' => 'New',
				'size'  => 'lg',
			)
		);
		$this->assertSame(
			"[vc_row el_class='keep  me']\n[vc_column][vc_btn title=\"New\" color=blue size=\"lg\"][acme_slider speed=300]x[/acme_slider][/vc_column]\n[/vc_row]",
			Shortcodes::serialize( $nodes ),
			'Untouched tags and untouched attributes keep their exact text.'
		);
		$nodes[0]['children'][1]['children'][0] = Shortcodes::with_atts( $button, array( 'color' => null ) );
		$this->assertStringContainsString( "[vc_btn title='Old']", Shortcodes::serialize( $nodes ) );
	}

	public function test_large_layout_parses_quickly(): void {
		$row     = '[vc_row][vc_column width="1/3"][vc_column_text]Text[/vc_column_text][vc_btn title="Go"][/vc_column][vc_column width="2/3"][vc_row_inner][vc_column_inner][vc_single_image image="1"][/vc_column_inner][/vc_row_inner][/vc_column][/vc_row]';
		$content = str_repeat( $row, 400 );
		$start   = microtime( true );
		$nodes   = self::parse( $content );
		$this->assertCount( 400, $nodes );
		$this->assertSame( $content, Shortcodes::serialize( $nodes ) );
		$this->assertLessThan( 2.0, microtime( true ) - $start, '3,200 elements in under two seconds.' );
	}
}
