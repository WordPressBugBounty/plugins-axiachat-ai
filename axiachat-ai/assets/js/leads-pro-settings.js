/**
 * Leads Pro Admin Settings – inline‑extracted companion script.
 *
 *
 * Depends on: aichat-leads-admin (parent script)
 * Uses: aichatLeadsAdmin (already localized by loader.php)
 * Localized object: aichatLeadsProSettings
 */
(function($){

    /* ── Destination config toggle + Google Sheets ── */
    function toggleDestConfig() {
        var dest = $('input[name=destination]:checked').val();
        $('.ll-dest-cf7, .ll-dest-wpforms, .ll-dest-google_sheets').hide();
        if (dest) $('.ll-dest-' + dest).show();
    }
    $('input[name=destination]').on('change', toggleDestConfig);
    toggleDestConfig();

    // Google Sheets – Disconnect
    $('#ll-gsheets-disconnect').on('click', function(){
        if (!confirm(aichatLeadsAdmin.i18n.confirm_disconnect || 'Disconnect?')) return;
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

})(jQuery);
