<?php
/**
 * Provider Error Tracking
 *
 * Records AI provider errors (auth, quota, model, server) in a transient buffer
 * and surfaces them as admin notices + menu badges on the Bots & Logs pages.
 *
 * @package AxiaChat
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ─── constants ───────────────────────────────────────────────────── */
define( 'AICHAT_ERROR_TRANSIENT',  'aichat_provider_errors' );
define( 'AICHAT_ERROR_MAX_BUFFER', 20 );
define( 'AICHAT_ERROR_TTL',        48 * HOUR_IN_SECONDS );

/* ─── classification ──────────────────────────────────────────────── */

/**
 * Classify a provider error into severity + human hint.
 *
 * @param  int|string $http_code  HTTP status code (or 0).
 * @param  string     $message    Raw error text from provider.
 * @return array{severity:string,hint:string}
 */
function aichat_classify_provider_error( $http_code, $message ) {
    $code = (int) $http_code;
    $msg  = strtolower( $message );

    // 401 / 403 → invalid key
    if ( $code === 401 || $code === 403 || str_contains( $msg, 'invalid api key' ) || str_contains( $msg, 'authentication' ) || str_contains( $msg, 'permission' ) ) {
        return [
            'severity' => 'critical',
            'hint'     => 'Check your API key in Settings → Provider Keys.',
        ];
    }

    // 402 or quota / billing errors (often 429 with "quota" / "billing" wording)
    if ( $code === 402 || str_contains( $msg, 'quota' ) || str_contains( $msg, 'billing' ) || str_contains( $msg, 'insufficient_quota' ) || str_contains( $msg, 'exceeded your current' ) ) {
        return [
            'severity' => 'critical',
            'hint'     => 'Your account has no balance — add credits or check your billing plan.',
        ];
    }

    // 429 rate-limit (after quota check above)
    if ( $code === 429 || str_contains( $msg, 'rate limit' ) || str_contains( $msg, 'too many requests' ) ) {
        return [
            'severity' => 'warning',
            'hint'     => 'Too many requests — consider lowering traffic or upgrading your plan.',
        ];
    }

    // 404 model not found
    if ( $code === 404 || str_contains( $msg, 'model not found' ) || str_contains( $msg, 'does not exist' ) || str_contains( $msg, 'retired' ) ) {
        return [
            'severity' => 'warning',
            'hint'     => 'Model not found or retired — update the model in your bot configuration.',
        ];
    }

    // 5xx server errors
    if ( $code >= 500 && $code < 600 ) {
        return [
            'severity' => 'info',
            'hint'     => 'Temporary server error on the provider side — usually resolves itself.',
        ];
    }

    // fallback
    return [
        'severity' => 'warning',
        'hint'     => 'Unexpected provider error — see the message for details.',
    ];
}

/* ─── record ──────────────────────────────────────────────────────── */

/**
 * Record a provider error into the transient buffer.
 *
 * @param string     $provider  e.g. openai, claude, gemini.
 * @param string     $model     Model slug used in the request.
 * @param int|string $http_code HTTP status code (0 if unknown).
 * @param string     $message   Raw error text from the provider.
 * @param string     $bot_slug  Bot slug that triggered the request.
 */
function aichat_record_provider_error( $provider, $model, $http_code, $message, $bot_slug = '' ) {
    $errors = get_transient( AICHAT_ERROR_TRANSIENT );
    if ( ! is_array( $errors ) ) {
        $errors = [];
    }

    $classification = aichat_classify_provider_error( $http_code, $message );

    $entry = [
        'ts'       => current_time( 'mysql' ),
        'provider' => sanitize_text_field( $provider ),
        'model'    => sanitize_text_field( $model ),
        'code'     => (int) $http_code,
        'message'  => mb_substr( sanitize_text_field( $message ), 0, 300 ),
        'bot'      => sanitize_text_field( $bot_slug ),
        'severity' => $classification['severity'],
        'hint'     => $classification['hint'],
    ];

    // Push to the end; trim oldest if over limit.
    $errors[] = $entry;
    if ( count( $errors ) > AICHAT_ERROR_MAX_BUFFER ) {
        $errors = array_slice( $errors, -AICHAT_ERROR_MAX_BUFFER );
    }

    set_transient( AICHAT_ERROR_TRANSIENT, $errors, AICHAT_ERROR_TTL );
}

/* ─── read helpers ────────────────────────────────────────────────── */

/**
 * Get all recorded provider errors (newest first).
 *
 * @return array
 */
function aichat_get_provider_errors() {
    $errors = get_transient( AICHAT_ERROR_TRANSIENT );
    if ( ! is_array( $errors ) ) {
        return [];
    }
    return array_reverse( $errors ); // newest first
}

/**
 * Count of current errors in the buffer (for badges).
 *
 * @return int
 */
function aichat_provider_error_badge_count() {
    $errors = get_transient( AICHAT_ERROR_TRANSIENT );
    return is_array( $errors ) ? count( $errors ) : 0;
}

/* ─── dismiss ─────────────────────────────────────────────────────── */

/**
 * AJAX handler: dismiss (clear) all recorded provider errors.
 */
function aichat_ajax_dismiss_provider_errors() {
    check_ajax_referer( 'aichat_dismiss_errors', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }

    delete_transient( AICHAT_ERROR_TRANSIENT );
    wp_send_json_success();
}
add_action( 'wp_ajax_aichat_dismiss_provider_errors', 'aichat_ajax_dismiss_provider_errors' );

/* ─── menu badges ─────────────────────────────────────────────────── */

/**
 * Append error-count badges to Bots & Logs submenu titles.
 * Hooked at admin_menu priority 99 (before agency strips at 99999).
 */
function aichat_maybe_add_error_badges() {
    $count = aichat_provider_error_badge_count();
    if ( $count < 1 ) {
        return;
    }

    global $submenu;
    if ( empty( $submenu['aichat-settings'] ) ) {
        return;
    }

    $badge = sprintf(
        ' <span class="update-plugins count-%1$d"><span class="plugin-count">%1$d</span></span>',
        $count
    );

    $target_slugs = [ 'aichat-bots-settings', 'aichat-logs' ];

    foreach ( $submenu['aichat-settings'] as &$item ) {
        // $item[2] = slug
        if ( in_array( $item[2], $target_slugs, true ) ) {
            // Avoid double-appending on multiple calls.
            if ( strpos( $item[0], 'update-plugins' ) === false ) {
                $item[0] .= $badge;
            }
        }
    }
    unset( $item );
}
add_action( 'admin_menu', 'aichat_maybe_add_error_badges', 99 );

/* ─── admin notice (rendered on Bots & Logs pages) ────────────────── */

/**
 * Render the provider-error notice banner at the top of Bots / Logs pages.
 */
function aichat_render_provider_error_notice() {
    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }

    // Match the Bots and Logs page screen IDs.
    $allowed = [
        'axiachat-ai_page_aichat-bots-settings',
        'axiachat-ai_page_aichat-logs',
    ];
    if ( ! in_array( $screen->id, $allowed, true ) ) {
        return;
    }

    $errors = aichat_get_provider_errors();
    if ( empty( $errors ) ) {
        return;
    }

    // Check highest severity for banner colour.
    $has_critical = false;
    foreach ( $errors as $e ) {
        if ( $e['severity'] === 'critical' ) { $has_critical = true; break; }
    }
    $notice_class = $has_critical ? 'notice-error' : 'notice-warning';

    $dismiss_nonce = wp_create_nonce( 'aichat_dismiss_errors' );
    ?>
    <div class="notice <?php echo esc_attr( $notice_class ); ?> aichat-provider-error-notice" style="position:relative">
        <p>
            <strong>⚠ Provider Errors Detected</strong> —
            <?php echo esc_html( count( $errors ) ); ?> recent error(s) from your AI providers.
            <a href="#" class="aichat-toggle-error-details" style="margin-left:6px">Show details ▾</a>
        </p>
        <table class="widefat striped aichat-error-table" style="display:none;margin:8px 0 12px">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Severity</th>
                    <th>Provider</th>
                    <th>Model</th>
                    <th>Bot</th>
                    <th>Message</th>
                    <th>Hint</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $errors as $err ) : ?>
                <tr>
                    <td><?php echo esc_html( $err['ts'] ); ?></td>
                    <td>
                        <?php
                        $sev_labels = [ 'critical' => '🔴 Critical', 'warning' => '🟡 Warning', 'info' => '🔵 Info' ];
                        echo esc_html( $sev_labels[ $err['severity'] ] ?? $err['severity'] );
                        ?>
                    </td>
                    <td><?php echo esc_html( ucfirst( $err['provider'] ) ); ?></td>
                    <td><code><?php echo esc_html( $err['model'] ); ?></code></td>
                    <td><?php echo esc_html( $err['bot'] ?: '—' ); ?></td>
                    <td style="max-width:280px;word-break:break-word"><?php echo esc_html( $err['message'] ); ?></td>
                    <td style="max-width:220px"><?php echo esc_html( $err['hint'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <button type="button" class="button button-small aichat-dismiss-errors" data-nonce="<?php echo esc_attr( $dismiss_nonce ); ?>">
                Dismiss All
            </button>
        </p>
    </div>
    <?php
    // Enqueue external error-notice script
    wp_enqueue_script(
        'aichat-error-notice',
        AICHAT_PLUGIN_URL . 'assets/js/error-notice.js',
        [ 'jquery' ],
        defined( 'AICHAT_VERSION' ) ? AICHAT_VERSION : '1.0.0',
        true
    );
}
add_action( 'admin_notices', 'aichat_render_provider_error_notice' );
