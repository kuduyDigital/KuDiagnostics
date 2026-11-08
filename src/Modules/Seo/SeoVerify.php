<?php
/**
 * Tool: kuduy-seo-verify.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * What visitors and search engines get: fetches the published page as the site serves it and reads the SEO tags in
 * its `<head>` with core's WP_HTML_Tag_Processor; optionally compares them with expected values. Unpublished posts
 * cannot be fetched; then only the SEO plugin's own rendered values are reported.
 */
final class SeoVerify implements Tool {

	private const TAGS = array(
		'description'         => 'description',
		'robots'              => 'robots',
		'og:title'            => 'og.title',
		'og:description'      => 'og.description',
		'twitter:title'       => 'twitter.title',
		'twitter:description' => 'twitter.description',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-seo-verify';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Check a page\'s SEO tags', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Fetches a published post\'s live page and reports the SEO tags in its <head>: title, meta description, robots, canonical, Open Graph and X. Pass "expect" (same fields as kuduy-seo-update; noindex as true/false) to compare; mismatches are listed. A page cache can still serve old tags after a change. Drafts cannot be fetched: then only the SEO plugin\'s own values are shown.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$text = array(
			'type'      => 'string',
			'maxLength' => 2000,
		);
		$pair = array(
			'type'                 => 'object',
			'properties'           => array(
				'title'       => $text,
				'description' => $text,
			),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expect'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'       => $text,
						'description' => $text,
						'canonical'   => $text,
						'noindex'     => array( 'type' => 'boolean' ),
						'og'          => $pair,
						'twitter'     => $pair,
					),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'post_id' ),
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
				'post_id'      => array( 'type' => 'integer' ),
				'url'          => array( 'type' => 'string' ),
				'live_checked' => array( 'type' => 'boolean' ),
				'http_status'  => array( 'type' => array( 'integer', 'null' ) ),
				'head'         => array( 'type' => array( 'object', 'null' ) ),
				'rendered'     => array( 'type' => array( 'object', 'null' ) ),
				'mismatches'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'field'    => array( 'type' => 'string' ),
							'expected' => array( 'type' => array( 'string', 'boolean' ) ),
							'found'    => array( 'type' => array( 'string', 'boolean', 'null' ) ),
						),
					),
				),
				'ok'           => array( 'type' => 'boolean' ),
				'notes'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'post_id', 'live_checked', 'head', 'mismatches', 'ok', 'notes' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
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
		return isset( $input['post_id'] ) ? array( 'edit_post', (int) $input['post_id'] ) : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post is missing.
	 */
	public function execute( array $input ): array {
		$post = Content::post( (int) $input['post_id'] );
		if ( ! $post ) {
			throw new ToolError( 'No post, page or custom post type entry with this id.' );
		}
		AuditLog::target( 'post', (string) $post->ID );
		$notes    = array();
		$rendered = null;
		try {
			$adapter  = Seo::adapter();
			$values   = $adapter->rendered( $post->ID );
			$rendered = null === $values ? null : Seo::nest( $values );
		} catch ( ToolError $e ) {
			$notes[] = $e->getMessage();
		}
		$url    = (string) get_permalink( $post );
		$head   = null;
		$status = null;
		if ( 'publish' === $post->post_status && '' === $post->post_password ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 3,
				)
			);
			if ( is_wp_error( $response ) ) {
				$notes[] = 'The live page could not be fetched from the server itself (' . $response->get_error_message() . ').';
			} else {
				$status = (int) wp_remote_retrieve_response_code( $response );
				$head   = self::parse( (string) wp_remote_retrieve_body( $response ) );
				if ( 200 !== $status ) {
					$notes[] = 'The live page answered HTTP ' . $status . '.';
				}
			}
		} else {
			$notes[] = 'Not published (or password-protected): the live page cannot be checked.';
		}
		$mismatches = null === $head ? array() : self::compare( $head, (array) ( $input['expect'] ?? array() ) );
		if ( $mismatches ) {
			$notes[] = 'Some tags differ from what was expected. A page cache may still serve the old version; clear it and check again.';
		}
		$visibility = Seo::visibility_note();
		if ( null !== $visibility ) {
			$notes[] = $visibility;
		}
		return array(
			'post_id'      => $post->ID,
			'url'          => $url,
			'live_checked' => null !== $head,
			'http_status'  => $status,
			'head'         => null === $head ? null : Seo::nest( $head ),
			'rendered'     => $rendered,
			'mismatches'   => $mismatches,
			'ok'           => null !== $head && 200 === $status && ! $mismatches,
			'notes'        => $notes,
		);
	}

	/**
	 * SEO tags of an HTML page (first occurrence of each).
	 *
	 * @param string $html Page HTML.
	 * @return array<string, mixed> Flat: title, description, robots, canonical, og.title, …
	 */
	public static function parse( string $html ): array {
		$end  = stripos( $html, '</head>' );
		$tags = new \WP_HTML_Tag_Processor( false === $end ? $html : substr( $html, 0, $end ) );
		$out  = array(
			'title'     => null,
			'canonical' => null,
		);
		foreach ( self::TAGS as $field ) {
			$out[ $field ] = null;
		}
		while ( $tags->next_tag() ) {
			$tag = $tags->get_tag();
			if ( 'TITLE' === $tag && null === $out['title'] ) {
				$out['title'] = html_entity_decode( trim( $tags->get_modifiable_text() ), ENT_QUOTES | ENT_HTML5 );
			} elseif ( 'LINK' === $tag && null === $out['canonical'] && 'canonical' === strtolower( (string) $tags->get_attribute( 'rel' ) ) ) {
				$out['canonical'] = (string) $tags->get_attribute( 'href' );
			} elseif ( 'META' === $tag ) {
				$key = strtolower( (string) ( $tags->get_attribute( 'name' ) ?? $tags->get_attribute( 'property' ) ) );
				if ( isset( self::TAGS[ $key ] ) && null === $out[ self::TAGS[ $key ] ] ) {
					$out[ self::TAGS[ $key ] ] = (string) $tags->get_attribute( 'content' );
				}
			}
		}
		return $out;
	}

	/**
	 * Differences between found tags and expected values.
	 *
	 * @param array<string, mixed> $head   Flat parsed tags.
	 * @param array<string, mixed> $expect Expected (nested input).
	 * @return list<array{field:string, expected:string|bool, found:string|bool|null}>
	 */
	private static function compare( array $head, array $expect ): array {
		$out = array();
		foreach ( array( 'title', 'description', 'canonical', 'og.title', 'og.description', 'twitter.title', 'twitter.description' ) as $field ) {
			$parts    = explode( '.', $field );
			$expected = 2 === count( $parts ) ? ( $expect[ $parts[0] ][ $parts[1] ] ?? null ) : ( $expect[ $field ] ?? null );
			if ( null !== $expected && trim( (string) $expected ) !== trim( (string) $head[ $field ] ) ) {
				$out[] = array(
					'field'    => $field,
					'expected' => (string) $expected,
					'found'    => $head[ $field ],
				);
			}
		}
		if ( isset( $expect['noindex'] ) ) {
			$found = str_contains( strtolower( (string) $head['robots'] ), 'noindex' );
			if ( $found !== (bool) $expect['noindex'] ) {
				$out[] = array(
					'field'    => 'noindex',
					'expected' => (bool) $expect['noindex'],
					'found'    => $found,
				);
			}
		}
		return $out;
	}
}
