<?php

namespace VCSystem\hooks;

use Exception;
use LocalFile;
use MediaWiki\MediaWikiServices;
use RequestContext;
use MediaWiki\Logger\LoggerFactory;

class FileUploadHook
{
    // Добавление тегов при записи к ревизии ФАЙЛА
    public static function onFileUpload(LocalFile $file, $reupload, $hasDescription) {

        global $wgRequest;
        $context = RequestContext::getMain();
        $output = $context->getOutput();

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        // Получаем значение куки
        $tagFromCookie = $wgRequest->getCookie('brnch', 'vcs_');

        if (!$tagFromCookie) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-not-selected' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $output->addHTML($errorMessage);
            return false;
        }

        // Проверка на соответствие формату имени тега
        if (!preg_match('/^[\w-]{3,10}$/i', $tagFromCookie)) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-invalid-name' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $output->addHTML($errorMessage);
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
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-not-found' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $output->addHTML($errorMessage);
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
            $output->addHTML($errorMessage);
            return false;
        }

        // чтобы получить page_latest (rev_id) прямо в одном запросе
        $row = $dbw->selectRow(
            ['page', 'image' => 'image'],
            'page_latest',
            ['page_title' => $file->getName(), 'page_namespace' => NS_FILE],
            __METHOD__,
            [],
            [
                'image' => ['INNER JOIN', ['img_name=page_title']]
            ]
        );

        if (!$row) {
            $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-file-not-found')->text() . '</div>';
            $output->addHTML($errorMessage);
            return false;
        }

        $revisionId = $row->page_latest;

        try {

            $dbw->insert(
                'change_tag',
                [
                    'ct_rev_id' => $revisionId,
                    'ct_tag_id' => $tagId
                ],
                __METHOD__
            );

        } catch (Exception $e) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-common-error-title' )->text() . $e . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $output->addHTML($errorMessage);
            return false;
        }

        // Удаляем метки (для данной ветки) со всех других ревизий данного файла, чтобы не было путаницы.
        try {

            // Выбераем все ID ревизий для данного файла
            $relevantRevisionIds = $dbw->selectFieldValues(
                'revision',
                'rev_id',
                [ 'rev_page' => $file->getTitle()->getId() ],
                __METHOD__
            );

            // Удаляем текущую ревизию из списка ревизий по этому файлу
            $key = array_search($revisionId, $relevantRevisionIds);
            if ($key !== false) {
                unset($relevantRevisionIds[$key]);
            }

            // Если после удаления текущего revision ID ещё остались ID, то удаляем соответствующие записи из БД
            if (!empty($relevantRevisionIds)) {
                $dbw->delete(
                    'change_tag',
                    [
                        'ct_tag_id' => $tagId,
                        'ct_rev_id' => $relevantRevisionIds
                    ],
                    __METHOD__
                );
            }

        } catch (Exception $e) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-common-error-title' )->text() . $e . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $output->addHTML($errorMessage);

            return false;
        }

        return true;
    }
}
