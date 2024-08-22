<?php
namespace VCSystem;

use Exception;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use Title;
use MediaWiki\Logger\LoggerFactory;

class SpecialVCSystemSettings extends SpecialPage {
    public function __construct() {
        // Название служебной страницы
        parent::__construct('VCSystemSettings');
    }

    private function getLoadBalancer()
    {
        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);
        $prefix = $dbw->tablePrefix();

        return [$dbw, $prefix];
    }

    private function getTagFromCookies(&$out, &$wgRequest)
    {
        $tagFromCookie = $wgRequest->getCookie('brnch', 'vcs_');

        if ($tagFromCookie === 'Master'){
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-specialpage-settings-request-on-master' )->text() . '</div>';
            $out->addHTML($errorMessage);
            return false;
        }

        // Если куки нет или пусто, просто возвраем true
        if (!$tagFromCookie) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-not-selected' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $out->addHTML($errorMessage);
            return false;
        }

        // Проверка на соответствие формату имени тега
        if (!preg_match('/^[\w-]{3,10}$/i', $tagFromCookie)) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-invalid-name' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);

            $out->addHTML($errorMessage);
            return false;
        }

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );


        $loadBalancerResult = self::getLoadBalancer();
        if($loadBalancerResult === false){
            return false;
        }

        [&$dbw, &$prefix] = $loadBalancerResult;

        $tagId = $dbw->selectField(
            'change_tag_def',
            'ctd_id',
            ['ctd_name' => $branchesDBPrefix.$tagFromCookie],
            __METHOD__
        );

        if (!$tagId) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-not-found' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);

            $out->addHTML($errorMessage);
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

            $out->addHTML($errorMessage);
            return false;
        }

        return [
            'tagFromCookie' => $branchesDBPrefix.$tagFromCookie,
            'dbw' => $dbw,
            'dbprefix' => $prefix,
            'tagId' => $tagId,
            'tagData' => $tagData
        ];
    }

    private function filesMerge(&$dbw, &$out, &$wgRequest, &$revData,&$pageData, &$revIdFromGET, &$masterTagId)
    {
        $imageData = $dbw->selectRow(
            'image',
            '*',
            ['img_timestamp' => $dbw->timestamp($revData->rev_timestamp)],
            __METHOD__
        );
        $fromOldImage = false;
        if (!$imageData) {
            $imageData = $dbw->selectRow(
                'oldimage',
                '*',
                ['oi_timestamp' => $dbw->timestamp($revData->rev_timestamp)],
                __METHOD__
            );
            $fromOldImage = true;
        }
        // Если была изменена только страница файла (а не сам файл) - нужно обновлять именно страницу.
        if (!$imageData) {
            $resultOtherDocsMerge = self::otherDocsMerge($dbw, $out, $revIdFromGET, $masterTagId, $pageData, $wgRequest);
            if($resultOtherDocsMerge === false){
                return false;
            }
            // Перебрасываем снова на страницу настроек, но без query string
            $resultRedirectToOrigin = self::redirectToOrigin($this);
            if($resultRedirectToOrigin === false){
                return false;
            }
            return false;
        }

        // Актуализация файла
        $file = null;
        if ($fromOldImage) {

            // Получить путь из oldimage
            $title = Title::newFromText($imageData->oi_name, NS_FILE);
            $repoGroup = MediaWikiServices::getInstance()->getRepoGroup();
            $file = $repoGroup->getLocalRepo()->newFromArchiveName($title, $imageData->oi_archive_name);

            $localFilePath = $file->getArchivePath($imageData->oi_archive_name);

        } else {

            // Получить путь для текущего файла
            $title = Title::newFromText($imageData->img_name, NS_FILE);
            $repoGroup = MediaWikiServices::getInstance()->getRepoGroup();
            $file = $repoGroup->getLocalRepo()->newFile($title);

            $localFilePath = $file->getPath();
        }

        $globalUploadDir = $GLOBALS['wgUploadDirectory'];
        $prefixToRemove = 'mwstore://local-backend/local-public/';
        $cleanedLocalFilePath = str_replace($prefixToRemove, '', $localFilePath);

        $newFilePath = preg_replace('/^\d{14}!/', '', $cleanedLocalFilePath);
        $latestFilePath = dirname($globalUploadDir . '/' . $newFilePath);


        if ($fromOldImage) {

            // Собираем информацию о текущей ревизии (той, что самая свежая в системе) файла из image таблицы
            $currentImageRevData = $dbw->selectRow(
                'image',
                '*',
                ['img_name' => $pageData->page_title],
                __METHOD__
            );

            // Пути для исходного файла и целевого. $archivedVersionPath - это файл, который сейчас в архиве
            // $cleanedLocalFilePath - это имя файла, в который нужно переименовать (с перемещением)
            // наш текущий последний файл (последняя ревизия не из архива)
            // $latestRevFilePath - это файл, который мы должны заменить файлом из $archivedVersionPath
            $archivedVersionPath = $latestFilePath . '/' . $revData->rev_timestamp . '!' . $pageData->page_title;
            $newArchivedVersionPath = $latestFilePath . '/' . $currentImageRevData->img_timestamp . '!' . $pageData->page_title;
            $latestRevFilePath = preg_replace('/(archive\/)/i', '', $latestFilePath) . '/' . $pageData->page_title;

            // Копируем файл из $latestRevFilePath в $newArchivedVersionPath
            if (!copy($latestRevFilePath, $newArchivedVersionPath)) {
                $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-specialpage-merge-to-master-error-on-copy-file')->text() . '</div>';
                $out->addHTML($errorMessage);
                return false;
            }

            // Теперь перемещаем (перезаписываем) файл из $archivedVersionPath в $latestRevFilePath
            if (!rename($archivedVersionPath, $latestRevFilePath)) {
                $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-specialpage-merge-to-master-error-on-move-file')->text() . '</div>';
                $out->addHTML($errorMessage);
                return false;
            }

            // Регистрируем в БД изменения
            $newArchivedFileName = $currentImageRevData->img_timestamp . '!' . $imageData->img_name;

            // получаем timestamp для новой записи в архиве (она берётся из имени предыдущей, т.е. той, которую мы сейчас переносим в image)
            $newArchiveTimeStamp = null;
            if (preg_match('/^(\d{14})!/', $imageData->oi_archive_name, $matches)) {
                $newArchiveTimeStamp = $matches[1];
            }

            $dbw->insert(
                'oldimage',
                [
                    'oi_name' => $currentImageRevData->img_name,
                    'oi_archive_name' => $newArchivedFileName,
                    'oi_timestamp' => $newArchiveTimeStamp,
                    'oi_size' => $currentImageRevData->img_size,
                    'oi_width' => $currentImageRevData->img_width,
                    'oi_height' => $currentImageRevData->img_height,
                    'oi_metadata' => $currentImageRevData->img_metadata,
                    'oi_bits' => $currentImageRevData->img_bits,
                    'oi_media_type' => $currentImageRevData->img_media_type,
                    'oi_major_mime' => $currentImageRevData->img_major_mime,
                    'oi_minor_mime' => $currentImageRevData->img_minor_mime,
                    'oi_description_id' => $currentImageRevData->img_description_id,
                    'oi_actor' => $currentImageRevData->img_actor,
                    'oi_sha1' => $currentImageRevData->img_sha1,
                    'oi_deleted' => 0
                ],
                __METHOD__
            );

            $dbw->update(
                'image',
                [
                    'img_timestamp' => $revData->rev_timestamp,
                    'img_name' => $imageData->oi_name,
                    'img_size' => $imageData->oi_size,
                    'img_width' => $imageData->oi_width,
                    'img_height' => $imageData->oi_height,
                    'img_metadata' => $imageData->oi_metadata,
                    'img_bits' => $imageData->oi_bits,
                    'img_media_type' => $imageData->oi_media_type,
                    'img_major_mime' => $imageData->oi_major_mime,
                    'img_minor_mime' => $imageData->oi_minor_mime,
                    'img_description_id' => $imageData->oi_description_id,
                    'img_actor' => $imageData->oi_actor,
                    'img_sha1' => $imageData->oi_sha1
                ],
                [ 'img_name' => $currentImageRevData->img_name ], // условие для выбора соответствующей записи
                __METHOD__
            );

            // связываем ревизию с мастером (по тегу)
            $result = $dbw->insert(
                'change_tag',
                [
                    'ct_rev_id' => $revIdFromGET,
                    'ct_tag_id' => $masterTagId
                ],
                __METHOD__
            );

            if (!$result) {
                $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-specialpage-merge-to-master-db-error-on-insert-into-change_tag')->text() . '</div>';
                $out->addHTML($errorMessage);
                return false;
            }
        }

        else { // если файл и так является самой последней версией (в таблице image) - нужно только добавить тег ему

            // связываем ревизию с мастером (по тегу)
            $result = $dbw->insert(
                'change_tag',
                [
                    'ct_rev_id' => $revIdFromGET,
                    'ct_tag_id' => $masterTagId
                ],
                __METHOD__
            );

            if (!$result) {
                $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-specialpage-merge-to-master-db-error-on-insert-into-change_tag')->text() . '</div>';
                $out->addHTML($errorMessage);
                return false;
            }

        }

        // Удаляем метки (для данной ветки) со всех других ревизий данного файла, чтобы не было путаницы.
        try {

            // Выбераем все ID ревизий для данного файла для Master
            $relevantRevisionIds = $dbw->selectFieldValues(
                'revision',
                'rev_id',
                [ 'rev_page' => $file->getTitle()->getId() ],
                __METHOD__
            );

            // Удаляем текущую ревизию из списка ревизий по этому файлу
            $key = array_search($revIdFromGET, $relevantRevisionIds);
            if ($key !== false) {
                unset($relevantRevisionIds[$key]);
            }

            // Если после удаления Master revision ID ещё остались ID, то удаляем соответствующие записи из БД
            if (!empty($relevantRevisionIds)) {
                $dbw->delete(
                    'change_tag',
                    [
                        'ct_tag_id' => $masterTagId,
                        'ct_rev_id' => $relevantRevisionIds
                    ],
                    __METHOD__
                );
            }

        } catch (Exception $e) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-common-error-title' )->text() . $e . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $out->addHTML($errorMessage);

            return false;
        }
    }

    private function mergerToMaster(&$out, &$revIdFromGET, &$wgRequest)
    {
        if (!preg_match('/^\d{1,10}$/i', $revIdFromGET)) {

            $out->showErrorPage('vcsystem-specialpage-settings-merge-to-master-invalidparam-title', 'vcsystem-specialpage-settings-merge-to-master-document-incorrect-or-empty');
            return false;
        }

        $user = $this->getUser();
        if (!$user->isAllowed('vcs-merge-versions-permission')) {  // проверяем разрешение
            $out->showErrorPage('vcsystem-specialpage-settings-merge-to-master-permissionerror-title', 'vcsystem-specialpage-settings-merge-to-master-nopermissiontoexecute');
            return false;
        }

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        $revData = $dbw->selectRow(
            'revision',
            ['rev_timestamp', 'rev_page'],
            ['rev_id' => $revIdFromGET],
            __METHOD__
        );

        if (!$revData) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-revision-not-found' )->text() . '</div>';
            $out->addHTML($errorMessage);
            return false;
        }

        $tagName = 'Master';
        $masterTagId = $dbw->selectField(
            'change_tag_def',
            'ctd_id',
            [ 'ctd_name' => $branchesDBPrefix.$tagName ], // условие по ctd_name, без учета регистра
            __METHOD__
        );

        if ($masterTagId == null) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-specialpage-merge-to-master-cannot-find-master-tag' )->text() . '</div>';
            $out->addHTML($errorMessage);
            return false;
        }

        $revTagRecord = $dbw->selectRow(
            'change_tag',
            'ct_id',
            [
                'ct_rev_id' => $revIdFromGET,
                'ct_tag_id' => $masterTagId
            ],
            __METHOD__
        );

        // Если нашли мастер - просто завершаем и говорим, что уже есть в Мастере
        if ($revTagRecord) {
            $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-specialpage-merge-to-master-already-in-master')->text() . '</div>';
            $out->addHTML($errorMessage);
            return false;
        }

        $pageData = $dbw->selectRow(
            'page',
            ['*'],
            ['page_id' => $revData->rev_page],
            __METHOD__
        );

        if (!$pageData) {
            $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-specialpage-merge-to-master-page-not-found')->text() . '</div>';
            $out->addHTML($errorMessage);
            return false;
        }

        // Если файл
        if ($pageData->page_namespace == NS_FILE) {

            $resultFilesMerge = self::filesMerge($dbw, $out, $wgRequest, $revData,$pageData, $revIdFromGET, $masterTagId);
            if($resultFilesMerge === false){
                return false;
            }

        } else { // для всех остальных типов документа - просто связываем с мастером по тегу

            $resultOtherDocsMerge = self::otherDocsMerge($dbw, $out, $revIdFromGET, $masterTagId, $pageData, $wgRequest);
            if($resultOtherDocsMerge === false){
                return false;
            }
        }

        // Перебрасываем снова на страницу настроек, но без query string
        $resultRedirectToOrigin = self::redirectToOrigin($this);
        if($resultRedirectToOrigin === false){
            return false;
        }
        return false;
    }

    private function redirectToOrigin(&$thisParam)
    {
        // Перебрасываем снова на страницу настроек, но без query string
            $thisSpecialPageTitle = $thisParam->getPageTitle();
            $url = $thisSpecialPageTitle->getFullURL();

            $out = $thisParam->getOutput();
            $out->redirect($url);
    }

    public function otherDocsMerge(&$dbw, &$out, &$revIdFromGET, &$masterTagId, &$pageData, &$wgRequest){
        // связываем ревизию с мастером (по тегу)
        $result = $dbw->insert(
            'change_tag',
            [
                'ct_rev_id' => $revIdFromGET,
                'ct_tag_id' => $masterTagId
            ],
            __METHOD__
        );

        if (!$result) {
            $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-specialpage-merge-to-master-db-error-on-insert-into-change_tag')->text() . '</div>';
            $out->addHTML($errorMessage);
            return false;
        }

        try {

            // Выбераем все ID ревизий для данного документа для Master
            $relevantRevisionIds = $dbw->selectFieldValues(
                'revision',
                'rev_id',
                [ 'rev_page' => $pageData->page_id ],
                __METHOD__
            );

            // Удаляем текущую ревизию из списка ревизий по этому документу
            $key = array_search($revIdFromGET, $relevantRevisionIds);
            if ($key !== false) {
                unset($relevantRevisionIds[$key]);
            }

            // Если после удаления Master revision ID ещё остались ID, то удаляем соответствующие записи из БД
            if (!empty($relevantRevisionIds)) {
                $dbw->delete(
                    'change_tag',
                    [
                        'ct_tag_id' => $masterTagId,
                        'ct_rev_id' => $relevantRevisionIds
                    ],
                    __METHOD__
                );
            }

        } catch (Exception $e) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-common-error-title' )->text() . $e . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $out->addHTML($errorMessage);

            return false;
        }
    }

    public function execute($subPage) {

        $logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'VCSystem' );
//        $logger->info( 'This is an info message.' );

        $out = $this->getOutput();
        $out->setPageTitle($this->msg('vcsystem-specialpage-settings-moniker'));
        $out->addModules('ext.VCSystem.vcsmerger');
        $out->addModules('ext.VCSystem.vcsspecialpagesettingsbottom'); // загрузится внизу

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        global $wgRequest;

        // Начало вывода
        $out = $this->getOutput();
        $this->setHeaders();

        $revIdFromGET = $wgRequest->getInt('mergeToMaster'); // Вернет 0, если параметр отсутствует

        // слияние с мастером
        if ($revIdFromGET != null) {
            $resultMergeToMaster = self::mergerToMaster($out, $revIdFromGET, $wgRequest);
            if($resultMergeToMaster === false){
                return false;
            }
        }

        // Обычная загразка (не слияние)
        $resultGetTagFromCookies = self::getTagFromCookies($out, $wgRequest);

        if($resultGetTagFromCookies === false){
            return false;
        }

        $tagFromCookie = &$resultGetTagFromCookies['tagFromCookie'];
        $dbw = &$resultGetTagFromCookies['dbw'];
        $prefix = &$resultGetTagFromCookies['dbprefix'];
        $tagId = &$resultGetTagFromCookies['tagId'];
        $tagData = &$resultGetTagFromCookies['tagData'];

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        // получаем ID мастер-ветки
        $tagName = 'Master';
        $masterTagId = $dbw->selectField(
            'change_tag_def',
            'ctd_id',
            [ 'ctd_name' => $branchesDBPrefix.$tagName ],
            __METHOD__
        );

        if ($masterTagId == null) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-specialpage-merge-to-master-cannot-find-master-tag' )->text() . '</div>';
            $out->addHTML($errorMessage);
            return false;
        }

        // собираем все записи по page таблице (и для файлов и для доков одна) по данной ветке (тегу)
        $pagesRes = $dbw->select(
            [
                'p' => 'page',
                'rev' => 'revision',
                'ct' => 'change_tag'
            ],
            [
                'p.page_id',
                'p.page_title',
                'p.page_namespace',
                'rev.rev_id',
                'rev.rev_timestamp'
            ],
            [
                'ct.ct_tag_id' => $tagId,
                'rev.rev_deleted' => 0
            ],
            __METHOD__,
            [
                'GROUP BY' => 'p.page_id',
                'ORDER BY' => 'rev.rev_timestamp DESC'
            ],
            [
                'rev' => ['JOIN', 'p.page_id = rev.rev_page'],
                'ct' => ['JOIN', 'ct.ct_rev_id = rev.rev_id']
            ]
        );

        // Формируем словарь с этими записями по ключу page_id. Обращаться так: $pagesInThisBranchDict[123]->rev_id
        $pagesInThisBranchDict = [];

        foreach ($pagesRes as $row) {
            if (!isset($pagesInThisBranchDict[$row->page_id]) || $row->rev_timestamp > $pagesInThisBranchDict[$row->page_id]->rev_timestamp) {
                $pagesInThisBranchDict[$row->page_id] = $row;
            }
        }

        if(empty($pagesInThisBranchDict)){
            $outpuMessage = wfMessage( 'vcsystem-specialpage-empty-history-for-branch' )->text();
            $out->addHTML($outpuMessage);
            return false;
        }

        // тут бубут храниться только page_id в сиде списка
        $pageIds = array_keys($pagesInThisBranchDict);

        // собираем все последние ревизии из master для тех же документов
        $masterRevisions = $dbw->select(
            [
                'ct' => 'change_tag',
                'rev' => 'revision',
                'p' => 'page'
            ],
            ['rev.rev_id', 'rev.rev_timestamp', 'rev.rev_page'],
            [
                'ct.ct_tag_id' => $masterTagId,
                'rev.rev_deleted' => 0,
                'p.page_id' => $pageIds
            ],
            __METHOD__,
            [
                'ORDER BY' => 'rev.rev_timestamp DESC',
                'GROUP BY' => 'p.page_id'
            ],
            [
                'rev' => ['JOIN', ['ct.ct_rev_id=rev.rev_id']],
                'p' => ['JOIN', ['rev.rev_page=p.page_id']]
            ]
        );

        $foundPageIds = [];
        foreach ($masterRevisions as $row) {
            $foundPageIds[] = $row->rev_page;
        }

        $notFoundPageIds = array_diff($pageIds, $foundPageIds);

        // Получаем пары ID и имя всех тегов из vcsystem_main таблицы.
        $res_vc_all_tags = $dbw->select(
            [ 'vcs' => 'vcsystem_main', 'ctd' => 'change_tag_def' ],
            [ 'vcs.ctd_id', 'ctd.ctd_name', 'vcs.isActive', 'vc_timestamp' ],
            [],
            __METHOD__,
            [],
            [
                'ctd' => [ 'INNER JOIN', 'vcs.ctd_id = ctd.ctd_id' ]
            ]
        );

        $vc_all_tags = [];
        foreach ( $res_vc_all_tags as $row ) {
            $vc_all_tags[$row->ctd_id] = ['name' => $row->ctd_name, 'isActive' => $row->isActive, 'vc_timestamp' => $row->vc_timestamp];
        }

        if (empty($vc_all_tags)) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-could-not-read-from-db' )->text() . 'vcsystem_main</div>';
            $out->addHTML($errorMessage);
            return false;
        }

        $masterRevisionRows = null;
        if ($notFoundPageIds) {

            // если какие-то документы не представлены по ветке master -
            // выбираем ревизии по ним самые последние перед созданием нашей ветки
            $masterRevisionRows = $dbw->select(
                ['rv' => 'revision', 'ct' => 'change_tag'],
                [
                    'max_rev_id' => 'MAX(rev_id)',
                    'max_rev_timestamp' => 'MAX(rev_timestamp)',
                    'rev_page'
                ],
                [
                    'rev_page' => $notFoundPageIds,
                    'rev_timestamp < ' . $dbw->addQuotes($tagData->vc_timestamp),
                    'ct.ct_tag_id NOT IN (' . $dbw->makeList(array_keys($vc_all_tags)) . ') OR ct.ct_tag_id IS NULL'
                ],
                __METHOD__,
                [
                    'ORDER BY' => ['rev_page', 'max_rev_timestamp DESC'],
                    'GROUP BY' => 'rev_page',
                ],
                [
                    'ct' => ['LEFT JOIN', ['ct.ct_rev_id=rv.rev_id']]
                ]
            );
        }

        $masterRevisionMap = [];
        foreach ($masterRevisions as $revision) {
            $masterRevisionMap[$revision->rev_page] = $revision->rev_id;
        }

        foreach ($masterRevisionRows as $revision) {
            $masterRevisionMap[$revision->rev_page] = $revision->max_rev_id;
        }

        // разделяем на файлы и страницы
        $pages = [];
        $files = [];

        foreach ($pagesInThisBranchDict as $pageData) {
            if ($pageData->page_namespace == NS_FILE) {
                $files[] = $pageData;
            } else {
                $pages[] = $pageData;
            }
        }

        $out->addHTML('<h2>' . wfMessage('vcsystem-specialpage-settings-files-table-title')->text() . '</h2>');
        $out->addHTML('<table class="table-striped" style="width:100%;">');
        $out->addHTML('<thead>');
        $out->addHTML('<tr>');
        $out->addHTML('<th style="width:79%; min-width:500px;">' . wfMessage('vcsystem-specialpage-settings-files-table-column-title')->text() . '</th>');
        $out->addHTML('<th style="width:21%; min-width:450px; text-align: center;" colspan="3">' . wfMessage('vcsystem-specialpage-settings-files-table-column-actions')->text() . '</th>');
        $out->addHTML('</tr>');
        $out->addHTML('</thead>');
        $out->addHTML('<tbody>');

        foreach ($files as $file) {

            $fileTitleObj = \Title::newFromText($file->page_title, NS_FILE);
            $viewURL = $fileTitleObj->getFullURL(['oldid' => $file->rev_id]);

            $fileTitle = htmlspecialchars($file->page_title); // Экранируем заголовок
            $revisionDate = wfTimestamp(TS_RFC2822, $file->rev_timestamp); // Преобразуем timestamp

            $diffButton = '<button class="btn disabled">'.wfMessage('vcsystem-specialpage-settings-pages-table-diff-button-label-no-diff')->text().'</button>';
            $titleObj = \Title::newFromText($file->page_title, NS_FILE);

            if (isset($masterRevisionMap[$file->page_id])) {
                // Находим последнюю ревизию с мастером перед созданием ветки (для diff с родительской)
                $parentRevIdWithMaster = null;
                $parrentRevId = null;
                $parentRevIdWithMaster = $dbw->selectField(
                    [
                        'r' => 'revision',
                        'ct' => 'change_tag'
                    ],
                    ['r.rev_id'],
                    [
                        'r.rev_page' => $file->page_id,
                        'r.rev_timestamp < ' . $dbw->addQuotes($tagData->vc_timestamp),
                        'ct.ct_tag_id' => $masterTagId,
                        'r.rev_id = ct.ct_rev_id' // JOIN условие
                    ],
                    __METHOD__,
                    [
                        'LIMIT' => 1,
                        'ORDER BY' => 'r.rev_timestamp DESC',  // Добавлено, чтобы получить самую последнюю ревизию перед указанной датой
                        'INNER JOIN' => [
                            ['ct', 'r.rev_id = ct.ct_rev_id'] // JOIN с change_tag
                        ]
                    ]
                );

                // Если с Мастером не нашли - ищем просто последнюю ревизию перед созданием ветки
                if (!$parentRevIdWithMaster){
                    $parrentRevId = $dbw->selectField(
                        'revision',
                        'rev_id',
                        [
                            'rev_page' => $file->page_id,
                            'rev_timestamp < ' . $dbw->addQuotes($tagData->vc_timestamp),
                        ],
                        __METHOD__,
                        [
                            'LIMIT' => 1,
                            'ORDER BY' => 'rev_timestamp DESC',
                        ]
                    );
                }
                if ($parrentRevId || $parentRevIdWithMaster) {
                    $diffURL = $titleObj->getFullURL(['diff' => $file->rev_id, 'oldid' => $parrentRevId ?? $parentRevIdWithMaster]);
                    $diffButton = '<button class="btn btn-dark" onclick="window.open(\'' . $diffURL . '\', \'_blank\')">' . wfMessage('vcsystem-specialpage-settings-pages-table-diff-button-label')->text() . '</button>';
                }
            }

// Проверяем - есть ли эта ревизия уже в Мастере?
            $revTagRecord = $dbw->selectRow(
                'change_tag',
                'ct_id',
                [
                    'ct_rev_id' => $file->rev_id,
                    'ct_tag_id' => $masterTagId
                ],
                __METHOD__
            );

// Проверяем - Если в мастере, то какая ветка была последней слита в этом документе с мастером?
            $beforeMasterBranchName = '<button class="btn btn-light disabled">' . wfMessage('vcsystem-specialpage-settings-pages-table-to-master-branch-before-the-master-not-found')->text() . '</button>';
            if (!$revTagRecord) {
                // Получаем rev_id мастера в этом документе, чтобы дальше по этой ревизии искать -
                // из какой ветки было последнее слияние с Мастером
                $masterRev = $dbw->selectRow(
                    [
                        'r' => 'revision',
                        'ct' => 'change_tag'
                    ],
                    ['r.rev_id', 'r.rev_timestamp'],
                    [
                        'r.rev_page' => $file->page_id,
                        'ct.ct_tag_id' => $masterTagId,
                        'r.rev_id = ct.ct_rev_id' // JOIN условие
                    ],
                    __METHOD__,
                    [
                        'LIMIT' => 1,
                        'INNER JOIN' => [
                            ['ct', 'r.rev_id = ct.ct_rev_id'] // JOIN с change_tag
                        ]
                    ]
                );
                if (!empty($masterRev)) {
                    // Если нашли ревизию с мастером, ищем в ней тег, который стоит (помимо мастера) -
                    // это и будет та ветка, которая была слита с Мастером
                    $revIdResult = $dbw->selectField(
                        'change_tag',
                        'ct_tag_id',
                        [
                            'ct_rev_id' => $masterRev->rev_id,
                            'ct_tag_id IN ('. $dbw->makeList(array_keys(array_filter($vc_all_tags, function($item) use ($branchesDBPrefix) {
                                return $item['name'] !== $branchesDBPrefix."Master";
                            }))). ')'
                        ],
                        __METHOD__
                    );

                    if ($revIdResult) {
                        $parentDiffURL = $titleObj->getFullURL(['diff' => $file->rev_id, 'oldid' => $masterRev->rev_id]);
                        $parrentBtnClass = "success";
                        if ($vc_all_tags[$revIdResult]['isActive'] != 1){
                            $parrentBtnClass = "secondary";
                        }

                        $buttonLabel = "";
                        if (strpos($vc_all_tags[$revIdResult]['name'], $branchesDBPrefix) === 0) {
                            $buttonLabel = substr($vc_all_tags[$revIdResult]['name'], strlen($branchesDBPrefix));
                        }

                        $beforeMasterBranchName = '<button class="btn btn-' . $parrentBtnClass . ' vcs-merge-to-master-parent" onclick="window.open(\'' . $parentDiffURL . '\', \'_blank\')"><span class="label-in-the-master-parent-icon" style="filter: invert(100%);"></span>&nbsp;' . $buttonLabel . '</button>';
                    }

                    else{
                        // Если не нашли запись с тегом, помимо Мастера - используем сам Мастер (видимо, прямо в нём
                        // были сделаны изменения)
                        $parentDiffURL = $titleObj->getFullURL(['diff' => $file->rev_id, 'oldid' => $masterRev->rev_id]);
                        $parrentBtnClass = "success";
                        $beforeMasterBranchName = '<button class="btn btn-' . $parrentBtnClass . ' vcs-merge-to-master-parent" onclick="window.open(\'' . $parentDiffURL . '\', \'_blank\')"><span class="label-in-the-master-icon" style="filter: invert(100%);"></span>&nbsp;' . wfMessage('vcsystem-master-branch-name')->text(). '</button>';
                    }
                }
                else{
                    // Получаем самую последнюю ревизию по данному документу, которая не связана ни с одним из тегов
                    // VCSystem
                    $latestActualRev = $dbw->selectRow(
                        [
                            'r' => 'revision',
                            'ct' => 'change_tag'
                        ],
                        ['r.rev_id', 'r.rev_timestamp'],
                        [
                            'r.rev_page' => $file->page_id,
                            'ct.ct_tag_id NOT IN (' . $dbw->makeList(array_keys($vc_all_tags)) . ') OR ct.ct_tag_id IS NULL',
                        ],
                        __METHOD__,
                        [
                            'LIMIT' => 1,
                            'ORDER BY' => 'r.rev_timestamp DESC',
                            'JOIN' => [
                                'TABLE' => 'ct',
                                'CONDITION' => 'r.rev_id = ct.ct_rev_id'
                            ]
                        ]
                    );

                    if (!empty($latestActualRev)){
                        // Сравниваем timestamps нашей ветки и последней ревизии в документе
                        if($latestActualRev->rev_timestamp > $vc_all_tags[$tagId]['vc_timestamp'] && $latestActualRev->rev_id != $file->rev_id){
                            $parentDiffURL = $titleObj->getFullURL(['diff' => $file->rev_id, 'oldid' => $latestActualRev->rev_id]);
                            $parrentBtnClass = "light-sea-wave";
                            $beforeMasterBranchName = '<button class="btn btn-' . $parrentBtnClass . ' vcs-merge-to-master-parent" onclick="window.open(\'' . $parentDiffURL . '\', \'_blank\')"><span class="label-in-the-master-parent-icon" style="filter: invert(100%);"></span>&nbsp;' . wfMessage('vcsystem-unknown-branch-name')->text(). '</button>';
                        }
                    }
                }
            }

            $mergeToMasterButton = '';
            $tdBgColor = '';
            // Если нашли уже слитую в Мастер - убираем кнопку слияния и
            if ($revTagRecord) {
                $mergeToMasterButton = '<h4 class="label" style="color: var(--bs-success);"><span class="label-in-the-master"></span> &nbsp;'.wfMessage('vcsystem-specialpage-settings-pages-table-merge-file-in-master-label')->text().'</h4>';
                $tdBgColor = 'background-color: honeydew;';
            }
            else {
                $mergeToMasterButton = '<button class="btn btn-warning vcs-merge-to-master" data-doc="'. $file->rev_id .'">'.wfMessage('vcsystem-specialpage-settings-pages-table-to-master-button-label')->text().'</button>';
            }


            $out->addHTML('<tr>');
            $out->addHTML('<td><a href="'.$viewURL.'" title="'.wfMessage('vcsystem-specialpage-settings-pages-table-view-link-title')->text().'" target="_blank">' . $fileTitle . '</a> <code>(' . $revisionDate . ')</code></td>');
            $out->addHTML('<td style="text-align: center; width:7%; min-width:225px;">');
            $out->addHTML($diffButton);
            $out->addHTML('<td style="text-align: center; width:7%; min-width:190px;' . $tdBgColor . '">');
            $out->addHTML($mergeToMasterButton);
            $out->addHTML('</td>');
            $out->addHTML('<td style="text-align: center; width:7%; min-width:190px;">');
            $out->addHTML($beforeMasterBranchName);
            $out->addHTML('</td>');
            $out->addHTML('</tr>');
        }

        $out->addHTML('</tbody>');
        $out->addHTML('</table>');

        $out->addHTML('<h2>' . wfMessage('vcsystem-specialpage-settings-pages-table-title')->text() . '</h2>');
        $out->addHTML('<table class="table-striped" style="width:100%;">');
        $out->addHTML('<thead>');
        $out->addHTML('<tr>');
        $out->addHTML('<th style="width:79%; min-width:500px;">' . wfMessage('vcsystem-specialpage-settings-pages-table-column-title')->text() . '</th>');
        $out->addHTML('<th style="width:21%; min-width:450px; text-align: center;" colspan="3">' . wfMessage('vcsystem-specialpage-settings-pages-table-column-actions')->text() . '</th>');
        $out->addHTML('</tr>');
        $out->addHTML('</thead>');
        $out->addHTML('<tbody>');

        foreach ($pages as $page) {
            // Для страниц

            $titleObj = \Title::newFromText($page->page_title, $page->page_namespace);
            $viewURL = $titleObj->getFullURL(['oldid' => $page->rev_id]);

            $pageTitle = htmlspecialchars($page->page_title); // На всякий случай экранируем заголовок
            $revisionDate = wfTimestamp(TS_RFC2822, $page->rev_timestamp); // Преобразуем timestamp в читаемый формат

            $diffButton = '<button class="btn disabled">'.wfMessage('vcsystem-specialpage-settings-pages-table-diff-button-label-no-diff')->text().'</button>';
            $titleObj = \Title::newFromText($page->page_title, $page->page_namespace);

            if (isset($masterRevisionMap[$page->page_id])) {
                // Находим последнюю ревизию с мастером перед созданием ветки (для diff с родительской)
                $parentRevIdWithMaster = null;
                $parrentRevId = null;
                    $parentRevIdWithMaster = $dbw->selectField(
                    [
                        'r' => 'revision',
                        'ct' => 'change_tag'
                    ],
                    ['r.rev_id'],
                    [
                        'r.rev_page' => $page->page_id,
                        'r.rev_timestamp < ' . $dbw->addQuotes($tagData->vc_timestamp),
                        'ct.ct_tag_id' => $masterTagId,
                        'r.rev_id = ct.ct_rev_id' // JOIN условие
                    ],
                    __METHOD__,
                    [
                        'LIMIT' => 1,
                        'ORDER BY' => 'r.rev_timestamp DESC',  // Добавлено, чтобы получить самую последнюю ревизию перед указанной датой
                        'INNER JOIN' => [
                            ['ct', 'r.rev_id = ct.ct_rev_id'] // JOIN с change_tag
                        ]
                    ]
                );

                // Если с Мастером не нашли - ищем просто последнюю ревизию перед созданием ветки
                if (!$parentRevIdWithMaster){
                    $parrentRevId = $dbw->selectField(
                        'revision',
                        'rev_id',
                        [
                            'rev_page' => $page->page_id,
                            'rev_timestamp < ' . $dbw->addQuotes($tagData->vc_timestamp),
                        ],
                        __METHOD__,
                        [
                            'LIMIT' => 1,
                            'ORDER BY' => 'rev_timestamp DESC',
                        ]
                    );
                }
                if ($parrentRevId || $parentRevIdWithMaster) {
                    $diffURL = $titleObj->getFullURL(['diff' => $page->rev_id, 'oldid' => $parrentRevId ?? $parentRevIdWithMaster]);
                    $diffButton = '<button class="btn btn-dark" onclick="window.open(\'' . $diffURL . '\', \'_blank\')">' . wfMessage('vcsystem-specialpage-settings-pages-table-diff-button-label')->text() . '</button>';
                }
            }

            // Проверяем - есть ли эта ревизия уже в Мастере?
            $revTagRecord = $dbw->selectRow(
                'change_tag',
                'ct_id',
                [
                    'ct_rev_id' => $page->rev_id,
                    'ct_tag_id' => $masterTagId
                ],
                __METHOD__
            );

            // Проверяем - Если в мастере, то какая ветка была последней слита в этом документе с мастером?
            $beforeMasterBranchName = '<button class="btn btn-light disabled">' . wfMessage('vcsystem-specialpage-settings-pages-table-to-master-branch-before-the-master-not-found')->text() . '</button>';
            if (!$revTagRecord) {
                // Получаем rev_id мастера в этом документе, чтобы дальше по этой ревизии искать -
                // из какой ветки было последнее слияние с Мастером
                $masterRev = $dbw->selectRow(
                    [
                        'r' => 'revision',
                        'ct' => 'change_tag'
                    ],
                    ['r.rev_id', 'r.rev_timestamp'],
                    [
                        'r.rev_page' => $page->page_id,
                        'ct.ct_tag_id' => $masterTagId,
                        'r.rev_id = ct.ct_rev_id' // JOIN условие
                    ],
                    __METHOD__,
                    [
                        'LIMIT' => 1,
                        'INNER JOIN' => [
                            ['ct', 'r.rev_id = ct.ct_rev_id'] // JOIN с change_tag
                        ]
                    ]
                );
                if (!empty($masterRev)) {
                    // Если нашли ревизию с мастером, ищем в ней тег, который стоит (помимо мастера) -
                    // это и будет та ветка, которая была слита с Мастером
                    $revIdResult = $dbw->selectField(
                        'change_tag',
                        'ct_tag_id',
                        [
                            'ct_rev_id' => $masterRev->rev_id,
                            'ct_tag_id IN ('. $dbw->makeList(array_keys(array_filter($vc_all_tags, function($item) use ($branchesDBPrefix) {
                                return $item['name'] !== $branchesDBPrefix."Master";
                            }))). ')'
                        ],
                        __METHOD__
                    );

                    if ($revIdResult) {
                        $parentDiffURL = $titleObj->getFullURL(['diff' => $page->rev_id, 'oldid' => $masterRev->rev_id]);
                        $parrentBtnClass = "success";
                        if ($vc_all_tags[$revIdResult]['isActive'] != 1){
                            $parrentBtnClass = "secondary";
                        }

                        $buttonLabel = "";
                        if (strpos($vc_all_tags[$revIdResult]['name'], $branchesDBPrefix) === 0) {
                            $buttonLabel = substr($vc_all_tags[$revIdResult]['name'], strlen($branchesDBPrefix));
                        }
                        $beforeMasterBranchName = '<button class="btn btn-' . $parrentBtnClass . ' vcs-merge-to-master-parent" onclick="window.open(\'' . $parentDiffURL . '\', \'_blank\')"><span class="label-in-the-master-parent-icon" style="filter: invert(100%);"></span>&nbsp;' . $buttonLabel . '</button>';
                    }

                    else{
                        // Если не нашли запись с тегом, помимо Мастера - используем сам Мастер (видимо, прямо в нём
                        // были сделаны изменения)
                        $parentDiffURL = $titleObj->getFullURL(['diff' => $page->rev_id, 'oldid' => $masterRev->rev_id]);
                        $parrentBtnClass = "success";
                        $beforeMasterBranchName = '<button class="btn btn-' . $parrentBtnClass . ' vcs-merge-to-master-parent" onclick="window.open(\'' . $parentDiffURL . '\', \'_blank\')"><span class="label-in-the-master-icon" style="filter: invert(100%);"></span>&nbsp;' . wfMessage('vcsystem-master-branch-name')->text(). '</button>';
                    }
                }
                else{
                    // Получаем самую последнюю ревизию по данному документу, которая не связана ни с одним из тегов
                    // VCSystem
                    $latestActualRev = $dbw->selectRow(
                        [
                            'r' => 'revision',
                            'ct' => 'change_tag'
                        ],
                        ['r.rev_id', 'r.rev_timestamp'],
                        [
                            'r.rev_page' => $page->page_id,
                            'ct.ct_tag_id NOT IN (' . $dbw->makeList(array_keys($vc_all_tags)) . ') OR ct.ct_tag_id IS NULL',
                        ],
                        __METHOD__,
                        [
                            'LIMIT' => 1,
                            'ORDER BY' => 'r.rev_timestamp DESC',
                            'JOIN' => [
                                'TABLE' => 'ct',
                                'CONDITION' => 'r.rev_id = ct.ct_rev_id'
                            ]
                        ]
                    );

                    if (!empty($latestActualRev)){
                        // Сравниваем timestamps нашей ветки и последней ревизии в документе
                        if($latestActualRev->rev_timestamp > $vc_all_tags[$tagId]['vc_timestamp'] && $latestActualRev->rev_id != $page->rev_id){
                            $parentDiffURL = $titleObj->getFullURL(['diff' => $page->rev_id, 'oldid' => $latestActualRev->rev_id]);
                            $parrentBtnClass = "light-sea-wave";
                            $beforeMasterBranchName = '<button class="btn btn-' . $parrentBtnClass . ' vcs-merge-to-master-parent" onclick="window.open(\'' . $parentDiffURL . '\', \'_blank\')"><span class="label-in-the-master-parent-icon" style="filter: invert(100%);"></span>&nbsp;' . wfMessage('vcsystem-unknown-branch-name')->text(). '</button>';
                        }
                    }
                }
            }

            $mergeToMasterButton = '';
            $tdBgColor = '';
            // Если нашли уже слитую в Мастер - убираем кнопку слияния и
            if ($revTagRecord) {
                $mergeToMasterButton = '<h4 class="label" style="color: var(--bs-success);"><span class="label-in-the-master"></span> &nbsp;'.wfMessage('vcsystem-specialpage-settings-pages-table-merge-file-in-master-label')->text().'</h4>';
                $tdBgColor = 'background-color: honeydew;';
            }
            else {
                $mergeToMasterButton = '<button class="btn btn-warning vcs-merge-to-master" data-doc="'. $page->rev_id .'">'.wfMessage('vcsystem-specialpage-settings-pages-table-to-master-button-label')->text().'</button>';
            }

            $out->addHTML('<tr>');
            $out->addHTML('<td><a href="'.$viewURL.'" title="'.wfMessage('vcsystem-specialpage-settings-pages-table-view-link-title')->text().'" target="_blank">' . $pageTitle . '</a> <code>(' . $revisionDate . ')</code></td>');
            $out->addHTML('<td style="text-align: center; width:7%; min-width:225px;">');
            $out->addHTML($diffButton);
            $out->addHTML('<td style="text-align: center; width:7%; min-width:190px;' . $tdBgColor . '">');
            $out->addHTML($mergeToMasterButton);
            $out->addHTML('</td>');
            $out->addHTML('<td style="text-align: center; width:7%; min-width:190px;">');
            $out->addHTML($beforeMasterBranchName);
            $out->addHTML('</td>');
            $out->addHTML('</tr>');
        }

        $out->addHTML('</tbody>');
        $out->addHTML('</table>');

        return true;
    }
}
