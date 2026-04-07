<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * General tab markup for AI Chat settings.
 *
 * Assumes variables from parent scope: $openai_key, $claude_key, $gemini_key,
 * $bots, $global_on, $global_slug.
 */
?>
<div class="tab-pane active" id="aichat-tab-general" role="tabpanel" aria-labelledby="aichat-tab-link-general" aria-hidden="false">
    <div class="row g-4">
<?php 
/**
 * Permite que un add-on (ej: Agency Connector) oculte el card de API keys.
 *
 * @since 1.3.0
 * @param bool $show True para mostrar, false para ocultar.
 */
$aichat_show_api_keys_card = apply_filters( 'aichat_settings_show_api_keys', true );
if ( $aichat_show_api_keys_card ) {
    ?>
        <div class="col-12">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-primary text-white d-flex align-items-center">
                    <i class="bi bi-key-fill me-2"></i><strong><?php 
    echo esc_html__( 'API Keys', 'axiachat-ai' );
    ?></strong>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="aichat_openai_api_key" class="form-label fw-semibold"><?php 
    echo esc_html__( 'OpenAI API Key', 'axiachat-ai' );
    ?></label>
                        <div class="input-group">
                            <input type="password" autocomplete="off" class="form-control" id="aichat_openai_api_key" name="aichat_openai_api_key" value="<?php 
    echo esc_attr( $openai_key );
    ?>" />
                            <button class="btn btn-outline-secondary aichat-toggle-secret" type="button" data-target="aichat_openai_api_key" aria-label="Toggle visibility"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text"><?php 
    echo esc_html__( 'API key to use OpenAI models.', 'axiachat-ai' );
    ?></div>
                    </div>
                    <div class="mb-3">
                        <label for="aichat_claude_api_key" class="form-label fw-semibold"><?php 
    echo esc_html__( 'Claude (Anthropic) API Key', 'axiachat-ai' );
    ?></label>
                        <div class="input-group">
                            <input type="password" autocomplete="off" class="form-control" id="aichat_claude_api_key" name="aichat_claude_api_key" value="<?php 
    echo esc_attr( $claude_key );
    ?>" />
                            <button class="btn btn-outline-secondary aichat-toggle-secret" type="button" data-target="aichat_claude_api_key" aria-label="Toggle visibility"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text"><?php 
    echo esc_html__( 'API key to use Anthropic (Claude) models.', 'axiachat-ai' );
    ?></div>
                    </div>
                    <div class="mb-0">
                        <label for="aichat_gemini_api_key" class="form-label fw-semibold"><?php 
    echo esc_html__( 'Google Gemini API Key', 'axiachat-ai' );
    ?></label>
                        <div class="input-group">
                            <input type="password" autocomplete="off" class="form-control" id="aichat_gemini_api_key" name="aichat_gemini_api_key" value="<?php 
    echo esc_attr( $gemini_key );
    ?>" />
                            <button class="btn btn-outline-secondary aichat-toggle-secret" type="button" data-target="aichat_gemini_api_key" aria-label="Toggle visibility"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="form-text"><?php 
    echo esc_html__( 'API key to use Google Gemini models. Get your key at aistudio.google.com/apikey', 'axiachat-ai' );
    ?></div>
                    </div>

                    <?php 
    if ( empty( $openai_key ) && empty( $claude_key ) && empty( $gemini_key ) ) {
        ?>
                    <div class="aichat-free-key-tip mt-3">
                        <i class="bi bi-lightbulb"></i>
                        <span>
                            <?php 
        echo esc_html__( "Don't have an API key? Google provides a free API key — enough for ~100 messages/day with Gemini Pro 3.", 'axiachat-ai' );
        ?>
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="aichat-free-key-howto-settings"><?php 
        echo esc_html__( 'How to get it', 'axiachat-ai' );
        ?></button>
                    </div>
                    <?php 
    }
    ?>

                </div>
            </div>
        </div>
<?php 
}
// aichat_settings_show_api_keys
?>
        <div class="col-12">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-secondary text-white d-flex align-items-center">
                    <i class="bi bi-robot me-2"></i><strong><?php 
echo esc_html__( 'Global Bot & Logging', 'axiachat-ai' );
?></strong>
                </div>
                <div class="card-body">
                    <!-- 1) Enable global floating bot -->
                    <div class="aichat-checkbox-row mb-3">
                        <input type="hidden" name="aichat_global_bot_enabled" value="0" />
                        <label for="aichat_global_bot_enabled" class="aichat-checkbox-label">
                            <input type="checkbox" id="aichat_global_bot_enabled" name="aichat_global_bot_enabled" value="1" <?php 
checked( $global_on );
?> />
                            <span><?php 
echo esc_html__( 'Enable global floating bot', 'axiachat-ai' );
?></span>
                        </label>
                        <div class="form-text ms-0"><?php 
echo esc_html__( 'Shortcode [aichat bot="..."] on a page suppresses the global bot there.', 'axiachat-ai' );
?></div>
                    </div>
                    <!-- 2) Bot selector -->
                    <div class="mb-3">
                        <label for="aichat_global_bot_slug" class="form-label fw-semibold"><?php 
echo esc_html__( 'Global Bot', 'axiachat-ai' );
?></label>
                        <?php 
if ( empty( $bots ) ) {
    ?>
                            <select id="aichat_global_bot_slug" class="form-select" disabled name="aichat_global_bot_slug"><option><?php 
    echo esc_html__( 'No bots defined yet', 'axiachat-ai' );
    ?></option></select>
                            <div class="form-text">
                                <?php 
    /* translators: %s: URL to AI Chat Bots settings page. */
    printf( wp_kses_post( __( 'Create one in <a href="%s">AI Chat → Bots</a>.', 'axiachat-ai' ) ), esc_url( admin_url( 'admin.php?page=aichat-bots-settings' ) ) );
    ?>
                            </div>
                        <?php 
} else {
    ?>
                            <select id="aichat_global_bot_slug" class="form-select" name="aichat_global_bot_slug">
                                <?php 
    foreach ( $bots as $aichat_bot ) {
        ?>
                                    <option value="<?php 
        echo esc_attr( $aichat_bot['slug'] );
        ?>" <?php 
        selected( $global_slug, $aichat_bot['slug'] );
        ?>><?php 
        echo esc_html( $aichat_bot['name'] . ' (' . $aichat_bot['slug'] . ')' );
        ?></option>
                                <?php 
    }
    ?>
                            </select>
                            <div class="form-text"><?php 
    echo esc_html__( 'Bot used when global floating bot is active.', 'axiachat-ai' );
    ?></div>
                        <?php 
}
?>
                    </div>
                    <!-- 3) Test Page -->
                    <?php 
$aichat_test_url = ( function_exists( 'aichat_get_test_page_url' ) ? aichat_get_test_page_url() : false );
if ( $aichat_test_url ) {
    ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="bi bi-eye me-1"></i><?php 
    echo esc_html__( 'Test Page', 'axiachat-ai' );
    ?></label>
                        <div class="d-flex align-items-center gap-2">
                            <a href="<?php 
    echo esc_url( $aichat_test_url );
    ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-box-arrow-up-right me-1"></i><?php 
    echo esc_html__( 'Open test page', 'axiachat-ai' );
    ?>
                            </a>
                        </div>
                        <div class="form-text"><?php 
    echo esc_html__( 'Preview your bot on a real page with your theme styles — without exposing it to visitors.', 'axiachat-ai' );
    ?></div>
                    </div>
                    <?php 
}
?>
                    <!-- 4) Conversation logging -->
                    <hr class="my-3" />
                    <div class="aichat-checkbox-row mb-3">
                        <input type="hidden" name="aichat_logging_enabled" value="0" />
                        <label for="aichat_logging_enabled" class="aichat-checkbox-label">
                            <input type="checkbox" id="aichat_logging_enabled" name="aichat_logging_enabled" value="1" <?php 
checked( (int) aichat_get_setting( 'aichat_logging_enabled' ), 1 );
?> />
                            <span><?php 
echo esc_html__( 'Conversation logging', 'axiachat-ai' );
?></span>
                        </label>
                        <div class="form-text ms-0"><?php 
echo esc_html__( 'Disable to stop saving new messages (existing records remain).', 'axiachat-ai' );
?></div>
                    </div>
                    <!-- 5) Email Alerts -->
                    <?php 
$aichat_email_alerts_on = (int) aichat_get_setting( 'aichat_email_alerts_enabled' );
$aichat_email_alerts_address = aichat_get_setting( 'aichat_email_alerts_address' );
if ( !$aichat_email_alerts_address ) {
    $aichat_email_alerts_address = get_option( 'admin_email' );
}
$aichat_email_alerts_content = ( aichat_get_setting( 'aichat_email_alerts_content' ) ?: 'full' );
$aichat_email_alerts_mode = ( aichat_get_setting( 'aichat_email_alerts_mode' ) ?: 'each' );
$aichat_email_alerts_idle = (int) (( aichat_get_setting( 'aichat_email_alerts_idle_minutes' ) ?: 15 ));
?>
                    <div class="aichat-checkbox-row mb-2">
                        <input type="hidden" name="aichat_email_alerts_enabled" value="0" />
                        <label for="aichat_email_alerts_enabled" class="aichat-checkbox-label">
                            <input type="checkbox" id="aichat_email_alerts_enabled" name="aichat_email_alerts_enabled" value="1" <?php 
checked( $aichat_email_alerts_on, 1 );
?> />
                            <span><?php 
echo esc_html__( 'Email alerts for new conversations', 'axiachat-ai' );
?></span>
                        </label>
                    </div>
                    <div id="aichat-email-alerts-fields" class="ms-4 mb-0" style="<?php 
echo ( $aichat_email_alerts_on ? '' : 'display:none;' );
?>">
                        <div class="mb-2">
                            <label for="aichat_email_alerts_address" class="form-label fw-semibold mb-1"><?php 
echo esc_html__( 'Recipient email', 'axiachat-ai' );
?></label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="email" class="form-control form-control-sm" id="aichat_email_alerts_address" name="aichat_email_alerts_address" value="<?php 
echo esc_attr( $aichat_email_alerts_address );
?>" style="max-width:340px;" />
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="aichat-email-alerts-advanced-btn" title="<?php 
echo esc_attr__( 'Advanced options', 'axiachat-ai' );
?>">
                                    <i class="bi bi-gear me-1"></i><?php 
echo esc_html__( 'Advanced', 'axiachat-ai' );
?>
                                </button>
                            </div>
                            <div class="form-text"><?php 
echo esc_html__( 'Receive an email notification when users chat with your bot.', 'axiachat-ai' );
?></div>
                        </div>
                        <!-- Hidden fields that the Advanced modal populates -->
                        <input type="hidden" id="aichat_email_alerts_content" name="aichat_email_alerts_content" value="<?php 
echo esc_attr( $aichat_email_alerts_content );
?>" />
                        <input type="hidden" id="aichat_email_alerts_mode" name="aichat_email_alerts_mode" value="<?php 
echo esc_attr( $aichat_email_alerts_mode );
?>" />
                        <input type="hidden" id="aichat_email_alerts_idle_minutes" name="aichat_email_alerts_idle_minutes" value="<?php 
echo esc_attr( $aichat_email_alerts_idle );
?>" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Email Alerts Advanced Modal -->
        <div class="modal fade" id="aichat-email-alerts-modal" tabindex="-1" aria-labelledby="aichat-email-alerts-modal-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-secondary text-white">
                        <h5 class="modal-title" id="aichat-email-alerts-modal-label"><i class="bi bi-envelope-gear me-2"></i><?php 
echo esc_html__( 'Email Alerts — Advanced Options', 'axiachat-ai' );
?></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Email content -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><?php 
echo esc_html__( 'Email content', 'axiachat-ai' );
?></label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="_aichat_modal_content" id="aichat_modal_content_full" value="full" <?php 
checked( $aichat_email_alerts_content, 'full' );
?> />
                                <label class="form-check-label" for="aichat_modal_content_full">
                                    <?php 
echo esc_html__( 'Full conversation', 'axiachat-ai' );
?>
                                    <span class="text-muted d-block" style="font-size:12px;"><?php 
echo esc_html__( 'The email includes the complete conversation transcript.', 'axiachat-ai' );
?></span>
                                </label>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="_aichat_modal_content" id="aichat_modal_content_summary" value="summary" <?php 
checked( $aichat_email_alerts_content, 'summary' );
?> />
                                <label class="form-check-label" for="aichat_modal_content_summary">
                                    <?php 
echo esc_html__( 'Summary only', 'axiachat-ai' );
?>
                                    <span class="text-muted d-block" style="font-size:12px;"><?php 
echo esc_html__( 'A brief notification with session info and a link to the logs.', 'axiachat-ai' );
?></span>
                                </label>
                            </div>
                        </div>
                        <!-- Notification mode -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><?php 
echo esc_html__( 'Notification frequency', 'axiachat-ai' );
?></label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="_aichat_modal_mode" id="aichat_modal_mode_each" value="each" <?php 
checked( $aichat_email_alerts_mode, 'each' );
?> />
                                <label class="form-check-label" for="aichat_modal_mode_each">
                                    <?php 
echo esc_html__( 'Per conversation', 'axiachat-ai' );
?>
                                    <span class="text-muted d-block" style="font-size:12px;"><?php 
echo esc_html__( 'One email per finished conversation.', 'axiachat-ai' );
?></span>
                                </label>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="_aichat_modal_mode" id="aichat_modal_mode_digest" value="digest" <?php 
checked( $aichat_email_alerts_mode, 'digest' );
?> />
                                <label class="form-check-label" for="aichat_modal_mode_digest">
                                    <?php 
echo esc_html__( 'Digest', 'axiachat-ai' );
?>
                                    <span class="text-muted d-block" style="font-size:12px;"><?php 
echo esc_html__( 'A single email summarizing all new conversations since the last digest.', 'axiachat-ai' );
?></span>
                                </label>
                            </div>
                        </div>
                        <!-- Idle threshold -->
                        <div class="mb-0">
                            <label for="aichat_modal_idle_minutes" class="form-label fw-semibold"><?php 
echo esc_html__( 'Idle time (minutes)', 'axiachat-ai' );
?></label>
                            <input type="number" class="form-control form-control-sm" id="aichat_modal_idle_minutes" min="5" max="120" step="5" value="<?php 
echo esc_attr( $aichat_email_alerts_idle );
?>" style="max-width:120px;" />
                            <div class="form-text"><?php 
echo esc_html__( 'Minutes of user inactivity before a conversation is considered finished and the alert is sent.', 'axiachat-ai' );
?></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php 
echo esc_html__( 'Cancel', 'axiachat-ai' );
?></button>
                        <button type="button" class="btn btn-primary btn-sm" id="aichat-email-alerts-modal-save"><?php 
echo esc_html__( 'Apply', 'axiachat-ai' );
?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php 
?>
    </div>
</div>
