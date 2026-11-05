<?php
/**
 * Tool: kuduy-elementor-verify.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Checks what visitors get: renders the Elementor content (Elementor's own front-end renderer) and, for published
 * pages, also fetches the live URL. Reports which expected element ids and texts appear. Never claims a visual
 * check it did not make.
 */
final class ElementorVerify implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-elementor-verify';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Check an Elementor page', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Checks an Elementor page after changes: renders its content with Elementor\'s front-end renderer and, for published pages, fetches the live URL. Reports which expected element ids and texts are present. This checks the HTML, not how it looks; say so when reporting.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expect_ids'   => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'maxItems' => 100,
				),
				'expect_texts' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'maxItems' => 50,
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$strings = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'rendered'      => array( 'type' => 'boolean' ),
				'missing_ids'   => $strings,
				'missing_texts' => $strings,
				'live_checked'  => array( 'type' => 'boolean' ),
				'live_status'   => array( 'type' => array( 'integer', 'null' ) ),
				'live_missing'  => $strings,
				'notes'         => $strings,
				'ok'            => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'rendered', 'missing_ids', 'missing_texts', 'live_checked', 'ok', 'notes' ),
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
	public function capability( array $input ): string|array {
		$id = (int) ( $input['id'] ?? 0 );
		return $id > 0 && get_post( $id ) ? array( 'edit_post', $id ) : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Elementor::available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function execute( array $input ): array {
		$doc  = Elementor::document( (int) $input['id'] );
		$post = $doc->get_post();
		AuditLog::target( 'post', (string) $post->ID );
		$ids   = array_map( 'strval', (array) ( $input['expect_ids'] ?? array() ) );
		$texts = array_map( 'strval', (array) ( $input['expect_texts'] ?? array() ) );
		$notes = array();

		$html          = (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post->ID );
		$missing_ids   = self::missing_ids( $html, $ids );
		$missing_texts = self::missing_texts( $html, $texts );

		$live_checked = false;
		$live_status  = null;
		$live_missing = array();
		if ( 'publish' === $post->post_status && '' === $post->post_password ) {
			$response = wp_remote_get(
				(string) get_permalink( $post ),
				array(
					'timeout'     => 10,
					'redirection' => 3,
				)
			);
			if ( is_wp_error( $response ) ) {
				$notes[] = 'The live page could not be fetched from the server itself (' . $response->get_error_message() . '); only the rendered content was checked.';
			} else {
				$live_checked = true;
				$live_status  = (int) wp_remote_retrieve_response_code( $response );
				$body         = (string) wp_remote_retrieve_body( $response );
				$live_missing = array_merge( self::missing_ids( $body, $ids ), self::missing_texts( $body, $texts ) );
				if ( 200 !== $live_status ) {
					$notes[] = 'The live page answered HTTP ' . $live_status . '.';
				} elseif ( $live_missing ) {
					$notes[] = 'Rendered content and live page differ: a page cache may still serve the old version.';
				}
			}
		} else {
			$notes[] = 'Not published (or password-protected): only the rendered content was checked.';
		}//end if
		$notes[] = 'This checks the HTML, not the visual appearance.';
		return array(
			'rendered'      => '' !== $html,
			'missing_ids'   => $missing_ids,
			'missing_texts' => $missing_texts,
			'live_checked'  => $live_checked,
			'live_status'   => $live_status,
			'live_missing'  => $live_missing,
			'notes'         => $notes,
			'ok'            => '' !== $html && ! $missing_ids && ! $missing_texts && ( ! $live_checked || ( 200 === $live_status && ! $live_missing ) ),
		);
	}

	/**
	 * Expected element ids without a data-id attribute in the HTML.
	 *
	 * @param string       $html HTML.
	 * @param list<string> $ids  Ids.
	 * @return list<string>
	 */
	private static function missing_ids( string $html, array $ids ): array {
		return array_values( array_filter( $ids, static fn( string $id ): bool => ! str_contains( $html, 'data-id="' . $id . '"' ) ) );
	}

	/**
	 * Expected texts not in the visible text.
	 *
	 * @param string       $html  HTML.
	 * @param list<string> $texts Texts.
	 * @return list<string>
	 */
	private static function missing_texts( string $html, array $texts ): array {
		$visible = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES );
		return array_values( array_filter( $texts, static fn( string $text ): bool => ! str_contains( $visible, $text ) ) );
	}
}
