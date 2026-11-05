<?php
/**
 * Self-update from GitHub Releases (architecture §11, D5).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Update;

use Kuduy\Mcp\Plugin;

/**
 * Release contract: tag vX.Y.Z with assets kuduy-mcp.zip and kuduy-mcp.zip.sha256.
 * Checksum from the same release proves integrity, not authorship (signing: later).
 */
final class GitHubUpdater {

	public const REPO = 'kuduyDigital/KuDiagnostics';

	public const CACHE = 'kuduy_mcp_release';

	private const TTL = 12 * HOUR_IN_SECONDS;

	private const RETRY = HOUR_IN_SECONDS;

	private const ZIP = 'kuduy-mcp.zip';

	private const MAX_ZIP_BYTES = 50 * MB_IN_BYTES;

	/**
	 * Our plugin file relative to the plugins folder.
	 */
	public static function basename(): string {
		return plugin_basename( dirname( __DIR__, 2 ) . '/kuduy-mcp.php' );
	}

	/**
	 * `update_plugins_github.com` (core, WP 5.8+): our update data, only for our plugin file.
	 *
	 * @param mixed                $update      Update so far (false).
	 * @param array<string, mixed> $plugin_data Plugin headers.
	 * @param string               $plugin_file Plugin file.
	 * @return mixed
	 */
	public static function filter_update( mixed $update, array $plugin_data, string $plugin_file ): mixed {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$release = self::latest();
		return null === $release ? $update : self::update_data( $release );
	}

	/**
	 * `site_transient_update_plugins`: core skips Update URI plugins when wordpress.org is unreachable
	 * (wp_update_plugins() returns before that loop), so offer a cached newer release ourselves. Never fetches.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public static function inject( mixed $transient ): mixed {
		$release = self::cached();
		$file    = self::basename();
		if ( null === $release || ! version_compare( $release['version'], Plugin::VERSION, '>' ) ) {
			return $transient;
		}
		$transient = is_object( $transient ) ? $transient : new \stdClass();
		if ( isset( $transient->response[ $file ] ) ) {
			return $transient;
		}
		$transient->response          = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$data                         = self::update_data( $release );
		$data['new_version']          = $release['version'];
		$data['plugin']               = $file;
		$data['id']                   = 'https://github.com/' . self::REPO;
		$transient->response[ $file ] = (object) $data;
		return $transient;
	}

	/**
	 * `plugins_api`: the "View details" modal.
	 *
	 * @param mixed $result Result so far.
	 * @param mixed $action API action.
	 * @param mixed $args   Arguments (object with slug).
	 * @return mixed
	 */
	public static function plugins_api( mixed $result, mixed $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || 'kuduy-mcp' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::latest();
		if ( null === $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'KUDUY MCP',
			'slug'          => 'kuduy-mcp',
			'version'       => $release['version'],
			'author'        => 'KUDUY Digital',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.9',
			'requires_php'  => '8.1',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'MCP server for WordPress: connect Claude and manage this site under your own WordPress permissions.', 'kuduy-mcp' ),
				'changelog'   => wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * `upgrader_pre_download`: for our package, download it and its checksum and refuse a mismatch.
	 *
	 * @param mixed $reply      False, or what another filter already decided.
	 * @param mixed $package    Package URL.
	 * @return mixed Temp file path, WP_Error, or $reply untouched.
	 */
	public static function pre_download( mixed $reply, mixed $package ): mixed {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$release = self::cached() ?? self::latest();
		if ( null === $release || $package !== $release['package'] ) {
			return $reply;
		}
		$zip = self::fetch( $package, self::MAX_ZIP_BYTES );
		$sum = self::fetch( $release['checksum'], 4096 );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		if ( is_wp_error( $sum ) || ! preg_match( '/\b([a-f0-9]{64})\b/i', $sum, $m ) ) {
			return new \WP_Error( 'kuduy_mcp_checksum', __( 'The update checksum could not be read, so the update was not installed.', 'kuduy-mcp' ) );
		}
		if ( ! hash_equals( strtolower( $m[1] ), hash( 'sha256', $zip ) ) ) {
			return new \WP_Error( 'kuduy_mcp_checksum', __( 'The downloaded update does not match its published checksum, so it was not installed.', 'kuduy-mcp' ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = wp_tempnam( self::ZIP );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file handed to the upgrader, like download_url().
		if ( false === file_put_contents( $file, $zip ) ) {
			return new \WP_Error( 'kuduy_mcp_download', __( 'Could not save the update to a temporary file.', 'kuduy-mcp' ) );
		}
		return $file;
	}

	/**
	 * Latest usable release (cached 12 h, ETag-conditional).
	 *
	 * @param bool $force Re-check now.
	 * @return array{version:string, package:string, checksum:string, notes:string, url:string, published:string}|null
	 */
	public static function latest( bool $force = false ): ?array {
		$cache   = get_site_transient( self::CACHE );
		$cache   = is_array( $cache ) && ( $cache['channel'] ?? '' ) === self::channel() ? $cache : null;
		$release = $cache['release'] ?? null;
		if ( ! $force && null !== $cache && (int) $cache['fetched'] > time() - self::TTL ) {
			return $release;
		}

		$beta    = 'beta' === self::channel();
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => self::user_agent(),
		);
		$etag    = $cache['etag'] ?? '';
		if ( '' !== $etag ) {
			$headers['If-None-Match'] = $etag;
		}
		$url      = 'https://api.github.com/repos/' . self::REPO . ( $beta ? '/releases?per_page=10' : '/releases/latest' );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$fetched  = time();

		if ( 304 === $code ) {
			// Unchanged: keep the cached release.
			$etag = (string) $etag;
		} elseif ( 200 === $code ) {
			$data    = json_decode( wp_remote_retrieve_body( $response ), true );
			$list    = $beta ? ( is_array( $data ) ? $data : array() ) : array( $data );
			$release = null;
			foreach ( $list as $candidate ) {
				$release = is_array( $candidate ) ? self::parse( $candidate, $beta ) : null;
				if ( null !== $release ) {
					break;
				}
			}
			$etag = (string) wp_remote_retrieve_header( $response, 'etag' );
		} else {
			// GitHub unreachable or rate-limited: keep what we had, try again in an hour.
			$fetched = time() - self::TTL + self::RETRY;
		}

		set_site_transient(
			self::CACHE,
			array(
				'channel' => self::channel(),
				'etag'    => $etag,
				'release' => $release,
				'fetched' => $fetched,
			),
			7 * DAY_IN_SECONDS
		);
		return $release;
	}

	/**
	 * Cached release without any HTTP (null if never fetched).
	 *
	 * @return array{version:string, package:string, checksum:string, notes:string, url:string, published:string}|null
	 */
	public static function cached(): ?array {
		$cache = get_site_transient( self::CACHE );
		return is_array( $cache ) && is_array( $cache['release'] ?? null ) ? $cache['release'] : null;
	}

	/**
	 * Update channel: `stable` (default) or `beta` (also pre-releases).
	 */
	private static function channel(): string {
		$channel = defined( 'KUDUY_MCP_UPDATE_CHANNEL' ) ? (string) constant( 'KUDUY_MCP_UPDATE_CHANNEL' ) : 'stable';
		/**
		 * Filters the update channel.
		 *
		 * @param string $channel stable | beta.
		 */
		return 'beta' === apply_filters( 'kuduy_mcp_update_channel', $channel ) ? 'beta' : 'stable';
	}

	/**
	 * Release from the GitHub API, or null when unusable (draft, pre-release on stable, bad tag, missing assets).
	 *
	 * @param array<string, mixed> $r    Release object.
	 * @param bool                 $beta Accept pre-releases.
	 * @return array{version:string, package:string, checksum:string, notes:string, url:string, published:string}|null
	 */
	private static function parse( array $r, bool $beta ): ?array {
		if ( ! empty( $r['draft'] ) || ( ! $beta && ! empty( $r['prerelease'] ) ) ) {
			return null;
		}
		$version = ltrim( is_string( $r['tag_name'] ?? null ) ? $r['tag_name'] : '', 'v' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version ) ) {
			return null;
		}
		$assets = array();
		foreach ( is_array( $r['assets'] ?? null ) ? $r['assets'] : array() as $asset ) {
			if ( is_array( $asset ) && is_string( $asset['name'] ?? null ) && is_string( $asset['browser_download_url'] ?? null ) ) {
				$assets[ $asset['name'] ] = $asset['browser_download_url'];
			}
		}
		if ( ! isset( $assets[ self::ZIP ], $assets[ self::ZIP . '.sha256' ] ) ) {
			return null;
		}
		return array(
			'version'   => $version,
			'package'   => $assets[ self::ZIP ],
			'checksum'  => $assets[ self::ZIP . '.sha256' ],
			'notes'     => mb_substr( is_string( $r['body'] ?? null ) ? $r['body'] : '', 0, 20000 ),
			'url'       => is_string( $r['html_url'] ?? null ) ? $r['html_url'] : 'https://github.com/' . self::REPO . '/releases',
			'published' => is_string( $r['published_at'] ?? null ) ? $r['published_at'] : '',
		);
	}

	/**
	 * Core update fields.
	 *
	 * @param array{version:string, package:string, checksum:string, notes:string, url:string, published:string} $release Release.
	 * @return array<string, mixed>
	 */
	private static function update_data( array $release ): array {
		return array(
			'slug'         => 'kuduy-mcp',
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.9',
			'requires_php' => '8.1',
		);
	}

	/**
	 * GET a URL into memory.
	 *
	 * @param string $url URL.
	 * @param int    $max Max bytes.
	 */
	private static function fetch( string $url, int $max ): string|\WP_Error {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'limit_response_size' => $max,
				'headers'             => array( 'User-Agent' => self::user_agent() ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			/* translators: %d: HTTP status code. */
			return new \WP_Error( 'kuduy_mcp_download', sprintf( __( 'Update download failed (HTTP %d).', 'kuduy-mcp' ), (int) wp_remote_retrieve_response_code( $response ) ) );
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * GitHub requires a User-Agent; the site URL is not sent.
	 */
	private static function user_agent(): string {
		return 'KUDUY-MCP/' . Plugin::VERSION . ' (+https://github.com/' . self::REPO . ')';
	}
}
