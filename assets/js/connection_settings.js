/* global vb_connection_settings */
jQuery(function () {
    var vb_connection_basic_fields = jQuery(vb_connection_settings.connection_basic_fields_ids).closest("tr");
    var vb_connection_advanced_fields = jQuery(vb_connection_settings.connection_advanced_fields_ids).closest("tr");
    var vb_notification_advanced_fields = jQuery(vb_connection_settings.notification_advanced_fields_ids).closest("tr");

    vb_connection_basic_fields.hide();
    vb_connection_advanced_fields.hide();
    vb_notification_advanced_fields.hide();

    jQuery(vb_connection_settings.basic_settings_button_id).on("click", function () {
        vb_connection_advanced_fields.hide();
        vb_connection_basic_fields.show();
        return false;
    });

    jQuery(vb_connection_settings.advanced_settings_button_id).on("click", function () {
        vb_connection_basic_fields.hide();
        vb_connection_advanced_fields.show();
        return false;
    });

    jQuery(vb_connection_settings.payment_notification_advanced_button_id).on("click", function () {
        vb_notification_advanced_fields.show();
        return false;
    });

    jQuery(vb_connection_settings.process_callback_data_button_id).on("click", function () {
        if (!confirm(vb_connection_settings.message)) {
            return false;
        }

        var $this = jQuery(this);

        if ($this.prop("disabled")) {
            return false;
        }

        $this.prop("disabled", true);
        $this.next(".spinner").addClass("is-active");

        var callback_data = jQuery(vb_connection_settings.vb_callback_data_field_id).val();

        jQuery.ajax({
            type: "POST",
            data: {
                _ajax_nonce: vb_connection_settings.nonce,
                action: vb_connection_settings.action,
                callback_data: callback_data
            },
            dataType: "json",
            url: ajaxurl,
            complete: function (response, textStatus) {
                $this.prop("disabled", false);
                $this.next(".spinner").removeClass("is-active");

                if (response.responseJSON && response.responseJSON.data) {
                    alert(response.responseJSON.data);
                } else {
                    alert(response.responseText);
                }
            }
        });

        return false;
    });
});
