<?php
defined( 'ABSPATH' ) || exit;

/** Enforce verified HTTPS at the shared SDK's WordPress HTTP boundary. */
final class RR_Freemius_Transport {
	private static $initialized = false;
	private const HOSTS = array( 'api.freemius.com', 'sandbox-api.freemius.com', 'wp.freemius.com', 'fast-api.freemius.com' );
	private const ORIGIN = 'rr_freemius_verified_origin';

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_filter( 'http_request_args', array( __CLASS__, 'request_args' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_http_request', array( __CLASS__, 'pre_request' ), PHP_INT_MAX, 3 );
		add_filter( 'https_ssl_verify', array( __CLASS__, 'verify_certificate' ), PHP_INT_MAX, 2 );
		add_action( 'requests-requests.before_request', array( __CLASS__, 'before_request' ), PHP_INT_MAX, 5 );
		add_action( 'requests-requests.before_redirect', array( __CLASS__, 'before_redirect' ), PHP_INT_MAX, 5 );
	}

	private static function endpoint( $url ) {
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || ! in_array( strtolower( rtrim( $parts['host'], '.' ) ), self::HOSTS, true ) ) {
			return false;
		}
		return $parts;
	}

	private static function ca_bundle() {
		return ABSPATH . WPINC . '/certificates/ca-bundle.crt';
	}

	private static function secure_url( $parts ) {
		return is_array( $parts ) && isset( $parts['host'] ) && 'https' === strtolower( $parts['scheme'] ?? '' ) && ( ! isset( $parts['port'] ) || 443 === $parts['port'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}

	private static function error_message() {
		if ( ! did_action( 'init' ) ) {
			return 'Rewards & Referrals licensing requires verified HTTPS. Check the server certificate trust and connection, then try again.';
		}
		return __( 'Rewards & Referrals licensing requires verified HTTPS. Check the server certificate trust and connection, then try again.', 'rewards-referrals' );
	}

	public static function request_args( $args, $url ) {
		if ( self::endpoint( $url ) ) {
			$args['sslverify']          = true;
			$args['sslcertificates']    = self::ca_bundle();
			// Signed ZIP downloads may redirect to a CDN. The per-hop hooks below protect that path.
			$args['redirection']        = max( 0, min( 5, (int) ( $args['redirection'] ?? 5 ) ) );
			$args['_redirection']       = $args['redirection'];
			$args['reject_unsafe_urls'] = true;
		}
		return $args;
	}

	public static function pre_request( $preempt, $args, $url ) {
		$parts = self::endpoint( $url );
		if ( ! $parts || is_wp_error( $preempt ) ) {
			return $preempt;
		}
		if ( ! self::secure_url( $parts ) || true !== ( $args['sslverify'] ?? null ) || self::ca_bundle() !== ( $args['sslcertificates'] ?? null ) || ! is_readable( self::ca_bundle() ) || ! is_int( $args['redirection'] ?? null ) || $args['redirection'] < 0 || $args['redirection'] > 5 || $args['redirection'] !== ( $args['_redirection'] ?? null ) || true !== ( $args['reject_unsafe_urls'] ?? null ) ) {
			// Do not include request URLs, query strings, headers or license values.
			return new WP_Error(
				'rr_freemius_secure_transport_required',
				self::error_message()
			);
		}
		return $preempt;
	}

	public static function verify_certificate( $verify, $url ) {
		// WordPress has a second certificate filter after its request-argument filter.
		return self::endpoint( $url ) ? true : $verify;
	}

	private static function remove_credentials( &$headers ) {
		foreach ( array_keys( $headers ) as $name ) {
			if ( in_array( strtolower( (string) $name ), array( 'authorization', 'proxy-authorization', 'cookie', 'referer', 'referrer', 'date', 'content-md5' ), true ) ) {
				unset( $headers[ $name ] );
			}
		}
	}

	public static function before_request( &$url, &$headers, &$data, &$type, &$options ) {
		$parts = self::endpoint( $url );
		if ( ! $parts && empty( $options[ self::ORIGIN ] ) ) {
			return;
		}
		$parts = wp_parse_url( $url );
		if ( ! self::secure_url( $parts ) || ! is_readable( self::ca_bundle() ) ) {
			throw new \WpOrg\Requests\Exception( self::error_message(), 'rr_freemius_secure_transport_required' );
		}
		if ( empty( $options[ self::ORIGIN ] ) ) {
			$options[ self::ORIGIN ] = array( 'host' => strtolower( rtrim( $parts['host'], '.' ) ), 'crossed' => false );
		}
		$origin = &$options[ self::ORIGIN ];
		$origin['crossed'] = $origin['crossed'] || $origin['host'] !== strtolower( rtrim( $parts['host'], '.' ) );
		if ( $origin['crossed'] ) {
			if ( ! in_array( strtoupper( $type ), array( 'GET', 'HEAD' ), true ) || ! empty( $data ) || ! empty( $options['auth'] ) ) {
				throw new \WpOrg\Requests\Exception( self::error_message(), 'rr_freemius_redirect_rejected' );
			}
			// Runs after Requests' cookie callbacks, including on subsequent CDN hops.
			self::remove_credentials( $headers );
			$options['auth'] = false;
			$options['cookies'] = false;
		}
		$options['verify'] = self::ca_bundle();
		$options['verifyname'] = true;
	}

	public static function before_redirect( &$location, &$headers, &$data, &$options, $original ) {
		if ( empty( $options[ self::ORIGIN ] ) && ! self::endpoint( $original->url ) ) {
			return;
		}
		$target = wp_parse_url( $location );
		if ( ! self::secure_url( $target ) || ! wp_http_validate_url( $location ) ) {
			throw new \WpOrg\Requests\Exception( self::error_message(), 'rr_freemius_redirect_rejected' );
		}
		if ( empty( $options[ self::ORIGIN ] ) ) {
			$from = wp_parse_url( $original->url );
			$options[ self::ORIGIN ] = array( 'host' => strtolower( rtrim( $from['host'], '.' ) ), 'crossed' => false );
		}
		$origin = &$options[ self::ORIGIN ];
		$origin['crossed'] = $origin['crossed'] || $origin['host'] !== strtolower( rtrim( $target['host'], '.' ) );
		if ( $origin['crossed'] ) {
			// Existing Requests auth-handler callbacks cannot safely be unregistered here.
			if ( ! empty( $options['auth'] ) || ! in_array( strtoupper( $options['type'] ?? '' ), array( 'GET', 'HEAD' ), true ) || ! empty( $data ) ) {
				throw new \WpOrg\Requests\Exception( self::error_message(), 'rr_freemius_redirect_rejected' );
			}
			self::remove_credentials( $headers );
			$options['auth'] = false;
			$options['cookies'] = false;
		}
		$options['verify'] = self::ca_bundle();
		$options['verifyname'] = true;
	}
}
