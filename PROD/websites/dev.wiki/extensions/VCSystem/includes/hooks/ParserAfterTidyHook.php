<?php

namespace VCSystem\hooks;

use DOMDocument;
use DOMXPath;
use IDatabase;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use OldLocalFile;
use OutputPage;
use Parser;
use RequestContext;
use stdClass;
use Title;

class ParserAfterTidyHook
{
    public static function onParserAfterTidy(Parser $parser, &$text)
    {
        if ( defined( 'MW_ENTRY_POINT' ) && MW_ENTRY_POINT === 'load' ) {
            return true;
        }

        $logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'VCSystem' );

        global $wgRequest, $wgUploadPath, $wgLang;

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        $context = new RequestContext();
        $context->setRequest($wgRequest);
        $currentUser = RequestContext::getMain()->getUser(); // Используем это вместо $wgUser
        $context->setUser($currentUser);

        $output = new OutputPage($context);

        if ($wgRequest->getVal('diff') != null || $wgRequest->getVal('action') === 'history' ) {
            return true;
        }

        // Получение ct_id из cookies
        $ctIdFromCookie = $wgRequest->getCookie('brnch', 'vcs_', 'Master');

        // Получение cookies для режима редактирования файлов
        $filesEditModeCookie = $wgRequest->getCookie('isEditMode', 'vcs_', '0'); // если 1 - режим редактирования, иначе - просмотр.
        //Это - опционально (полезно для обычных файлов, который запрашиваются через cUrl внутри query и нет возможности дописать отдельно ещё параметр режима в строку)


        // Очистка ct_id
        if (!preg_match('/^[\w-]{3,10}$/i', $ctIdFromCookie)) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-branch-invalid-name' )->text() . '</div>';
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);

            $text = $errorMessage . $text;
            return false;
        }


        // Поиск в БД
        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        // Ищем timestamp и другие поля из vcsystem_main для данного $ctIdFromCookie
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
            $text = $errorMessage . $text;
            return false;
        }

        // Находим все img с атрибутом src, начинающимся на $wgUploadPath
        $text = preg_replace_callback(
            '/<img [^>]*?src=["\'](' . preg_quote($wgUploadPath, '/') . '[^"\']+)["\'][^>]*?>/i',
            function ($matches) use ($vcData, $output, $dbw, $branchesDBPrefix) {
                $logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'VCSystem' );
                global $wgUploadPath, $wgUploadDirectory;

                $oldSrcOrigHTML = $matches[1];
                $oldSrcOrig = urldecode($matches[1]);

                if (preg_match('/^' . preg_quote($wgUploadPath, '/'). '(\/thumb)/i', $oldSrcOrig)) {
                    // Убрать часть строки после последнего слэша - если мы имеем дело с миниатюрой (там после слеша указаны переметры размеров)
                    $oldSrc = preg_replace('/^' . preg_quote($wgUploadPath, '/') . '(\/thumb)?/i', '', $oldSrcOrig);
                    $oldSrcNoLastPart = preg_replace('/\/[^\/]*$/', '', $oldSrcOrig);

                    $testFile = preg_replace('/^' . preg_quote($wgUploadPath, '/') . '/', $wgUploadDirectory, $oldSrcNoLastPart);
                    if (file_exists($testFile)) {
                        $oldSrc = $oldSrcNoLastPart;
                    } // и так же для того же случая убираем директорию thumb

                }
                else {
                    $oldSrc = $oldSrcOrig;
                }

                $newSrc = self::getFileUrl($oldSrc, $vcData, $output, $dbw, $branchesDBPrefix);
                if ($newSrc === null) {
                    $errorMessage = '<div class="errorbox">' . $oldSrc . ' ' . wfMessage('vcsystem-file-not-found')->text() . '</div>';
                    $output->addHTML($errorMessage);
                    return false;
                }
                return str_replace($oldSrcOrigHTML, $newSrc, $matches[0]);
            },
            $text
        );

        // Удаляем тег для миниатуюр (пока не реализована логика создания миниатюр в VCSystem)
        $text = preg_replace('/srcset=\"[^\"]*\"/', '', $text);

        return true;
    }


    public static function getFileUrl(string &$path, stdClass &$vcData, OutputPage &$output, \Wikimedia\Rdbms\DBConnRef &$dbw, string &$branchesDBPrefix)
    {

        $logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'VCSystem' );
        $fileVersion = null;

        // Убираем $wgUploadPath из пути и декодируем URL
        $fileName = urldecode(str_replace($GLOBALS['wgUploadPath'], '', $path));

        $fileName = basename($fileName);

//        $logger->info( 'ARSENII PATH1: '.$fileName );

        // Создаем объект Title из полученного имени файла
        $title = Title::newFromText($fileName, NS_FILE);

        $revisionData = $dbw->selectRow(
            ['revision', 'change_tag'],
            ['rev_id', 'rev_timestamp'], // Поля, которые мы хотим выбрать
            [
                'rev_page' => $title->getId(),
                'ct_tag_id' => $vcData->ctd_id,
                'rev_deleted' => 0,
                'rev_id=ct_rev_id' // условие JOIN
            ],
            __METHOD__,
            ['ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1]
        );

        if ($title && $title->exists() && $title->getNamespace() == NS_FILE) {
            // для файлов

            if ($revisionData){

                // Проверяем основную запись в таблице image
                $fileVersion = $dbw->selectRow(
                    'image',
                    ['img_name', 'img_timestamp', 'img_sha1'],
                    [
                        'img_name' => $title->getDBkey(),
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
                            'oi_name' => $title->getDBkey(),
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
                        'rev_page' => $title->getId(),
                        'ct_tag_id' => $masterTagId,
                        'rev_deleted' => 0,
                        'rev_id=ct_rev_id' // условие JOIN
                    ],
                    __METHOD__,
                    ['ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1]
                );

                if ($revisionData) {
                    // Проверяем основную запись в таблице image
                    $fileVersion = $dbw->selectRow(
                        'image',
                        ['img_name', 'img_timestamp', 'img_sha1'],
                        [
                            'img_name' => $title->getDBkey(),
                            'img_timestamp' => $revisionData->rev_timestamp,
                        ],
                        __METHOD__
                    );

                    if (!$fileVersion) {
                        $fileVersion = $dbw->selectRow(
                            'oldimage',
                            ['oi_name', 'oi_sha1', 'oi_timestamp'],
                            [
                                'oi_name' => $title->getDBkey(),
                                'oi_timestamp' => $revisionData->rev_timestamp,
                                'oi_deleted' => 0
                            ],
                            __METHOD__
                        );
                    }
                } else // иначе, берём просто последнюю версию файла до момента создания ветки
                    // Проверяем основную запись в таблице image
                    $fileVersion = $dbw->selectRow(
                        'image',
                        ['img_name', 'img_timestamp', 'img_sha1'],
                        [
                            'img_name' => $title->getDBkey(),
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
                            'oi_name' => $title->getDBkey(),
                            'oi_timestamp <' . $dbw->addQuotes($vcData->vc_timestamp),
                            'oi_deleted' => 0
                        ],
                        __METHOD__,
                        [
                            'ORDER BY' => 'oi_timestamp DESC',
                            'LIMIT' => 1
                        ]
                    );
                }
            }

        }
        if ($fileVersion) {

            // Определяем, какой URL использовать
            $repoGroup = MediaWikiServices::getInstance()->getRepoGroup();

            if (isset($fileVersion->img_name)) {
                // Основное изображение
                $file = $repoGroup->getLocalRepo()->newFile($title);
            } else {

                // Старая версия изображения
                $repo = $repoGroup->getLocalRepo();
                $file = OldLocalFile::newFromTitle($title, $repo, $fileVersion->oi_timestamp); // используя timestamp для получения старой версии файла

            }
            if ($file->exists()) {
                return $file->getUrl();

            } else {
                return null;
            }
        }
        else {

            global $wgExtensionAssetsPath;
            return "$wgExtensionAssetsPath/VCSystem/resources/images/file-not-found.png";
        }

        return null;
    }
}
