<?php
/**
 * Avada Builder markup through the shared shortcode parser (test matrix AV1–AV3, decision D31): a page with the
 * structure of a real Avada 7.16 page (containers, rows, columns, nested columns, duplicated attributes, responsive
 * attributes, a global element, base64 settings, third-party and unknown shortcodes) round-trips byte for byte, has the
 * expected tree, and an edit rewrites only the changed tag. Needs no plugin, so it runs in every job.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Modules\Avada\AvadaLayout;
use Kuduy\Mcp\Modules\WpBakery\Shortcodes;
use PHPUnit\Framework\TestCase;

final class AvadaParserTest extends TestCase {

	/**
	 * The fixture page.
	 */
	private static function page(): string {
		return rtrim( (string) file_get_contents( __DIR__ . '/fixtures/avada-page.txt' ), "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	/**
	 * Parse like the Avada module does.
	 *
	 * @param string $content Content.
	 * @return list<array<string, mixed>>
	 */
	private static function parse( string $content ): array {
		return Shortcodes::parse( $content, static fn( string $name ) => str_starts_with( $name, 'fusion_' ) );
	}

	public function test_real_page_round_trips_byte_for_byte(): void {
		$page = self::page();
		$this->assertSame( $page, Shortcodes::serialize( self::parse( $page ) ) );
		foreach ( array( '', 'plain text [1]', '[fusion_builder_container][/fusion_builder_container]', '[fusion_global id="1"]' ) as $content ) {
			$this->assertSame( $content, Shortcodes::serialize( self::parse( $content ) ) );
		}
	}

	public function test_tree_shape(): void {
		$nodes = self::parse( self::page() );
		$this->assertSame( array( 'fusion_builder_container', 'fusion_builder_container' ), array_column( $nodes, 'tag' ) );
		$this->assertSame( 'container', AvadaLayout::role( $nodes[0] ) );
		$row = $nodes[0]['children'][0];
		$this->assertSame( 'row', AvadaLayout::role( $row ) );
		[ $left, $right ] = $row['children'];
		$this->assertSame( '1_2', AvadaLayout::width( $left ) );
		$this->assertSame( array( 'fusion_title', 'fusion_title', 'fusion_global' ), array_column( $left['children'], 'tag' ) );
		$this->assertFalse( $left['children'][2]['closed'], 'A global element is a single tag.' );
		$this->assertSame( 'global', AvadaLayout::role( $left['children'][2] ) );
		$inner = $right['children'][1];
		$this->assertSame( 'fusion_builder_row_inner', $inner['tag'] );
		$this->assertSame( array( 'fusion_builder_column_inner', 'fusion_builder_column_inner' ), array_column( $inner['children'], 'tag' ) );
		$this->assertSame( 'acme_slider', $inner['children'][1]['children'][0]['tag'], 'A third-party shortcode with a closing tag is an element.' );
		$this->assertSame( 'text', $inner['children'][1]['children'][1]['type'], 'An unknown single tag stays text, untouched.' );

		$split = AvadaLayout::attrs( $left );
		$this->assertSame( '1_2', $split['attrs']['type'], 'A duplicated attribute is shown once.' );
		$this->assertSame( '0', $split['responsive']['medium']['order'] );
		$this->assertSame( '20px', AvadaLayout::attrs( $left['children'][1] )['responsive']['small']['margin_bottom'] );
		$this->assertSame( '4_5', AvadaLayout::attrs( $right )['responsive']['medium']['type'] );
		$this->assertSame( array(), AvadaLayout::attrs( $nodes[1] )['attrs']['logics'], 'base64 JSON settings are shown decoded.' );
		$this->assertSame( 'Left & "quoted" [1] text.', wp_strip_all_tags( html_entity_decode( Shortcodes::serialize( $inner['children'][0]['children'][0]['children'] ) ) ) );
	}

	public function test_an_edit_rewrites_only_the_changed_tag(): void {
		$page  = self::page();
		$nodes = self::parse( $page );
		$title = $nodes[0]['children'][0]['children'][0]['children'][0];
		$nodes[0]['children'][0]['children'][0]['children'][0] = AvadaLayout::with_atts( $title, AvadaLayout::atts_from_input( array( 'attrs' => array( 'size' => '3', 'class' => 'Say "hi" [now]' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- two settings.
		$after = Shortcodes::serialize( $nodes );
		$this->assertStringContainsString( 'size="3"', $after );
		$this->assertStringContainsString( 'class="Say &quot;hi&quot; &#91;now&#93;"', $after, 'Quotes and brackets cannot break the tag.' );
		$this->assertSame( strlen( $page ) - strlen( 'size="5"' ) + strlen( 'size="3"' ) + strlen( ' class="Say &quot;hi&quot; &#91;now&#93;"' ), strlen( $after ), 'Nothing else changed.' );
		$column                                 = $nodes[0]['children'][0]['children'][0];
		$nodes[0]['children'][0]['children'][0] = AvadaLayout::with_atts( $column, AvadaLayout::atts_from_input( array( 'width' => '1/3' ) ) );
		$this->assertStringContainsString( '[fusion_builder_column type="1_3" type="1_3" layout="1_3" align_self="auto"', Shortcodes::serialize( $nodes ), 'A duplicated attribute changes everywhere, in place.' );
	}

	public function test_widths(): void {
		$this->assertSame( array( '1_3', '2_3' ), AvadaLayout::widths( '1/3 + 2_3' ) );
		$this->expectException( \Kuduy\Mcp\Mcp\ToolError::class );
		AvadaLayout::widths( '1_2 + 7_8' );
	}
}
