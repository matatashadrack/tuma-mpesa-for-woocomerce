jQuery(document).ready(function ($) {
    var checker;
    var statusTimer;

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function stopPaymentChecks() {
        if (checker) {
            clearInterval(checker);
        }
        if (statusTimer) {
            clearTimeout(statusTimer);
        }
    }

    function showConfirmedPayment(order, receipt) {
        var hasReceipt = receipt && receipt !== 'N/A';

        if (hasReceipt && !$('#tuma-receipt-overview').length) {
            $('.woocommerce-order-overview').append('<li id="tuma-receipt-overview" class="woocommerce-order-overview__payment-method method">Receipt number: <strong>' + escapeHtml(receipt) + '</strong></li>');
        }

        if (hasReceipt && !$('#tuma-receipt-table-row').length) {
            $('.woocommerce-table--order-details > tfoot')
                .find('tr:last-child')
                .prev()
                .after('<tr id="tuma-receipt-table-row"><th scope="row">Receipt number:</th><td>' + escapeHtml(receipt) + '</td></tr>');
        }

        $('#tuma_receipt').html(hasReceipt
            ? 'Payment confirmed. Receipt number: <b>' + escapeHtml(receipt) + '</b>.'
            : 'Payment confirmed successfully. The M-Pesa receipt number was not available from the status query.');

        if (!$('#tuma-pdf-receipt-download').length && $('#current_order_key').length) {
            var receiptUrl = TUMA_PDF_RECEIPT_URL
                + '?order=' + encodeURIComponent(order)
                + '&key=' + encodeURIComponent($('#current_order_key').val());
            $('#tuma_receipt').append(
                '<div style="margin-top: 12px;"><a id="tuma-pdf-receipt-download" class="button" href="'
                + receiptUrl
                + '">Download payment receipt (PDF)</a></div>'
            );
        }

        $('#renitiate-tuma-table').hide();
        $('a.button.pay, a.button.cancel, .woocommerce-button.pay, .woocommerce-button.cancel').hide();
        $("a[href*='order-pay'], a[href*='cancel_order']").hide();
        $('.order-actions, .order_actions, .woocommerce-order-actions').hide();
        stopPaymentChecks();
    }

    function checkReceipt() {
        if (!$('#payment_method').length || $('#payment_method').val() !== 'tuma_payments') {
            stopPaymentChecks();
            return;
        }

        var order = $('#current_order').val();
        if (!order) {
            return;
        }

        $.get(TUMA_RECEIPT_URL + '?order=' + encodeURIComponent(order), [], function (data) {
            if (data.confirmed_without_receipt || (data.payment_status === 'completed' && !data.receipt)) {
                showConfirmedPayment(order, '');
            } else if (data.receipt === '' || data.receipt === 'N/A') {
                $('#tuma_receipt').html('Confirming payment <span>.</span><span>.</span><span>.</span><span>.</span><span>.</span><span>.</span>');
            } else if (data.receipt === 'fail') {
                var reason = data.note && data.note.content ? data.note.content : 'Payment failed. Please try again.';
                $('#tuma_receipt').html('<b>' + escapeHtml(reason) + '</b>');
                $('#renitiate-tuma-button').prop('disabled', false);
                stopPaymentChecks();
            } else {
                showConfirmedPayment(order, data.receipt);
                if (data.user_token_instructions) {
                    $('#tuma_receipt').prepend('<div>' + data.user_token_instructions + '</div>');
                }
            }
        });
    }

    function queryManualStatus() {
        var order = $('#current_order').val();
        var key = $('#current_order_key').val();
        if (!order || !key || !window.tuma_ajax) {
            return;
        }

        $.get(tuma_ajax.check_status_url, { order: order, key: key }, function (response) {
            var result = response && response.success ? response.data : null;
            if (!result) {
                return;
            }

            if (result.status === 'completed') {
                checkReceipt();
                return;
            }

            if (result.status === 'failed' || result.status === 'cancelled') {
                $('#tuma_receipt').html('<b>' + escapeHtml(result.message || 'Payment failed. Please try again.') + '</b>');
                $('#renitiate-tuma-button').prop('disabled', false);
                stopPaymentChecks();
                return;
            }

            var retryMs = result.retry_after ? Number(result.retry_after) * 1000 : tuma_ajax.status_interval_ms;
            statusTimer = setTimeout(queryManualStatus, Math.max(1000, retryMs));
        }).fail(function () {
            statusTimer = setTimeout(queryManualStatus, tuma_ajax.status_interval_ms);
        });
    }

    function startStatusFallback(delay) {
        if (statusTimer) {
            clearTimeout(statusTimer);
        }
        statusTimer = setTimeout(queryManualStatus, delay);
    }

    // Phone number validation and formatting
    $("#tuma_phone").val($('#billing_phone').val());

    $('#billing_phone').keyup(function (e) {
        $("#tuma_phone").val($(this).val());
    });

    // Handle resend form submission
    $('#renitiate-tuma-form').submit(function (e) {
        e.preventDefault();
        $('#renitiate-tuma-button').prop('disabled', true);

        var form = $(this);

        $.post(form.attr('action'), form.serialize(), function (data) {
            if(data.success) {
                $("#tuma_receipt")
                .html('STK Resent. Confirming payment <span>.</span><span>.</span><span>.</span><span>.</span><span>.</span><span>.</span>');
                startStatusFallback(tuma_ajax.status_delay_ms);
            } else {
                $('#renitiate-tuma-button').prop('disabled', false);
                alert(data.data || 'Failed to resend payment request');
            }
         });
    });

    // Keep checking for the webhook locally, then reconcile directly after 45 seconds.
    checker = setInterval(checkReceipt, 10000);
    if ($('#current_order').length) {
        startStatusFallback(tuma_ajax.status_delay_ms);
    }

    // Phone number validation and formatting
    $(document).on('input', '#tuma_phone', function() {
        let phone = $(this).val().replace(/\D/g, '');
        
        // Format phone number as user types
        if (phone.startsWith('254')) {
            phone = phone.substring(0, 12);
        } else if (phone.startsWith('0')) {
            phone = '254' + phone.substring(1, 10);
        } else if (phone.startsWith('7') || phone.startsWith('1')) {
            phone = '254' + phone.substring(0, 9);
        }
        
        $(this).val(phone);
        
        // Validate phone number
        const isValid = /^254[71]\d{8}$/.test(phone);
        const errorDiv = $('#tuma-phone-error');
        
        if (phone.length > 0 && !isValid) {
            if (errorDiv.length === 0) {
                $(this).after('<div id="tuma-phone-error" style="color: red; font-size: 12px; margin-top: 5px;">Please enter a valid Kenyan phone number (254XXXXXXXXX)</div>');
            }
            $(this).css('border-color', '#e74c3c');
        } else {
            errorDiv.remove();
            $(this).css('border-color', '');
        }
    });
});
