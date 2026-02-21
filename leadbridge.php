<?php
/**
 * Plugin Name:       LeadBridge
 * Plugin URI:        https://foxt-seo.com/
 * Description:       Orchestrateur de leads multi-endpoints pour Fluent Forms. Configurez et gérez vos envois de leads vers Dashboard Webylead, Webylead App et Pardot depuis une interface centralisée.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            FOXT SEO
 * Author URI:        https://foxt-seo.com/
 * License:           GPL v2 or later
 * Text Domain:       leadbridge
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'LEADBRIDGE_VERSION', '1.0.0' );
define( 'LEADBRIDGE_FILE',    __FILE__ );
define( 'LEADBRIDGE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'LEADBRIDGE_URL',     plugin_dir_url( __FILE__ ) );
define( 'LEADBRIDGE_OPTION',  'leadbridge_config' );
define( 'LEADBRIDGE_QUEUE',   'leadbridge_retry_queue' );

// ── Autoload classes ──────────────────────────────────────────────────────────
foreach ( [
    'class-leadbridge-config',
    'class-leadbridge-logger',
    'class-leadbridge-utils',
    'class-leadbridge-sender',
    'class-leadbridge-core',
    'class-leadbridge-admin',
] as $file ) {
    require_once LEADBRIDGE_DIR . 'includes/' . $file . '.php';
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'leadbridge_init', 10 );

function leadbridge_init(): void {
    // Admin notice if Fluent Forms is not installed
    if ( ! class_exists( 'FluentForm\App\Modules\Form\Form' )
        && ! function_exists( 'wpFluentForm' )
        && ! defined( 'FLUENTFORM' ) ) {
        add_action( 'admin_notices', function () {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . '<strong>LeadBridge</strong> : '
                . esc_html__( 'Le plugin Fluent Forms est requis pour intercepter les soumissions de formulaires.', 'leadbridge' )
                . '</p></div>';
        } );
    }

    new LeadBridge_Core();

    if ( is_admin() ) {
        new LeadBridge_Admin();
    }
}

// ── Activation ────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    // Create protected log directory
    $upload  = wp_upload_dir();
    $log_dir = $upload['basedir'] . '/leadbridge-logs';

    if ( ! is_dir( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
    }

    // Protect from direct browser access
    if ( ! file_exists( $log_dir . '/.htaccess' ) ) {
        file_put_contents( $log_dir . '/.htaccess', "Deny from all\nOptions -Indexes\n" );
    }
    if ( ! file_exists( $log_dir . '/index.php' ) ) {
        file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden' );
    }

    // Initialize config option if absent
    if ( ! get_option( LEADBRIDGE_OPTION ) ) {
        update_option( LEADBRIDGE_OPTION, LeadBridge_Config::default_config(), false );
    }

    // Initialize empty retry queue
    if ( false === get_option( LEADBRIDGE_QUEUE ) ) {
        update_option( LEADBRIDGE_QUEUE, [], false );
    }

    // Schedule WP-Cron retry job
    if ( ! wp_next_scheduled( 'leadbridge_process_retry_queue' ) ) {
        wp_schedule_event( time(), 'leadbridge_15min', 'leadbridge_process_retry_queue' );
    }
} );

// ── Deactivation ──────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, function () {
    // Unschedule cron job
    $timestamp = wp_next_scheduled( 'leadbridge_process_retry_queue' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'leadbridge_process_retry_queue' );
    }

    // Clear rate-limit transients
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_leadbridge_rate_%',
            '_transient_timeout_leadbridge_rate_%'
        )
    );
} );

// ── Custom Cron Schedule ──────────────────────────────────────────────────────
add_filter( 'cron_schedules', function ( array $schedules ): array {
    if ( ! isset( $schedules['leadbridge_15min'] ) ) {
        $schedules['leadbridge_15min'] = [
            'interval' => 900,
            'display'  => __( 'Toutes les 15 minutes (LeadBridge)', 'leadbridge' ),
        ];
    }
    return $schedules;
} );
