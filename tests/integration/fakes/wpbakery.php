<?php
/**
 * A stand-in for WPBakery Page Builder in tests (paid plugin, no licensed copy available): the pieces KuDiagnostics
 * uses — the WPB_VC_VERSION constant, vc_map() and WPBMap with a handful of elements registered the way WPBakery,
 * themes and add-ons register theirs, and the shortcodes themselves. Loaded as a must-use plugin when the runner is
 * started with KUDUY_FAKES=wpbakery. It checks KuDiagnostics' side of the contract, not WPBakery itself.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, Universal.Files.SeparateFunctionsFromOO -- test fake.

define( 'WPB_VC_VERSION', '8.0-fake' );

/**
 * Element registry with WPBakery's method names.
 */
class WPBMap {

	/**
	 * Registered elements.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $sc = array();

	public static function map( $tag, $settings ) {
		self::$sc[ $tag ] = $settings + array( 'base' => $tag );
	}

	public static function addAllMappedShortcodes() {
		foreach ( array_keys( self::$sc ) as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, static fn( $atts, $content = '' ) => '<div class="' . esc_attr( $tag ) . '">' . do_shortcode( (string) $content ) . '</div>' );
			}
		}
	}

	public static function getAllShortCodes() {
		return self::$sc;
	}

	public static function getShortCode( $tag ) {
		return self::$sc[ $tag ] ?? null;
	}

	public static function exists( $tag ) {
		return isset( self::$sc[ $tag ] );
	}
}

function vc_map( $settings ) {
	WPBMap::map( $settings['base'], $settings );
}

add_action(
	'init',
	static function () {
		$css   = array(
			'type'       => 'css_editor',
			'heading'    => 'CSS box',
			'param_name' => 'css',
			'group'      => 'Design Options',
		);
		$class = array(
			'type'        => 'textfield',
			'heading'     => 'Extra class name',
			'param_name'  => 'el_class',
			'description' => 'Style this element differently with a class name.',
		);
		$id    = array(
			'type'       => 'el_id',
			'heading'    => 'Element ID',
			'param_name' => 'el_id',
		);
		vc_map(
			array(
				'name'         => 'Row',
				'base'         => 'vc_row',
				'is_container' => true,
				'category'     => 'Content',
				'description'  => 'Place content elements inside the row',
				'params'       => array(
					array(
						'type'       => 'dropdown',
						'heading'    => 'Row stretch',
						'param_name' => 'full_width',
						'value'      => array(
							'Default'                 => '',
							'Stretch row'             => 'stretch_row',
							'Stretch row and content' => 'stretch_row_content',
						),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => 'Columns gap',
						'param_name' => 'gap',
						'value'      => array(
							'0px'  => '0',
							'10px' => '10',
							'35px' => '35',
						),
						'std'        => '0',
					),
					array(
						'type'       => 'checkbox',
						'heading'    => 'Equal height',
						'param_name' => 'equal_height',
						'value'      => array( 'Yes' => 'yes' ),
					),
					$id,
					$class,
					$css,
				),
			)
		);
		vc_map(
			array(
				'name'            => 'Inner Row',
				'base'            => 'vc_row_inner',
				'content_element' => false,
				'is_container'    => true,
				'params'          => array( $id, $class, $css ),
			)
		);
		foreach ( array(
			'vc_column'       => 'Column',
			'vc_column_inner' => 'Inner Column',
		) as $tag => $name ) {
			vc_map(
				array(
					'name'            => $name,
					'base'            => $tag,
					'is_container'    => true,
					'content_element' => false,
					'params'          => array(
						array(
							'type'       => 'dropdown',
							'heading'    => 'Width',
							'param_name' => 'width',
							'value'      => array(
								'1 column - 1/12'  => '1/12',
								'3 columns - 1/4'  => '1/4',
								'4 columns - 1/3'  => '1/3',
								'6 columns - 1/2'  => '1/2',
								'8 columns - 2/3'  => '2/3',
								'9 columns - 3/4'  => '3/4',
								'12 columns - 1/1' => '1/1',
							),
							'std'        => '1/1',
						),
						array(
							'type'       => 'column_offset',
							'heading'    => 'Responsiveness',
							'param_name' => 'offset',
						),
						$class,
						$css,
					),
				)
			);
		}//end foreach
		vc_map(
			array(
				'name'        => 'Text Block',
				'base'        => 'vc_column_text',
				'category'    => 'Content',
				'description' => 'A block of text with WYSIWYG editor',
				'params'      => array(
					array(
						'type'       => 'textarea_html',
						'holder'     => 'div',
						'heading'    => 'Text',
						'param_name' => 'content',
						'value'      => '<p>I am text block.</p>',
					),
					$id,
					$class,
					$css,
				),
			)
		);
		vc_map(
			array(
				'name'     => 'Custom Heading',
				'base'     => 'vc_custom_heading',
				'category' => 'Content',
				'params'   => array(
					array(
						'type'        => 'textarea',
						'heading'     => 'Text',
						'param_name'  => 'text',
						'admin_label' => true,
						'value'       => 'This is custom heading element',
					),
					array(
						'type'       => 'vc_link',
						'heading'    => 'URL (Link)',
						'param_name' => 'link',
					),
					array(
						'type'       => 'font_container',
						'param_name' => 'font_container',
					),
					$class,
					$css,
				),
			)
		);
		vc_map(
			array(
				'name'     => 'Single Image',
				'base'     => 'vc_single_image',
				'category' => 'Content',
				'params'   => array(
					array(
						'type'       => 'attach_image',
						'heading'    => 'Image',
						'param_name' => 'image',
					),
					array(
						'type'       => 'textfield',
						'heading'    => 'Image size',
						'param_name' => 'img_size',
						'value'      => 'thumbnail',
					),
					array(
						'type'       => 'dropdown',
						'heading'    => 'Image alignment',
						'param_name' => 'alignment',
						'value'      => array(
							'Left'   => '',
							'Right'  => 'right',
							'Center' => 'center',
						),
					),
					$class,
					$css,
				),
			)
		);
		vc_map(
			array(
				'name'     => 'Button',
				'base'     => 'vc_btn',
				'category' => 'Content',
				'params'   => array(
					array(
						'type'       => 'textfield',
						'heading'    => 'Text',
						'param_name' => 'title',
						'value'      => 'Text on the button',
					),
					array(
						'type'       => 'vc_link',
						'heading'    => 'URL (Link)',
						'param_name' => 'link',
					),
					array(
						'type'       => 'dropdown',
						'heading'    => 'Color',
						'param_name' => 'color',
						'value'      => array(
							'Grey' => 'grey',
							'Blue' => 'blue',
							'Red'  => 'danger',
						),
						'std'        => 'grey',
					),
					array(
						'type'       => 'checkbox',
						'heading'    => 'Set full width button?',
						'param_name' => 'button_block',
						'value'      => array( 'Yes' => 'true' ),
						'dependency' => array(
							'element'            => 'align',
							'value_not_equal_to' => 'inline',
						),
					),
					$class,
					$css,
				),
			)
		);
		vc_map(
			array(
				'name'     => 'Raw HTML',
				'base'     => 'vc_raw_html',
				'category' => 'Structure',
				'params'   => array(
					array(
						'type'       => 'textarea_raw_html',
						'heading'    => 'Raw HTML',
						'param_name' => 'content',
					),
					$class,
				),
			)
		);
		vc_map(
			array(
				'name'     => 'Progress Bar',
				'base'     => 'vc_progress_bar',
				'category' => 'Content',
				'params'   => array(
					array(
						'type'       => 'param_group',
						'heading'    => 'Values',
						'param_name' => 'values',
						'params'     => array(
							array(
								'type'       => 'textfield',
								'param_name' => 'label',
							),
							array(
								'type'       => 'textfield',
								'param_name' => 'value',
							),
						),
					),
					$class,
				),
			)
		);
		vc_map(
			array(
				'name'         => 'Tabs',
				'base'         => 'vc_tta_tabs',
				'category'     => 'Content',
				'is_container' => true,
				'as_parent'    => array( 'only' => 'vc_tta_section' ),
				'params'       => array( $class ),
			)
		);
		vc_map(
			array(
				'name'            => 'Section',
				'base'            => 'vc_tta_section',
				'is_container'    => true,
				'content_element' => false,
				'as_child'        => array( 'only' => 'vc_tta_tabs,vc_tta_tour,vc_tta_accordion' ),
				'params'          => array(
					array(
						'type'       => 'textfield',
						'heading'    => 'Title',
						'param_name' => 'title',
					),
					array(
						'type'       => 'el_id',
						'heading'    => 'Section ID',
						'param_name' => 'tab_id',
					),
				),
			)
		);
		// An add-on's element, registered like any third party would.
		vc_map(
			array(
				'name'     => 'Acme Pricing Table',
				'base'     => 'acme_pricing',
				'category' => array( 'Acme', 'Content' ),
				'params'   => array(
					array(
						'type'       => 'textfield',
						'heading'    => 'Plan',
						'param_name' => 'plan',
					),
				),
			)
		);
		WPBMap::addAllMappedShortcodes();
	},
	5
);
