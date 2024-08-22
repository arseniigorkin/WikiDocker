<?php

namespace VCSystem;

use OutputPage;
use Skin;

class VCSystemMain
{
    public static function onBeforePageDisplay( OutputPage $out, Skin $skin  ) {

        global $wgRequest;
        $out->addModules('ext.VCSystem.main');
        $out->addModules('ext.VCSystem.switch');
        $out->addModules('ext.VCSystem');

        // Проверяем - если есть запрос на очистку содержимого - очищаем.
        //Полезно для страницы с файлом в ветках, в которых этого файла нет, чтобы не выводилась белиберда.
        $pageSettings = json_decode($wgRequest->getVal('pageOutput'), true);
        if (is_array($pageSettings) &&  $pageSettings[0] === 'clearAll'){
            $out->clearHTML(); // Очищает основное содержимое страницы
            if ($pageSettings[1] != null){
                $out->addHTML($pageSettings[1]);
            }
//
        }

        // Устанавливаем кастомные http-заголовки (если есть)
        $httpHeadersSettings = json_decode($wgRequest->getVal('setHttpHeader'), true);

        if (is_array($httpHeadersSettings)){
            foreach ($httpHeadersSettings as $headerSetting) {
                if (is_array($headerSetting) && count($headerSetting) == 2) {
                    $out->getRequest()->response()->header($headerSetting[0] . ':' . $headerSetting[1]);
                }
            }
        }

        return true;
    }

    // Создаём нужную группу
    public static function onUserGetDefaultGroups(&$defaultGroups) {
        // Добавляем 'vcsadmin' к списку стандартных групп
        $defaultGroups[] = 'vcsadmin';
        return true;
    }

    public static function onMediaWikiServices() {
        global $wgGroupPermissions;

        // Устанавливаем права для группы 'vcsadmin'
        $wgGroupPermissions['vcsadmin']['vcs-merge-versions-permission'] = true;
        $wgGroupPermissions['vcsadmin']['vcs-manage-branches-permission'] = true;
        $wgGroupPermissions['vcsadmin']['read'] = true;
        $wgGroupPermissions['vcsadmin']['edit'] = true;
        $wgGroupPermissions['vcsadmin']['createpage'] = true;
        $wgGroupPermissions['vcsadmin']['applychangetags'] = true;

        return true;
    }
}