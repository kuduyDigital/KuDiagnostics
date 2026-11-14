<?php
/**
 * A dynamic tag for the tests: free Elementor registers none, so ElementorDocsTest registers this one through
 * Elementor's dynamic tags manager. Loaded only when Elementor is active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

/**
 * Text tag that prints a fixed marker and its "suffix" setting.
 */
final class ElementorTestTag extends \Elementor\Core\DynamicTags\Tag {

	public function get_name(): string {
		return 'kuduy-test-tag';
	}

	public function get_title(): string {
		return 'Kuduy test tag';
	}

	public function get_group(): string {
		return 'site';
	}

	/**
	 * Tag kinds.
	 *
	 * @return list<string>
	 */
	public function get_categories(): array {
		return array( 'text' );
	}

	protected function register_controls(): void {
		$this->add_control(
			'suffix',
			array(
				'label' => 'Suffix',
				'type'  => 'text',
			)
		);
	}

	public function render(): void {
		echo 'KUDUY-DYNAMIC-' . esc_html( (string) $this->get_settings( 'suffix' ) );
	}
}
