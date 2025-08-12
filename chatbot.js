jQuery(document).ready(function($) {
    const log = $('#ssgc-chat-log');
    const input = $('#ssgc-chat-input');
    const button = $('#ssgc-chat-send');

    function appendMessage(sender, text) {
        log.append('<div><strong>' + sender + ':</strong> ' + text + '</div>');
        log.scrollTop(log[0].scrollHeight);
    }

    function sendMessage() {
        const message = input.val().trim();
        if (!message) return;
        appendMessage('You', message);
        input.val('');

        $.post(ssgc_ajax.ajax_url, {
            action: 'ssgc_chat',
            message: message
        }, function(response) {
            appendMessage('Bot', response.reply);
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
