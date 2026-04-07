<?php

/**
 * Leads Admin AJAX Handlers
 * 
 * @package AIChat
 * @subpackage Leads
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Save settings via AJAX
 */
add_action( 'wp_ajax_aichat_leads_save_settings', 'aichat_leads_ajax_save_settings' );
function aichat_leads_ajax_save_settings() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    // Validate destination
    $valid_destinations = [
        'internal',
        'google_sheets',
        'cpt',
        'cf7',
        'wpforms'
    ];
    $destination = ( isset( $_POST['destination'] ) ? sanitize_key( $_POST['destination'] ) : 'internal' );
    if ( !in_array( $destination, $valid_destinations, true ) ) {
        $destination = 'internal';
    }
    $settings = [
        'enabled'        => !empty( $_POST['enabled'] ),
        'destination'    => $destination,
        'store_ip'       => !empty( $_POST['store_ip'] ),
        'retention_days' => ( isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 0 ),
        'notify_enabled' => !empty( $_POST['notify_enabled'] ),
        'notify_email'   => ( isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '' ),
    ];
    $settings['cf7_form_id'] = ( isset( $_POST['cf7_form_id'] ) ? absint( $_POST['cf7_form_id'] ) : 0 );
    $settings['wpforms_form_id'] = ( isset( $_POST['wpforms_form_id'] ) ? absint( $_POST['wpforms_form_id'] ) : 0 );
    AIChat_Leads_Manager::update_settings( $settings );
    // Save Google Sheets settings separately
    if ( isset( $_POST['gsheets_spreadsheet_id'] ) ) {
        update_option( 'aichat_leads_gsheets_spreadsheet_id', sanitize_text_field( wp_unslash( $_POST['gsheets_spreadsheet_id'] ) ) );
    }
    if ( isset( $_POST['gsheets_sheet_name'] ) ) {
        update_option( 'aichat_leads_gsheets_sheet_name', sanitize_text_field( wp_unslash( $_POST['gsheets_sheet_name'] ) ) );
    }
    // Save Webhook URL
    if ( isset( $_POST['webhook_url'] ) ) {
        update_option( 'aichat_leads_webhook_url', esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) );
    }
    wp_send_json_success( [
        'message' => __( 'Settings saved successfully.', 'axiachat-ai' ),
    ] );
}

/**
 * Save email template via AJAX
 */
add_action( 'wp_ajax_aichat_leads_save_email_template', 'aichat_leads_ajax_save_email_template' );
function aichat_leads_ajax_save_email_template() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $email_subject = ( isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '' );
    $email_body = ( isset( $_POST['email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_body'] ) ) : '' );
    $settings = AIChat_Leads_Manager::get_settings();
    $settings['email_subject'] = $email_subject;
    $settings['email_body'] = $email_body;
    AIChat_Leads_Manager::update_settings( $settings );
    wp_send_json_success( [
        'message' => __( 'Email template saved successfully.', 'axiachat-ai' ),
    ] );
}

/**
 * Get lead detail via AJAX
 */
add_action( 'wp_ajax_aichat_leads_get_lead', 'aichat_leads_ajax_get_lead' );
function aichat_leads_ajax_get_lead() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $id = ( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
    if ( !$id ) {
        wp_send_json_error( [
            'message' => 'Invalid lead ID',
        ] );
    }
    $lead = AIChat_Leads_Manager::get_lead( $id );
    if ( !$lead ) {
        wp_send_json_error( [
            'message' => 'Lead not found',
        ] );
    }
    // Parse campos_extra if JSON
    if ( !empty( $lead['campos_extra'] ) ) {
        $lead['campos_extra'] = json_decode( $lead['campos_extra'], true );
    }
    wp_send_json_success( [
        'lead' => $lead,
    ] );
}

/**
 * Update lead status via AJAX
 */
add_action( 'wp_ajax_aichat_leads_update_status', 'aichat_leads_ajax_update_status' );
function aichat_leads_ajax_update_status() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $id = ( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
    $status = ( isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '' );
    if ( !$id || !$status ) {
        wp_send_json_error( [
            'message' => 'Invalid parameters',
        ] );
    }
    $result = AIChat_Leads_Manager::update_status( $id, $status );
    if ( $result !== false ) {
        wp_send_json_success( [
            'message' => __( 'Status updated.', 'axiachat-ai' ),
        ] );
    }
    wp_send_json_error( [
        'message' => 'Update failed',
    ] );
}

/**
 * Delete lead via AJAX
 */
add_action( 'wp_ajax_aichat_leads_delete', 'aichat_leads_ajax_delete' );
function aichat_leads_ajax_delete() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $id = ( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
    if ( !$id ) {
        wp_send_json_error( [
            'message' => 'Invalid lead ID',
        ] );
    }
    $result = AIChat_Leads_Manager::delete( $id );
    if ( $result ) {
        wp_send_json_success( [
            'message' => __( 'Lead deleted.', 'axiachat-ai' ),
        ] );
    }
    wp_send_json_error( [
        'message' => 'Delete failed',
    ] );
}

/**
 * Bulk delete leads via AJAX
 */
add_action( 'wp_ajax_aichat_leads_bulk_delete', 'aichat_leads_ajax_bulk_delete' );
function aichat_leads_ajax_bulk_delete() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $ids = ( isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : [] );
    if ( empty( $ids ) ) {
        wp_send_json_error( [
            'message' => 'No leads selected',
        ] );
    }
    $deleted = AIChat_Leads_Manager::bulk_delete( $ids );
    wp_send_json_success( [
        'message' => sprintf( 
            /* translators: %d: number of deleted leads */
            __( '%d leads deleted.', 'axiachat-ai' ),
            $deleted
         ),
    ] );
}

/**
 * Export leads to CSV via AJAX
 */
add_action( 'wp_ajax_aichat_leads_export', 'aichat_leads_ajax_export' );
function aichat_leads_ajax_export() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied' );
    }
    $args = [];
    if ( !empty( $_GET['estado'] ) ) {
        $args['estado'] = sanitize_key( $_GET['estado'] );
    }
    if ( !empty( $_GET['s'] ) ) {
        $args['search'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
    }
    $csv = AIChat_Leads_Manager::export_csv( $args );
    $filename = 'leads-export-' . gmdate( 'Y-m-d-His' ) . '.csv';
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );
    // Add BOM for Excel UTF-8 compatibility
    echo "﻿";
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- BOM bytes for UTF-8 CSV.
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw CSV binary download, Content-Type: text/csv, not HTML context.
    echo $csv;
    exit;
}

/**\n * CF7 / WPForms / Google Sheets AJAX handlers (integrations)\n */
add_action( 'wp_ajax_aichat_leads_get_cf7_fields', 'aichat_leads_ajax_get_cf7_fields' );
add_action( 'wp_ajax_aichat_leads_get_wpforms_fields', 'aichat_leads_ajax_get_wpforms_fields' );
add_action( 'wp_ajax_aichat_leads_gsheets_disconnect', 'aichat_leads_ajax_gsheets_disconnect' );
add_action( 'wp_ajax_aichat_leads_gsheets_test', 'aichat_leads_ajax_gsheets_test' );
add_action( 'wp_ajax_aichat_leads_gsheets_save', 'aichat_leads_ajax_gsheets_save' );
function aichat_leads_ajax_get_cf7_fields() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $form_id = ( isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0 );
    if ( !$form_id || !class_exists( 'AIChat_Leads_Adapter_CF7' ) ) {
        wp_send_json_error( [
            'message' => 'Invalid form',
        ] );
    }
    $fields = AIChat_Leads_Adapter_CF7::get_form_fields( $form_id );
    wp_send_json_success( [
        'fields' => $fields,
    ] );
}

function aichat_leads_ajax_get_wpforms_fields() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $form_id = ( isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0 );
    if ( !$form_id || !class_exists( 'AIChat_Leads_Adapter_WPForms' ) ) {
        wp_send_json_error( [
            'message' => 'Invalid form',
        ] );
    }
    $fields = AIChat_Leads_Adapter_WPForms::get_form_fields( $form_id );
    wp_send_json_success( [
        'fields' => $fields,
    ] );
}

function aichat_leads_ajax_gsheets_disconnect() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    AIChat_Leads_GSheets_OAuth::disconnect();
    delete_option( 'aichat_leads_gsheets_spreadsheet_id' );
    delete_option( 'aichat_leads_gsheets_sheet_name' );
    wp_send_json_success( [
        'message' => __( 'Disconnected from Google Sheets.', 'axiachat-ai' ),
    ] );
}

function aichat_leads_ajax_gsheets_test() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $spreadsheet_id = ( isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '' );
    if ( empty( $spreadsheet_id ) ) {
        wp_send_json_error( [
            'message' => __( 'Please enter a spreadsheet ID.', 'axiachat-ai' ),
        ] );
    }
    $result = AIChat_Leads_GSheets_Client::test_connection( $spreadsheet_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [
            'message' => $result->get_error_message(),
        ] );
    }
    wp_send_json_success( [
        'message' => sprintf( 
            /* translators: %s: Spreadsheet title */
            __( 'Connected to: %s', 'axiachat-ai' ),
            $result['title']
         ),
        'title'   => $result['title'],
        'sheets'  => $result['sheets'],
    ] );
}

function aichat_leads_ajax_gsheets_save() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $spreadsheet_id = ( isset( $_POST['spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ) ) : '' );
    $sheet_name = ( isset( $_POST['sheet_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet_name'] ) ) : 'Sheet1' );
    update_option( 'aichat_leads_gsheets_spreadsheet_id', $spreadsheet_id );
    update_option( 'aichat_leads_gsheets_sheet_name', $sheet_name );
    wp_send_json_success( [
        'message' => __( 'Google Sheets settings saved.', 'axiachat-ai' ),
    ] );
}

// ============================================================
// Lead Lists CRUD AJAX handlers
// ============================================================
/**
 * Get all lead lists
 */
add_action( 'wp_ajax_aichat_lead_lists_get', 'aichat_lead_lists_ajax_get' );
function aichat_lead_lists_ajax_get() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $lists = AIChat_Leads_Manager::get_lists();
    $counts = AIChat_Leads_Manager::get_leads_count_by_list();
    foreach ( $lists as &$list ) {
        $list['leads_count'] = $counts[(int) $list['id']] ?? 0;
    }
    wp_send_json_success( [
        'lists' => $lists,
    ] );
}

/**
 * Get a single lead list
 */
add_action( 'wp_ajax_aichat_lead_lists_get_one', 'aichat_lead_lists_ajax_get_one' );
function aichat_lead_lists_ajax_get_one() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $id = ( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
    if ( !$id ) {
        wp_send_json_error( [
            'message' => 'Invalid list ID',
        ] );
    }
    $list = AIChat_Leads_Manager::get_list( $id );
    if ( !$list ) {
        wp_send_json_error( [
            'message' => 'List not found',
        ] );
    }
    wp_send_json_success( [
        'list' => $list,
    ] );
}

/**
 * Create a new lead list
 */
add_action( 'wp_ajax_aichat_lead_lists_create', 'aichat_lead_lists_ajax_create' );
function aichat_lead_lists_ajax_create() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $data = aichat_lead_lists_parse_form_data();
    $result = AIChat_Leads_Manager::create_list( $data );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [
            'message' => $result->get_error_message(),
        ] );
    }
    wp_send_json_success( [
        'message' => __( 'Lead list created successfully.', 'axiachat-ai' ),
        'list_id' => $result,
    ] );
}

/**
 * Update a lead list
 */
add_action( 'wp_ajax_aichat_lead_lists_update', 'aichat_lead_lists_ajax_update' );
function aichat_lead_lists_ajax_update() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $id = ( isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0 );
    if ( !$id ) {
        wp_send_json_error( [
            'message' => 'Invalid list ID',
        ] );
    }
    $data = aichat_lead_lists_parse_form_data();
    $result = AIChat_Leads_Manager::update_list( $id, $data );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [
            'message' => $result->get_error_message(),
        ] );
    }
    wp_send_json_success( [
        'message' => __( 'Lead list updated successfully.', 'axiachat-ai' ),
    ] );
}

/**
 * Delete a lead list
 */
add_action( 'wp_ajax_aichat_lead_lists_delete', 'aichat_lead_lists_ajax_delete' );
function aichat_lead_lists_ajax_delete() {
    check_ajax_referer( 'aichat_leads_admin', 'nonce' );
    if ( !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied',
        ] );
    }
    $id = ( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
    if ( !$id ) {
        wp_send_json_error( [
            'message' => 'Invalid list ID',
        ] );
    }
    $result = AIChat_Leads_Manager::delete_list( $id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [
            'message' => $result->get_error_message(),
        ] );
    }
    wp_send_json_success( [
        'message' => __( 'Lead list deleted.', 'axiachat-ai' ),
    ] );
}

/**
 * Parse lead list form data from $_POST
 *
 * Nonce is verified in calling functions (aichat_lead_lists_ajax_create / _update).
 *
 * @return array Parsed data
 */
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in callers.
function aichat_lead_lists_parse_form_data() {
    $data = [];
    // Basic fields
    $data['slug'] = ( isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '' );
    $data['name'] = ( isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '' );
    $data['description'] = ( isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '' );
    $data['destination'] = ( isset( $_POST['destination'] ) ? sanitize_key( $_POST['destination'] ) : 'internal' );
    // Boolean flags
    $data['tool_enabled'] = !empty( $_POST['tool_enabled'] );
    $data['form_enabled'] = !empty( $_POST['form_enabled'] );
    $data['notify_enabled'] = !empty( $_POST['notify_enabled'] );
    $data['webhook_enabled'] = !empty( $_POST['webhook_enabled'] );
    $data['store_ip'] = !empty( $_POST['store_ip'] );
    // String fields
    $data['notify_email'] = ( isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '' );
    $data['email_subject'] = ( isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '' );
    $data['email_body'] = ( isset( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : '' );
    $data['webhook_url'] = ( isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '' );
    $data['tool_description'] = ( isset( $_POST['tool_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tool_description'] ) ) : '' );
    $data['retention_days'] = ( isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 0 );
    $data['status'] = ( isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'active' );
    // Bot assignment (slug or 'all')
    $data['assigned_bots'] = ( isset( $_POST['assigned_bots'] ) ? sanitize_key( $_POST['assigned_bots'] ) : 'all' );
    // Form appearance
    $data['form_mode'] = ( isset( $_POST['form_mode'] ) && $_POST['form_mode'] === 'compact' ? 'compact' : 'full' );
    $data['form_header'] = ( isset( $_POST['form_header'] ) ? wp_kses_post( wp_unslash( $_POST['form_header'] ) ) : '' );
    $data['form_submit_text'] = ( isset( $_POST['form_submit_text'] ) ? sanitize_text_field( wp_unslash( $_POST['form_submit_text'] ) ) : '' );
    $data['form_success_msg'] = ( isset( $_POST['form_success_msg'] ) ? sanitize_text_field( wp_unslash( $_POST['form_success_msg'] ) ) : '' );
    $data['form_bg_color'] = ( isset( $_POST['form_bg_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['form_bg_color'] ) ) : '' );
    $data['form_btn_color'] = ( isset( $_POST['form_btn_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['form_btn_color'] ) ) : '' );
    // Fields (JSON array from POST)
    if ( isset( $_POST['fields'] ) ) {
        if ( is_string( $_POST['fields'] ) ) {
            $raw_fields = aichat_json_decode_post( 'fields' );
        } else {
            $raw_fields = map_deep( wp_unslash( $_POST['fields'] ), 'sanitize_text_field' );
        }
        if ( is_array( $raw_fields ) ) {
            $fields = [];
            foreach ( $raw_fields as $f ) {
                $fields[] = [
                    'key'         => sanitize_key( $f['key'] ?? '' ),
                    'label'       => sanitize_text_field( $f['label'] ?? '' ),
                    'type'        => sanitize_key( $f['type'] ?? 'text' ),
                    'required'    => !empty( $f['required'] ),
                    'description' => sanitize_text_field( $f['description'] ?? '' ),
                ];
            }
            $data['fields'] = array_filter( $fields, function ( $f ) {
                return !empty( $f['key'] ) && !empty( $f['label'] );
            } );
        }
    }
    // Destination config (JSON object from POST)
    if ( isset( $_POST['destination_config'] ) ) {
        if ( is_string( $_POST['destination_config'] ) ) {
            $raw_config = aichat_json_decode_post( 'destination_config' );
        } else {
            $raw_config = map_deep( wp_unslash( $_POST['destination_config'] ), 'sanitize_text_field' );
        }
        if ( is_array( $raw_config ) ) {
            $config = [];
            foreach ( $raw_config as $k => $v ) {
                $config[sanitize_key( $k )] = ( is_string( $v ) ? sanitize_text_field( $v ) : $v );
            }
            $data['destination_config'] = $config;
        }
    }
    return $data;
}

// phpcs:enable WordPress.Security.NonceVerification.Missing
/* ========================================================================
 * Frontend Lead Form Submission (used by show_form inline widget)
 * ======================================================================== */
add_action( 'wp_ajax_aichat_lead_form_submit', 'aichat_lead_form_ajax_submit' );
add_action( 'wp_ajax_nopriv_aichat_lead_form_submit', 'aichat_lead_form_ajax_submit' );
/**
 * Handle inline lead form submission from the chat widget.
 *
 * Expects: nonce (aichat_ajax), list_slug, session_id, bot_slug, and field values.
 */
function aichat_lead_form_ajax_submit() {
    // Verify nonce (same nonce used by the main chat AJAX)
    if ( !check_ajax_referer( 'aichat_ajax', 'nonce', false ) ) {
        wp_send_json_error( [
            'message' => __( 'Security check failed.', 'axiachat-ai' ),
        ], 403 );
    }
    $list_slug = ( isset( $_POST['list_slug'] ) ? sanitize_key( $_POST['list_slug'] ) : '' );
    $session_id = ( isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '' );
    $bot_slug = ( isset( $_POST['bot_slug'] ) ? sanitize_key( $_POST['bot_slug'] ) : '' );
    if ( empty( $list_slug ) ) {
        wp_send_json_error( [
            'message' => __( 'Invalid form configuration.', 'axiachat-ai' ),
        ], 400 );
    }
    $list = AIChat_Leads_Manager::get_list_by_slug( $list_slug );
    if ( !$list || $list['status'] !== 'active' ) {
        wp_send_json_error( [
            'message' => __( 'This form is no longer available.', 'axiachat-ai' ),
        ], 404 );
    }
    // Validate required fields and collect data
    $fields = $list['fields'];
    $lead_data = [];
    $errors = [];
    foreach ( $fields as $f ) {
        $key = $f['key'] ?? '';
        $value = ( isset( $_POST['field_' . $key] ) ? sanitize_text_field( wp_unslash( $_POST['field_' . $key] ) ) : '' );
        // Email validation
        if ( ($f['type'] ?? '') === 'email' && $value !== '' ) {
            $value = sanitize_email( $value );
            if ( !is_email( $value ) ) {
                /* translators: %s: field label */
                $errors[] = sprintf( __( '%s is not a valid email address.', 'axiachat-ai' ), $f['label'] ?? $key );
                continue;
            }
        }
        if ( !empty( $f['required'] ) && $value === '' ) {
            /* translators: %s: field label */
            $errors[] = sprintf( __( '%s is required.', 'axiachat-ai' ), $f['label'] ?? $key );
        }
        $lead_data[$key] = $value;
    }
    if ( !empty( $errors ) ) {
        wp_send_json_error( [
            'message' => implode( ' ', $errors ),
        ], 422 );
    }
    // Build context for save
    $context = [
        'session_id' => $session_id,
        'bot_slug'   => $bot_slug,
        'source'     => 'inline_form',
        'page_url'   => ( isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '' ),
    ];
    $result = AIChat_Leads_Manager::save_to_list( $lead_data, $context, (int) $list['id'] );
    if ( !empty( $result['ok'] ) ) {
        wp_send_json_success( [
            'message' => $result['message'] ?? __( 'Thank you! Your information has been saved.', 'axiachat-ai' ),
        ] );
    } else {
        wp_send_json_error( [
            'message' => $result['message'] ?? __( 'There was a problem saving your information.', 'axiachat-ai' ),
        ], 500 );
    }
}
