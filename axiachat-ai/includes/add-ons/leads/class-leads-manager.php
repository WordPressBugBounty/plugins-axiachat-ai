<?php

/**
 * Leads Manager - Core class for lead handling
 * 
 * Manages saving leads to the configured destination.
 * 
 * @package AIChat
 * @subpackage Leads
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
class AIChat_Leads_Manager {
    /**
     * Registered adapters
     */
    private static $adapters = [];

    /**
     * Register an adapter
     */
    public static function register_adapter( $id, $adapter ) {
        self::$adapters[$id] = $adapter;
    }

    /**
     * Get registered adapters
     */
    public static function get_adapters() {
        return self::$adapters;
    }

    /**
     * Get available destinations based on active plugins
     */
    public static function get_available_destinations() {
        $destinations = [
            'internal' => [
                'id'          => 'internal',
                'name'        => __( 'Internal Database', 'axiachat-ai' ),
                'description' => __( 'Save leads to the plugin\'s internal table.', 'axiachat-ai' ),
                'available'   => true,
                'icon'        => 'dashicons-database',
            ],
        ];
        $destinations['google_sheets'] = [
            'id'          => 'google_sheets',
            'name'        => 'Google Sheets',
            'description' => __( 'Save leads to a Google Spreadsheet.', 'axiachat-ai' ),
            'available'   => true,
            'icon'        => 'dashicons-media-spreadsheet',
        ];
        $destinations['cpt'] = [
            'id'          => 'cpt',
            'name'        => __( 'WordPress (Custom Post Type)', 'axiachat-ai' ),
            'description' => __( 'Save leads as a custom post type for native WP integration.', 'axiachat-ai' ),
            'available'   => true,
            'icon'        => 'dashicons-wordpress',
        ];
        $destinations['cf7'] = [
            'id'          => 'cf7',
            'name'        => 'Contact Form 7',
            'description' => __( 'Submit leads to a Contact Form 7 form.', 'axiachat-ai' ),
            'available'   => class_exists( 'WPCF7' ),
            'icon'        => 'dashicons-email-alt',
        ];
        $destinations['wpforms'] = [
            'id'          => 'wpforms',
            'name'        => 'WPForms',
            'description' => __( 'Submit leads to a WPForms form.', 'axiachat-ai' ),
            'available'   => function_exists( 'wpforms' ),
            'icon'        => 'dashicons-feedback',
        ];
        return apply_filters( 'aichat_leads_destinations', $destinations );
    }

    /**
     * Get settings
     */
    public static function get_settings() {
        $defaults = [
            'enabled'        => true,
            'destination'    => 'internal',
            'store_ip'       => false,
            'notify_email'   => '',
            'notify_enabled' => false,
            'retention_days' => 0,
        ];
        $defaults['cf7_form_id'] = 0;
        $defaults['wpforms_form_id'] = 0;
        $settings = get_option( 'aichat_leads_settings', [] );
        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Update settings
     */
    public static function update_settings( $settings ) {
        return update_option( 'aichat_leads_settings', $settings );
    }

    /**
     * Save a lead
     * 
     * @param array $data Lead data from the tool call (fields depend on destination)
     * @param array $context Execution context (session_id, bot_slug, etc.)
     * @return array Result with ok status and lead_id or error
     */
    public static function save( $data, $context = [] ) {
        $settings = self::get_settings();
        $destination = $settings['destination'] ?? 'internal';
        // Integrations check
        $pro_destinations = [
            'google_sheets',
            'cpt',
            'cf7',
            'wpforms'
        ];
        if ( in_array( $destination, $pro_destinations, true ) && !self::has_integrations_license() ) {
            $destination = 'internal';
            if ( function_exists( 'aichat_log_debug' ) ) {
                aichat_log_debug( '[Leads] Integrations destination blocked (Standard+ required), falling back to internal', [
                    'requested' => $settings['destination'],
                ] );
            }
        }
        $limit_check = self::check_usage_limit();
        if ( is_wp_error( $limit_check ) ) {
            return [
                'ok'      => false,
                'error'   => 'limit_reached',
                'message' => $limit_check->get_error_message(),
            ];
        }
        // Validate we have at least one identifier (accept both Spanish and English keys)
        if ( $destination === 'internal' ) {
            $has_identifier = !empty( $data['nombre'] ) || !empty( $data['name'] ) || !empty( $data['email'] ) || !empty( $data['telefono'] ) || !empty( $data['phone'] );
            if ( !$has_identifier ) {
                return [
                    'ok'      => false,
                    'error'   => 'no_identifier',
                    'message' => __( 'At least one identifier (name, email, or phone) is required to save a lead.', 'axiachat-ai' ),
                ];
            }
        }
        // For CPT, also validate identifiers (accept both Spanish and English keys)
        if ( $destination === 'cpt' ) {
            $has_identifier = !empty( $data['nombre'] ) || !empty( $data['name'] ) || !empty( $data['email'] ) || !empty( $data['telefono'] ) || !empty( $data['phone'] );
            if ( !$has_identifier ) {
                return [
                    'ok'      => false,
                    'error'   => 'no_identifier',
                    'message' => __( 'At least one identifier (name, email, or phone) is required to save a lead.', 'axiachat-ai' ),
                ];
            }
        }
        // For CF7/WPForms, just need some data
        if ( in_array( $destination, ['cf7', 'wpforms'], true ) && empty( $data ) ) {
            return [
                'ok'      => false,
                'error'   => 'no_data',
                'message' => __( 'No data provided to save.', 'axiachat-ai' ),
            ];
        }
        // Build lead data based on destination
        $lead = self::build_lead_data(
            $data,
            $destination,
            $settings,
            $context
        );
        // Debug: log built lead data
        if ( function_exists( 'aichat_log_debug' ) ) {
            aichat_log_debug( '[Leads] Built lead data', [
                'destination'  => $destination,
                'lead_keys'    => array_keys( $lead ),
                'has_fields'   => isset( $lead['fields'] ),
                'fields_count' => ( isset( $lead['fields'] ) ? count( $lead['fields'] ) : 0 ),
                'input_data'   => $data,
            ] );
        }
        // Save to the selected destination
        $result = [
            'ok'    => false,
            'error' => 'no_adapter',
        ];
        if ( isset( self::$adapters[$destination] ) ) {
            $adapter = self::$adapters[$destination];
            $result = $adapter->save( $lead, $settings );
            // Debug: log adapter result
            if ( function_exists( 'aichat_log_debug' ) ) {
                aichat_log_debug( '[Leads] Adapter result', [
                    'destination' => $destination,
                    'result'      => $result,
                ] );
            }
        } else {
            if ( function_exists( 'aichat_log_debug' ) ) {
                aichat_log_debug( '[Leads] No adapter found', [
                    'destination'        => $destination,
                    'available_adapters' => array_keys( self::$adapters ),
                ] );
            }
        }
        $success = !empty( $result['ok'] );
        $lead_id = $result['lead_id'] ?? null;
        // Track external leads count
        if ( $success && in_array( $destination, ['cf7', 'wpforms'], true ) ) {
            self::increment_external_count();
        }
        // Send notification email if enabled
        if ( $success && $settings['notify_enabled'] ) {
            $notify_email = ( !empty( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' ) );
            if ( $notify_email ) {
                self::send_notification( $lead, $settings );
            }
        }
        // Send webhook if configured (independent of destination)
        if ( $success ) {
            self::send_webhook( $lead, $lead_id, $context );
        }
        // Log the capture
        if ( function_exists( 'aichat_log_debug' ) ) {
            aichat_log_debug( '[Leads] Lead saved', [
                'destination' => $destination,
                'success'     => $success,
                'data_keys'   => array_keys( $data ),
            ] );
        }
        if ( $success ) {
            return [
                'ok'      => true,
                'lead_id' => $lead_id,
                'message' => __( 'Contact information saved successfully. We will get in touch soon.', 'axiachat-ai' ),
            ];
        }
        return [
            'ok'      => false,
            'error'   => 'save_failed',
            'message' => __( 'There was a problem saving the information. Please try again.', 'axiachat-ai' ),
        ];
    }

    /**
     * Get leads from internal storage
     */
    public static function get_leads( $args = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_leads';
        $defaults = [
            'per_page'  => 20,
            'page'      => 1,
            'orderby'   => 'created_at',
            'order'     => 'DESC',
            'estado'    => '',
            'bot_slug'  => '',
            'search'    => '',
            'date_from' => '',
            'date_to'   => '',
            'list_id'   => '',
        ];
        $args = wp_parse_args( $args, $defaults );
        $where = ['1=1'];
        $params = [];
        if ( !empty( $args['estado'] ) ) {
            $where[] = 'estado = %s';
            $params[] = $args['estado'];
        }
        if ( !empty( $args['bot_slug'] ) ) {
            $where[] = 'bot_slug = %s';
            $params[] = $args['bot_slug'];
        }
        if ( !empty( $args['search'] ) ) {
            $search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(nombre LIKE %s OR email LIKE %s OR telefono LIKE %s OR empresa LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        if ( !empty( $args['date_from'] ) ) {
            $where[] = 'DATE(created_at) >= %s';
            $params[] = $args['date_from'];
        }
        if ( !empty( $args['date_to'] ) ) {
            $where[] = 'DATE(created_at) <= %s';
            $params[] = $args['date_to'];
        }
        if ( $args['list_id'] !== '' && $args['list_id'] !== null ) {
            $where[] = 'list_id = %d';
            $params[] = (int) $args['list_id'];
        }
        $where_sql = implode( ' AND ', $where );
        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        if ( !empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count_sql = $wpdb->prepare( $count_sql, $params );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var( $count_sql );
        // Get leads
        $orderby = ( in_array( $args['orderby'], [
            'id',
            'nombre',
            'email',
            'estado',
            'created_at'
        ], true ) ? $args['orderby'] : 'created_at' );
        $order = ( strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC' );
        $offset = ($args['page'] - 1) * $args['per_page'];
        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = $args['per_page'];
        $params[] = $offset;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $leads = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return [
            'leads'       => $leads,
            'total'       => $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => ceil( $total / $args['per_page'] ),
        ];
    }

    /**
     * Get a single lead by ID
     */
    public static function get_lead( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_leads';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $wpdb->get_row( 
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted plugin table name.
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
         );
    }

    /**
     * Update lead status
     */
    public static function update_status( $id, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_leads';
        $valid_statuses = [
            'nuevo',
            'contactado',
            'convertido',
            'descartado'
        ];
        if ( !in_array( $status, $valid_statuses, true ) ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->update(
            $table,
            [
                'estado'     => $status,
                'updated_at' => current_time( 'mysql' ),
            ],
            [
                'id' => $id,
            ],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Delete a lead
     */
    public static function delete( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_leads';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->delete( $table, [
            'id' => $id,
        ], ['%d'] );
    }

    /**
     * Bulk delete leads
     */
    public static function bulk_delete( $ids ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_leads';
        $ids = array_map( 'intval', (array) $ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $wpdb->query( 
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table and $placeholders are trusted plugin values.
            $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids )
         );
    }

    /**
     * Export leads to CSV
     */
    public static function export_csv( $args = [] ) {
        $args['per_page'] = 9999;
        $result = self::get_leads( $args );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using php://temp memory stream for CSV generation, not actual filesystem.
        $output = fopen( 'php://temp', 'r+' );
        // Headers
        fputcsv( $output, [
            'ID',
            'Nombre',
            'Email',
            'Teléfono',
            'Empresa',
            'Interés',
            'Notas',
            'Bot',
            'Estado',
            'Fecha'
        ] );
        // Rows
        foreach ( $result['leads'] as $lead ) {
            fputcsv( $output, [
                $lead['id'],
                $lead['nombre'],
                $lead['email'],
                $lead['telefono'],
                $lead['empresa'],
                $lead['interes'],
                $lead['notas'],
                $lead['bot_slug'],
                $lead['estado'],
                $lead['created_at']
            ] );
        }
        rewind( $output );
        $csv = stream_get_contents( $output );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://temp memory stream.
        fclose( $output );
        return $csv;
    }

    /**
     * Get statistics
     */
    public static function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_leads';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $today = (int) $wpdb->get_var( 
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted plugin table name.
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = %s", current_time( 'Y-m-d' ) )
         );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $this_month = (int) $wpdb->get_var( $wpdb->prepare( 
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted plugin table name.
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
            current_time( 'Y-m-01 00:00:00' )
         ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $by_status = $wpdb->get_results( 
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted plugin table name.
            "SELECT estado, COUNT(*) as count FROM {$table} GROUP BY estado",
            ARRAY_A
         );
        $status_counts = [];
        foreach ( $by_status as $row ) {
            $status_counts[$row['estado']] = (int) $row['count'];
        }
        return [
            'total'      => $total,
            'today'      => $today,
            'this_month' => $this_month,
            'by_status'  => $status_counts,
        ];
    }

    /**
     * Get total leads count across all destinations
     * Used for plan limits
     * 
     * @return int Total number of leads saved
     */
    public static function get_total_leads_count() {
        $count = 0;
        // Count from internal table
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_leads';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $internal_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $count += $internal_count;
        // Count from CPT
        $cpt_count = wp_count_posts( 'aichat_lead' );
        if ( $cpt_count && isset( $cpt_count->publish ) ) {
            $count += (int) $cpt_count->publish;
        }
        // Track external destination leads via counter
        $external_count = (int) get_option( 'aichat_leads_external_count', 0 );
        $count += $external_count;
        return $count;
    }

    /**
     * Increment external leads counter
     */
    public static function increment_external_count() {
        $current = (int) get_option( 'aichat_leads_external_count', 0 );
        update_option( 'aichat_leads_external_count', $current + 1 );
    }

    /**
     * Get leads limit — unlimited.
     * 
     * @return int
     */
    public static function get_free_limit() {
        return 999999;
    }

    /**
     * Whether lead storage is unlimited.
     * 
     * @return bool Always true.
     */
    public static function has_pro_license() {
        return true;
    }

    /**
     * Check if integrations adapter is available.
     * 
     * @return bool True if integrations adapter is present
     */
    public static function has_integrations_license() {
        return true;
    }

    /**
     * Check usage limit before saving a lead
     * 
     * @return true Always allows saving (no limits)
     */
    public static function check_usage_limit() {
        return true;
    }

    /**
     * Get usage info for display
     * 
     * @return array Usage information
     */
    public static function get_usage_info() {
        $count = self::get_total_leads_count();
        return [
            'count'     => $count,
            'limit'     => 999999,
            'remaining' => 999999,
            'unlimited' => true,
            'percent'   => 0,
        ];
    }

    /**
     * Build lead data based on destination
     * 
     * @param array  $data        Raw data from tool call
     * @param string $destination Selected destination
     * @param array  $settings    Leads settings
     * @param array  $context     Execution context
     * @return array Formatted lead data
     */
    private static function build_lead_data(
        $data,
        $destination,
        $settings,
        $context
    ) {
        $lead = [
            'bot_slug'   => $context['bot_slug'] ?? '',
            'session_id' => $context['session_id'] ?? '',
            'ip_hash'    => ( !empty( $settings['store_ip'] ) ? self::hash_ip() : null ),
            'estado'     => 'nuevo',
            'created_at' => current_time( 'mysql' ),
        ];
        // For internal destination: use standard fields (accept both Spanish and English keys)
        if ( $destination === 'internal' ) {
            $lead['nombre'] = sanitize_text_field( $data['nombre'] ?? $data['name'] ?? $data['your-name'] ?? '' );
            $lead['email'] = sanitize_email( $data['email'] ?? $data['your-email'] ?? '' );
            $lead['telefono'] = sanitize_text_field( $data['telefono'] ?? $data['phone'] ?? $data['tel'] ?? $data['your-tel'] ?? '' );
            $lead['empresa'] = sanitize_text_field( $data['empresa'] ?? $data['company'] ?? '' );
            $lead['interes'] = sanitize_text_field( $data['interes'] ?? $data['interest'] ?? '' );
            $lead['notas'] = sanitize_textarea_field( $data['notas'] ?? $data['notes'] ?? $data['message'] ?? '' );
        }
        // For CPT: same standard fields (accept both Spanish and English keys)
        if ( $destination === 'cpt' ) {
            $lead['nombre'] = sanitize_text_field( $data['nombre'] ?? $data['name'] ?? $data['your-name'] ?? '' );
            $lead['email'] = sanitize_email( $data['email'] ?? $data['your-email'] ?? '' );
            $lead['telefono'] = sanitize_text_field( $data['telefono'] ?? $data['phone'] ?? $data['tel'] ?? $data['your-tel'] ?? '' );
            $lead['empresa'] = sanitize_text_field( $data['empresa'] ?? $data['company'] ?? '' );
            $lead['interes'] = sanitize_text_field( $data['interes'] ?? $data['interest'] ?? '' );
            $lead['notas'] = sanitize_textarea_field( $data['notas'] ?? $data['notes'] ?? $data['message'] ?? '' );
        } elseif ( in_array( $destination, ['cf7', 'wpforms'], true ) ) {
            $fields = [];
            foreach ( $data as $key => $value ) {
                if ( is_string( $value ) ) {
                    $fields[sanitize_key( $key )] = sanitize_text_field( $value );
                } elseif ( is_array( $value ) ) {
                    $fields[sanitize_key( $key )] = array_map( 'sanitize_text_field', $value );
                }
            }
            $lead['fields'] = $fields;
            $lead['nombre'] = sanitize_text_field( $data['name'] ?? $data['nombre'] ?? $data['your-name'] ?? '' );
            $lead['email'] = sanitize_email( $data['email'] ?? $data['your-email'] ?? '' );
            $lead['telefono'] = sanitize_text_field( $data['phone'] ?? $data['telefono'] ?? $data['tel'] ?? '' );
            $lead['empresa'] = sanitize_text_field( $data['company'] ?? $data['empresa'] ?? '' );
            $lead['interes'] = '';
            $lead['notas'] = '';
        }
        return $lead;
    }

    private static function hash_ip() {
        $ip = '';
        if ( !empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
        } elseif ( !empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
        } elseif ( !empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        return ( $ip ? hash( 'sha256', $ip . wp_salt() ) : null );
    }

    /**
     * Send notification email
     */
    private static function send_notification( $lead, $settings ) {
        $to = $settings['notify_email'];
        $subject = sprintf( 
            /* translators: %s: site name */
            __( '[%s] New Lead Captured', 'axiachat-ai' ),
            get_bloginfo( 'name' )
         );
        $message = sprintf(
            /* translators: 1: Name, 2: Email, 3: Phone, 4: Company, 5: Interest, 6: Notes, 7: Bot slug, 8: Date */
            __( "A new lead has been captured:\n\nName: %1\$s\nEmail: %2\$s\nPhone: %3\$s\nCompany: %4\$s\nInterest: %5\$s\nNotes: %6\$s\n\nBot: %7\$s\nDate: %8\$s", 'axiachat-ai' ),
            $lead['nombre'],
            ( $lead['email'] ?: '-' ),
            ( $lead['telefono'] ?: '-' ),
            ( $lead['empresa'] ?: '-' ),
            ( $lead['interes'] ?: '-' ),
            ( $lead['notas'] ?: '-' ),
            ( $lead['bot_slug'] ?: 'Unknown' ),
            current_time( 'mysql' )
        );
        wp_mail( $to, $subject, $message );
    }

    /**
     * Cleanup old leads based on retention policy
     */
    public static function cleanup_old_leads() {
        return 0;
    }

    /**
     * Send webhook notification
     * 
     * @param array $lead Lead data
     * @param int|null $lead_id Lead ID (if saved to internal DB)
     * @param array $context Execution context
     */
    private static function send_webhook( $lead, $lead_id = null, $context = [] ) {
        $webhook_url = get_option( 'aichat_leads_webhook_url', '' );
        if ( empty( $webhook_url ) ) {
            return;
        }
        // Build webhook payload
        $payload = [
            'name'     => $lead['nombre'] ?? '',
            'email'    => $lead['email'] ?? '',
            'phone'    => $lead['telefono'] ?? '',
            'company'  => $lead['empresa'] ?? '',
            'interest' => $lead['interes'] ?? '',
            'notes'    => $lead['notas'] ?? '',
            'bot'      => $lead['bot_slug'] ?? '',
            'date'     => current_time( 'c' ),
            'source'   => home_url(),
            'lead_id'  => $lead_id,
        ];
        // Allow filtering the payload
        $payload = apply_filters(
            'aichat_leads_webhook_payload',
            $payload,
            $lead,
            $context
        );
        // Send POST request
        $response = wp_remote_post( $webhook_url, [
            'timeout'   => 15,
            'headers'   => [
                'Content-Type' => 'application/json',
            ],
            'body'      => wp_json_encode( $payload ),
            'sslverify' => true,
        ] );
        // Log result
        if ( function_exists( 'aichat_log_debug' ) ) {
            if ( is_wp_error( $response ) ) {
                aichat_log_debug( '[Leads] Webhook error', [
                    'url'   => $webhook_url,
                    'error' => $response->get_error_message(),
                ] );
            } else {
                aichat_log_debug( '[Leads] Webhook sent', [
                    'url'    => $webhook_url,
                    'status' => wp_remote_retrieve_response_code( $response ),
                ] );
            }
        }
    }

    // ─── Lead Lists CRUD ──────────────────────────────────────────
    /**
     * Get all lead lists
     *
     * @param array $args Optional filters: status, search
     * @return array
     */
    public static function get_lists( $args = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_lead_lists';
        $where = ['1=1'];
        $params = [];
        if ( !empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        if ( !empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[] = '(name LIKE %s OR slug LIKE %s OR description LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC";
        if ( !empty( $params ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare( $sql, $params );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        // Decode JSON columns
        foreach ( $rows as &$row ) {
            $row['fields'] = ( json_decode( $row['fields'] ?? '[]', true ) ?: [] );
            $row['destination_config'] = ( json_decode( $row['destination_config'] ?? '{}', true ) ?: [] );
        }
        return $rows;
    }

    /**
     * Get a single lead list by ID
     *
     * @param int $list_id
     * @return array|null
     */
    public static function get_list( $list_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_lead_lists';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $list_id ), ARRAY_A );
        if ( !$row ) {
            return null;
        }
        $row['fields'] = ( json_decode( $row['fields'] ?? '[]', true ) ?: [] );
        $row['destination_config'] = ( json_decode( $row['destination_config'] ?? '{}', true ) ?: [] );
        return $row;
    }

    /**
     * Get a lead list by slug
     *
     * @param string $slug
     * @return array|null
     */
    public static function get_list_by_slug( $slug ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_lead_lists';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A );
        if ( !$row ) {
            return null;
        }
        $row['fields'] = ( json_decode( $row['fields'] ?? '[]', true ) ?: [] );
        $row['destination_config'] = ( json_decode( $row['destination_config'] ?? '{}', true ) ?: [] );
        return $row;
    }

    /**
     * Create a new lead list
     *
     * @param array $data List data
     * @return int|WP_Error List ID or error
     */
    public static function create_list( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_lead_lists';
        // Validate slug
        $slug = sanitize_key( $data['slug'] ?? '' );
        if ( empty( $slug ) || !preg_match( '/^[a-z0-9_]{2,64}$/', $slug ) ) {
            return new \WP_Error('invalid_slug', __( 'Slug must be 2-64 characters, lowercase alphanumeric and underscores only.', 'axiachat-ai' ));
        }
        // Check slug uniqueness
        $existing = self::get_list_by_slug( $slug );
        if ( $existing ) {
            return new \WP_Error('slug_exists', __( 'A list with this slug already exists.', 'axiachat-ai' ));
        }
        $now = current_time( 'mysql' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->insert( $table, [
            'slug'               => $slug,
            'name'               => sanitize_text_field( $data['name'] ?? $slug ),
            'description'        => sanitize_textarea_field( $data['description'] ?? '' ),
            'fields'             => wp_json_encode( $data['fields'] ?? self::get_default_fields() ),
            'destination'        => sanitize_key( $data['destination'] ?? 'internal' ),
            'destination_config' => wp_json_encode( $data['destination_config'] ?? [] ),
            'notify_enabled'     => ( !empty( $data['notify_enabled'] ) ? 1 : 0 ),
            'notify_email'       => sanitize_email( $data['notify_email'] ?? '' ),
            'email_subject'      => sanitize_text_field( $data['email_subject'] ?? '' ),
            'email_body'         => wp_kses_post( $data['email_body'] ?? '' ),
            'webhook_url'        => esc_url_raw( $data['webhook_url'] ?? '' ),
            'webhook_enabled'    => ( !empty( $data['webhook_enabled'] ) ? 1 : 0 ),
            'tool_enabled'       => ( isset( $data['tool_enabled'] ) ? (int) (bool) $data['tool_enabled'] : 1 ),
            'form_enabled'       => ( isset( $data['form_enabled'] ) ? (int) (bool) $data['form_enabled'] : 1 ),
            'assigned_bots'      => sanitize_text_field( $data['assigned_bots'] ?? 'all' ),
            'tool_description'   => sanitize_textarea_field( $data['tool_description'] ?? '' ),
            'form_mode'          => ( in_array( $data['form_mode'] ?? 'full', ['full', 'compact'], true ) ? $data['form_mode'] : 'full' ),
            'form_header'        => wp_kses_post( $data['form_header'] ?? '' ),
            'form_submit_text'   => sanitize_text_field( $data['form_submit_text'] ?? '' ),
            'form_success_msg'   => sanitize_text_field( $data['form_success_msg'] ?? '' ),
            'form_bg_color'      => ( sanitize_hex_color( $data['form_bg_color'] ?? '' ) ?: '' ),
            'form_btn_color'     => ( sanitize_hex_color( $data['form_btn_color'] ?? '' ) ?: '' ),
            'store_ip'           => ( !empty( $data['store_ip'] ) ? 1 : 0 ),
            'retention_days'     => max( 0, (int) ($data['retention_days'] ?? 0) ),
            'status'             => 'active',
            'created_at'         => $now,
            'updated_at'         => $now,
        ] );
        if ( !$inserted ) {
            return new \WP_Error('db_error', __( 'Failed to create lead list.', 'axiachat-ai' ));
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Update a lead list
     *
     * @param int   $list_id List ID
     * @param array $data    Updated data
     * @return true|WP_Error
     */
    public static function update_list( $list_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_lead_lists';
        $existing = self::get_list( $list_id );
        if ( !$existing ) {
            return new \WP_Error('not_found', __( 'Lead list not found.', 'axiachat-ai' ));
        }
        // If slug is changing, validate uniqueness
        if ( isset( $data['slug'] ) && $data['slug'] !== $existing['slug'] ) {
            $slug = sanitize_key( $data['slug'] );
            if ( !preg_match( '/^[a-z0-9_]{2,64}$/', $slug ) ) {
                return new \WP_Error('invalid_slug', __( 'Slug must be 2-64 characters, lowercase alphanumeric and underscores only.', 'axiachat-ai' ));
            }
            $other = self::get_list_by_slug( $slug );
            if ( $other && (int) $other['id'] !== $list_id ) {
                return new \WP_Error('slug_exists', __( 'A list with this slug already exists.', 'axiachat-ai' ));
            }
        }
        $update = [
            'updated_at' => current_time( 'mysql' ),
        ];
        $map = [
            'slug'             => 'sanitize_key',
            'name'             => 'sanitize_text_field',
            'description'      => 'sanitize_textarea_field',
            'destination'      => 'sanitize_key',
            'notify_email'     => 'sanitize_email',
            'email_subject'    => 'sanitize_text_field',
            'email_body'       => 'wp_kses_post',
            'webhook_url'      => 'esc_url_raw',
            'tool_description' => 'sanitize_textarea_field',
            'form_header'      => 'wp_kses_post',
            'form_submit_text' => 'sanitize_text_field',
            'form_success_msg' => 'sanitize_text_field',
        ];
        foreach ( $map as $key => $sanitizer ) {
            if ( isset( $data[$key] ) ) {
                $update[$key] = call_user_func( $sanitizer, $data[$key] );
            }
        }
        $bool_keys = [
            'notify_enabled',
            'webhook_enabled',
            'tool_enabled',
            'form_enabled',
            'store_ip'
        ];
        foreach ( $bool_keys as $key ) {
            if ( isset( $data[$key] ) ) {
                $update[$key] = ( !empty( $data[$key] ) ? 1 : 0 );
            }
        }
        if ( isset( $data['assigned_bots'] ) ) {
            $update['assigned_bots'] = sanitize_text_field( $data['assigned_bots'] );
        }
        if ( isset( $data['form_mode'] ) && in_array( $data['form_mode'], ['full', 'compact'], true ) ) {
            $update['form_mode'] = $data['form_mode'];
        }
        if ( isset( $data['form_bg_color'] ) ) {
            $update['form_bg_color'] = ( sanitize_hex_color( $data['form_bg_color'] ) ?: '' );
        }
        if ( isset( $data['form_btn_color'] ) ) {
            $update['form_btn_color'] = ( sanitize_hex_color( $data['form_btn_color'] ) ?: '' );
        }
        if ( isset( $data['retention_days'] ) ) {
            $update['retention_days'] = max( 0, (int) $data['retention_days'] );
        }
        if ( isset( $data['status'] ) && in_array( $data['status'], ['active', 'inactive'], true ) ) {
            $update['status'] = $data['status'];
        }
        if ( isset( $data['fields'] ) ) {
            $update['fields'] = wp_json_encode( $data['fields'] );
        }
        if ( isset( $data['destination_config'] ) ) {
            $update['destination_config'] = wp_json_encode( $data['destination_config'] );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update( $table, $update, [
            'id' => $list_id,
        ] );
        if ( $result === false ) {
            return new \WP_Error('db_error', __( 'Failed to update lead list.', 'axiachat-ai' ));
        }
        return true;
    }

    /**
     * Delete a lead list (cannot delete the last list)
     *
     * @param int $list_id
     * @return true|WP_Error
     */
    public static function delete_list( $list_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_lead_lists';
        // Count existing lists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $total <= 1 ) {
            return new \WP_Error('cannot_delete_last', __( 'Cannot delete the only remaining lead list.', 'axiachat-ai' ));
        }
        // Get the first OTHER list to reassign orphaned leads
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $fallback_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id != %d ORDER BY id ASC LIMIT 1", $list_id ) );
        // Reassign leads
        $leads_table = $wpdb->prefix . 'aichat_leads';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "UPDATE {$leads_table} SET list_id = %d WHERE list_id = %d", $fallback_id, $list_id ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $table, [
            'id' => $list_id,
        ], ['%d'] );
        return true;
    }

    /**
     * Get active lead lists (convenience)
     *
     * @return array
     */
    public static function get_active_lists() {
        return self::get_lists( [
            'status' => 'active',
        ] );
    }

    /**
     * Get default fields definition
     *
     * @return array
     */
    public static function get_default_fields() {
        return [
            [
                'key'         => 'name',
                'label'       => 'Name',
                'type'        => 'text',
                'required'    => false,
                'description' => 'Customer full name',
            ],
            [
                'key'         => 'email',
                'label'       => 'Email',
                'type'        => 'email',
                'required'    => false,
                'description' => 'Contact email address',
            ],
            [
                'key'         => 'phone',
                'label'       => 'Phone',
                'type'        => 'tel',
                'required'    => false,
                'description' => 'Phone number',
            ],
            [
                'key'         => 'company',
                'label'       => 'Company',
                'type'        => 'text',
                'required'    => false,
                'description' => 'Company or organization name',
            ],
            [
                'key'         => 'interest',
                'label'       => 'Interest',
                'type'        => 'text',
                'required'    => false,
                'description' => 'Product/service of interest',
            ],
            [
                'key'         => 'notes',
                'label'       => 'Notes',
                'type'        => 'textarea',
                'required'    => false,
                'description' => 'Additional notes',
            ]
        ];
    }

    /**
     * Get settings for a specific list (used by adapters/tools)
     * Returns settings in the same format as the old get_settings() for backward compatibility.
     *
     * @param int $list_id
     * @return array
     */
    public static function get_list_settings( $list_id ) {
        $list = self::get_list( $list_id );
        if ( !$list ) {
            return self::get_settings();
        }
        $config = $list['destination_config'];
        $result = [
            'enabled'          => $list['status'] === 'active',
            'destination'      => $list['destination'],
            'store_ip'         => (bool) $list['store_ip'],
            'notify_email'     => $list['notify_email'],
            'notify_enabled'   => (bool) $list['notify_enabled'],
            'retention_days'   => (int) $list['retention_days'],
            'email_subject'    => $list['email_subject'],
            'email_body'       => $list['email_body'],
            '_list_id'         => (int) $list['id'],
            '_list_slug'       => $list['slug'],
            '_webhook_url'     => $list['webhook_url'],
            '_webhook_enabled' => (bool) $list['webhook_enabled'],
        ];
        $result['cf7_form_id'] = (int) ($config['cf7_form_id'] ?? 0);
        $result['wpforms_form_id'] = (int) ($config['wpforms_form_id'] ?? 0);
        $result['_spreadsheet_id'] = $config['spreadsheet_id'] ?? '';
        $result['_sheet_name'] = $config['sheet_name'] ?? '';
        return $result;
    }

    /**
     * Count leads per list
     *
     * @return array [ list_id => count ]
     */
    public static function get_leads_count_by_list() {
        global $wpdb;
        $table = $wpdb->prefix . 'aichat_leads';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT list_id, COUNT(*) as cnt FROM {$table} GROUP BY list_id", ARRAY_A );
        $counts = [];
        foreach ( $rows as $row ) {
            $counts[(int) $row['list_id']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Save a lead to a specific list
     *
     * @param array $data    Lead data from tool call
     * @param array $context Execution context
     * @param int   $list_id Target list ID
     * @return array Result
     */
    public static function save_to_list( $data, $context, $list_id ) {
        $list = self::get_list( $list_id );
        if ( !$list || $list['status'] !== 'active' ) {
            return [
                'ok'      => false,
                'error'   => 'list_not_found',
                'message' => __( 'The specified lead list is not available.', 'axiachat-ai' ),
            ];
        }
        // Build settings from list for adapter compatibility
        $settings = self::get_list_settings( $list_id );
        $destination = $settings['destination'];
        // Integrations check
        $pro_destinations = [
            'google_sheets',
            'cpt',
            'cf7',
            'wpforms'
        ];
        if ( in_array( $destination, $pro_destinations, true ) && !self::has_integrations_license() ) {
            $destination = 'internal';
        }
        $limit_check = self::check_usage_limit();
        if ( is_wp_error( $limit_check ) ) {
            return [
                'ok'      => false,
                'error'   => 'limit_reached',
                'message' => $limit_check->get_error_message(),
            ];
        }
        // Build lead data
        $lead = self::build_lead_data(
            $data,
            $destination,
            $settings,
            $context
        );
        $lead['list_id'] = $list_id;
        // Save via adapter
        $result = [
            'ok'    => false,
            'error' => 'no_adapter',
        ];
        if ( isset( self::$adapters[$destination] ) ) {
            // Override Google Sheets options for per-list config
            if ( $destination === 'google_sheets' ) {
                $saved_sid = get_option( 'aichat_leads_gsheets_spreadsheet_id' );
                $saved_sn = get_option( 'aichat_leads_gsheets_sheet_name' );
                if ( !empty( $settings['_spreadsheet_id'] ) ) {
                    update_option( 'aichat_leads_gsheets_spreadsheet_id', $settings['_spreadsheet_id'] );
                }
                if ( !empty( $settings['_sheet_name'] ) ) {
                    update_option( 'aichat_leads_gsheets_sheet_name', $settings['_sheet_name'] );
                }
            }
            $adapter = self::$adapters[$destination];
            $result = $adapter->save( $lead, $settings );
            // Restore Google Sheets options
            if ( $destination === 'google_sheets' ) {
                if ( isset( $saved_sid ) ) {
                    update_option( 'aichat_leads_gsheets_spreadsheet_id', $saved_sid );
                }
                if ( isset( $saved_sn ) ) {
                    update_option( 'aichat_leads_gsheets_sheet_name', $saved_sn );
                }
            }
        }
        $success = !empty( $result['ok'] );
        $lead_id = $result['lead_id'] ?? null;
        // Track external leads count
        if ( $success && in_array( $destination, ['cf7', 'wpforms'], true ) ) {
            self::increment_external_count();
        }
        // Send notification email if enabled for this list
        if ( $success && $list['notify_enabled'] ) {
            $notify_email = ( !empty( $list['notify_email'] ) ? $list['notify_email'] : get_option( 'admin_email' ) );
            if ( $notify_email ) {
                $settings['notify_email'] = $notify_email;
                self::send_notification( $lead, $settings );
            }
        }
        // Send webhook if configured for this list
        if ( $success && $list['webhook_enabled'] && !empty( $list['webhook_url'] ) ) {
            self::send_list_webhook(
                $lead,
                $lead_id,
                $context,
                $list
            );
        }
        if ( function_exists( 'aichat_log_debug' ) ) {
            aichat_log_debug( '[Leads] Lead saved to list', [
                'list_id'     => $list_id,
                'list_slug'   => $list['slug'],
                'destination' => $destination,
                'success'     => $success,
            ] );
        }
        if ( $success ) {
            $custom_msg = ( !empty( $list['form_success_msg'] ) ? $list['form_success_msg'] : __( 'Contact information saved successfully. We will get in touch soon.', 'axiachat-ai' ) );
            return [
                'ok'      => true,
                'lead_id' => $lead_id,
                'message' => $custom_msg,
            ];
        }
        return [
            'ok'      => false,
            'error'   => 'save_failed',
            'message' => __( 'There was a problem saving the information. Please try again.', 'axiachat-ai' ),
        ];
    }

    /**
     * Send webhook for a specific list
     */
    private static function send_list_webhook(
        $lead,
        $lead_id,
        $context,
        $list
    ) {
        $webhook_url = $list['webhook_url'];
        if ( empty( $webhook_url ) ) {
            return;
        }
        $payload = [
            'name'      => $lead['nombre'] ?? '',
            'email'     => $lead['email'] ?? '',
            'phone'     => $lead['telefono'] ?? '',
            'company'   => $lead['empresa'] ?? '',
            'interest'  => $lead['interes'] ?? '',
            'notes'     => $lead['notas'] ?? '',
            'bot'       => $lead['bot_slug'] ?? '',
            'list'      => $list['slug'],
            'list_name' => $list['name'],
            'date'      => current_time( 'c' ),
            'source'    => home_url(),
            'lead_id'   => $lead_id,
        ];
        $payload = apply_filters(
            'aichat_leads_webhook_payload',
            $payload,
            $lead,
            $context
        );
        $response = wp_remote_post( $webhook_url, [
            'timeout'   => 15,
            'headers'   => [
                'Content-Type' => 'application/json',
            ],
            'body'      => wp_json_encode( $payload ),
            'sslverify' => true,
        ] );
        if ( function_exists( 'aichat_log_debug' ) ) {
            if ( is_wp_error( $response ) ) {
                aichat_log_debug( '[Leads] List webhook error', [
                    'list'  => $list['slug'],
                    'error' => $response->get_error_message(),
                ] );
            } else {
                aichat_log_debug( '[Leads] List webhook sent', [
                    'list'   => $list['slug'],
                    'status' => wp_remote_retrieve_response_code( $response ),
                ] );
            }
        }
    }

}

// Register cleanup cron
add_action( 'aichat_daily_cleanup', ['AIChat_Leads_Manager', 'cleanup_old_leads'] );