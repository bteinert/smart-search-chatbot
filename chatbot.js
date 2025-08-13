jQuery(document).ready(function($) {
    const log = $('#ssgc-chat-log');
    const input = $('#ssgc-chat-input');
    const button = $('#ssgc-chat-send');
    let isTyping = false;

    function appendMessage(sender, text, messageType = '') {
        const messageClass = messageType ? ` class="${messageType}"` : '';
        log.append(`<div${messageClass}><strong>${sender}:</strong> ${text}</div>`);
        log.scrollTop(log[0].scrollHeight);
    }

    function setTypingIndicator(show) {
        if (show && !isTyping) {
            isTyping = true;
            log.append('<div class="typing-indicator"><strong>Bot:</strong> is typing...</div>');
            log.scrollTop(log[0].scrollHeight);
        } else if (!show && isTyping) {
            isTyping = false;
            $('.typing-indicator').remove();
        }
    }

    function sendMessage() {
        const message = input.val().trim();
        if (!message) return;

        appendMessage('You', message);
        input.val('');
        input.prop('disabled', true);
        button.prop('disabled', true);
        setTypingIndicator(true);

        $.post(ssgc_ajax.ajax_url, {
            action: 'ssgc_chat',
            nonce: ssgc_ajax.nonce,
            message: message
        })
        .done(function(response) {
            if (response.success) {
                appendMessage('Bot', response.data.reply);
            } else {
                appendMessage('Bot', response.data.reply, 'error');
            }
        })
        .fail(function() {
            appendMessage('Bot', 'Request failed. Please try again.', 'error');
        })
        .always(function() {
            setTypingIndicator(false);
            input.prop('disabled', false);
            button.prop('disabled', false);
            input.focus();
        });
    }

    button.on('click', sendMessage);
    input.on('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            sendMessage();
        }
    });
});
