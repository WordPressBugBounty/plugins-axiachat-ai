<?php
/**
 * Leads Capture Add-on Loader
 * 
 * Provides lead capture functionality through AI chat conversations.
 * Allows bots to collect customer data (name, email, phone, etc.) and
 * save it to the internal database.
 * 
 * @package AIChat
 * @subpackage Leads
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Add-on metadata
if ( ! function_exists( 'aichat_leads_addon_info' ) ) {
    function aichat_leads_addon_info() {
        return [
            'id'          => 'leads',
            'name'        => __( 'Lead Capture', 'axiachat-ai' ),
            'description' => __( 'Capture customer contact information through chat conversations.', 'axiachat-ai' ),
            'version'     => '1.0.0',
            'author'      => 'AxiaChat AI',
            'requires'    => '2.0.0',
            'default'     => true, // Activado por defecto
        ];
    }
}

// Check if add-on is enabled (default: true)
$aichat_leads_enabled = get_option( 'aichat_addon_leads_enabled', 1 );
if ( ! $aichat_leads_enabled ) {
    return;
}

// Define constants
if ( ! defined( 'AICHAT_LEADS_VERSION' ) ) {
    define( 'AICHAT_LEADS_VERSION', '1.0.0' );
}
if ( ! defined( 'AICHAT_LEADS_DIR' ) ) {
    define( 'AICHAT_LEADS_DIR', __DIR__ . '/' );
}

// Load core components
require_once AICHAT_LEADS_DIR . 'class-leads-manager.php';
require_once AICHAT_LEADS_DIR . 'integration.php';

// Load adapters
require_once AICHAT_LEADS_DIR . 'adapters/class-adapter-internal.php';

// Extended adapters
require_once AICHAT_LEADS_DIR . 'adapters/class-adapter-cpt.php';
if ( file_exists( AICHAT_LEADS_DIR . 'adapters/class-adapter-cf7.php' ) ) {
    require_once AICHAT_LEADS_DIR . 'adapters/class-adapter-cf7.php';
}
if ( file_exists( AICHAT_LEADS_DIR . 'adapters/class-adapter-wpforms.php' ) ) {
    require_once AICHAT_LEADS_DIR . 'adapters/class-adapter-wpforms.php';
}

// Google Sheets integration
require_once AICHAT_LEADS_DIR . 'google-sheets/class-gsheets-oauth.php';
require_once AICHAT_LEADS_DIR . 'google-sheets/class-gsheets-client.php';
require_once AICHAT_LEADS_DIR . 'adapters/class-adapter-gsheets.php';

// Admin UI (solo en admin)
if ( is_admin() ) {
    require_once AICHAT_LEADS_DIR . 'admin-settings.php';
    require_once AICHAT_LEADS_DIR . 'admin-ajax.php';
    
    // Register Leads submenu
    add_action( 'admin_menu', 'aichat_leads_register_menu', 16 );
    
    // Enqueue admin assets
    add_action( 'admin_enqueue_scripts', 'aichat_leads_admin_enqueue_scripts' );
}

/**
 * Register Leads admin menu
 */
function aichat_leads_register_menu() {
    add_submenu_page(
        'aichat-settings',
        __( 'Leads', 'axiachat-ai' ),
        __( 'Leads', 'axiachat-ai' ),
        'manage_options',
        'aichat-leads',
        'aichat_leads_render_page'
    );
}

/**
 * Enqueue Leads admin scripts
 */
function aichat_leads_admin_enqueue_scripts( $hook_suffix ) {
    if ( $hook_suffix !== 'axiachat-ai_page_aichat-leads' ) {
        return;
    }
    
    $base_url = plugin_dir_url( dirname( dirname( dirname( __FILE__ ) ) ) );
    
    // CSS - depends on Bootstrap which is loaded by main plugin for this page
    wp_enqueue_style(
        'aichat-leads-admin',
        $base_url . 'assets/css/leads-admin.css',
        [ 'aichat-bootstrap', 'aichat-bootstrap-icons' ],
        AICHAT_LEADS_VERSION
    );
    
    // JS depends on jQuery and Bootstrap - add wp-i18n for translation support
    wp_enqueue_script(
        'aichat-leads-admin',
        $base_url . 'assets/js/leads-admin.js',
        [ 'jquery', 'aichat-bootstrap', 'wp-i18n' ],
        AICHAT_LEADS_VERSION,
        true
    );
    wp_set_script_translations( 'aichat-leads-admin', 'axiachat-ai', dirname( dirname( dirname( __DIR__ ) ) ) . '/languages' );
    
    $leads_config = [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'aichat_leads_admin' ),
        'i18n'     => [
            'confirm_delete'          => __( 'Are you sure you want to delete this lead?', 'axiachat-ai' ),
            'confirm_bulk'            => __( 'Are you sure you want to delete the selected leads?', 'axiachat-ai' ),
            'export_success'          => __( 'Export completed successfully.', 'axiachat-ai' ),
            'save_success'            => __( 'Settings saved successfully.', 'axiachat-ai' ),
            'error'                   => __( 'An error occurred. Please try again.', 'axiachat-ai' ),
        ],
    ];
    $leads_config['isPremium'] = true;
    $leads_config['i18n']['confirm_disconnect']      = __( 'Are you sure you want to disconnect Google Sheets?', 'axiachat-ai' );
    $leads_config['i18n']['spreadsheet_id_required'] = __( 'Please enter a Spreadsheet ID first.', 'axiachat-ai' );
    wp_localize_script( 'aichat-leads-admin', 'aichatLeadsAdmin', $leads_config );

    wp_enqueue_script(
        'aichat-leads-pro-extensions',
        AICHAT_PLUGIN_URL . 'assets/js/leads-pro-extensions.js',
        [ 'jquery', 'aichat-leads-admin' ],
        defined( 'AICHAT_VERSION' ) ? AICHAT_VERSION : '1.0.0',
        true
    );
}

/**
 * Create leads table on activation
 */
function aichat_leads_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'aichat_leads';
    $charset = $wpdb->get_charset_collate();
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        list_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        session_id VARCHAR(64) NULL,
        bot_slug VARCHAR(100) NOT NULL DEFAULT '',
        conversation_id BIGINT(20) UNSIGNED NULL,
        
        nombre VARCHAR(255) NOT NULL,
        email VARCHAR(255) NULL,
        telefono VARCHAR(50) NULL,
        empresa VARCHAR(255) NULL,
        interes TEXT NULL,
        notas TEXT NULL,
        campos_extra LONGTEXT NULL,
        
        destino VARCHAR(32) NOT NULL DEFAULT 'internal',
        destino_ref VARCHAR(100) NULL,
        estado ENUM('nuevo','contactado','convertido','descartado') NOT NULL DEFAULT 'nuevo',
        ip_hash VARCHAR(64) NULL,
        
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        
        PRIMARY KEY (id),
        KEY list_id (list_id),
        KEY bot_slug (bot_slug),
        KEY email (email),
        KEY estado (estado),
        KEY created_at (created_at),
        KEY conversation_id (conversation_id)
    ) $charset;";
    
    dbDelta( $sql );
}

/**
 * Create lead_lists table on activation
 */
function aichat_leads_create_lists_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'aichat_lead_lists';
    $charset = $wpdb->get_charset_collate();
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(64) NOT NULL,
        name VARCHAR(200) NOT NULL,
        description TEXT NULL,
        fields LONGTEXT NULL,
        destination VARCHAR(50) NOT NULL DEFAULT 'internal',
        destination_config LONGTEXT NULL,
        notify_enabled TINYINT(1) NOT NULL DEFAULT 0,
        notify_email VARCHAR(200) NULL,
        email_subject VARCHAR(200) NULL,
        email_body LONGTEXT NULL,
        webhook_url VARCHAR(500) NULL,
        webhook_enabled TINYINT(1) NOT NULL DEFAULT 0,
        tool_enabled TINYINT(1) NOT NULL DEFAULT 1,
        form_enabled TINYINT(1) NOT NULL DEFAULT 1,
        assigned_bots VARCHAR(500) NOT NULL DEFAULT 'all',
        tool_description TEXT NULL,
        form_mode VARCHAR(20) NOT NULL DEFAULT 'full',
        form_header TEXT NULL,
        form_submit_text VARCHAR(200) NULL,
        form_success_msg VARCHAR(500) NULL,
        form_bg_color VARCHAR(20) NULL,
        form_btn_color VARCHAR(20) NULL,
        store_ip TINYINT(1) NOT NULL DEFAULT 0,
        retention_days INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY status (status)
    ) $charset;";
    
    dbDelta( $sql );
}

/**
 * Migrate existing settings to default lead list
 */
function aichat_leads_maybe_migrate_to_lists() {
    global $wpdb;
    $table = $wpdb->prefix . 'aichat_lead_lists';
    
    // Skip if table doesn't exist yet
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return;
    }
    
    // Skip if already migrated (any lists exist)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    if ( $count > 0 ) {
        return;
    }
    
    // Build default list from existing settings
    $settings = get_option( 'aichat_leads_settings', [] );
    $now = current_time( 'mysql' );
    
    $destination = $settings['destination'] ?? 'internal';
    
    // Build destination config from existing options
    $dest_config = [];
    if ( $destination === 'cf7' ) {
        $dest_config['cf7_form_id'] = $settings['cf7_form_id'] ?? 0;
    } elseif ( $destination === 'wpforms' ) {
        $dest_config['wpforms_form_id'] = $settings['wpforms_form_id'] ?? 0;
    } elseif ( $destination === 'google_sheets' ) {
        $dest_config['spreadsheet_id'] = get_option( 'aichat_leads_gsheets_spreadsheet_id', '' );
        $dest_config['sheet_name']     = get_option( 'aichat_leads_gsheets_sheet_name', '' );
    }
    
    // Default fields (the 6 standard fields)
    $default_fields = [
        [ 'key' => 'name',     'label' => 'Name',     'type' => 'text',     'required' => false, 'description' => 'Customer full name' ],
        [ 'key' => 'email',    'label' => 'Email',    'type' => 'email',    'required' => false, 'description' => 'Contact email address' ],
        [ 'key' => 'phone',    'label' => 'Phone',    'type' => 'tel',      'required' => false, 'description' => 'Phone number' ],
        [ 'key' => 'company',  'label' => 'Company',  'type' => 'text',     'required' => false, 'description' => 'Company or organization name' ],
        [ 'key' => 'interest', 'label' => 'Interest', 'type' => 'text',     'required' => false, 'description' => 'Product/service of interest' ],
        [ 'key' => 'notes',    'label' => 'Notes',    'type' => 'textarea', 'required' => false, 'description' => 'Additional notes' ],
    ];
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->insert( $table, [
        'slug'               => 'default',
        'name'               => __( 'Default', 'axiachat-ai' ),
        'description'        => __( 'Default lead capture list', 'axiachat-ai' ),
        'fields'             => wp_json_encode( $default_fields ),
        'destination'        => $destination,
        'destination_config' => wp_json_encode( $dest_config ),
        'notify_enabled'     => ! empty( $settings['notify_enabled'] ) ? 1 : 0,
        'notify_email'       => $settings['notify_email'] ?? '',
        'email_subject'      => $settings['email_subject'] ?? '',
        'email_body'         => $settings['email_body'] ?? '',
        'webhook_url'        => get_option( 'aichat_leads_webhook_url', '' ),
        'webhook_enabled'    => ! empty( get_option( 'aichat_leads_webhook_url', '' ) ) ? 1 : 0,
        'tool_enabled'       => 1,
        'form_enabled'       => 1,
        'assigned_bots'      => 'all',
        'tool_description'   => '',
        'store_ip'           => ! empty( $settings['store_ip'] ) ? 1 : 0,
        'retention_days'     => (int) ( $settings['retention_days'] ?? 0 ),
        'status'             => 'active',
        'created_at'         => $now,
        'updated_at'         => $now,
    ] );
    
    // Assign existing leads to new default list
    $list_id = $wpdb->insert_id;
    if ( $list_id ) {
        $leads_table = $wpdb->prefix . 'aichat_leads';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "UPDATE $leads_table SET list_id = %d WHERE list_id = 0", $list_id ) );
    }
    
    if ( function_exists( 'aichat_log_debug' ) ) {
        aichat_log_debug( '[Leads] Migrated existing settings to default lead list', [
            'list_id'     => $list_id,
            'destination' => $destination,
        ] );
    }
}

// Hook table creation to plugin activation
add_action( 'aichat_after_tables_created', 'aichat_leads_create_table' );
add_action( 'aichat_after_tables_created', 'aichat_leads_create_lists_table' );
add_action( 'aichat_after_tables_created', 'aichat_leads_maybe_migrate_to_lists' );

// Create or upgrade tables when needed (existing installations + schema changes)
add_action( 'admin_init', function() {
    $current_schema = '3'; // Bump when schema changes
    $stored_schema  = get_option( 'aichat_leads_schema_version', '0' );
    
    if ( $stored_schema === $current_schema ) {
        return; // Schema is up-to-date
    }
    
    // Re-run dbDelta for both tables (creates missing tables AND adds new columns)
    aichat_leads_create_table();
    aichat_leads_create_lists_table();
    
    // Explicit fallback: add list_id column if dbDelta missed it
    global $wpdb;
    $leads_table = $wpdb->prefix . 'aichat_leads';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $col = $wpdb->get_var( $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'list_id'",
        $leads_table
    ) );
    if ( ! $col ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "ALTER TABLE `{$leads_table}` ADD COLUMN `list_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `id`" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "ALTER TABLE `{$leads_table}` ADD KEY `list_id` (`list_id`)" );
    }
    
    // Explicit fallback: add assigned_bots column to lead_lists if dbDelta missed it
    $lists_table = $wpdb->prefix . 'aichat_lead_lists';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $col2 = $wpdb->get_var( $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'assigned_bots'",
        $lists_table
    ) );
    if ( ! $col2 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "ALTER TABLE `{$lists_table}` ADD COLUMN `assigned_bots` VARCHAR(500) NOT NULL DEFAULT 'all' AFTER `form_enabled`" );
    }
    
    // Explicit fallback: add form appearance columns to lead_lists
    $form_cols = [ 'form_mode', 'form_header', 'form_submit_text', 'form_success_msg', 'form_bg_color', 'form_btn_color' ];
    foreach ( $form_cols as $fc ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $lists_table, $fc
        ) );
        if ( ! $exists ) {
            $type = 'TEXT NULL';
            if ( $fc === 'form_mode' )        $type = "VARCHAR(20) NOT NULL DEFAULT 'full'";
            if ( $fc === 'form_submit_text' ) $type = 'VARCHAR(200) NULL';
            if ( $fc === 'form_success_msg' ) $type = 'VARCHAR(500) NULL';
            if ( $fc === 'form_bg_color' )    $type = 'VARCHAR(20) NULL';
            if ( $fc === 'form_btn_color' )   $type = 'VARCHAR(20) NULL';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( "ALTER TABLE `{$lists_table}` ADD COLUMN `{$fc}` {$type}" );
        }
    }
    
    update_option( 'aichat_leads_schema_version', $current_schema );
    
    // Run migration if needed
    aichat_leads_maybe_migrate_to_lists();
}, 5 );

// Google Sheets OAuth callback handler
add_action( 'admin_init', 'aichat_leads_gsheets_oauth_callback', 10 );
function aichat_leads_gsheets_oauth_callback() {
    // Check if this is a Google Sheets OAuth callback
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from Google cannot include nonce.
    if ( ! isset( $_GET['gsheets_callback'] ) || ! isset( $_GET['page'] ) || $_GET['page'] !== 'aichat-leads' ) {
        return;
    }
    
    // Verify we have a code
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from Google.
    if ( ! isset( $_GET['code'] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth error from Google.
        if ( isset( $_GET['error'] ) ) {
            add_action( 'admin_notices', function() {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth error from Google.
                $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Google Sheets connection failed: ', 'axiachat-ai' ) . esc_html( $error ) . '</p></div>';
            } );
        }
        return;
    }
    
    // Verify state for CSRF protection
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback; state verified below as CSRF protection.
    $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
    $saved_state = get_transient( 'aichat_gsheets_oauth_state' );
    
    // Try to decode state as JSON (new format from central redirect)
    $state_data = json_decode( base64_decode( $state ), true );
    $state_nonce = is_array( $state_data ) && isset( $state_data['nonce'] ) ? $state_data['nonce'] : $state;
    
    if ( ! $state_nonce || ! $saved_state || ! hash_equals( $saved_state, $state_nonce ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid OAuth state. Please try again.', 'axiachat-ai' ) . '</p></div>';
        } );
        return;
    }
    
    // Exchange code for tokens
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from Google.
    $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
    $result = AIChat_Leads_GSheets_OAuth::exchange_code( $code );
    
    if ( is_wp_error( $result ) ) {
        add_action( 'admin_notices', function() use ( $result ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to connect: ', 'axiachat-ai' ) . esc_html( $result->get_error_message() ) . '</p></div>';
        } );
        return;
    }
    
    // Success - redirect back to the page the user was on (or settings tab as fallback)
    delete_transient( 'aichat_gsheets_oauth_state' );
    $redirect = admin_url( 'admin.php?page=aichat-leads&tab=settings&gsheets_connected=1' );
    if ( is_array( $state_data ) && ! empty( $state_data['origin_url'] ) ) {
        $origin = esc_url_raw( $state_data['origin_url'] );
        // Validate it's a local admin URL
        if ( strpos( $origin, admin_url() ) === 0 ) {
            $redirect = add_query_arg( 'gsheets_connected', '1', $origin );
        }
    }
    wp_safe_redirect( $redirect );
    exit;
}
