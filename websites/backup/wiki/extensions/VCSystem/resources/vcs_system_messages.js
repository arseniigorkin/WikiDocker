var currentMessageTimeout; // Для хранения текущего тайм-аута

function showMessage(type, message, place) {
    var $messageBox = $('#' + place);

    // Если у нас уже есть тайм-аут для сообщения, сначала очистим его
    if (currentMessageTimeout) {
        clearTimeout(currentMessageTimeout);
    }

    if (type === 'error') {
        $messageBox.removeClass('text-success').addClass('text-danger');
    } else {
        $messageBox.removeClass('text-danger').addClass('text-success');
    }

    // Если сообщение уже отображается, прячем его
    if ($messageBox.is(':visible')) {
        $messageBox.stop(true, true).hide(0, function() {
            // После скрытия предыдущего сообщения, показываем новое
            $messageBox.text(message).stop(true, true).show();

            currentMessageTimeout = setTimeout(function() {
                $messageBox.fadeOut(300);
            }, 1700);
        });
    } else {
        $messageBox.text(message).stop(true, true).show();

        currentMessageTimeout = setTimeout(function() {
            $messageBox.fadeOut(300);
        }, 2000);
    }
}
