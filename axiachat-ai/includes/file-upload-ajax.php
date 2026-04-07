<?php
/**
 * AI Chat — File Upload from Chat Widget
 *
 * Handles file uploads (PDF & images) sent by users from the chat widget.
 * Extracts text from PDFs (reusing existing PDF extraction pipeline) and
 * stores the result in a short-lived transient so the next chat message
 * can inject it as conversation context.
 *
 * Images are stored as base64 for multimodal AI provider calls.
 *
 * @package AxiaChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bot lookup from internal plugin table.

add_action( 'wp_ajax_aichat_upload_file',        'aichat_handle_file_upload' );
add_action( 'wp_ajax_nopriv_aichat_upload_file',  'aichat_handle_file_upload' );

/**
 * Maximum characters of extracted text to store (prevents huge transients).
 */
define( 'AICHAT_FILE_CONTEXT_MAX_CHARS', 32000 );

/**
 * Transient TTL in seconds (30 minutes).
 */
define( 'AICHAT_FILE_TRANSIENT_TTL', 30 * MINUTE_IN_SECONDS );

/**
 * Max dimension (longest side) for image optimisation before sending to AI model.
 * Models internally resize anyway; 1536 px is the sweet spot for quality/cost.
 */
define( 'AICHAT_IMAGE_MAX_DIMENSION', 1536 );

/**
 * JPEG quality used when compressing images for AI vision (1-100).
 */
define( 'AICHAT_IMAGE_JPEG_QUALITY', 82 );

/**
 * Handle a file upload from the chat widget.
 *
 * Expects:
 *  - $_POST['nonce']    → aichat_ajax nonce
 *  - $_POST['bot_slug'] → bot identifier (to check if uploads are enabled)
 *  - $_FILES['file']    → the uploaded file
 *
 * Returns JSON:
 *  - success: { file_id, file_name, file_type ('pdf'|'image'), text_preview }
 *  - error:   { message }
 */
function aichat_handle_file_upload() {
	// Clean any stray output that could corrupt the JSON response.
	if ( ob_get_level() ) {
		ob_clean();
	}

	aichat_log_debug( '[AIChat FileUpload] === Handler start ===', [
		'user_id'    => get_current_user_id(),
		'has_nonce'  => isset( $_POST['nonce'] ),
		'has_slug'   => isset( $_POST['bot_slug'] ),
		'has_file'   => ! empty( $_FILES['file'] ),
		'post_keys'  => implode( ',', array_map( 'sanitize_key', array_keys( $_POST ) ) ),
		'files_keys' => implode( ',', array_map( 'sanitize_key', array_keys( $_FILES ) ) ),
	] );

	// ── Nonce ──
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'aichat_ajax' ) ) {
		aichat_log_debug( '[AIChat FileUpload] FAIL: nonce invalid', [
			'nonce_empty' => empty( $nonce ),
			'user_id'     => get_current_user_id(),
		] );
		wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'axiachat-ai' ) ], 403 );
	}

	// ── Honeypot ──
	$hp = isset( $_POST['aichat_hp'] )
		? sanitize_text_field( wp_unslash( $_POST['aichat_hp'] ) )
		: '';
	if ( $hp !== '' ) {
		aichat_log_debug( '[AIChat FileUpload] FAIL: honeypot not empty' );
		wp_send_json_error( [ 'message' => __( 'Request blocked.', 'axiachat-ai' ) ], 403 );
	}

	// ── Rate limit (reuse existing helper) ──
	$session = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
	$bot_slug = isset( $_POST['bot_slug'] ) ? sanitize_title( wp_unslash( $_POST['bot_slug'] ) ) : '';
	if ( function_exists( 'aichat_rate_limit_check' ) && $session ) {
		$rl = aichat_rate_limit_check( $session, $bot_slug );
		if ( is_wp_error( $rl ) ) {
			aichat_log_debug( '[AIChat FileUpload] FAIL: rate limit', [ 'error' => $rl->get_error_message() ] );
			wp_send_json_error( [ 'message' => $rl->get_error_message() ], 429 );
		}
	}

	// ── Resolve bot & check file_upload_enabled ──
	$bot = aichat_file_upload_resolve_bot( $bot_slug );
	if ( ! $bot || empty( $bot['file_upload_enabled'] ) ) {
		aichat_log_debug( '[AIChat FileUpload] FAIL: bot not found or uploads disabled', [
			'bot_slug'            => $bot_slug,
			'bot_found'           => (bool) $bot,
			'file_upload_enabled' => $bot['file_upload_enabled'] ?? 'N/A',
		] );
		wp_send_json_error( [ 'message' => __( 'File uploads are not enabled for this bot.', 'axiachat-ai' ) ], 403 );
	}

	$allowed_types_str = ! empty( $bot['file_upload_types'] ) ? $bot['file_upload_types'] : 'pdf,jpg,png,webp';
	$max_size_mb       = ! empty( $bot['file_upload_max_size'] ) ? intval( $bot['file_upload_max_size'] ) : 5;
	$max_size_bytes    = $max_size_mb * 1024 * 1024;

	// ── Validate uploaded file exists ──
	if ( empty( $_FILES['file'] ) || empty( $_FILES['file']['tmp_name'] ) ) {
		aichat_log_debug( '[AIChat FileUpload] FAIL: no file received', [
			'FILES_empty'    => empty( $_FILES['file'] ),
			'tmp_name_empty' => empty( $_FILES['file']['tmp_name'] ?? '' ),
			'post_max_size'  => ini_get( 'post_max_size' ),
			'upload_max'     => ini_get( 'upload_max_filesize' ),
		] );
		wp_send_json_error( [ 'message' => __( 'No file received.', 'axiachat-ai' ) ], 400 );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- File metadata sanitized individually below; tmp_name used only via WordPress upload APIs.
	$file_raw = $_FILES['file'];

	// Sanitize all file metadata immediately upon receipt.
	$safe_name = sanitize_file_name( wp_unslash( $file_raw['name'] ) );
	$safe_type = sanitize_mime_type( wp_unslash( $file_raw['type'] ) );
	$safe_size = absint( $file_raw['size'] );
	$safe_err  = absint( $file_raw['error'] );
	// tmp_name is server-generated; only used via WordPress upload APIs.
	$tmp_path  = $file_raw['tmp_name'];
	// No further references to $file_raw below — use $safe_* variables only.

	aichat_log_debug( '[AIChat FileUpload] File received', [
		'name'  => $safe_name,
		'type'  => $safe_type,
		'size'  => $safe_size,
		'error' => $safe_err,
	] );

	// Check upload error
	if ( $safe_err !== UPLOAD_ERR_OK ) {
		aichat_log_debug( '[AIChat FileUpload] FAIL: upload error code ' . $safe_err );
		wp_send_json_error( [ 'message' => __( 'Upload error.', 'axiachat-ai' ) . ' (code ' . $safe_err . ')' ], 400 );
	}

	// ── Size check ──
	if ( $safe_size > $max_size_bytes ) {
		wp_send_json_error( [
			'message' => sprintf(
				/* translators: %d: maximum file size in megabytes */
				__( 'File too large. Maximum allowed: %d MB.', 'axiachat-ai' ),
				$max_size_mb
			),
		], 400 );
	}

	// ── MIME / extension check ──
	$allowed_exts = array_map( 'trim', explode( ',', strtolower( $allowed_types_str ) ) );
	// Normalize jpeg ↔ jpg
	if ( in_array( 'jpg', $allowed_exts, true ) && ! in_array( 'jpeg', $allowed_exts, true ) ) {
		$allowed_exts[] = 'jpeg';
	}

	$file_info = wp_check_filetype_and_ext( $tmp_path, $safe_name );
	$ext       = strtolower( $file_info['ext'] ?: pathinfo( $safe_name, PATHINFO_EXTENSION ) );
	$mime      = $file_info['type'] ?: $safe_type;

	aichat_log_debug( '[AIChat FileUpload] Type check', [
		'wp_ext'       => $file_info['ext'],
		'wp_type'      => $file_info['type'],
		'resolved_ext' => $ext,
		'resolved_mime' => $mime,
		'allowed_exts' => implode( ',', $allowed_exts ),
	] );

	if ( ! in_array( $ext, $allowed_exts, true ) ) {
		aichat_log_debug( '[AIChat FileUpload] FAIL: ext not allowed', [ 'ext' => $ext, 'allowed' => implode( ',', $allowed_exts ) ] );
		wp_send_json_error( [
			'message' => sprintf(
				/* translators: %s: comma-separated list of allowed file extensions */
				__( 'File type not allowed. Accepted: %s', 'axiachat-ai' ),
				strtoupper( implode( ', ', $allowed_exts ) )
			),
		], 400 );
	}

	// Additional MIME validation
	$mime_whitelist = [
		'pdf'   => 'application/pdf',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'png'   => 'image/png',
		'webp'  => 'image/webp',
	];
	$expected_mime = isset( $mime_whitelist[ $ext ] ) ? $mime_whitelist[ $ext ] : '';
	if ( $expected_mime && $mime !== $expected_mime ) {
		// Allow some flexibility for JPEG variants
		$is_jpeg_variant = in_array( $ext, [ 'jpg', 'jpeg' ], true ) && strpos( $mime, 'image/' ) === 0;
		if ( ! $is_jpeg_variant ) {
			aichat_log_debug( '[AIChat FileUpload] FAIL: MIME mismatch', [ 'ext' => $ext, 'mime' => $mime, 'expected' => $expected_mime ] );
			wp_send_json_error( [ 'message' => __( 'File MIME type does not match extension.', 'axiachat-ai' ) ], 400 );
		}
	}

	// ── Process the file ──
	$file_id   = wp_generate_uuid4();
	$file_name = $safe_name;
	$is_pdf    = ( $ext === 'pdf' );
	$is_image  = in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp' ], true );

	$result = [
		'file_id'      => $file_id,
		'file_name'    => $file_name,
		'file_type'    => $is_pdf ? 'pdf' : 'image',
		'text_preview' => '',
	];

	$transient_data = [
		'file_name' => $file_name,
		'file_type' => $is_pdf ? 'pdf' : 'image',
		'text'      => '',
		'image_b64' => '',
		'mime'      => $mime,
		'created'   => time(),
	];

	if ( $is_pdf ) {
		// ── Extract text from PDF using existing pipeline ──
		$extracted = aichat_file_upload_extract_pdf( $tmp_path, $file_name );
		if ( is_wp_error( $extracted ) ) {
			aichat_log_debug( '[AIChat FileUpload] PDF extraction failed: ' . $extracted->get_error_message() );
			wp_send_json_error( [
				'message' => __( 'Could not extract text from the PDF.', 'axiachat-ai' ) . ' ' . $extracted->get_error_message(),
			], 422 );
		}

		// Truncate to safety limit
		$text = mb_substr( $extracted, 0, AICHAT_FILE_CONTEXT_MAX_CHARS );
		$transient_data['text'] = $text;
		$result['text_preview'] = mb_substr( $text, 0, 200 ) . ( mb_strlen( $text ) > 200 ? '…' : '' );

		aichat_log_debug( '[AIChat FileUpload] PDF processed', [
			'file'     => $file_name,
			'text_len' => mb_strlen( $text ),
		] );

	} elseif ( $is_image ) {
		// ── Optimise image (resize + compress) then encode as base64 ──
		$optimised = aichat_file_upload_optimise_image( $tmp_path, $mime, $ext );

		if ( is_wp_error( $optimised ) ) {
			// Fallback: read original if optimisation fails
			aichat_log_debug( '[AIChat FileUpload] Image optimisation failed, using original: ' . $optimised->get_error_message() );
			$fs_img = aichat_wp_filesystem();
			$image_data = $fs_img ? $fs_img->get_contents( $tmp_path ) : false;
			if ( $image_data === false ) {
				wp_send_json_error( [ 'message' => __( 'Could not read image file.', 'axiachat-ai' ) ], 500 );
			}
			$final_mime = $mime;
		} else {
			$image_data = $optimised['data'];
			$final_mime = $optimised['mime'];
		}

		// Hard limit (10 MB raw → ~13 MB base64)
		if ( strlen( $image_data ) > 10 * 1024 * 1024 ) {
			wp_send_json_error( [ 'message' => __( 'Image file is too large for processing.', 'axiachat-ai' ) ], 400 );
		}

		$b64 = base64_encode( $image_data );
		$transient_data['image_b64'] = $b64;
		$transient_data['mime']      = $final_mime;

		// Generate a tiny thumbnail for log storage (~2-4 KB)
		$thumb_b64 = aichat_file_upload_make_thumbnail( $tmp_path, 80, 50 );
		if ( $thumb_b64 ) {
			$transient_data['thumb_b64'] = $thumb_b64;
			$result['thumb']             = 'data:image/jpeg;base64,' . $thumb_b64;
		}

		$result['text_preview'] = sprintf(
			/* translators: 1: file name, 2: optimised size */
			__( 'Image: %1$s (%2$s)', 'axiachat-ai' ),
			$file_name,
			size_format( strlen( $image_data ) )
		);

		aichat_log_debug( '[AIChat FileUpload] Image processed', [
			'file'         => $file_name,
			'original_len' => $safe_size,
			'final_len'    => strlen( $image_data ),
			'savings_pct'  => $safe_size > 0 ? round( ( 1 - strlen( $image_data ) / $safe_size ) * 100 ) : 0,
			'final_mime'   => $final_mime,
			'thumb'        => $thumb_b64 ? 'yes (' . strlen( $thumb_b64 ) . ' b64 chars)' : 'no',
		] );
	}

	// ── Store in transient ──
	set_transient( 'aichat_file_' . $file_id, $transient_data, AICHAT_FILE_TRANSIENT_TTL );

	wp_send_json_success( $result );
}

/**
 * Resolve bot row from slug.
 *
 * @param string $slug Bot slug.
 * @return array|null Bot row or null.
 */
function aichat_file_upload_resolve_bot( $slug ) {
	if ( ! function_exists( 'aichat_bots_table' ) ) {
		return null;
	}
	global $wpdb;
	$table = aichat_bots_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is a trusted plugin table name.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE slug = %s LIMIT 1", $slug ), ARRAY_A );
	return $row ?: null;
}

/**
 * Extract text from a PDF using the existing multi-level extraction pipeline.
 *
 * 1. pdftotext (system binary)
 * 2. Smalot\PdfParser (PHP library)
 * 3. AI Vision fallback (Imagick + provider API)
 *
 * @param string $path      Absolute path to temp PDF file.
 * @param string $filename  Original filename (for logging).
 * @return string|WP_Error  Extracted text or error.
 */
function aichat_file_upload_extract_pdf( $path, $filename ) {
	// Reuse the unified extractor from contexto-pdf-ajax.php
	if ( function_exists( 'aichat_extract_text' ) ) {
		$result = aichat_extract_text( $path, 'application/pdf', $filename );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_string( $result ) && strlen( trim( $result ) ) > 20 ) {
			return trim( $result );
		}
		return new WP_Error( 'empty_extraction', __( 'No readable text found in the PDF.', 'axiachat-ai' ) );
	}

	// Fallback: try Smalot directly
	if ( function_exists( 'aichat_extract_pdf_with_smalot' ) ) {
		$r = aichat_extract_pdf_with_smalot( $path );
		if ( ! is_wp_error( $r ) && strlen( trim( $r ) ) > 20 ) {
			return trim( $r );
		}
	}

	return new WP_Error( 'no_extractor', __( 'No PDF text extractor available.', 'axiachat-ai' ) );
}

/**
 * Optimise an image for AI vision: resize (max longest side) + compress to JPEG.
 *
 * Uses wp_get_image_editor() which delegates to Imagick or GD — whichever
 * WordPress has available. WebP/PNG images are converted to JPEG to maximise
 * compression (unless the PNG has alpha transparency, which is rare for chat
 * uploads and not worth the complexity of detecting).
 *
 * @param string $tmp_path  Path to the temporary uploaded file.
 * @param string $mime      Original MIME type (image/jpeg, image/png, image/webp).
 * @param string $ext       Original extension (jpg, jpeg, png, webp).
 * @return array|WP_Error   { data: string (binary), mime: string } or WP_Error.
 */
function aichat_file_upload_optimise_image( $tmp_path, $mime, $ext ) {
	$max_dim = defined( 'AICHAT_IMAGE_MAX_DIMENSION' ) ? (int) AICHAT_IMAGE_MAX_DIMENSION : 1536;
	$quality = defined( 'AICHAT_IMAGE_JPEG_QUALITY' )  ? (int) AICHAT_IMAGE_JPEG_QUALITY  : 82;

	// Prefer GD over Imagick for this operation: GD works entirely in memory
	// and doesn't need /tmp write access (Imagick fails on many VPS with
	// PrivateTmp / open_basedir restrictions).
	$prefer_gd = static function () {
		return [ 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ];
	};
	add_filter( 'wp_image_editors', $prefer_gd, 99 );

	$editor = wp_get_image_editor( $tmp_path );

	remove_filter( 'wp_image_editors', $prefer_gd, 99 );

	if ( is_wp_error( $editor ) ) {
		return $editor;
	}

	$size = $editor->get_size(); // { width, height }
	$w = isset( $size['width'] )  ? (int) $size['width']  : 0;
	$h = isset( $size['height'] ) ? (int) $size['height'] : 0;

	// Resize only if either dimension exceeds the cap
	if ( $w > $max_dim || $h > $max_dim ) {
		$resized = $editor->resize( $max_dim, $max_dim, false ); // false = keep aspect ratio
		if ( is_wp_error( $resized ) ) {
			return $resized;
		}
		$new_size = $editor->get_size();
		aichat_log_debug( '[AIChat FileUpload] Image resized', [
			'from' => "{$w}x{$h}",
			'to'   => $new_size['width'] . 'x' . $new_size['height'],
		] );
	}

	// Set quality and save as JPEG to a temp file
	$editor->set_quality( $quality );

	// Build temp destination path (always .jpg for max compression).
	// Use get_temp_dir() (WP's writable temp) instead of dirname($tmp_path)
	// which may point to a restricted /tmp on certain VPS setups.
	$tmp_dir  = get_temp_dir();
	$out_name = wp_unique_filename( $tmp_dir, 'aichat_opt_' . wp_generate_uuid4() . '.jpg' );
	$out_path = trailingslashit( $tmp_dir ) . $out_name;

	$saved = $editor->save( $out_path, 'image/jpeg' );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	$real_path = isset( $saved['path'] ) ? $saved['path'] : $out_path;

	$fs_opt = aichat_wp_filesystem();
	$data   = $fs_opt ? $fs_opt->get_contents( $real_path ) : false;

	// Clean up temp file
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	@unlink( $real_path );

	if ( $data === false ) {
		return new WP_Error( 'read_failed', __( 'Could not read optimised image.', 'axiachat-ai' ) );
	}

	return [
		'data' => $data,
		'mime' => 'image/jpeg',
	];
}

/**
 * Generate a tiny JPEG thumbnail for log storage.
 *
 * Returns a base64-encoded JPEG string (~2-4 KB) or empty string on failure.
 * Uses GD directly to avoid Imagick /tmp issues and keep it lightweight.
 *
 * @param string $tmp_path Path to the uploaded (temporary) file.
 * @param int    $max_dim  Max thumbnail dimension in pixels.
 * @param int    $quality  JPEG quality (0-100).
 * @return string Base64-encoded JPEG or empty string.
 */
function aichat_file_upload_make_thumbnail( $tmp_path, $max_dim = 80, $quality = 50 ) {
	if ( ! function_exists( 'imagecreatefromstring' ) ) {
		return '';
	}

	$fs_thumb = aichat_wp_filesystem();
	$raw      = $fs_thumb ? $fs_thumb->get_contents( $tmp_path ) : false;
	if ( $raw === false ) {
		return '';
	}

	$src = @imagecreatefromstring( $raw );
	if ( ! $src ) {
		return '';
	}

	$w = imagesx( $src );
	$h = imagesy( $src );
	if ( $w <= 0 || $h <= 0 ) {
		imagedestroy( $src );
		return '';
	}

	// Calculate new dimensions (fit inside $max_dim square)
	if ( $w > $max_dim || $h > $max_dim ) {
		$ratio = min( $max_dim / $w, $max_dim / $h );
		$nw    = max( 1, (int) round( $w * $ratio ) );
		$nh    = max( 1, (int) round( $h * $ratio ) );
	} else {
		$nw = $w;
		$nh = $h;
	}

	$dst = imagecreatetruecolor( $nw, $nh );
	if ( ! $dst ) {
		imagedestroy( $src );
		return '';
	}

	imagecopyresampled( $dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h );
	imagedestroy( $src );

	// Capture JPEG output to buffer
	ob_start();
	imagejpeg( $dst, null, $quality );
	$jpeg = ob_get_clean();
	imagedestroy( $dst );

	if ( ! $jpeg ) {
		return '';
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	return base64_encode( $jpeg );
}
