<?php

namespace VCSystem\hooks;

use ApiMain;
use Exception;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWTimestamp;
use OldLocalFile;
use RequestContext;
use Title;
use UploadFromFile;
use VCSystem\hooks\models\UpdateRevisionForFileOnPageContentChangeModel;
use WikiPage;
use MediaWiki;

class PageSaveCompleteHook
{
    // Добавление тегов ПОСЛЕ записи к ревизии СТРАНИЦЫ
    public static function onPageSaveComplete(
        WikiPage $wikiPage,
        MediaWiki\User\UserIdentity $user,
        string $summary,
        int $flags,
        MediaWiki\Revision\RevisionRecord $revisionRecord,
        MediaWiki\Storage\EditResult $editResult
    ){

        $logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'VCSystem' );
//        $logger->info( 'This is an info message.' );

        global $wgRequest;

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        $context = RequestContext::getMain();
        $output = $context->getOutput();

        // Получаем значение куки
//        $tagFromCookie = $_COOKIE['vcs_brnch'] ?? null;
        $tagFromCookie = $wgRequest->getCookie('brnch', 'vcs_');

        // Если куки нет или пусто, просто возвращаем true
        if (!$tagFromCookie) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-not-selected' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $logger->info( 'onPageSaveComplete: не задан $tagFromCookie!' );

            $output->addHTML($errorMessage);

            return false;
        }

        // Проверка на соответствие формату имени тега
        if (!preg_match('/^[\w-]{3,10}$/i', $tagFromCookie)) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-invalid-name' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $logger->info( 'onPageSaveComplete: Формат неверный $tagFromCookie!' );

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
            $logger->info( 'onPageSaveComplete: не найден $tagId!' );

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
            $logger->info( 'onPageSaveComplete: не задана $tagData!' );

            $output->addHTML($errorMessage);

            return false;
        }


        // Если есть куки и тег найден в БД, привязываем его к только что созданной ревизии.
        // Делаем это с игнором, чтобы избежать проблем с попыткой записать пару значений, которая уже есть
        // (если другой хук раньше уже добавил их). В связи с этим используем чистый SQL тут, т.к. средствами
        // MediaWiki это невозможно (пока что) сделать.
        $revisionId = $revisionRecord->getId();

        try {
            global $wgDBprefix;
            $sql = "INSERT IGNORE INTO {$wgDBprefix}change_tag (`ct_rev_id`, `ct_tag_id`) VALUES (" .
                $dbw->addQuotes($revisionId) . ", " .
                $dbw->addQuotes($tagId) . ")";
            $dbw->query($sql, __METHOD__);

        } catch (Exception $e) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-common-error-title' )->text() . $e . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $logger->info( 'onPageSaveComplete: Исключение в catch (INSERT IGNORE INTO)!' );

            $output->addHTML($errorMessage);

            return false;
        }

        // Удаляем метки (для данной ветки) со всех других ревизий данного документа, чтобы не было путаницы.
        try {
            // Выбераем все ID ревизий для данной страницы
            $relevantRevisionIds = $dbw->selectFieldValues(
                'revision',
                'rev_id',
                [ 'rev_page' => $wikiPage->getId() ],
                __METHOD__
            );

            // Удаляем текущую ревизию из списка ревизий по этому документу
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
            $logger->info( 'onPageSaveComplete: Исключение в catch (149 строка)!' );
            $output->addHTML($errorMessage);

            return false;
        }
//        $logger = LoggerFactory::getInstance( 'VCSystem' );
//        $logger->info( 'onPageSaveComplete Arsenii' );


        /////////////////////////////
        // Проверяем, является ли редактируемая страница страницей файла
        // Если да, то это значит, что редактируется ТОЛЬКО описание файла.
        if ($wikiPage->getTitle()->getNamespace() === NS_FILE) {

            UpdateRevisionForFileOnPageContentChangeModel::setIsNeedJob(true);
            UpdateRevisionForFileOnPageContentChangeModel::setTagId($tagId);
            UpdateRevisionForFileOnPageContentChangeModel::setRevisionRecord($revisionRecord);
            UpdateRevisionForFileOnPageContentChangeModel::setWikiPage($wikiPage);
            UpdateRevisionForFileOnPageContentChangeModel::setTagData($tagData);

        }

        return true;
    }
}
