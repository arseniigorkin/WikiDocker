$(document).ready(function() {
    // Нажатие кнопки "Слить с мастером" в спец. странице
    $('.vcs-merge-to-master').on('click', function(e) {
        // Останавливаем стандартное действие кнопки
        e.preventDefault();

        // Запрашиваем подтверждение у пользователя
        var isConfirmed = window.confirm(mw.message('vcsystem-merger-js-prompt-undon-action').text());

        // Если пользователь подтвердил действие
        if (isConfirmed) {
            var docValue = $(this).data('doc');  // Получаем значение data-doc
            window.location.href = '?mergeToMaster=' + encodeURIComponent(docValue);
        }
    });
});
