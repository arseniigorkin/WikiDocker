$(document).ready(function() {

    // Создаем ToggleSwitchWidget
    var toggleSwitch = new OO.ui.ToggleSwitchWidget({
        id: 'vcseditswitch',
        value: false
    });

    // Добавляем ToggleSwitchWidget в контейнер
    $('#vcseditswitchcontainer').append(toggleSwitch.$element);

    // Устанавливаем начальное состояние чекбокса на основе куки
    var editModeCookie = getCookie('vcs_isEditMode');
    if (editModeCookie === '1') {
        toggleSwitch.setValue(true);
    } else {
        toggleSwitch.setValue(false);
    }

    toggleSwitch.on('change', function() {
        var expiresDate = new Date();
        expiresDate.setMonth(expiresDate.getMonth() + 1); // Устанавливаем срок жизни куки на 1 месяц
        var expires = "; expires=" + expiresDate.toUTCString();
        if (toggleSwitch.getValue()) {
            document.cookie = "vcs_isEditMode=1" + expires + "; path=/";
        } else {
            document.cookie = "vcs_isEditMode=0" + expires + "; path=/";
        }
        location.reload();
    });

    function getCookie(name) {
        var value = "; " + document.cookie;
        var parts = value.split("; " + name + "=");
        if (parts.length === 2) return parts.pop().split(";").shift();
    }
});
