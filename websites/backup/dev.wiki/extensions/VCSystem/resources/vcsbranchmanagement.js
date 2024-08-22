$(document).ready(function() {
    // Нажатие кнопки "Деактивировать" или "Активировать" на спец. странице управления ветками
    $('.vcs-toggle-branch').on('click', function(e) {
        // Останавливаем стандартное действие кнопки
        e.preventDefault();

        // Запрашиваем подтверждение у пользователя
        var isConfirmed = window.confirm(mw.message('vcsystem-branch-management-js-prompt-action').text());

        // Если пользователь подтвердил действие
        if (isConfirmed) {
            var branchValue = $(this).data('branch');  // Получаем значение data-branch
            window.location.href = '?toggleBranch=' + encodeURIComponent(branchValue);
        }
    });
});