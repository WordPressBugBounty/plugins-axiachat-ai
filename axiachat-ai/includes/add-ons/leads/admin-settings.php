<?php

/**
 * Leads Admin Settings Page
 * 
 * Renders the Leads admin page with tabs for configuration and lead listing.
 * Uses Bootstrap for consistent styling with main plugin settings.
 * 
 * @package AIChat
 * @subpackage Leads
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Render the main Leads admin page
 */
function aichat_leads_render_page() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page navigation.
    $tab = ( isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'lead_lists' );
    // Redirect old 'settings' tab to lead_lists
    if ( $tab === 'settings' ) {
        $tab = 'lead_lists';
    }
    $stats = AIChat_Leads_Manager::get_stats();
    $usage = AIChat_Leads_Manager::get_usage_info();
    ?>
    <div class="wrap aichat-leads-wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-groups" style="color:#2271b1"></span>
            <?php 
    esc_html_e( 'Leads', 'axiachat-ai' );
    ?>
        </h1>
        <p class="description mb-3"><?php 
    esc_html_e( 'Manage leads captured through AI chat conversations.', 'axiachat-ai' );
    ?></p>
        
        <?php 
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success message display after OAuth callback.
    if ( isset( $_GET['gsheets_connected'] ) && $_GET['gsheets_connected'] === '1' ) {
        ?>
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php 
        esc_html_e( 'Google Sheets connected successfully!', 'axiachat-ai' );
        ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php 
    }
    ?>
        
        <!-- Tabs Navigation -->
        <div class="aichat-leads-tabs">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a href="<?php 
    echo esc_url( admin_url( 'admin.php?page=aichat-leads&tab=lead_lists' ) );
    ?>" 
                       class="nav-link <?php 
    echo ( $tab === 'lead_lists' ? 'active' : '' );
    ?>">
                        <i class="bi bi-collection me-1"></i><?php 
    esc_html_e( 'Lists Settings', 'axiachat-ai' );
    ?>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a href="<?php 
    echo esc_url( admin_url( 'admin.php?page=aichat-leads&tab=list' ) );
    ?>" 
                       class="nav-link <?php 
    echo ( $tab === 'list' ? 'active' : '' );
    ?>">
                        <i class="bi bi-list-ul me-1"></i><?php 
    esc_html_e( 'Lead List', 'axiachat-ai' );
    ?>
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a href="<?php 
    echo esc_url( admin_url( 'admin.php?page=aichat-leads&tab=help' ) );
    ?>" 
                       class="nav-link <?php 
    echo ( $tab === 'help' ? 'active' : '' );
    ?>">
                        <i class="bi bi-question-circle me-1"></i><?php 
    esc_html_e( 'Help', 'axiachat-ai' );
    ?>
                    </a>
                </li>
                <?php 
    ?>
            </ul>
            
            <div class="tab-content border border-top-0 bg-white p-4">
                <?php 
    if ( $tab === 'lead_lists' ) {
        aichat_leads_render_lists_tab();
    } elseif ( $tab === 'help' ) {
        aichat_leads_render_help_tab();
    } else {
        aichat_leads_render_list_tab();
    }
    ?>
            </div>
        </div>
    </div>
    <?php 
}

/**
 * Render Lists tab (Lead Lists CRUD)
 */
function aichat_leads_render_lists_tab() {
    $lists = AIChat_Leads_Manager::get_lists();
    $counts = AIChat_Leads_Manager::get_leads_count_by_list();
    $destinations = AIChat_Leads_Manager::get_available_destinations();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page navigation.
    $edit_id = ( isset( $_GET['edit_list'] ) ? absint( $_GET['edit_list'] ) : 0 );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $action = ( isset( $_GET['list_action'] ) ? sanitize_key( $_GET['list_action'] ) : '' );
    // If editing or creating, show the form
    if ( $action === 'new' || $edit_id ) {
        $list = ( $edit_id ? AIChat_Leads_Manager::get_list( $edit_id ) : null );
        aichat_leads_render_list_form( $list, $destinations );
        return;
    }
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="mb-1"><i class="bi bi-collection me-2"></i><?php 
    esc_html_e( 'Lead Lists', 'axiachat-ai' );
    ?></h5>
            <p class="text-muted mb-0 small"><?php 
    esc_html_e( 'Each list registers its own AI tool and can have different fields, destinations, and settings.', 'axiachat-ai' );
    ?></p>
        </div>
        <a href="<?php 
    echo esc_url( admin_url( 'admin.php?page=aichat-leads&tab=lead_lists&list_action=new' ) );
    ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i><?php 
    esc_html_e( 'New List', 'axiachat-ai' );
    ?>
        </a>
    </div>
    
    <?php 
    if ( empty( $lists ) ) {
        ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <?php 
        esc_html_e( 'No lead lists found. Create your first list to start capturing leads.', 'axiachat-ai' );
        ?>
        </div>
    <?php 
    } else {
        ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th><?php 
        esc_html_e( 'Name', 'axiachat-ai' );
        ?></th>
                        <th><?php 
        esc_html_e( 'Slug', 'axiachat-ai' );
        ?></th>
                        <th><?php 
        esc_html_e( 'Destination', 'axiachat-ai' );
        ?></th>
                        <th class="text-center"><?php 
        esc_html_e( 'Tools', 'axiachat-ai' );
        ?></th>
                        <th class="text-center"><?php 
        esc_html_e( 'Leads', 'axiachat-ai' );
        ?></th>
                        <th class="text-center"><?php 
        esc_html_e( 'Status', 'axiachat-ai' );
        ?></th>
                        <th class="text-end"><?php 
        esc_html_e( 'Actions', 'axiachat-ai' );
        ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
        foreach ( $lists as $list ) {
            $lead_count = $counts[(int) $list['id']] ?? 0;
            $dest_label = $destinations[$list['destination']]['name'] ?? $list['destination'];
            $is_single = count( $lists ) === 1;
            ?>
                    <tr>
                        <td>
                            <strong><?php 
            echo esc_html( $list['name'] );
            ?></strong>
                            <?php 
            if ( !empty( $list['description'] ) ) {
                ?>
                                <br><small class="text-muted"><?php 
                echo esc_html( wp_trim_words( $list['description'], 10 ) );
                ?></small>
                            <?php 
            }
            ?>
                        </td>
                        <td><code><?php 
            echo esc_html( $list['slug'] );
            ?></code></td>
                        <td>
                            <span class="badge bg-light text-dark border"><?php 
            echo esc_html( $dest_label );
            ?></span>
                        </td>
                        <td class="text-center">
                            <?php 
            if ( $list['tool_enabled'] ) {
                ?>
                                <span class="badge bg-success-subtle text-success" title="save_lead_<?php 
                echo esc_attr( $list['slug'] );
                ?>">
                                    <i class="bi bi-wrench me-1"></i>save_lead
                                </span>
                            <?php 
            }
            ?>
                            <?php 
            if ( $list['form_enabled'] ) {
                ?>
                                <span class="badge bg-info-subtle text-info" title="show_form_<?php 
                echo esc_attr( $list['slug'] );
                ?>">
                                    <i class="bi bi-ui-checks me-1"></i>show_form
                                </span>
                            <?php 
            }
            ?>
                        </td>
                        <td class="text-center">
                            <a href="<?php 
            echo esc_url( admin_url( 'admin.php?page=aichat-leads&tab=list&list_id=' . $list['id'] ) );
            ?>" class="text-decoration-none">
                                <?php 
            echo esc_html( $lead_count );
            ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <?php 
            if ( $list['status'] === 'active' ) {
                ?>
                                <span class="badge bg-success"><?php 
                esc_html_e( 'Active', 'axiachat-ai' );
                ?></span>
                            <?php 
            } else {
                ?>
                                <span class="badge bg-secondary"><?php 
                esc_html_e( 'Inactive', 'axiachat-ai' );
                ?></span>
                            <?php 
            }
            ?>
                        </td>
                        <td class="text-end">
                            <a href="<?php 
            echo esc_url( admin_url( 'admin.php?page=aichat-leads&tab=lead_lists&edit_list=' . $list['id'] ) );
            ?>" 
                               class="btn btn-sm btn-outline-primary" title="<?php 
            esc_attr_e( 'Edit', 'axiachat-ai' );
            ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php 
            if ( !$is_single ) {
                ?>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-list" 
                                    data-list-id="<?php 
                echo esc_attr( $list['id'] );
                ?>"
                                    data-list-name="<?php 
                echo esc_attr( $list['name'] );
                ?>"
                                    title="<?php 
                esc_attr_e( 'Delete', 'axiachat-ai' );
                ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php 
            }
            ?>
                        </td>
                    </tr>
                    <?php 
        }
        ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td colspan="4" class="text-end fw-semibold"><?php 
        esc_html_e( 'Total', 'axiachat-ai' );
        ?></td>
                        <td class="text-center fw-semibold">
                            <?php 
        $total_leads = AIChat_Leads_Manager::get_total_leads_count();
        echo esc_html( $total_leads );
        ?>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php 
    }
    ?>
    
    <?php 
    // Enqueue companion script (lists tab) — only needs delete-list i18n
    wp_enqueue_script(
        'aichat-leads-settings',
        AICHAT_PLUGIN_URL . 'assets/js/leads-settings.js',
        ['jquery', 'aichat-leads-admin'],
        ( defined( 'AICHAT_VERSION' ) ? AICHAT_VERSION : '1.0.0' ),
        true
    );
    wp_localize_script( 'aichat-leads-settings', 'aichatLeadsSettings', [
        'isPremium'         => true,
        'isEdit'            => false,
        'defaultSubmitText' => '',
        'defaultHeader'     => '',
        'redirectUrl'       => '',
        'i18n'              => [
            'deleteList'        => __( 'Delete list', 'axiachat-ai' ),
            'leadsReassign'     => __( 'Leads will be reassigned to another list.', 'axiachat-ai' ),
            'disconnectGsheets' => __( 'Disconnect Google Sheets?', 'axiachat-ai' ),
            'deleteContact'     => __( 'Delete this contact?', 'axiachat-ai' ),
            'deleteSelected'    => __( 'Delete selected contacts?', 'axiachat-ai' ),
        ],
    ] );
}

/**
 * Render Create/Edit form for a lead list
 *
 * @param array|null $list Existing list data or null for new
 * @param array      $destinations Available destinations
 */
function aichat_leads_render_list_form(  $list, $destinations  ) {
    $is_edit = !empty( $list );
    $fields = ( $is_edit ? $list['fields'] : AIChat_Leads_Manager::get_default_fields() );
    $config = ( $is_edit ? $list['destination_config'] : [] );
    $cur_dest = ( $is_edit ? $list['destination'] : 'internal' );
    $has_integrations = AIChat_Leads_Manager::has_integrations_license();
    $pro_destinations = [
        'google_sheets',
        'cpt',
        'cf7',
        'wpforms'
    ];
    // Google Sheets OAuth state (shared across all lists)
    $gsheets_connected = ( class_exists( 'AIChat_Leads_GSheets_OAuth' ) ? AIChat_Leads_GSheets_OAuth::is_connected() : false );
    $gsheets_user_email = ( $gsheets_connected && class_exists( 'AIChat_Leads_GSheets_OAuth' ) ? AIChat_Leads_GSheets_OAuth::get_user_email() : '' );
    // Get all bots for AI Tools card
    global $wpdb;
    $bots_table = $wpdb->prefix . 'aichat_bots';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $all_bots = $wpdb->get_results( "SELECT slug, name FROM {$bots_table} ORDER BY name ASC", ARRAY_A );
    if ( !is_array( $all_bots ) ) {
        $all_bots = [];
    }
    $cur_assigned = ( $is_edit ? $list['assigned_bots'] ?? 'all' : 'all' );
    $cur_slug = ( $is_edit ? $list['slug'] : '' );
    ?>
    <div class="mb-3">
        <a href="<?php 
    echo esc_url( admin_url( 'admin.php?page=aichat-leads&tab=lead_lists' ) );
    ?>" class="text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i><?php 
    esc_html_e( 'Back to Lists', 'axiachat-ai' );
    ?>
        </a>
    </div>
    
    <h5 class="mb-4">
        <i class="bi bi-<?php 
    echo ( $is_edit ? 'pencil-square' : 'plus-circle' );
    ?> me-2"></i>
        <?php 
    echo ( $is_edit ? esc_html__( 'Edit Lead List', 'axiachat-ai' ) : esc_html__( 'New Lead List', 'axiachat-ai' ) );
    ?>
        <?php 
    if ( $is_edit ) {
        ?>
            <small class="text-muted ms-2">(<?php 
        echo esc_html( $list['name'] );
        ?>)</small>
        <?php 
    }
    ?>
    </h5>
    
    <form id="aichat-lead-list-form">
        <?php 
    if ( $is_edit ) {
        ?>
            <input type="hidden" name="list_id" value="<?php 
        echo esc_attr( $list['id'] );
        ?>">
        <?php 
    }
    ?>
        
        <!-- Basic Info -->
        <div class="card shadow-sm mb-4 card100">
            <div class="card-header bg-light"><i class="bi bi-info-circle me-2"></i><strong><?php 
    esc_html_e( 'Basic Information', 'axiachat-ai' );
    ?></strong></div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?php 
    esc_html_e( 'Name', 'axiachat-ai' );
    ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required value="<?php 
    echo esc_attr( ( $is_edit ? $list['name'] : '' ) );
    ?>" placeholder="<?php 
    esc_attr_e( 'e.g. Contact Requests', 'axiachat-ai' );
    ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><?php 
    esc_html_e( 'Slug', 'axiachat-ai' );
    ?> <span class="text-danger">*</span></label>
                        <input type="text" name="slug" class="form-control" required pattern="[a-z0-9_]{2,64}" value="<?php 
    echo esc_attr( ( $is_edit ? $list['slug'] : '' ) );
    ?>" placeholder="<?php 
    esc_attr_e( 'e.g. contact_requests', 'axiachat-ai' );
    ?>" <?php 
    echo ( $is_edit && $list['slug'] === 'default' ? 'readonly' : '' );
    ?>>
                        <div class="form-text"><?php 
    esc_html_e( 'Lowercase, numbers and underscores only. Used for tool names.', 'axiachat-ai' );
    ?></div>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold"><?php 
    esc_html_e( 'Description', 'axiachat-ai' );
    ?></label>
                        <textarea name="description" class="form-control" rows="2" placeholder="<?php 
    esc_attr_e( 'Brief description of this list\'s purpose', 'axiachat-ai' );
    ?>"><?php 
    echo esc_textarea( ( $is_edit ? $list['description'] : '' ) );
    ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold"><?php 
    esc_html_e( 'Status', 'axiachat-ai' );
    ?></label>
                        <select name="status" class="form-select">
                            <option value="active" <?php 
    selected( ( $is_edit ? $list['status'] : 'active' ), 'active' );
    ?>><?php 
    esc_html_e( 'Active', 'axiachat-ai' );
    ?></option>
                            <option value="inactive" <?php 
    selected( ( $is_edit ? $list['status'] : '' ), 'inactive' );
    ?>><?php 
    esc_html_e( 'Inactive', 'axiachat-ai' );
    ?></option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Fields Configuration -->
        <div class="card shadow-sm mb-4 card100">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <span><i class="bi bi-input-cursor-text me-2"></i><strong><?php 
    esc_html_e( 'Fields', 'axiachat-ai' );
    ?></strong></span>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-field">
                    <i class="bi bi-plus me-1"></i><?php 
    esc_html_e( 'Add Field', 'axiachat-ai' );
    ?>
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" id="fields-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px"></th>
                            <th><?php 
    esc_html_e( 'Key', 'axiachat-ai' );
    ?></th>
                            <th><?php 
    esc_html_e( 'Label', 'axiachat-ai' );
    ?></th>
                            <th style="width:120px"><?php 
    esc_html_e( 'Type', 'axiachat-ai' );
    ?></th>
                            <th style="width:80px" class="text-center"><?php 
    esc_html_e( 'Required', 'axiachat-ai' );
    ?></th>
                            <th><?php 
    esc_html_e( 'AI Description', 'axiachat-ai' );
    ?></th>
                            <th style="width:50px"></th>
                        </tr>
                    </thead>
                    <tbody id="fields-body">
                        <?php 
    foreach ( $fields as $i => $f ) {
        ?>
                        <tr class="field-row" data-idx="<?php 
        echo esc_attr( $i );
        ?>">
                            <td class="text-center text-muted" style="cursor:grab"><i class="bi bi-grip-vertical"></i></td>
                            <td><input type="text" class="form-control form-control-sm field-key" value="<?php 
        echo esc_attr( $f['key'] );
        ?>" placeholder="field_key"></td>
                            <td><input type="text" class="form-control form-control-sm field-label" value="<?php 
        echo esc_attr( $f['label'] );
        ?>" placeholder="Label"></td>
                            <td>
                                <select class="form-select form-select-sm field-type">
                                    <option value="text" <?php 
        selected( $f['type'], 'text' );
        ?>>Text</option>
                                    <option value="email" <?php 
        selected( $f['type'], 'email' );
        ?>>Email</option>
                                    <option value="tel" <?php 
        selected( $f['type'], 'tel' );
        ?>>Phone</option>
                                    <option value="textarea" <?php 
        selected( $f['type'], 'textarea' );
        ?>>Textarea</option>
                                    <option value="number" <?php 
        selected( $f['type'], 'number' );
        ?>>Number</option>
                                    <option value="url" <?php 
        selected( $f['type'], 'url' );
        ?>>URL</option>
                                </select>
                            </td>
                            <td class="text-center"><input type="checkbox" class="form-check-input field-required" <?php 
        checked( !empty( $f['required'] ) );
        ?>></td>
                            <td><input type="text" class="form-control form-control-sm field-desc" value="<?php 
        echo esc_attr( $f['description'] );
        ?>" placeholder="Description for AI"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-field"><i class="bi bi-x"></i></button></td>
                        </tr>
                        <?php 
    }
    ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Destination -->
        <div class="card shadow-sm mb-4 card100">
            <div class="card-header bg-light"><i class="bi bi-diagram-3 me-2"></i><strong><?php 
    esc_html_e( 'Save Destination', 'axiachat-ai' );
    ?></strong></div>
            <div class="card-body">
                <div class="row">
                    <?php 
    foreach ( $destinations as $dest ) {
        $is_pro_dest = in_array( $dest['id'], $pro_destinations, true );
        $is_locked = $is_pro_dest && !$has_integrations;
        $is_disabled = !$dest['available'] || $is_locked;
        ?>
                    <div class="col-md-4 col-lg-3 mb-3">
                        <div class="card h-100 <?php 
        echo ( $is_disabled ? 'bg-light opacity-75' : '' );
        ?> <?php 
        echo ( $cur_dest === $dest['id'] ? 'border-primary' : '' );
        ?>" <?php 
        echo ( $is_locked ? 'style="position:relative;"' : '' );
        ?>>
                            <?php 
        if ( $is_locked ) {
            ?>
                            <div class="position-absolute top-0 end-0 m-2">
                                <span class="badge bg-gradient" style="background:linear-gradient(135deg,#667eea,#764ba2);font-size:.7rem"><i class="bi bi-star-fill me-1"></i>Standard+</span>
                            </div>
                            <?php 
        }
        ?>
                            <div class="card-body py-2">
                                <div class="form-check">
                                    <input type="radio" class="form-check-input" name="destination" 
                                           id="ll_dest_<?php 
        echo esc_attr( $dest['id'] );
        ?>" 
                                           value="<?php 
        echo esc_attr( $dest['id'] );
        ?>"
                                           <?php 
        checked( $cur_dest, $dest['id'] );
        ?>
                                           <?php 
        disabled( $is_disabled );
        ?>>
                                    <label class="form-check-label fw-semibold" for="ll_dest_<?php 
        echo esc_attr( $dest['id'] );
        ?>">
                                        <?php 
        echo esc_html( $dest['name'] );
        ?>
                                    </label>
                                </div>
                                <p class="small text-muted mb-0 mt-1"><?php 
        echo esc_html( $dest['description'] );
        ?></p>
                                <?php 
        if ( !$dest['available'] && !$is_locked ) {
            ?>
                                    <span class="badge bg-warning text-dark mt-2"><?php 
            esc_html_e( 'Plugin not installed', 'axiachat-ai' );
            ?></span>
                                <?php 
        }
        ?>
                            </div>
                        </div>
                    </div>
                    <?php 
    }
    ?>
                </div>

            </div>
        </div>
        
        <!-- CF7 Configuration (per-list) -->
        <?php 
    if ( class_exists( 'WPCF7' ) ) {
        ?>
        <div class="card shadow-sm mb-4 card100 ll-dest-cf7" style="<?php 
        echo ( $cur_dest === 'cf7' ? '' : 'display:none;' );
        ?>">
            <div class="card-header bg-light d-flex align-items-center">
                <i class="bi bi-envelope me-2"></i>
                <strong><?php 
        esc_html_e( 'Contact Form 7 Configuration', 'axiachat-ai' );
        ?></strong>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="ll_cf7_form_id" class="col-md-3 col-form-label"><?php 
        esc_html_e( 'Select Form', 'axiachat-ai' );
        ?></label>
                    <div class="col-md-9">
                        <select name="cf7_form_id" id="ll_cf7_form_id" class="form-select dest-config-field" data-key="cf7_form_id" style="max-width: 400px;">
                            <option value=""><?php 
        esc_html_e( '-- Select a form --', 'axiachat-ai' );
        ?></option>
                            <?php 
        $cf7_forms = AIChat_Leads_Adapter_CF7::get_forms();
        foreach ( $cf7_forms as $form ) {
            ?>
                                <option value="<?php 
            echo esc_attr( $form['id'] );
            ?>" 
                                        <?php 
            selected( $config['cf7_form_id'] ?? '', $form['id'] );
            ?>>
                                    <?php 
            echo esc_html( $form['title'] );
            ?>
                                </option>
                            <?php 
        }
        ?>
                        </select>
                        <div class="form-text text-muted mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            <?php 
        esc_html_e( 'The AI tool will automatically use the fields defined in your CF7 form.', 'axiachat-ai' );
        ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php 
    }
    ?>
        
        <!-- WPForms Configuration (per-list) -->
        <?php 
    if ( function_exists( 'wpforms' ) ) {
        ?>
        <div class="card shadow-sm mb-4 card100 ll-dest-wpforms" style="<?php 
        echo ( $cur_dest === 'wpforms' ? '' : 'display:none;' );
        ?>">
            <div class="card-header bg-light d-flex align-items-center">
                <i class="bi bi-ui-checks-grid me-2"></i>
                <strong><?php 
        esc_html_e( 'WPForms Configuration', 'axiachat-ai' );
        ?></strong>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="ll_wpforms_form_id" class="col-md-3 col-form-label"><?php 
        esc_html_e( 'Select Form', 'axiachat-ai' );
        ?></label>
                    <div class="col-md-9">
                        <select name="wpforms_form_id" id="ll_wpforms_form_id" class="form-select dest-config-field" data-key="wpforms_form_id" style="max-width: 400px;">
                            <option value=""><?php 
        esc_html_e( '-- Select a form --', 'axiachat-ai' );
        ?></option>
                            <?php 
        $wpf_forms = AIChat_Leads_Adapter_WPForms::get_forms();
        foreach ( $wpf_forms as $form ) {
            ?>
                                <option value="<?php 
            echo esc_attr( $form['id'] );
            ?>" 
                                        <?php 
            selected( $config['wpforms_form_id'] ?? '', $form['id'] );
            ?>>
                                    <?php 
            echo esc_html( $form['title'] );
            ?>
                                </option>
                            <?php 
        }
        ?>
                        </select>
                        <div class="form-text text-muted mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            <?php 
        esc_html_e( 'The AI tool will automatically use the fields defined in your WPForms form.', 'axiachat-ai' );
        ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php 
    }
    ?>
        
        <!-- Google Sheets Configuration (per-list) -->
        <div class="card shadow-sm mb-4 card100 ll-dest-google_sheets" style="<?php 
    echo ( $cur_dest === 'google_sheets' ? '' : 'display:none;' );
    ?>">
            <div class="card-header bg-light d-flex align-items-center">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i>
                <strong><?php 
    esc_html_e( 'Google Sheets Configuration', 'axiachat-ai' );
    ?></strong>
            </div>
            <div class="card-body">
                <?php 
    if ( !$gsheets_connected ) {
        ?>
                    <!-- Not connected -->
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <?php 
        esc_html_e( 'Connect your Google account to save leads to a Google Spreadsheet.', 'axiachat-ai' );
        ?>
                    </div>
                    <?php 
        if ( class_exists( 'AIChat_Leads_GSheets_OAuth' ) ) {
            ?>
                    <a href="<?php 
            echo esc_url( AIChat_Leads_GSheets_OAuth::get_auth_url() );
            ?>" class="btn btn-primary">
                        <i class="bi bi-google me-2"></i><?php 
            esc_html_e( 'Connect Google Sheets', 'axiachat-ai' );
            ?>
                    </a>
                    <?php 
        }
        ?>
                <?php 
    } else {
        ?>
                    <!-- Connected -->
                    <div class="alert alert-success mb-3">
                        <i class="bi bi-check-circle me-2"></i>
                        <?php 
        if ( !empty( $gsheets_user_email ) ) {
            printf( 
                /* translators: %s: Google account email */
                esc_html__( 'Connected as: %s', 'axiachat-ai' ),
                '<strong>' . esc_html( $gsheets_user_email ) . '</strong>'
             );
        } else {
            esc_html_e( 'Connected to Google Sheets', 'axiachat-ai' );
        }
        ?>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-3" id="ll-gsheets-disconnect">
                            <i class="bi bi-x-circle me-1"></i><?php 
        esc_html_e( 'Disconnect', 'axiachat-ai' );
        ?>
                        </button>
                    </div>
                    
                    <div class="row mb-3">
                        <label for="ll_gsheets_spreadsheet_id" class="col-md-3 col-form-label">
                            <?php 
        esc_html_e( 'Google Sheet URL', 'axiachat-ai' );
        ?>
                            <span class="text-danger">*</span>
                        </label>
                        <div class="col-md-9">
                            <input type="text" class="form-control dest-config-field" data-key="spreadsheet_id" id="ll_gsheets_spreadsheet_id"
                                   value="<?php 
        echo esc_attr( $config['spreadsheet_id'] ?? '' );
        ?>" 
                                   placeholder="https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit"
                                   style="max-width: 600px;">
                            <small class="text-muted d-block mt-1">
                                <?php 
        esc_html_e( 'Paste the full URL from your Google Sheets share link or browser address bar.', 'axiachat-ai' );
        ?>
                            </small>
                            <div class="d-flex align-items-center mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="ll-gsheets-test-connection">
                                    <i class="bi bi-plug me-1"></i><?php 
        esc_html_e( 'Test Connection', 'axiachat-ai' );
        ?>
                                </button>
                                <span id="ll-gsheets-test-result" class="ms-2"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <label for="ll_gsheets_sheet_name" class="col-md-3 col-form-label">
                            <?php 
        esc_html_e( 'Sheet Name', 'axiachat-ai' );
        ?>
                            <span class="text-muted small">(<?php 
        esc_html_e( 'optional', 'axiachat-ai' );
        ?>)</span>
                        </label>
                        <div class="col-md-9">
                            <input type="text" class="form-control dest-config-field" data-key="sheet_name" id="ll_gsheets_sheet_name"
                                   value="<?php 
        echo esc_attr( $config['sheet_name'] ?? '' );
        ?>" 
                                   placeholder=""
                                   style="max-width: 300px;">
                            <small class="text-muted"><?php 
        esc_html_e( 'Leave empty to use the first sheet automatically. Only specify if you want a specific tab.', 'axiachat-ai' );
        ?></small>
                        </div>
                    </div>
                    
                    <div class="alert alert-light border mt-3">
                        <strong><i class="bi bi-lightbulb me-1"></i><?php 
        esc_html_e( 'How it works:', 'axiachat-ai' );
        ?></strong>
                        <ol class="mb-0 mt-2 small">
                            <li><?php 
        esc_html_e( 'Create a new spreadsheet in Google Sheets', 'axiachat-ai' );
        ?></li>
                            <li><?php 
        esc_html_e( 'Copy the URL from your browser and paste it above', 'axiachat-ai' );
        ?></li>
                            <li><?php 
        esc_html_e( 'Headers will be added automatically on the first lead', 'axiachat-ai' );
        ?></li>
                            <li><?php 
        esc_html_e( 'Each new lead will be added as a new row', 'axiachat-ai' );
        ?></li>
                        </ol>
                    </div>
                <?php 
    }
    ?>
            </div>
        </div>
        
        <!-- Notifications -->
        <div class="card shadow-sm mb-4 card100">
            <div class="card-header bg-light d-flex align-items-center">
                <i class="bi bi-bell me-2"></i>
                <strong><?php 
    esc_html_e( 'Notifications', 'axiachat-ai' );
    ?></strong>
            </div>
            <div class="card-body">
                <div class="row mb-3 pb-3 border-bottom">
                    <div class="col-md-8">
                        <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                            <input type="checkbox" name="notify_enabled" id="ll_notify" value="1" <?php 
    checked( ( $is_edit ? $list['notify_enabled'] : false ) );
    ?>>
                            <strong><?php 
    esc_html_e( 'Send email notification when a new lead is captured', 'axiachat-ai' );
    ?></strong>
                        </label>
                    </div>
                    <div class="col-md-4 text-end">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#llEmailTemplateModal">
                            <i class="bi bi-pencil me-1"></i><?php 
    esc_html_e( 'Edit Template', 'axiachat-ai' );
    ?>
                        </button>
                    </div>
                </div>
                
                <div class="row">
                    <label for="ll_notify_email" class="col-md-3 col-form-label"><?php 
    esc_html_e( 'Notification Email', 'axiachat-ai' );
    ?></label>
                    <div class="col-md-9">
                        <input type="email" class="form-control" name="notify_email" id="ll_notify_email"
                               value="<?php 
    echo esc_attr( ( $is_edit ? $list['notify_email'] : '' ) );
    ?>" 
                               placeholder="<?php 
    echo esc_attr( get_option( 'admin_email' ) );
    ?>"
                               style="max-width: 400px;">
                        <small class="text-muted"><?php 
    esc_html_e( 'Leave empty to use the site admin email.', 'axiachat-ai' );
    ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Email Template Modal (per-list) -->
        <div class="modal fade" id="llEmailTemplateModal" tabindex="-1" aria-labelledby="llEmailTemplateModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="llEmailTemplateModalLabel">
                            <i class="bi bi-envelope me-2"></i><?php 
    esc_html_e( 'Lead Notification Email Template', 'axiachat-ai' );
    ?>
                            <?php 
    if ( $is_edit ) {
        ?>
                                <small class="text-muted ms-2">(<?php 
        echo esc_html( $list['name'] );
        ?>)</small>
                            <?php 
    }
    ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="ll_email_subject" class="form-label"><?php 
    esc_html_e( 'Email Subject', 'axiachat-ai' );
    ?></label>
                            <input type="text" class="form-control" id="ll_email_subject" name="email_subject"
                                   value="<?php 
    echo esc_attr( ( $is_edit && !empty( $list['email_subject'] ) ? $list['email_subject'] : __( 'New Lead Captured: {name}', 'axiachat-ai' ) ) );
    ?>">
                        </div>
                        <div class="mb-3">
                            <label for="ll_email_body" class="form-label"><?php 
    esc_html_e( 'Email Body', 'axiachat-ai' );
    ?></label>
                            <textarea class="form-control" id="ll_email_body" name="email_body" rows="12"><?php 
    echo esc_textarea( ( $is_edit && !empty( $list['email_body'] ) ? $list['email_body'] : __( "A new lead has been captured:\n\nName: {name}\nEmail: {email}\nPhone: {phone}\nCompany: {company}\n\nMessage:\n{message}\n\nDate: {date}\nBot: {bot}\n\n---\nView all leads: {admin_url}", 'axiachat-ai' ) ) );
    ?></textarea>
                        </div>
                        <div class="alert alert-info small">
                            <strong><?php 
    esc_html_e( 'Available placeholders:', 'axiachat-ai' );
    ?></strong><br>
                            <code>{name}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{company}</code>, <code>{message}</code>, <code>{date}</code>, <code>{bot}</code>, <code>{admin_url}</code>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php 
    esc_html_e( 'Close', 'axiachat-ai' );
    ?></button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Bot instructions guide (visible before Advanced) -->
        <?php 
    $tool_slug_suffix_guide = ( $cur_slug ? '_' . $cur_slug : '_<em>slug</em>' );
    $training_url = admin_url( 'admin.php?page=aichat-training' );
    ?>
        <div class="alert alert-info d-flex align-items-start gap-3 mb-4" role="alert">
            <i class="bi bi-info-circle-fill fs-4 mt-1"></i>
            <div>
                <strong><?php 
    esc_html_e( 'How to use in bot instructions', 'axiachat-ai' );
    ?></strong>
                <p class="mb-2 mt-1"><?php 
    esc_html_e( 'Add a line like this to your bot\'s instructions to tell it when to capture leads:', 'axiachat-ai' );
    ?></p>
                <div class="bg-white border rounded p-2 mb-2" style="font-family:monospace;font-size:12px;">
                    <span class="text-muted">"</span><?php 
    $cur_name_guide = ( $is_edit ? esc_html( $list['name'] ) : '<em>list name</em>' );
    echo wp_kses( sprintf( 
        /* translators: 1: list name, 2: show_form tool name */
        __( 'When the user shows interest in %1$s, use the tool %2$s to ask for their contact details.', 'axiachat-ai' ),
        '<strong id="ll-guide-name-hint">' . $cur_name_guide . '</strong>',
        '<strong id="ll-guide-form-hint">show_form' . esc_html( $tool_slug_suffix_guide ) . '</strong>'
     ), [
        'strong' => [
            'id' => true,
        ],
        'em'     => [],
    ] );
    ?><span class="text-muted">"</span>
                </div>
                <a href="<?php 
    echo esc_url( $training_url );
    ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil-square me-1"></i><?php 
    esc_html_e( 'Edit Bot Instructions', 'axiachat-ai' );
    ?>
                </a>
            </div>
        </div>
        
        <!-- ═══════════ Advanced (Optional) ═══════════ -->
        <div class="d-flex align-items-center my-4">
            <hr class="flex-grow-1">
            <span class="mx-3 text-muted fw-semibold text-uppercase small">
                <i class="bi bi-gear-wide-connected me-1"></i><?php 
    esc_html_e( 'Advanced', 'axiachat-ai' );
    ?>
                <span class="text-muted fw-normal">(<?php 
    esc_html_e( 'optional', 'axiachat-ai' );
    ?>)</span>
            </span>
            <hr class="flex-grow-1">
        </div>
        
        <!-- AI Tools -->
        <?php 
    $capture_enabled = ( $is_edit ? !empty( $list['tool_enabled'] ) || !empty( $list['form_enabled'] ) : true );
    $tool_slug_suffix = ( $cur_slug ? '_' . $cur_slug : '_<em>slug</em>' );
    ?>
        <div class="card shadow-sm mb-4 card100">
            <div class="card-header bg-light"><i class="bi bi-robot me-2"></i><strong><?php 
    esc_html_e( 'AI Tools', 'axiachat-ai' );
    ?></strong></div>
            <div class="card-body">
                <!-- Enable checkbox -->
                <div class="row mb-3 pb-3 border-bottom">
                    <div class="col-md-8">
                        <label class="d-flex align-items-center gap-2" style="cursor:pointer">
                            <input type="checkbox" id="ll_capture_enabled" value="1" <?php 
    checked( $capture_enabled );
    ?>>
                            <strong><?php 
    esc_html_e( 'Enable Lead Capture', 'axiachat-ai' );
    ?></strong>
                        </label>
                        <p class="text-muted small mb-0 mt-1"><?php 
    esc_html_e( 'Registers AI tools so bots can capture leads for this list.', 'axiachat-ai' );
    ?></p>
                    </div>
                    <div class="col-md-4">
                        <label for="ll_assigned_bots" class="form-label small text-muted"><?php 
    esc_html_e( 'Available for', 'axiachat-ai' );
    ?></label>
                        <select id="ll_assigned_bots" name="assigned_bots" class="form-select form-select-sm" style="max-width:250px;">
                            <option value="all" <?php 
    selected( $cur_assigned, 'all' );
    ?>><?php 
    esc_html_e( 'All bots', 'axiachat-ai' );
    ?></option>
                            <?php 
    foreach ( $all_bots as $bot ) {
        ?>
                                <option value="<?php 
        echo esc_attr( $bot['slug'] );
        ?>" <?php 
        selected( $cur_assigned, $bot['slug'] );
        ?>>
                                    <?php 
        echo esc_html( $bot['name'] );
        ?>
                                </option>
                            <?php 
    }
    ?>
                        </select>
                    </div>
                </div>
                
                <!-- Tool names (dynamic) -->
                <div class="mb-3">
                    <label class="form-label fw-semibold small text-muted text-uppercase"><?php 
    esc_html_e( 'Registered Tool Names', 'axiachat-ai' );
    ?></label>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-success-subtle text-success border px-3 py-2" style="font-size:.85rem">
                            <i class="bi bi-wrench me-1"></i><code id="ll-tool-name-save">save_lead<?php 
    echo esc_html( $tool_slug_suffix );
    ?></code>
                        </span>
                        <span class="badge bg-info-subtle text-info border px-3 py-2" style="font-size:.85rem">
                            <i class="bi bi-ui-checks me-1"></i><code id="ll-tool-name-form">show_form<?php 
    echo esc_html( $tool_slug_suffix );
    ?></code>
                        </span>
                    </div>
                    <small class="text-muted d-block mt-1"><?php 
    esc_html_e( 'These tool names are generated from the list slug. Reference them in your bot instructions.', 'axiachat-ai' );
    ?></small>
                </div>
                

            </div>
        </div>
        
        <!-- Form Appearance -->
        <?php 
    $form_mode = ( $is_edit ? $list['form_mode'] ?? 'full' : 'full' );
    $form_header = ( $is_edit ? $list['form_header'] ?? '' : '' );
    $form_submit_text = ( $is_edit ? $list['form_submit_text'] ?? '' : '' );
    $form_success_msg = ( $is_edit ? $list['form_success_msg'] ?? '' : '' );
    $form_bg_color = ( $is_edit ? $list['form_bg_color'] ?? '' : '' );
    $form_btn_color = ( $is_edit ? $list['form_btn_color'] ?? '' : '' );
    // Defaults for placeholders
    $default_header = '<h3>' . esc_html( ( $is_edit ? $list['name'] : 'My List' ) ) . '</h3><p>' . esc_html__( 'Please fill in your details below.', 'axiachat-ai' ) . '</p>';
    $default_submit_text = __( 'Send', 'axiachat-ai' );
    $default_success_msg = __( 'Thank you! Your information has been saved.', 'axiachat-ai' );
    ?>
        <div class="card shadow-sm mb-4 card100">
            <div class="card-header bg-light d-flex align-items-center">
                <i class="bi bi-palette me-2"></i>
                <strong><?php 
    esc_html_e( 'Form Appearance', 'axiachat-ai' );
    ?></strong>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- Left: Settings -->
                    <div class="col-lg-7">
                        <!-- Mode -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><?php 
    esc_html_e( 'Display Mode', 'axiachat-ai' );
    ?></label>
                            <div class="d-flex gap-3">
                                <label class="d-flex align-items-center gap-2" style="cursor:pointer">
                                    <input type="radio" name="form_mode" value="full" <?php 
    checked( $form_mode, 'full' );
    ?>>
                                    <span><strong><?php 
    esc_html_e( 'Full', 'axiachat-ai' );
    ?></strong> — <?php 
    esc_html_e( 'Labels + placeholders', 'axiachat-ai' );
    ?></span>
                                </label>
                                <label class="d-flex align-items-center gap-2" style="cursor:pointer">
                                    <input type="radio" name="form_mode" value="compact" <?php 
    checked( $form_mode, 'compact' );
    ?>>
                                    <span><strong><?php 
    esc_html_e( 'Compact', 'axiachat-ai' );
    ?></strong> — <?php 
    esc_html_e( 'Placeholders only', 'axiachat-ai' );
    ?></span>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Header HTML -->
                        <div class="mb-3">
                            <label for="ll_form_header" class="form-label fw-semibold"><?php 
    esc_html_e( 'Form Header', 'axiachat-ai' );
    ?></label>
                            <textarea id="ll_form_header" name="form_header" class="form-control" rows="3"
                                      placeholder="<?php 
    echo esc_attr( $default_header );
    ?>"
                            ><?php 
    echo esc_textarea( $form_header );
    ?></textarea>
                            <small class="text-muted"><?php 
    esc_html_e( 'HTML displayed at the top of the form. Leave empty for no header. Supports h1-h6, p, strong, em tags.', 'axiachat-ai' );
    ?></small>
                        </div>
                        
                        <!-- Submit button text -->
                        <div class="mb-3">
                            <label for="ll_form_submit_text" class="form-label fw-semibold"><?php 
    esc_html_e( 'Submit Button Text', 'axiachat-ai' );
    ?></label>
                            <input type="text" id="ll_form_submit_text" name="form_submit_text" class="form-control" style="max-width:300px"
                                   value="<?php 
    echo esc_attr( $form_submit_text );
    ?>"
                                   placeholder="<?php 
    echo esc_attr( $default_submit_text );
    ?>">
                        </div>
                        
                        <!-- Success message -->
                        <div class="mb-3">
                            <label for="ll_form_success_msg" class="form-label fw-semibold"><?php 
    esc_html_e( 'Success Message', 'axiachat-ai' );
    ?></label>
                            <input type="text" id="ll_form_success_msg" name="form_success_msg" class="form-control"
                                   value="<?php 
    echo esc_attr( $form_success_msg );
    ?>"
                                   placeholder="<?php 
    echo esc_attr( $default_success_msg );
    ?>">
                            <small class="text-muted"><?php 
    esc_html_e( 'Shown after the form is successfully submitted.', 'axiachat-ai' );
    ?></small>
                        </div>
                        
                        <!-- Colors -->
                        <div class="row mb-3">
                            <div class="col-6">
                                <label for="ll_form_bg_color" class="form-label fw-semibold"><?php 
    esc_html_e( 'Background Color', 'axiachat-ai' );
    ?></label>
                                <div class="input-group" style="max-width:200px">
                                    <input type="color" class="form-control form-control-color" id="ll_form_bg_color_picker"
                                           value="<?php 
    echo esc_attr( ( $form_bg_color ?: '#1f2937' ) );
    ?>"
                                           title="<?php 
    esc_attr_e( 'Choose background color', 'axiachat-ai' );
    ?>">
                                    <input type="text" class="form-control form-control-sm" name="form_bg_color" id="ll_form_bg_color"
                                           value="<?php 
    echo esc_attr( $form_bg_color );
    ?>" placeholder="#1f2937" style="max-width:100px">
                                </div>
                            </div>
                            <div class="col-6">
                                <label for="ll_form_btn_color" class="form-label fw-semibold"><?php 
    esc_html_e( 'Button Color', 'axiachat-ai' );
    ?></label>
                                <div class="input-group" style="max-width:200px">
                                    <input type="color" class="form-control form-control-color" id="ll_form_btn_color_picker"
                                           value="<?php 
    echo esc_attr( ( $form_btn_color ?: '#0073aa' ) );
    ?>"
                                           title="<?php 
    esc_attr_e( 'Choose button color', 'axiachat-ai' );
    ?>">
                                    <input type="text" class="form-control form-control-sm" name="form_btn_color" id="ll_form_btn_color"
                                           value="<?php 
    echo esc_attr( $form_btn_color );
    ?>" placeholder="#0073aa" style="max-width:100px">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right: Live Preview -->
                    <div class="col-lg-5">
                        <label class="form-label fw-semibold text-muted"><?php 
    esc_html_e( 'Preview', 'axiachat-ai' );
    ?></label>
                        <div id="ll-form-preview" class="rounded-3 p-3" style="background: <?php 
    echo esc_attr( ( $form_bg_color ?: '#1f2937' ) );
    ?>; color: #fff; max-width: 340px; font-size: 14px;">
                            <div id="ll-preview-header" style="margin-bottom: 8px;">
                                <?php 
    echo ( $form_header ? wp_kses_post( $form_header ) : wp_kses_post( $default_header ) );
    ?>
                            </div>
                            <div id="ll-preview-fields">
                                <?php 
    $preview_fields = ( $is_edit ? array_slice( $fields, 0, 3 ) : [[
        'label' => 'Name',
        'key'   => 'name',
        'type'  => 'text',
    ], [
        'label' => 'Email',
        'key'   => 'email',
        'type'  => 'email',
    ]] );
    foreach ( $preview_fields as $pf ) {
        ?>
                                    <div class="ll-preview-field-group mb-2">
                                        <label class="ll-preview-label d-block" style="font-size:12px; font-weight:600; margin-bottom:2px;"><?php 
        echo esc_html( $pf['label'] );
        ?></label>
                                        <div style="border:1px solid rgba(255,255,255,.25); border-radius:6px; background:rgba(255,255,255,.1); padding:7px 10px; font-size:13px; color:rgba(255,255,255,.5);">
                                            <?php 
        echo esc_html( $pf['label'] );
        ?>
                                        </div>
                                    </div>
                                <?php 
    }
    ?>
                            </div>
                            <button type="button" id="ll-preview-btn" class="w-100 mt-2" disabled
                                    style="padding:8px 16px; border:0; border-radius:8px; background:<?php 
    echo esc_attr( ( $form_btn_color ?: '#0073aa' ) );
    ?>; color:#fff; font-size:14px; font-weight:600; cursor:default;">
                                <?php 
    echo esc_html( ( $form_submit_text ?: $default_submit_text ) );
    ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Webhook -->
        <div class="card shadow-sm mb-4 card100">
            <div class="card-header bg-light d-flex align-items-center">
                <i class="bi bi-broadcast me-2"></i>
                <strong><?php 
    esc_html_e( 'Webhook Integration', 'axiachat-ai' );
    ?></strong>
                <span class="badge bg-secondary ms-2"><?php 
    esc_html_e( 'Optional', 'axiachat-ai' );
    ?></span>
            </div>
            <div class="card-body">
                <div class="row mb-3 pb-3 border-bottom">
                    <div class="col-md-8">
                        <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                            <input type="checkbox" name="webhook_enabled" id="ll_webhook" value="1" <?php 
    checked( ( $is_edit ? $list['webhook_enabled'] : false ) );
    ?>>
                            <strong><?php 
    esc_html_e( 'Send webhook on new lead', 'axiachat-ai' );
    ?></strong>
                        </label>
                    </div>
                </div>
                <p class="text-muted small mb-3">
                    <?php 
    esc_html_e( 'Send lead data to an external service via webhook. This works with any destination and is useful for integrations with automation tools like n8n, Zapier, Make, or custom APIs.', 'axiachat-ai' );
    ?>
                </p>
                
                <div class="row mb-3">
                    <label for="ll_webhook_url" class="col-md-3 col-form-label"><?php 
    esc_html_e( 'Webhook URL', 'axiachat-ai' );
    ?></label>
                    <div class="col-md-9">
                        <input type="url" class="form-control" name="webhook_url" id="ll_webhook_url"
                               value="<?php 
    echo esc_attr( ( $is_edit ? $list['webhook_url'] : '' ) );
    ?>" 
                               placeholder="https://your-n8n-instance.com/webhook/abc123"
                               style="max-width: 500px;"
                               <?php 
    disabled( false );
    ?>>
                        <small class="text-muted d-block mt-1">
                                <?php 
    esc_html_e( 'Leave empty to disable. A POST request with JSON data will be sent for each new lead.', 'axiachat-ai' );
    ?>
                        </small>
                    </div>
                </div>
                
                <div class="alert alert-light border small mb-0">
                    <strong><i class="bi bi-code-slash me-1"></i><?php 
    esc_html_e( 'JSON Payload Format:', 'axiachat-ai' );
    ?></strong>
                    <pre class="mb-0 mt-2" style="font-size: 12px;"><code>{
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+1234567890",
  "company": "ACME Inc",
  "interest": "Product inquiry",
  "notes": "Customer message...",
  "bot": "sales-bot",
  "date": "2026-01-26 14:30:00",
  "source": "axiachat"
}</code></pre>
                </div>
            </div>
        </div>
        
        <?php 
    ?>
        
        <!-- Save Button -->
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" id="btn-save-list">
                <i class="bi bi-check-lg me-1"></i>
                <?php 
    echo ( $is_edit ? esc_html__( 'Update List', 'axiachat-ai' ) : esc_html__( 'Create List', 'axiachat-ai' ) );
    ?>
            </button>
            <a href="<?php 
    echo esc_url( admin_url( 'admin.php?page=aichat-leads&tab=lead_lists' ) );
    ?>" class="btn btn-outline-secondary">
                <?php 
    esc_html_e( 'Cancel', 'axiachat-ai' );
    ?>
            </a>
        </div>
    </form>
    
    <?php 
    // Enqueue companion script (list form tab) — needs form-specific config
    wp_enqueue_script(
        'aichat-leads-settings',
        AICHAT_PLUGIN_URL . 'assets/js/leads-settings.js',
        ['jquery', 'aichat-leads-admin'],
        ( defined( 'AICHAT_VERSION' ) ? AICHAT_VERSION : '1.0.0' ),
        true
    );
    wp_localize_script( 'aichat-leads-settings', 'aichatLeadsSettings', [
        'isPremium'         => true,
        'isEdit'            => $is_edit,
        'defaultSubmitText' => $default_submit_text,
        'defaultHeader'     => $default_header,
        'redirectUrl'       => admin_url( 'admin.php?page=aichat-leads&tab=lead_lists' ),
        'i18n'              => [
            'deleteList'        => __( 'Delete list', 'axiachat-ai' ),
            'leadsReassign'     => __( 'Leads will be reassigned to another list.', 'axiachat-ai' ),
            'disconnectGsheets' => __( 'Disconnect Google Sheets?', 'axiachat-ai' ),
            'deleteContact'     => __( 'Delete this contact?', 'axiachat-ai' ),
            'deleteSelected'    => __( 'Delete selected contacts?', 'axiachat-ai' ),
        ],
    ] );
}

/**
 * Render List tab
 */
function aichat_leads_render_list_tab() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page filters and pagination.
    $page = ( isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1 );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $estado = ( isset( $_GET['estado'] ) ? sanitize_key( $_GET['estado'] ) : '' );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $search = ( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $list_id = ( isset( $_GET['list_id'] ) ? sanitize_text_field( wp_unslash( $_GET['list_id'] ) ) : '' );
    $query_args = [
        'page'    => $page,
        'estado'  => $estado,
        'search'  => $search,
        'list_id' => $list_id,
    ];
    $result = AIChat_Leads_Manager::get_leads( $query_args );
    $leads = $result['leads'];
    $total_pages = $result['total_pages'];
    $all_lists = AIChat_Leads_Manager::get_lists();
    $list_names = [];
    foreach ( $all_lists as $ll ) {
        $list_names[(int) $ll['id']] = $ll['name'];
    }
    ?>
    
    <!-- Toolbar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="hidden" name="page" value="aichat-leads">
            <input type="hidden" name="tab" value="list">
            <div class="input-group" style="width: auto;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" class="form-control" name="s" value="<?php 
    echo esc_attr( $search );
    ?>" 
                       placeholder="<?php 
    esc_attr_e( 'Search leads...', 'axiachat-ai' );
    ?>" style="width: 200px;">
            </div>
            <select name="estado" class="form-select" style="width: auto;">
                <option value=""><?php 
    esc_html_e( 'All statuses', 'axiachat-ai' );
    ?></option>
                <option value="nuevo" <?php 
    selected( $estado, 'nuevo' );
    ?>><?php 
    esc_html_e( 'New', 'axiachat-ai' );
    ?></option>
                <option value="contactado" <?php 
    selected( $estado, 'contactado' );
    ?>><?php 
    esc_html_e( 'Contacted', 'axiachat-ai' );
    ?></option>
                <option value="convertido" <?php 
    selected( $estado, 'convertido' );
    ?>><?php 
    esc_html_e( 'Converted', 'axiachat-ai' );
    ?></option>
                <option value="descartado" <?php 
    selected( $estado, 'descartado' );
    ?>><?php 
    esc_html_e( 'Discarded', 'axiachat-ai' );
    ?></option>
            </select>
            <?php 
    if ( count( $all_lists ) > 1 ) {
        ?>
            <select name="list_id" class="form-select" style="width: auto;">
                <option value=""><?php 
        esc_html_e( 'All lists', 'axiachat-ai' );
        ?></option>
                <?php 
        foreach ( $all_lists as $ll ) {
            ?>
                    <option value="<?php 
            echo esc_attr( $ll['id'] );
            ?>" <?php 
            selected( $list_id, (string) $ll['id'] );
            ?>><?php 
            echo esc_html( $ll['name'] );
            ?></option>
                <?php 
        }
        ?>
            </select>
            <?php 
    }
    ?>
            <button type="submit" class="btn btn-secondary">
                <i class="bi bi-funnel me-1"></i><?php 
    esc_html_e( 'Filter', 'axiachat-ai' );
    ?>
            </button>
        </form>
        <button type="button" class="btn btn-outline-primary" id="export-leads-btn">
            <i class="bi bi-download me-1"></i><?php 
    esc_html_e( 'Export CSV', 'axiachat-ai' );
    ?>
        </button>
    </div>
    
    <!-- Leads Table -->
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" class="form-check-input" id="cb-select-all">
                    </th>
                    <th><?php 
    esc_html_e( 'Name', 'axiachat-ai' );
    ?></th>
                    <th><?php 
    esc_html_e( 'Email', 'axiachat-ai' );
    ?></th>
                    <th><?php 
    esc_html_e( 'Phone', 'axiachat-ai' );
    ?></th>
                    <th><?php 
    esc_html_e( 'List', 'axiachat-ai' );
    ?></th>
                    <th><?php 
    esc_html_e( 'Bot', 'axiachat-ai' );
    ?></th>
                    <th><?php 
    esc_html_e( 'Status', 'axiachat-ai' );
    ?></th>
                    <th><?php 
    esc_html_e( 'Date', 'axiachat-ai' );
    ?></th>
                    <th style="width: 120px;"><?php 
    esc_html_e( 'Actions', 'axiachat-ai' );
    ?></th>
                </tr>
            </thead>
            <tbody>
                <?php 
    if ( empty( $leads ) ) {
        ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            <i class="bi bi-inbox display-6 d-block mb-2"></i>
                            <?php 
        esc_html_e( 'No leads found.', 'axiachat-ai' );
        ?>
                        </td>
                    </tr>
                <?php 
    } else {
        ?>
                    <?php 
        foreach ( $leads as $lead ) {
            ?>
                        <tr data-id="<?php 
            echo esc_attr( $lead['id'] );
            ?>">
                            <td>
                                <input type="checkbox" class="form-check-input" name="leads[]" value="<?php 
            echo esc_attr( $lead['id'] );
            ?>">
                            </td>
                            <td>
                                <strong><?php 
            echo esc_html( ( $lead['nombre'] ?: '—' ) );
            ?></strong>
                                <?php 
            if ( !empty( $lead['empresa'] ) ) {
                ?>
                                    <br><small class="text-muted"><?php 
                echo esc_html( $lead['empresa'] );
                ?></small>
                                <?php 
            }
            ?>
                            </td>
                            <td>
                                <?php 
            if ( !empty( $lead['email'] ) ) {
                ?>
                                    <a href="mailto:<?php 
                echo esc_attr( $lead['email'] );
                ?>" class="text-decoration-none">
                                        <i class="bi bi-envelope me-1"></i><?php 
                echo esc_html( $lead['email'] );
                ?>
                                    </a>
                                <?php 
            } else {
                ?>
                                    <span class="text-muted">—</span>
                                <?php 
            }
            ?>
                            </td>
                            <td>
                                <?php 
            if ( !empty( $lead['telefono'] ) ) {
                ?>
                                    <a href="tel:<?php 
                echo esc_attr( $lead['telefono'] );
                ?>" class="text-decoration-none">
                                        <i class="bi bi-telephone me-1"></i><?php 
                echo esc_html( $lead['telefono'] );
                ?>
                                    </a>
                                <?php 
            } else {
                ?>
                                    <span class="text-muted">—</span>
                                <?php 
            }
            ?>
                            </td>
                            <td>
                                <?php 
            $lid = (int) ($lead['list_id'] ?? 0);
            $lname = $list_names[$lid] ?? '';
            ?>
                                <?php 
            if ( $lname ) {
                ?>
                                    <span class="badge bg-light text-dark"><?php 
                echo esc_html( $lname );
                ?></span>
                                <?php 
            } else {
                ?>
                                    <span class="text-muted">—</span>
                                <?php 
            }
            ?>
                            </td>
                            <td>
                                <code class="bg-light px-2 py-1 rounded small"><?php 
            echo esc_html( ( $lead['bot_slug'] ?: '—' ) );
            ?></code>
                            </td>
                            <td>
                                <select class="form-select form-select-sm lead-status-select" data-id="<?php 
            echo esc_attr( $lead['id'] );
            ?>" style="width: auto;">
                                    <option value="nuevo" <?php 
            selected( $lead['estado'], 'nuevo' );
            ?>><?php 
            esc_html_e( 'New', 'axiachat-ai' );
            ?></option>
                                    <option value="contactado" <?php 
            selected( $lead['estado'], 'contactado' );
            ?>><?php 
            esc_html_e( 'Contacted', 'axiachat-ai' );
            ?></option>
                                    <option value="convertido" <?php 
            selected( $lead['estado'], 'convertido' );
            ?>><?php 
            esc_html_e( 'Converted', 'axiachat-ai' );
            ?></option>
                                    <option value="descartado" <?php 
            selected( $lead['estado'], 'descartado' );
            ?>><?php 
            esc_html_e( 'Discarded', 'axiachat-ai' );
            ?></option>
                                </select>
                            </td>
                            <td>
                                <small><?php 
            echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $lead['created_at'] ) ) );
            ?></small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-secondary view-lead-btn" data-id="<?php 
            echo esc_attr( $lead['id'] );
            ?>" title="<?php 
            esc_attr_e( 'View', 'axiachat-ai' );
            ?>">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php 
            if ( !empty( $lead['conversation_id'] ) ) {
                ?>
                                        <a href="<?php 
                echo esc_url( admin_url( 'admin.php?page=aichat-logs-detail&id=' . $lead['conversation_id'] ) );
                ?>" 
                                           class="btn btn-outline-info" title="<?php 
                esc_attr_e( 'View Conversation', 'axiachat-ai' );
                ?>">
                                            <i class="bi bi-chat-dots"></i>
                                        </a>
                                    <?php 
            }
            ?>
                                    <button type="button" class="btn btn-outline-danger delete-lead-btn" data-id="<?php 
            echo esc_attr( $lead['id'] );
            ?>" title="<?php 
            esc_attr_e( 'Delete', 'axiachat-ai' );
            ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php 
        }
        ?>
                <?php 
    }
    ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php 
    if ( $total_pages > 1 ) {
        ?>
        <nav aria-label="Leads pagination" class="mt-3">
            <ul class="pagination justify-content-center">
                <?php 
        for ($i = 1; $i <= $total_pages; $i++) {
            $url = add_query_arg( 'paged', $i );
            $active = ( $i === $page ? 'active' : '' );
            ?>
                    <li class="page-item <?php 
            echo esc_attr( $active );
            ?>">
                        <a class="page-link" href="<?php 
            echo esc_url( $url );
            ?>"><?php 
            echo esc_html( $i );
            ?></a>
                    </li>
                <?php 
        }
        ?>
            </ul>
        </nav>
    <?php 
    }
    ?>
    
    <!-- Lead Detail Modal (Bootstrap 5) -->
    <div class="modal fade" id="lead-detail-modal" tabindex="-1" aria-labelledby="leadDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="leadDetailModalLabel">
                        <i class="bi bi-person-badge me-2"></i><?php 
    esc_html_e( 'Lead Details', 'axiachat-ai' );
    ?>
                    </h5>
                    <button type="button" class="btn-close close-modal" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="lead-detail-content">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>
    <?php 
}

/**
 * Render Help tab
 */
function aichat_leads_render_help_tab() {
    ?>
    <div class="row">
        <div class="col-lg-8">
            
            <!-- Overview Card -->
            <div class="card shadow-sm mb-4 card100">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Lead Capture Overview</strong>
                </div>
                <div class="card-body">
                    <p>The Lead Capture add-on enables your AI chatbot to collect and save customer contact information during conversations. When users provide their details (name, email, phone, etc.), the bot can automatically save this data to your chosen destination.</p>
                    
                    <h6 class="fw-bold mt-4"><i class="bi bi-diagram-3 me-2"></i>Available Destinations</h6>
                    <ul class="mb-0">
                        <li><strong>Internal Database:</strong> Saves leads to the plugin's built-in table. View and manage them from the Lead List tab.</li>
                        <?php 
    ?>
                    </ul>
                </div>
            </div>
            
            <!-- How It Works Card -->
            <div class="card shadow-sm mb-4 card100">
                <div class="card-header bg-light">
                    <i class="bi bi-gear-wide-connected me-2"></i>
                    <strong>How It Works</strong>
                </div>
                <div class="card-body">
                    <ol>
                        <li class="mb-2"><strong>Enable the Tool:</strong> Go to your Bot settings and enable the <code>save_lead</code> tool in the AI Tools section.</li>
                        <li class="mb-2"><strong>Configure Destination:</strong> Choose where leads should be saved in the Settings tab.</li>
                        <li class="mb-2"><strong>Add Instructions:</strong> Include lead capture instructions in your bot's System Instructions (see examples below).</li>
                        <li class="mb-2"><strong>Automatic Schema:</strong> The tool automatically adapts its parameters based on your destination:
                            <ul>
                                <li>Uses standard fields (name, email, phone, company, interest, notes)</li>
                                <?php 
    ?>
                            </ul>
                        </li>
                    </ol>
                </div>
            </div>
            
            <!-- System Instructions Examples -->
            <div class="card shadow-sm mb-4 card100">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-code-slash me-2"></i>
                    <strong>System Instructions Examples</strong>
                </div>
                <div class="card-body">
                    <p>Add these instructions to your bot's <strong>System Instructions</strong> field to enable intelligent lead capture:</p>
                    
                    <h6 class="fw-bold mt-4"><i class="bi bi-building me-2"></i>Example 1: Business/Sales Bot</h6>
                    <div class="bg-light p-3 rounded mb-4">
                        <pre class="mb-0" style="white-space: pre-wrap; font-size: 13px;"><code>## Lead Capture Instructions

You have the ability to save customer contact information using the save_lead tool. Follow these guidelines:

1. **When to capture leads:**
   - When a user expresses interest in our products/services
   - When a user requests a quote, demo, or callback
   - When a user asks to be contacted by sales

2. **Information to collect:**
   - Name (required)
   - Email or phone (at least one required)
   - Company name (if B2B)
   - What they're interested in
   - Any relevant notes from the conversation

3. **How to ask:**
   - Be natural and conversational
   - Explain why you need the information
   - Ask for confirmation before saving: "Would you like me to save your contact details so our team can reach out?"

4. **After saving:**
   - Confirm the lead was saved successfully
   - Let them know someone will contact them soon
   - Ask if there's anything else you can help with</code></pre>
                    </div>
                    
                    <h6 class="fw-bold mt-4"><i class="bi bi-headset me-2"></i>Example 2: Support/Contact Bot</h6>
                    <div class="bg-light p-3 rounded mb-4">
                        <pre class="mb-0" style="white-space: pre-wrap; font-size: 13px;"><code>## Contact Form Assistant

You help users submit contact requests. When a user wants to get in touch or leave a message:

1. Collect their information naturally through conversation:
   - Full name
   - Email address
   - Phone number (optional)
   - Their message or inquiry

2. Before saving, summarize what you collected:
   "Let me confirm: Your name is [name], email is [email], and you'd like to ask about [topic]. Should I submit this?"

3. Use the save_lead tool only after user confirmation.

4. After successful submission, thank them and provide expected response time.</code></pre>
                    </div>
                    
                    <h6 class="fw-bold mt-4"><i class="bi bi-calendar-check me-2"></i>Example 3: Appointment/Demo Request</h6>
                    <div class="bg-light p-3 rounded mb-4">
                        <pre class="mb-0" style="white-space: pre-wrap; font-size: 13px;"><code>## Demo Request Handler

When users want to schedule a demo or consultation:

1. Express enthusiasm: "Great! I'd be happy to help you schedule a demo."

2. Collect required information:
   - Name
   - Business email
   - Company name
   - Preferred contact method
   - Best time to call (store in notes)

3. Validate email format before saving.

4. Always ask: "May I save your details so our team can schedule the demo?"

5. Use save_lead with:
   - name: User's full name
   - email: Business email
   - company: Company name
   - interest: "Demo Request"
   - notes: Preferred time and any other relevant details

6. Confirm: "Your demo request has been submitted. You'll hear from us within 24 hours."</code></pre>
                    </div>
                </div>
            </div>
            
            <!-- Best Practices Card -->
            <div class="card shadow-sm mb-4 card100">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-lightbulb me-2"></i>
                    <strong>Best Practices</strong>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold text-success"><i class="bi bi-check-circle me-1"></i> Do</h6>
                            <ul>
                                <li>Always ask for user consent before saving</li>
                                <li>Explain why you need their information</li>
                                <li>Confirm the data before submitting</li>
                                <li>Provide clear next steps after capture</li>
                                <li>Be transparent about how data will be used</li>
                                <li>Respect user privacy and GDPR requirements</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold text-danger"><i class="bi bi-x-circle me-1"></i> Don't</h6>
                            <ul>
                                <li>Force users to provide information</li>
                                <li>Save data without explicit consent</li>
                                <li>Ask for unnecessary personal details</li>
                                <li>Make promises about response times you can't keep</li>
                                <li>Store sensitive data (passwords, credit cards)</li>
                                <li>Capture leads from users who just want information</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
        
        <!-- Sidebar -->
        <div class="col-lg-4">
            
            <!-- Quick Reference Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-bookmark me-2"></i>
                    <strong>Quick Reference</strong>
                </div>
                <div class="card-body">
                    <h6 class="fw-bold">Tool Name</h6>
                    <p><code>save_lead</code></p>
                    
                    <h6 class="fw-bold mt-3">Default Parameters</h6>
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Field</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><code>nombre</code></td><td>string</td></tr>
                            <tr><td><code>email</code></td><td>string</td></tr>
                            <tr><td><code>telefono</code></td><td>string</td></tr>
                            <tr><td><code>empresa</code></td><td>string</td></tr>
                            <tr><td><code>interes</code></td><td>string</td></tr>
                            <tr><td><code>notas</code></td><td>string</td></tr>
                        </tbody>
                    </table>
                    <?php 
    ?>
                </div>
            </div>
            
            <!-- Troubleshooting Card -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-secondary text-white">
                    <i class="bi bi-wrench me-2"></i>
                    <strong>Troubleshooting</strong>
                </div>
                <div class="card-body">
                    <h6 class="fw-bold">Lead not saving?</h6>
                    <ul class="small">
                        <li>Check that the <code>save_lead</code> tool is enabled in Bot settings</li>
                        <li>Verify destination is properly configured</li>
                        <?php 
    ?>
                        <li>Check the debug log for error details</li>
                    </ul>
                    
                    <h6 class="fw-bold mt-3">Bot not asking for contact info?</h6>
                    <ul class="small mb-0">
                        <li>Add lead capture instructions to System Instructions</li>
                        <li>Be specific about when to collect data</li>
                        <li>Test with explicit requests like "I want to be contacted"</li>
                    </ul>
                </div>
            </div>
            

            
            <?php 
    ?>
            
        </div>
    </div>
    <?php 
}
