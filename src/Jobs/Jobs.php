<?php
/**
 * Background jobs (decision D9).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Jobs;

use Kuduy\Mcp\Mcp\Executor;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\OAuth\Scopes;

/**
 * A job runs one tool over many inputs in the background, as the user who started it. Each item goes through the
 * normal executor (availability, schema, capability for that input, snapshot, redaction, audit), so a job can do
 * nothing the user could not do call by call. Runner: Action Scheduler when loaded (WooCommerce, Rank Math and
 * AIOSEO bundle it), otherwise one WP-Cron event per batch plus a cron spawn. A job stops when it is cancelled, when
 * the OAuth connection that started it is revoked, or when its scope no longer covers the tool.
 */
final class Jobs {

	public const HOOK      = 'kuduy_mcp_run_job';
	public const MAX_ITEMS = 500;

	/**
	 * Tools that may run as jobs (idempotent single-object writes with undo, or harmless regeneration).
	 */
	public const TOOLS = array( 'kuduy-seo-update', 'kuduy-media-update', 'kuduy-media-regenerate' );

	private const BATCH   = 25;
	private const SECONDS = 20;

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'kuduy_jobs';
	}

	/**
	 * Store a job and schedule its first batch.
	 *
	 * @param string                     $tool      Tool name.
	 * @param list<array<string, mixed>> $items     Inputs.
	 * @param int                        $user_id   Owner.
	 * @param string|null                $client_id OAuth client, or null (cookie / Application Password).
	 * @param list<string>|null          $scopes    Granted scopes, or null.
	 */
	public static function create( string $tool, array $items, int $user_id, ?string $client_id, ?array $scopes ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => $now,
				'updated_at' => $now,
				'user_id'    => $user_id,
				'client_id'  => (string) $client_id,
				'scopes'     => null === $scopes ? '' : implode( ' ', $scopes ),
				'tool'       => $tool,
				'status'     => 'queued',
				'total'      => count( $items ),
				'done'       => 0,
				'failed'     => 0,
				'items'      => (string) wp_json_encode( $items ),
				'results'    => '[]',
			)
		);
		$id = (int) $wpdb->insert_id;
		self::schedule( $id );
		return $id;
	}

	/**
	 * One job row (decoded), or null.
	 *
	 * @param int $id Job id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, fixed name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['items']   = json_decode( (string) $row['items'], true );
		$row['results'] = json_decode( (string) $row['results'], true );
		foreach ( array( 'id', 'user_id', 'total', 'done', 'failed' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	/**
	 * Change a job's status (and its error) unless it already finished.
	 *
	 * @param int    $id     Job id.
	 * @param string $status New status.
	 * @param string $error  Reason.
	 */
	public static function finish( int $id, string $status, string $error = '' ): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, fixed name.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, error = %s, updated_at = %s WHERE id = %d AND status IN ('queued', 'running')", $status, $error, gmdate( 'Y-m-d H:i:s' ), $id ) );
	}

	/**
	 * Schedule the next batch.
	 *
	 * @param int $id Job id.
	 */
	public static function schedule( int $id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $id ), 'kuduy-mcp' );
			return;
		}
		wp_schedule_single_event( time(), self::HOOK, array( $id ) );
		spawn_cron();
	}

	/**
	 * Run one batch (the scheduled hook).
	 *
	 * @param int $id Job id.
	 */
	public static function run( int $id ): void {
		$job = self::get( $id );
		if ( ! $job || ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}
		$why = self::blocked( $job );
		if ( null !== $why ) {
			self::finish( $id, 'stopped', $why );
			return;
		}
		$tool = ToolRegistry::create()->get( (string) $job['tool'] );
		if ( ! $tool ) {
			self::finish( $id, 'failed', 'The tool is no longer available.' );
			return;
		}
		$previous = get_current_user_id();
		wp_set_current_user( (int) $job['user_id'] );
		$results = (array) $job['results'];
		$items   = (array) $job['items'];
		$start   = time();
		$count   = 0;
		$total   = count( $items );
		$index   = count( $results );
		while ( $index < $total && $count < self::BATCH && time() - $start < self::SECONDS ) {
			$result = Executor::call( $tool, $items[ $index ] );
			$error  = ! empty( $result['isError'] );
			$entry  = array( 'ok' => ! $error );
			if ( $error ) {
				$entry['error'] = (string) ( $result['content'][0]['text'] ?? 'failed' );
			} elseif ( isset( $result['structuredContent']['_snapshot_id'] ) ) {
				$entry['snapshot_id'] = (int) $result['structuredContent']['_snapshot_id'];
			}
			$results[] = $entry;
			++$count;
			++$index;
			if ( null !== self::blocked( (array) self::get( $id ) ) ) {
				break;
			}
		}
		wp_set_current_user( $previous );
		self::save_progress( $id, $results );
		$fresh = self::get( $id );
		if ( ! $fresh || 'running' !== $fresh['status'] ) {
			return;
		}
		if ( count( $results ) >= count( $items ) ) {
			self::finish( $id, 'done' );
		} else {
			self::schedule( $id );
		}
	}

	/**
	 * Why a job may not continue, or null.
	 *
	 * @param array<string, mixed> $job Job.
	 */
	private static function blocked( array $job ): ?string {
		if ( 'cancelled' === ( $job['status'] ?? '' ) ) {
			return 'Cancelled.';
		}
		$user = get_userdata( (int) ( $job['user_id'] ?? 0 ) );
		if ( ! $user ) {
			return 'The user who started the job no longer exists.';
		}
		$scopes = (string) ( $job['scopes'] ?? '' );
		$tool   = ToolRegistry::create()->get( (string) ( $job['tool'] ?? '' ) );
		if ( '' !== $scopes && ( ! $tool || ! Scopes::allows( Scopes::parse( $scopes ), $tool->scope() ) ) ) {
			return 'The connection does not allow this tool.';
		}
		$client = (string) ( $job['client_id'] ?? '' );
		if ( '' !== $client && ! self::connected( (int) $job['user_id'], $client ) ) {
			return 'The Claude connection that started the job was revoked.';
		}
		return null;
	}

	/**
	 * Whether the user still has a live connection with this client (an unrevoked, unexpired refresh token).
	 *
	 * @param int    $user_id   User.
	 * @param string $client_id Client.
	 */
	private static function connected( int $user_id, string $client_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'kuduy_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, fixed name.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE user_id = %d AND client_id = %s AND type = 'refresh' AND revoked_at IS NULL AND expires_at > %s LIMIT 1", $user_id, $client_id, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Store results and counts; marks the job running.
	 *
	 * @param int                        $id      Job id.
	 * @param list<array<string, mixed>> $results Results so far.
	 */
	private static function save_progress( int $id, array $results ): void {
		global $wpdb;
		$failed = count( array_filter( $results, static fn( $r ) => empty( $r['ok'] ) ) );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, fixed name.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', results = %s, done = %d, failed = %d, updated_at = %s WHERE id = %d AND status IN ('queued', 'running')", (string) wp_json_encode( $results ), count( $results ), $failed, gmdate( 'Y-m-d H:i:s' ), $id ) );
	}
}
