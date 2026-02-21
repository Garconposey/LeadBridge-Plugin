<?php
/**
 * LeadBridge – JSONL Logger.
 * Writes one JSON record per line to a protected log file.
 */

defined( 'ABSPATH' ) || exit;

class LeadBridge_Logger {

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * @param string $target  Endpoint label / type identifier.
     * @param array  $payload Data sent (will be masked before logging).
     * @param array  $result  Result from LeadBridge_Sender::send().
     * @param int    $form_id Fluent Forms form ID (optional).
     */
    public static function log(
        string $target,
        array $payload,
        array $result,
        int $form_id = 0
    ): void {
        $record = [
            'ts'      => current_time( 'c' ),
            'target'  => $target,
            'form_id' => $form_id,
            'ok'      => (bool) ( $result['ok'] ?? false ),
            'code'    => (int) ( $result['code'] ?? 0 ),
            'error'   => $result['error'] ?? null,
            'payload' => self::mask( $payload ),
            'preview' => $result['body'] ?? '',
        ];

        $line = json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

        @file_put_contents(
            self::log_file(),
            $line,
            FILE_APPEND | LOCK_EX
        );

        // Increment failure counter (for admin badge)
        if ( ! $record['ok'] ) {
            self::increment_failure_count();
        }
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Returns the last $limit log entries in reverse-chronological order.
     *
     * @param int         $limit   Maximum number of entries to return.
     * @param string|null $filter  'ok', 'fail', or null for all.
     */
    public static function get_entries( int $limit = 200, ?string $filter = null ): array {
        $file = self::log_file();
        if ( ! file_exists( $file ) ) {
            return [];
        }

        $lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! $lines ) {
            return [];
        }

        // Read from the end
        $lines   = array_reverse( $lines );
        $entries = [];

        foreach ( $lines as $line ) {
            $entry = json_decode( $line, true );
            if ( ! is_array( $entry ) ) {
                continue;
            }

            if ( $filter === 'ok'   && ! $entry['ok'] ) continue;
            if ( $filter === 'fail' && $entry['ok'] )  continue;

            $entries[] = $entry;
            if ( count( $entries ) >= $limit ) {
                break;
            }
        }

        return $entries;
    }

    public static function get_log_path(): string {
        return self::log_file();
    }

    public static function get_log_size(): int {
        $file = self::log_file();
        return file_exists( $file ) ? (int) filesize( $file ) : 0;
    }

    public static function get_log_line_count(): int {
        $file = self::log_file();
        if ( ! file_exists( $file ) ) {
            return 0;
        }
        $count = 0;
        $fh    = fopen( $file, 'r' );
        while ( ! feof( $fh ) ) {
            $line = fgets( $fh );
            if ( $line !== false && trim( $line ) !== '' ) {
                $count++;
            }
        }
        fclose( $fh );
        return $count;
    }

    public static function clear_log(): bool {
        self::reset_failure_count();
        return (bool) @file_put_contents( self::log_file(), '' );
    }

    // ── Failure counter (for admin menu badge) ────────────────────────────────

    public static function get_failure_count(): int {
        return (int) get_option( 'leadbridge_failure_count', 0 );
    }

    public static function reset_failure_count(): void {
        update_option( 'leadbridge_failure_count', 0, false );
    }

    private static function increment_failure_count(): void {
        $count = self::get_failure_count();
        update_option( 'leadbridge_failure_count', $count + 1, false );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function log_file(): string {
        $upload = wp_upload_dir();
        return $upload['basedir'] . '/leadbridge-logs/leadbridge.log';
    }

    /**
     * Mask sensitive fields for privacy before writing to disk.
     */
    private static function mask( array $payload ): array {
        $sensitive_keys = [
            'email', 'Email',
            'telephone', 'Téléphone', 'phone',
            'lastname', 'Nom', 'nom', 'prenom', 'Prénom',
        ];

        foreach ( $sensitive_keys as $key ) {
            if ( isset( $payload[ $key ] ) ) {
                $val            = (string) $payload[ $key ];
                $len            = mb_strlen( $val );
                $payload[ $key ] = $len > 4
                    ? mb_substr( $val, 0, 3 ) . str_repeat( '*', max( 1, $len - 3 ) )
                    : str_repeat( '*', $len );
            }
        }

        return $payload;
    }
}
