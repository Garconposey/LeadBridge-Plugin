<?php
/**
 * LeadBridge – Utility helpers.
 * IGN city resolution, rate limiting, sanitization, validation.
 */

defined( 'ABSPATH' ) || exit;

class LeadBridge_Utils {

    // ── IGN API – Resolve city from French postal code ────────────────────────

    /**
     * Returns the city name for a 5-digit French postal code.
     * Falls back to 'Non précisée' on error.
     */
    public static function resolve_city( string $cp ): string {
        $cp = trim( $cp );

        if ( ! preg_match( '/^\d{5}$/', $cp ) ) {
            return 'Non précisée';
        }

        // Short-circuit cache (transient, 7 days)
        $cache_key = 'lb_city_' . $cp;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return (string) $cached;
        }

        $url      = 'https://apicarto.ign.fr/api/codes-postaux/communes/' . rawurlencode( $cp );
        $response = wp_remote_get( $url, [
            'timeout'    => 8,
            'user-agent' => 'LeadBridge/' . LEADBRIDGE_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
            'headers'    => [ 'Accept' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            return 'Non précisée';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $data ) || empty( $data[0] ) ) {
            return 'Non précisée';
        }

        $city = $data[0]['nomCommune'] ?? ( $data[0]['libelleAcheminement'] ?? 'Non précisée' );
        $city = (string) $city;

        // Cache for 7 days
        set_transient( $cache_key, $city, 7 * DAY_IN_SECONDS );

        return $city;
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    /**
     * Returns true if the IP is within the allowed limit; false if rate-limited.
     */
    public static function check_rate_limit( string $ip ): bool {
        $settings = LeadBridge_Config::get_settings();

        if ( empty( $settings['rate_limit_enabled'] ) ) {
            return true;
        }

        $max    = max( 1, (int) ( $settings['rate_limit_max'] ?? 5 ) );
        $window = max( 60, (int) ( $settings['rate_limit_window'] ?? 3600 ) );
        $key    = 'leadbridge_rate_' . md5( $ip );
        $data   = get_transient( $key );

        if ( $data === false ) {
            set_transient( $key, [ 'count' => 1, 'start' => time() ], $window );
            return true;
        }

        if ( (int) $data['count'] >= $max ) {
            return false;
        }

        $data['count']++;
        $remaining = $window - ( time() - (int) $data['start'] );
        set_transient( $key, $data, max( 1, $remaining ) );
        return true;
    }

    // ── IP detection ──────────────────────────────────────────────────────────

    public static function get_client_ip(): string {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ( $candidates as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $header ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                    return $ip;
                }
            }
        }

        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
    }

    // ── Sanitization ──────────────────────────────────────────────────────────

    /**
     * Sanitize all values in a flat string array.
     */
    public static function sanitize_payload( array $payload ): array {
        $clean = [];
        foreach ( $payload as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
            }
            $clean[ sanitize_text_field( $key ) ] = sanitize_text_field( (string) $value );
        }
        return $clean;
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public static function validate_email( string $email ): bool {
        return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
    }

    public static function validate_phone( string $phone ): bool {
        return (bool) preg_match( '/^[\d\s\+\(\)\-\.]{6,20}$/', $phone );
    }

    public static function validate_postal_code( string $cp ): bool {
        return (bool) preg_match( '/^\d{5}$/', $cp );
    }

    // ── Formatting ────────────────────────────────────────────────────────────

    public static function format_bytes( int $bytes ): string {
        if ( $bytes >= 1048576 ) {
            return round( $bytes / 1048576, 2 ) . ' MB';
        }
        if ( $bytes >= 1024 ) {
            return round( $bytes / 1024, 2 ) . ' KB';
        }
        return $bytes . ' B';
    }
}
