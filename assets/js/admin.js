/**
 * Eclicsys Admin Scripts
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Force create shipment
        $(document).on('click', '.eclicsys-force-create', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var originalText = $btn.text();

            $btn.prop('disabled', true).text(eclicsysAdmin.strings.sending);

            $.post(eclicsysAdmin.ajaxUrl, {
                action: 'eclicsys_force_create_order',
                order_id: $btn.data('order-id'),
                nonce: eclicsysAdmin.nonce
            }, function (response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(eclicsysAdmin.strings.error + (response.data?.message || 'Unknown error'));
                    $btn.prop('disabled', false).text(originalText);
                }
            }).fail(function () {
                alert(eclicsysAdmin.strings.error + 'Server error');
                $btn.prop('disabled', false).text(originalText);
            });
        });

        // Debug: preview payload
        $(document).on('click', '.eclicsys-debug-payload', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var $output = $('#eclicsys-debug-output');

            $btn.prop('disabled', true).text('Loading...');
            $output.hide();

            $.post(eclicsysAdmin.ajaxUrl, {
                action: 'eclicsys_debug_payload',
                order_id: $btn.data('order-id'),
                nonce: eclicsysAdmin.nonce
            }, function (response) {
                $btn.prop('disabled', false).text('Debug Payload');

                if (response.success) {
                    $output.show().html('<pre>' + JSON.stringify(response.data, null, 2) + '</pre>');
                } else {
                    alert('Error: ' + (response.data?.message || 'Unknown error'));
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('Debug Payload');
                alert('Server error');
            });
        });
    });
})(jQuery);