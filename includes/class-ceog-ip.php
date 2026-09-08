<?php
/**
 * Client IP resolution and privacy helpers.
 *
 * @package CoderEmbassy_Order_Vanguard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves, normalizes, anonymizes, and hashes client addresses.
 */
final class CEOG_IP {
	/**
	 * Resolves the client IP using only the explicitly trusted source.
	 *
	 * @param array<string, mixed>|null $settings Optional normalized settings.
	 * @return string Canonical full address, or an empty string.
	 */
	public static function resolve( $settings = null ) {
		$settings  = is_array( $settings ) ? $settings : ceog_get_settings();
		$proxy     = isset( $settings['trusted_proxy'] ) ? (string) $settings['trusted_proxy'] : 'none';
		$fallback  = self::server_value( 'REMOTE_ADDR' );
		$candidate = $fallback;

		if ( 'cloudflare' === $proxy ) {
			$candidate = self::server_value( 'HTTP_CF_CONNECTING_IP' );
		} elseif ( 'xff' === $proxy ) {
			$forwarded = explode( ',', self::server_value( 'HTTP_X_FORWARDED_FOR' ) );
			$candidate = isset( $forwarded[0] ) ? trim( $forwarded[0] ) : '';
		}

		$resolved = self::canonicalize( $candidate );

		return '' === $resolved ? self::canonicalize( $fallback ) : $resolved;
	}

	/**
	 * Returns a canonical full IPv4 or IPv6 address.
	 *
	 * @param mixed $ip Candidate address.
	 * @return string
	 */
	public static function canonicalize( $ip ) {
		$ip = is_scalar( $ip ) ? trim( (string) $ip ) : '';

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return '';
		}

		$canonical = inet_ntop( $packed );

		return false === $canonical ? '' : strtolower( $canonical );
	}

	/**
	 * Normalizes IPv6 addresses to their /64 network for matching and hashing.
	 *
	 * @param mixed $ip Candidate address.
	 * @return string IPv4 address or canonical IPv6 /64 network address.
	 */
	public static function normalize( $ip ) {
		$canonical = self::canonicalize( $ip );
		if ( '' === $canonical || false === filter_var( $canonical, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $canonical;
		}

		$packed = inet_pton( $canonical );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return '';
		}

		$network = substr( $packed, 0, 8 ) . str_repeat( "\0", 8 );
		$prefix  = inet_ntop( $network );

		return false === $prefix ? '' : strtolower( $prefix );
	}

	/**
	 * Returns the privacy-preserving human-readable address.
	 *
	 * @param mixed $ip Candidate address.
	 * @return string
	 */
	public static function anonymize( $ip ) {
		$canonical = self::canonicalize( $ip );
		if ( '' === $canonical ) {
			return '';
		}

		if ( filter_var( $canonical, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$octets    = explode( '.', $canonical );
			$octets[3] = '0';

			return implode( '.', $octets );
		}

		$normalized = self::normalize( $canonical );

		return '' === $normalized ? '' : $normalized . '/64';
	}

	/**
	 * Produces a stable one-way hash for an IPv4 or IPv6 /64 network.
	 *
	 * @param mixed $ip Candidate address.
	 * @return string
	 */
	public static function hash( $ip ) {
		$normalized = self::normalize( $ip );

		return '' === $normalized
			? ''
			: hash_hmac( 'sha256', $normalized, wp_salt( 'auth' ) );
	}

	/**
	 * Produces a stable one-way hash for a lowercased email address.
	 *
	 * @param mixed $email Candidate email.
	 * @return string
	 */
	public static function email_hash( $email ) {
		$email = is_scalar( $email ) ? strtolower( trim( (string) $email ) ) : '';

		return '' === $email ? '' : hash_hmac( 'sha256', $email, wp_salt( 'auth' ) );
	}

	/**
	 * Reads a scalar server variable without trusting arrays or objects.
	 *
	 * @param string $key Server variable key.
	 * @return string
	 */
	private static function server_value( $key ) {
		return isset( $_SERVER[ $key ] ) && is_scalar( $_SERVER[ $key ] )
			? trim( sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) )
			: '';
	}
}

/**
 * Hashes an address using the shared IPv6 /64 normalization rule.
 *
 * @param mixed $ip Candidate address.
 * @return string
 */
function ceog_ip_hash( $ip ) {
	return CEOG_IP::hash( $ip );
}

/**
 * Hashes a lowercased email without storing the plaintext value.
 *
 * @param mixed $email Candidate email.
 * @return string
 */
function ceog_email_hash( $email ) {
	return CEOG_IP::email_hash( $email );
}
