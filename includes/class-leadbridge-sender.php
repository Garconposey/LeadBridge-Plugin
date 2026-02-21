<?php
/**
 * LeadBridge – HTTP transport layer.
 * All outbound requests go through this class using wp_remote_post().
 */

defined( 'ABSPATH' ) || exit;

class LeadBridge_Sender {

    /**
     * POST an application/x-www-form-urlencoded payload to a URL.
     *
     * @param string $url          Target endpoint URL.
     * @param array  $payload      Key-value pairs to send.
     * @param bool   $with_referer Whether to include the site's home URL as Referer.
     *
     * @return array {
     *   bool   $ok      True if HTTP 2xx.
     *   int    $code    HTTP response code (0 on connection error).
     *   string $error   WP_Error message, or null on success.
     *   string $body    First 300 chars of response body (stripped).
     * }
     */
    public static function send( string $url, array $payload, bool $with_referer = false ): array {
        if ( empty( $url ) ) {
            return [
                'ok'    => false,
                'code'  => 0,
                'error' => 'URL vide',
                'body'  => '',
            ];
        }

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'User-Agent'   => 'LeadBridge/' . LEADBRIDGE_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
        ];

        if ( $with_referer ) {
            $headers['Referer'] = home_url( '/' );
        }

        $args = [
            'body'        => http_build_query( $payload, '', '&', PHP_QUERY_RFC3986 ),
            'timeout'     => 15,
            'redirection' => 5,
            'headers'     => $headers,
            'sslverify'   => true,
        ];

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [
                'ok'    => false,
                'code'  => 0,
                'error' => $response->get_error_message(),
                'body'  => '',
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        return [
            'ok'    => ( $code >= 200 && $code < 300 ),
            'code'  => $code,
            'error' => null,
            'body'  => self::preview( $body ),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns a cleaned 300-char preview of a response body.
     */
    private static function preview( string $body ): string {
        $str = wp_strip_all_tags( $body );
        $str = (string) preg_replace( '/\s+/', ' ', trim( $str ) );
        return mb_strlen( $str ) > 300 ? mb_substr( $str, 0, 300 ) . '…' : $str;
    }
}
