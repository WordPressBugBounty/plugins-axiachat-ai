/**
 * Appointments Admin Settings – inline‑extracted companion script.
 *
 * Depends on: aichat-appointments-admin (parent script)
 * Localized object: aichatAppointmentsSettings
 *   .isPremium       bool
 *   .gcalAuthUrl     string
 *   .i18n.confirmDisconnectGcal  string
 */
(function($){
    if (typeof aichatAppointmentsSettings === 'undefined') return;
    var cfg = aichatAppointmentsSettings;

    // Toggle destination options
    $('.destination-option input[type="radio"]').on('change', function() {
            $('.destination-option').removeClass('active');
            $(this).closest('.destination-option').addClass('active');

            var destination = $(this).val();
            if (destination === 'bookly') {
                $('#bookly-settings-card').slideDown(200);
                $('#amelia-settings-card').slideUp(200);
                $('#ssa-settings-card').slideUp(200);
                $('#gcal-settings-card').slideUp(200);
                $('.working-hours-card').slideUp(200);
                $('.internal-gcal-card').slideUp(200);
            } else if (destination === 'amelia') {
                $('#bookly-settings-card').slideUp(200);
                $('#amelia-settings-card').slideDown(200);
                $('#ssa-settings-card').slideUp(200);
                $('#gcal-settings-card').slideUp(200);
                $('.working-hours-card').slideUp(200);
                $('.internal-gcal-card').slideUp(200);
            } else if (destination === 'ssa') {
                $('#bookly-settings-card').slideUp(200);
                $('#amelia-settings-card').slideUp(200);
                $('#ssa-settings-card').slideDown(200);
                $('#gcal-settings-card').slideUp(200);
                $('.working-hours-card').slideUp(200);
                $('.internal-gcal-card').slideUp(200);
            } else if (destination === 'google_calendar') {
                $('#bookly-settings-card').slideUp(200);
                $('#amelia-settings-card').slideUp(200);
                $('#ssa-settings-card').slideUp(200);
                $('#gcal-settings-card').slideDown(200);
                $('.working-hours-card').slideDown(200);
                $('.internal-gcal-card').slideDown(200);
            } else {
                // Internal
                $('#bookly-settings-card').slideUp(200);
                $('#amelia-settings-card').slideUp(200);
                $('#ssa-settings-card').slideUp(200);
                $('#gcal-settings-card').slideUp(200);
                $('.working-hours-card').slideDown(200);
                $('.internal-gcal-card').slideDown(200);
            }
        });

        // Initial visibility based on current destination
        var currentDestination = $('input[name="destination"]:checked').val();
        if (currentDestination !== 'internal' && currentDestination !== 'google_calendar') {
            $('.working-hours-card').hide();
            $('.internal-gcal-card').hide();
        }

        // Google Calendar disconnect button
        $('#gcal-disconnect').on('click', function(e) {
            e.preventDefault();
            if (!confirm(cfg.i18n.confirmDisconnectGcal)) return;
            $.post(ajaxurl, {
                action: 'aichat_appointments_gcal_disconnect',
                nonce: $('#appointments_nonce').val()
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data || 'Error disconnecting');
                }
            });
        });

        // Google Calendar reconnect button
        $('#gcal-reconnect').on('click', function(e) {
            e.preventDefault();
            window.location.href = $('#gcal-connect').attr('href') || cfg.gcalAuthUrl;
        });

        // Toggle Bookly service default dropdown based on mode
        $('#bookly_service_mode').on('change', function() {
            if ($(this).val() === 'default') {
                $('#bookly-service-default-row').slideDown(200);
            } else {
                $('#bookly-service-default-row').slideUp(200);
            }
        });

        // Toggle Bookly staff default dropdown based on mode
        $('#bookly_staff_mode').on('change', function() {
            if ($(this).val() === 'default') {
                $('#bookly-staff-default-row').slideDown(200);
            } else {
                $('#bookly-staff-default-row').slideUp(200);
            }
        });

        // Toggle Amelia service default dropdown based on mode
        $('#amelia_service_mode').on('change', function() {
            if ($(this).val() === 'default') {
                $('#amelia-service-default-row').slideDown(200);
            } else {
                $('#amelia-service-default-row').slideUp(200);
            }
        });

        // Toggle Amelia provider default dropdown based on mode
        $('#amelia_provider_mode').on('change', function() {
            if ($(this).val() === 'default') {
                $('#amelia-provider-default-row').slideDown(200);
            } else {
                $('#amelia-provider-default-row').slideUp(200);
            }
        });

        // Toggle SSA service default dropdown based on mode
        $('#ssa_service_mode').on('change', function() {
            if ($(this).val() === 'default') {
                $('#ssa-service-default-row').slideDown(200);
            } else {
                $('#ssa-service-default-row').slideUp(200);
            }
        });

    // Reset confirmation email template
    $('#resetConfirmationTemplate').on('click', function() {
        $('#confirmation_email_subject').val('');
        $('#confirmation_email_body').val('');
    });

    // Reset reminder email template
    $('#resetReminderTemplate').on('click', function() {
        $('#reminder_email_subject').val('');
        $('#reminder_email_body').val('');
    });

    // Day toggle: disabled class on row (replaces inline onchange).
    $(document).on('change', '.aichat-day-toggle-cb', function() {
        $(this).closest('.day-row').toggleClass('disabled', !this.checked);
    });
})(jQuery);
