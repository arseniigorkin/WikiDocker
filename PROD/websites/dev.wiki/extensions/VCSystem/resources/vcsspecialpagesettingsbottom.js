$(document).ready(function() {
    // добавляем иконку в лейбу напротив файла, который уже залит в мастер (вместо кнопки "Слить с Мастером")
    var mergedToMaster = new OO.ui.IconWidget({
        icon: 'check',
        title: mw.message('vcsystem-vcsspecialpagesettingsbottom-js-label-in-the-master-text').text(),
        classes: 'label'
    });

    $('.label-in-the-master').append(mergedToMaster.$element);

    // Добавляем иконку в кнопку родительской ветки
    var mergedToMasterParent = new OO.ui.IconWidget({
        icon: 'articleRedirect',
        classes: 'label'
    });

    $('.label-in-the-master-parent-icon').html(mergedToMasterParent.$element);

    // Добавляем иконку в кнопку master ветки
    var masterStartIcon = new OO.ui.IconWidget({
        icon: 'unStar',
        classes: 'label'
    });

    $('.label-in-the-master-icon').html(masterStartIcon.$element);

});