<?php
/**
 * LeadBridge – Configuration manager.
 * Reads/writes the plugin config from the WP options table.
 */

defined( 'ABSPATH' ) || exit;

class LeadBridge_Config {

    /** @var array|null In-memory cache */
    private static ?array $cache = null;

    // ── Public read API ───────────────────────────────────────────────────────

    public static function get(): array {
        if ( self::$cache === null ) {
            $raw         = get_option( LEADBRIDGE_OPTION, [] );
            self::$cache = self::parse( is_array( $raw ) ? $raw : [] );
        }
        return self::$cache;
    }

    public static function get_all_forms(): array {
        return self::get()['forms'] ?? [];
    }

    /** Find a form config by Fluent Forms form ID (runtime lookup). */
    public static function get_form_by_fluent_id( int $fluent_id ): ?array {
        foreach ( self::get_all_forms() as $form ) {
            if ( (int) ( $form['form_id'] ?? 0 ) === $fluent_id && ! empty( $form['enabled'] ) ) {
                return $form;
            }
        }
        return null;
    }

    /** Find a form config by its internal UUID. */
    public static function get_form_by_id( string $id ): ?array {
        foreach ( self::get_all_forms() as $form ) {
            if ( ( $form['id'] ?? '' ) === $id ) {
                return $form;
            }
        }
        return null;
    }

    public static function get_settings(): array {
        return wp_parse_args( self::get()['settings'] ?? [], self::default_settings() );
    }

    // ── Public write API ──────────────────────────────────────────────────────

    public static function save( array $config ): bool {
        self::$cache = null;
        return (bool) update_option( LEADBRIDGE_OPTION, $config, false );
    }

    /** Upsert a single form config (identified by its 'id'). */
    public static function save_form( array $form ): bool {
        $config = self::get();
        $forms  = $config['forms'] ?? [];
        $found  = false;

        foreach ( $forms as &$existing ) {
            if ( ( $existing['id'] ?? '' ) === $form['id'] ) {
                $existing = $form;
                $found    = true;
                break;
            }
        }
        unset( $existing );

        if ( ! $found ) {
            $forms[] = $form;
        }

        $config['forms'] = $forms;
        self::$cache     = null;
        return self::save( $config );
    }

    public static function delete_form( string $id ): bool {
        $config          = self::get();
        $config['forms'] = array_values(
            array_filter( $config['forms'] ?? [], fn( $f ) => ( $f['id'] ?? '' ) !== $id )
        );
        self::$cache = null;
        return self::save( $config );
    }

    public static function save_settings( array $settings ): bool {
        $config             = self::get();
        $config['settings'] = self::sanitize_settings( $settings );
        self::$cache        = null;
        return self::save( $config );
    }

    // ── Defaults ──────────────────────────────────────────────────────────────

    public static function default_config(): array {
        return [
            'forms'    => [],
            'settings' => self::default_settings(),
        ];
    }

    public static function default_settings(): array {
        return [
            'rate_limit_enabled' => true,
            'rate_limit_max'     => 5,
            'rate_limit_window'  => 3600,
            'notify_on_failure'  => false,
            'notify_email'       => '',
            'retry_max'          => 3,
            'retry_delay'        => 900, // seconds between attempts
        ];
    }

    public static function generate_id(): string {
        return wp_generate_uuid4();
    }

    // ── Quick-Start Templates ──────────────────────────────────────────────────

    /**
     * Returns the 6 pre-configured site templates.
     * URLs are intentionally left blank for the user to fill in.
     */
    public static function get_templates(): array {
        return [
            'fontaine' => [
                'name'      => 'fontaine-a-eau.com',
                'endpoints' => [
                    [
                        'type'    => 'dashboard',
                        'label'   => 'Dashboard Webylead',
                        'url'     => '',
                        'enabled' => true,
                        'mapping' => [
                            'salaries'  => 'Nombre de salariés',
                            'visiteurs' => 'Visiteurs par jour',
                            'delai'     => 'Délai',
                            'nom'       => 'Nom',
                            'prenom'    => 'Prénom',
                            'telephone' => 'Téléphone',
                            'email'     => 'Email',
                            'societe'   => 'Société',
                            'cp'        => 'CP',
                        ],
                        'fixed'   => [],
                    ],
                    [
                        'type'         => 'culligan',
                        'label'        => 'Culligan / Pardot',
                        'url'          => '',
                        'enabled'      => true,
                        'fields'       => [
                            'nom'       => 'nom',
                            'prenom'    => 'prenom',
                            'email'     => 'email',
                            'telephone' => 'telephone',
                            'societe'   => 'societe',
                            'cp'        => 'cp',
                            'salaries'  => 'salaries',
                            'visiteurs' => 'visiteurs',
                            'delai'     => 'delai',
                        ],
                        'question_tpl' => 'Demande via partenaire. Effectif: %s, Visiteurs/jour: %s, Délai: %s.',
                        'fixed'        => [
                            'utm_campaign' => 'ALB',
                            'utm_source'   => 'Affiliation',
                            'utm_medium'   => 'albFON',
                            'utm_adgroup'  => 'Fontaine',
                            'utm_term'     => 'ctatxt',
                        ],
                    ],
                ],
            ],

            'distributeur' => [
                'name'      => 'distributeurautomatique.net',
                'endpoints' => [
                    [
                        'type'    => 'dashboard',
                        'label'   => 'Dashboard Webylead',
                        'url'     => '',
                        'enabled' => true,
                        'mapping' => [
                            'salaries'  => 'Nombre de salariés',
                            'visiteurs' => 'Nombre de visiteurs',
                            'locaux'    => 'Type de locaux',
                            'nom'       => 'Nom',
                            'prenom'    => 'Prénom',
                            'telephone' => 'Téléphone',
                            'email'     => 'Email',
                            'societe'   => 'Société',
                            'cp'        => 'CP',
                        ],
                        'fixed'   => [],
                    ],
                    [
                        'type'    => 'bridge',
                        'label'   => 'Applicatif Webylead',
                        'url'     => '',
                        'enabled' => true,
                        'slugs'   => 'nom,prenom,cp,email,telephone,societe,salaries,visiteurs,locaux',
                        'fixed'   => [
                            'formulaire' => 'devis-distributeur',
                            'domaine'    => 'distributeurautomatique.net',
                        ],
                    ],
                ],
            ],

            'photocopieuse' => [
                'name'      => 'photocopieuse.net',
                'endpoints' => [
                    [
                        'type'    => 'dashboard',
                        'label'   => 'Dashboard Webylead',
                        'url'     => '',
                        'enabled' => true,
                        'mapping' => [
                            'impressions' => "Volume d'impressions",
                            'format'      => "Format d'impression",
                            'effectif'    => 'Effectif',
                            'postes'      => 'Postes informatiques',
                            'nom'         => 'Nom',
                            'prenom'      => 'Prénom',
                            'telephone'   => 'Téléphone',
                            'email'       => 'Email',
                            'societe'     => 'Société',
                            'cp'          => 'CP',
                        ],
                        'fixed'   => [],
                    ],
                ],
            ],

            'autolaveuse' => [
                'name'      => 'autolaveuse.fr',
                'endpoints' => [
                    [
                        'type'    => 'dashboard',
                        'label'   => 'Dashboard Webylead',
                        'url'     => '',
                        'enabled' => true,
                        'mapping' => [
                            'type'       => 'Type de machine',
                            'superficie' => 'Superficie des locaux',
                            'secteur'    => "Secteur d'activité",
                            'nom'        => 'Nom',
                            'prenom'     => 'Prénom',
                            'telephone'  => 'Téléphone',
                            'email'      => 'Email',
                            'societe'    => 'Société',
                            'cp'         => 'CP',
                        ],
                        'fixed'   => [],
                    ],
                ],
            ],

            'telesurveillance' => [
                'name'      => 'telesurveillance.eu',
                'endpoints' => [
                    [
                        'type'    => 'dashboard',
                        'label'   => 'Dashboard Webylead',
                        'url'     => '',
                        'enabled' => true,
                        'mapping' => [
                            'locaux'       => 'Type de locaux',
                            'cameras'      => 'Nombre de caméras',
                            'surface'      => 'Surface à sécuriser',
                            'installation' => "Type d'installation",
                            'creation'     => 'Création de société',
                            'nom'          => 'Nom',
                            'prenom'       => 'Prénom',
                            'societe'      => 'Nom de societe',
                            'telephone'    => 'Téléphone',
                            'email'        => 'Email',
                            'cp'           => 'Code postal',
                        ],
                        'fixed'   => [],
                    ],
                    [
                        'type'    => 'bridge',
                        'label'   => 'Applicatif Webylead',
                        'url'     => '',
                        'enabled' => true,
                        'slugs'   => 'locaux,cameras,surface,installation,creation,cp,nom,prenom,societe,email,telephone',
                        'fixed'   => [
                            'formulaire' => 'devis-telesurveillance',
                            'domaine'    => 'telesurveillance.eu',
                        ],
                    ],
                ],
            ],

            'nettoyage' => [
                'name'      => 'entreprisenettoyage.net',
                'endpoints' => [
                    [
                        'type'    => 'bridge',
                        'label'   => 'Applicatif Webylead',
                        'url'     => '',
                        'enabled' => true,
                        'slugs'   => 'prestation,frequence,surface,nom,telephone,cp,email,fonction,entreprise',
                        'fixed'   => [
                            'formulaire' => 'devis-nettoyage',
                            'domaine'    => 'entreprisenettoyage.net',
                        ],
                    ],
                ],
            ],
        ];
    }

    // ── Sanitization helpers ──────────────────────────────────────────────────

    private static function parse( array $raw ): array {
        return wp_parse_args( $raw, self::default_config() );
    }

    private static function sanitize_settings( array $s ): array {
        return [
            'rate_limit_enabled' => ! empty( $s['rate_limit_enabled'] ),
            'rate_limit_max'     => max( 1, (int) ( $s['rate_limit_max'] ?? 5 ) ),
            'rate_limit_window'  => max( 60, (int) ( $s['rate_limit_window'] ?? 3600 ) ),
            'notify_on_failure'  => ! empty( $s['notify_on_failure'] ),
            'notify_email'       => sanitize_email( $s['notify_email'] ?? '' ),
            'retry_max'          => min( 10, max( 0, (int) ( $s['retry_max'] ?? 3 ) ) ),
            'retry_delay'        => max( 60, (int) ( $s['retry_delay'] ?? 900 ) ),
        ];
    }

    /**
     * Build a clean form array from raw POST data (admin form save).
     */
    public static function sanitize_form_from_post( array $raw, string $id = '' ): array {
        $form = [
            'id'        => $id ?: self::generate_id(),
            'name'      => sanitize_text_field( $raw['name'] ?? '' ),
            'form_id'   => max( 1, (int) ( $raw['form_id'] ?? 0 ) ),
            'enabled'   => ! empty( $raw['enabled'] ),
            'endpoints' => [],
        ];

        foreach ( (array) ( $raw['endpoints'] ?? [] ) as $ep_raw ) {
            $ep = self::sanitize_endpoint( $ep_raw );
            if ( $ep ) {
                $form['endpoints'][] = $ep;
            }
        }

        return $form;
    }

    private static function sanitize_endpoint( array $raw ): ?array {
        $type = sanitize_key( $raw['type'] ?? '' );
        if ( ! in_array( $type, [ 'dashboard', 'bridge', 'culligan' ], true ) ) {
            return null;
        }

        $ep = [
            'id'      => sanitize_text_field( $raw['id'] ?? self::generate_id() ),
            'type'    => $type,
            'label'   => sanitize_text_field( $raw['label'] ?? '' ),
            'url'     => esc_url_raw( $raw['url'] ?? '' ),
            'enabled' => ! empty( $raw['enabled'] ),
            'fixed'   => [],
        ];

        // Fixed fields (key => value pairs)
        foreach ( (array) ( $raw['fixed_rows'] ?? [] ) as $row ) {
            $key   = sanitize_text_field( $row['key'] ?? '' );
            $value = sanitize_text_field( $row['value'] ?? '' );
            if ( $key !== '' ) {
                $ep['fixed'][ $key ] = $value;
            }
        }

        switch ( $type ) {
            case 'dashboard':
                $ep['mapping'] = [];
                foreach ( (array) ( $raw['mapping_rows'] ?? [] ) as $row ) {
                    $slug  = sanitize_key( $row['slug'] ?? '' );
                    $label = sanitize_text_field( $row['label'] ?? '' );
                    if ( $slug !== '' ) {
                        $ep['mapping'][ $slug ] = $label;
                    }
                }
                break;

            case 'bridge':
                $ep['slugs'] = sanitize_text_field( $raw['slugs'] ?? '' );
                break;

            case 'culligan':
                $ep['fields'] = [];
                foreach ( (array) ( $raw['culligan_rows'] ?? [] ) as $row ) {
                    $slug = sanitize_key( $row['slug'] ?? '' );
                    $role = sanitize_key( $row['role'] ?? '' );
                    if ( $slug !== '' && $role !== '' ) {
                        $ep['fields'][ $slug ] = $role;
                    }
                }
                $ep['question_tpl'] = sanitize_text_field(
                    $raw['question_tpl'] ?? 'Demande via partenaire. Effectif: %s, Visiteurs/jour: %s, Délai: %s.'
                );
                break;
        }

        return $ep;
    }
}
