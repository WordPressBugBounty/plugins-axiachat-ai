<?php
/**
 * Leads Integration - Register tool with AI Tools system
 * 
 * Dynamically registers the save_lead tool based on the selected destination.
 * For Internal, uses default lead fields.
 * 
 * @package AIChat
 * @subpackage Leads
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Register lead tools dynamically based on active lead lists.
 * 
 * For each active list with tool_enabled:
 *   - If only 1 list: registers as 'save_lead' (backward compatible)
 *   - If multiple lists: registers as 'save_lead_{slug}'
 * 
 * For each active list with form_enabled:
 *   - If only 1 list: registers as 'show_form'
 *   - If multiple lists: registers as 'show_form_{slug}'
 */
add_action( 'init', 'aichat_leads_register_tool', 25 );
function aichat_leads_register_tool() {
    
    // Only register if AI Tools API is available
    if ( ! function_exists( 'aichat_register_tool_safe' ) ) {
        return;
    }
    
    // Check if leads add-on is enabled
    if ( ! get_option( 'aichat_addon_leads_enabled', 1 ) ) {
        return;
    }
    
    // Get active lists
    if ( ! class_exists( 'AIChat_Leads_Manager' ) ) {
        return;
    }
    
    $lists = AIChat_Leads_Manager::get_active_lists();
    
    // Fallback: if no lists exist yet (pre-migration), use legacy registration
    if ( empty( $lists ) ) {
        aichat_leads_register_legacy_tool();
        return;
    }
    
    $is_single = count( $lists ) === 1;
    $tool_names = []; // Track for macro registration
    $tool_bot_map = []; // tool_id → assigned_bots value (for runtime filtering)
    
    foreach ( $lists as $list ) {
        $list_id = (int) $list['id'];
        $slug    = $list['slug'];
        $assigned = $list['assigned_bots'] ?? 'all';
        
        // Register save_lead tool
        if ( ! empty( $list['tool_enabled'] ) ) {
            $tool_id = $is_single ? 'save_lead' : 'save_lead_' . $slug;
            $schema  = aichat_leads_build_list_schema( $list );
            $desc    = aichat_leads_build_list_description( $list );
            
            aichat_register_tool_safe( $tool_id, [
                'type'           => 'function',
                'name'           => $tool_id,
                'description'    => $desc,
                'activity_label' => aichat_get_dialog_string( 'saving_contact' ),
                'schema'         => $schema,
                'callback'       => aichat_leads_make_callback( $list_id ),
                'timeout'        => 10,
                'parallel'       => false,
                'max_calls'      => 1,
            ] );
            
            $tool_names[] = $tool_id;
            $tool_bot_map[ $tool_id ] = $assigned;
        }
        
        // Register show_form tool
        if ( ! empty( $list['form_enabled'] ) ) {
            $form_tool_id = $is_single ? 'show_form' : 'show_form_' . $slug;
            
            aichat_register_tool_safe( $form_tool_id, [
                'type'           => 'function',
                'name'           => $form_tool_id,
                'description'    => 'Display a form to the user interface. Do not wait for results.',
                'activity_label' => aichat_get_dialog_string( 'preparing_form' ),
                'schema'         => [
                    'type'                 => 'object',
                    'properties'           => new \stdClass(),
                    'required'             => [],
                    'additionalProperties' => false,
                ],
                'callback'       => aichat_leads_make_form_callback( $list_id, $slug ),
                'timeout'        => 5,
                'parallel'       => false,
                'max_calls'      => 1,
            ] );
            
            $tool_names[] = $form_tool_id;
            $tool_bot_map[ $form_tool_id ] = $assigned;
        }
    }
    
    // Register macro for easy enabling in bot settings
    if ( ! empty( $tool_names ) && function_exists( 'aichat_register_macro' ) ) {
        aichat_register_macro( [
            'name'        => 'lead_capture',
            'label'       => __( 'Lead Capture', 'axiachat-ai' ),
            'description' => __( 'Enables the bot to capture and save customer contact information during conversations.', 'axiachat-ai' ),
            'tools'       => $tool_names,
            'source'      => 'local',
            'source_ref'  => 'axiachat_leads',
        ] );
    }
    
    // Store mapping for runtime bot-filtering (used by provider tool filters)
    if ( ! empty( $tool_bot_map ) ) {
        $GLOBALS['aichat_lead_tool_bot_map'] = $tool_bot_map;
    }
}

/**
 * Filter lead tools based on assigned_bots setting.
 *
 * Removes lead tools (save_lead_*, show_form_*) from the tool list
 * when the current bot is not in the list's assigned_bots.
 *
 * @param array $tools  Tools array in provider format.
 * @param array $ctx    Context with 'bot' key (bot_slug).
 * @return array Filtered tools.
 */
function aichat_leads_filter_tools_by_bot( $tools, $ctx ) {
    $bot_slug = isset( $ctx['bot'] ) ? sanitize_title( (string) $ctx['bot'] ) : '';
    if ( $bot_slug === '' ) {
        return $tools;
    }
    
    $map = $GLOBALS['aichat_lead_tool_bot_map'] ?? [];
    if ( empty( $map ) ) {
        return $tools;
    }
    
    return array_values( array_filter( $tools, function( $tool ) use ( $map, $bot_slug ) {
        // Extract tool name depending on format
        $name = '';
        if ( isset( $tool['function']['name'] ) ) {
            $name = $tool['function']['name'];
        } elseif ( isset( $tool['name'] ) ) {
            $name = $tool['name'];
        }
        
        // Not a lead tool — keep it
        if ( ! isset( $map[ $name ] ) ) {
            return true;
        }
        
        $assigned = $map[ $name ];
        
        // 'all' means available to every bot
        if ( $assigned === 'all' ) {
            return true;
        }
        
        // Check if current bot matches the assigned slug
        return $assigned === $bot_slug;
    } ) );
}
add_filter( 'aichat_openai_responses_tools', 'aichat_leads_filter_tools_by_bot', 20, 2 );
add_filter( 'aichat_claude_messages_tools',  'aichat_leads_filter_tools_by_bot', 20, 2 );
add_filter( 'aichat_gemini_tools',           'aichat_leads_filter_tools_by_bot', 20, 2 );

/**
 * Legacy tool registration (pre-migration fallback)
 */
function aichat_leads_register_legacy_tool() {
    $schema      = aichat_leads_get_tool_schema();
    $description = aichat_leads_get_tool_description();
    
    aichat_register_tool_safe( 'save_lead', [
        'type'           => 'function',
        'name'           => 'save_lead',
        'description'    => $description,
        'activity_label' => aichat_get_dialog_string( 'saving_contact' ),
        'schema'         => $schema,
        'callback'       => 'aichat_leads_tool_callback',
        'timeout'        => 10,
        'parallel'       => false,
        'max_calls'      => 1,
    ] );
    
    if ( function_exists( 'aichat_register_macro' ) ) {
        aichat_register_macro( [
            'name'        => 'lead_capture',
            'label'       => __( 'Lead Capture', 'axiachat-ai' ),
            'description' => __( 'Enables the bot to capture and save customer contact information during conversations.', 'axiachat-ai' ),
            'tools'       => [ 'save_lead' ],
            'source'      => 'local',
            'source_ref'  => 'axiachat_leads',
        ] );
    }
}

/**
 * Build tool schema from a lead list's fields definition
 *
 * @param array $list Lead list row
 * @return array JSON Schema
 */
function aichat_leads_build_list_schema( $list ) {
    $destination = $list['destination'];
    $config      = $list['destination_config'];
    
    // For CF7/WPForms with a form_id, use form fields
    if ( $destination === 'cf7' && ! empty( $config['cf7_form_id'] ) ) {
        return aichat_leads_get_cf7_schema( (int) $config['cf7_form_id'] );
    }
    if ( $destination === 'wpforms' && ! empty( $config['wpforms_form_id'] ) ) {
        return aichat_leads_get_wpforms_schema( (int) $config['wpforms_form_id'] );
    }
    
    // Build from list's custom fields
    $fields = $list['fields'];
    if ( empty( $fields ) || ! is_array( $fields ) ) {
        return aichat_leads_get_default_schema();
    }
    
    $properties = [];
    $required   = [];
    
    foreach ( $fields as $f ) {
        $key = $f['key'] ?? '';
        if ( empty( $key ) ) continue;
        
        $properties[ $key ] = [
            'type'        => 'string',
            'description' => $f['description'] ?? ucfirst( str_replace( '_', ' ', $key ) ),
        ];
        
        if ( ! empty( $f['required'] ) ) {
            $required[] = $key;
        }
    }
    
    if ( empty( $properties ) ) {
        return aichat_leads_get_default_schema();
    }
    
    return [
        'type'                 => 'object',
        'properties'           => $properties,
        'required'             => $required,
        'additionalProperties' => false,
    ];
}

/**
 * Build tool description from a lead list
 *
 * @param array $list Lead list row
 * @return string
 */
function aichat_leads_build_list_description( $list ) {
    // Custom description if set
    if ( ! empty( $list['tool_description'] ) ) {
        return $list['tool_description'];
    }
    
    $base = 'Saves customer contact information (lead). Use this tool when a user provides contact details. IMPORTANT: Always ask for confirmation before saving. Only save data that the user has explicitly provided.';
    
    // Add field list from schema
    $fields = $list['fields'];
    if ( ! empty( $fields ) && is_array( $fields ) ) {
        $names = array_column( $fields, 'label' );
        if ( ! empty( $names ) ) {
            $base .= ' Available fields: ' . implode( ', ', $names ) . '.';
        }
    }
    
    if ( ! empty( $list['description'] ) ) {
        $base .= ' List purpose: ' . $list['description'];
    }
    
    return $base;
}

/**
 * Create a closure callback for save_lead on a specific list
 *
 * @param int $list_id
 * @return callable
 */
function aichat_leads_make_callback( $list_id ) {
    return function( $args, $context = [] ) use ( $list_id ) {
        if ( ! class_exists( 'AIChat_Leads_Manager' ) ) {
            return [
                'ok'      => false,
                'error'   => 'manager_not_loaded',
                'message' => 'Lead capture system is not available.',
            ];
        }
        return AIChat_Leads_Manager::save_to_list( $args, $context, $list_id );
    };
}

/**
 * Create a closure callback for show_form on a specific list
 *
 * Stores form metadata in $GLOBALS['aichat_pending_forms'] so the
 * aichat_response_data filter can inject it into the JSON response.
 *
 * @param int    $list_id
 * @param string $slug
 * @return callable
 */
function aichat_leads_make_form_callback( $list_id, $slug ) {
    return function( $args, $context = [] ) use ( $list_id, $slug ) {
        $list = AIChat_Leads_Manager::get_list( $list_id );
        if ( ! $list ) {
            return [ 'ok' => false, 'error' => 'list_not_found' ];
        }

        // Sanitize fields for frontend consumption
        $fields = [];
        if ( ! empty( $list['fields'] ) && is_array( $list['fields'] ) ) {
            foreach ( $list['fields'] as $f ) {
                $fields[] = [
                    'key'         => sanitize_key( $f['key'] ?? '' ),
                    'label'       => sanitize_text_field( $f['label'] ?? '' ),
                    'type'        => sanitize_text_field( $f['type'] ?? 'text' ),
                    'required'    => ! empty( $f['required'] ),
                    'description' => sanitize_text_field( $f['description'] ?? '' ),
                ];
            }
        }

        // Store in global so the response filter can attach it
        if ( ! isset( $GLOBALS['aichat_pending_forms'] ) ) {
            $GLOBALS['aichat_pending_forms'] = [];
        }
        $GLOBALS['aichat_pending_forms'][] = [
            'list_id'          => $list_id,
            'slug'             => $slug,
            'name'             => sanitize_text_field( $list['name'] ),
            'fields'           => $fields,
            'form_mode'        => sanitize_key( $list['form_mode'] ?? 'full' ),
            'form_header'      => wp_kses_post( $list['form_header'] ?? '' ),
            'form_submit_text' => sanitize_text_field( $list['form_submit_text'] ?? '' ),
            'form_success_msg' => sanitize_text_field( $list['form_success_msg'] ?? '' ),
            'form_bg_color'    => sanitize_hex_color( $list['form_bg_color'] ?? '' ) ?: '',
            'form_btn_color'   => sanitize_hex_color( $list['form_btn_color'] ?? '' ) ?: '',
        ];

        return [
            'ok'      => true,
            'action'  => 'show_form',
            'message' => sprintf( 'Form "%s" will be displayed to the user.', $list['name'] ),
        ];
    };
}

/**
 * Inject pending lead form data into the AJAX response.
 *
 * Hooked on aichat_response_data (called at all 4 response exit points).
 */
add_filter( 'aichat_response_data', 'aichat_leads_inject_form_data', 10, 1 );
function aichat_leads_inject_form_data( $resp ) {
    if ( ! empty( $GLOBALS['aichat_pending_forms'] ) && is_array( $GLOBALS['aichat_pending_forms'] ) ) {
        $resp['lead_form'] = $GLOBALS['aichat_pending_forms'][0]; // one form per response
        unset( $GLOBALS['aichat_pending_forms'] );
    }
    return $resp;
}

/**
 * Get the tool description based on destination
 */
function aichat_leads_get_tool_description() {
    $settings = AIChat_Leads_Manager::get_settings();
    $destination = $settings['destination'] ?? 'internal';
    
    $base = 'Saves customer contact information (lead). Use this tool when a user provides contact details. IMPORTANT: Always ask for confirmation before saving. Only save data that the user has explicitly provided.';
    
    if ( $destination === 'cf7' && ! empty( $settings['cf7_form_id'] ) ) {
        $fields = AIChat_Leads_Adapter_CF7::get_form_fields( $settings['cf7_form_id'] );
        if ( ! empty( $fields ) ) {
            $field_names = array_column( $fields, 'name' );
            $base .= ' Available fields: ' . implode( ', ', $field_names ) . '.';
        }
    } elseif ( $destination === 'wpforms' && ! empty( $settings['wpforms_form_id'] ) ) {
        $fields = AIChat_Leads_Adapter_WPForms::get_form_fields( $settings['wpforms_form_id'] );
        if ( ! empty( $fields ) ) {
            $field_labels = array_column( $fields, 'label' );
            $base .= ' Available fields: ' . implode( ', ', $field_labels ) . '.';
        }
    } else {
        $base .= ' Available fields: name, email, phone, company, interest, notes.';
    }
    
    return $base;
}

/**
 * Get the tool schema based on destination
 */
function aichat_leads_get_tool_schema() {
    $settings = AIChat_Leads_Manager::get_settings();
    $destination = $settings['destination'] ?? 'internal';
    
    // For CF7, use form fields
    if ( $destination === 'cf7' && ! empty( $settings['cf7_form_id'] ) ) {
        return aichat_leads_get_cf7_schema( $settings['cf7_form_id'] );
    }
    
    // For WPForms, use form fields
    if ( $destination === 'wpforms' && ! empty( $settings['wpforms_form_id'] ) ) {
        return aichat_leads_get_wpforms_schema( $settings['wpforms_form_id'] );
    }
    
    // Default schema for Internal
    return aichat_leads_get_default_schema();
}

/**
 * Get default schema for Internal/CPT destination
 */
function aichat_leads_get_default_schema() {
    return [
        'type' => 'object',
        'properties' => [
            'nombre' => [
                'type' => 'string',
                'description' => 'Customer full name',
            ],
            'email' => [
                'type' => 'string',
                'description' => 'Contact email address (valid email format)',
            ],
            'telefono' => [
                'type' => 'string',
                'description' => 'Phone number with or without international prefix',
            ],
            'empresa' => [
                'type' => 'string',
                'description' => 'Company or organization name (optional)',
            ],
            'interes' => [
                'type' => 'string',
                'description' => 'Product/service of interest or reason for contact',
            ],
            'notas' => [
                'type' => 'string',
                'description' => 'Additional relevant information from the conversation',
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ];
}

/**
 * Get schema from CF7 form fields
 */
function aichat_leads_get_cf7_schema( $form_id ) {
    if ( ! class_exists( 'AIChat_Leads_Adapter_CF7' ) ) {
        return aichat_leads_get_default_schema();
    }
    
    $fields = AIChat_Leads_Adapter_CF7::get_form_fields( $form_id );
    
    if ( empty( $fields ) ) {
        return aichat_leads_get_default_schema();
    }
    
    $properties = [];
    $required = [];
    
    foreach ( $fields as $field ) {
        $name = $field['name'];
        $type = $field['type'];
        
        // Skip hidden and submit fields
        if ( in_array( $type, [ 'submit', 'hidden', 'acceptance', 'quiz', 'recaptcha' ], true ) ) {
            continue;
        }
        
        // Build property
        $prop = [
            'type' => 'string',
            'description' => ucfirst( str_replace( [ '-', '_', 'your-' ], [ ' ', ' ', '' ], $name ) ),
        ];
        
        // Add type hints
        if ( $type === 'email' ) {
            $prop['description'] = 'Email address (valid email format)';
        } elseif ( $type === 'tel' ) {
            $prop['description'] = 'Phone number';
        } elseif ( $type === 'textarea' ) {
            $prop['description'] = 'Message or additional notes';
        } elseif ( $type === 'url' ) {
            $prop['description'] = 'Website URL';
        }
        
        $properties[ $name ] = $prop;
        
        // Check if field is required (CF7 uses * in field name or basetype ends with *)
        if ( strpos( $field['type'], '*' ) !== false ) {
            $required[] = $name;
        }
    }
    
    if ( empty( $properties ) ) {
        return aichat_leads_get_default_schema();
    }
    
    return [
        'type' => 'object',
        'properties' => $properties,
        'required' => $required,
        'additionalProperties' => false,
    ];
}

/**
 * Get schema from WPForms form fields
 */
function aichat_leads_get_wpforms_schema( $form_id ) {
    if ( ! class_exists( 'AIChat_Leads_Adapter_WPForms' ) ) {
        return aichat_leads_get_default_schema();
    }
    
    $fields = AIChat_Leads_Adapter_WPForms::get_form_fields( $form_id );
    
    if ( empty( $fields ) ) {
        return aichat_leads_get_default_schema();
    }
    
    $properties = [];
    $required = [];
    
    foreach ( $fields as $field ) {
        $field_id = 'field_' . $field['id'];
        $label = $field['label'];
        $type = $field['type'];
        
        // Skip certain field types
        if ( in_array( $type, [ 'divider', 'html', 'pagebreak', 'captcha', 'stripe-credit-card', 'paypal-commerce' ], true ) ) {
            continue;
        }
        
        // Build property with label as key for readability
        $prop_name = sanitize_key( $label );
        if ( empty( $prop_name ) ) {
            $prop_name = $field_id;
        }
        
        $prop = [
            'type' => 'string',
            'description' => $label,
        ];
        
        // Add type hints
        if ( $type === 'email' ) {
            $prop['description'] = $label . ' (valid email format)';
        } elseif ( $type === 'phone' ) {
            $prop['description'] = $label . ' (phone number)';
        }
        
        // Store field_id mapping in description for callback
        $prop['x-wpforms-field-id'] = $field['id'];
        
        $properties[ $prop_name ] = $prop;
    }
    
    if ( empty( $properties ) ) {
        return aichat_leads_get_default_schema();
    }
    
    return [
        'type' => 'object',
        'properties' => $properties,
        'required' => $required,
        'additionalProperties' => false,
    ];
}

/**
 * Tool callback function
 * 
 * @param array $args Arguments from the LLM
 * @param array $context Execution context
 * @return array Result to return to the LLM
 */
function aichat_leads_tool_callback( $args, $context = [] ) {
    
    // Ensure manager class is loaded
    if ( ! class_exists( 'AIChat_Leads_Manager' ) ) {
        return [
            'ok'      => false,
            'error'   => 'manager_not_loaded',
            'message' => 'Lead capture system is not available.',
        ];
    }
    
    // Call the manager to save the lead
    $result = AIChat_Leads_Manager::save( $args, $context );
    
    return $result;
}

/**
 * Add lead capture instructions hint to bot system prompt
 * (Optional enhancement - can be enabled via filter)
 */
add_filter( 'aichat_system_prompt_additions', 'aichat_leads_prompt_hint', 10, 2 );
function aichat_leads_prompt_hint( $additions, $bot ) {
    
    // Only add if leads is enabled and filter allows
    if ( ! apply_filters( 'aichat_leads_add_prompt_hint', false, $bot ) ) {
        return $additions;
    }
    
    $hint = "\n\n[LEAD CAPTURE] You have the ability to save customer contact information using the save_lead tool. When a user shares contact details (name, email, phone, etc.), offer to save their information for follow-up. Always ask for confirmation before saving.";
    
    $additions[] = $hint;
    
    return $additions;
}
