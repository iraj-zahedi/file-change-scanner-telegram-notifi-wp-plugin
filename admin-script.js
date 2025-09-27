jQuery(document).ready(function($) {
    // Test Message Button
    $('#fcs-send-test-telegram').on('click', function() {
        var button = $(this);
        var resultSpan = $('#fcs-test-telegram-result');
        resultSpan.removeClass('success error').text('Sending...');
        button.prop('disabled', true);

        $.post(fcs_ajax.ajax_url, {
            action: 'fcs_send_test_message',
            _ajax_nonce: fcs_ajax.nonce
        })
        .done(function(response) {
            if (response.success) {
                resultSpan.addClass('success').text(response.data);
            } else {
                resultSpan.addClass('error').text('Error: ' + response.data);
            }
        })
        .fail(function() { resultSpan.addClass('error').text('Unknown request error.'); })
        .always(function() { button.prop('disabled', false); });
    });
});