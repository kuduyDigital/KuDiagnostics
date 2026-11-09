<?php
/**
 * Phase 7 WooCommerce reviews (test matrix W11): list with rating and masked reviewer email, moderation that
 * refreshes the product's rating, undo, replies, and WooCommerce's review permissions. Runs only when WooCommerce is
 * active.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class WooReviewsTest extends TestCase {

	use CallsTools;

	private static int $manager;
	private static int $editor;
	private static int $product;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WC_VERSION' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$manager = self::make_user( 'shop_manager' );
		self::$editor  = self::make_user( 'editor' );
		$product       = new \WC_Product_Simple();
		$product->set_name( 'Kuduy reviewed' );
		$product->set_status( 'publish' );
		self::$product = $product->save();
	}

	protected function setUp(): void {
		wp_set_current_user( self::$manager );
	}

	/**
	 * A pending 4-star review from a visitor.
	 */
	private static function review(): int {
		$id = (int) wp_insert_comment(
			array(
				'comment_post_ID'      => self::$product,
				'comment_author'       => 'Kari',
				'comment_author_email' => 'kari.reviewer@example.test',
				'comment_content'      => 'Great shirt.',
				'comment_type'         => 'review',
				'comment_approved'     => 0,
			)
		);
		add_comment_meta( $id, 'rating', 4, true );
		return $id;
	}

	/**
	 * Product read fresh.
	 */
	private static function product(): \WC_Product {
		$product = wc_get_product( self::$product );
		self::assertInstanceOf( \WC_Product::class, $product );
		return $product;
	}

	public function test_list_shows_rating_and_masks_the_reviewer_email(): void {
		$id   = self::review();
		$list = $this->ok(
			'kuduy-woo-review-list',
			array(
				'product_id' => self::$product,
				'status'     => 'hold',
			)
		);
		$row  = array_values( array_filter( $list['reviews'], static fn( $r ) => $id === $r['id'] ) )[0] ?? null;
		$this->assertNotNull( $row );
		$this->assertSame( 4, $row['rating'] );
		$this->assertSame( 'hold', $row['status'] );
		$this->assertStringNotContainsString( 'kari.reviewer@', (string) wp_json_encode( $list ) );
	}

	public function test_moderation_refreshes_the_rating_and_undo_puts_it_back(): void {
		$id = self::review();
		$this->assertSame( 0, self::product()->get_review_count() );
		$approved = $this->ok(
			'kuduy-woo-review-moderate',
			array(
				'id'     => $id,
				'status' => 'approve',
			)
		);
		$this->assertSame( 'approved', $approved['status'] );
		$this->assertSame( 1, self::product()->get_review_count() );
		$this->assertEquals( 4.0, (float) self::product()->get_average_rating() );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $approved['_snapshot_id'] ) );
		$this->assertSame( '0', get_comment( $id )->comment_approved );
		$this->assertSame( 0, self::product()->get_review_count() );
	}

	public function test_reply_and_non_reviews_are_refused(): void {
		$id      = self::review();
		$reply   = $this->ok(
			'kuduy-woo-review-reply',
			array(
				'id'      => $id,
				'content' => 'Thank you!',
			)
		);
		$comment = get_comment( $reply['id'] );
		$this->assertSame( (string) $id, $comment->comment_parent );
		$this->assertSame( (string) self::$manager, $comment->user_id );

		$post    = (int) wp_insert_post(
			array(
				'post_title'  => 'Plain',
				'post_status' => 'publish',
			)
		);
		$comment = (int) wp_insert_comment(
			array(
				'comment_post_ID' => $post,
				'comment_content' => 'Not a review',
			)
		);
		$this->fails(
			'kuduy-woo-review-moderate',
			array(
				'id'     => $comment,
				'status' => 'spam',
			),
			'a plain comment is not a review'
		);
	}

	public function test_editor_without_product_rights_cannot_moderate_reviews(): void {
		$id = self::review();
		wp_set_current_user( self::$editor );
		$this->fails(
			'kuduy-woo-review-moderate',
			array(
				'id'     => $id,
				'status' => 'approve',
			),
			'editor moderating a review'
		);
		$this->assertSame( '0', get_comment( $id )->comment_approved );
	}
}
