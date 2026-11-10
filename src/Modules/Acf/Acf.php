<?php
/**
 * Shared ACF rules (RESEARCH.md §10a, D14 A).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Acf;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Objects, field resolution, validation and value shapes for the kuduy-acf-* tools. Only fields of groups that ACF
 * shows on the object are accepted (the same location "screen" ACF's own REST API builds, without its show_in_rest
 * filter: KuDiagnostics acts like the edit screen). update_field() is always called with a field key, so ACF never writes a
 * "dummy" field under a free name.
 */
final class Acf {

	public const OBJECTS = array( 'post', 'user', 'term' );

	/**
	 * Field types that hold no value (layout only).
	 */
	private const LAYOUT = array( 'tab', 'message', 'accordion', 'separator', 'output' );

	/**
	 * Field types whose value must be one of the field's choices.
	 */
	private const CHOICE = array( 'select', 'radio', 'button_group', 'checkbox' );

	/**
	 * Tool availability.
	 */
	public static function available(): bool|string {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'update_field' ) ? true : 'Advanced Custom Fields is not active';
	}

	/**
	 * Object input schema (object + id).
	 *
	 * @return array<string, mixed>
	 */
	public static function object_input(): array {
		return array(
			'object' => array(
				'type' => 'string',
				'enum' => self::OBJECTS,
			),
			'id'     => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
	}

	/**
	 * ACF's post_id for an object: 123, user_5, term_7.
	 *
	 * @param string $kind post | user | term.
	 * @param int    $id     Object id.
	 */
	public static function acf_id( string $kind, int $id ): int|string {
		return 'post' === $kind ? $id : $kind . '_' . $id;
	}

	/**
	 * Whether the object exists (posts of an editable type, any user, any term).
	 *
	 * @param string $kind post | user | term.
	 * @param int    $id     Object id.
	 */
	public static function exists( string $kind, int $id ): bool {
		return match ( $kind ) {
			'post'  => get_post( $id ) instanceof \WP_Post,
			'user'  => get_userdata( $id ) instanceof \WP_User,
			default => get_term( $id ) instanceof \WP_Term,
		};
	}

	/**
	 * Capability check for reading or changing the object's fields.
	 *
	 * @param string $kind post | user | term.
	 * @param int    $id     Object id.
	 * @param bool   $write  Change (true) or read.
	 * @return array{0: string, 1: int}
	 */
	public static function capability( string $kind, int $id, bool $write ): array {
		return match ( $kind ) {
			'post'  => array( $write ? 'edit_post' : 'read_post', $id ),
			'user'  => array( 'edit_user', $id ),
			default => array( 'edit_term', $id ),
		};
	}

	/**
	 * Location screen for acf_get_field_groups(), as ACF's REST API builds it.
	 *
	 * @param string $kind post | user | term.
	 * @param int    $id     Object id.
	 * @return array<string, mixed>
	 */
	public static function screen( string $kind, int $id ): array {
		if ( 'user' === $kind ) {
			return array(
				'user_id' => $id,
				'rest'    => true,
			);
		}
		if ( 'term' === $kind ) {
			$term = get_term( $id );
			return array( 'taxonomy' => $term instanceof \WP_Term ? $term->taxonomy : '' );
		}
		return array( 'post_id' => $id );
	}

	/**
	 * Field groups ACF shows on the object.
	 *
	 * @param string $kind post | user | term.
	 * @param int    $id     Object id.
	 * @return list<array<string, mixed>>
	 */
	public static function groups( string $kind, int $id ): array {
		return array_values( (array) acf_get_field_groups( self::screen( $kind, $id ) ) );
	}

	/**
	 * Value fields shown on the object, by field key.
	 *
	 * @param string $kind post | user | term.
	 * @param int    $id     Object id.
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( string $kind, int $id ): array {
		$out = array();
		foreach ( self::groups( $kind, $id ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( ! in_array( $field['type'] ?? '', self::LAYOUT, true ) && ! empty( $field['key'] ) ) {
					$out[ (string) $field['key'] ] = $field;
				}
			}
		}
		return $out;
	}

	/**
	 * Resolve the given field keys or names among the fields shown on the object.
	 *
	 * @param array<string, array<string, mixed>> $fields Fields shown on the object (by key).
	 * @param array<mixed>                        $values Selector => value.
	 * @return array<string, array{field: array<string, mixed>, value: mixed}> By field key.
	 * @throws ToolError When a selector is not a field shown on the object (nothing is written).
	 */
	public static function resolve( array $fields, array $values ): array {
		$by_name = array();
		foreach ( $fields as $key => $field ) {
			$by_name[ (string) ( $field['name'] ?? '' ) ] = $key;
		}
		$out     = array();
		$unknown = array();
		foreach ( $values as $selector => $value ) {
			$key = isset( $fields[ (string) $selector ] ) ? (string) $selector : ( $by_name[ (string) $selector ] ?? null );
			if ( null === $key ) {
				$unknown[] = (string) $selector;
				continue;
			}
			$out[ $key ] = array(
				'field' => $fields[ $key ],
				'value' => $value,
			);
		}
		if ( $unknown ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'Not an ACF field shown on this object: ' . implode( ', ', $unknown ) . '. Use kuduy-acf-get to see its fields. Nothing was changed.' );
		}
		return $out;
	}

	/**
	 * Validate every value the way ACF's forms do (acf_validate_value: required, per-type rules), plus choice fields
	 * (ACF accepts any value there outside its forms). Nothing is written when anything fails.
	 *
	 * @param array<string, array{field: array<string, mixed>, value: mixed}> $resolved Resolved values.
	 * @throws ToolError Listing every problem.
	 */
	public static function validate( array $resolved ): void {
		$errors = array();
		foreach ( $resolved as $key => $item ) {
			$field  = $item['field'] + array( '_name' => $item['field']['name'] ?? '' );
			$value  = $item['value'];
			$label  = (string) ( $field['label'] ?? $field['name'] ?? $key );
			$choice = self::choice_error( $field, $value );
			if ( null !== $choice ) {
				$errors[] = $label . ': ' . $choice;
				continue;
			}
			acf_reset_validation_errors();
			if ( ! acf_validate_value( $value, $field, 'acf[' . $key . ']' ) ) {
				foreach ( (array) acf_get_validation_errors() as $error ) {
					$errors[] = $label . ': ' . wp_strip_all_tags( (string) ( $error['message'] ?? '' ) );
				}
			}
			acf_reset_validation_errors();
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'ACF refused the values: ' . implode( '; ', $errors ) . '. Nothing was changed.' );
		}
	}

	/**
	 * A choice field's value must be among its choices.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Value.
	 * @return string|null Problem, or null.
	 */
	private static function choice_error( array $field, mixed $value ): ?string {
		if ( ! in_array( $field['type'] ?? '', self::CHOICE, true ) || null === $value || '' === $value || array() === $value ) {
			return null;
		}
		$choices = array_map( 'strval', array_keys( (array) ( $field['choices'] ?? array() ) ) );
		$many    = 'checkbox' === $field['type'] || ! empty( $field['multiple'] );
		if ( is_array( $value ) && ! $many ) {
			return 'takes one value';
		}
		foreach ( (array) $value as $one ) {
			if ( ! is_scalar( $one ) || ! in_array( (string) $one, $choices, true ) ) {
				return 'use one of ' . implode( ', ', $choices );
			}
		}
		return null;
	}

	/**
	 * A field in output shape: definition summary, raw and formatted value; password values never leave.
	 *
	 * @param array<string, mixed> $field  Field.
	 * @param int|string           $acf_id ACF post_id.
	 * @return array<string, mixed>
	 */
	public static function describe( array $field, int|string $acf_id ): array {
		$raw    = acf_get_metadata( $acf_id, (string) $field['name'] );
		$secret = 'password' === ( $field['type'] ?? '' );
		return array(
			'key'       => (string) $field['key'],
			'name'      => (string) $field['name'],
			'label'     => (string) ( $field['label'] ?? '' ),
			'type'      => (string) ( $field['type'] ?? '' ),
			'required'  => ! empty( $field['required'] ),
			'value'     => $secret ? null : self::plain( get_field( (string) $field['key'], $acf_id, false ) ),
			'formatted' => $secret ? null : self::plain( get_field( (string) $field['key'], $acf_id, true ) ),
			'has_value' => null !== $raw && '' !== $raw,
		);
	}

	/**
	 * Values made JSON-safe: WordPress objects become id + title/name.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	public static function plain( mixed $value, int $depth = 0 ): mixed {
		if ( $value instanceof \WP_Post ) {
			return array(
				'id'    => $value->ID,
				'title' => $value->post_title,
				'type'  => $value->post_type,
			);
		}
		if ( $value instanceof \WP_Term ) {
			return array(
				'id'       => $value->term_id,
				'name'     => $value->name,
				'taxonomy' => $value->taxonomy,
			);
		}
		if ( $value instanceof \WP_User ) {
			return array(
				'id'   => $value->ID,
				'name' => $value->display_name,
			);
		}
		if ( is_array( $value ) ) {
			return $depth > 8 ? array() : array_map( static fn( $v ) => self::plain( $v, $depth + 1 ), $value );
		}
		return is_object( $value ) ? null : $value;
	}

	/**
	 * Definition summary of a field for field-group listings.
	 *
	 * @param array<string, mixed> $field Field.
	 * @return array<string, mixed>
	 */
	public static function definition( array $field ): array {
		$out = array(
			'key'          => (string) ( $field['key'] ?? '' ),
			'name'         => (string) ( $field['name'] ?? '' ),
			'label'        => (string) ( $field['label'] ?? '' ),
			'type'         => (string) ( $field['type'] ?? '' ),
			'required'     => ! empty( $field['required'] ),
			'instructions' => (string) ( $field['instructions'] ?? '' ),
		);
		foreach ( array( 'choices', 'default_value', 'min', 'max', 'multiple', 'return_format', 'post_type', 'taxonomy' ) as $key ) {
			if ( isset( $field[ $key ] ) && '' !== $field[ $key ] && array() !== $field[ $key ] ) {
				$out[ $key ] = self::plain( $field[ $key ] );
			}
		}
		if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$out['sub_fields'] = array_map( array( self::class, 'definition' ), array_values( $field['sub_fields'] ) );
		}
		return $out;
	}
}
