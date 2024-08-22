$(document).ready(function() {
    loadBranches();
});

function loadBranches() {

    $.post(mw.util.wikiScript('api'), {
        action: 'getbranches',
        format: 'json'
    }, function(data) {

        if (!data.getbranches) {
            showMessage('error', mw.message('vcsystem-get-branches-empty-response').text(), 'getBranchesValidatorMsg');
        } else if (!data.getbranches.isSuccess) {
            showMessage('error', data.getbranches.message, 'getBranchesValidatorMsg');
        } else {
            var comboBoxOptions = [];
            var selectedBranch = mw.cookie.get('brnch', 'vcs_') || "Master";

            data.getbranches.entities.forEach(function(branch) {
                comboBoxOptions.push({
                    data: branch.branchname,
                    label: branch.branchname
                });
            });

            // Создаём экземпляр ComboBoxInputWidget
            var comboBox = new OO.ui.ComboBoxInputWidget({
                options: comboBoxOptions,
                menu: {
                    filterFromInput: true,
                    autocomplete: true
                }
            });

            let confirmButton = $('#selectBranchBtn');
            confirmButton.on('click', function() {
                var value = comboBox.getValue();
                if (data.getbranches.entities.some(branch => branch.branchname === value)) {
                    document.cookie = "vcs_brnch=" + value + "; path=/; SameSite=Lax";
                    location.reload();
                }
            });

            // comboBox.dropdownButton.on('click', function() {
            comboBox.on('change', function(value) {
                if (data.getbranches.entities.some(branch => branch.branchname === value)) {
                    confirmButton.prop('disabled', false); // Активировать кнопку
                } else {
                    confirmButton.prop('disabled', true); // Деактивировать кнопку
                }
            });


            // Сперва очищаем список, чтобы не созадавлся ниже новый
            $('#branchSelectContainer').html('');
            // Добавляем ComboBoxInputWidget в документ
            $('#branchSelectContainer').append(comboBox.$element);
            comboBox.setValue(selectedBranch);
        }
    });
}
