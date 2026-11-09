<?php
/**
 * Tools: kuduy-woo-review-list, -moderate, -reply.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Woo;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Product reviews: comments of type `review` on products, with the `rating` and `verified` comment meta WooCommerce
 * writes. Permissions are WooCommerce's (`wc_rest_check_product_reviews_permissions()`: moderate_comments to read,
 * edit_products to change). After moderation the product's rating and review count are recalculated with
 * WC_Comments::clear_transients(). Replies are ordinary comments under the review, posted through wp_new_comment().
 * Reviewer emails are always masked.
 */
final class ReviewTools implements Tool, Snapshottable {

	private const STATUS = array(
		'approve' => '1',
		'hold'    => '0',
		'spam'    => 'spam',
		'trash'   => 'trash',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode list | moderate | reply.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-woo-review-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'     => __( 'List product reviews', 'kuduy-mcp' ),
			'moderate' => __( 'Moderate a product review', 'kuduy-mcp' ),
			default    => __( 'Reply to a product review', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'     => 'Lists WooCommerce product reviews with product, reviewer name (email masked), rating, verified-owner flag, status and text. Filters: product_id, status (all = approved and pending), rating. Paged.',
			'moderate' => 'Approves, holds, marks as spam or trashes a WooCommerce product review; the product\'s average rating and review count are updated. Snapshot first; undo with kuduy-snapshot-restore.',
			default    => 'Replies to a WooCommerce product review as your account (shown under the review once approved; your own replies as a moderator are approved at once). Remove a reply with kuduy-comment-moderate (trash).',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id                        = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		[ $properties, $required ] = match ( $this->mode ) {
			'list'     => array(
				array(
					'product_id' => $id,
					'status'     => array(
						'type'    => 'string',
						'enum'    => array( 'all', 'approve', 'hold', 'spam', 'trash' ),
						'default' => 'all',
					),
					'rating'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 5,
					),
				) + Content::paging_input(),
				array(),
			),
			'moderate' => array(
				array(
					'id'     => $id,
					'status' => array(
						'type' => 'string',
						'enum' => array_keys( self::STATUS ),
					),
				),
				array( 'id', 'status' ),
			),
			default    => array(
				array(
					'id'      => $id,
					'content' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 5000,
					),
				),
				array( 'id', 'content' ),
			),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * Schema of one review.
	 *
	 * @return array<string, mixed>
	 */
	private static function row_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'product_id'   => array( 'type' => 'integer' ),
				'product_name' => array( 'type' => 'string' ),
				'parent'       => array( 'type' => 'integer' ),
				'reviewer'     => array( 'type' => 'string' ),
				'email'        => array( 'type' => 'string' ),
				'rating'       => array( 'type' => array( 'integer', 'null' ) ),
				'verified'     => array( 'type' => 'boolean' ),
				'status'       => array( 'type' => 'string' ),
				'content'      => array( 'type' => 'string' ),
				'date'         => array( 'type' => 'string' ),
			),
			'required'   => array( 'id', 'product_id', 'status' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		if ( 'list' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'reviews'     => array(
						'type'  => 'array',
						'items' => self::row_schema(),
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'page'        => array( 'type' => 'integer' ),
					'per_page'    => array( 'type' => 'integer' ),
				),
				'required'   => array( 'reviews', 'total', 'total_pages', 'page', 'per_page' ),
			);
		}
		$schema                               = self::row_schema();
		$schema['properties']['_snapshot_id'] = array( 'type' => 'integer' );
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'list' === $this->mode ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'moderate' === $this->mode,
			'idempotentHint'  => 'moderate' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'list' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( 'moderate' === $this->mode && self::review( (int) ( $input['id'] ?? 0 ) ) ) {
			return array( 'edit_comment', (int) $input['id'] );
		}
		return 'list' === $this->mode ? 'moderate_comments' : 'edit_products';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return Woo::available();
	}

	/**
	 * A product review, or null.
	 *
	 * @param int $id Comment id.
	 */
	private static function review( int $id ): ?\WP_Comment {
		$comment = $id > 0 ? get_comment( $id ) : null;
		return $comment instanceof \WP_Comment && 'review' === $comment->comment_type && 'product' === get_post_type( (int) $comment->comment_post_ID ) ? $comment : null;
	}

	/**
	 * WooCommerce's review permission check.
	 *
	 * @param string $context read | create | edit.
	 * @param int    $id      Review id.
	 * @throws ToolError When WooCommerce refuses.
	 */
	private static function assert_permission( string $context, int $id = 0 ): void {
		if ( ! wc_rest_check_product_reviews_permissions( $context, $id ) ) {
			throw new ToolError( 'WooCommerce does not allow your account to ' . esc_html( $context ) . ' product reviews (needs edit_products to change them).' );
		}
	}

	/**
	 * Review (or reply) for output.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return array<string, mixed>
	 */
	private static function row( \WP_Comment $comment ): array {
		$rating = get_comment_meta( (int) $comment->comment_ID, 'rating', true );
		$status = wp_get_comment_status( $comment );
		return array(
			'id'           => (int) $comment->comment_ID,
			'product_id'   => (int) $comment->comment_post_ID,
			'product_name' => (string) get_the_title( (int) $comment->comment_post_ID ),
			'parent'       => (int) $comment->comment_parent,
			'reviewer'     => (string) $comment->comment_author,
			'email'        => Pii::email( (string) $comment->comment_author_email ),
			'rating'       => '' === $rating ? null : (int) $rating,
			'verified'     => (bool) get_comment_meta( (int) $comment->comment_ID, 'verified', true ),
			'status'       => 'unapproved' === $status ? 'hold' : (string) $status,
			'content'      => (string) $comment->comment_content,
			'date'         => (string) $comment->comment_date_gmt,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$review = 'moderate' === $this->mode ? self::review( (int) ( $input['id'] ?? 0 ) ) : null;
		return null === $review ? null : array(
			'object_type' => 'comment',
			'object_id'   => (string) $review->comment_ID,
			'payload'     => array(
				'id'     => (int) $review->comment_ID,
				'status' => (string) $review->comment_approved,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the review is gone.
	 */
	public function restore( array $payload ): void {
		$review = self::review( (int) ( $payload['id'] ?? 0 ) );
		if ( null === $review ) {
			throw new ToolError( 'The review no longer exists.' );
		}
		$id     = (int) $review->comment_ID;
		$status = (string) ( $payload['status'] ?? '0' );
		if ( 'trash' !== $status ) {
			wp_untrash_comment( $id );
			wp_unspam_comment( $id );
		}
		wp_set_comment_status( $id, array_flip( self::STATUS )[ $status ] ?? 'hold' );
		\WC_Comments::clear_transients( (int) $review->comment_post_ID );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the review is missing or WordPress refuses.
	 */
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			self::assert_permission( 'read' );
			return $this->items( $input );
		}
		$review = self::review( (int) $input['id'] );
		if ( null === $review ) {
			throw new ToolError( 'Comment ' . (int) $input['id'] . ' is not a product review.' );
		}
		$id      = (int) $review->comment_ID;
		$product = (int) $review->comment_post_ID;
		self::assert_permission( 'moderate' === $this->mode ? 'edit' : 'create', $id );
		AuditLog::target( 'comment', (string) $id );
		if ( 'moderate' === $this->mode ) {
			$status = (string) $input['status'];
			$ok     = match ( $status ) {
				'trash' => wp_trash_comment( $id ),
				'spam'  => wp_spam_comment( $id ),
				default => (bool) wp_set_comment_status( $id, $status ),
			};
			if ( ! $ok ) {
				throw new ToolError( 'WordPress did not change the review.' );
			}
			\WC_Comments::clear_transients( $product );
			$fresh = get_comment( $id );
			return self::row( $fresh instanceof \WP_Comment ? $fresh : $review );
		}
		$user  = wp_get_current_user();
		$reply = wp_new_comment(
			wp_slash(
				array(
					'comment_post_ID'      => $product,
					'comment_parent'       => $id,
					'comment_content'      => (string) $input['content'],
					'user_id'              => $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_author_url'   => $user->user_url,
					'comment_type'         => 'comment',
				)
			),
			true
		);
		if ( is_wp_error( $reply ) ) {
			throw new ToolError( 'WordPress refused the reply: ' . esc_html( $reply->get_error_message() ) );
		}
		$comment = get_comment( (int) $reply );
		if ( ! $comment instanceof \WP_Comment ) {
			throw new ToolError( 'The reply was saved but could not be read back.' );
		}
		AuditLog::target( 'comment', (string) $comment->comment_ID );
		return self::row( $comment );
	}

	/**
	 * A page of reviews.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private function items( array $input ): array {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$args     = array(
			'type'      => 'review',
			'post_type' => 'product',
			'status'    => (string) ( $input['status'] ?? 'all' ),
			'orderby'   => 'comment_date_gmt',
			'order'     => 'DESC',
		);
		if ( isset( $input['product_id'] ) ) {
			$args['post_id'] = (int) $input['product_id'];
		}
		if ( isset( $input['rating'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WooCommerce stores the rating as comment meta.
			$args['meta_query'] = array(
				array(
					'key'   => 'rating',
					'value' => (int) $input['rating'],
				),
			);
		}
		$total   = (int) get_comments( $args + array( 'count' => true ) );
		$reviews = array();
		foreach ( (array) get_comments(
			$args + array(
				'number' => $per_page,
				'offset' => ( $page - 1 ) * $per_page,
			)
		) as $comment ) {
			$reviews[] = self::row( $comment );
		}
		return array(
			'reviews'     => $reviews,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}
}
