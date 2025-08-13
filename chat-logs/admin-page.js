jQuery(document).ready(function($) {
    $('.ssc-delete-log').on('click', function() {
        if (!confirm('Are you sure you want to delete this log?')) return;

        const id = $(this).data('id');
        const row = $('#log-' + id);

        $.post(sscChatLogs.ajaxUrl, {
            action: 'ssc_delete_chat_log',
            nonce: sscChatLogs.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                row.fadeOut(300, function() { $(this).remove(); });
            } else {
                alert('Error deleting log: ' + (response.data || 'Unknown error'));
            }
        });
    });
});
