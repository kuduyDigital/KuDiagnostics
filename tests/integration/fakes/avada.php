<?php
/**
 * A stand-in for Avada Builder in tests (paid, no licensed copy in CI): the pieces KuDiagnostics uses, with the names
 * and shapes read from Avada Builder 3.16.4 — FUSION_BUILDER_VERSION, fusion_builder_map() filling the global
 * $all_fusion_builder_elements, awb_maybe_init_element_maps() firing fusion_builder_before_init, a handful of elements
 * with real parameter shapes, the layout shortcodes, and the Library and Layout post types. Loaded as a must-use plugin
 * with KUDUY_FAKES=avada. It checks KuDiagnostics' side of the contract, not Avada itself.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable -- test fake with Avada's own names.

define( 'FUSION_BUILDER_VERSION', '3.16.4-fake' );

$GLOBALS['all_fusion_builder_elements'] = array();

function fusion_builder_map( $module ) {
	$GLOBALS['all_fusion_builder_elements'][ $module['shortcode'] ] = $module;
}

function awb_maybe_init_element_maps() {
	if ( ! did_action( 'fusion_builder_before_init' ) ) {
		do_action( 'fusion_builder_before_init' );
	}
}

add_action(
	'fusion_builder_before_init',
	static function () {
		$visibility = array(
			'type'       => 'checkbox_button_set',
			'heading'    => 'Element Visibility',
			'param_name' => 'hide_on_mobile',
			'value'      => array(
				'small-visibility'  => 'Small Screen',
				'medium-visibility' => 'Medium Screen',
				'large-visibility'  => 'Large Screen',
			),
			'default'    => 'small-visibility,medium-visibility,large-visibility',
		);
		$class      = array(
			'type'       => 'textfield',
			'heading'    => 'CSS Class',
			'param_name' => 'class',
			'value'      => '',
		);
		$margin     = array(
			'type'       => 'dimension',
			'heading'    => 'Margin',
			'param_name' => 'margin_bottom',
			'responsive' => array( 'state' => 'large' ),
			'value'      => '',
		);
		fusion_builder_map(
			array(
				'name'      => 'Container',
				'shortcode' => 'fusion_builder_container',
				'params'    => array(
					array(
						'type'       => 'radio_button_set',
						'heading'    => 'Interior Content Width',
						'param_name' => 'hundred_percent',
						'value'      => array(
							'yes' => '100% Width',
							'no'  => 'Site Width',
						),
						'default'    => 'no',
					),
					array(
						'type'       => 'colorpickeralpha',
						'heading'    => 'Background Color',
						'param_name' => 'background_color',
						'value'      => '',
					),
					array(
						'type'       => 'upload',
						'heading'    => 'Background Image',
						'param_name' => 'background_image',
						'value'      => '',
					),
					array(
						'type'       => 'dimension',
						'heading'    => 'Padding',
						'param_name' => 'padding_top',
						'responsive' => array( 'state' => 'large' ),
						'value'      => '',
					),
					array(
						'type'       => 'textfield',
						'heading'    => 'Admin Label',
						'param_name' => 'admin_label',
						'value'      => '',
					),
					$visibility,
					$class,
				),
			)
		);
		foreach ( array( 'fusion_builder_row' => 'Row', 'fusion_builder_row_inner' => 'Nested Columns' ) as $tag => $name ) {
			fusion_builder_map(
				array(
					'name'      => $name,
					'shortcode' => $tag,
					'params'    => array(),
				)
			);
		}
		foreach ( array( 'fusion_builder_column' => 'Column', 'fusion_builder_column_inner' => 'Nested Column' ) as $tag => $name ) {
			fusion_builder_map(
				array(
					'name'      => $name,
					'shortcode' => $tag,
					'params'    => array(
						array(
							'type'       => 'select',
							'heading'    => 'Width',
							'param_name' => 'type',
							'value'      => array(
								'1_1' => '1/1',
								'1_2' => '1/2',
								'1_3' => '1/3',
								'2_3' => '2/3',
								'1_4' => '1/4',
								'3_4' => '3/4',
							),
							'default'    => '1_1',
							'responsive' => array( 'state' => 'large' ),
						),
						array(
							'type'       => 'colorpickeralpha',
							'heading'    => 'Background Color',
							'param_name' => 'background_color',
							'value'      => '',
						),
						$visibility,
						$class,
					),
				)
			);
		}
		fusion_builder_map(
			array(
				'name'      => 'Title',
				'shortcode' => 'fusion_title',
				'params'    => array(
					array(
						'type'       => 'radio_button_set',
						'heading'    => 'Title Type',
						'param_name' => 'title_type',
						'value'      => array(
							'text'      => 'Text',
							'rotating'  => 'Rotating',
							'highlight' => 'Highlight',
						),
						'default'    => 'text',
					),
					array(
						'type'        => 'tinymce',
						'heading'     => 'Title',
						'param_name'  => 'element_content',
						'value'       => 'Your Content Goes Here',
					),
					array(
						'type'       => 'radio_button_set',
						'heading'    => 'HTML Heading Size',
						'param_name' => 'size',
						'value'      => array(
							'1' => 'H1',
							'2' => 'H2',
							'3' => 'H3',
							'4' => 'H4',
							'5' => 'H5',
							'6' => 'H6',
						),
						'default'    => '1',
					),
					array(
						'type'       => 'colorpickeralpha',
						'heading'    => 'Font Color',
						'param_name' => 'text_color',
						'value'      => '',
					),
					$margin,
					$visibility,
					$class,
				),
			)
		);
		fusion_builder_map(
			array(
				'name'      => 'Text Block',
				'shortcode' => 'fusion_text',
				'params'    => array(
					array(
						'type'       => 'tinymce',
						'heading'    => 'Content',
						'param_name' => 'element_content',
						'value'      => '',
					),
					$visibility,
					$class,
				),
			)
		);
		fusion_builder_map(
			array(
				'name'      => 'Button',
				'shortcode' => 'fusion_button',
				'params'    => array(
					array(
						'type'       => 'link_selector',
						'heading'    => 'Button URL',
						'param_name' => 'link',
						'value'      => '',
					),
					array(
						'type'       => 'textfield',
						'heading'    => 'Button Text',
						'param_name' => 'element_content',
						'value'      => 'Button Text',
					),
					array(
						'type'       => 'radio_button_set',
						'heading'    => 'Link Target',
						'param_name' => 'target',
						'value'      => array(
							'_self'  => '_self',
							'_blank' => '_blank',
						),
						'default'    => '_self',
					),
					array(
						'type'       => 'select',
						'heading'    => 'Button Color',
						'param_name' => 'color',
						'value'      => array(
							'default' => 'Default',
							'custom'  => 'Custom',
							'green'   => 'Green',
						),
						'default'    => 'default',
					),
					$margin,
					$visibility,
					$class,
				),
			)
		);
		fusion_builder_map(
			array(
				'name'      => 'Image',
				'shortcode' => 'fusion_imageframe',
				'params'    => array(
					array(
						'type'       => 'upload',
						'heading'    => 'Image',
						'param_name' => 'element_content',
						'value'      => '',
					),
					array(
						'type'       => 'textfield',
						'heading'    => 'Image ID',
						'param_name' => 'image_id',
						'value'      => '',
					),
					array(
						'type'       => 'textfield',
						'heading'    => 'Max Width',
						'param_name' => 'max_width',
						'value'      => '',
					),
					$visibility,
					$class,
				),
			)
		);
		fusion_builder_map(
			array(
				'name'          => 'Tabs',
				'shortcode'     => 'fusion_tabs',
				'multi'         => 'multi_element_parent',
				'element_child' => 'fusion_tab',
				'params'        => array(
					array(
						'type'       => 'tinymce',
						'heading'    => 'Content',
						'param_name' => 'element_content',
						'value'      => '[fusion_tab title="Your Content Goes Here"]Your Content Goes Here[/fusion_tab]',
					),
					$class,
				),
			)
		);
		fusion_builder_map(
			array(
				'name'      => 'Tab',
				'shortcode' => 'fusion_tab',
				'params'    => array(
					array(
						'type'       => 'textfield',
						'heading'    => 'Title',
						'param_name' => 'title',
						'value'      => '',
					),
					array(
						'type'       => 'tinymce',
						'heading'    => 'Tab Content',
						'param_name' => 'element_content',
						'value'      => '',
					),
				),
			)
		);
		// An add-on's element, registered like any third party would.
		fusion_builder_map(
			array(
				'name'      => 'Acme Pricing',
				'shortcode' => 'acme_avada_pricing',
				'params'    => array(
					array(
						'type'       => 'textfield',
						'heading'    => 'Plan',
						'param_name' => 'plan',
						'value'      => '',
					),
				),
			)
		);
	}
);

add_action(
	'init',
	static function () {
		foreach ( array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_row_inner', 'fusion_builder_column', 'fusion_builder_column_inner', 'fusion_title', 'fusion_text', 'fusion_button', 'fusion_imageframe', 'fusion_tabs', 'fusion_tab', 'fusion_global', 'acme_avada_pricing' ) as $tag ) {
			add_shortcode( $tag, static fn( $atts, $content = '' ) => '<div class="' . esc_attr( $tag ) . '">' . do_shortcode( (string) $content ) . '</div>' );
		}
		foreach ( array( 'fusion_element' => 'element_category', 'fusion_template' => 'template_category', 'fusion_tb_layout' => '', 'fusion_tb_section' => 'fusion_tb_category' ) as $type => $taxonomy ) {
			register_post_type(
				$type,
				array(
					'public'       => false,
					'show_ui'      => true,
					'show_in_menu' => false,
					'map_meta_cap' => true,
					'supports'     => array( 'title', 'editor', 'revisions' ),
				)
			);
			if ( '' !== $taxonomy ) {
				register_taxonomy( $taxonomy, array( $type ), array( 'hierarchical' => true, 'show_ui' => false ) );
			}
		}
	},
	5
);
