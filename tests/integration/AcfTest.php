<?php
/**
 * Phase 7 ACF (test matrix A1–A6, decision D14 A): status and field groups, values read and written through ACF's
 * public API on posts, users and terms, only fields ACF shows on the object, validation, undo, password fields never
 * returned, the post-meta guard and bulk updates as jobs. Field groups are registered with acf_add_local_field_group().
 * Runs only when ACF is active (KUDUY_PLUGINS=advanced-custom-fields@…).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Jobs\Jobs;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class AcfTest extends TestCase {

	use CallsTools;

	private const SECRET = 'acf-password-4711';

	private static int $editor;
	private static int $author;
	private static int $term;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			self::markTestSkipped( 'ACF is not active in this run (KUDUY_PLUGINS).' );
		}
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$editor = self::make_user( 'editor' );
		self::$author = self::make_user( 'author' );
		$term         = wp_insert_term( 'Kuduy ACF category', 'category' );
		self::$term   = is_array( $term ) ? (int) $term['term_id'] : 0;

		acf_add_local_field_group(
			array(
				'key'      => 'group_kuduy_post',
				'title'    => 'Kuduy post fields',
				'fields'   => array(
					array(
						'key'      => 'field_kuduy_subtitle',
						'name'     => 'kacf_subtitle',
						'label'    => 'Subtitle',
						'type'     => 'text',
						'required' => 1,
					),
					array(
						'key'   => 'field_kuduy_price',
						'name'  => 'kacf_price',
						'label' => 'Price',
						'type'  => 'number',
						'min'   => 0,
					),
					array(
						'key'     => 'field_kuduy_color',
						'name'    => 'kacf_color',
						'label'   => 'Color',
						'type'    => 'select',
						'choices' => array(
							'red'  => 'Red',
							'blue' => 'Blue',
						),
					),
					array(
						'key'   => 'field_kuduy_featured',
						'name'  => 'kacf_featured',
						'label' => 'Featured',
						'type'  => 'true_false',
					),
					array(
						'key'   => 'field_kuduy_contact',
						'name'  => 'kacf_contact',
						'label' => 'Contact',
						'type'  => 'email',
					),
					array(
						'key'   => 'field_kuduy_secret',
						'name'  => 'kacf_secret',
						'label' => 'Secret',
						'type'  => 'password',
					),
				),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- ACF location rule.
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_kuduy_page',
				'title'    => 'Kuduy page fields',
				'fields'   => array(
					array(
						'key'   => 'field_kuduy_page_only',
						'name'  => 'kacf_page_only',
						'label' => 'Page only',
						'type'  => 'text',
					),
				),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- ACF location rule.
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_kuduy_user',
				'title'    => 'Kuduy user fields',
				'fields'   => array(
					array(
						'key'   => 'field_kuduy_twitter',
						'name'  => 'kacf_twitter',
						'label' => 'Twitter',
						'type'  => 'text',
					),
				),
				'location' => array( array( array( 'param' => 'user_form', 'operator' => '==', 'value' => 'all' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- ACF location rule.
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_kuduy_term',
				'title'    => 'Kuduy category fields',
				'fields'   => array(
					array(
						'key'   => 'field_kuduy_banner',
						'name'  => 'kacf_banner',
						'label' => 'Banner text',
						'type'  => 'text',
					),
				),
				'location' => array( array( array( 'param' => 'taxonomy', 'operator' => '==', 'value' => 'category' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- ACF location rule.
			)
		);
	}

	protected function setUp(): void {
		wp_set_current_user( self::$editor );
	}

	/**
	 * A post with ACF values, owned by a user.
	 *
	 * @param int $author Author.
	 */
	private static function post( int $author ): int {
		$id = (int) wp_insert_post(
			array(
				'post_title'  => 'Kuduy ACF post',
				'post_status' => 'draft',
				'post_author' => $author,
			)
		);
		update_field( 'field_kuduy_subtitle', 'Original', $id );
		update_field( 'field_kuduy_secret', self::SECRET, $id );
		return $id;
	}

	public function test_status_and_field_groups_by_location(): void {
		$status = $this->ok( 'kuduy-acf-status' );
		$this->assertSame( ACF_VERSION, $status['version'] );
		$this->assertFalse( $status['abilities_enabled'] );
		$this->assertArrayNotHasKey( 'google_api_key', $status );

		$keys = array_column( $this->ok( 'kuduy-acf-field-groups', array( 'post_type' => 'post' ) )['groups'], 'key' );
		$this->assertContains( 'group_kuduy_post', $keys );
		$this->assertNotContains( 'group_kuduy_page', $keys );

		$id     = self::post( self::$editor );
		$groups = $this->ok(
			'kuduy-acf-field-groups',
			array(
				'object' => 'post',
				'id'     => $id,
			)
		)['groups'];
		$fields = array_column( $groups[0]['fields'], 'type', 'name' );
		$this->assertSame( 'select', $fields['kacf_color'] );
	}

	public function test_get_returns_values_and_never_a_password(): void {
		$id     = self::post( self::$editor );
		$out    = $this->ok(
			'kuduy-acf-get',
			array(
				'object' => 'post',
				'id'     => $id,
			)
		);
		$byname = array_column( $out['fields'], null, 'name' );
		$this->assertSame( 'Original', $byname['kacf_subtitle']['value'] );
		$this->assertNull( $byname['kacf_secret']['value'] );
		$this->assertTrue( $byname['kacf_secret']['has_value'] );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $out ) );
	}

	public function test_update_post_values_with_undo(): void {
		$id      = self::post( self::$editor );
		$updated = $this->ok(
			'kuduy-acf-update',
			array(
				'object' => 'post',
				'id'     => $id,
				'values' => array(
					'kacf_subtitle' => 'Changed',
					'kacf_price'    => 12.5,
					'kacf_color'    => 'blue',
					'kacf_featured' => true,
				),
			)
		);
		$this->assertSame( array( 'kacf_subtitle', 'kacf_price', 'kacf_color', 'kacf_featured' ), $updated['changed'] );
		$this->assertSame( 'Changed', get_field( 'kacf_subtitle', $id ) );
		$this->assertEquals( 12.5, get_field( 'kacf_price', $id ) );
		$this->assertSame( 'blue', get_field( 'kacf_color', $id ) );
		$this->assertTrue( get_field( 'kacf_featured', $id ) );
		$this->assertSame( 'field_kuduy_subtitle', get_post_meta( $id, '_kacf_subtitle', true ), 'ACF reference key written.' );

		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $updated['_snapshot_id'] ) );
		$this->assertSame( 'Original', get_field( 'kacf_subtitle', $id ) );
		$this->assertFalse( metadata_exists( 'post', $id, 'kacf_price' ), 'A field without a value before has none after undo.' );
		$this->assertFalse( metadata_exists( 'post', $id, 'kacf_color' ) );
	}

	public function test_update_user_and_term_values(): void {
		wp_set_current_user( self::make_user( 'administrator' ) );
		$this->ok(
			'kuduy-acf-update',
			array(
				'object' => 'user',
				'id'     => self::$author,
				'values' => array( 'kacf_twitter' => '@kuduy' ),
			)
		);
		$this->assertSame( '@kuduy', get_field( 'kacf_twitter', 'user_' . self::$author ) );
		$this->ok(
			'kuduy-acf-update',
			array(
				'object' => 'term',
				'id'     => self::$term,
				'values' => array( 'field_kuduy_banner' => 'Sale' ),
			)
		);
		$this->assertSame( 'Sale', get_field( 'kacf_banner', 'term_' . self::$term ) );
		$values = array_column(
			$this->ok(
				'kuduy-acf-get',
				array(
					'object' => 'term',
					'id'     => self::$term,
				)
			)['fields'],
			'value',
			'name'
		);
		$this->assertSame( 'Sale', $values['kacf_banner'] );
	}

	public function test_refusals_write_nothing(): void {
		$id    = self::post( self::$editor );
		$cases = array(
			'field of another location' => array( 'kacf_page_only' => 'x' ),
			'unknown name'              => array( 'nope' => 'x' ),
			'required left empty'       => array( 'kacf_subtitle' => '' ),
			'option not in choices'     => array( 'kacf_color' => 'green' ),
			'number below minimum'      => array( 'kacf_price' => -5 ),
			'invalid email'             => array( 'kacf_contact' => 'not-an-email' ),
			'one bad value of two'      => array(
				'kacf_subtitle' => 'Would change',
				'kacf_color'    => 'green',
			),
		);
		foreach ( $cases as $label => $values ) {
			$this->fails(
				'kuduy-acf-update',
				array(
					'object' => 'post',
					'id'     => $id,
					'values' => $values,
				),
				$label
			);
		}
		$this->assertSame( 'Original', get_field( 'kacf_subtitle', $id ) );
		$this->assertFalse( metadata_exists( 'post', $id, 'kacf_page_only' ) );
		$this->assertFalse( metadata_exists( 'post', $id, 'nope' ), 'No dummy field written.' );
		$this->assertFalse( metadata_exists( 'post', $id, 'kacf_color' ) );
	}

	public function test_object_permissions(): void {
		$editors = self::post( self::$editor );
		$own     = self::post( self::$author );
		wp_set_current_user( self::$author );
		$this->fails(
			'kuduy-acf-update',
			array(
				'object' => 'post',
				'id'     => $editors,
				'values' => array( 'kacf_subtitle' => 'Nope' ),
			),
			'author on an editor\'s post'
		);
		$this->ok(
			'kuduy-acf-update',
			array(
				'object' => 'post',
				'id'     => $own,
				'values' => array( 'kacf_subtitle' => 'Mine' ),
			)
		);
		$this->fails(
			'kuduy-acf-update',
			array(
				'object' => 'user',
				'id'     => self::$editor,
				'values' => array( 'kacf_twitter' => '@nope' ),
			),
			'author on another user'
		);
		$this->assertSame( 'Original', get_field( 'kacf_subtitle', $editors ) );
	}

	public function test_password_value_is_kept_out_of_the_audit_log(): void {
		$id = self::post( self::$editor );
		$this->ok(
			'kuduy-acf-update',
			array(
				'object' => 'post',
				'id'     => $id,
				'values' => array( 'kacf_secret' => 'new-acf-password-0815' ),
			)
		);
		$this->assertSame( 'new-acf-password-0815', get_field( 'kacf_secret', $id, false ) );
		$this->assertStringNotContainsString( 'new-acf-password-0815', (string) wp_json_encode( AuditLog::latest( 5 ) ) );
	}

	public function test_post_meta_set_refuses_acf_field_names(): void {
		$id      = self::post( self::$editor );
		$message = $this->fails(
			'kuduy-post-meta-set',
			array(
				'post_id' => $id,
				'key'     => 'kacf_subtitle',
				'value'   => 'Raw',
			),
			'raw write to an ACF field'
		);
		$this->assertStringContainsString( 'kuduy-acf-update', $message );
		$this->assertSame( 'Original', get_field( 'kacf_subtitle', $id ) );
		$this->ok(
			'kuduy-post-meta-set',
			array(
				'post_id' => $id,
				'key'     => 'kuduy_plain',
				'value'   => 'ok',
			)
		);
	}

	public function test_bulk_update_as_a_job(): void {
		$a       = self::post( self::$editor );
		$b       = self::post( self::$editor );
		$started = $this->ok(
			'kuduy-job-start',
			array(
				'tool'  => 'kuduy-acf-update',
				'items' => array(
					array(
						'object' => 'post',
						'id'     => $a,
						'values' => array( 'kacf_subtitle' => 'Bulk A' ),
					),
					array(
						'object' => 'post',
						'id'     => $b,
						'values' => array( 'kacf_subtitle' => 'Bulk B' ),
					),
				),
			)
		);
		wp_set_current_user( 0 );
		Jobs::run( $started['id'] ); // The hook WP-Cron fires.
		wp_set_current_user( self::$editor );
		$this->assertSame( 0, $this->ok( 'kuduy-job-status', array( 'id' => $started['id'] ) )['failed'] );
		$this->assertSame( 'Bulk A', get_field( 'kacf_subtitle', $a ) );
		$this->assertSame( 'Bulk B', get_field( 'kacf_subtitle', $b ) );
	}
}
