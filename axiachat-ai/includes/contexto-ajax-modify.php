<?php
/**
 * AJAX handlers for Modify Context feature.
 * Handles listing, removing, and adding documents to existing contexts.
 *
 * @package AxiaChat
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include shared context functions
require_once plugin_dir_path( __FILE__ ) . 'contexto-functions.php';

// =====================
// List documents (grouped by post_id)
// =====================
add_action( 'wp_ajax_aichat_modify_list_documents', 'aichat_modify_list_documents' );
function aichat_modify_list_documents() {
    check_ajax_referer( 'aichat_modify_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }

    global $wpdb;
    $chunks_table = $wpdb->prefix . 'aichat_chunks';

    $context_id = isset( $_POST['context_id'] ) ? absint( wp_unslash( $_POST['context_id'] ) ) : 0;
    if ( $context_id <= 0 ) {
        wp_send_json_error( [ 'message' => 'Missing context_id' ] );
    }

    $page     = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
    $per_page = isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : 10;
    if ( $per_page <= 0 ) { $per_page = 10; }
    if ( $per_page > 50 ) { $per_page = 50; }
    $offset = ( $page - 1 ) * $per_page;

    $q = isset( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ) : '';
    if ( strlen( $q ) > 120 ) { $q = substr( $q, 0, 120 ); }
    $q_like = $q !== '' ? '%' . $wpdb->esc_like( $q ) . '%' : '';

    $filter_type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
    // Accept 'file' as alias for aichat_upload_chunk in the filter dropdown
    if ( $filter_type === 'file' || $filter_type === 'web' ) { $filter_type = 'aichat_upload_chunk'; }
    $allowed_types = [ 'post', 'page', 'product', 'aichat_upload_chunk' ];
    if ( $filter_type && ! in_array( $filter_type, $allowed_types, true ) ) { $filter_type = ''; }

    // For upload chunks we group by their parent upload post, not by chunk post_id.
    // We use a LEFT JOIN on wp_postmeta (_aichat_upload_id) to resolve the parent.
    // COALESCE(pm.meta_value, c.post_id) gives us the "document ID" (parent for uploads, post_id for others).
    $pm_table = $wpdb->postmeta;

    // Build WHERE
    $where_clauses = [ 'c.id_context = %d' ];
    $where_params  = [ $context_id ];

    if ( $filter_type ) {
        $where_clauses[] = 'c.type = %s';
        $where_params[]  = $filter_type;
    }
    if ( $q_like ) {
        $where_clauses[] = 'c.title LIKE %s';
        $where_params[]  = $q_like;
    }

    $where_sql = implode( ' AND ', $where_clauses );

    // Count total distinct documents (collapsing upload chunks by parent)
    $count_sql = "SELECT COUNT(DISTINCT CASE WHEN c.type = 'aichat_upload_chunk' THEN COALESCE(pm.meta_value, c.post_id) ELSE c.post_id END)
                  FROM $chunks_table c
                  LEFT JOIN $pm_table pm ON pm.post_id = c.post_id AND pm.meta_key = '_aichat_upload_id' AND c.type = 'aichat_upload_chunk'
                  WHERE $where_sql";
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$where_params ) );

    if ( $total === 0 ) {
        wp_send_json_success( [
            'context_id'  => $context_id,
            'rows'        => [],
            'total'       => 0,
            'total_pages' => 0,
            'page'        => $page,
            'per_page'    => $per_page,
        ] );
    }

    // Fetch grouped rows — upload chunks collapsed by parent upload ID
    $sql = "SELECT
                CASE WHEN c.type = 'aichat_upload_chunk' THEN COALESCE(CAST(pm.meta_value AS UNSIGNED), c.post_id) ELSE c.post_id END AS doc_id,
                MIN(c.title) AS title,
                c.type,
                COUNT(*) AS chunk_count,
                MAX(COALESCE(c.updated_at, c.created_at)) AS last_update
            FROM $chunks_table c
            LEFT JOIN $pm_table pm ON pm.post_id = c.post_id AND pm.meta_key = '_aichat_upload_id' AND c.type = 'aichat_upload_chunk'
            WHERE $where_sql
            GROUP BY doc_id, c.type
            ORDER BY last_update DESC, doc_id DESC
            LIMIT %d OFFSET %d";
    $rows_params = array_merge( $where_params, [ $per_page, $offset ] );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $rows_raw = $wpdb->get_results( $wpdb->prepare( $sql, ...$rows_params ), ARRAY_A );

    $rows = [];
    foreach ( $rows_raw as $r ) {
        $type_label = (string) $r['type'];
        $title      = (string) $r['title'];
        $doc_id     = (int) $r['doc_id'];

        // For upload chunks: show parent filename and friendly type
        if ( $type_label === 'aichat_upload_chunk' ) {
            $type_label = 'file';
            // Try to get the parent upload filename for a cleaner title
            $parent_filename = get_post_meta( $doc_id, '_aichat_filename', true );
            if ( $parent_filename ) {
                $title = $parent_filename;
            }
            // Detect web-scraped pages (source_type = 'web_scraper')
            $source_type = get_post_meta( $doc_id, '_aichat_source_type', true );
            if ( $source_type === 'web_scraper' ) {
                $type_label = 'web';
                $source_url = get_post_meta( $doc_id, '_aichat_source_url', true );
                if ( $source_url ) {
                    $title = $title ?: $source_url;
                }
            }
        }

        $rows[] = [
            'post_id'     => $doc_id,
            'title'       => $title,
            'type'        => $type_label,
            'chunk_count' => (int) $r['chunk_count'],
            'last_update' => (string) $r['last_update'],
        ];
    }

    $total_pages = (int) ceil( $total / $per_page );

    wp_send_json_success( [
        'context_id'  => $context_id,
        'rows'        => $rows,
        'total'       => $total,
        'total_pages' => $total_pages,
        'page'        => $page,
        'per_page'    => $per_page,
    ] );
}

// =====================
// View document chunks (read-only)
// =====================
add_action( 'wp_ajax_aichat_modify_view_document', 'aichat_modify_view_document' );
function aichat_modify_view_document() {
    check_ajax_referer( 'aichat_modify_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }

    global $wpdb;
    $chunks_table = $wpdb->prefix . 'aichat_chunks';

    $context_id = isset( $_POST['context_id'] ) ? absint( wp_unslash( $_POST['context_id'] ) ) : 0;
    $doc_id     = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

    if ( $context_id <= 0 || $doc_id <= 0 ) {
        wp_send_json_error( [ 'message' => 'Missing context_id or post_id' ] );
    }

    // Determine if this is an upload parent (has children aichat_upload_chunk)
    $post = get_post( $doc_id );
    $is_upload = ( $post && $post->post_type === 'aichat_upload' );

    $child_ids = [];
    if ( $is_upload ) {
        // Find all child chunk posts for this upload parent
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $child_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_aichat_upload_id' AND pm.meta_value = %d
                 WHERE p.post_type = 'aichat_upload_chunk' AND p.post_status = 'publish'
                 ORDER BY p.ID ASC",
                $doc_id
            )
        );
        if ( empty( $child_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'No chunk posts found for this upload.', 'axiachat-ai' ) ] );
        }
    }

    // Fetch chunks from the chunks table
    $post_ids_to_query = $is_upload ? array_map( 'absint', $child_ids ) : [ $doc_id ];
    $placeholders = implode( ',', array_fill( 0, count( $post_ids_to_query ), '%d' ) );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $chunks = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, post_id, chunk_index, content, tokens, COALESCE(updated_at, created_at) AS last_update
             FROM $chunks_table
             WHERE post_id IN ($placeholders) AND id_context = %d
             ORDER BY post_id ASC, chunk_index ASC",
            ...array_merge( $post_ids_to_query, [ $context_id ] )
        ),
        ARRAY_A
    );

    if ( empty( $chunks ) ) {
        wp_send_json_error( [ 'message' => __( 'No chunks found for this document.', 'axiachat-ai' ) ] );
    }

    // Build response
    $title = '';
    $type  = '';
    $meta  = [];

    if ( $is_upload ) {
        $title = get_post_meta( $doc_id, '_aichat_filename', true ) ?: ( $post ? $post->post_title : '#' . $doc_id );
        $source_type = get_post_meta( $doc_id, '_aichat_source_type', true );
        if ( $source_type === 'web_scraper' ) {
            $type = 'web';
            $meta['source_url'] = get_post_meta( $doc_id, '_aichat_source_url', true );
        } else {
            $type = 'file';
            $meta['filename'] = get_post_meta( $doc_id, '_aichat_filename', true );
            $meta['mime']     = get_post_meta( $doc_id, '_aichat_mime', true );
        }
    } else {
        $title = $post ? $post->post_title : '#' . $doc_id;
        $type  = $post ? $post->post_type : 'unknown';
    }

    $chunk_data = [];
    $total_tokens = 0;
    foreach ( $chunks as $ch ) {
        $chunk_data[] = [
            'id'          => (int) $ch['id'],
            'post_id'     => (int) $ch['post_id'],
            'chunk_index' => (int) $ch['chunk_index'],
            'content'     => (string) $ch['content'],
            'tokens'      => (int) $ch['tokens'],
            'last_update' => (string) $ch['last_update'],
        ];
        $total_tokens += (int) $ch['tokens'];
    }

    wp_send_json_success( [
        'doc_id'       => $doc_id,
        'title'        => $title,
        'type'         => $type,
        'is_upload'    => $is_upload,
        'meta'         => $meta,
        'chunks'       => $chunk_data,
        'total_chunks' => count( $chunk_data ),
        'total_tokens' => $total_tokens,
    ] );
}

// =====================
// Save edited document chunks (with re-embedding)
// =====================
add_action( 'wp_ajax_aichat_modify_save_document', 'aichat_modify_save_document' );
function aichat_modify_save_document() {
    check_ajax_referer( 'aichat_modify_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }

    global $wpdb;
    $chunks_table = $wpdb->prefix . 'aichat_chunks';

    $context_id = isset( $_POST['context_id'] ) ? absint( wp_unslash( $_POST['context_id'] ) ) : 0;
    $doc_id     = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

    // Chunks JSON: decode safely via centralized helper (sanitize_text_field + wp_unslash + json_decode + is_array).
    $edited_chunks = aichat_json_decode_post( 'chunks' );

    if ( $context_id <= 0 || $doc_id <= 0 ) {
        wp_send_json_error( [ 'message' => 'Missing context_id or post_id' ] );
    }

    if ( empty( $edited_chunks ) ) {
        wp_send_json_error( [ 'message' => __( 'No changes to save.', 'axiachat-ai' ) ] );
    }

    // Determine embedding provider for this context
    $provider = '';
    if ( function_exists( 'aichat_get_context_embedding_provider' ) ) {
        $provider = aichat_get_context_embedding_provider( $context_id );
    }

    // Also check if context is remote (Pinecone)
    $ctx_table = $wpdb->prefix . 'aichat_contexts';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $context = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT context_type, remote_type, remote_api_key, remote_endpoint FROM {$ctx_table} WHERE id = %d",
            $context_id
        ),
        ARRAY_A
    );
    $is_remote = ( $context && $context['context_type'] === 'remoto' );

    $updated  = 0;
    $errors   = 0;
    $details  = [];

    foreach ( $edited_chunks as $ch ) {
        $chunk_id    = isset( $ch['id'] ) ? absint( $ch['id'] ) : 0;
        $new_content = isset( $ch['content'] ) ? sanitize_textarea_field( $ch['content'] ) : '';

        if ( $chunk_id <= 0 || $new_content === '' ) {
            $errors++;
            /* translators: %d: chunk number */
            $details[] = sprintf( __( 'Chunk #%d: invalid data.', 'axiachat-ai' ), $chunk_id );
            continue;
        }

        // Generate new embedding for the edited content
        $new_embedding = null;
        if ( function_exists( 'aichat_generate_embedding' ) ) {
            $new_embedding = aichat_generate_embedding( $new_content, 'document', $provider );
        }

        if ( ! is_array( $new_embedding ) || empty( $new_embedding ) ) {
            $errors++;
            /* translators: %d: chunk number */
            $details[] = sprintf( __( 'Chunk #%d: embedding generation failed.', 'axiachat-ai' ), $chunk_id );
            continue;
        }

        $embed_json = wp_json_encode( array_values( $new_embedding ) );
        $tokens     = str_word_count( $new_content );

        // Update the chunk in local DB
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $chunks_table,
            [
                'content'    => $new_content,
                'embedding'  => $embed_json,
                'tokens'     => $tokens,
                'updated_at' => current_time( 'mysql' ),
            ],
            [
                'id'         => $chunk_id,
                'id_context' => $context_id,
            ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d', '%d' ]
        );

        if ( false === $result ) {
            $errors++;
            /* translators: %d: chunk number */
            $details[] = sprintf( __( 'Chunk #%d: database error.', 'axiachat-ai' ), $chunk_id );
            continue;
        }

        // If remote (Pinecone), also upsert the vector
        if ( $is_remote && $context['remote_type'] === 'pinecone' && ! empty( $context['remote_api_key'] ) && ! empty( $context['remote_endpoint'] ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $chunk_row = $wpdb->get_row(
                $wpdb->prepare( "SELECT post_id, title FROM $chunks_table WHERE id = %d", $chunk_id ),
                ARRAY_A
            );
            if ( $chunk_row ) {
                $payload = [
                    'vectors'   => [ [
                        'id'       => (string) $chunk_row['post_id'] . '_' . $chunk_id,
                        'values'   => array_values( $new_embedding ),
                        'metadata' => [
                            'post_id'    => (int) $chunk_row['post_id'],
                            'title'      => $chunk_row['title'],
                            'context_id' => (int) $context_id,
                            'chunk_id'   => (int) $chunk_id,
                        ],
                    ] ],
                    'namespace' => 'aichat_context_' . $context_id,
                ];
                wp_remote_post(
                    rtrim( $context['remote_endpoint'], '/' ) . '/vectors/upsert',
                    [
                        'headers' => [
                            'Api-Key'      => $context['remote_api_key'],
                            'Content-Type' => 'application/json',
                        ],
                        'body'    => wp_json_encode( $payload ),
                        'timeout' => 30,
                    ]
                );
            }
        }

        $updated++;
        /* translators: %d: chunk number */
        $details[] = sprintf( __( 'Chunk #%d: updated successfully.', 'axiachat-ai' ), $chunk_id );
    }

    if ( $updated > 0 ) {
        wp_send_json_success( [
            'updated' => $updated,
            'errors'  => $errors,
            'details' => $details,
            'message' => sprintf(
                /* translators: 1: updated count, 2: error count */
                __( '%1$d chunk(s) updated, %2$d error(s).', 'axiachat-ai' ),
                $updated,
                $errors
            ),
        ] );
    } else {
        wp_send_json_error( [
            'message' => __( 'No chunks were updated.', 'axiachat-ai' ),
            'details' => $details,
        ] );
    }
}

// =====================
// Remove documents from context
// =====================
add_action( 'wp_ajax_aichat_modify_remove_documents', 'aichat_modify_remove_documents' );
function aichat_modify_remove_documents() {
    check_ajax_referer( 'aichat_modify_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }

    global $wpdb;
    $chunks_table = $wpdb->prefix . 'aichat_chunks';
    $ctx_table    = $wpdb->prefix . 'aichat_contexts';

    $context_id = isset( $_POST['context_id'] ) ? absint( wp_unslash( $_POST['context_id'] ) ) : 0;
    $post_ids   = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : [];

    if ( $context_id <= 0 || empty( $post_ids ) ) {
        wp_send_json_error( [ 'message' => 'Missing context_id or post_ids' ] );
    }

    // Verify context exists
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $context = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, context_type, remote_type, remote_api_key, remote_endpoint, items_to_process FROM {$ctx_table} WHERE id = %d",
            $context_id
        ),
        ARRAY_A
    );
    if ( ! $context ) {
        wp_send_json_error( [ 'message' => 'Context not found' ] );
    }

    // 0.5. Expand parent aichat_upload IDs to their aichat_upload_chunk child IDs
    //      (document list groups chunks by parent, so removal sends parent ID)
    $expanded_ids = [];
    foreach ( $post_ids as $pid ) {
        $pt = get_post_type( (int) $pid );
        if ( $pt === 'aichat_upload' ) {
            $child_chunks = get_posts( [
                'post_type'   => 'aichat_upload_chunk',
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_key'    => '_aichat_upload_id',   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value'  => (int) $pid,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            ] );
            if ( ! empty( $child_chunks ) ) {
                $expanded_ids = array_merge( $expanded_ids, array_map( 'intval', $child_chunks ) );
            }
        } else {
            $expanded_ids[] = (int) $pid;
        }
    }
    // Merge originals + expanded (covers both chunk-level & parent-level IDs)
    $all_ids = array_unique( array_merge( array_map( 'intval', $post_ids ), $expanded_ids ) );
    if ( empty( $all_ids ) ) {
        wp_send_json_error( [ 'message' => 'No valid IDs to remove' ] );
    }

    // 1. Delete chunks from local DB
    $placeholders = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
    $del_params   = array_merge( [ $context_id ], $all_ids );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$chunks_table} WHERE id_context = %d AND post_id IN ($placeholders)",
            ...$del_params
        )
    );

    // 2. Update items_to_process (remove these IDs from the serialized list)
    $items = maybe_unserialize( $context['items_to_process'] );
    if ( is_array( $items ) ) {
        $removed_set = array_flip( $all_ids );
        $items       = array_values( array_filter( $items, function( $id ) use ( $removed_set ) {
            return ! isset( $removed_set[ (int) $id ] );
        } ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $ctx_table,
            [ 'items_to_process' => maybe_serialize( $items ) ],
            [ 'id' => $context_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    // 3. For Pinecone remote contexts, also delete vectors
    if ( $context['context_type'] === 'remoto' && $context['remote_type'] === 'pinecone'
         && ! empty( $context['remote_api_key'] ) && ! empty( $context['remote_endpoint'] ) ) {
        $endpoint = rtrim( $context['remote_endpoint'], '/' ) . '/vectors/delete';
        $payload  = [
            'ids'       => array_map( 'strval', $all_ids ),
            'namespace' => 'aichat_context_' . $context_id,
        ];
        wp_remote_post( $endpoint, [
            'headers' => [
                'Api-Key'      => $context['remote_api_key'],
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ] );
    }

    // 4. Get updated counts
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $new_chunk_count = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$chunks_table} WHERE id_context = %d", $context_id )
    );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $new_doc_count = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$chunks_table} WHERE id_context = %d", $context_id )
    );

    wp_send_json_success( [
        'context_id'     => $context_id,
        'deleted_chunks' => (int) $deleted,
        'removed_posts'  => count( $post_ids ),
        'new_chunk_count' => $new_chunk_count,
        'new_doc_count'   => $new_doc_count,
    ] );
}

// =====================
// Add documents to context (batch processing)
// =====================
add_action( 'wp_ajax_aichat_modify_add_documents', 'aichat_modify_add_documents' );
function aichat_modify_add_documents() {
    check_ajax_referer( 'aichat_modify_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }

    global $wpdb;
    $ctx_table = $wpdb->prefix . 'aichat_contexts';

    $context_id  = isset( $_POST['context_id'] ) ? absint( wp_unslash( $_POST['context_id'] ) ) : 0;
    $selected    = isset( $_POST['selected'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['selected'] ) ) : [];
    $all_selected = isset( $_POST['all_selected'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['all_selected'] ) ) : [];
    $batch       = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 0;

    if ( $context_id <= 0 ) {
        wp_send_json_error( [ 'message' => 'Missing context_id' ] );
    }

    // Verify context exists
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $context = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$ctx_table} WHERE id = %d", $context_id ),
        ARRAY_A
    );
    if ( ! $context ) {
        wp_send_json_error( [ 'message' => 'Context not found' ] );
    }

    $BATCH_SIZE = 10;

    // On first batch, build the item list and merge with existing
    if ( $batch === 0 && ( ! empty( $selected ) || ! empty( $all_selected ) ) ) {
        $new_items = [];

        // Expand selected (custom picks) – handle upload parents → chunks
        if ( ! empty( $selected ) ) {
            if ( function_exists( 'aichat_expand_selected_ids_to_chunks' ) ) {
                $new_items = array_merge( $new_items, aichat_expand_selected_ids_to_chunks( $selected ) );
            } else {
                $new_items = array_merge( $new_items, $selected );
            }
        }

        // Expand "ALL" groups
        foreach ( $all_selected as $all_type ) {
            if ( $all_type === 'all_uploaded' ) {
                if ( function_exists( 'aichat_all_uploaded_chunks_ids' ) ) {
                    $chunk_ids = aichat_all_uploaded_chunks_ids();
                    if ( ! empty( $chunk_ids ) ) {
                        $new_items = array_merge( $new_items, $chunk_ids );
                    }
                }
                continue;
            }
            $pt = '';
            if ( $all_type === 'all_posts' )    { $pt = 'post'; }
            if ( $all_type === 'all_pages' )    { $pt = 'page'; }
            if ( $all_type === 'all_products' ) { $pt = 'product'; }
            if ( $pt ) {
                $ids = get_posts( [
                    'post_type'      => $pt,
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                ] );
                $new_items = array_merge( $new_items, $ids );
            }
        }

        // Dedup
        if ( function_exists( 'aichat_stable_unique_ids' ) ) {
            $new_items = aichat_stable_unique_ids( $new_items );
        } else {
            $new_items = array_unique( array_map( 'intval', $new_items ) );
        }

        // Merge with existing items_to_process
        $current = maybe_unserialize( $context['items_to_process'] );
        if ( ! is_array( $current ) ) { $current = []; }
        $merged = array_merge( $current, $new_items );
        if ( function_exists( 'aichat_stable_unique_ids' ) ) {
            $merged = aichat_stable_unique_ids( $merged );
        } else {
            $merged = array_values( array_unique( array_map( 'intval', $merged ) ) );
        }

        // Save merged list. We store the NEW items as a transient for batch processing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $ctx_table,
            [ 'items_to_process' => maybe_serialize( $merged ) ],
            [ 'id' => $context_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // Store the new-only items in a transient for this add operation
        set_transient( 'aichat_modify_add_' . $context_id, $new_items, HOUR_IN_SECONDS );
    }

    // Get the items to add (from transient)
    $items_to_add = get_transient( 'aichat_modify_add_' . $context_id );
    if ( ! is_array( $items_to_add ) ) { $items_to_add = []; }
    $total = count( $items_to_add );

    if ( $total === 0 ) {
        wp_send_json_success( [
            'context_id'      => $context_id,
            'total_processed' => 0,
            'progress'        => 100,
            'batch'           => $batch,
            'continue'        => false,
            'total'           => 0,
            'message'         => __( 'No items to add.', 'axiachat-ai' ),
        ] );
    }

    // Calculate cursor from batch
    $cursor      = $batch * $BATCH_SIZE;
    $batch_items = array_slice( $items_to_add, $cursor, $BATCH_SIZE );
    $attempted   = count( $batch_items );

    if ( $attempted === 0 || $cursor >= $total ) {
        delete_transient( 'aichat_modify_add_' . $context_id );
        wp_send_json_success( [
            'context_id'      => $context_id,
            'total_processed' => 0,
            'progress'        => 100,
            'batch'           => $batch,
            'continue'        => false,
            'total'           => $total,
            'message'         => __( 'Completed.', 'axiachat-ai' ),
        ] );
    }

    // Process batch
    $processed  = 0;
    $provider   = '';
    if ( function_exists( 'aichat_get_context_embedding_provider' ) ) {
        $provider = aichat_get_context_embedding_provider( $context_id );
    }

    foreach ( $batch_items as $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            continue;
        }

        if ( $context['context_type'] === 'remoto' && $context['remote_type'] === 'pinecone' ) {
            // Remote (Pinecone) indexing
            $text      = wp_strip_all_tags( $post->post_title . "\n" . $post->post_content );
            $title     = $post->post_title;
            $embedding = aichat_generate_embedding( $text, 'document', $provider );
            if ( ! is_array( $embedding ) || empty( $embedding ) ) {
                continue;
            }
            $payload = [
                'vectors'   => [ [
                    'id'       => (string) $post_id,
                    'values'   => array_values( $embedding ),
                    'metadata' => [
                        'post_id'    => (int) $post_id,
                        'title'      => $title,
                        'context_id' => (int) $context_id,
                    ],
                ] ],
                'namespace' => 'aichat_context_' . $context_id,
            ];
            $resp = wp_remote_post(
                rtrim( $context['remote_endpoint'], '/' ) . '/vectors/upsert',
                [
                    'headers' => [
                        'Api-Key'      => $context['remote_api_key'],
                        'Content-Type' => 'application/json',
                    ],
                    'body'    => wp_json_encode( $payload ),
                    'timeout' => 30,
                ]
            );
            $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
            if ( $code >= 200 && $code < 300 ) {
                $processed++;
            }
        } else {
            // Local indexing (reuses aichat_index_post which does delete+insert)
            if ( function_exists( 'aichat_index_post' ) ) {
                $ok = aichat_index_post( $post_id, $context_id, $provider );
                if ( $ok ) { $processed++; }
            }
        }
    }

    // Progress
    $new_cursor = min( $cursor + $attempted, $total );
    $progress   = (int) floor( $new_cursor * 100 / max( 1, $total ) );
    $continue   = ( $new_cursor < $total );

    if ( ! $continue ) {
        delete_transient( 'aichat_modify_add_' . $context_id );
    }

    wp_send_json_success( [
        'context_id'      => $context_id,
        'total_processed' => $processed,
        'progress'        => $progress,
        'batch'           => $batch + 1,
        'continue'        => $continue,
        'total'           => $total,
        'message'         => $continue
            ? sprintf(
                /* translators: %d: progress percentage */
                __( 'Processing... %d%%', 'axiachat-ai' ),
                $progress
            )
            : __( 'Completed.', 'axiachat-ai' ),
    ] );
}

// =====================
// Load items for the "Add" selector (reuses existing action if available, or provides own)
// =====================
add_action( 'wp_ajax_aichat_modify_load_items', 'aichat_modify_load_items' );
function aichat_modify_load_items() {
    check_ajax_referer( 'aichat_modify_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
    }

    $pt     = sanitize_text_field( wp_unslash( $_POST['post_type'] ?? '' ) );
    $tab    = sanitize_text_field( wp_unslash( $_POST['tab'] ?? 'recent' ) );
    $search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
    $paged  = isset( $_POST['paged'] ) ? absint( wp_unslash( $_POST['paged'] ) ) : 1;
    $context_id = isset( $_POST['context_id'] ) ? absint( wp_unslash( $_POST['context_id'] ) ) : 0;

    // aichat_upload (PADRES) son privados; el resto publish
    $status = ( $pt === 'aichat_upload' ) ? 'private' : 'publish';

    $args = [
        'post_type'      => $pt,
        'post_status'    => $status,
        'posts_per_page' => 5,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ];

    if ( $tab === 'recent' ) {
        $args['posts_per_page'] = 5;
    } elseif ( $tab === 'all' || $tab === 'search' ) {
        $args['posts_per_page'] = 20;
        $args['paged']          = $paged;
    }

    if ( $tab === 'search' && strlen( $search ) ) {
        $args['s'] = $search;
    }

    $ids = get_posts( $args );

    // Check which are already in the context
    $already_indexed = [];
    if ( $context_id > 0 && ! empty( $ids ) ) {
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params       = array_merge( [ $context_id ], array_map( 'intval', $ids ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->prefix}aichat_chunks WHERE id_context = %d AND post_id IN ($placeholders)",
                ...$params
            )
        );
        $already_indexed = array_flip( array_map( 'intval', $existing ) );
    }

    // Total for pagination
    $counts      = wp_count_posts( $pt );
    $total_posts = ( $pt === 'aichat_upload' )
        ? ( isset( $counts->private ) ? (int) $counts->private : 0 )
        : ( isset( $counts->publish ) ? (int) $counts->publish : 0 );
    $max_pages   = ( $tab === 'all' || $tab === 'search' ) ? max( 1, (int) ceil( $total_posts / 20 ) ) : 1;

    // Render HTML
    $html = '';
    if ( ! empty( $ids ) ) {
        foreach ( $ids as $post_id ) {
            $label = get_the_title( $post_id );
            if ( $pt === 'aichat_upload' ) {
                $fn = get_post_meta( $post_id, '_aichat_filename', true );
                if ( $fn ) { $label = $fn; }
            }
            $is_indexed = isset( $already_indexed[ (int) $post_id ] );
            $badge      = $is_indexed
                ? ' <span class="badge bg-success bg-opacity-25 text-success small ms-1">' . esc_html__( 'already indexed', 'axiachat-ai' ) . '</span>'
                : '';
            $html .= '<label class="d-block mb-1"><input type="checkbox" value="' . esc_attr( $post_id ) . '"'
                   . ( $is_indexed ? ' data-indexed="1"' : '' )
                   . ' /> ' . esc_html( $label ) . $badge . '</label>';
        }
    }
    if ( empty( $html ) ) {
        $html = '<p class="text-muted small">' . esc_html__( 'No items found.', 'axiachat-ai' ) . '</p>';
    }

    wp_send_json_success( [
        'html'         => $html,
        'max_pages'    => $max_pages,
        'current_page' => $paged,
    ] );
}
