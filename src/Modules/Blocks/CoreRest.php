<?php
/**
 * Core REST routes run in-process as the current user (decision D18 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Templates, Global Styles, widgets and block types go through WordPress's own REST controllers with
 * rest_do_request(), so core's permission checks, kses filtering, custom-CSS validation and theme-file handling apply
 * exactly as in the Site Editor.
 */
final class CoreRest {

	/**
	 * Run a core route; returns the response data.
	 *
	 * @param string               $method GET | POST | DELETE.
	 * @param string               $route  Route, e.g. /wp/v2/templates.
	 * @param array<string, mixed> $params Query (GET, DELETE) or body (POST) parameters.
	 * @return mixed
	 * @throws ToolError When core refuses or fails.
	 */
	public static function call( string $method, string $route, array $params = array() ): mixed {
		$request = new \WP_REST_Request( $method, $route );
		if ( 'POST' === $method ) {
			$request->set_body_params( $params );
		} else {
			$request->set_query_params( $params );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error = $response->as_error();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WordPress refused: ' . wp_strip_all_tags( $error->get_error_message() ) . ' Nothing was changed.' );
		}
		return $response->get_data();
	}

	/**
	 * Raw value of a REST field that may be { raw, rendered } or a plain string.
	 *
	 * @param mixed $field Field.
	 */
	public static function raw( mixed $field ): string {
		if ( is_array( $field ) ) {
			return (string) ( $field['raw'] ?? $field['rendered'] ?? '' );
		}
		return is_scalar( $field ) ? (string) $field : '';
	}

	/**
	 * Object input schema.
	 *
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	public static function object_input( string $description ): array {
		return array(
			'type'        => 'object',
			'description' => $description,
		);
	}
}
