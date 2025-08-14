jQuery(document).ready(function($) {
    const log = $('#ssgc-chat-log');
    const input = $('#ssgc-chat-input');
    const button = $('#ssgc-chat-send');
    let isTyping = false;
    let requestInProgress = false;

    // Set max length attribute
    if (typeof ssgc_ajax !== 'undefined' && ssgc_ajax.max_length) {
        input.attr('maxlength', ssgc_ajax.max_length);
    }

    function appendMessage(sender, text, messageType = '') {
        const messageClass = messageType ? ` class="${messageType}"` : '';
        const timestamp = new Date().toLocaleTimeString();
        log.append(`<div${messageClass}><strong>${sender}:</strong> ${escapeHtml(text)} <span class="timestamp">${timestamp}</span></div>`);
        log.scrollTop(log[0].scrollHeight);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
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

    function validateMessage(message) {
        if (!message || message.trim().length === 0) {
            return 'Message cannot be empty.';
        }
        
        if (typeof ssgc_ajax !== 'undefined' && ssgc_ajax.max_length && message.length > ssgc_ajax.max_length) {
            return `Message is too long. Maximum ${ssgc_ajax.max_length} characters allowed.`;
        }
        
        // Basic client-side content filtering
        const blockedPatterns = [
            /<[^>]*>/g, // HTML tags
            /javascript:/gi,
            /vbscript:/gi,
            /on\w+\s*=/gi // Event handlers
        ];
        
        for (let pattern of blockedPatterns) {
            if (pattern.test(message)) {
                return 'Message contains invalid content.';
            }
        }
        
        return null;
    }

    function updateCharacterCount() {
        const currentLength = input.val().length;
        const maxLength = ssgc_ajax.max_length || 500;
        const remaining = maxLength - currentLength;
        
        let countElement = $('#ssgc-char-count');
        if (countElement.length === 0) {
            countElement = $('<div id="ssgc-char-count" class="char-count"></div>');
            input.after(countElement);
        }
        
        countElement.text(`${remaining} characters remaining`);
        
        if (remaining < 50) {
            countElement.addClass('warning');
        } else {
            countElement.removeClass('warning');
        }
    }

    function sendMessage() {
        if (requestInProgress) return;
        
        const message = input.val().trim();
        
        // Client-side validation
        const validationError = validateMessage(message);
        if (validationError) {
            appendMessage('System', validationError, 'error');
            return;
        }

        requestInProgress = true;
        appendMessage('You', message);
        input.val('');
        updateCharacterCount();
        input.prop('disabled', true);
        button.prop('disabled', true);
        setTypingIndicator(true);

        const requestData = {
            action: 'ssgc_chat',
            nonce: ssgc_ajax.nonce,
            message: message
        };

        $.post(ssgc_ajax.ajax_url, requestData)
        .done(function(response) {
            if (response.success) {
                appendMessage('Bot', response.data.reply);
            } else {
                const errorMessage = response.data && response.data.reply 
                    ? response.data.reply 
                    : 'An error occurred. Please try again.';
                appendMessage('Bot', errorMessage, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            let errorMessage = 'Request failed. Please try again.';
            
            if (xhr.status === 429) {
                errorMessage = 'Too many requests. Please wait before trying again.';
            } else if (xhr.status === 0) {
                errorMessage = 'Network error. Please check your connection.';
            } else if (xhr.status >= 500) {
                errorMessage = 'Server error. Please try again later.';
            }
            
            appendMessage('Bot', errorMessage, 'error');
        })
        .always(function() {
            requestInProgress = false;
            setTypingIndicator(false);
            input.prop('disabled', false);
            button.prop('disabled', false);
            input.focus();
        });
    }

    // Event handlers
    button.on('click', sendMessage);
    
    input.on('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            sendMessage();
        }
    });

    input.on('input', updateCharacterCount);

    // Initialize character count
    updateCharacterCount();
    
    // Focus input on load
    input.focus();
});
