/**
 * Leads Pro Extensions – inline‑extracted companion script from loader.php.
 *
 * Extends the base LeadsAdmin object with extra destination toggles
 * (CF7 / WPForms / Google Sheets disconnect & test).
 *
 * Depends on: aichat-leads-admin (parent script)
 * Uses: aichatLeadsAdmin (already localized), LeadsAdmin global object
 */
(function($){
    if (typeof LeadsAdmin === 'undefined') return;
    var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function(s){ return s; };

    var _origToggle = LeadsAdmin.toggleDestinationConfig;
    LeadsAdmin.toggleDestinationConfig = function() {
        _origToggle.call(this);
        var selected = $('input[name="destination"]:checked').val();
        if (selected === 'cf7') { $('.cf7-settings').slideDown(); } else { $('.cf7-settings').slideUp(); }
        if (selected === 'wpforms') { $('.wpforms-settings').slideDown(); } else { $('.wpforms-settings').slideUp(); }
        if (selected === 'google_sheets') { $('#gsheets-settings-card').slideDown(); } else { $('#gsheets-settings-card').slideUp(); }
    };

    // Google Sheets disconnect
    LeadsAdmin.gsheetsDisconnect = function(e) {
        e.preventDefault();
        if (!confirm(aichatLeadsAdmin.i18n.confirm_disconnect || 'Are you sure?')) return;
        var $button = $(e.target).closest('button');
        var originalHtml = $button.html();
        $button.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i>');
        $.ajax({
            url: aichatLeadsAdmin.ajax_url, type: 'POST',
            data: { action: 'aichat_leads_gsheets_disconnect', nonce: aichatLeadsAdmin.nonce },
            success: function(r) {
                if (r.success) { window.location.reload(); }
                else { LeadsAdmin.showNotice('danger', r.data.message || 'Error'); $button.prop('disabled', false).html(originalHtml); }
            },
            error: function() { LeadsAdmin.showNotice('danger', 'Error'); $button.prop('disabled', false).html(originalHtml); }
        });
    };

    // Google Sheets test connection
    LeadsAdmin.gsheetsTestConnection = function(e) {
        e.preventDefault();
        var $button = $(e.target).closest('button');
        var $result = $('#gsheets-test-result');
        var originalHtml = $button.html();
        var spreadsheetId = $('#gsheets_spreadsheet_id').val();
        var sheetName = $('#gsheets_sheet_name').val();
        if (!spreadsheetId) {
            $result.html('<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>' + (aichatLeadsAdmin.i18n.spreadsheet_id_required || 'Please enter a Spreadsheet ID') + '</span>');
            return;
        }
        $button.prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Testing...');
        $result.html('');
        $.ajax({
            url: aichatLeadsAdmin.ajax_url, type: 'POST',
            data: { action: 'aichat_leads_gsheets_test', nonce: aichatLeadsAdmin.nonce, spreadsheet_id: spreadsheetId, sheet_name: sheetName },
            success: function(r) {
                if (r.success) { $result.html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + (r.data.message || 'OK') + '</span>'); }
                else { $result.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + (r.data.message || 'Error') + '</span>'); }
            },
            error: function() { $result.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Error</span>'); },
            complete: function() { $button.prop('disabled', false).html(originalHtml); }
        });
    };

    // Bind Google Sheets buttons
    $('#gsheets-disconnect').on('click', LeadsAdmin.gsheetsDisconnect.bind(LeadsAdmin));
    $('#gsheets-test-connection').on('click', LeadsAdmin.gsheetsTestConnection.bind(LeadsAdmin));
})(jQuery);
