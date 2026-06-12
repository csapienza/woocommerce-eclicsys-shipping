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
    });
})(jQuery);