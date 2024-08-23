<?php

namespace VCSystem\hooks;

use Article;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use OldLocalFile;
use OutputPage;
use RequestContext;

class ArticleViewHeaderHook
{
    public static function onArticleViewHeader(Article &$article, &$output, bool &$useParserCache) {

        global $wgRequest, $wgOut;

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        $logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'VCSystem' );

        $output = $article->getContext()->getOutput();

        $wgOut->addModules('ext.VCSystem.main');
        $wgOut->addModules('ext.VCSystem.switch');
        $wgOut->addModules('ext.VCSystem');

        if ($wgRequest->getVal('diff') != null ) {
            return true;
        }

        // Получение ct_id из cookies
        $ctIdFromCookie = $wgRequest->getCookie('brnch', 'vcs_', 'Master');

        // Очистка ct_id
        if (!preg_match('/^[\w-]{3,10}$/i', $ctIdFromCookie)) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-invalid-name' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);

            $output->addHTML($errorMessage);
            return false;
        }


        // Поиск в БД
        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        // Ищем timestamp и другие поля из vcsystem_main для данного $branchesDBPrefix.$ctIdFromCookie
        $vcData = $dbw->selectRow(
            ['vm' => 'vcsystem_main', 'ctd' => 'change_tag_def'],
            ['vm.vc_timestamp', 'vm.owner_usr_id', 'ctd.ctd_id'],
            [
                'vm.ctd_id = ctd.ctd_id',
                'ctd.ctd_name' => $branchesDBPrefix.$ctIdFromCookie,
                'vm.isActive != 0'
            ],
            __METHOD__
        );

        if (!$vcData){
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-not-found' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $wgRequest->setVal('setHttpHeader', json_encode([['cvs_isBranchOk', 'no']]));
            $output->addHTML($errorMessage);
            return false;
        }

        // на всякий случай очщаем от всего лишнего, так как, если руками вносить данные в БД через IDE, например, могут быть BOM-символы, которые
        // будут влиять на отображение информации.
        $vcData->vc_timestamp = preg_replace('/\D/', '', $vcData->vc_timestamp);

        $revisionData = $dbw->selectRow(
            ['revision', 'change_tag'],
            ['rev_id', 'rev_timestamp'], // Поля, которые мы хотим выбрать
            [
                'rev_page' => $article->getPage()->getId(),
                'ct_tag_id' => $vcData->ctd_id,
                'rev_deleted' => 0,
                'rev_id=ct_rev_id' // условие JOIN
            ],
            __METHOD__,
            ['ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1]
        );

        // Получение cookies для режима редактирования файлов
        $filesEditModeCookie = $wgRequest->getCookie('isEditMode', 'vcs_', '0'); // если 1 - режим редактирования, иначе - просмотр.
        //Это - опционально (полезно для обычных файлов, который запрашиваются через cUrl внутри query и нет возможности дописать отдельно ещё параметр режима в строку)

        // Если запросили страницу с файлом (через File:filename.ext) с аргументом isVCSystem=1 или
        // куки vcs_isEditMode (при том, что пользователь имеет права vcs-merge-versions-permission,
        // или если пользователь простой без прав vcs-merge-versions-permission, то переадресовывем на файл.
        // Иначе - просто выводим страницу файла.
        $user = RequestContext::getMain()->getUser();
        if ($article->getPage()->getNamespace() == NS_FILE
            && ((($wgRequest->getVal('isVCSystem') === 1 || $filesEditModeCookie === "0")
                    && $user->isAllowed('vcs-merge-versions-permission'))
                || !$user->isAllowed('vcs-merge-versions-permission')))
        {
            // для файлов
            if ($revisionData) {

                // Проверяем основную запись в таблице image
                $fileVersion = $dbw->selectRow(
                    'image',
                    ['img_name', 'img_timestamp', 'img_sha1'],
                    [
                        'img_name' => $article->getPage()->getDBkey(),
                        'img_timestamp' => $revisionData->rev_timestamp
                    ],
                    __METHOD__
                );

                // Если не нашли в основной таблице, проверяем старые версии файла
                if (!$fileVersion) {
                    $fileVersion = $dbw->selectRow(
                        'oldimage',
                        ['oi_name', 'oi_sha1', 'oi_timestamp'],
                        [
                            'oi_name' => $article->getPage()->getDBkey(),
                            'oi_timestamp' => $revisionData->rev_timestamp,
                            'oi_deleted' => 0
                        ],
                        __METHOD__
                    );
                }
            }

            else { // если не найдена ревизия в ветки - ищем по-другому

                // Проверяем у мастера
                // Ищем Id мастер ветки
                $tagName = 'Master';
                $masterTagId = $dbw->selectField(
                    'change_tag_def',
                    'ctd_id',
                    ['ctd_name' => $branchesDBPrefix . $tagName], // условие по ctd_name, без учета регистра
                    __METHOD__
                );

                // Берём ревизию у мастера
                $revisionData = $dbw->selectRow(
                    ['revision', 'change_tag'],
                    ['rev_id', 'rev_timestamp'], // Поля, которые мы хотим выбрать
                    [
                        'rev_page' => $article->getPage()->getId(),
                        'ct_tag_id' => $masterTagId,
                        'rev_deleted' => 0,
                        'rev_id=ct_rev_id' // условие JOIN
                    ],
                    __METHOD__,
                    ['ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1]
                );

                if($revisionData){
                    // Проверяем основную запись в таблице image
                    $fileVersion = $dbw->selectRow(
                        'image',
                        ['img_name', 'img_timestamp', 'img_sha1'],
                        [
                            'img_name' => $article->getPage()->getDBkey(),
                            'img_timestamp' => $revisionData->rev_timestamp,
                        ],
                        __METHOD__
                    );

                    if(!$fileVersion){
                        $fileVersion = $dbw->selectRow(
                            'oldimage',
                            ['oi_name', 'oi_sha1', 'oi_timestamp'],
                            [
                                'oi_name' => $article->getPage()->getDBkey(),
                                'oi_timestamp' => $revisionData->rev_timestamp,
                                'oi_deleted' => 0
                            ],
                            __METHOD__
                        );
                    }
                }
                else // иначе, берём просто последнюю версию файла до момента создания ветки
                {
                    // Ищем в основной таблице
                    $fileVersion = $dbw->selectRow(
                        'image',
                        ['img_name', 'img_timestamp', 'img_sha1'],
                        [
                            'img_name' => $article->getPage()->getDBkey(),
                            'img_timestamp <' . $dbw->addQuotes($vcData->vc_timestamp)
                        ],
                        __METHOD__
                    );
                    // Если не нашли в основной таблице, проверяем старые версии файла
                    if (!$fileVersion) {
                        $fileVersion = $dbw->selectRow(
                            'oldimage',
                            ['oi_name', 'oi_sha1', 'oi_timestamp'],
                            [
                                'oi_name' => $article->getPage()->getDBkey(),
                                'oi_timestamp <' . $dbw->addQuotes($vcData->vc_timestamp),
                                'oi_deleted' => 0
                            ],
                            __METHOD__
                        );


                    }
                }
            }

            if ($fileVersion) {
                // Определяем, какой URL использовать
                $repoGroup = MediaWikiServices::getInstance()->getRepoGroup();

                if (isset($fileVersion->img_name)) {
                    // Основное изображение
                    $file = $repoGroup->getLocalRepo()->newFile($article->getPage());
                } else {
                    // Старая версия изображения
                    $repo = $repoGroup->getLocalRepo();
                    $file = OldLocalFile::newFromTitle($article->getTitle(), $repo, $fileVersion->oi_timestamp); // используя timestamp для получения старой версии файла
                }

                if ($file->exists()) {
                    $fileUrl = $file->getUrl();
                    $output->redirect($fileUrl);
                } else {
                    //TODO: Добавить HTTP-заголовок и его обработать в bpmn!

                    // Обработка ошибки, если файл не найден
                    $errorMessage = '<div class="errorbox">' . wfMessage('vcsystem-file-not-found')->text() . '</div>';
                    $output->addHTML($errorMessage);
                    return false;
                }
            }
            else {
                // Выводим заглушку
                global $wgExtensionAssetsPath;
                $imageUrl = "$wgExtensionAssetsPath/VCSystem/resources/images/file-not-found.png";
                $output->redirect($imageUrl);
            }

        }

        else {
            // Для стандартных страниц

            // если не нашли по тегу - берём ревизию по мастеру
            if (!$revisionData) {

                // Ищем Id мастер ветки
                $tagName = 'Master';
                $masterTagId = $dbw->selectField(
                    'change_tag_def',
                    'ctd_id',
                    ['ctd_name' => $branchesDBPrefix . $tagName], // условие по ctd_name, без учета регистра
                    __METHOD__
                );

                // Берём ревизию у мастера
                $revisionData = $dbw->selectRow(
                    ['revision', 'change_tag'],
                    ['rev_id', 'rev_timestamp'], // Поля, которые мы хотим выбрать
                    [
                        'rev_page' => $article->getPage()->getId(),
                        'ct_tag_id' => $masterTagId,
                        'rev_deleted' => 0,
                        'rev_id=ct_rev_id' // условие JOIN
                    ],
                    __METHOD__,
                    ['ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1]
                );

            }

            // если не нашли у мастера - ищем последнюю ревизию в системе, где тег не соответстует ни одной ветке.
            if (!$revisionData) {

                // Выбираем Id всех тегов VCSystem
                $resTagsIds = $dbw->select(
                    ['ctd' => 'change_tag_def', 'vm' => 'vcsystem_main'],
                    ['ctd.ctd_id'],
                    [
                        'ctd.ctd_name LIKE ' . $dbw->addQuotes($branchesDBPrefix . '%'),
                        'ctd.ctd_id = vm.ctd_id'
                    ],
                    __METHOD__
                );

                $allTagsIdsList = [];
                foreach ($resTagsIds as $row) {
                    $allTagsIdsList[] = $row->ctd_id;
                }

                // Ищем последнюю ревизию БЕЗ метки любой из веток.
                $revisionData = $dbw->selectRow(
                    ['revision', 'change_tag'],
                    ['rev_id', 'rev_timestamp'], // Поля, которые мы хотим выбрать
                    [
                        'rev_page' => $article->getPage()->getId(),
                        'ct_tag_id NOT IN (' . $dbw->makeList($allTagsIdsList) . ')',
                        'rev_deleted' => 0,
                        'rev_id=ct_rev_id' // условие JOIN
                    ],
                    __METHOD__,
                    ['ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1]
                );
            }

            // если не нашли по тегу - берём ревизию по времени (нужно для страниц файлов)
            if (!$revisionData) {
                // Ищем ревизию с rev_timestamp меньше $rvTimestamp
                $revisionData = $dbw->selectRow(
                    'revision',
                    ['rev_id', 'rev_timestamp'],
                    ['rev_page' => $article->getPage()->getId(), "rev_timestamp < {$dbw->addQuotes($vcData->vc_timestamp)}"],
                    __METHOD__,
                    ['ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1]
                );

            }

            if (!$revisionData){

                $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-revision-of-the-file-not-found' )->text() . '</div>';
                $wgRequest->setVal('pageOutput', json_encode(['clearAll', $errorMessage])); // Запрос в onBeforePageDisplay на очистку остального содержимаого страницы.
                //Полезно для страницы с файлом в ветках, в которых этого файла нет, чтобы не выводилась белиберда.
                return false;
            }

            $revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
            $revisionRecord = $revisionLookup->getRevisionById($revisionData->rev_id);

            if ($revisionRecord) {

                $wgRequest->setVal('revisionId', $revisionData->rev_id);
                $content = $revisionRecord->getContent(SlotRecord::MAIN);

                $contentRenderer = MediaWikiServices::getInstance()->getContentRenderer();
                $parserOutput = $contentRenderer->getParserOutput($content, $article->getTitle());

                $article->getContext()->getOutput()->clearHTML();
                $article->getContext()->getOutput()->addParserOutput($parserOutput);

                $outputDone = true; // Это говорит MediaWiki, что больше ничего не нужно добавлять к выводу
                $useParserCache = false; // Отключаем использование кеша парсера для этой страницы
            }
        }

        return true;
    }
}
