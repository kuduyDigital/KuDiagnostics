<?php
/**
 * Authorization endpoint and consent screen (wp-login.php?action=kuduy-mcp-authorize).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\OAuth;

/**
 * Decides in handle(), does HTTP in route(). Bad client or redirect → error page, never a redirect.
 */
final class Authorize {

	public const NONCE = 'kuduy-mcp-authorize';

	private const PARAMS = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' );

	/**
	 * Hooked to login_form_kuduy-mcp-authorize.
	 */
	public static function route(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- handle() verifies the nonce on POST.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ? 'POST' : 'GET';
		$result = self::handle( $method, wp_unslash( $_GET ), wp_unslash( $_POST ) );
		// phpcs:enable
		nocache_headers();
		if ( 'login' === $result['type'] ) {
			wp_safe_redirect( $result['url'] );
			exit;
		}
		if ( 'redirect' === $result['type'] ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri was matched against the client's registered URIs.
			wp_redirect( $result['url'] );
			exit;
		}
		status_header( $result['status'] );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		login_header( __( 'Connect to this site', 'kuduy-mcp' ) );
		echo $result['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in this class.
		login_footer();
		exit;
	}

	/**
	 * Decide the response to an authorization request.
	 *
	 * @param string               $method GET shows consent, POST processes it.
	 * @param array<string, mixed> $query  Query parameters (unslashed).
	 * @param array<string, mixed> $post   Form fields (unslashed).
	 * @return array{type:string, url?:string, status?:int, html?:string}
	 */
	public static function handle( string $method, array $query, array $post ): array {
		$in  = 'POST' === $method ? $post : $query;
		$get = static fn( string $key ): string => is_string( $in[ $key ] ?? null ) ? $in[ $key ] : '';

		if ( Urls::insecure() ) {
			return self::page( 400, __( 'This site must use HTTPS before apps can connect to it.', 'kuduy-mcp' ) );
		}
		// Log in first: looking up a CIMD client fetches a URL, which anonymous visitors must not trigger.
		if ( ! is_user_logged_in() ) {
			$params = array_filter( array_intersect_key( $in, array_flip( self::PARAMS ) ), 'is_string' );
			return array(
				'type' => 'login',
				'url'  => wp_login_url( add_query_arg( array_map( 'rawurlencode', $params ), Urls::authorize() ) ),
			);
		}

		$client = '' === $get( 'client_id' ) ? null : Clients::find( $get( 'client_id' ) );
		if ( null === $client ) {
			return self::page( 400, __( 'This application is not registered, or its client information could not be verified.', 'kuduy-mcp' ) );
		}
		$redirect = $get( 'redirect_uri' );
		if ( '' === $redirect || ! Clients::redirect_allowed( $client, $redirect ) ) {
			return self::page( 400, __( 'The return address of this application is not registered. Nothing was shared.', 'kuduy-mcp' ) );
		}
		$fail = static fn( string $error, string $description ) => self::back(
			$redirect,
			$get( 'state' ),
			array(
				'error'             => $error,
				'error_description' => $description,
			)
		);

		if ( 'code' !== $get( 'response_type' ) ) {
			return $fail( 'unsupported_response_type', 'Only response_type=code is supported.' );
		}
		if ( 'S256' !== $get( 'code_challenge_method' ) || ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $get( 'code_challenge' ) ) ) {
			return $fail( 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' );
		}
		if ( '' !== $get( 'resource' ) && ! Urls::is_ours( $get( 'resource' ) ) ) {
			return $fail( 'invalid_target', 'resource must be ' . Urls::resource() );
		}
		$requested = self::requested_scopes( $get( 'scope' ) );
		if ( ! $requested ) {
			return $fail( 'invalid_scope', 'Supported scopes: ' . implode( ' ', Scopes::supported() ) );
		}

		$user    = wp_get_current_user();
		$offered = Scopes::for_user( $requested, $user );
		if ( ! $offered ) {
			return $fail( 'access_denied', 'Your WordPress account cannot grant the requested access.' );
		}

		if ( 'POST' !== $method ) {
			return array(
				'type'   => 'page',
				'status' => 200,
				'html'   => self::consent( $client, $redirect, $offered, $user, array_intersect_key( $in, array_flip( self::PARAMS ) ) ),
			);
		}

		if ( ! wp_verify_nonce( is_string( $post['_kuduy_nonce'] ?? null ) ? $post['_kuduy_nonce'] : '', self::NONCE ) ) {
			return self::page( 403, __( 'This approval form expired or was not sent from this site. Please start the connection again.', 'kuduy-mcp' ) );
		}
		if ( 'approve' !== ( $post['decision'] ?? '' ) ) {
			return $fail( 'access_denied', 'The user declined.' );
		}
		// Browsers send nothing for unticked boxes: no `scopes` field means nothing was granted.
		$ticked = array_values( array_intersect( $offered, (array) ( $post['scopes'] ?? array() ) ) );
		if ( ! $ticked ) {
			return $fail( 'access_denied', 'No access was granted.' );
		}
		$code = Tokens::new_code( $client['client_id'], $user->ID, $redirect, $ticked, $get( 'code_challenge' ) );
		return self::back( $redirect, $get( 'state' ), array( 'code' => $code ) );
	}

	/**
	 * Requested scopes; none asked (or only offline_access) means the defaults.
	 *
	 * @param string $scope Scope parameter.
	 * @return list<string>
	 */
	private static function requested_scopes( string $scope ): array {
		$parts = preg_split( '/\s+/', trim( $scope ) );
		$asked = array_diff( false === $parts ? array() : $parts, array( '', 'offline_access' ) );
		return $asked ? Scopes::parse( implode( ' ', $asked ) ) : Scopes::DEFAULT;
	}

	/**
	 * Redirect back to the client with state and iss (RFC 9207).
	 *
	 * @param string                $redirect Redirect URI.
	 * @param string                $state    State.
	 * @param array<string, string> $params   Response parameters.
	 * @return array{type:string, url:string}
	 */
	private static function back( string $redirect, string $state, array $params ): array {
		if ( '' !== $state ) {
			$params['state'] = $state;
		}
		$params['iss'] = Urls::issuer();
		$separator     = str_contains( $redirect, '?' ) ? '&' : '?';
		return array(
			'type' => 'redirect',
			'url'  => $redirect . $separator . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ),
		);
	}

	/**
	 * Error page.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Message.
	 * @return array{type:string, status:int, html:string}
	 */
	private static function page( int $status, string $message ): array {
		return array(
			'type'   => 'page',
			'status' => $status,
			'html'   => '<div id="login_error" class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>',
		);
	}

	/**
	 * Consent form.
	 *
	 * @param array{client_id:string, name:string, redirect_uris:list<string>, type:string} $client   Client.
	 * @param string                                                                        $redirect Redirect URI.
	 * @param list<string>                                                                  $offered  Scopes the user may grant.
	 * @param \WP_User                                                                      $user     User.
	 * @param array<string, mixed>                                                          $params   OAuth params to carry.
	 */
	private static function consent( array $client, string $redirect, array $offered, \WP_User $user, array $params ): string {
		$host     = (string) wp_parse_url( $redirect, PHP_URL_HOST );
		$loopback = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
		$labels   = array(
			Scopes::READ  => __( 'Read site content and settings', 'kuduy-mcp' ),
			Scopes::WRITE => __( 'Create and edit content', 'kuduy-mcp' ),
			Scopes::ADMIN => __( 'Manage plugins, themes, users and settings', 'kuduy-mcp' ),
			Scopes::CODE  => __( 'Run code on this site (developer mode)', 'kuduy-mcp' ),
		);

		$html = '<form method="post" action="' . esc_url( Urls::authorize() ) . '">'
			. '<p><strong>' . esc_html( $client['name'] ) . '</strong> '
			/* translators: %s: WordPress display name. */
			. esc_html( sprintf( __( 'wants to act on this site as %s, limited by your WordPress permissions.', 'kuduy-mcp' ), $user->display_name ) ) . '</p>'
			/* translators: %s: host name the browser returns to after approval. */
			. '<p>' . esc_html( sprintf( __( 'After you approve, you return to: %s', 'kuduy-mcp' ), $host ) ) . '</p>';
		if ( $loopback ) {
			$html .= '<p class="message">' . esc_html__( 'This returns to a program on your own computer (for example Claude Code). Approve only if you just started this connection yourself.', 'kuduy-mcp' ) . '</p>';
		}
		if ( 'cimd' === $client['type'] ) {
			/* translators: %s: host publishing the application's identity document. */
			$html .= '<p>' . esc_html( sprintf( __( 'Application identity published by: %s', 'kuduy-mcp' ), (string) wp_parse_url( $client['client_id'], PHP_URL_HOST ) ) ) . '</p>';
		}
		$html .= '<fieldset><legend>' . esc_html__( 'Allow:', 'kuduy-mcp' ) . '</legend>';
		foreach ( $offered as $scope ) {
			$html .= '<p><label><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '" checked> ' . esc_html( $labels[ $scope ] ?? $scope ) . '</label></p>';
		}
		$html .= '</fieldset>';
		foreach ( $params as $key => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( is_string( $value ) ? $value : '' ) . '">';
		}
		$html .= wp_nonce_field( self::NONCE, '_kuduy_nonce', false, false )
			. '<p class="submit"><button type="submit" name="decision" value="approve" class="button button-primary button-large">' . esc_html__( 'Approve', 'kuduy-mcp' ) . '</button> '
			. '<button type="submit" name="decision" value="deny" class="button button-large">' . esc_html__( 'Deny', 'kuduy-mcp' ) . '</button></p></form>';
		return $html;
	}
}
