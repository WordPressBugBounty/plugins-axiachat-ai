<?php
/**
 * AI Chat — Email Alerts for New Conversations
 *
 * Sends email notifications to the configured admin address when a conversation
 * is considered "finished" (idle for N minutes since the last user message).
 *
 * Modes:
 *  - "each"   → one email per finished conversation.
 *  - "digest" → a single email summarising all finished conversations since last run.
 *
 * Content:
 *  - "full"    → includes the complete conversation transcript.
 *  - "summary" → short notification with session meta + link to logs.
 *
 * @package AIChat
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/* =========================================================================
   1. Custom cron interval (every 15 minutes)
========================================================================= */

add_filter( 'cron_schedules', 'aichat_email_alerts_cron_schedules' );
function aichat_email_alerts_cron_schedules( $schedules ) {
    if ( ! isset( $schedules['aichat_every_15min'] ) ) {
        $schedules['aichat_every_15min'] = [
            'interval' => 900,
            'display'  => __( 'Every 15 Minutes', 'axiachat-ai' ),
        ];
    }
    return $schedules;
}

/* =========================================================================
   2. Schedule / un-schedule the cron event
========================================================================= */

add_action( 'admin_init', 'aichat_email_alerts_maybe_schedule' );
function aichat_email_alerts_maybe_schedule() {
    $enabled = (int) get_option( 'aichat_email_alerts_enabled', 1 );
    $hook    = 'aichat_email_alerts_cron';

    if ( $enabled ) {
        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time() + 60, 'aichat_every_15min', $hook );
        }
    } else {
        $ts = wp_next_scheduled( $hook );
        if ( $ts ) {
            wp_unschedule_event( $ts, $hook );
        }
    }
}

// Clean up on deactivation.
register_deactivation_hook( AICHAT_PLUGIN_FILE, 'aichat_email_alerts_deactivate' );
function aichat_email_alerts_deactivate() {
    $ts = wp_next_scheduled( 'aichat_email_alerts_cron' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'aichat_email_alerts_cron' );
    }
}

/* =========================================================================
   3. Queue: track sessions that need to be notified
      Stored as a JSON-encoded array of session_ids in an option.
========================================================================= */

/**
 * Hook fired every time a conversation row is saved.
 * Adds the session_id to the pending-notification queue.
 *
 * @param array $data { id, bot_slug, session_id, user_id, page_id }
 */
add_action( 'aichat_conversation_saved', 'aichat_email_alerts_enqueue_session' );
function aichat_email_alerts_enqueue_session( $data ) {
    if ( ! (int) get_option( 'aichat_email_alerts_enabled', 1 ) ) {
        return;
    }
    $session_id = isset( $data['session_id'] ) ? sanitize_text_field( $data['session_id'] ) : '';
    if ( $session_id === '' ) {
        return;
    }

    $queue = get_option( 'aichat_email_alerts_queue', [] );
    if ( ! is_array( $queue ) ) {
        $queue = [];
    }

    // Store / update the last-activity timestamp for this session.
    $queue[ $session_id ] = time();

    // Cap queue size to prevent bloat (keep newest 500).
    if ( count( $queue ) > 500 ) {
        arsort( $queue );
        $queue = array_slice( $queue, 0, 500, true );
    }

    update_option( 'aichat_email_alerts_queue', $queue, false );
}

/* =========================================================================
   4. Cron handler — process the queue, send emails
========================================================================= */

add_action( 'aichat_email_alerts_cron', 'aichat_email_alerts_process' );
function aichat_email_alerts_process() {
    if ( ! (int) get_option( 'aichat_email_alerts_enabled', 1 ) ) {
        return;
    }

    $queue = get_option( 'aichat_email_alerts_queue', [] );
    if ( ! is_array( $queue ) || empty( $queue ) ) {
        return;
    }

    $idle_minutes = (int) get_option( 'aichat_email_alerts_idle_minutes', 15 );
    if ( $idle_minutes < 5 ) { $idle_minutes = 5; }
    $idle_seconds = $idle_minutes * 60;
    $now          = time();

    // Collect sessions that have been idle long enough.
    $ready_sessions = [];
    foreach ( $queue as $sid => $last_ts ) {
        if ( ( $now - (int) $last_ts ) >= $idle_seconds ) {
            $ready_sessions[] = $sid;
        }
    }

    if ( empty( $ready_sessions ) ) {
        return;
    }

    // Remove ready sessions from queue immediately to prevent duplicate sends.
    foreach ( $ready_sessions as $sid ) {
        unset( $queue[ $sid ] );
    }
    update_option( 'aichat_email_alerts_queue', $queue, false );

    $mode    = get_option( 'aichat_email_alerts_mode', 'each' );
    $content = get_option( 'aichat_email_alerts_content', 'full' );
    $to      = get_option( 'aichat_email_alerts_address', get_option( 'admin_email' ) );
    if ( ! is_email( $to ) ) {
        $to = get_option( 'admin_email' );
    }

    if ( $mode === 'digest' ) {
        aichat_email_alerts_send_digest( $to, $ready_sessions, $content );
    } else {
        foreach ( $ready_sessions as $sid ) {
            aichat_email_alerts_send_single( $to, $sid, $content );
        }
    }

    // Store timestamp of last successful run.
    update_option( 'aichat_email_alerts_last_run', $now, false );
}

/* =========================================================================
   5. Email builders
========================================================================= */

/**
 * Fetch all conversation rows for a session_id, ordered chronologically.
 *
 * @param string $session_id
 * @return array|null
 */
function aichat_email_alerts_get_conversation( $session_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'aichat_conversations';

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted plugin table name.
    $rows = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM `$table` WHERE session_id = %s ORDER BY id ASC", $session_id ),
        ARRAY_A
    );
    return $rows ?: null;
}

/**
 * Build an HTML representation of a single conversation.
 *
 * @param array  $rows  Rows from wp_aichat_conversations.
 * @param string $mode  'full' or 'summary'.
 * @return string HTML fragment.
 */
function aichat_email_alerts_build_conversation_html( $rows, $mode = 'full' ) {
    if ( empty( $rows ) ) {
        return '';
    }

    $first    = $rows[0];
    $bot_slug = $first['bot_slug'] ?? '—';
    $sess     = $first['session_id'] ?? '—';
    $created  = $first['created_at'] ?? '';
    $count    = count( $rows );
    $logs_url = admin_url( 'admin.php?page=aichat-logs&session=' . urlencode( $sess ) );

    $html  = '<table cellpadding="6" cellspacing="0" style="border:1px solid #ddd;border-collapse:collapse;width:100%;font-family:sans-serif;font-size:14px;">';
    $html .= '<tr style="background:#f7f7f7;"><td style="padding:8px 12px;border-bottom:1px solid #ddd;" colspan="2">';
    $html .= '<strong>' . esc_html__( 'Bot', 'axiachat-ai' ) . ':</strong> ' . esc_html( $bot_slug );
    $html .= ' &nbsp;|&nbsp; <strong>' . esc_html__( 'Session', 'axiachat-ai' ) . ':</strong> <code>' . esc_html( substr( $sess, 0, 12 ) ) . '…</code>';
    $html .= ' &nbsp;|&nbsp; <strong>' . esc_html__( 'Messages', 'axiachat-ai' ) . ':</strong> ' . (int) $count;
    $html .= ' &nbsp;|&nbsp; <strong>' . esc_html__( 'Started', 'axiachat-ai' ) . ':</strong> ' . esc_html( $created );
    $html .= '</td></tr>';

    if ( $mode === 'full' ) {
        foreach ( $rows as $row ) {
            // User message
            $html .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;background:#e8f4fd;width:50%;vertical-align:top;">';
            $html .= '<strong style="color:#1a73e8;">👤 ' . esc_html__( 'User', 'axiachat-ai' ) . '</strong><br />';
            $html .= nl2br( esc_html( wp_strip_all_tags( $row['message'] ?? '' ) ) );
            $html .= '</td>';
            // Bot response
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;background:#f0f9f0;width:50%;vertical-align:top;">';
            $html .= '<strong style="color:#0d6f3c;">🤖 ' . esc_html__( 'Bot', 'axiachat-ai' ) . '</strong><br />';
            $html .= nl2br( esc_html( wp_strip_all_tags( $row['response'] ?? '' ) ) );
            $html .= '</td></tr>';
        }
    }

    $html .= '<tr><td colspan="2" style="padding:8px 12px;text-align:center;">';
    $html .= '<a href="' . esc_url( $logs_url ) . '" style="color:#1a73e8;">' . esc_html__( 'View full conversation in Logs →', 'axiachat-ai' ) . '</a>';
    $html .= '</td></tr>';
    $html .= '</table>';

    return $html;
}

/**
 * Send a single email for one conversation.
 */
function aichat_email_alerts_send_single( $to, $session_id, $content_mode ) {
    $rows = aichat_email_alerts_get_conversation( $session_id );
    if ( empty( $rows ) ) {
        return;
    }

    $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $bot_slug  = $rows[0]['bot_slug'] ?? 'unknown';
    $count     = count( $rows );

    $subject = sprintf(
        /* translators: 1: site name, 2: bot slug, 3: message count */
        __( '[%1$s] New conversation — Bot: %2$s (%3$d messages)', 'axiachat-ai' ),
        $site_name,
        $bot_slug,
        $count
    );

    $body  = '<div style="font-family:sans-serif;max-width:700px;margin:auto;">';
    $body .= '<h2 style="color:#333;">' . esc_html__( 'New conversation completed', 'axiachat-ai' ) . '</h2>';
    $body .= aichat_email_alerts_build_conversation_html( $rows, $content_mode );
    $body .= '<p style="font-size:12px;color:#999;margin-top:16px;">';
    $body .= esc_html__( 'This is an automated notification from AxiaChat AI.', 'axiachat-ai' );
    $body .= '</p></div>';

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

    wp_mail( $to, $subject, $body, $headers );
}

/**
 * Send a digest email summarising multiple conversations.
 */
function aichat_email_alerts_send_digest( $to, $session_ids, $content_mode ) {
    if ( empty( $session_ids ) ) {
        return;
    }

    $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $total     = count( $session_ids );

    $subject = sprintf(
        /* translators: 1: site name, 2: number of conversations */
        __( '[%1$s] Chat digest — %2$d new conversation(s)', 'axiachat-ai' ),
        $site_name,
        $total
    );

    $body  = '<div style="font-family:sans-serif;max-width:700px;margin:auto;">';
    $body .= '<h2 style="color:#333;">' . sprintf(
        /* translators: %d: number of new conversations */
        esc_html__( '%d new conversation(s) completed', 'axiachat-ai' ),
        $total
    ) . '</h2>';

    $index = 0;
    foreach ( $session_ids as $sid ) {
        $index++;
        $rows = aichat_email_alerts_get_conversation( $sid );
        if ( empty( $rows ) ) {
            continue;
        }
        $body .= '<h3 style="margin-top:20px;color:#555;">' . sprintf(
            /* translators: %d: conversation number */
            esc_html__( 'Conversation #%d', 'axiachat-ai' ),
            $index
        ) . '</h3>';
        $body .= aichat_email_alerts_build_conversation_html( $rows, $content_mode );
    }

    $body .= '<p style="font-size:12px;color:#999;margin-top:16px;">';
    $body .= esc_html__( 'This is an automated notification from AxiaChat AI.', 'axiachat-ai' );
    $body .= '</p></div>';

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

    wp_mail( $to, $subject, $body, $headers );
}
