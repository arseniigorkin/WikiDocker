<?php

namespace VCSystem\hooks;

use MediaWiki\MediaWikiServices;

class UploadVerifyFileHook
{
    // Хук перед записью файлов
    public static function onUploadVerifyFile(&$upload, &$file, &$error) {

        global $wgRequest;

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        // Получаем значение куки
//        $tagFromCookie = $_COOKIE['vcs_brnch'] ?? null;
        $tagFromCookie = $wgRequest->getCookie('brnch', 'vcs_');

        // Если куки нет или пусто, возвращаем ошибку
        if (!$tagFromCookie) {
            $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-branch-not-selected')->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $error = $errorMessage;

            return false;
        }

        // Проверка на соответствие формату имени тега
        if (!preg_match('/^[\w-]{3,10}$/i', $tagFromCookie)) {
            $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-branch-invalid-name')->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $error = $errorMessage;

            return false;
        }

        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        // Получаем ID тега из change_tag_def
        $tagId = $dbw->selectField(
            'change_tag_def',
            'ctd_id',
            ['ctd_name' => $branchesDBPrefix.$tagFromCookie],
            __METHOD__
        );

        if (!$tagId) {
            $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-branch-not-found')->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $error = $errorMessage;

            return false;
        }

        // Проверяем, что это именно к нашей системе VCSystem относится тег
        $tagData = $dbw->selectRow(
            'vcsystem_main',
            ['vc_timestamp', 'owner_usr_id', 'isActive'],
            ['ctd_id' => $tagId, 'isActive != 0'],
            __METHOD__
        );

        if (!$tagData){
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-invalid-name' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $error = $errorMessage;

            return false;
        }

        // Если все проверки прошли успешно
        return true;
    }
}
