<?php
/**
 * Tool: kuduy-woo-report.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * WooCommerce Analytics reports, read through WooCommerce's own `wc-analytics/reports/*` REST routes inside this
 * request (rest_do_request(), as the current user: WooCommerce checks view_woocommerce_reports). Analytics data comes
 * from lookup tables WooCommerce fills in the background, so very recent orders may be missing; the result says so.
 * Customer data in report rows is masked (D12).
 */
final class Report implements Tool {

	/**
	 * At most this many pages of 100 intervals (a daily report over up to about 2.7 years).
	 */
	private const MAX_INTERVAL_PAGES = 10;

	/**
	 * Report => [ stats route or null, rows route or null ].
	 */
	private const ROUTES = array(
		'revenue'    => array( 'reports/revenue/stats', null ),
		'orders'     => array( 'reports/orders/stats', 'reports/orders' ),
		'products'   => array( 'reports/products/stats', 'reports/products' ),
		'variations' => array( 'reports/variations/stats', 'reports/variations' ),
		'categories' => array( null, 'reports/categories' ),
		'coupons'    => array( 'reports/coupons/stats', 'reports/coupons' ),
		'taxes'      => array( 'reports/taxes/stats', 'reports/taxes' ),
		'stock'      => array( 'reports/stock/stats', 'reports/stock' ),
		'customers'  => array( 'reports/customers/stats', 'reports/customers' ),
		'downloads'  => array( 'reports/downloads/stats', 'reports/downloads' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-report';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'WooCommerce report', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'WooCommerce Analytics for a date range (default: the last 30 days): revenue (gross/net sales, orders, refunds, taxes, shipping per interval), orders, products, variations, categories, coupons, taxes, stock, customers or downloads. Stats reports give totals and per-interval values; list reports give up to per_page rows. Numbers come from WooCommerce\'s analytics tables, which can lag behind the newest orders. Customer emails are masked.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'report'   => array(
					'type' => 'string',
					'enum' => array_keys( self::ROUTES ),
				),
				'after'    => $date + array( 'description' => 'First day (YYYY-MM-DD, store time zone).' ),
				'before'   => $date + array( 'description' => 'Last day, included (YYYY-MM-DD).' ),
				'interval' => array(
					'type'    => 'string',
					'enum'    => array( 'day', 'week', 'month', 'quarter', 'year' ),
					'default' => 'day',
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 25,
				),
			),
			'required'             => array( 'report' ),
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
				'report'     => array( 'type' => 'string' ),
				'after'      => array( 'type' => 'string' ),
				'before'     => array( 'type' => 'string' ),
				'interval'   => array( 'type' => 'string' ),
				'totals'     => array( 'type' => array( 'object', 'array', 'null' ) ),
				'intervals'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'rows'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total_rows' => array( 'type' => array( 'integer', 'null' ) ),
				'note'       => array( 'type' => 'string' ),
			),
			'required'   => array( 'report', 'after', 'before', 'note' ),
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
	public function capability( array $input ): string {
		return 'view_woocommerce_reports';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * Run one wc-analytics route.
	 *
	 * @param string               $route  Route under wc-analytics/.
	 * @param array<string, mixed> $params Query parameters.
	 * @return array{0: mixed, 1: int|null, 2: int} Data, X-WP-Total and X-WP-TotalPages.
	 * @throws ToolError When WooCommerce returns an error.
	 */
	private static function fetch( string $route, array $params ): array {
		$request = new \WP_REST_Request( 'GET', '/wc-analytics/' . $route );
		$request->set_query_params( $params );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error = $response->as_error();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( 'WooCommerce Analytics refused the report: ' . wp_strip_all_tags( $error->get_error_message() ) );
		}
		$headers = $response->get_headers();
		$total   = $headers['X-WP-Total'] ?? null;
		return array( $response->get_data(), null === $total ? null : (int) $total, (int) ( $headers['X-WP-TotalPages'] ?? 1 ) );
	}

	/**
	 * Stats with every interval: wc-analytics pages the intervals (10 per page by default, 100 at most), so a 30-day
	 * daily report needs all pages (found on staging 2026-11-09).
	 *
	 * @param string               $route  Stats route.
	 * @param array<string, mixed> $params Query parameters.
	 * @return array{totals: array<mixed>, intervals: list<array<mixed>>, complete: bool}
	 */
	private static function stats( string $route, array $params ): array {
		$totals    = array();
		$intervals = array();
		$pages     = 1;
		$page      = 0;
		while ( $page < $pages && $page < self::MAX_INTERVAL_PAGES ) {
			++$page;
			[ $data, , $pages ] = self::fetch(
				$route,
				$params + array(
					'per_page' => 100,
					'page'     => $page,
					'order'    => 'asc',
				)
			);
			$data               = is_array( $data ) ? $data : array();
			$totals             = (array) ( $data['totals'] ?? $totals );
			foreach ( (array) ( $data['intervals'] ?? array() ) as $row ) {
				$intervals[] = (array) $row;
			}
		}
		return array(
			'totals'    => $totals,
			'intervals' => $intervals,
			'complete'  => $pages <= self::MAX_INTERVAL_PAGES,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When analytics is off or WooCommerce refuses.
	 */
	public function execute( array $input ): array {
		if ( ! wc_rest_check_manager_permissions( 'reports', 'read' ) ) {
			throw new ToolError( 'WooCommerce does not allow your account to view reports.' );
		}
		if ( 'no' === get_option( 'woocommerce_analytics_enabled', 'yes' ) ) {
			throw new ToolError( 'WooCommerce Analytics is turned off (WooCommerce > Settings > Advanced > Features), so there are no reports.' );
		}
		$report = (string) $input['report'];
		$now    = current_datetime();
		$before = (string) ( $input['before'] ?? $now->format( 'Y-m-d' ) );
		$after  = (string) ( $input['after'] ?? $now->modify( '-29 days' )->format( 'Y-m-d' ) );
		if ( $after > $before ) {
			throw new ToolError( 'after must be on or before before.' );
		}
		$interval                     = (string) ( $input['interval'] ?? 'day' );
		$params                       = array(
			'after'  => $after . 'T00:00:00',
			'before' => $before . 'T23:59:59',
		);
		[ $stats_route, $rows_route ] = self::ROUTES[ $report ];
		$out                          = array(
			'report'     => $report,
			'after'      => $after,
			'before'     => $before,
			'interval'   => $interval,
			'totals'     => null,
			'intervals'  => array(),
			'rows'       => array(),
			'total_rows' => null,
			'note'       => 'Numbers come from WooCommerce Analytics tables, which WooCommerce fills in the background' . ( 'yes' === get_option( 'woocommerce_analytics_scheduled_import', 'no' ) ? ' on a schedule' : '' ) . '; the newest orders may not be counted yet. Dates use the store time zone.',
		);
		if ( $stats_route ) {
			$stats            = self::stats( $stats_route, $params + array( 'interval' => $interval ) );
			$out['totals']    = Pii::scrub( $stats['totals'] );
			$out['intervals'] = array_map( static fn( $row ) => Pii::scrub( $row ), $stats['intervals'] );
			if ( ! $stats['complete'] ) {
				$out['note'] .= ' Only the first ' . count( $out['intervals'] ) . ' intervals are listed; choose a longer interval for this range.';
			}
		}
		if ( $rows_route ) {
			// extended_info adds names, SKUs and (for orders) customer details to the rows; Pii::scrub() masks the latter.
			$query             = $params + array(
				'per_page'      => (int) ( $input['per_page'] ?? 25 ),
				'extended_info' => true,
			);
			[ $rows, $total ]  = self::fetch( $rows_route, $query );
			$out['rows']       = array_values( array_map( static fn( $row ) => Pii::scrub( (array) $row ), is_array( $rows ) ? $rows : array() ) );
			$out['total_rows'] = $total;
		}
		return $out;
	}
}
