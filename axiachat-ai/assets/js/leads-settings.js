/**
 * Leads Admin Settings – inline‑extracted companion script.
 *
 * Depends on: aichat-leads-admin (parent script)
 * Localized object: aichatLeadsSettings  (added by PHP render functions)
 *   .isPremium          bool
 *   .isEdit             bool
 *   .defaultSubmitText  string
 *   .defaultHeader      string (HTML)
 *   .redirectUrl        string
 *   .i18n.*             translated strings
 */
(function($){
    if (typeof aichatLeadsSettings === 'undefined') return;
    var cfg = aichatLeadsSettings;

    /* ══════════════════════════════════════════════
     * Block 1 — Lead‑lists table: delete button
     * ══════════════════════════════════════════════ */
    $('.btn-delete-list').on('click', function(){
        var id = $(this).data('list-id');
        var name = $(this).data('list-name');
        if (!confirm(cfg.i18n.deleteList + ' "' + name + '"? ' + cfg.i18n.leadsReassign)) return;
        $.post(aichatLeadsAdmin.ajax_url, {
            action: 'aichat_lead_lists_delete',
            nonce: aichatLeadsAdmin.nonce,
            id: id
        }, function(r){
            if (r.success) location.reload();
            else alert(r.data && r.data.message ? r.data.message : 'Error');
        });
    });

    /* ══════════════════════════════════════════════
     * Block 2 — List create/edit form
     * ══════════════════════════════════════════════ */
    if ($('#aichat-lead-list-form').length) {
        // Auto-generate slug from name (only on create) + update guide text
        $('input[name="name"]').on('input', function(){
            var name = $(this).val() || 'list name';
            if (!cfg.isEdit) {
                var slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').substring(0, 64);
                $('input[name="slug"]').val(slug);
            }
            $('#ll-guide-name-hint').text(name);
            updateToolNames();
        });

        // Show/hide destination config cards
        function toggleDestConfig() {
                var dest = $('input[name="destination"]:checked').val();
                $('.ll-dest-cf7, .ll-dest-wpforms, .ll-dest-google_sheets').hide();
                if (dest) $('.ll-dest-' + dest).show();
            }
            $('input[name="destination"]').on('change', toggleDestConfig);
            toggleDestConfig();

            // Google Sheets – Disconnect
            $('#ll-gsheets-disconnect').on('click', function(){
                if (!confirm(cfg.i18n.disconnectGsheets)) return;
                var $btn = $(this).prop('disabled', true);
                $.post(aichatLeadsAdmin.ajax_url, {
                    action: 'aichat_leads_gsheets_disconnect',
                    nonce: aichatLeadsAdmin.nonce
                }, function(r){
                    if (r.success) location.reload();
                    else { alert(r.data && r.data.message ? r.data.message : 'Error'); $btn.prop('disabled', false); }
                });
            });

            // Google Sheets – Test Connection
            $('#ll-gsheets-test-connection').on('click', function(){
                var $btn = $(this).prop('disabled', true);
                var $res = $('#ll-gsheets-test-result').html('<span class="spinner-border spinner-border-sm"></span>');
                $.post(aichatLeadsAdmin.ajax_url, {
                    action: 'aichat_leads_gsheets_test',
                    nonce: aichatLeadsAdmin.nonce,
                    spreadsheet_id: $('#ll_gsheets_spreadsheet_id').val(),
                    sheet_name: $('#ll_gsheets_sheet_name').val()
                }, function(r){
                    $btn.prop('disabled', false);
                    if (r.success) {
                        $res.html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + (r.data.message || 'OK') + '</span>');
                    } else {
                        $res.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + (r.data && r.data.message ? r.data.message : 'Error') + '</span>');
                    }
                }).fail(function(){ $btn.prop('disabled', false); $res.html('<span class="text-danger">Request failed</span>'); });
            });

        // Add field row
        $('#btn-add-field').on('click', function(){
            var idx = $('#fields-body .field-row').length;
            var row = '<tr class="field-row" data-idx="'+idx+'">'
                + '<td class="text-center text-muted" style="cursor:grab"><i class="bi bi-grip-vertical"></i></td>'
                + '<td><input type="text" class="form-control form-control-sm field-key" placeholder="field_key"></td>'
                + '<td><input type="text" class="form-control form-control-sm field-label" placeholder="Label"></td>'
                + '<td><select class="form-select form-select-sm field-type"><option value="text">Text</option><option value="email">Email</option><option value="tel">Phone</option><option value="textarea">Textarea</option><option value="number">Number</option><option value="url">URL</option></select></td>'
                + '<td class="text-center"><input type="checkbox" class="form-check-input field-required"></td>'
                + '<td><input type="text" class="form-control form-control-sm field-desc" placeholder="Description for AI"></td>'
                + '<td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-field"><i class="bi bi-x"></i></button></td>'
                + '</tr>';
            $('#fields-body').append(row);
            updatePreview();
        });

        // Remove field row
        $(document).on('click', '.btn-remove-field', function(){
            $(this).closest('.field-row').remove();
            updatePreview();
        });

        // Field label/key edits → update preview
        $(document).on('input', '.field-label, .field-key', function(){ updatePreview(); });
        $(document).on('change', '.field-type, .field-required', function(){ updatePreview(); });

        // Collect fields JSON
        function collectFields() {
            var fields = [];
            $('#fields-body .field-row').each(function(){
                var key = $(this).find('.field-key').val().trim();
                var label = $(this).find('.field-label').val().trim();
                if (!key || !label) return;
                fields.push({
                    key: key.toLowerCase().replace(/[^a-z0-9_]/g, '_'),
                    label: label,
                    type: $(this).find('.field-type').val(),
                    required: $(this).find('.field-required').is(':checked'),
                    description: $(this).find('.field-desc').val().trim()
                });
            });
            return fields;
        }

        // Collect destination config from the visible destination card
        function collectDestConfig() {
            var config = {};
            var dest = $('input[name="destination"]:checked').val();
            $('.ll-dest-' + dest + ' .dest-config-field').each(function(){
                config[$(this).data('key')] = $(this).val();
            });
            return config;
        }

        // AI Tools: dynamic tool names from slug
        function updateToolNames() {
            var slug = $('input[name="slug"]').val() || 'slug';
            var suffix = '_' + slug;
            $('#ll-tool-name-save').text('save_lead' + suffix);
            $('#ll-tool-name-form').text('show_form' + suffix);
            $('#ll-guide-form-hint').text('show_form' + suffix);
        }
        $('input[name="slug"]').on('input', updateToolNames);

        // Initialize preview with current fields on page load
        updatePreview();

        // ── Form Appearance: live preview ──
        $('#ll_form_bg_color_picker').on('input', function(){ $('#ll_form_bg_color').val($(this).val()); updatePreview(); });
        $('#ll_form_bg_color').on('input', function(){ var v = $(this).val(); if (/^#[0-9a-f]{6}$/i.test(v)) $('#ll_form_bg_color_picker').val(v); updatePreview(); });
        $('#ll_form_btn_color_picker').on('input', function(){ $('#ll_form_btn_color').val($(this).val()); updatePreview(); });
        $('#ll_form_btn_color').on('input', function(){ var v = $(this).val(); if (/^#[0-9a-f]{6}$/i.test(v)) $('#ll_form_btn_color_picker').val(v); updatePreview(); });

        $('input[name="form_mode"]').on('change', updatePreview);
        $('#ll_form_header, #ll_form_submit_text').on('input', updatePreview);

        function updatePreview() {
            var bg = $('#ll_form_bg_color').val() || '#1f2937';
            var btn = $('#ll_form_btn_color').val() || '#0073aa';
            var mode = $('input[name="form_mode"]:checked').val() || 'full';
            var header = $('#ll_form_header').val();
            var submitTxt = $('#ll_form_submit_text').val() || cfg.defaultSubmitText;

            $('#ll-form-preview').css('background', bg);
            $('#ll-preview-btn').css('background', btn).text(submitTxt);

            if (header) {
                var safe = header.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '');
                $('#ll-preview-header').html(safe).show();
            } else {
                $('#ll-preview-header').html(cfg.defaultHeader).show();
            }

            // Rebuild preview fields from current field rows
            var fieldsHtml = '';
            $('#fields-body .field-row').each(function() {
                var label = $(this).find('.field-label').val() || $(this).find('.field-key').val() || 'Field';
                var ftype = $(this).find('.field-type').val() || 'text';
                var isReq = $(this).find('.field-required').is(':checked');
                var labelClass = (mode === 'compact') ? 'd-none' : 'd-block';
                var reqMark = isReq ? ' <span style="color:#ff9b9b">*</span>' : '';
                var safeLabel = $('<span>').text(label).html();
                var fieldStyle = 'border:1px solid rgba(255,255,255,.25); border-radius:6px; background:rgba(255,255,255,.1); font-size:13px; color:rgba(255,255,255,.5);';
                var fieldHtml;
                if (ftype === 'textarea') {
                    fieldHtml = '<div style="' + fieldStyle + ' padding:7px 10px; min-height:48px;">' + safeLabel + '</div>';
                } else {
                    fieldHtml = '<div style="' + fieldStyle + ' padding:7px 10px;">' + safeLabel + '</div>';
                }
                fieldsHtml += '<div class="ll-preview-field-group mb-2">'
                    + '<label class="ll-preview-label ' + labelClass + '" style="font-size:12px; font-weight:600; margin-bottom:2px;">' + safeLabel + reqMark + '</label>'
                    + fieldHtml + '</div>';
            });
            if (!fieldsHtml) {
                fieldsHtml = '<div class="text-center" style="opacity:.5; padding:8px 0; font-size:12px;">No fields defined</div>';
            }
            $('#ll-preview-fields').html(fieldsHtml);
        }

        // Save form
        $('#aichat-lead-list-form').on('submit', function(e){
            e.preventDefault();
            var $btn = $('#btn-save-list').prop('disabled', true);
            var isEdit = $('input[name="list_id"]').length > 0;
            var captureEnabled = $('#ll_capture_enabled').is(':checked') ? 1 : 0;

            var formData = {
                action: isEdit ? 'aichat_lead_lists_update' : 'aichat_lead_lists_create',
                nonce: aichatLeadsAdmin.nonce,
                name: $('input[name="name"]').val(),
                slug: $('input[name="slug"]').val(),
                description: $('textarea[name="description"]').val(),
                status: $('select[name="status"]').val(),
                destination: $('input[name="destination"]:checked').val(),
                destination_config: JSON.stringify(collectDestConfig()),
                fields: JSON.stringify(collectFields()),
                tool_enabled: captureEnabled,
                form_enabled: captureEnabled,
                assigned_bots: $('#ll_assigned_bots').val(),
                notify_enabled: $('#ll_notify').is(':checked') ? 1 : 0,
                notify_email: $('input[name="notify_email"]').val(),
                email_subject: $('input[name="email_subject"]').val(),
                email_body: $('textarea[name="email_body"]').val(),
                webhook_enabled: $('#ll_webhook').is(':checked') ? 1 : 0,
                webhook_url: $('input[name="webhook_url"]').val(),
                store_ip: $('#ll_store_ip').is(':checked') ? 1 : 0,
                retention_days: $('input[name="retention_days"]').val(),
                form_mode: $('input[name="form_mode"]:checked').val() || 'full',
                form_header: $('textarea[name="form_header"]').val(),
                form_submit_text: $('input[name="form_submit_text"]').val(),
                form_success_msg: $('input[name="form_success_msg"]').val(),
                form_bg_color: $('input[name="form_bg_color"]').val(),
                form_btn_color: $('input[name="form_btn_color"]').val()
            };

            if (isEdit) {
                formData.list_id = $('input[name="list_id"]').val();
            }

            $.post(aichatLeadsAdmin.ajax_url, formData, function(r){
                $btn.prop('disabled', false);
                if (r.success) {
                    window.location.href = cfg.redirectUrl;
                } else {
                    alert(r.data && r.data.message ? r.data.message : 'Error');
                }
            }).fail(function(){
                $btn.prop('disabled', false);
                alert('Request failed');
            });
        });
    }

})(jQuery);
