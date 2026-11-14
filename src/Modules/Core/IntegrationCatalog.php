<?php
/**
 * The catalogue of integrations KuDiagnostics knows about, with what it really supports for each.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

/**
 * One row per integration (decision D30): the target list of the upgrade plan plus what is supported beyond it. The
 * support class is a statement about tests, not intentions: "full" means real operations tested against the real
 * product, "partial" some operations or not verified against it, "detection" recognised only, "none" nothing. It
 * changes only together with INTEGRATION-MATRIX.md when a test passes. Folder names of paid products are the ones
 * those products are known to install under and are UNVERIFIED (no copy was available).
 */
final class IntegrationCatalog {

	/**
	 * Categories, in display order.
	 */
	public const CATEGORIES = array( 'builder', 'theme', 'blocks', 'store', 'forms', 'fields', 'addon', 'dynamic', 'code', 'seo', 'translation', 'cache' );

	/**
	 * Support classes.
	 */
	public const SUPPORT = array( 'full', 'partial', 'detection', 'none' );

	/**
	 * Rows: id, name, category, kind (plugin | theme | core), folders, support, tool name prefixes, copy (free | paid |
	 * core), plan phase, note.
	 *
	 * @var list<array{0: string, 1: string, 2: string, 3: string, 4: list<string>, 5: string, 6: list<string>, 7: string, 8: string, 9: string}>
	 */
	private const ROWS = array(
		array( 'elementor', 'Elementor', 'builder', 'plugin', array( 'elementor' ), 'full', array( 'kuduy-elementor-' ), 'free', 'F', 'Tested on 3.35.9 and 4.3.6. Not yet: popups, dynamic tags, duplicating a whole document.' ),
		array( 'elementor-pro', 'Elementor Pro', 'builder', 'plugin', array( 'elementor-pro' ), 'partial', array( 'kuduy-elementor-theme-' ), 'paid', 'F', 'Theme Builder templates and conditions tested with Pro 4.3.2. Not yet: popups, forms, dynamic tags.' ),
		array( 'wpbakery', 'WPBakery Page Builder', 'builder', 'plugin', array( 'js_composer' ), 'partial', array( 'kuduy-wpbakery-' ), 'paid', 'L', 'All 16 tools tested against a stand-in for its element list, not against the plugin itself. Not yet: templates.' ),
		array( 'beaver-builder', 'Beaver Builder', 'builder', 'plugin', array( 'beaver-builder-lite-version', 'bb-plugin' ), 'none', array(), 'free', 'H', '' ),
		array( 'kirki', 'Kirki', 'builder', 'plugin', array( 'kirki' ), 'none', array(), 'free', 'H', '' ),
		array( 'bricks', 'Bricks', 'builder', 'theme', array( 'bricks' ), 'none', array(), 'paid', 'L', '' ),
		array( 'oxygen', 'Oxygen', 'builder', 'plugin', array( 'oxygen' ), 'none', array(), 'paid', 'L', '' ),
		array( 'divi', 'Divi', 'builder', 'theme', array( 'Divi' ), 'none', array(), 'paid', 'L', '' ),
		array( 'divi-builder', 'Divi Builder (plugin)', 'builder', 'plugin', array( 'divi-builder' ), 'none', array(), 'paid', 'L', '' ),
		array( 'breakdance', 'Breakdance', 'builder', 'plugin', array( 'breakdance' ), 'none', array(), 'paid', 'L', '' ),
		array( 'etch', 'Etch', 'builder', 'plugin', array( 'etch' ), 'none', array(), 'paid', 'L', '' ),
		array( 'mosaic', 'Mosaic', 'builder', 'plugin', array( 'mosaic' ), 'none', array(), 'paid', 'L', '' ),

		array( 'ollie', 'Ollie', 'theme', 'theme', array( 'ollie' ), 'partial', array( 'kuduy-site-template', 'kuduy-global-styles-', 'kuduy-nav-menu' ), 'free', 'D', 'Block theme: the Site Editor tools apply, but have not been run on this theme.' ),
		array( 'spectra-one', 'Spectra One', 'theme', 'theme', array( 'spectra-one' ), 'partial', array( 'kuduy-site-template', 'kuduy-global-styles-', 'kuduy-nav-menu' ), 'free', 'D', 'Block theme: the Site Editor tools apply, but have not been run on this theme.' ),
		array( 'astra', 'Astra', 'theme', 'theme', array( 'astra' ), 'detection', array(), 'free', 'D', 'Theme settings are not managed yet.' ),
		array( 'kadence', 'Kadence', 'theme', 'theme', array( 'kadence' ), 'detection', array(), 'free', 'D', 'Theme settings are not managed yet.' ),
		array( 'blocksy', 'Blocksy', 'theme', 'theme', array( 'blocksy' ), 'detection', array(), 'free', 'D', 'Theme settings are not managed yet.' ),
		array( 'generatepress', 'GeneratePress', 'theme', 'theme', array( 'generatepress' ), 'detection', array(), 'free', 'D', 'Theme settings are not managed yet.' ),
		array( 'oceanwp', 'OceanWP', 'theme', 'theme', array( 'oceanwp' ), 'detection', array(), 'free', 'D', 'Theme settings are not managed yet.' ),
		array( 'avada', 'Avada (theme)', 'theme', 'theme', array( 'Avada' ), 'partial', array( 'kuduy-avada-status', 'kuduy-avada-global-options' ), 'paid', 'L', 'Detected with versions and child theme; Global Options readable (design settings only). Theme options are not changed.' ),
		array( 'avada-builder', 'Avada Builder', 'builder', 'plugin', array( 'fusion-builder' ), 'full', array( 'kuduy-avada-' ), 'paid', 'L', 'Pages, containers, columns, elements, Library and Layouts. Tested on real Avada 7.16.4 / Builder 3.16.4 (2026-11-13). Not changed: Global Options, Layout conditions.' ),
		array( 'voxel', 'Voxel', 'theme', 'theme', array( 'voxel' ), 'detection', array(), 'paid', 'L', 'Theme settings are not managed yet.' ),
		array( 'mylisting', 'MyListing', 'theme', 'theme', array( 'my-listing' ), 'detection', array(), 'paid', 'L', 'Theme settings are not managed yet.' ),

		array( 'gutenberg', 'WordPress blocks and Site Editor', 'blocks', 'core', array(), 'partial', array( 'kuduy-block-types', 'kuduy-post-blocks', 'kuduy-site-template', 'kuduy-global-styles-', 'kuduy-nav-menu', 'kuduy-widget' ), 'core', 'C', 'Templates, Global Styles, navigation and widgets are managed. Blocks of a post are read; changing one block is not offered yet (the whole content is replaced with kuduy-post-update). Patterns not yet.' ),
		array( 'generateblocks', 'GenerateBlocks', 'blocks', 'plugin', array( 'generateblocks' ), 'detection', array(), 'free', 'C', 'Its blocks show in kuduy-block-types; not tested.' ),
		array( 'kadence-blocks', 'Kadence Blocks', 'blocks', 'plugin', array( 'kadence-blocks' ), 'detection', array(), 'free', 'C', 'Its blocks show in kuduy-block-types; not tested.' ),
		array( 'spectra', 'Spectra (Spectra Blocks)', 'blocks', 'plugin', array( 'ultimate-addons-for-gutenberg' ), 'detection', array(), 'free', 'C', 'Its blocks show in kuduy-block-types; not tested.' ),

		array( 'woocommerce', 'WooCommerce', 'store', 'plugin', array( 'woocommerce' ), 'full', array( 'kuduy-woo-' ), 'free', 'G', 'Tested with order storage (HPOS) on and off. Not offered: refunds, permanent delete. Not yet: general store settings, attribute taxonomies.' ),

		array( 'contact-form-7', 'Contact Form 7', 'forms', 'plugin', array( 'contact-form-7' ), 'full', array( 'kuduy-forms-' ), 'free', '', 'Entries need Flamingo.' ),
		array( 'flamingo', 'Flamingo', 'forms', 'plugin', array( 'flamingo' ), 'full', array( 'kuduy-forms-entr' ), 'free', '', '' ),
		array( 'fluentform', 'Fluent Forms', 'forms', 'plugin', array( 'fluentform' ), 'partial', array( 'kuduy-forms-' ), 'free', 'B', 'Forms are read; entries are read, changed and annotated. Not yet: creating or changing forms.' ),
		array( 'wpforms', 'WPForms', 'forms', 'plugin', array( 'wpforms-lite', 'wpforms' ), 'partial', array( 'kuduy-forms-' ), 'free', 'L', 'Forms are read (tested with Lite 2.0.4). Entries need WPForms Pro: reading, changing and notes are tested against a stand-in, not against Pro itself.' ),
		array( 'gravityforms', 'Gravity Forms', 'forms', 'plugin', array( 'gravityforms' ), 'partial', array( 'kuduy-forms-' ), 'paid', 'L', 'Forms are read; entries are read, changed and annotated. Built from its documentation and tested against a stand-in, not against the plugin itself.' ),
		array( 'ninja-forms', 'Ninja Forms', 'forms', 'plugin', array( 'ninja-forms' ), 'partial', array( 'kuduy-forms-' ), 'free', 'B', 'Forms are read; submissions are read, trashed and restored (tested with 3.16.1). Not yet: creating or changing forms.' ),
		array( 'formidable', 'Formidable Forms', 'forms', 'plugin', array( 'formidable' ), 'partial', array( 'kuduy-forms-' ), 'free', 'B', 'Forms are read; entries are read only (tested with 7.0). Not yet: creating or changing forms.' ),

		array( 'acf', 'Advanced Custom Fields', 'fields', 'plugin', array( 'advanced-custom-fields', 'advanced-custom-fields-pro' ), 'partial', array( 'kuduy-acf-' ), 'free', 'E', 'Field groups are read; values are read and changed. Not yet: creating field groups; repeater and flexible content (Pro) are not verified.' ),
		array( 'meta-box', 'Meta Box', 'fields', 'plugin', array( 'meta-box' ), 'none', array(), 'free', 'E', '' ),
		array( 'pods', 'Pods', 'fields', 'plugin', array( 'pods' ), 'none', array(), 'free', 'E', '' ),
		array( 'acpt', 'ACPT', 'fields', 'plugin', array( 'acpt-lite', 'advanced-custom-post-type' ), 'none', array(), 'free', 'E', '' ),
		array( 'ase', 'Admin and Site Enhancements (ASE)', 'fields', 'plugin', array( 'admin-site-enhancements', 'admin-site-enhancements-pro' ), 'none', array(), 'free', 'E', '' ),
		array( 'jet-engine', 'JetEngine', 'fields', 'plugin', array( 'jet-engine' ), 'none', array(), 'paid', 'L', '' ),

		array( 'ultimate-addons-elementor', 'Ultimate Addons for Elementor', 'addon', 'plugin', array( 'header-footer-elementor', 'ultimate-elementor' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with the free version 2.9.6).' ),
		array( 'essential-addons-elementor', 'Essential Addons for Elementor', 'addon', 'plugin', array( 'essential-addons-for-elementor-lite' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with 6.8.8).' ),
		array( 'premium-addons-elementor', 'Premium Addons for Elementor', 'addon', 'plugin', array( 'premium-addons-for-elementor' ), 'full', array( 'kuduy-elementor-' ), 'free', '', 'Its widgets work through the Elementor tools (tested with 4.11.117).' ),
		array( 'happy-addons', 'Happy Addons', 'addon', 'plugin', array( 'happy-elementor-addons' ), 'partial', array( 'kuduy-elementor-' ), 'free', 'F', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'ht-mega', 'HT Mega', 'addon', 'plugin', array( 'ht-mega-for-elementor' ), 'partial', array( 'kuduy-elementor-' ), 'free', 'F', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'powerpack', 'PowerPack for Elementor', 'addon', 'plugin', array( 'powerpack-lite-for-elementor', 'powerpack-elements' ), 'partial', array( 'kuduy-elementor-' ), 'free', 'F', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'qi-addons', 'Qi Addons for Elementor', 'addon', 'plugin', array( 'qi-addons-for-elementor' ), 'partial', array( 'kuduy-elementor-' ), 'free', 'F', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'unlimited-elements', 'Unlimited Elements for Elementor', 'addon', 'plugin', array( 'unlimited-elements-for-elementor' ), 'partial', array( 'kuduy-elementor-' ), 'free', 'F', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'xpro-addons', 'Xpro Elementor Addons', 'addon', 'plugin', array( 'xpro-elementor-addons' ), 'partial', array( 'kuduy-elementor-' ), 'free', 'F', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'element-pack', 'Element Pack', 'addon', 'plugin', array( 'bdthemes-element-pack-lite', 'bdthemes-element-pack' ), 'partial', array( 'kuduy-elementor-' ), 'free', 'F', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'piotnet-addons', 'Piotnet Addons for Elementor', 'addon', 'plugin', array( 'piotnet-addons-for-elementor', 'piotnet-addons-for-elementor-pro' ), 'partial', array( 'kuduy-elementor-' ), 'free', 'F', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'jet-elements', 'JetElements', 'addon', 'plugin', array( 'jet-elements' ), 'partial', array( 'kuduy-elementor-' ), 'paid', 'L', 'Its widgets should work through the Elementor tools like any registered widget; not tested.' ),
		array( 'dynamic-content-elementor', 'Dynamic Content for Elementor', 'addon', 'plugin', array( 'dynamic-content-for-elementor' ), 'partial', array( 'kuduy-elementor-' ), 'paid', 'L', 'Its widgets should work through the Elementor tools like any registered widget; not tested. Dynamic tags are not managed yet.' ),
		array( 'bricksforge', 'Bricksforge', 'addon', 'plugin', array( 'bricksforge' ), 'none', array(), 'paid', 'L', 'Needs Bricks.' ),

		array( 'dynamic-shortcodes', 'Dynamic Shortcodes', 'dynamic', 'plugin', array( 'dynamic-shortcodes' ), 'none', array(), 'paid', 'L', '' ),
		array( 'code-snippets', 'Code Snippets', 'code', 'plugin', array( 'code-snippets', 'code-snippets-pro' ), 'none', array(), 'free', 'G', '' ),

		array( 'rank-math', 'Rank Math SEO', 'seo', 'plugin', array( 'seo-by-rank-math' ), 'full', array( 'kuduy-seo-' ), 'free', 'G', 'Meta, redirects, schema, sitemap status. Not yet: heading, link and duplicate audits.' ),
		array( 'yoast', 'Yoast SEO', 'seo', 'plugin', array( 'wordpress-seo' ), 'full', array( 'kuduy-seo-status', 'kuduy-seo-get', 'kuduy-seo-update', 'kuduy-seo-verify', 'kuduy-seo-audit', 'kuduy-seo-sitemap' ), 'free', 'G', 'Meta and sitemap status. Not yet: schema type, sitemap settings.' ),
		array( 'aioseo', 'All in One SEO', 'seo', 'plugin', array( 'all-in-one-seo-pack' ), 'full', array( 'kuduy-seo-status', 'kuduy-seo-get', 'kuduy-seo-update', 'kuduy-seo-verify', 'kuduy-seo-audit', 'kuduy-seo-sitemap' ), 'free', 'G', 'Meta.' ),
		array( 'seopress', 'SEOPress', 'seo', 'plugin', array( 'wp-seopress' ), 'full', array( 'kuduy-seo-status', 'kuduy-seo-get', 'kuduy-seo-update', 'kuduy-seo-verify', 'kuduy-seo-audit', 'kuduy-seo-sitemap' ), 'free', 'G', 'Meta.' ),

		array( 'polylang', 'Polylang', 'translation', 'plugin', array( 'polylang', 'polylang-pro' ), 'full', array( 'kuduy-lang-' ), 'free', '', 'Tested with 3.9.2.' ),
		array( 'wpml', 'WPML', 'translation', 'plugin', array( 'sitepress-multilingual-cms' ), 'none', array(), 'paid', 'L', '' ),
		array( 'weglot', 'Weglot', 'translation', 'plugin', array( 'weglot' ), 'none', array(), 'free', 'K', 'Translations are kept in Weglot\'s cloud service, not on the site.' ),

		array( 'litespeed-cache', 'LiteSpeed Cache', 'cache', 'plugin', array( 'litespeed-cache' ), 'full', array( 'kuduy-cache-' ), 'free', '', '' ),
		array( 'w3-total-cache', 'W3 Total Cache', 'cache', 'plugin', array( 'w3-total-cache' ), 'full', array( 'kuduy-cache-' ), 'free', '', '' ),
		array( 'wp-super-cache', 'WP Super Cache', 'cache', 'plugin', array( 'wp-super-cache' ), 'full', array( 'kuduy-cache-' ), 'free', '', '' ),
		array( 'wp-fastest-cache', 'WP Fastest Cache', 'cache', 'plugin', array( 'wp-fastest-cache' ), 'full', array( 'kuduy-cache-' ), 'free', '', '' ),
		array( 'wp-rocket', 'WP Rocket', 'cache', 'plugin', array( 'wp-rocket' ), 'partial', array( 'kuduy-cache-' ), 'paid', 'L', 'Purge built from its documentation; not tested against the plugin.' ),
	);

	/**
	 * Every catalogue row as a keyed array.
	 *
	 * @return list<array{id: string, name: string, category: string, kind: string, folders: list<string>, support: string, prefixes: list<string>, copy: string, phase: string, note: string}>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::ROWS as $row ) {
			$out[] = array(
				'id'       => $row[0],
				'name'     => $row[1],
				'category' => $row[2],
				'kind'     => $row[3],
				'folders'  => $row[4],
				'support'  => $row[5],
				'prefixes' => $row[6],
				'copy'     => $row[7],
				'phase'    => $row[8],
				'note'     => $row[9],
			);
		}
		return $out;
	}
}
