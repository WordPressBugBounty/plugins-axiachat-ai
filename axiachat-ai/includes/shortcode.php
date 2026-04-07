<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Footer filter — optional footer line in the widget.
 * Default: OFF (opt-in only). The admin must explicitly enable it.
 * HTML value stored in aichat_footer_html wp_option (set on activation).
 */
add_filter(
    'aichat_widget_footer',
    function ( $footer, $slug ) {
        // Respect the global toggle (opt-in only)
        $enabled = get_option( 'aichat_footer_enabled', 0 );
        if ( !$enabled ) {
            return [];
        }
        // Read the stored footer HTML
        $html = get_option( 'aichat_footer_html', '' );
        if ( empty( $html ) ) {
            return [];
        }
        return [
            'html' => $html,
        ];
    },
    10,
    2
);
/**
 * [aichat] shortcode
 * Uso:
 *   [aichat bot="mi-bot-slug"]
 *   (alias) [aichat id="mi-bot-slug"]
 */
add_action( 'init', function () {
    add_shortcode( 'aichat', 'aichat_render_shortcode' );
} );
function aichat_render_shortcode(  $atts, $content = null, $tag = 'aichat'  ) {
    global $wpdb;
    // Impide que se pinte el Global en esta página
    $GLOBALS['aichat_has_shortcode'] = true;
    // Atributos de entrada
    $atts = shortcode_atts( [
        'bot'         => '',
        'id'          => '',
        'title'       => '',
        'placeholder' => '',
        'class'       => '',
        'layout'      => '',
        'position'    => '',
    ], $atts, $tag );
    // Resolver slug
    $slug = sanitize_title( ( $atts['bot'] ?: $atts['id'] ) );
    // On the test page, allow ?bot=slug to override the shortcode attribute.
    $test_page_id = (int) get_option( 'aichat_test_page_id', 0 );
    if ( $test_page_id > 0 && get_the_ID() === $test_page_id && !empty( $_GET['bot'] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only frontend query param.
        $slug = sanitize_title( wp_unslash( $_GET['bot'] ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }
    if ( empty( $slug ) ) {
        // Fallbacks: global → primero
        $global_on = (bool) get_option( 'aichat_global_bot_enabled', false );
        $global_slug = ( $global_on ? get_option( 'aichat_global_bot_slug', '' ) : '' );
        if ( $global_on && $global_slug ) {
            $slug = sanitize_title( $global_slug );
        } else {
            $bots_table = $wpdb->prefix . 'aichat_bots';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal table, no user input.
            $slug = $wpdb->get_var( "SELECT slug FROM {$bots_table} ORDER BY id ASC LIMIT 1" );
            $slug = ( $slug ? sanitize_title( $slug ) : '' );
        }
    }
    // Si seguimos sin slug, avisa (solo admin)
    if ( empty( $slug ) ) {
        if ( current_user_can( 'manage_options' ) ) {
            return '<div class="aichat-widget"><em style="color:#b00">' . esc_html__( '[AIChat] No bots configured.', 'axiachat-ai' ) . '</em></div>';
        }
        return '<div class="aichat-widget"></div>';
    }
    // Leer bot de BD
    $table = $wpdb->prefix . 'aichat_bots';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Frontend read of plugin settings table.
    $bot = $wpdb->get_row( 
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted plugin table name.
        $wpdb->prepare( "SELECT * FROM {$table} WHERE slug=%s LIMIT 1", $slug ),
        ARRAY_A
     );
    if ( !$bot ) {
        if ( current_user_can( 'manage_options' ) ) {
            /* translators: %s: bot slug that was not found */
            return '<div class="aichat-widget"><em style="color:#b00">[AIChat] ' . sprintf( esc_html__( 'Bot not found: %s', 'axiachat-ai' ), esc_html( $slug ) ) . '</em></div>';
        }
        return '<div class="aichat-widget"></div>';
    }
    // --------- Budget / cost limit pre-check (suppress widget before render) ----------
    $cost_limited = '';
    if ( !current_user_can( 'manage_options' ) ) {
        $budget_status = aichat_is_budget_exceeded();
        if ( $budget_status['exceeded'] ) {
            if ( $budget_status['behavior'] === 'hide' ) {
                return '';
                // Do not render the widget at all
            }
            // 'whatsapp' → render widget but flag it for immediate limited state
            $cost_limited = '1';
        }
    }
    // --------- Mapear campos de UI desde la tabla de bots ----------
    // Nombres tentativos: usa el primero que exista/no vacío
    $ui_layout = aichat_norm_layout( aichat_pick( $bot, ['ui_layout', 'layout'], 'inline' ) );
    $ui_pos = aichat_norm_pos( aichat_pick( $bot, ['ui_position', 'position'], 'bottom-right' ) );
    $ui_color = aichat_pick( $bot, [
        'ui_color',
        'color',
        'theme_color',
        'primary_color'
    ], '#0073aa' );
    $ui_title = aichat_pick( $bot, ['ui_title', 'title', 'name'], 'AI Chat' );
    $ui_ph = aichat_pick( $bot, ['ui_placeholder', 'placeholder'], 'Escribe tu pregunta...' );
    $ui_width = intval( aichat_pick( $bot, ['ui_width', 'width'], 380 ) );
    $ui_height = intval( aichat_pick( $bot, ['ui_height', 'height', 'messages_height'], 380 ) );
    $ui_role = aichat_pick( $bot, ['ui_role', 'role', 'subtitle'], 'AI Agent Specialist' );
    // Avatares
    $ui_avatar_enabled = intval( aichat_pick( $bot, ['ui_avatar_enabled'], 0 ) );
    $ui_avatar_key = aichat_pick( $bot, ['ui_avatar_key'], '' );
    $ui_icon_url = aichat_pick( $bot, ['ui_icon_url'], '' );
    // Controles de ventana
    $ui_closable = intval( aichat_pick( $bot, ['ui_closable'], 1 ) );
    $ui_minimizable = intval( aichat_pick( $bot, ['ui_minimizable'], 0 ) );
    $ui_draggable = intval( aichat_pick( $bot, ['ui_draggable'], 1 ) );
    $ui_minimized_default = intval( aichat_pick( $bot, ['ui_minimized_default'], 0 ) );
    $ui_superminimized_default = intval( aichat_pick( $bot, ['ui_superminimized_default'], 0 ) );
    $ui_avatar_bubble = intval( aichat_pick( $bot, ['ui_avatar_bubble'], 1 ) );
    $ui_css_force = intval( aichat_pick( $bot, ['ui_css_force'], 0 ) );
    // Suggestions / next actions
    $ui_sug_enabled = intval( aichat_pick( $bot, ['ui_suggestions_enabled'], 0 ) );
    $ui_sug_count = intval( aichat_pick( $bot, ['ui_suggestions_count'], 3 ) );
    if ( $ui_sug_count < 1 ) {
        $ui_sug_count = 1;
    }
    if ( $ui_sug_count > 6 ) {
        $ui_sug_count = 6;
    }
    $ui_sug_bg = aichat_pick( $bot, ['ui_suggestions_bg'], '#f1f3f4' );
    $ui_sug_text = aichat_pick( $bot, ['ui_suggestions_text'], '#1a73e8' );
    if ( !preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $ui_sug_bg ) ) {
        $ui_sug_bg = '#f1f3f4';
    }
    if ( !preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $ui_sug_text ) ) {
        $ui_sug_text = '#1a73e8';
    }
    // History persistence (default ON)
    $ui_history_persistence = intval( aichat_pick( $bot, ['history_persistence'], 1 ) );
    // Start sentence
    $ui_start_sentence = aichat_pick( $bot, [
        'ui_start_sentence',
        'start_sentence',
        'ui_start_text',
        'start_text'
    ], '' );
    // Botón enviar (nuevo)
    $ui_button_send = aichat_pick( $bot, ['ui_button_send', 'button_send', 'ui_send_label'], 'Send' );
    // Tipo de bot: 'text' | 'voice_text'
    $bot_type = ( isset( $bot['type'] ) ? sanitize_text_field( $bot['type'] ) : 'text' );
    // WhatsApp CTA
    $wa_enabled = intval( aichat_pick( $bot, ['wa_enabled'], 0 ) );
    $wa_phone = aichat_pick( $bot, ['wa_phone'], '' );
    $wa_message = aichat_pick( $bot, ['wa_message'], '' );
    $wa_tooltip = aichat_pick( $bot, ['wa_tooltip'], '' );
    $wa_schedule = aichat_pick( $bot, ['wa_schedule'], '{}' );
    $wa_outside_mode = aichat_pick( $bot, ['wa_outside_mode'], 'hide' );
    $wa_outside_label = aichat_pick( $bot, ['wa_outside_label'], '' );
    $wa_trigger_mode = aichat_pick( $bot, ['wa_trigger_mode'], 'always' );
    $wa_trigger_value = intval( aichat_pick( $bot, ['wa_trigger_value'], 0 ) );
    $wa_icon_color = aichat_pick( $bot, ['wa_icon_color'], '#25D366' );
    $wa_icon_bg = aichat_pick( $bot, ['wa_icon_bg'], '#ffffff' );
    // File upload from chat widget
    $file_upload_enabled = intval( aichat_pick( $bot, ['file_upload_enabled'], 0 ) );
    $file_upload_types = aichat_pick( $bot, ['file_upload_types'], 'pdf,jpg,png,webp' );
    $file_upload_max_size = intval( aichat_pick( $bot, ['file_upload_max_size'], 5 ) );
    // Quick questions above input
    $qq_enabled = intval( aichat_pick( $bot, ['quick_questions_enabled'], 0 ) );
    $qq_content = aichat_pick( $bot, ['quick_questions'], '' );
    // URL base del plugin (para assets/images)
    $plugin_base_url = trailingslashit( dirname( plugin_dir_url( __FILE__ ) ) );
    $avatar_url = '';
    if ( !empty( $ui_icon_url ) ) {
        $avatar_url = esc_url_raw( $ui_icon_url );
    } else {
        // Acepta claves '7' o 'avatar7' … hasta 22
        $m = [];
        if ( preg_match( '/^(?:avatar)?([1-9]|1[0-9]|2[0-2])$/i', (string) $ui_avatar_key, $m ) ) {
            $num = $m[1];
            $avatar_url = $plugin_base_url . 'assets/images/' . $num . '.png';
        }
    }
    // Overrides por shortcode si vinieran
    if ( !empty( $atts['title'] ) ) {
        $ui_title = sanitize_text_field( $atts['title'] );
    }
    if ( !empty( $atts['placeholder'] ) ) {
        $ui_ph = sanitize_text_field( $atts['placeholder'] );
    }
    // NUEVO: forzar layout/posición si se pasan como atributo (para el global)
    if ( !empty( $atts['layout'] ) ) {
        $ui_layout = aichat_norm_layout( $atts['layout'] );
    }
    if ( !empty( $atts['position'] ) ) {
        $ui_pos = aichat_norm_pos( $atts['position'] );
    }
    // Clases
    $classes = ['aichat-widget'];
    if ( $ui_layout === 'floating' ) {
        $classes[] = 'is-global';
    }
    // normaliza la posición a una clase pos-*
    $pos_class = 'pos-' . str_replace( '_', '-', strtolower( $ui_pos ) );
    // pos-bottom-right, etc.
    $classes[] = $pos_class;
    if ( !empty( $atts['class'] ) ) {
        $classes[] = preg_replace( '/[^a-z0-9\\-\\_\\s]/i', '', $atts['class'] );
    }
    // Estilo directo opcional (anchura para floating)
    $style = '';
    if ( $ui_layout === 'floating' && $ui_width > 0 ) {
        $style = 'style="width: ' . intval( $ui_width ) . 'px"';
    }
    // Encola assets
    wp_enqueue_style( 'aichat-frontend' );
    wp_enqueue_script( 'jquery' );
    wp_enqueue_script( 'aichat-frontend' );
    // Footer: optional footer line in the widget (opt-in via settings)
    $footer_data = apply_filters( 'aichat_widget_footer', [], $slug );
    $footer_attr = '';
    if ( !empty( $footer_data['html'] ) ) {
        $footer_attr .= sprintf( ' data-footer-html="%s"', esc_attr( $footer_data['html'] ) );
    }
    // WhatsApp CTA data-attrs
    $wa_attr = '';
    if ( $wa_enabled && $wa_phone ) {
        $wa_attr = sprintf(
            ' data-wa-enabled="1" data-wa-phone="%s" data-wa-message="%s" data-wa-tooltip="%s"' . ' data-wa-schedule="%s" data-wa-outside-mode="%s" data-wa-outside-label="%s"' . ' data-wa-trigger-mode="%s" data-wa-trigger-value="%d"' . ' data-wa-icon-color="%s" data-wa-icon-bg="%s"',
            esc_attr( $wa_phone ),
            esc_attr( $wa_message ),
            esc_attr( $wa_tooltip ),
            esc_attr( $wa_schedule ),
            esc_attr( $wa_outside_mode ),
            esc_attr( $wa_outside_label ),
            esc_attr( $wa_trigger_mode ),
            $wa_trigger_value,
            esc_attr( $wa_icon_color ),
            esc_attr( $wa_icon_bg )
        );
    }
    // File upload data-attrs
    $fu_attr = '';
    if ( $file_upload_enabled ) {
        $fu_attr = sprintf( ' data-file-upload="1" data-file-upload-types="%s" data-file-upload-max="%d"', esc_attr( $file_upload_types ), $file_upload_max_size );
    }
    // Quick questions data-attrs
    $qq_attr = '';
    if ( $qq_enabled && trim( $qq_content ) !== '' ) {
        $qq_attr = sprintf( ' data-quick-questions="%s"', esc_attr( $qq_content ) );
    }
    // Budget limit flag (WhatsApp mode only — 'hide' mode already returned empty above)
    $cl_attr = '';
    if ( $cost_limited === '1' ) {
        $cl_attr = ' data-cost-limited="1"';
    }
    // Contenedor con data-attrs de UI
    $html = sprintf(
        '<div class="%s" %s ' . 'data-bot="%s" data-type="%s" data-title="%s" data-placeholder="%s" ' . 'data-layout="%s" data-position="%s" data-color="%s" ' . 'data-width="%d" data-height="%d" ' . 'data-avatar-enabled="%d" data-avatar-url="%s" ' . 'data-start-sentence="%s" data-role="%s" data-button-send="%s" ' . 'data-closable="%d" data-minimizable="%d" data-draggable="%d" data-minimized-default="%d" data-superminimized-default="%d" ' . 'data-avatar-bubble="%d" ' . 'data-css-force="%d" ' . 'data-suggestions-enabled="%d" data-suggestions-count="%d" data-suggestions-bg="%s" data-suggestions-text="%s" ' . 'data-history-persistence="%d"' . '%s%s%s%s%s></div>',
        esc_attr( implode( ' ', $classes ) ),
        $style,
        esc_attr( $slug ),
        esc_attr( $bot_type ),
        esc_attr( $ui_title ),
        esc_attr( $ui_ph ),
        esc_attr( $ui_layout ),
        esc_attr( strtolower( $ui_pos ) ),
        esc_attr( $ui_color ),
        $ui_width,
        $ui_height,
        ( $ui_avatar_enabled ? 1 : 0 ),
        esc_attr( $avatar_url ),
        esc_attr( $ui_start_sentence ),
        esc_attr( $ui_role ),
        esc_attr( $ui_button_send ),
        ( $ui_closable ? 1 : 0 ),
        ( $ui_minimizable ? 1 : 0 ),
        ( $ui_draggable ? 1 : 0 ),
        ( $ui_minimized_default ? 1 : 0 ),
        ( $ui_superminimized_default ? 1 : 0 ),
        ( $ui_avatar_bubble ? 1 : 0 ),
        ( $ui_css_force ? 1 : 0 ),
        ( $ui_sug_enabled ? 1 : 0 ),
        $ui_sug_count,
        esc_attr( $ui_sug_bg ),
        esc_attr( $ui_sug_text ),
        ( $ui_history_persistence ? 1 : 0 ),
        $footer_attr,
        $wa_attr,
        $fu_attr,
        $qq_attr,
        $cl_attr
    );
    // Honeypot (front-end). Bots that auto-complete will fill this hidden field.
    // Backend rejects any request where $_POST['aichat_hp'] is non-empty.
    $honeypot = '<input type="text" name="aichat_hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;" />';
    $html .= $honeypot;
    return $html;
}

/** helper: toma el primer campo no vacío de un array de claves */
function aichat_pick(  array $row, array $keys, $default = ''  ) {
    foreach ( $keys as $k ) {
        if ( array_key_exists( $k, $row ) ) {
            $val = $row[$k];
            if ( $val === null ) {
                continue;
            }
            // evita null → deprecated en core
            if ( $val === '' ) {
                continue;
            }
            // Normaliza arrays/objetos a cadena corta para evitar notices si se pasan a sanitize_*
            if ( is_array( $val ) || is_object( $val ) ) {
                $val = '';
                // no usamos estructuras complejas aquí
            }
            return ( is_string( $val ) ? wp_unslash( $val ) : $val );
        }
    }
    return $default;
}

// helpers globales (puedes ponerlos en un utils.php si prefieres)
function aichat_norm_layout(  $v  ) {
    $v = strtolower( trim( (string) $v ) );
    if ( in_array( $v, [
        'floating',
        'float',
        'global',
        'popup',
        'flotante'
    ], true ) ) {
        return 'floating';
    }
    if ( in_array( $v, [
        'inline',
        'embed',
        'incrustado',
        'contenido',
        'inline-block'
    ], true ) ) {
        return 'inline';
    }
    return 'inline';
}

function aichat_norm_pos(  $v  ) {
    $v = strtolower( trim( (string) $v ) );
    if ( $v === 'tr' ) {
        return 'top-right';
    }
    if ( $v === 'tl' ) {
        return 'top-left';
    }
    if ( $v === 'br' ) {
        return 'bottom-right';
    }
    if ( $v === 'bl' ) {
        return 'bottom-left';
    }
    $map = [
        'top-right'    => ['top-right', 'derecha-superior', 'superior-derecha'],
        'top-left'     => ['top-left', 'izquierda-superior', 'superior-izquierda'],
        'bottom-right' => ['bottom-right', 'derecha-inferior', 'inferior-derecha'],
        'bottom-left'  => ['bottom-left', 'izquierda-inferior', 'inferior-izquierda'],
    ];
    foreach ( $map as $k => $alts ) {
        if ( in_array( $v, $alts, true ) ) {
            return $k;
        }
    }
    return 'bottom-right';
}
