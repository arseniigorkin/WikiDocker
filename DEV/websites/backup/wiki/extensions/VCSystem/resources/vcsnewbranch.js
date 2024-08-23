$(document).ready(function() {
    let clearMessageTimer;
    let debounceTimer;

    $('#branchNameInput').on('input', function() {
        let branchName = $(this).val();

        $('#newBranchValidationMsg').text("");

        // Проверка длины
        if (branchName.length < 3 || branchName.length > 10) {
            showMessage('error', mw.message('vcsystem-new-branch-required-chars-number').text(), 'newBranchValidationMsg');
            return;
        }

        // Проверка соответствия шаблону
        if (!/^[\w-]{3,10}$/i.test(branchName)) {
            showMessage('error', mw.message('vcsystem-new-branch-allowed-chars').text(), 'newBranchValidationMsg');
            return;
        }

        // Проверка уникальности названия метки после ввода 3 символов
        if (branchName.length >= 3) {
            clearTimeout(debounceTimer); // очистка предыдущего таймера
            debounceTimer = setTimeout(function() {
                $.post(mw.util.wikiScript('api'), {
                    action: 'createbranch',
                    name: branchName,
                    actionType: 'validate',
                    format: 'json'}, function(data) {
                    if (!data.createbranch.IsSuccess) {
                        showMessage('error', data.createbranch.message, 'newBranchValidationMsg');
                    }
                });
            }, 500);
        }
    });

    $('#addBranchBtn').on('click', function() {
        let branchName = $('#branchNameInput').val();
        $.post(mw.util.wikiScript('api'), {
            action: 'createbranch',
            name: branchName,
            actionType: 'create',
            format: 'json'
        }, function (data) {
            if (!data.createbranch.IsSuccess) {
                showMessage('error', data.createbranch.message, 'newBranchValidationMsg');
            }
            else {
                $('#newBranchValidationMsg').text("");
                $('#branchNameInput').val(""); // очищаем поле
                showMessage(null, mw.message('vcsystem-new-branch-successfully-added').text(), 'newBranchValidationMsg'); // добавляем уведомление

                clearTimeout(clearMessageTimer);
                clearMessageTimer = setTimeout(function() {
                    $('#newBranchValidationMsg').text("");
                }, 2000);
                loadBranches(); // обновляем список
            }
        });
    });
});
