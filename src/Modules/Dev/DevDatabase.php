<?php
/**
 * Tools: kuduy-dev-db-schema, kuduy-dev-db-query.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Dev;

use Kuduy\Mcp\Audit\Redactor;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Database schema and capped read-only queries (D21 A). A query must be one SELECT, SHOW, DESCRIBE or EXPLAIN
 * statement without file, lock or sleep functions; at most 200 rows come back. Password, token, key and session
 * columns are masked, as are option and meta values whose name looks secret and secret keys inside serialized
 * values. Site administrators with the admin scope only.
 */
final class DevDatabase implements Tool {

	private const MAX_ROWS = 200;

	/**
	 * Words that make a read query unsafe or not read-only.
	 */
	private const FORBIDDEN = '/\b(insert|update|delete|replace|drop|alter|create|truncate|rename|grant|revoke|set|call|do|handler|load_file|load\s+data|into\s+(out|dump)file|sleep|benchmark|get_lock|release_lock|lock\s+tables|unlock|prepare|execute|deallocate|outfile|dumpfile)\b/i';

	/**
	 * Columns always masked.
	 */
	private const SECRET_COLUMNS = array( 'user_pass', 'user_activation_key', 'session_tokens', 'consumer_secret', 'consumer_key', 'secret', 'token', 'password' );

	/**
	 * Mode.
	 *
	 * @param string $mode schema | query.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-dev-db-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'schema' === $this->mode ? __( 'Database schema', 'kuduy-mcp' ) : __( 'Read-only database query', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'schema' === $this->mode
			? 'Lists this site\'s database tables (those with the site\'s table prefix) with approximate row counts; give table to get its columns and indexes.'
			: 'Runs one read-only query (SELECT, SHOW, DESCRIBE or EXPLAIN) and returns at most 200 rows. Use {prefix} for the table prefix, e.g. SELECT ID, post_title FROM {prefix}posts LIMIT 10. Writing statements, several statements, file, lock and sleep functions are refused. Passwords, tokens, keys, sessions and secret option/meta values are masked.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = 'schema' === $this->mode
			? array(
				'table' => array(
					'type'    => 'string',
					'pattern' => '^[A-Za-z0-9_]+$',
				),
			)
			: array(
				'sql' => array(
					'type'      => 'string',
					'minLength' => 6,
					'maxLength' => 5000,
				),
			);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'schema' === $this->mode ? array() : array( 'sql' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		);
		return 'schema' === $this->mode
			? array(
				'type'       => 'object',
				'properties' => array(
					'prefix'  => array( 'type' => 'string' ),
					'tables'  => $list,
					'columns' => $list,
					'indexes' => $list,
				),
				'required'   => array( 'prefix' ),
			)
			: array(
				'type'       => 'object',
				'properties' => array(
					'rows'      => $list,
					'count'     => array( 'type' => 'integer' ),
					'truncated' => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'rows', 'count', 'truncated' ),
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
		return 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * Pattern for tables that hold credentials or personal data.
	 */
	private static function sensitive_tables(): string {
		global $wpdb;
		$p = preg_quote( $wpdb->prefix, '/' );
		return '/\b' . $p . '(users|usermeta|options|postmeta|termmeta|commentmeta|sitemeta|kuduy_\w+|woocommerce_\w+|wc_\w+)\b/i';
	}

	/**
	 * A read-only statement with {prefix} replaced, or a ToolError.
	 *
	 * @param string $sql Query.
	 * @throws ToolError When it is not one read-only statement.
	 */
	public static function checked( string $sql ): string {
		global $wpdb;
		$sql = trim( str_replace( '{prefix}', $wpdb->prefix, $sql ) );
		$sql = (string) preg_replace( '/;\s*$/', '', $sql );
		// Strings and comments are checked too: a forbidden word inside them is refused rather than parsed around.
		if ( str_contains( $sql, ';' ) ) {
			throw new ToolError( 'One statement only (no ";"). Nothing was run.' );
		}
		if ( preg_match( '/--|#|\/\*/', $sql ) ) {
			throw new ToolError( 'Comments are not allowed in queries. Nothing was run.' );
		}
		if ( ! preg_match( '/^(select|show|describe|desc|explain)\b/i', $sql ) ) {
			throw new ToolError( 'Only SELECT, SHOW, DESCRIBE and EXPLAIN queries can be run. Nothing was run.' );
		}
		if ( preg_match( self::FORBIDDEN, $sql, $m ) ) {
			throw new ToolError( 'The query uses "' . esc_html( strtoupper( $m[1] ) ) . '", which is not allowed in read-only queries. Nothing was run.' );
		}
		// Masking works on column names, so a UNION (values under another query's column names) is refused, and on
		// tables that hold credentials or personal data the select list must be plain column names: no aliases,
		// functions or sub-queries that would hide which column a value comes from (security review 2026-11-10).
		if ( preg_match( '/\bunion\b/i', $sql ) ) {
			throw new ToolError( 'UNION is not allowed in read-only queries (it hides which column a value comes from). Run the queries one by one. Nothing was run.' );
		}
		if ( preg_match( '/^select\b/i', $sql ) && preg_match( self::sensitive_tables(), $sql ) ) {
			$list = (string) preg_replace( '/^select\s+(distinct\s+)?/i', '', (string) preg_split( '/\bfrom\b/i', $sql )[0] );
			foreach ( explode( ',', $list ) as $item ) {
				if ( ! preg_match( '/^\s*[`\w.*]+\s*$/', $item ) ) {
					throw new ToolError( 'On users, options, user or post meta, sessions, API keys and KUDUY\'s own tables, select plain column names only (no aliases, functions or sub-queries), so secret values can be masked. Nothing was run.' );
				}
			}
		}
		if ( preg_match( '/^select\b/i', $sql ) ) {
			$cap = self::MAX_ROWS + 1;
			if ( preg_match( '/\blimit\s+(\d+)(?:\s*,\s*(\d+))?(?:\s+offset\s+(\d+))?\s*$/i', $sql, $m ) ) {
				// LIMIT n | LIMIT offset, n | LIMIT n OFFSET offset: keep the offset, cap the count.
				$offset = isset( $m[2] ) && '' !== $m[2] ? (int) $m[1] : (int) ( $m[3] ?? 0 );
				$count  = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : (int) $m[1];
				$sql    = substr( $sql, 0, - strlen( $m[0] ) ) . ' LIMIT ' . $offset . ', ' . min( $count, $cap );
			} else {
				$sql .= ' LIMIT ' . $cap;
			}
		}
		return $sql;
	}

	/**
	 * Mask one result row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public static function mask_row( array $row ): array {
		$name = (string) ( $row['option_name'] ?? $row['meta_key'] ?? '' );
		foreach ( $row as $column => $value ) {
			$column = (string) $column;
			if ( null === $value ) {
				continue;
			}
			// A value without its option name or meta key cannot be judged, so it is masked (select the name with it).
			$secret_value_column = in_array( $column, array( 'option_value', 'meta_value' ), true ) && ( '' === $name || Redactor::is_secret_key( $name ) || in_array( $name, array( 'session_tokens', '_application_passwords' ), true ) || str_contains( $name, 'session_tokens' ) );
			if ( in_array( strtolower( $column ), self::SECRET_COLUMNS, true ) || Redactor::is_secret_key( $column ) || $secret_value_column ) {
				$row[ $column ] = Redactor::MASK;
				continue;
			}
			if ( is_string( $value ) && is_serialized( $value ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress data, objects refused.
				$data = unserialize( $value, array( 'allowed_classes' => false ) );
				if ( is_array( $data ) ) {
					$row[ $column ] = Redactor::redact_array( $data );
					continue;
				}
			}
			$row[ $column ] = is_string( $value ) ? DevText::mask( $value ) : $value;
		}//end foreach
		return $row;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the table is unknown or the query is refused or fails.
	 */
	public function execute( array $input ): array {
		global $wpdb;
		if ( 'schema' === $this->mode ) {
			$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
			$tables = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only schema listing.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A ) as $t ) {
				if ( isset( $t['Name'] ) ) {
					$tables[] = array(
						'name'   => (string) $t['Name'],
						'rows'   => isset( $t['Rows'] ) ? (int) $t['Rows'] : null,
						'engine' => (string) ( $t['Engine'] ?? '' ),
					);
				}
			}
			if ( ! $tables ) {
				// Database drivers without TABLE STATUS (e.g. WordPress on SQLite): names only.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only schema listing.
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $name ) {
					$tables[] = array(
						'name'   => (string) $name,
						'rows'   => null,
						'engine' => '',
					);
				}
			}
			$out = array(
				'prefix' => $wpdb->prefix,
				'tables' => $tables,
			);
			if ( isset( $input['table'] ) ) {
				$table = (string) $input['table'];
				if ( ! in_array( $table, array_column( $tables, 'name' ), true ) ) {
					throw new ToolError( 'No table "' . esc_html( $table ) . '" with this site\'s prefix.' );
				}
				// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name checked against SHOW TABLE STATUS.
				$out['columns'] = (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
				$out['indexes'] = (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
				// phpcs:enable
			}
			return $out;
		}//end if
		$sql = self::checked( (string) $input['sql'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- read-only statement checked by checked().
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new ToolError( 'The database refused the query: ' . esc_html( $wpdb->last_error ) );
		}
		$rows      = (array) $rows;
		$truncated = count( $rows ) > self::MAX_ROWS;
		$rows      = array_slice( $rows, 0, self::MAX_ROWS );
		return array(
			'rows'      => array_map( array( self::class, 'mask_row' ), $rows ),
			'count'     => count( $rows ),
			'truncated' => $truncated,
		);
	}
}
