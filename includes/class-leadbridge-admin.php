<?php
/**
 * LeadBridge – Admin interface.
 *
 * Pages:
 *  - leadbridge            → Dashboard / form list
 *  - leadbridge-edit       → Add / edit a form config
 *  - leadbridge-logs       → Log viewer + retry queue
 *  - leadbridge-settings   → Global settings
 */

defined( 'ABSPATH' ) || exit;

class LeadBridge_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'handle_post_actions' ] );
        add_action( 'wp_ajax_lb_get_logs',        [ $this, 'ajax_get_logs' ] );
        add_action( 'wp_ajax_lb_clear_logs',      [ $this, 'ajax_clear_logs' ] );
        add_action( 'wp_ajax_lb_test_endpoint',   [ $this, 'ajax_test_endpoint' ] );
        add_action( 'wp_ajax_lb_retry_item',      [ $this, 'ajax_retry_item' ] );
        add_action( 'wp_ajax_lb_dismiss_item',    [ $this, 'ajax_dismiss_item' ] );
        add_action( 'wp_ajax_lb_load_template',   [ $this, 'ajax_load_template' ] );
        add_action( 'wp_ajax_lb_run_cron',        [ $this, 'ajax_run_cron' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        $failure_count = LeadBridge_Logger::get_failure_count();
        $badge         = $failure_count > 0
            ? ' <span class="awaiting-mod">' . number_format_i18n( $failure_count ) . '</span>'
            : '';

        add_menu_page(
            'LeadBridge',
            'LeadBridge',
            'manage_options',
            'leadbridge',
            [ $this, 'page_dashboard' ],
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 2a8 8 0 100 16A8 8 0 0010 2zm0 3l3 5H7l3-5zm0 9a1.5 1.5 0 110-3 1.5 1.5 0 010 3z"/></svg>' ),
            58
        );

        add_submenu_page( 'leadbridge', 'Formulaires',  'Formulaires',           'manage_options', 'leadbridge',        [ $this, 'page_dashboard' ] );
        add_submenu_page( 'leadbridge', 'Ajouter',      'Ajouter un formulaire', 'manage_options', 'leadbridge-edit',   [ $this, 'page_edit' ] );
        add_submenu_page( 'leadbridge', 'Logs',         'Logs' . $badge,         'manage_options', 'leadbridge-logs',   [ $this, 'page_logs' ] );
        add_submenu_page( 'leadbridge', 'Paramètres',   'Paramètres',            'manage_options', 'leadbridge-settings', [ $this, 'page_settings' ] );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        $lb_hooks = [
            'toplevel_page_leadbridge',
            'leadbridge_page_leadbridge-edit',
            'leadbridge_page_leadbridge-logs',
            'leadbridge_page_leadbridge-settings',
        ];

        if ( ! in_array( $hook, $lb_hooks, true ) ) {
            return;
        }

        wp_enqueue_style(
            'leadbridge-admin',
            LEADBRIDGE_URL . 'assets/css/admin.css',
            [],
            LEADBRIDGE_VERSION
        );

        wp_enqueue_script(
            'leadbridge-admin',
            LEADBRIDGE_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            LEADBRIDGE_VERSION,
            true
        );

        wp_localize_script( 'leadbridge-admin', 'LeadBridge', [
            'ajaxurl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'leadbridge_ajax' ),
            'templates'  => LeadBridge_Config::get_templates(),
            'strings'    => [
                'confirmDelete'  => 'Supprimer ce formulaire ? Cette action est irréversible.',
                'confirmClear'   => 'Vider le fichier de log ? Cette action est irréversible.',
                'confirmDismiss' => 'Retirer cet élément de la file d\'attente ?',
                'confirmRetry'   => 'Relancer manuellement cet envoi ?',
                'testSending'    => 'Envoi du test en cours…',
                'testOk'         => '✓ Succès',
                'testFail'       => '✗ Échec',
                'noUrl'          => 'Saisissez l\'URL de l\'endpoint avant de tester.',
                'saved'          => 'Sauvegardé.',
                'loadTplConfirm' => 'Charger ce template ? Les champs actuels seront remplacés.',
            ],
        ] );
    }

    // ── POST action router ────────────────────────────────────────────────────

    public function handle_post_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = sanitize_key( $_POST['lb_action'] ?? $_GET['lb_action'] ?? '' );

        switch ( $action ) {
            case 'save_form':
                $this->handle_save_form();
                break;
            case 'delete_form':
                $this->handle_delete_form();
                break;
            case 'save_settings':
                $this->handle_save_settings();
                break;
        }
    }

    private function handle_save_form(): void {
        check_admin_referer( 'lb_save_form' );

        $raw  = $_POST['lb_form'] ?? [];
        $id   = sanitize_text_field( $_POST['lb_form_id'] ?? '' );
        $form = LeadBridge_Config::sanitize_form_from_post( $raw, $id );

        if ( empty( $form['name'] ) ) {
            $this->redirect_edit( $id, 'error', 'Le nom du formulaire est requis.' );
            return;
        }
        if ( $form['form_id'] < 1 ) {
            $this->redirect_edit( $id, 'error', 'L\'ID Fluent Forms doit être ≥ 1.' );
            return;
        }

        LeadBridge_Config::save_form( $form );
        $this->redirect_edit( $form['id'], 'success', 'Configuration sauvegardée.' );
    }

    private function handle_delete_form(): void {
        check_admin_referer( 'lb_delete_form' );
        $id = sanitize_text_field( $_POST['lb_form_id'] ?? '' );
        LeadBridge_Config::delete_form( $id );
        wp_safe_redirect( admin_url( 'admin.php?page=leadbridge&lb_notice=deleted' ) );
        exit;
    }

    private function handle_save_settings(): void {
        check_admin_referer( 'lb_save_settings' );
        $raw = $_POST['lb_settings'] ?? [];
        LeadBridge_Config::save_settings( $raw );
        wp_safe_redirect( admin_url( 'admin.php?page=leadbridge-settings&lb_notice=saved' ) );
        exit;
    }

    private function redirect_edit( string $id, string $type, string $msg ): void {
        $url = admin_url( 'admin.php?page=leadbridge-edit' );
        if ( $id ) {
            $url = add_query_arg( [ 'id' => $id, 'lb_notice' => $type, 'lb_msg' => urlencode( $msg ) ], $url );
        } else {
            $url = add_query_arg( [ 'lb_notice' => $type, 'lb_msg' => urlencode( $msg ) ], $url );
        }
        wp_safe_redirect( $url );
        exit;
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    public function ajax_get_logs(): void {
        check_ajax_referer( 'leadbridge_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $filter  = sanitize_key( $_POST['filter'] ?? '' ) ?: null;
        $entries = LeadBridge_Logger::get_entries( 200, $filter );
        wp_send_json_success( $entries );
    }

    public function ajax_clear_logs(): void {
        check_ajax_referer( 'leadbridge_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        LeadBridge_Logger::clear_log();
        wp_send_json_success( [ 'message' => 'Logs vidés.' ] );
    }

    public function ajax_test_endpoint(): void {
        check_ajax_referer( 'leadbridge_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $url  = esc_url_raw( $_POST['url'] ?? '' );
        $type = sanitize_key( $_POST['type'] ?? 'dashboard' );

        if ( empty( $url ) ) {
            wp_send_json_error( 'URL manquante.' );
        }

        // Send a clearly-marked test payload
        $payload = [
            'leadbridge_test' => 'true',
            'source'          => 'LeadBridge/' . LEADBRIDGE_VERSION . ' – Test admin',
            'timestamp'       => current_time( 'c' ),
            'nom'             => 'TEST',
            'email'           => 'test@leadbridge.local',
        ];

        $with_referer = ( $type === 'culligan' );
        $result       = LeadBridge_Sender::send( $url, $payload, $with_referer );

        wp_send_json_success( [
            'ok'   => $result['ok'],
            'code' => $result['code'],
            'body' => $result['body'],
        ] );
    }

    public function ajax_retry_item(): void {
        check_ajax_referer( 'leadbridge_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $item_id = sanitize_text_field( $_POST['item_id'] ?? '' );
        $queue   = get_option( LEADBRIDGE_QUEUE, [] );

        foreach ( $queue as &$item ) {
            if ( ( $item['id'] ?? '' ) === $item_id ) {
                $item['next_attempt'] = time(); // trigger on next cron run
                $item['attempts']     = max( 0, (int) $item['attempts'] - 1 ); // give one more chance
                break;
            }
        }
        unset( $item );

        update_option( LEADBRIDGE_QUEUE, $queue, false );

        // Also run immediately
        do_action( 'leadbridge_process_retry_queue' );

        wp_send_json_success( [ 'message' => 'Relancé.' ] );
    }

    public function ajax_dismiss_item(): void {
        check_ajax_referer( 'leadbridge_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $item_id = sanitize_text_field( $_POST['item_id'] ?? '' );
        $queue   = array_values( array_filter(
            get_option( LEADBRIDGE_QUEUE, [] ),
            fn( $i ) => ( $i['id'] ?? '' ) !== $item_id
        ) );
        update_option( LEADBRIDGE_QUEUE, $queue, false );
        wp_send_json_success();
    }

    public function ajax_load_template(): void {
        check_ajax_referer( 'leadbridge_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        $key       = sanitize_key( $_POST['template'] ?? '' );
        $templates = LeadBridge_Config::get_templates();

        if ( ! isset( $templates[ $key ] ) ) {
            wp_send_json_error( 'Template inconnu.' );
        }

        wp_send_json_success( $templates[ $key ] );
    }

    public function ajax_run_cron(): void {
        check_ajax_referer( 'leadbridge_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

        do_action( 'leadbridge_process_retry_queue' );
        wp_send_json_success( [ 'message' => 'File d\'attente traitée.' ] );
    }

    // =========================================================================
    // ── Page: Dashboard / Forms list ─────────────────────────────────────────
    // =========================================================================

    public function page_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $forms       = LeadBridge_Config::get_all_forms();
        $log_size    = LeadBridge_Utils::format_bytes( LeadBridge_Logger::get_log_size() );
        $fail_count  = LeadBridge_Logger::get_failure_count();
        $queue_count = count( get_option( LEADBRIDGE_QUEUE, [] ) );
        $notice      = $this->get_notice();

        ?>
        <div class="wrap lb-wrap">
            <h1 class="lb-page-title">
                <span class="lb-logo">⟐</span> LeadBridge
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=leadbridge-edit' ) ); ?>" class="page-title-action">
                    + Ajouter un formulaire
                </a>
            </h1>

            <?php $this->render_notice( $notice ); ?>

            <!-- Stats bar -->
            <div class="lb-stats-bar">
                <div class="lb-stat">
                    <span class="lb-stat-value"><?php echo esc_html( count( $forms ) ); ?></span>
                    <span class="lb-stat-label">Formulaire<?php echo count( $forms ) > 1 ? 's' : ''; ?> configuré<?php echo count( $forms ) > 1 ? 's' : ''; ?></span>
                </div>
                <div class="lb-stat <?php echo $fail_count > 0 ? 'lb-stat--error' : ''; ?>">
                    <span class="lb-stat-value"><?php echo esc_html( $fail_count ); ?></span>
                    <span class="lb-stat-label">Échec<?php echo $fail_count > 1 ? 's' : ''; ?> non acquitté<?php echo $fail_count > 1 ? 's' : ''; ?></span>
                </div>
                <div class="lb-stat <?php echo $queue_count > 0 ? 'lb-stat--warn' : ''; ?>">
                    <span class="lb-stat-value"><?php echo esc_html( $queue_count ); ?></span>
                    <span class="lb-stat-label">En attente de retry</span>
                </div>
                <div class="lb-stat">
                    <span class="lb-stat-value"><?php echo esc_html( $log_size ); ?></span>
                    <span class="lb-stat-label">Taille du log</span>
                </div>
            </div>

            <?php if ( empty( $forms ) ) : ?>
                <div class="lb-empty-state">
                    <div class="lb-empty-icon">📋</div>
                    <h2>Aucun formulaire configuré</h2>
                    <p>Commencez par ajouter un formulaire ou chargez un template parmi les 6 sites préconfigurés.</p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=leadbridge-edit' ) ); ?>" class="button button-primary button-hero">
                        + Ajouter mon premier formulaire
                    </a>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped lb-table">
                    <thead>
                        <tr>
                            <th style="width:30%">Nom du site / formulaire</th>
                            <th style="width:12%">ID Fluent Forms</th>
                            <th style="width:35%">Endpoints</th>
                            <th style="width:8%">Statut</th>
                            <th style="width:15%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $forms as $form ) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $form['name'] ?? '—' ); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo esc_html( $form['form_id'] ?? '?' ); ?></code>
                                </td>
                                <td>
                                    <?php foreach ( (array) ( $form['endpoints'] ?? [] ) as $ep ) : ?>
                                        <span class="lb-ep-badge lb-ep-badge--<?php echo esc_attr( $ep['type'] ?? 'unknown' ); ?>">
                                            <?php echo esc_html( $ep['label'] ?? $ep['type'] ?? '?' ); ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <?php if ( empty( $form['endpoints'] ) ) : ?>
                                        <em class="lb-muted">Aucun endpoint</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( ! empty( $form['enabled'] ) ) : ?>
                                        <span class="lb-status lb-status--on">Actif</span>
                                    <?php else : ?>
                                        <span class="lb-status lb-status--off">Inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=leadbridge-edit&id=' . $form['id'] ) ); ?>" class="button button-small">Modifier</a>
                                    &nbsp;
                                    <form method="post" style="display:inline" onsubmit="return confirm(LeadBridge.strings.confirmDelete)">
                                        <?php wp_nonce_field( 'lb_delete_form' ); ?>
                                        <input type="hidden" name="lb_action" value="delete_form">
                                        <input type="hidden" name="lb_form_id" value="<?php echo esc_attr( $form['id'] ); ?>">
                                        <button type="submit" class="button button-small lb-btn-delete">Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // ── Page: Edit / Add form ─────────────────────────────────────────────────
    // =========================================================================

    public function page_edit(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $id      = sanitize_text_field( $_GET['id'] ?? '' );
        $form    = $id ? LeadBridge_Config::get_form_by_id( $id ) : null;
        $is_new  = ( $form === null );
        $notice  = $this->get_notice();
        $title   = $is_new ? 'Nouveau formulaire' : 'Modifier : ' . esc_html( $form['name'] ?? '' );

        ?>
        <div class="wrap lb-wrap">
            <h1 class="lb-page-title">
                <span class="lb-logo">⟐</span> LeadBridge &rsaquo; <?php echo esc_html( $title ); ?>
            </h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=leadbridge' ) ); ?>">&larr; Retour à la liste</a></p>

            <?php $this->render_notice( $notice ); ?>

            <!-- Template loader -->
            <div class="lb-template-loader">
                <label for="lb-tpl-select"><strong>Charger un template :</strong></label>
                <select id="lb-tpl-select">
                    <option value="">— Sélectionner un template —</option>
                    <option value="fontaine">fontaine-a-eau.com (Dashboard + Culligan)</option>
                    <option value="distributeur">distributeurautomatique.net (Dashboard + Bridge)</option>
                    <option value="photocopieuse">photocopieuse.net (Dashboard)</option>
                    <option value="autolaveuse">autolaveuse.fr (Dashboard)</option>
                    <option value="telesurveillance">telesurveillance.eu (Dashboard + Bridge)</option>
                    <option value="nettoyage">entreprisenettoyage.net (Bridge)</option>
                </select>
                <button type="button" id="lb-load-tpl" class="button">Charger</button>
                <span class="lb-muted lb-tpl-hint">⚠ Remplace la configuration actuelle de la page.</span>
            </div>

            <form method="post" id="lb-form-edit" action="<?php echo esc_url( admin_url( 'admin.php?page=leadbridge-edit' ) ); ?>">
                <?php wp_nonce_field( 'lb_save_form' ); ?>
                <input type="hidden" name="lb_action" value="save_form">
                <input type="hidden" name="lb_form_id" value="<?php echo esc_attr( $form['id'] ?? '' ); ?>">

                <!-- Basic info -->
                <div class="lb-card">
                    <h2 class="lb-card-title">Informations générales</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="lb-name">Nom du site / formulaire <span class="required">*</span></label></th>
                            <td>
                                <input type="text" id="lb-name" name="lb_form[name]" class="regular-text"
                                       value="<?php echo esc_attr( $form['name'] ?? '' ); ?>"
                                       placeholder="ex : fontaine-a-eau.com" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="lb-form-id">ID Fluent Forms <span class="required">*</span></label></th>
                            <td>
                                <input type="number" id="lb-form-id" name="lb_form[form_id]" class="small-text"
                                       value="<?php echo esc_attr( $form['form_id'] ?? '' ); ?>"
                                       min="1" required>
                                <p class="description">Trouvez l'ID dans Fluent Forms → Formulaires → colonne <em>ID</em>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Statut</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="lb_form[enabled]" value="1"
                                           <?php checked( $form['enabled'] ?? true ); ?>>
                                    Formulaire actif
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Endpoints -->
                <div class="lb-card">
                    <h2 class="lb-card-title">Endpoints
                        <button type="button" class="button lb-add-endpoint" data-idx="<?php echo esc_attr( count( $form['endpoints'] ?? [] ) ); ?>">
                            + Ajouter un endpoint
                        </button>
                    </h2>

                    <div id="lb-endpoints-container">
                        <?php
                        $ep_list = $form['endpoints'] ?? [];
                        foreach ( $ep_list as $idx => $ep ) {
                            $this->render_endpoint( $idx, $ep );
                        }
                        ?>
                    </div>

                    <?php if ( empty( $ep_list ) ) : ?>
                        <p id="lb-no-endpoints" class="lb-muted">Aucun endpoint configuré. Cliquez sur <em>+ Ajouter un endpoint</em>.</p>
                    <?php endif; ?>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">Sauvegarder la configuration</button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=leadbridge' ) ); ?>" class="button button-large">Annuler</a>
                </p>
            </form>
        </div>

        <!-- Endpoint template (hidden, cloned by JS) -->
        <template id="lb-ep-template">
            <?php $this->render_endpoint( '__IDX__', [
                'id'      => '',
                'type'    => 'dashboard',
                'label'   => '',
                'url'     => '',
                'enabled' => true,
                'mapping' => [],
                'fixed'   => [],
            ] ); ?>
        </template>

        <!-- Mapping row template -->
        <template id="lb-mapping-row-template">
            <?php $this->render_mapping_row( '__IDX__', '__RIDX__', '', '' ); ?>
        </template>

        <!-- Fixed row template -->
        <template id="lb-fixed-row-template">
            <?php $this->render_fixed_row( '__IDX__', '__RIDX__', '', '' ); ?>
        </template>

        <!-- Culligan row template -->
        <template id="lb-culligan-row-template">
            <?php $this->render_culligan_row( '__IDX__', '__RIDX__', '', '' ); ?>
        </template>
        <?php
    }

    // ── Render helpers for endpoint cards ─────────────────────────────────────

    private function render_endpoint( $idx, array $ep ): void {
        $type     = $ep['type'] ?? 'dashboard';
        $ep_id    = $ep['id'] ?? '';
        $prefix   = "lb_form[endpoints][{$idx}]";
        $type_labels = [
            'dashboard' => 'Dashboard Webylead',
            'bridge'    => 'Applicatif Webylead',
            'culligan'  => 'Culligan / Pardot',
        ];
        ?>
        <div class="lb-endpoint-card" data-idx="<?php echo esc_attr( $idx ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
            <div class="lb-endpoint-header">
                <span class="lb-endpoint-drag">⠿</span>
                <span class="lb-endpoint-type-badge lb-ep-badge--<?php echo esc_attr( $type ); ?>">
                    <?php echo esc_html( $type_labels[ $type ] ?? $type ); ?>
                </span>
                <span class="lb-endpoint-label-preview"><?php echo esc_html( $ep['label'] ?? '' ); ?></span>
                <button type="button" class="lb-toggle-endpoint button-link">▾</button>
                <button type="button" class="lb-remove-endpoint lb-btn-icon" title="Supprimer cet endpoint">✕</button>
            </div>
            <div class="lb-endpoint-body">
                <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( $ep_id ); ?>">

                <table class="form-table lb-ep-table">
                    <tr>
                        <th><label>Type d'endpoint</label></th>
                        <td>
                            <select name="<?php echo esc_attr( $prefix ); ?>[type]" class="lb-ep-type-select">
                                <option value="dashboard" <?php selected( $type, 'dashboard' ); ?>>Dashboard Webylead</option>
                                <option value="bridge"    <?php selected( $type, 'bridge' ); ?>>Applicatif Webylead (Bridge)</option>
                                <option value="culligan"  <?php selected( $type, 'culligan' ); ?>>Culligan / Pardot</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Label (référence interne)</label></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" class="regular-text lb-ep-label-input"
                                   value="<?php echo esc_attr( $ep['label'] ?? '' ); ?>"
                                   placeholder="ex : Dashboard principal">
                        </td>
                    </tr>
                    <tr>
                        <th><label>URL de l'endpoint <span class="required">*</span></label></th>
                        <td>
                            <input type="url" name="<?php echo esc_attr( $prefix ); ?>[url]" class="large-text lb-ep-url"
                                   value="<?php echo esc_attr( $ep['url'] ?? '' ); ?>"
                                   placeholder="https://...">
                            <button type="button" class="button lb-test-btn" data-idx="<?php echo esc_attr( $idx ); ?>">
                                Tester la connexion
                            </button>
                            <span class="lb-test-result"></span>
                        </td>
                    </tr>
                    <tr>
                        <th>Statut</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="1"
                                       <?php checked( $ep['enabled'] ?? true ); ?>>
                                Endpoint actif
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- ── Dashboard: field mapping ── -->
                <div class="lb-section lb-section--dashboard" <?php echo $type !== 'dashboard' ? 'style="display:none"' : ''; ?>>
                    <h3 class="lb-section-title">Mapping des champs
                        <span class="lb-section-hint">(Slug Fluent Forms → Nom du champ envoyé)</span>
                    </h3>
                    <table class="lb-mapping-table widefat striped lb-field-table">
                        <thead>
                            <tr>
                                <th style="width:40%">Slug Fluent Forms</th>
                                <th style="width:50%">Libellé envoyé à l'API</th>
                                <th style="width:10%"></th>
                            </tr>
                        </thead>
                        <tbody class="lb-mapping-rows">
                            <?php
                            $mapping = (array) ( $ep['mapping'] ?? [] );
                            $ridx    = 0;
                            foreach ( $mapping as $slug => $label ) {
                                $this->render_mapping_row( $idx, $ridx, $slug, $label );
                                $ridx++;
                            }
                            ?>
                        </tbody>
                    </table>
                    <button type="button" class="button lb-add-mapping" data-idx="<?php echo esc_attr( $idx ); ?>"
                            data-ridx="<?php echo esc_attr( $ridx ); ?>">+ Ajouter un champ</button>
                </div>

                <!-- ── Bridge: slugs list ── -->
                <div class="lb-section lb-section--bridge" <?php echo $type !== 'bridge' ? 'style="display:none"' : ''; ?>>
                    <h3 class="lb-section-title">Slugs à envoyer
                        <span class="lb-section-hint">(séparés par des virgules)</span>
                    </h3>
                    <input type="text" name="<?php echo esc_attr( $prefix ); ?>[slugs]" class="large-text lb-bridge-slugs"
                           value="<?php echo esc_attr( $ep['slugs'] ?? '' ); ?>"
                           placeholder="nom,prenom,email,telephone,cp,societe">
                    <p class="description">Ces slugs seront envoyés tels quels (sans traduction).</p>
                </div>

                <!-- ── Culligan: field roles ── -->
                <div class="lb-section lb-section--culligan" <?php echo $type !== 'culligan' ? 'style="display:none"' : ''; ?>>
                    <h3 class="lb-section-title">Correspondance des champs Culligan
                        <span class="lb-section-hint">(Slug Fluent Forms → Rôle dans le payload Culligan)</span>
                    </h3>
                    <table class="lb-culligan-table widefat striped lb-field-table">
                        <thead>
                            <tr>
                                <th style="width:40%">Slug Fluent Forms</th>
                                <th style="width:50%">Rôle (nom, prenom, email, cp, telephone, societe, salaries, visiteurs, delai)</th>
                                <th style="width:10%"></th>
                            </tr>
                        </thead>
                        <tbody class="lb-culligan-rows">
                            <?php
                            $fields = (array) ( $ep['fields'] ?? [] );
                            $ridx   = 0;
                            foreach ( $fields as $slug => $role ) {
                                $this->render_culligan_row( $idx, $ridx, $slug, $role );
                                $ridx++;
                            }
                            ?>
                        </tbody>
                    </table>
                    <button type="button" class="button lb-add-culligan" data-idx="<?php echo esc_attr( $idx ); ?>"
                            data-ridx="<?php echo esc_attr( $ridx ); ?>">+ Ajouter un champ</button>

                    <h3 class="lb-section-title" style="margin-top:16px">Modèle de la question</h3>
                    <input type="text" name="<?php echo esc_attr( $prefix ); ?>[question_tpl]" class="large-text"
                           value="<?php echo esc_attr( $ep['question_tpl'] ?? 'Demande via partenaire. Effectif: %s, Visiteurs/jour: %s, Délai: %s.' ); ?>">
                    <p class="description">Les 3 <code>%s</code> seront remplacés par : salaries, visiteurs, delai.</p>
                </div>

                <!-- ── Fixed fields (shared) ── -->
                <div class="lb-section lb-section--fixed">
                    <h3 class="lb-section-title">Champs fixes
                        <span class="lb-section-hint">(valeurs statiques toujours injectées)</span>
                    </h3>
                    <table class="lb-fixed-table widefat striped lb-field-table">
                        <thead>
                            <tr>
                                <th style="width:40%">Clé</th>
                                <th style="width:50%">Valeur</th>
                                <th style="width:10%"></th>
                            </tr>
                        </thead>
                        <tbody class="lb-fixed-rows">
                            <?php
                            $fixed = (array) ( $ep['fixed'] ?? [] );
                            $ridx  = 0;
                            foreach ( $fixed as $key => $val ) {
                                $this->render_fixed_row( $idx, $ridx, $key, $val );
                                $ridx++;
                            }
                            ?>
                        </tbody>
                    </table>
                    <button type="button" class="button lb-add-fixed" data-idx="<?php echo esc_attr( $idx ); ?>"
                            data-ridx="<?php echo esc_attr( $ridx ); ?>">+ Ajouter un champ fixe</button>
                </div>

            </div><!-- /.lb-endpoint-body -->
        </div><!-- /.lb-endpoint-card -->
        <?php
    }

    private function render_mapping_row( $ep_idx, $ridx, string $slug, string $label ): void {
        $prefix = "lb_form[endpoints][{$ep_idx}][mapping_rows][{$ridx}]";
        ?>
        <tr class="lb-row">
            <td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[slug]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" placeholder="ex: nom"></td>
            <td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" class="regular-text" placeholder="ex: Nom"></td>
            <td><button type="button" class="lb-remove-row lb-btn-icon" title="Supprimer">✕</button></td>
        </tr>
        <?php
    }

    private function render_fixed_row( $ep_idx, $ridx, string $key, string $value ): void {
        $prefix = "lb_form[endpoints][{$ep_idx}][fixed_rows][{$ridx}]";
        ?>
        <tr class="lb-row">
            <td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[key]" value="<?php echo esc_attr( $key ); ?>" class="regular-text" placeholder="ex: utm_source"></td>
            <td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[value]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="ex: Affiliation"></td>
            <td><button type="button" class="lb-remove-row lb-btn-icon" title="Supprimer">✕</button></td>
        </tr>
        <?php
    }

    private function render_culligan_row( $ep_idx, $ridx, string $slug, string $role ): void {
        $prefix = "lb_form[endpoints][{$ep_idx}][culligan_rows][{$ridx}]";
        ?>
        <tr class="lb-row">
            <td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[slug]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" placeholder="ex: nom"></td>
            <td>
                <select name="<?php echo esc_attr( $prefix ); ?>[role]">
                    <?php foreach ( [ 'nom', 'prenom', 'email', 'telephone', 'societe', 'cp', 'salaries', 'visiteurs', 'delai' ] as $r ) : ?>
                        <option value="<?php echo esc_attr( $r ); ?>" <?php selected( $role, $r ); ?>><?php echo esc_html( $r ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><button type="button" class="lb-remove-row lb-btn-icon" title="Supprimer">✕</button></td>
        </tr>
        <?php
    }

    // =========================================================================
    // ── Page: Logs ────────────────────────────────────────────────────────────
    // =========================================================================

    public function page_logs(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Reset failure count when user views logs
        LeadBridge_Logger::reset_failure_count();

        $queue   = get_option( LEADBRIDGE_QUEUE, [] );
        $entries = LeadBridge_Logger::get_entries( 200 );
        $log_path = LeadBridge_Logger::get_log_path();
        $log_size = LeadBridge_Utils::format_bytes( LeadBridge_Logger::get_log_size() );
        ?>
        <div class="wrap lb-wrap">
            <h1 class="lb-page-title"><span class="lb-logo">⟐</span> LeadBridge &rsaquo; Logs &amp; File d'attente</h1>

            <!-- ── Retry queue ── -->
            <?php if ( ! empty( $queue ) ) : ?>
            <div class="lb-card lb-card--warning">
                <h2 class="lb-card-title">⏳ File d'attente – <?php echo esc_html( count( $queue ) ); ?> élément<?php echo count( $queue ) > 1 ? 's' : ''; ?></h2>
                <div class="lb-queue-actions">
                    <button type="button" id="lb-run-cron" class="button button-primary">Traiter maintenant</button>
                </div>
                <table class="wp-list-table widefat fixed striped lb-table" style="margin-top:12px">
                    <thead>
                        <tr>
                            <th style="width:25%">Endpoint</th>
                            <th style="width:15%">Formulaire</th>
                            <th style="width:10%">Tentatives</th>
                            <th style="width:25%">Prochaine tentative</th>
                            <th style="width:25%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $queue as $item ) : ?>
                            <tr id="lb-qi-<?php echo esc_attr( $item['id'] ?? '' ); ?>">
                                <td>
                                    <strong><?php echo esc_html( $item['endpoint_label'] ?? '' ); ?></strong><br>
                                    <small class="lb-muted"><?php echo esc_html( $item['endpoint_url'] ?? '' ); ?></small>
                                </td>
                                <td><code>#<?php echo esc_html( $item['form_id'] ?? '?' ); ?></code></td>
                                <td><?php echo esc_html( $item['attempts'] ?? 0 ); ?> / <?php echo esc_html( $item['max_attempts'] ?? 3 ); ?></td>
                                <td>
                                    <?php
                                    $next = (int) ( $item['next_attempt'] ?? 0 );
                                    echo $next > time()
                                        ? esc_html( human_time_diff( time(), $next ) . ' restant' )
                                        : '<em>Maintenant</em>';
                                    ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small lb-retry-btn"
                                            data-id="<?php echo esc_attr( $item['id'] ?? '' ); ?>">Relancer</button>
                                    <button type="button" class="button button-small lb-dismiss-btn"
                                            data-id="<?php echo esc_attr( $item['id'] ?? '' ); ?>">Ignorer</button>
                                    <?php if ( ! empty( $item['errors'] ) ) : ?>
                                        <button type="button" class="button button-small lb-show-errors"
                                                data-errors="<?php echo esc_attr( json_encode( $item['errors'] ) ); ?>">Erreurs</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- ── Log viewer ── -->
            <div class="lb-card">
                <h2 class="lb-card-title">
                    Journal des envois
                    <div class="lb-log-actions">
                        <select id="lb-filter">
                            <option value="">Tous les événements</option>
                            <option value="ok">Succès uniquement</option>
                            <option value="fail">Échecs uniquement</option>
                        </select>
                        <button type="button" id="lb-refresh-logs" class="button">↻ Rafraîchir</button>
                        <button type="button" id="lb-clear-logs" class="button lb-btn-danger">🗑 Vider les logs</button>
                        <label class="lb-autorefresh-label">
                            <input type="checkbox" id="lb-autorefresh"> Auto-refresh (10s)
                        </label>
                    </div>
                </h2>

                <div class="lb-log-meta">
                    Fichier : <code><?php echo esc_html( $log_path ); ?></code>
                    &nbsp;|&nbsp; Taille : <strong><?php echo esc_html( $log_size ); ?></strong>
                    &nbsp;|&nbsp; <span id="lb-log-count"><?php echo esc_html( count( $entries ) ); ?></span> entrées affichées
                </div>

                <div id="lb-log-container">
                    <?php $this->render_log_table( $entries ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_log_table( array $entries ): void {
        if ( empty( $entries ) ) {
            echo '<p class="lb-muted lb-empty-log">Aucune entrée de log.</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped lb-table lb-log-table">
            <thead>
                <tr>
                    <th style="width:160px">Date</th>
                    <th style="width:80px">Statut</th>
                    <th style="width:80px">Code</th>
                    <th style="width:100px">Formulaire</th>
                    <th>Endpoint → Payload → Réponse</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $entries as $e ) : ?>
                    <tr class="lb-log-row <?php echo $e['ok'] ? 'lb-row--ok' : 'lb-row--fail'; ?>">
                        <td class="lb-log-ts"><?php echo esc_html( substr( $e['ts'] ?? '', 0, 19 ) ); ?></td>
                        <td>
                            <?php if ( $e['ok'] ) : ?>
                                <span class="lb-badge lb-badge--ok">OK</span>
                            <?php else : ?>
                                <span class="lb-badge lb-badge--fail">FAIL</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html( $e['code'] ?? '–' ); ?></code></td>
                        <td><code>#<?php echo esc_html( $e['form_id'] ?? '–' ); ?></code></td>
                        <td>
                            <strong><?php echo esc_html( $e['target'] ?? '' ); ?></strong>
                            <?php if ( ! empty( $e['error'] ) ) : ?>
                                <span class="lb-log-error"><?php echo esc_html( $e['error'] ); ?></span>
                            <?php endif; ?>
                            <button type="button" class="lb-expand-btn button-link" data-payload="<?php echo esc_attr( json_encode( $e['payload'] ?? [] ) ); ?>"
                                    data-preview="<?php echo esc_attr( $e['preview'] ?? '' ); ?>">Détails ▾</button>
                            <div class="lb-log-details" style="display:none"></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // =========================================================================
    // ── Page: Settings ────────────────────────────────────────────────────────
    // =========================================================================

    public function page_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $settings = LeadBridge_Config::get_settings();
        $notice   = $this->get_notice();
        $next_cron = wp_next_scheduled( 'leadbridge_process_retry_queue' );
        ?>
        <div class="wrap lb-wrap">
            <h1 class="lb-page-title"><span class="lb-logo">⟐</span> LeadBridge &rsaquo; Paramètres</h1>

            <?php $this->render_notice( $notice ); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=leadbridge-settings' ) ); ?>">
                <?php wp_nonce_field( 'lb_save_settings' ); ?>
                <input type="hidden" name="lb_action" value="save_settings">

                <!-- Rate limiting -->
                <div class="lb-card">
                    <h2 class="lb-card-title">Limitation du taux (Rate Limiting)</h2>
                    <table class="form-table">
                        <tr>
                            <th>Activer la limitation</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="lb_settings[rate_limit_enabled]" value="1"
                                           <?php checked( $settings['rate_limit_enabled'] ?? true ); ?>>
                                    Limiter les soumissions par IP
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Nombre max de soumissions</label></th>
                            <td>
                                <input type="number" name="lb_settings[rate_limit_max]" class="small-text"
                                       value="<?php echo esc_attr( $settings['rate_limit_max'] ?? 5 ); ?>" min="1" max="100">
                                <span class="lb-muted"> par IP par fenêtre de temps</span>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Fenêtre de temps</label></th>
                            <td>
                                <select name="lb_settings[rate_limit_window]">
                                    <?php
                                    $options = [ 60 => '1 minute', 300 => '5 minutes', 900 => '15 minutes', 1800 => '30 minutes', 3600 => '1 heure', 21600 => '6 heures', 86400 => '24 heures' ];
                                    foreach ( $options as $val => $label ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $val ); ?>"
                                                <?php selected( (int) ( $settings['rate_limit_window'] ?? 3600 ), $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Retry -->
                <div class="lb-card">
                    <h2 class="lb-card-title">Retry automatique (WP-Cron)</h2>
                    <p class="description">
                        En cas d'échec, le système retentera automatiquement l'envoi.
                        <?php if ( $next_cron ) : ?>
                            Prochain passage WP-Cron : <strong><?php echo esc_html( human_time_diff( time(), $next_cron ) ); ?></strong>.
                        <?php endif; ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th><label>Nombre max de tentatives</label></th>
                            <td>
                                <input type="number" name="lb_settings[retry_max]" class="small-text"
                                       value="<?php echo esc_attr( $settings['retry_max'] ?? 3 ); ?>" min="0" max="10">
                                <p class="description">0 = désactiver le retry.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Délai entre tentatives</label></th>
                            <td>
                                <select name="lb_settings[retry_delay]">
                                    <?php
                                    $delays = [ 60 => '1 minute', 300 => '5 minutes', 600 => '10 minutes', 900 => '15 minutes', 1800 => '30 minutes', 3600 => '1 heure' ];
                                    foreach ( $delays as $val => $label ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $val ); ?>"
                                                <?php selected( (int) ( $settings['retry_delay'] ?? 900 ), $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Notifications -->
                <div class="lb-card">
                    <h2 class="lb-card-title">Notifications par email</h2>
                    <table class="form-table">
                        <tr>
                            <th>Notifier en cas d'échec</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="lb_settings[notify_on_failure]" value="1"
                                           <?php checked( $settings['notify_on_failure'] ?? false ); ?>>
                                    Envoyer un email lors d'un échec d'envoi
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Email de notification</label></th>
                            <td>
                                <input type="email" name="lb_settings[notify_email]" class="regular-text"
                                       value="<?php echo esc_attr( $settings['notify_email'] ?? '' ); ?>"
                                       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                                <p class="description">Laisser vide pour utiliser l'email administrateur WordPress.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Log file info -->
                <div class="lb-card">
                    <h2 class="lb-card-title">Fichier de log</h2>
                    <table class="form-table">
                        <tr>
                            <th>Emplacement</th>
                            <td><code><?php echo esc_html( LeadBridge_Logger::get_log_path() ); ?></code></td>
                        </tr>
                        <tr>
                            <th>Taille actuelle</th>
                            <td>
                                <?php echo esc_html( LeadBridge_Utils::format_bytes( LeadBridge_Logger::get_log_size() ) ); ?>
                                &nbsp;
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=leadbridge-logs' ) ); ?>" class="button button-small">Voir les logs</a>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">Enregistrer les paramètres</button>
                </p>
            </form>
        </div>
        <?php
    }

    // ── Notice helpers ────────────────────────────────────────────────────────

    private function get_notice(): array {
        $type = sanitize_key( $_GET['lb_notice'] ?? '' );
        $msg  = sanitize_text_field( urldecode( $_GET['lb_msg'] ?? '' ) );

        if ( ! $type ) return [];

        $defaults = [
            'saved'   => 'Configuration sauvegardée.',
            'deleted' => 'Formulaire supprimé.',
            'success' => $msg ?: 'Opération réussie.',
            'error'   => $msg ?: 'Une erreur est survenue.',
        ];

        return [
            'type'    => $type,
            'message' => $msg ?: ( $defaults[ $type ] ?? '' ),
        ];
    }

    private function render_notice( array $notice ): void {
        if ( empty( $notice ) ) return;

        $class = in_array( $notice['type'], [ 'error' ], true ) ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
    }
}
