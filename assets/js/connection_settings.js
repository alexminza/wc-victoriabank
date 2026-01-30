/* global vb_connection_settings, ajaxurl */
jQuery(function ($) {
    var $connectionBasicFields = $(vb_connection_settings.connection_basic_fields_ids).closest('tr');
    var $connectionAdvancedFields = $(vb_connection_settings.connection_advanced_fields_ids).closest('tr');
    var $notificationAdvancedFields = $(vb_connection_settings.notification_advanced_fields_ids).closest('tr');

    $connectionBasicFields.hide();
    $connectionAdvancedFields.hide();
    $notificationAdvancedFields.hide();

    $(vb_connection_settings.basic_settings_button_id).on('click', function (e) {
        e.preventDefault();
        $connectionAdvancedFields.hide();
        $connectionBasicFields.show();
    });

    $(vb_connection_settings.advanced_settings_button_id).on('click', function (e) {
        e.preventDefault();
        $connectionBasicFields.hide();
        $connectionAdvancedFields.show();
    });

    $(vb_connection_settings.payment_notification_advanced_button_id).on('click', function (e) {
        e.preventDefault();
        $notificationAdvancedFields.toggle();
    });

    $(vb_connection_settings.process_callback_data_button_id).on('click', function (e) {
        e.preventDefault();

        if (!confirm(vb_connection_settings.message)) {
            return;
        }

        var $this = $(this);
        if ($this.prop('disabled')) {
            return;
        }

        $this.prop('disabled', true);
        $this.next('.spinner').addClass('is-active');

        var callbackData = $(vb_connection_settings.vb_callback_data_field_id).val();

        $.ajax({
            type: 'POST',
            url: ajaxurl,
            dataType: 'json',
            data: {
                _ajax_nonce: vb_connection_settings.nonce,
                action: vb_connection_settings.action,
                callback_data: callbackData
            },
            complete: function (response) {
                $this.prop('disabled', false);
                $this.next('.spinner').removeClass('is-active');

                if (response.responseJSON && response.responseJSON.data) {
                    alert(response.responseJSON.data);
                } else {
                    alert(response.responseText);
                }
            }
        });
    });
});
