<?php
if ( ! defined('ABSPATH') ) { exit; }

function aichat_logs_detail_page() {
    if ( ! current_user_can('manage_options') ) {
    wp_die( esc_html__('Unauthorized','axiachat-ai') );
    }
    global $wpdb;
    $table = $wpdb->prefix.'aichat_conversations';

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detail view; no state changes.
    $session_raw = sanitize_text_field( wp_unslash( $_GET['session'] ?? '' ) );
    $session     = $session_raw !== '' ? preg_replace( '/[^a-z0-9\-]/i', '', (string) $session_raw ) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detail view; no state changes.
    $bot     = sanitize_title( wp_unslash( $_GET['bot'] ?? '' ) );

    if ( ! $session || ! $bot ) {
    echo '<div class="wrap"><h1>'.esc_html__('Conversation Detail','axiachat-ai').'</h1><p>'.esc_html__('Missing parameters.','axiachat-ai').'</p></div>';
        return;
    }

    // Check if file_meta column exists (added in v2.x; graceful for older schemas)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $has_file_meta = (bool) $wpdb->get_var(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SHOW COLUMNS FROM `$table` LIKE %s", 'file_meta'
        )
    );
    $file_meta_col = $has_file_meta ? 'file_meta,' : '';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin-only detail view; internal table read.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted plugin table name.
            "SELECT id, user_id, message, response, {$file_meta_col} created_at FROM {$table} WHERE session_id=%s AND bot_slug=%s ORDER BY id ASC",
            $session,
            $bot
        ),
        ARRAY_A
    );

    $user_id = 0; $wh_label = '';
    if ( $rows ) {
        foreach($rows as $r){ if ( (int)$r['user_id'] > 0 ) { $user_id = (int)$r['user_id']; break; } }
    }
    // Label especial si la sesión es de WhatsApp
    if ( stripos($session,'wha_') === 0 || stripos($session,'wha') === 0 ) {
        // Compat: antiguo 'wha_' y nuevo 'wha'
        if ( stripos($session,'wha_') === 0 ) {
            $digits = preg_replace('/[^0-9]/','', substr($session,4));
        } else {
            $digits = preg_replace('/[^0-9]/','', substr($session,3));
        }
        if ($digits === '') { $digits = substr(md5($session),0,8); }
        $wh_label = 'WHA'.$digits;
    }

    $total = $rows ? count($rows) : 0;

    // Preparar pares (alternando user/bot)
    $messages = [];
    if ( $rows ) {
        foreach ( $rows as $r ) {
            // Decode file_meta JSON (only relevant for user messages)
            $fm = null;
            if ( ! empty( $r['file_meta'] ) ) {
                $fm = json_decode( $r['file_meta'], true );
                if ( ! is_array( $fm ) ) { $fm = null; }
            }
            if ( $r['message'] !== '' ) {
                $messages[] = [
                    'role'      => 'user',
                    'text'      => $r['message'],
                    'at'        => $r['created_at'],
                    'file_meta' => $fm,
                ];
            }
            if ( $r['response'] !== '' ) {
                $messages[] = [
                    'role' => 'assistant',
                    'text' => $r['response'],
                    'at'   => $r['created_at'],
                    'file_meta' => null,
                ];
            }
        }
    }

    // URL de retorno (se escapa en el punto de salida para satisfacer sniff de seguridad)
    $back_url = admin_url('admin.php?page=aichat-logs');
    ?>
    <div class="wrap">
        <h1 class="mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-chat-square-text"></i> <?php esc_html_e('Conversation Detail','axiachat-ai'); ?>
        </h1>

        <div class="card mb-4 shadow-sm">
            <div class="card-body row g-4">
                <div class="col-md-3">
                    <div class="small text-muted"><?php esc_html_e('Session ID','axiachat-ai'); ?></div>
                    <code><?php echo esc_html($session); ?></code>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted"><?php esc_html_e('Bot','axiachat-ai'); ?></div>
                    <code><?php echo esc_html($bot); ?></code>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted"><?php esc_html_e('User','axiachat-ai'); ?></div>
                    <span>
                        <?php
                        if ( $wh_label ) {
                            echo '<span class="badge bg-info aichat-log-wh-badge">'.esc_html($wh_label).'</span>';
                        } elseif ( $user_id ) {
                            echo intval($user_id);
                        } else {
                            echo '—';
                        }
                        ?>
                    </span>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted"><?php esc_html_e('Messages','axiachat-ai'); ?></div>
                    <span class="fw-semibold"><?php echo (int)$total; ?></span>
                </div>
                <div class="col-md-3 d-flex align-items-start gap-2 justify-content-end">
                    <a href="<?php echo esc_url( $back_url ); ?>" class="button">
                        <i class="bi bi-arrow-left-circle"></i> <?php esc_html_e('Back','axiachat-ai'); ?>
                    </a>
                    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" id="aichat-delete-conv-form" data-confirm="<?php echo esc_attr__('Delete this entire conversation?','axiachat-ai'); ?>">
                        <?php wp_nonce_field('aichat_delete_conversation'); ?>
                        <input type="hidden" name="action" value="aichat_delete_conversation" />
                        <input type="hidden" name="session_id" value="<?php echo esc_attr($session); ?>" />
                        <input type="hidden" name="bot_slug" value="<?php echo esc_attr($bot); ?>" />
                        <button type="submit" class="button button-danger">
                            <i class="bi bi-trash"></i> <?php esc_html_e('Delete','axiachat-ai'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="aichat-log-col-time"><?php esc_html_e('Time','axiachat-ai'); ?></th>
                        <th class="aichat-log-col-role"><?php esc_html_e('Role','axiachat-ai'); ?></th>
                        <th><?php esc_html_e('Text','axiachat-ai'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $messages ) : ?>
                    <?php foreach( $messages as $m ): 
                        $role_label = $m['role']==='user' ? __('User','axiachat-ai') : __('Assistant','axiachat-ai');
                        ?>
                        <tr>
                            <td class="small text-muted"><?php echo esc_html($m['at']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo esc_attr( $m['role']==='user' ? 'primary' : 'secondary' ); ?>">
                                    <?php echo esc_html($role_label); ?>
                                </span>
                            </td>
                            <td class="small">
                                <?php
                                // File attachment preview (images get thumbnail, PDFs get icon)
                                if ( $m['role'] === 'user' && ! empty( $m['file_meta'] ) ) {
                                    $fmeta = $m['file_meta'];
                                    $fname = isset( $fmeta['name'] ) ? $fmeta['name'] : '';
                                    $ftype = isset( $fmeta['type'] ) ? $fmeta['type'] : '';
                                    echo '<div class="aichat-log-file-attachment">';
                                    if ( $ftype === 'image' && ! empty( $fmeta['thumb'] ) ) {
                                        // Full-size viewing via JS: data attributes consumed by enqueued handler below.
                                        $has_full = ! empty( $fmeta['image_b64'] ) && ! empty( $fmeta['mime'] );
                                        $data_attrs = '';
                                        if ( $has_full ) {
                                            $data_attrs = ' data-full-src="' . esc_attr( 'data:' . $fmeta['mime'] . ';base64,' . $fmeta['image_b64'] ) . '"'
                                                        . ' data-full-title="' . esc_attr( $fname ) . '"';
                                        }
                                        echo '<a href="#" class="aichat-log-img-preview' . ( $has_full ? ' aichat-log-img-preview--active' : ' aichat-log-img-preview--inactive' ) . '"' . $data_attrs . ' title="' . esc_attr__('View full size','axiachat-ai') . '">';
                                        echo '<img src="' . esc_attr( $fmeta['thumb'] ) . '" alt="' . esc_attr( $fname ) . '" '
                                            . 'class="aichat-log-thumb" />';
                                        echo '</a>';
                                        echo '<span class="text-muted aichat-log-fname">' . esc_html( $fname ) . '</span>';
                                    } else {
                                        echo '<span class="aichat-log-pdf-badge">';
                                        echo '<i class="bi bi-file-earmark-pdf"></i> ' . esc_html( $fname );
                                        echo '</span>';
                                    }
                                    echo '</div>';
                                }
                                // Mostrar HTML seguro de la respuesta; mensajes user se escapan
                                if ( $m['role'] === 'assistant' ) {
                                    echo wp_kses_post( $m['text'] );
                                } else {
                                    echo esc_html( wp_strip_all_tags( $m['text'] ) );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">
                        <i class="bi bi-info-circle"></i> <?php esc_html_e('No messages in this conversation.','axiachat-ai'); ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    // Enqueue external JS for image preview & delete-confirm handlers.
    wp_enqueue_script(
        'aichat-logs-detail',
        AICHAT_PLUGIN_URL . 'assets/js/logs-detail.js',
        [],
        filemtime( AICHAT_PLUGIN_DIR . 'assets/js/logs-detail.js' ),
        true
    );
}