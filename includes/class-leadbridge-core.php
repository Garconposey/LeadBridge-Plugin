<?php
/**
 * LeadBridge – Core orchestrator.
 *
 * Hooks into Fluent Forms, maps field data, dispatches to endpoints,
 * handles the WP-Cron retry queue, and sends failure notifications.
 */

defined( 'ABSPATH' ) || exit;

class LeadBridge_Core {

    public function __construct() {
        // Fluent Forms submission hooks (both legacy and current action names)
        add_action( 'fluentform_submission_inserted', [ $this, 'handle_submission' ], 10, 3 );
        add_action( 'fluentform/submission_inserted', [ $this, 'handle_submission' ], 10, 3 );

        // WP-Cron retry queue processor
        add_action( 'leadbridge_process_retry_queue', [ $this, 'process_retry_queue' ] );
    }

    // ── Main submission handler ───────────────────────────────────────────────

    /**
     * Called by Fluent Forms on every successful form submission.
     *
     * @param int|mixed   $entry_id  Submission entry ID.
     * @param array|mixed $form_data Submitted field values, keyed by slug.
     * @param object|mixed $form     Form object (has ->id property).
     */
    public function handle_submission( $entry_id, $form_data, $form ): void {
        $fluent_id = is_object( $form ) ? (int) $form->id : (int) $form;
        $config    = LeadBridge_Config::get_form_by_fluent_id( $fluent_id );

        if ( ! $config ) {
            return; // No LeadBridge config for this form
        }

        // Rate limiting
        $ip = LeadBridge_Utils::get_client_ip();
        if ( ! LeadBridge_Utils::check_rate_limit( $ip ) ) {
            LeadBridge_Logger::log(
                'rate_limit',
                [ 'ip' => $ip, 'form_id' => $fluent_id ],
                [ 'ok' => false, 'code' => 429, 'error' => 'Rate limit exceeded', 'body' => '' ],
                $fluent_id
            );
            return;
        }

        // Extract and sanitize field data
        $fields = $this->extract_fields( $form_data );

        // Dispatch each enabled endpoint
        foreach ( (array) ( $config['endpoints'] ?? [] ) as $endpoint ) {
            if ( empty( $endpoint['enabled'] ) ) {
                continue;
            }
            $this->dispatch_endpoint( $endpoint, $fields, $fluent_id );
        }
    }

    // ── Endpoint dispatcher ───────────────────────────────────────────────────

    private function dispatch_endpoint( array $endpoint, array $fields, int $form_id ): void {
        $type = $endpoint['type'] ?? '';
        $url  = $endpoint['url'] ?? '';

        if ( empty( $url ) ) {
            return;
        }

        switch ( $type ) {
            case 'dashboard':
                $this->send_dashboard( $endpoint, $fields, $form_id );
                break;
            case 'bridge':
                $this->send_bridge( $endpoint, $fields, $form_id );
                break;
            case 'culligan':
                $this->send_culligan( $endpoint, $fields, $form_id );
                break;
        }
    }

    // ── Send: Dashboard Webylead ──────────────────────────────────────────────

    private function send_dashboard( array $endpoint, array $fields, int $form_id ): void {
        $mapping = (array) ( $endpoint['mapping'] ?? [] );
        $fixed   = (array) ( $endpoint['fixed'] ?? [] );
        $url     = $endpoint['url'];
        $payload = [];

        // Map form slugs → API labels
        foreach ( $mapping as $slug => $label ) {
            if ( $label !== '' && isset( $fields[ $slug ] ) ) {
                $payload[ $label ] = sanitize_text_field( $this->flatten( $fields[ $slug ] ) );
            }
        }

        // Inject fixed fields (overwrite if key collides)
        foreach ( $fixed as $key => $value ) {
            $payload[ $key ] = $value;
        }

        $result = LeadBridge_Sender::send( $url, $payload );
        $target = 'dashboard:' . ( $endpoint['label'] ?? $url );

        LeadBridge_Logger::log( $target, $payload, $result, $form_id );

        if ( ! $result['ok'] ) {
            $this->on_failure( $endpoint, $payload, $result, $form_id );
        }
    }

    // ── Send: Applicatif Webylead (Bridge) ────────────────────────────────────

    private function send_bridge( array $endpoint, array $fields, int $form_id ): void {
        $slugs  = array_filter( array_map( 'trim', explode( ',', $endpoint['slugs'] ?? '' ) ) );
        $fixed  = (array) ( $endpoint['fixed'] ?? [] );
        $url    = $endpoint['url'];
        $payload = [];

        // Fixed fields first (formulaire, domaine, etc.)
        foreach ( $fixed as $key => $value ) {
            $payload[ $key ] = $value;
        }

        // Then form slugs (raw, no label translation)
        foreach ( $slugs as $slug ) {
            if ( isset( $fields[ $slug ] ) ) {
                $payload[ $slug ] = sanitize_text_field( $this->flatten( $fields[ $slug ] ) );
            }
        }

        $result = LeadBridge_Sender::send( $url, $payload );
        $target = 'bridge:' . ( $endpoint['label'] ?? $url );

        LeadBridge_Logger::log( $target, $payload, $result, $form_id );

        if ( ! $result['ok'] ) {
            $this->on_failure( $endpoint, $payload, $result, $form_id );
        }
    }

    // ── Send: Culligan / Pardot ───────────────────────────────────────────────

    private function send_culligan( array $endpoint, array $fields, int $form_id ): void {
        $field_map    = (array) ( $endpoint['fields'] ?? [] );
        $fixed        = (array) ( $endpoint['fixed'] ?? [] );
        $url          = $endpoint['url'];
        $question_tpl = $endpoint['question_tpl']
            ?? 'Demande via partenaire. Effectif: %s, Visiteurs/jour: %s, Délai: %s.';

        // Helper: get field value by semantic role
        $get = function ( string $role ) use ( $field_map, $fields ): string {
            // Find which slug maps to this role
            $slug = array_search( $role, $field_map, true );
            if ( $slug === false ) {
                $slug = $role; // fallback: try slug == role
            }
            return sanitize_text_field( $this->flatten( $fields[ $slug ] ?? '' ) );
        };

        $nom       = $get( 'nom' );
        $prenom    = $get( 'prenom' );
        $societe   = $get( 'societe' );
        $email     = sanitize_email( $get( 'email' ) );
        $cp        = $get( 'cp' );
        $telephone = $get( 'telephone' );
        $salaries  = $get( 'salaries' );
        $visiteurs = $get( 'visiteurs' );
        $delai     = $get( 'delai' );

        // Resolve city via IGN API
        $ville = LeadBridge_Utils::resolve_city( $cp );

        // Build question summary
        $question = sprintf(
            $question_tpl,
            $salaries  ?: 'Non précisé',
            $visiteurs ?: 'Non précisé',
            $delai     ?: 'Non précisé'
        );

        $payload = [
            'salutation'  => 'Non précisé',
            'lastname'    => trim( ( $prenom ? $prenom . ' ' : '' ) . $nom ),
            'company'     => $societe,
            'email'       => $email,
            'postal_code' => $cp,
            'city'        => $ville,
            'phone'       => $telephone,
            'question'    => $question,
        ];

        // Inject fixed UTM + other fields
        foreach ( $fixed as $key => $value ) {
            $payload[ $key ] = $value;
        }

        $result = LeadBridge_Sender::send( $url, $payload, true ); // with Referer
        $target = 'culligan:' . ( $endpoint['label'] ?? $url );

        LeadBridge_Logger::log( $target, $payload, $result, $form_id );

        if ( ! $result['ok'] ) {
            $this->on_failure( $endpoint, $payload, $result, $form_id );
        }
    }

    // ── Failure handling ──────────────────────────────────────────────────────

    /**
     * Triggered when an endpoint returns a non-2xx response.
     * Queues the item for retry and optionally sends an admin email.
     */
    private function on_failure( array $endpoint, array $payload, array $result, int $form_id ): void {
        $settings = LeadBridge_Config::get_settings();

        // Add to retry queue
        if ( (int) ( $settings['retry_max'] ?? 3 ) > 0 ) {
            $this->enqueue_retry( $endpoint, $payload, $form_id, $result['error'] ?? '' );
        }

        // Send email notification (first failure only, before retries)
        if ( ! empty( $settings['notify_on_failure'] ) ) {
            $email = $settings['notify_email'] ?? '';
            if ( empty( $email ) ) {
                $email = get_option( 'admin_email' );
            }
            $this->send_failure_email( $email, $endpoint, $result, $form_id );
        }
    }

    // ── Retry queue ───────────────────────────────────────────────────────────

    private function enqueue_retry( array $endpoint, array $payload, int $form_id, string $error ): void {
        $settings = LeadBridge_Config::get_settings();
        $delay    = max( 60, (int) ( $settings['retry_delay'] ?? 900 ) );

        $queue   = get_option( LEADBRIDGE_QUEUE, [] );
        $queue[] = [
            'id'           => LeadBridge_Config::generate_id(),
            'form_id'      => $form_id,
            'endpoint_id'  => $endpoint['id'] ?? '',
            'endpoint_url' => $endpoint['url'] ?? '',
            'endpoint_type'=> $endpoint['type'] ?? '',
            'endpoint_label'=> $endpoint['label'] ?? '',
            'payload'      => $payload,
            'attempts'     => 0,
            'max_attempts' => max( 1, (int) ( $settings['retry_max'] ?? 3 ) ),
            'next_attempt' => time() + $delay,
            'errors'       => [ date( 'c' ) . ' | ' . $error ],
            'created_at'   => time(),
        ];

        update_option( LEADBRIDGE_QUEUE, $queue, false );
    }

    /**
     * WP-Cron callback – processes all items due for retry.
     */
    public function process_retry_queue(): void {
        $queue    = get_option( LEADBRIDGE_QUEUE, [] );
        $settings = LeadBridge_Config::get_settings();
        $delay    = max( 60, (int) ( $settings['retry_delay'] ?? 900 ) );
        $updated  = [];

        foreach ( $queue as $item ) {
            if ( (int) $item['next_attempt'] > time() ) {
                $updated[] = $item; // not yet
                continue;
            }

            if ( (int) $item['attempts'] >= (int) $item['max_attempts'] ) {
                // Max retries reached – log permanent failure
                LeadBridge_Logger::log(
                    'retry_failed:' . ( $item['endpoint_label'] ?? $item['endpoint_url'] ),
                    $item['payload'],
                    [ 'ok' => false, 'code' => 0, 'error' => 'Max retries reached', 'body' => '' ],
                    (int) $item['form_id']
                );

                // Final failure email
                if ( ! empty( $settings['notify_on_failure'] ) ) {
                    $email = $settings['notify_email'] ?: get_option( 'admin_email' );
                    $this->send_final_failure_email( $email, $item );
                }

                continue; // drop from queue
            }

            // Attempt the send
            $result = LeadBridge_Sender::send( $item['endpoint_url'], $item['payload'] );
            $item['attempts']++;

            if ( $result['ok'] ) {
                LeadBridge_Logger::log(
                    'retry_ok:' . ( $item['endpoint_label'] ?? $item['endpoint_url'] ),
                    $item['payload'],
                    $result,
                    (int) $item['form_id']
                );
                // Drop from queue on success
            } else {
                $item['errors'][]    = date( 'c' ) . ' (tentative ' . $item['attempts'] . ') | ' . ( $result['error'] ?? 'HTTP ' . $result['code'] );
                $item['next_attempt'] = time() + $delay;
                $updated[]           = $item;
            }
        }

        update_option( LEADBRIDGE_QUEUE, array_values( $updated ), false );
    }

    // ── Email notifications ───────────────────────────────────────────────────

    private function send_failure_email( string $to, array $endpoint, array $result, int $form_id ): void {
        if ( ! is_email( $to ) ) {
            return;
        }

        $subject = sprintf(
            '[LeadBridge] Échec d\'envoi – %s (Formulaire #%d)',
            $endpoint['label'] ?? $endpoint['url'] ?? 'inconnu',
            $form_id
        );

        $body  = "Un envoi LeadBridge a échoué.\n\n";
        $body .= 'Endpoint : ' . ( $endpoint['label'] ?? '' ) . "\n";
        $body .= 'URL      : ' . ( $endpoint['url'] ?? '' ) . "\n";
        $body .= 'Code HTTP: ' . ( $result['code'] ?? 0 ) . "\n";
        $body .= 'Erreur   : ' . ( $result['error'] ?? '' ) . "\n";
        $body .= 'Réponse  : ' . ( $result['body'] ?? '' ) . "\n\n";
        $body .= 'Une nouvelle tentative automatique sera effectuée sous peu.' . "\n\n";
        $body .= 'Voir les logs : ' . admin_url( 'admin.php?page=leadbridge-logs' );

        wp_mail( $to, $subject, $body );
    }

    private function send_final_failure_email( string $to, array $item ): void {
        if ( ! is_email( $to ) ) {
            return;
        }

        $subject = sprintf(
            '[LeadBridge] Échec définitif – %s (Formulaire #%d)',
            $item['endpoint_label'] ?? $item['endpoint_url'] ?? 'inconnu',
            $item['form_id']
        );

        $body  = "Un lead n'a pas pu être envoyé après " . $item['max_attempts'] . " tentatives.\n\n";
        $body .= 'Endpoint : ' . ( $item['endpoint_label'] ?? '' ) . "\n";
        $body .= 'URL      : ' . ( $item['endpoint_url'] ?? '' ) . "\n";
        $body .= 'Historique des erreurs :' . "\n";
        foreach ( $item['errors'] as $err ) {
            $body .= '  - ' . $err . "\n";
        }
        $body .= "\nVérifiez la configuration ou contactez l'administrateur système.\n";
        $body .= 'Voir les logs : ' . admin_url( 'admin.php?page=leadbridge-logs' );

        wp_mail( $to, $subject, $body );
    }

    // ── Field extraction helpers ──────────────────────────────────────────────

    /**
     * Normalize Fluent Forms data (handles both array and object structures).
     */
    private function extract_fields( $form_data ): array {
        if ( is_object( $form_data ) ) {
            $form_data = (array) $form_data;
        }

        if ( ! is_array( $form_data ) ) {
            return [];
        }

        // Fluent Forms sometimes wraps data in a 'data' key
        if ( isset( $form_data['data'] ) && is_array( $form_data['data'] ) ) {
            $form_data = $form_data['data'];
        }

        // Strip internal Fluent Forms keys (start with __)
        return array_filter(
            array_map( fn( $v ) => is_array( $v ) ? $v : (string) $v, $form_data ),
            fn( $k ) => strpos( (string) $k, '__' ) !== 0,
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Flatten a value that might be an array (e.g. checkbox multi-select).
     */
    private function flatten( $value ): string {
        if ( is_array( $value ) ) {
            return implode( ', ', array_map( 'strval', $value ) );
        }
        return (string) $value;
    }
}
