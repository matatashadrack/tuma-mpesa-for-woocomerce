jQuery(document).ready(function ($) {
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
            } else {
                $('#renitiate-tuma-button').prop('disabled', false);
                alert(data.data || 'Failed to resend payment request');
            }
         });
    });

    // Payment status checker - exactly like original M-Pesa plugin
    var checker = setInterval(() => {
        if (!$("#payment_method").length || $("#payment_method").val() !== 'tuma_payments') {
            clearInterval(checker);
            return;
        }

        if ($("#current_order").length) {
            var order = $("#current_order").val();

            if (order.length) {
                $.get(`${TUMA_RECEIPT_URL}?order=${order}`, [], function (data) {
                    if (data.receipt == '' || data.receipt == 'N/A') {
                        $("#tuma_receipt").html('Confirming payment <span>.</span><span>.</span><span>.</span><span>.</span><span>.</span><span>.</span>');
                    } else if (data.receipt == 'fail') {
                        $("#tuma_receipt").html(`<b>${data.note?.content}</b>`);
                    } else {
                        if (!$("#tuma-receipt-overview").length) {
                            $(".woocommerce-order-overview").append(`<li id="tuma-receipt-overview" class="woocommerce-order-overview__payment-method method">Receipt number: <strong>${data.receipt}</strong></li>`);
                        }

                        if (!$("#tuma-receipt-table-row").length) {
                            $(".woocommerce-table--order-details > tfoot")
                                .find('tr:last-child')
                                .prev()
                                .after(`<tr id="tuma-receipt-table-row"><th scope="row">Receipt number:</th><td>${data.receipt}</td></tr>`);
                        }
                        
                        $("#tuma_receipt").html(`Payment confirmed. Receipt number: <b>${data.receipt}</b>.`);

                        if(data.user_token_instructions) {
                          $("#tuma_receipt").html(data.user_token_instructions);
                        }

                        $("#renitiate-tuma-table").hide();

                        clearInterval(checker);

                        // Don't redirect, just stop polling
                        return false;
                    }
                });
            }
        }
    }, 10000); // Check every 10 seconds like original

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
