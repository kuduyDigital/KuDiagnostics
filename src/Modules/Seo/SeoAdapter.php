<?php
/**
 * One SEO plugin behind KUDUY's normalized SEO fields.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

/**
 * Fields are flat ids from Seo::FIELDS (e.g. "title", "robots.noindex", "og.title"). Values: string|null for texts
 * (null = not set, the plugin's default or template applies), bool|null for robots.noindex (null = post type default),
 * bool for the other flags, list<string> for additional_keywords. Adapters use only the plugin's own API
 * (RESEARCH.md §9, decision D8); a field the plugin cannot store is simply not in fields().
 */
interface SeoAdapter {

	/**
	 * Slug, e.g. rank-math.
	 */
	public function slug(): string;

	/**
	 * True when the plugin is set up and working, else what the site owner must do.
	 */
	public function ready(): bool|string;

	/**
	 * Field ids this plugin stores.
	 *
	 * @return list<string>
	 */
	public function fields(): array;

	/**
	 * Values stored for a post (only fields()).
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>
	 */
	public function stored( int $post_id ): array;

	/**
	 * Values the plugin outputs for the post (defaults and templates applied), or null when the current user may not
	 * read them through the plugin. Keys: title, description, canonical, robots (list), og.title, … as available.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>|null
	 */
	public function rendered( int $post_id ): ?array;

	/**
	 * Capabilities of the plugin's own role manager that this patch needs and the current user lacks.
	 *
	 * @param array<string, mixed> $patch Flat patch.
	 * @return list<string>
	 */
	public function missing_caps( array $patch ): array;

	/**
	 * Apply a flat patch through the plugin's API. Throws ToolError when the plugin refuses.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $patch   Flat patch (only fields()).
	 */
	public function write( int $post_id, array $patch ): void;

	/**
	 * Everything write() can change, for undo.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>
	 */
	public function snapshot( int $post_id ): array;

	/**
	 * Put a snapshot() back through the plugin's API.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, mixed> $state   From snapshot().
	 */
	public function restore( int $post_id, array $state ): void;
}
