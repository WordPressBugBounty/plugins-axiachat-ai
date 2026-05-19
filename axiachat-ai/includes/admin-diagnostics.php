<?php
/**
 * Admin diagnostics runner for Settings > Advanced.
 *
 * Keeps each diagnostic step isolated behind AJAX so the UI can show progress
 * and continue after individual failures.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_aichat_run_diagnostic_step', 'aichat_ajax_run_diagnostic_step' );

function aichat_ajax_run_diagnostic_step() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'axiachat-ai' ) ], 403 );
    }

    check_ajax_referer( 'aichat_settings', 'nonce' );

    $step      = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
    $bot_slug  = isset( $_POST['bot_slug'] ) ? sanitize_title( wp_unslash( $_POST['bot_slug'] ) ) : '';
    $raw_state = isset( $_POST['state'] ) ? sanitize_textarea_field( wp_unslash( $_POST['state'] ) ) : '';
    $state     = aichat_diagnostic_decode_state( $raw_state );

    $allowed_steps = [
        'environment',
        'config',
        'http_probe',
        'embedding',
        'context',
        'chat_1',
        'chat_2',
        'chat_3',
        'tool',
        'timeout',
    ];

    if ( ! in_array( $step, $allowed_steps, true ) ) {
        wp_send_json_error( [ 'message' => __( 'Invalid diagnostic step.', 'axiachat-ai' ) ], 400 );
    }

    $started = microtime( true );
    try {
        $result = aichat_diagnostic_run_step( $step, $bot_slug, $state );
    } catch ( Throwable $exception ) {
        $result = aichat_diagnostic_result(
            $step,
            'fail',
            __( 'The diagnostic step crashed before completing.', 'axiachat-ai' ),
            [
                'error' => aichat_diagnostic_redact( $exception->getMessage() ),
                'type'  => get_class( $exception ),
            ],
            $state
        );
    }

    $result['elapsed_ms'] = isset( $result['elapsed_ms'] )
        ? (int) $result['elapsed_ms']
        : (int) round( ( microtime( true ) - $started ) * 1000 );

    wp_send_json_success( $result );
}

function aichat_diagnostic_decode_state( $raw_state ) {
    $raw_state = (string) $raw_state;
    if ( $raw_state === '' || strlen( $raw_state ) > 25000 ) {
        return [];
    }

    $decoded = json_decode( $raw_state, true );
    return is_array( $decoded ) ? $decoded : [];
}

function aichat_diagnostic_run_step( $step, $bot_slug, array $state ) {
    switch ( $step ) {
        case 'environment':
            return aichat_diagnostic_environment_step( $step, $state );
        case 'config':
            return aichat_diagnostic_config_step( $step, $bot_slug, $state );
        case 'http_probe':
            return aichat_diagnostic_http_probe_step( $step, $bot_slug, $state );
        case 'embedding':
            return aichat_diagnostic_embedding_step( $step, $bot_slug, $state );
        case 'context':
            return aichat_diagnostic_context_step( $step, $bot_slug, $state );
        case 'chat_1':
        case 'chat_2':
        case 'chat_3':
            return aichat_diagnostic_chat_step( $step, $bot_slug, $state );
        case 'tool':
            return aichat_diagnostic_tool_step( $step, $bot_slug, $state );
        case 'timeout':
            return aichat_diagnostic_timeout_step( $step, $bot_slug, $state );
    }

    return aichat_diagnostic_result( $step, 'fail', __( 'Unknown diagnostic step.', 'axiachat-ai' ), [], $state );
}

function aichat_diagnostic_environment_step( $step, array $state ) {
    $curl_loaded  = extension_loaded( 'curl' );
    $curl_version = $curl_loaded ? (string) phpversion( 'curl' ) : '';
    $details      = [
        'plugin_version'       => defined( 'AICHAT_VERSION' ) ? AICHAT_VERSION : 'unknown',
        'wordpress_version'    => get_bloginfo( 'version' ),
        'php_version'          => PHP_VERSION,
        'php_sapi'             => PHP_SAPI,
        'wp_http_available'    => class_exists( 'WP_Http' ) ? 'yes' : 'no',
        'curl_extension'       => $curl_loaded ? 'yes' : 'no',
        'curl_version'         => $curl_version,
        'openssl_version'      => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : '',
        'max_execution_time'   => ini_get( 'max_execution_time' ),
        'memory_limit'         => ini_get( 'memory_limit' ),
        'allow_url_fopen'      => ini_get( 'allow_url_fopen' ) ? 'yes' : 'no',
        'site_url_host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
        'server_time_utc'      => gmdate( 'c' ),
    ];

    $status  = $curl_loaded ? 'pass' : 'warn';
    $message = $curl_loaded
        ? __( 'Runtime looks ready for HTTP diagnostics.', 'axiachat-ai' )
        : __( 'PHP cURL is not available; timing details will be limited.', 'axiachat-ai' );

    return aichat_diagnostic_result( $step, $status, $message, $details, $state );
}

function aichat_diagnostic_config_step( $step, $bot_slug, array $state ) {
    $context = aichat_diagnostic_resolve_bot_context( $bot_slug );
    if ( is_wp_error( $context ) ) {
        return aichat_diagnostic_result(
            $step,
            'fail',
            $context->get_error_message(),
            [],
            $state
        );
    }

    $bot     = $context['bot'];
    $details = [
        'bot'              => $bot['name'] . ' (' . $bot['slug'] . ')',
        'bot_active'       => ! empty( $bot['is_active'] ) ? 'yes' : 'no',
        'provider'         => $context['provider'],
        'model'            => $context['model'],
        'api_key'          => $context['api_key_present'] ? 'present, length ' . strlen( $context['api_key'] ) : 'missing',
        'agency_proxy'     => $context['agency_enabled'] ? 'enabled' : 'disabled',
        'temperature'      => (string) $context['temperature'],
        'max_tokens'       => (string) $context['max_tokens'],
        'context_mode'     => $context['context_mode'],
        'context_id'       => $context['context_id'] ?: 'none',
        'provider_registry'=> $context['provider_available'] ? 'available' : 'not available',
    ];

    $context_meta = aichat_diagnostic_context_meta( $context['context_id'] );
    if ( $context_meta ) {
        $details['context_name']       = $context_meta['name'];
        $details['context_type']       = $context_meta['context_type'];
        $details['context_chunks']     = (string) $context_meta['chunks'];
        $details['embedding_provider'] = $context_meta['embedding_provider'];
    }

    $state['bot_slug'] = $bot['slug'];
    $state['provider'] = $context['provider'];
    $state['model']    = $context['model'];

    if ( ! $context['provider_available'] ) {
        return aichat_diagnostic_result(
            $step,
            'fail',
            __( 'The selected provider is not registered in the provider registry.', 'axiachat-ai' ),
            $details,
            $state
        );
    }

    if ( ! $context['api_key_present'] && ! $context['agency_enabled'] ) {
        return aichat_diagnostic_result(
            $step,
            'fail',
            __( 'The selected provider has no API key configured.', 'axiachat-ai' ),
            $details,
            $state
        );
    }

    return aichat_diagnostic_result(
        $step,
        'pass',
        __( 'Bot configuration and provider settings were resolved.', 'axiachat-ai' ),
        $details,
        $state
    );
}

function aichat_diagnostic_http_probe_step( $step, $bot_slug, array $state ) {
    $context = aichat_diagnostic_resolve_bot_context( $bot_slug );
    if ( is_wp_error( $context ) ) {
        return aichat_diagnostic_result( $step, 'fail', $context->get_error_message(), [], $state );
    }

    $request_args = aichat_diagnostic_provider_probe_request( $context );
    if ( is_wp_error( $request_args ) ) {
        return aichat_diagnostic_result(
            $step,
            'warn',
            $request_args->get_error_message(),
            [ 'provider' => $context['provider'], 'model' => $context['model'] ],
            $state
        );
    }

    $probe = aichat_diagnostic_http_request( $request_args['url'], $request_args, [ $context['api_key'] ] );
    return aichat_diagnostic_result(
        $step,
        aichat_diagnostic_status_for_http_probe( $probe ),
        aichat_diagnostic_message_for_http_probe( $probe ),
        aichat_diagnostic_probe_details( $probe, $context ),
        $state
    );
}

function aichat_diagnostic_embedding_step( $step, $bot_slug, array $state ) {
    $context = aichat_diagnostic_resolve_bot_context( $bot_slug );
    if ( is_wp_error( $context ) ) {
        return aichat_diagnostic_result( $step, 'fail', $context->get_error_message(), [], $state );
    }

    if ( ! function_exists( 'aichat_generate_embedding' ) ) {
        return aichat_diagnostic_result(
            $step,
            'fail',
            __( 'Embedding function is not loaded.', 'axiachat-ai' ),
            [],
            $state
        );
    }

    $started   = microtime( true );
    $embedding = aichat_generate_embedding( 'AxiaChat diagnostic embedding connectivity test', 'query', $context['provider'] );
    $elapsed   = (int) round( ( microtime( true ) - $started ) * 1000 );

    $details = [
        'requested_provider' => $context['provider'],
        'elapsed_ms'         => $elapsed,
        'dimension'          => is_array( $embedding ) ? count( $embedding ) : 0,
    ];

    if ( $context['provider'] === 'claude' ) {
        $details['note'] = __( 'Claude has no native embeddings API; the plugin uses the configured OpenAI fallback when available.', 'axiachat-ai' );
    }

    if ( is_array( $embedding ) && ! empty( $embedding ) ) {
        return aichat_diagnostic_result(
            $step,
            'pass',
            __( 'Embedding request returned a vector.', 'axiachat-ai' ),
            $details,
            $state
        );
    }

    return aichat_diagnostic_result(
        $step,
        'fail',
        __( 'Embedding request did not return a valid vector.', 'axiachat-ai' ),
        $details,
        $state
    );
}

function aichat_diagnostic_context_step( $step, $bot_slug, array $state ) {
    $context = aichat_diagnostic_resolve_bot_context( $bot_slug );
    if ( is_wp_error( $context ) ) {
        return aichat_diagnostic_result( $step, 'fail', $context->get_error_message(), [], $state );
    }

    if ( $context['context_mode'] === 'none' || $context['context_id'] <= 0 ) {
        return aichat_diagnostic_result(
            $step,
            'warn',
            __( 'The selected bot has no RAG context configured.', 'axiachat-ai' ),
            [
                'context_mode' => $context['context_mode'],
                'context_id'   => $context['context_id'] ?: 'none',
            ],
            $state
        );
    }

    if ( ! function_exists( 'aichat_get_context_for_question' ) ) {
        return aichat_diagnostic_result( $step, 'fail', __( 'Context retrieval function is not loaded.', 'axiachat-ai' ), [], $state );
    }

    $mode     = $context['context_mode'] === 'page' ? 'page' : 'auto';
    $started  = microtime( true );
    $contexts = aichat_get_context_for_question(
        'AxiaChat diagnostic context retrieval test',
        [
            'context_id' => $context['context_id'],
            'mode'       => $mode,
            'limit'      => 3,
            'provider'   => $context['provider'],
        ]
    );
    $elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

    $scores = [];
    foreach ( array_slice( (array) $contexts, 0, 3 ) as $row ) {
        $scores[] = [
            'title' => isset( $row['title'] ) ? mb_substr( (string) $row['title'], 0, 90 ) : '',
            'score' => isset( $row['score'] ) ? round( (float) $row['score'], 4 ) : null,
            'type'  => isset( $row['type'] ) ? (string) $row['type'] : '',
        ];
    }

    $details = [
        'context_mode' => $context['context_mode'],
        'resolved_mode'=> $mode,
        'context_id'   => $context['context_id'],
        'elapsed_ms'   => $elapsed,
        'matches'      => is_array( $contexts ) ? count( $contexts ) : 0,
        'top_matches'  => $scores,
    ];

    if ( is_array( $contexts ) && ! empty( $contexts ) ) {
        return aichat_diagnostic_result( $step, 'pass', __( 'Context retrieval returned matches.', 'axiachat-ai' ), $details, $state );
    }

    return aichat_diagnostic_result( $step, 'warn', __( 'Context retrieval completed but returned no matches.', 'axiachat-ai' ), $details, $state );
}

function aichat_diagnostic_chat_step( $step, $bot_slug, array $state ) {
    $context = aichat_diagnostic_resolve_bot_context( $bot_slug );
    if ( is_wp_error( $context ) ) {
        return aichat_diagnostic_result( $step, 'fail', $context->get_error_message(), [], $state );
    }

    $provider_instance = aichat_diagnostic_provider_instance( $context );
    if ( is_wp_error( $provider_instance ) ) {
        return aichat_diagnostic_result( $step, 'fail', $provider_instance->get_error_message(), [], $state );
    }

    $turn = (int) str_replace( 'chat_', '', $step );
    $messages = isset( $state['chat_messages'] ) && is_array( $state['chat_messages'] )
        ? $state['chat_messages']
        : [
            [
                'role'    => 'system',
                'content' => 'You are running a private AxiaChat diagnostic. Reply briefly and do not include credentials or internal paths.',
            ],
        ];

    $messages[] = [
        'role'    => 'user',
        'content' => 'Diagnostic conversation turn ' . $turn . '. Reply with the token AXIACHAT_DIAG_' . $turn . ' and one short sentence.',
    ];

    $result = $provider_instance->chat(
        $messages,
        [
            'model'        => $context['model'],
            'temperature'  => 0,
            'max_tokens'   => min( 96, max( 32, $context['max_tokens'] ) ),
            'bot_slug'     => $context['bot']['slug'],
            'session_id'   => 'admin-diagnostic',
            'request_uuid' => wp_generate_uuid4(),
            'message'      => 'diagnostic chat turn ' . $turn,
        ]
    );

    if ( ! is_array( $result ) ) {
        return aichat_diagnostic_result( $step, 'fail', __( 'Provider returned an invalid response shape.', 'axiachat-ai' ), [], $state );
    }

    if ( isset( $result['error'] ) ) {
        return aichat_diagnostic_result(
            $step,
            'fail',
            aichat_diagnostic_redact( (string) $result['error'], [ $context['api_key'] ] ),
            aichat_diagnostic_provider_result_details( $result ),
            $state
        );
    }

    $answer = isset( $result['message'] ) ? (string) $result['message'] : '';
    $messages[] = [
        'role'    => 'assistant',
        'content' => mb_substr( $answer, 0, 1000 ),
    ];

    $limited_messages = array_slice( $messages, -7 );
    if ( isset( $messages[0]['role'] ) && $messages[0]['role'] === 'system' ) {
        $limited_has_system = isset( $limited_messages[0]['role'] ) && $limited_messages[0]['role'] === 'system';
        if ( ! $limited_has_system ) {
            array_unshift( $limited_messages, $messages[0] );
        }
    }
    $state['chat_messages'] = array_values( array_slice( $limited_messages, -8 ) );

    $details = aichat_diagnostic_provider_result_details( $result );
    $details['response_excerpt'] = mb_substr( wp_strip_all_tags( $answer ), 0, 300 );

    return aichat_diagnostic_result(
        $step,
        $answer !== '' ? 'pass' : 'warn',
        $answer !== '' ? __( 'Chat turn completed.', 'axiachat-ai' ) : __( 'Provider returned an empty chat answer.', 'axiachat-ai' ),
        $details,
        $state
    );
}

function aichat_diagnostic_tool_step( $step, $bot_slug, array $state ) {
    $context = aichat_diagnostic_resolve_bot_context( $bot_slug );
    if ( is_wp_error( $context ) ) {
        return aichat_diagnostic_result( $step, 'fail', $context->get_error_message(), [], $state );
    }

    $tool_definition = aichat_diagnostic_register_tool();
    $local_result    = call_user_func(
        $tool_definition['callback'],
        [ 'token' => 'local-check' ],
        [ 'source' => 'local' ]
    );

    $details = [
        'local_tool_execution' => is_array( $local_result ) && ! empty( $local_result['ok'] ) ? 'pass' : 'fail',
        'tool_registry'        => function_exists( 'aichat_get_registered_tools' ) ? 'available' : 'not loaded',
    ];

    if ( ! function_exists( 'aichat_get_registered_tools' ) ) {
        return aichat_diagnostic_result(
            $step,
            'warn',
            __( 'The local diagnostic tool ran, but the AI Tools registry is not loaded for provider tool execution.', 'axiachat-ai' ),
            $details,
            $state
        );
    }

    $provider_instance = aichat_diagnostic_provider_instance( $context );
    if ( is_wp_error( $provider_instance ) ) {
        return aichat_diagnostic_result( $step, 'fail', $provider_instance->get_error_message(), $details, $state );
    }

    $GLOBALS['aichat_diagnostic_tool_calls'] = [];

    $tool_schema = $tool_definition['schema'];
    $tool_params = [
        'type'     => 'function',
        'function' => [
            'name'        => 'aichat_diagnostic_ping',
            'description' => $tool_definition['description'],
            'strict'      => true,
            'parameters'  => $tool_schema,
        ],
    ];

    $messages = [
        [
            'role'    => 'system',
            'content' => 'You are running a private diagnostic. You must call the provided diagnostic tool exactly once when the user asks for it.',
        ],
        [
            'role'    => 'user',
            'content' => 'Call the function aichat_diagnostic_ping now with token "model-check". Do not answer from memory.',
        ],
    ];

    $result = $provider_instance->chat(
        $messages,
        [
            'model'           => $context['model'],
            'temperature'     => 0,
            'max_tokens'      => 128,
            'tools'           => [ $tool_params ],
            'max_tool_rounds' => 3,
            'bot_slug'        => $context['bot']['slug'],
            'session_id'      => 'admin-diagnostic',
            'request_uuid'    => wp_generate_uuid4(),
            'message'         => 'diagnostic tool test',
        ]
    );

    if ( is_array( $result ) && isset( $result['status'] ) && $result['status'] === 'tool_pending' ) {
        $pending_names = array_map(
            static function( $tool_call ) {
                return isset( $tool_call['name'] ) ? (string) $tool_call['name'] : '';
            },
            isset( $result['tool_calls'] ) && is_array( $result['tool_calls'] ) ? $result['tool_calls'] : []
        );
        $details['tool_pending'] = implode( ', ', array_filter( $pending_names ) );

        if ( method_exists( $provider_instance, 'continue_from_tool_pending' ) ) {
            $result = $provider_instance->continue_from_tool_pending(
                (string) ( $result['response_id'] ?? '' ),
                isset( $result['tool_calls'] ) && is_array( $result['tool_calls'] ) ? $result['tool_calls'] : []
            );
        } else {
            $details['continuation'] = __( 'Provider returned a tool_pending handshake but does not expose a direct continuation method here.', 'axiachat-ai' );
        }
    }

    if ( ! is_array( $result ) ) {
        return aichat_diagnostic_result( $step, 'fail', __( 'Provider returned an invalid tool response shape.', 'axiachat-ai' ), $details, $state );
    }

    if ( isset( $result['error'] ) ) {
        $details = array_merge( $details, aichat_diagnostic_provider_result_details( $result ) );
        return aichat_diagnostic_result(
            $step,
            'fail',
            aichat_diagnostic_redact( (string) $result['error'], [ $context['api_key'] ] ),
            $details,
            $state
        );
    }

    $model_calls = isset( $GLOBALS['aichat_diagnostic_tool_calls'] ) && is_array( $GLOBALS['aichat_diagnostic_tool_calls'] )
        ? count( $GLOBALS['aichat_diagnostic_tool_calls'] )
        : 0;

    $details = array_merge( $details, aichat_diagnostic_provider_result_details( $result ) );
    $details['model_tool_calls'] = $model_calls;
    if ( isset( $result['message'] ) ) {
        $details['response_excerpt'] = mb_substr( wp_strip_all_tags( (string) $result['message'] ), 0, 300 );
    }

    if ( $model_calls > 0 ) {
        return aichat_diagnostic_result( $step, 'pass', __( 'The model requested and executed the diagnostic tool.', 'axiachat-ai' ), $details, $state );
    }

    if ( ! empty( $details['tool_pending'] ) ) {
        return aichat_diagnostic_result( $step, 'warn', __( 'The model requested the diagnostic tool, but execution was not completed inside this backend step.', 'axiachat-ai' ), $details, $state );
    }

    return aichat_diagnostic_result( $step, 'fail', __( 'The model did not call the diagnostic tool.', 'axiachat-ai' ), $details, $state );
}

function aichat_diagnostic_timeout_step( $step, $bot_slug, array $state ) {
    $context = aichat_diagnostic_resolve_bot_context( $bot_slug );
    if ( is_wp_error( $context ) ) {
        return aichat_diagnostic_result( $step, 'fail', $context->get_error_message(), [], $state );
    }

    $url = aichat_diagnostic_provider_host_url( $context['provider'] );
    if ( ! $url ) {
        return aichat_diagnostic_result( $step, 'warn', __( 'No provider host probe is defined for this provider.', 'axiachat-ai' ), [ 'provider' => $context['provider'] ], $state );
    }

    $probe = aichat_diagnostic_http_request(
        $url,
        [
            'method'          => 'GET',
            'headers'         => [],
            'body'            => null,
            'timeout'         => 10,
            'connect_timeout' => 5,
        ],
        []
    );

    $details = aichat_diagnostic_probe_details( $probe, $context );
    $details['diagnostic_timeout'] = '10s total / 5s connect';
    $details['embedding_timeout']  = '25s';
    $details['purpose']            = __( 'This checks provider host reachability and measures DNS/TCP/TLS timing (best effort) plus total request timing without sending credentials.', 'axiachat-ai' );

    $status = aichat_diagnostic_status_for_host_timing( $probe );
    return aichat_diagnostic_result( $step, $status, aichat_diagnostic_message_for_host_timing( $probe ), $details, $state );
}

function aichat_diagnostic_resolve_bot_context( $bot_slug = '' ) {
    global $wpdb;

    $table = $wpdb->prefix . 'aichat_bots';
    $slug  = sanitize_title( (string) $bot_slug );
    $bot   = null;

    if ( $slug !== '' ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin diagnostic lookup in plugin-owned table; table name is built from trusted $wpdb->prefix.
        $bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A );
    }

    if ( ! $bot ) {
        $global_slug = sanitize_title( (string) aichat_get_setting( 'aichat_global_bot_slug' ) );
        if ( $global_slug !== '' ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin diagnostic lookup in plugin-owned table; table name is built from trusted $wpdb->prefix.
            $bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $global_slug ), ARRAY_A );
        }
    }

    if ( ! $bot ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal trusted table name assembled from $wpdb->prefix.
        $bot = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY is_active DESC, id ASC LIMIT 1", ARRAY_A );
    }

    if ( ! $bot ) {
        return new WP_Error( 'aichat_no_bot', __( 'No bot is available to diagnose.', 'axiachat-ai' ) );
    }

    $provider = isset( $bot['provider'] ) ? sanitize_key( (string) $bot['provider'] ) : 'openai';
    if ( $provider === 'anthropic' ) {
        $provider = 'claude';
    }

    $model = isset( $bot['model'] ) ? sanitize_text_field( (string) $bot['model'] ) : '';
    if ( $model === '' && function_exists( 'aichat_get_default_model' ) ) {
        $model = aichat_get_default_model( $provider );
    }
    if ( function_exists( 'aichat_resolve_model' ) ) {
        $model = aichat_resolve_model( $model, $provider );
    }

    $api_key = aichat_diagnostic_get_api_key( $provider );
    $config  = [ 'api_key' => $api_key ];
    if ( $provider === 'openai' ) {
        $organization = aichat_get_setting( 'aichat_openai_organization' );
        if ( ! empty( $organization ) ) {
            $config['organization'] = $organization;
        }
    }

    $provider_available = false;
    if ( class_exists( 'AIChat_Provider_Registry' ) ) {
        $provider_available = AIChat_Provider_Registry::instance()->is_available( $provider );
    }

    return [
        'bot'                => $bot,
        'provider'           => $provider,
        'model'              => $model,
        'api_key'            => $api_key,
        'api_key_present'    => $api_key !== '',
        'provider_config'    => $config,
        'provider_available' => $provider_available,
        'agency_enabled'     => aichat_diagnostic_agency_enabled(),
        'temperature'        => isset( $bot['temperature'] ) ? (float) $bot['temperature'] : 0.7,
        'max_tokens'         => isset( $bot['max_tokens'] ) ? max( 1, (int) $bot['max_tokens'] ) : 2048,
        'context_mode'       => isset( $bot['context_mode'] ) ? sanitize_key( (string) $bot['context_mode'] ) : 'none',
        'context_id'         => isset( $bot['context_id'] ) ? (int) $bot['context_id'] : 0,
    ];
}

function aichat_diagnostic_provider_instance( array $context ) {
    if ( ! class_exists( 'AIChat_Provider_Registry' ) ) {
        return new WP_Error( 'aichat_registry_missing', __( 'Provider registry is not loaded.', 'axiachat-ai' ) );
    }

    if ( ! $context['api_key_present'] && ! $context['agency_enabled'] ) {
        return new WP_Error( 'aichat_api_key_missing', __( 'The selected provider has no API key configured.', 'axiachat-ai' ) );
    }

    $provider_instance = AIChat_Provider_Registry::instance()->get( $context['provider'], $context['provider_config'], false );
    if ( ! $provider_instance ) {
        return new WP_Error( 'aichat_provider_missing', __( 'Could not instantiate the selected provider.', 'axiachat-ai' ) );
    }

    return $provider_instance;
}

function aichat_diagnostic_get_api_key( $provider ) {
    if ( $provider === 'gemini' ) {
        return (string) aichat_get_setting( 'aichat_gemini_api_key' );
    }
    if ( $provider === 'claude' ) {
        return (string) aichat_get_setting( 'aichat_claude_api_key' );
    }
    return (string) aichat_get_setting( 'aichat_openai_api_key' );
}

function aichat_diagnostic_agency_enabled() {
    return ( function_exists( 'aichat_agency_is_configured' ) && aichat_agency_is_configured() )
        || (bool) get_option( 'aichat_agency_enabled', false );
}

function aichat_diagnostic_context_meta( $context_id ) {
    $context_id = (int) $context_id;
    if ( $context_id <= 0 ) {
        return null;
    }

    global $wpdb;
    $contexts_table = $wpdb->prefix . 'aichat_contexts';
    $chunks_table   = $wpdb->prefix . 'aichat_chunks';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin diagnostic lookup in plugin-owned table; table name is built from trusted $wpdb->prefix.
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$contexts_table} WHERE id = %d LIMIT 1", $context_id ), ARRAY_A );
    if ( ! $row ) {
        return null;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin diagnostic count in plugin-owned table; table name is built from trusted $wpdb->prefix.
    $chunks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$chunks_table} WHERE id_context = %d", $context_id ) );

    return [
        'name'               => isset( $row['name'] ) ? (string) $row['name'] : '',
        'context_type'       => isset( $row['context_type'] ) ? (string) $row['context_type'] : '',
        'embedding_provider' => isset( $row['embedding_provider'] ) ? (string) $row['embedding_provider'] : '',
        'chunks'             => $chunks,
    ];
}

function aichat_diagnostic_provider_probe_request( array $context ) {
    if ( ! $context['api_key_present'] ) {
        return new WP_Error( 'aichat_probe_no_key', __( 'Direct provider probe skipped because no API key is configured for this provider.', 'axiachat-ai' ) );
    }

    if ( $context['provider'] === 'gemini' ) {
        return [
            'method'          => 'GET',
            'url'             => 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $context['api_key'] ),
            'headers'         => [],
            'body'            => null,
            'timeout'         => 20,
            'connect_timeout' => 10,
        ];
    }

    if ( $context['provider'] === 'claude' ) {
        return [
            'method'          => 'GET',
            'url'             => 'https://api.anthropic.com/v1/models',
            'headers'         => [
                'x-api-key'         => $context['api_key'],
                'anthropic-version' => '2023-06-01',
            ],
            'body'            => null,
            'timeout'         => 20,
            'connect_timeout' => 10,
        ];
    }

    return [
        'method'          => 'GET',
        'url'             => 'https://api.openai.com/v1/models',
        'headers'         => [ 'Authorization' => 'Bearer ' . $context['api_key'] ],
        'body'            => null,
        'timeout'         => 20,
        'connect_timeout' => 10,
    ];
}

function aichat_diagnostic_provider_host_url( $provider ) {
    if ( $provider === 'gemini' ) {
        return 'https://generativelanguage.googleapis.com/';
    }
    if ( $provider === 'claude' ) {
        return 'https://api.anthropic.com/';
    }
    if ( $provider === 'openai' ) {
        return 'https://api.openai.com/';
    }
    return '';
}

function aichat_diagnostic_http_request( $url, array $request_args, array $secrets ) {
    $method          = strtoupper( (string) ( $request_args['method'] ?? 'GET' ) );
    $headers         = isset( $request_args['headers'] ) && is_array( $request_args['headers'] ) ? $request_args['headers'] : [];
    $body            = $request_args['body'] ?? null;
    $timeout         = max( 1, (int) ( $request_args['timeout'] ?? 20 ) );
    $connect_timeout = max( 1, (int) ( $request_args['connect_timeout'] ?? min( 10, $timeout ) ) );
    $display_url     = aichat_diagnostic_public_url( $url );
    $socket_timings  = aichat_diagnostic_socket_timings( $url, $connect_timeout );

    $timings = [
        'total_ms'   => null,
        'dns_ms'     => isset( $socket_timings['dns_ms'] ) ? $socket_timings['dns_ms'] : null,
        'connect_ms' => isset( $socket_timings['connect_ms'] ) ? $socket_timings['connect_ms'] : null,
        'tls_ms'     => isset( $socket_timings['tls_ms'] ) ? $socket_timings['tls_ms'] : null,
    ];
    if ( isset( $socket_timings['timing_source'] ) ) {
        $timings['timing_source'] = $socket_timings['timing_source'];
    }
    if ( isset( $socket_timings['resolved_ip'] ) && $socket_timings['resolved_ip'] !== '' ) {
        $timings['resolved_ip'] = $socket_timings['resolved_ip'];
    }
    if ( isset( $socket_timings['socket_note'] ) && $socket_timings['socket_note'] !== '' ) {
        $timings['socket_note'] = $socket_timings['socket_note'];
    }

    $started  = microtime( true );
    $response = wp_remote_request(
        $url,
        [
            'method'      => $method,
            'headers'     => $headers,
            'body'        => $body,
            'timeout'     => $timeout,
            'connect_timeout' => $connect_timeout,
            'redirection' => 0,
        ]
    );
    $elapsed = round( ( microtime( true ) - $started ) * 1000 );
    $timings['total_ms'] = (int) $elapsed;

    if ( is_wp_error( $response ) ) {
        return [
            'transport'    => 'wp_http',
            'ok'           => false,
            'errno'        => 0,
            'error'        => aichat_diagnostic_redact( $response->get_error_message(), $secrets ),
            'http_code'    => 0,
            'display_url'  => $display_url,
            'body_excerpt' => '',
            'timings'      => $timings,
        ];
    }

    return [
        'transport'    => 'wp_http',
        'ok'           => true,
        'errno'        => 0,
        'error'        => '',
        'http_code'    => (int) wp_remote_retrieve_response_code( $response ),
        'display_url'  => $display_url,
        'body_excerpt' => aichat_diagnostic_redact( mb_substr( wp_remote_retrieve_body( $response ), 0, 700 ), $secrets ),
        'timings'      => $timings,
    ];
}

/**
 * Execute a callable and capture PHP warnings without using silenced operators.
 *
 * @param callable    $callback      Callback to execute.
 * @param string|null $error_message Filled with warning text if any warning is raised.
 * @return mixed
 */
function aichat_diagnostic_call_with_warning_capture( callable $callback, &$error_message = null ) {
    $captured_warning = null;
    $handler          = static function( $errno, $errstr ) use ( &$captured_warning ) {
        if ( $captured_warning === null ) {
            $captured_warning = (string) $errstr;
        }
        return true;
    };

    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped warning capture for socket probe only; avoids leaking PHP warnings into AJAX JSON.
    set_error_handler( $handler );
    try {
        $result = call_user_func( $callback );
    } finally {
        restore_error_handler();
    }

    $error_message = $captured_warning;
    return $result;
}

/**
 * Best-effort socket timings without direct cURL usage.
 *
 * Returns DNS/TCP/TLS timing when stream sockets are available.
 */
function aichat_diagnostic_socket_timings( $url, $connect_timeout ) {
    $parts = wp_parse_url( (string) $url );
    if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
        return [
            'timing_source' => 'socket_probe',
            'socket_note'   => 'invalid_host',
        ];
    }

    $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
    $host   = (string) $parts['host'];
    $port   = isset( $parts['port'] )
        ? (int) $parts['port']
        : ( $scheme === 'https' ? 443 : 80 );

    $out = [
        'timing_source' => 'socket_probe',
        'dns_ms'        => null,
        'connect_ms'    => null,
        'tls_ms'        => null,
        'resolved_ip'   => '',
        'socket_note'   => '',
    ];

    if ( ! function_exists( 'stream_socket_client' ) ) {
        $out['socket_note'] = 'stream_socket_client_unavailable';
        return $out;
    }

    $dns_started = microtime( true );
    $ip_list     = [];
    if ( function_exists( 'dns_get_record' ) ) {
        $dns_warning = null;
        $records     = aichat_diagnostic_call_with_warning_capture(
            static function() use ( $host ) {
                return dns_get_record( $host, DNS_A + DNS_AAAA );
            },
            $dns_warning
        );
        if ( is_array( $records ) ) {
            foreach ( $records as $record ) {
                if ( ! empty( $record['ip'] ) ) {
                    $ip_list[] = (string) $record['ip'];
                } elseif ( ! empty( $record['ipv6'] ) ) {
                    $ip_list[] = (string) $record['ipv6'];
                }
            }
        }
        if ( empty( $ip_list ) && ! empty( $dns_warning ) ) {
            $out['socket_note'] = 'dns_warning: ' . mb_substr( $dns_warning, 0, 120 );
        }
    }
    if ( empty( $ip_list ) && function_exists( 'gethostbynamel' ) ) {
        $dns_fallback_warning = null;
        $fallback             = aichat_diagnostic_call_with_warning_capture(
            static function() use ( $host ) {
                return gethostbynamel( $host );
            },
            $dns_fallback_warning
        );
        if ( is_array( $fallback ) ) {
            $ip_list = $fallback;
        } elseif ( ! empty( $dns_fallback_warning ) && $out['socket_note'] === '' ) {
            $out['socket_note'] = 'dns_warning: ' . mb_substr( $dns_fallback_warning, 0, 120 );
        }
    }
    $out['dns_ms'] = (int) round( ( microtime( true ) - $dns_started ) * 1000 );

    if ( empty( $ip_list ) ) {
        $out['socket_note'] = 'dns_lookup_failed';
        return $out;
    }

    $ip                = (string) reset( $ip_list );
    $out['resolved_ip'] = $ip;

    $tcp_target = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 )
        ? 'tcp://[' . $ip . ']:' . $port
        : 'tcp://' . $ip . ':' . $port;

    $tcp_started = microtime( true );
    $errno       = 0;
    $errstr      = '';
    $tcp_warning = null;
    $tcp_socket  = aichat_diagnostic_call_with_warning_capture(
        static function() use ( $tcp_target, &$errno, &$errstr, $connect_timeout ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions -- Best-effort timing probe; primary HTTP path uses wp_remote_request().
            return stream_socket_client(
                $tcp_target,
                $errno,
                $errstr,
                (float) $connect_timeout,
                STREAM_CLIENT_CONNECT
            );
        },
        $tcp_warning
    );
    $out['connect_ms'] = (int) round( ( microtime( true ) - $tcp_started ) * 1000 );

    if ( ! is_resource( $tcp_socket ) ) {
        if ( $errstr !== '' ) {
            $out['socket_note'] = 'tcp_connect_failed: ' . mb_substr( $errstr, 0, 120 );
        } elseif ( ! empty( $tcp_warning ) ) {
            $out['socket_note'] = 'tcp_connect_failed: ' . mb_substr( $tcp_warning, 0, 120 );
        } else {
            $out['socket_note'] = 'tcp_connect_failed';
        }
        return $out;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes a network socket stream resource, not a filesystem file handle.
    fclose( $tcp_socket );

    if ( $scheme !== 'https' ) {
        $out['tls_ms'] = 0;
        return $out;
    }

    $tls_target = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 )
        ? 'tls://[' . $ip . ']:' . $port
        : 'tls://' . $ip . ':' . $port;

    $ctx = stream_context_create(
        [
            'ssl' => [
                'peer_name'        => $host,
                'SNI_enabled'      => true,
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]
    );

    $tls_started = microtime( true );
    $tls_warning = null;
    $tls_socket  = aichat_diagnostic_call_with_warning_capture(
        static function() use ( $tls_target, &$errno, &$errstr, $connect_timeout, $ctx ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions -- Best-effort timing probe; primary HTTP path uses wp_remote_request().
            return stream_socket_client(
                $tls_target,
                $errno,
                $errstr,
                (float) $connect_timeout,
                STREAM_CLIENT_CONNECT,
                $ctx
            );
        },
        $tls_warning
    );
    $tls_total_ms = (int) round( ( microtime( true ) - $tls_started ) * 1000 );

    if ( ! is_resource( $tls_socket ) ) {
        $out['tls_ms']     = null;
        if ( $errstr !== '' ) {
            $out['socket_note'] = 'tls_handshake_failed: ' . mb_substr( $errstr, 0, 120 );
        } elseif ( ! empty( $tls_warning ) ) {
            $out['socket_note'] = 'tls_handshake_failed: ' . mb_substr( $tls_warning, 0, 120 );
        } else {
            $out['socket_note'] = 'tls_handshake_failed';
        }
        return $out;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes a network socket stream resource, not a filesystem file handle.
    fclose( $tls_socket );

    $estimated_tls = $tls_total_ms - (int) $out['connect_ms'];
    $out['tls_ms'] = $estimated_tls > 0 ? $estimated_tls : $tls_total_ms;
    return $out;
}

function aichat_diagnostic_public_url( $url ) {
    $parts = wp_parse_url( $url );
    if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
        return '';
    }

    $path = isset( $parts['path'] ) ? $parts['path'] : '/';
    return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $path;
}

function aichat_diagnostic_status_for_http_probe( array $probe ) {
    if ( empty( $probe['ok'] ) ) {
        return 'fail';
    }

    $http_code = (int) ( $probe['http_code'] ?? 0 );
    if ( $http_code >= 200 && $http_code < 300 ) {
        return 'pass';
    }
    if ( $http_code === 429 ) {
        return 'warn';
    }
    return 'fail';
}

function aichat_diagnostic_message_for_http_probe( array $probe ) {
    if ( empty( $probe['ok'] ) ) {
        return sprintf(
            /* translators: %s: transport error message */
            __( 'Network probe failed before a valid HTTP response: %s', 'axiachat-ai' ),
            (string) ( $probe['error'] ?? 'unknown error' )
        );
    }

    $http_code = (int) ( $probe['http_code'] ?? 0 );
    if ( $http_code >= 200 && $http_code < 300 ) {
        return __( 'Provider API endpoint responded successfully.', 'axiachat-ai' );
    }
    if ( $http_code === 429 ) {
        return __( 'Provider API endpoint is reachable but returned a rate limit response.', 'axiachat-ai' );
    }
    if ( $http_code === 401 || $http_code === 403 ) {
        return __( 'Provider API endpoint is reachable but rejected authentication.', 'axiachat-ai' );
    }

    return sprintf(
        /* translators: %d: HTTP status code */
        __( 'Provider API endpoint returned HTTP %d.', 'axiachat-ai' ),
        $http_code
    );
}

function aichat_diagnostic_status_for_host_timing( array $probe ) {
    if ( empty( $probe['ok'] ) || (int) ( $probe['http_code'] ?? 0 ) <= 0 ) {
        return 'fail';
    }

    $timings    = isset( $probe['timings'] ) && is_array( $probe['timings'] ) ? $probe['timings'] : [];
    $connect_ms = isset( $timings['connect_ms'] ) && $timings['connect_ms'] !== null ? (int) $timings['connect_ms'] : null;
    $total_ms   = isset( $timings['total_ms'] ) ? (int) $timings['total_ms'] : 0;

    if ( ( $connect_ms !== null && $connect_ms > 3000 ) || $total_ms > 8000 ) {
        return 'warn';
    }

    return 'pass';
}

function aichat_diagnostic_message_for_host_timing( array $probe ) {
    if ( empty( $probe['ok'] ) || (int) ( $probe['http_code'] ?? 0 ) <= 0 ) {
        return sprintf(
            /* translators: %s: transport error message */
            __( 'Provider host timing probe failed: %s', 'axiachat-ai' ),
            (string) ( $probe['error'] ?? 'unknown error' )
        );
    }

    $status = aichat_diagnostic_status_for_host_timing( $probe );
    if ( $status === 'warn' ) {
        return __( 'Provider host is reachable, but connection or total timing is slow.', 'axiachat-ai' );
    }

    return __( 'Provider host is reachable within the diagnostic timeout budget.', 'axiachat-ai' );
}

function aichat_diagnostic_probe_details( array $probe, array $context ) {
    $details = [
        'provider'     => $context['provider'],
        'model'        => $context['model'],
        'transport'    => $probe['transport'] ?? '',
        'endpoint'     => $probe['display_url'] ?? '',
        'http_code'    => (int) ( $probe['http_code'] ?? 0 ),
    ];

    if ( ! empty( $probe['error'] ) ) {
        $details['transport_error'] = $probe['error'];
    }
    if ( ! empty( $probe['timings'] ) && is_array( $probe['timings'] ) ) {
        $details['timings'] = $probe['timings'];
    }

    $body_error = aichat_diagnostic_extract_error_message( (string) ( $probe['body_excerpt'] ?? '' ) );
    if ( $body_error !== '' ) {
        $details['provider_message'] = $body_error;
    }

    return $details;
}

function aichat_diagnostic_extract_error_message( $body_excerpt ) {
    $body_excerpt = trim( (string) $body_excerpt );
    if ( $body_excerpt === '' ) {
        return '';
    }

    $decoded = json_decode( $body_excerpt, true );
    if ( is_array( $decoded ) ) {
        if ( isset( $decoded['error']['message'] ) ) {
            return mb_substr( (string) $decoded['error']['message'], 0, 300 );
        }
        if ( isset( $decoded['message'] ) ) {
            return mb_substr( (string) $decoded['message'], 0, 300 );
        }
    }

    return mb_substr( wp_strip_all_tags( $body_excerpt ), 0, 200 );
}

function aichat_diagnostic_provider_result_details( array $result ) {
    $details = [];

    if ( isset( $result['finish_reason'] ) ) {
        $details['finish_reason'] = (string) $result['finish_reason'];
    }
    if ( isset( $result['model'] ) ) {
        $details['returned_model'] = (string) $result['model'];
    }
    if ( isset( $result['usage'] ) && is_array( $result['usage'] ) ) {
        $usage = [];
        foreach ( [ 'prompt_tokens', 'completion_tokens', 'total_tokens', 'input_tokens', 'output_tokens' ] as $key ) {
            if ( isset( $result['usage'][ $key ] ) ) {
                $usage[ $key ] = (int) $result['usage'][ $key ];
            }
        }
        if ( ! empty( $usage ) ) {
            $details['usage'] = $usage;
        }
    }

    return $details;
}

function aichat_diagnostic_register_tool() {
    $definition = [
        'type'           => 'function',
        'name'           => 'aichat_diagnostic_ping',
        'description'    => 'Returns a fixed private diagnostic ping payload. Use only for AxiaChat admin diagnostics.',
        'schema'         => [
            'type'                 => 'object',
            'properties'           => [
                'token' => [
                    'type'        => 'string',
                    'description' => 'Diagnostic token supplied by the user prompt.',
                ],
            ],
            'required'             => [ 'token' ],
            'additionalProperties' => false,
        ],
        'strict'         => true,
        'timeout'        => 5,
        'max_calls'      => 1,
        'activity_label' => __( 'Running diagnostic ping...', 'axiachat-ai' ),
        'callback'       => static function( $args, $callback_context = [] ) {
            $source = isset( $callback_context['source'] ) ? sanitize_key( (string) $callback_context['source'] ) : 'model';
            if ( $source !== 'local' ) {
                if ( ! isset( $GLOBALS['aichat_diagnostic_tool_calls'] ) || ! is_array( $GLOBALS['aichat_diagnostic_tool_calls'] ) ) {
                    $GLOBALS['aichat_diagnostic_tool_calls'] = [];
                }
                $GLOBALS['aichat_diagnostic_tool_calls'][] = [
                    'token' => isset( $args['token'] ) ? sanitize_text_field( (string) $args['token'] ) : '',
                    'time'  => gmdate( 'c' ),
                ];
            }

            return [
                'ok'     => true,
                'token'  => isset( $args['token'] ) ? sanitize_text_field( (string) $args['token'] ) : '',
                'source' => $source,
                'time'   => gmdate( 'c' ),
            ];
        },
    ];

    if ( function_exists( 'aichat_register_tool_safe' ) ) {
        aichat_register_tool_safe( 'aichat_diagnostic_ping', $definition );
    }

    if ( ! isset( $GLOBALS['aichat_registered_tools'] ) || ! is_array( $GLOBALS['aichat_registered_tools'] ) ) {
        $GLOBALS['aichat_registered_tools'] = [];
    }
    $GLOBALS['aichat_registered_tools']['aichat_diagnostic_ping'] = array_merge(
        $definition,
        [ 'id' => 'aichat_diagnostic_ping' ]
    );

    return $definition;
}

function aichat_diagnostic_result( $step, $status, $message, array $details = [], array $state = [] ) {
    return [
        'step'    => sanitize_key( (string) $step ),
        'status'  => in_array( $status, [ 'pass', 'warn', 'fail' ], true ) ? $status : 'fail',
        'message' => aichat_diagnostic_redact( (string) $message ),
        'details' => aichat_diagnostic_sanitize_details( $details ),
        'state'   => aichat_diagnostic_sanitize_state( $state ),
    ];
}

function aichat_diagnostic_sanitize_details( array $details ) {
    $clean = [];
    foreach ( $details as $key => $value ) {
        $safe_key = sanitize_key( (string) $key );
        if ( is_array( $value ) ) {
            $clean[ $safe_key ] = aichat_diagnostic_sanitize_details( $value );
        } elseif ( is_bool( $value ) ) {
            $clean[ $safe_key ] = $value ? 'yes' : 'no';
        } elseif ( is_int( $value ) || is_float( $value ) ) {
            $clean[ $safe_key ] = $value;
        } elseif ( $value === null ) {
            $clean[ $safe_key ] = null;
        } else {
            $clean[ $safe_key ] = aichat_diagnostic_redact( mb_substr( (string) $value, 0, 1000 ) );
        }
    }

    return $clean;
}

function aichat_diagnostic_sanitize_state( array $state ) {
    $allowed = [];
    foreach ( [ 'bot_slug', 'provider', 'model' ] as $key ) {
        if ( isset( $state[ $key ] ) ) {
            $allowed[ $key ] = sanitize_text_field( (string) $state[ $key ] );
        }
    }

    if ( isset( $state['chat_messages'] ) && is_array( $state['chat_messages'] ) ) {
        $messages = [];
        foreach ( array_slice( $state['chat_messages'], -8 ) as $message ) {
            if ( ! is_array( $message ) ) {
                continue;
            }
            $role = isset( $message['role'] ) ? sanitize_key( (string) $message['role'] ) : '';
            if ( ! in_array( $role, [ 'system', 'user', 'assistant' ], true ) ) {
                continue;
            }
            $messages[] = [
                'role'    => $role,
                'content' => aichat_diagnostic_redact( mb_substr( (string) ( $message['content'] ?? '' ), 0, 1000 ) ),
            ];
        }
        $allowed['chat_messages'] = $messages;
    }

    return $allowed;
}

function aichat_diagnostic_redact( $value, array $extra_secrets = [] ) {
    $value = (string) $value;
    if ( $value === '' ) {
        return '';
    }

    $secrets = array_filter(
        array_merge(
            $extra_secrets,
            [
                aichat_get_setting( 'aichat_openai_api_key' ),
                aichat_get_setting( 'aichat_claude_api_key' ),
                aichat_get_setting( 'aichat_gemini_api_key' ),
            ]
        ),
        static function( $secret ) {
            return is_string( $secret ) && strlen( $secret ) >= 8;
        }
    );

    foreach ( $secrets as $secret ) {
        $value = str_replace( $secret, '[redacted]', $value );
    }

    $patterns = [
        '/AIza[0-9A-Za-z_\-]{10,}/',
        '/sk-[0-9A-Za-z_\-]{10,}/',
        '/sk-ant-[0-9A-Za-z_\-]{10,}/',
        '/Bearer\s+[0-9A-Za-z_\.\-]{10,}/i',
        '/key=([^&\s]+)/i',
    ];

    return preg_replace( $patterns, '[redacted]', $value );
}