<?php

namespace VCSystem\hooks;
use Exception;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki;
use MWTimestamp;
use OldLocalFile;
use RequestContext;
use Title;
use VCSystem\hooks\models\UpdateRevisionForFileOnPageContentChangeModel;

class RecentChange_saveHook
{
    public static function onRecentChange_save($recentChange){
        $logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'VCSystem' );
        $logger->info( 'onRecentChange_save: 1111111111111111111' );

        if (UpdateRevisionForFileOnPageContentChangeModel::getIsNeedJob()){
            self::initFileRevisionUpdater(
                UpdateRevisionForFileOnPageContentChangeModel::getRevisionRecord(),
                UpdateRevisionForFileOnPageContentChangeModel::getTagId(),
                UpdateRevisionForFileOnPageContentChangeModel::getWikiPage(),
                UpdateRevisionForFileOnPageContentChangeModel::getTagData(),
                $logger
            );
        }
        return true;
    }

    public static function initFileRevisionUpdater($revisionRecord, $tagId, $wikiPage, $tagData, $logger){

        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        // Создаем объект Title из имени файла
        $title = $wikiPage->getTitle();

        $revisionData = $dbw->selectRow(
            ['revision', 'change_tag'],
            ['rev_id', 'rev_timestamp'], // Поля, которые мы хотим выбрать
            [
                'rev_page' => $title->getId(),
                'ct_tag_id' => $tagId,
                'rev_deleted' => 0,
                'rev_id=ct_rev_id' // условие JOIN
            ],
            __METHOD__,
            ['ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1]
        );

        if ($revisionData){
            // Проверяем основную запись в таблице image
            $fileVersion = $dbw->selectRow(
                'image',
                ['*'],
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
                    ['*'],
                    [
                        'oi_name' => $title->getDBkey(),
                        'oi_timestamp' => $revisionData->rev_timestamp,
                        'oi_deleted' => 0
                    ],
                    __METHOD__
                );
            }
            // Если и в старых файлах не нашли - нужно создать запись. Похоже, что ревизия по документу найдена, а файл нет, так как сначала в ветке было изменение в описании файла.
            if (!$fileVersion) {
                // Проверяем основную запись в таблице image
                $fileVersion = $dbw->selectRow(
                    'image',
                    ['*'],
                    [
                        'img_name' => $title->getDBkey(),
                        'img_timestamp <' . $dbw->addQuotes($tagData->vc_timestamp)
                    ],
                    __METHOD__
                );

                // Если не нашли в основной таблице, проверяем старые версии файла
                if (!$fileVersion) {
                    $fileVersion = $dbw->selectRow(
                        'oldimage',
                        ['*'],
                        [
                            'oi_name' => $title->getDBkey(),
                            'oi_timestamp <' . $dbw->addQuotes($tagData->vc_timestamp),
                            'oi_deleted' => 0
                        ],
                        __METHOD__
                    );
                }
            }
        }

        else {

            // Проверяем основную запись в таблице image
            $fileVersion = $dbw->selectRow(
                'image',
                ['*'],
                [
                    'img_name' => $title->getDBkey(),
                    'img_timestamp <' . $dbw->addQuotes($tagData->vc_timestamp)
                ],
                __METHOD__
            );

            // Если не нашли в основной таблице, проверяем старые версии файла
            if (!$fileVersion) {
                $fileVersion = $dbw->selectRow(
                    'oldimage',
                    ['*'],
                    [
                        'oi_name' => $title->getDBkey(),
                        'oi_timestamp <' . $dbw->addQuotes($tagData->vc_timestamp),
                        'oi_deleted' => 0
                    ],
                    __METHOD__
                );
            }
        }

        // Обработка для файла, нужно создать новую ревизию для самого файла в этой ветке, чтобы не было путаницы
        if ($fileVersion != null) {

            // Определяем - какую версию брать (oldimage или image)
            if (isset($fileVersion->img_name)) {
                // Основное изображение (из таблицы image)
                self::addNewRevisionToPhysicalFile($tagId, $fileVersion, "img", $revisionRecord, $logger);
            } else {
                // Старая версия изображения (из таблицы oldimage)
                self::addNewRevisionToPhysicalFile($tagId, $fileVersion, "oi", $revisionRecord, $logger);
            }
        }
    }

    public static function addNewRevisionToPhysicalFile($tagId, \stdClass $fileVersion, string $type, MediaWiki\Revision\RevisionRecord $revisionRecord, $logger)
    {

        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        // Получаем основные службы MediaWiki
        $services = MediaWikiServices::getInstance();

        // Получаем главное файловое хранилище
        $repo = $services->getRepoGroup()->getLocalRepo();
        $localPath = null;
        $filename = null;
        $isOldFile = false;

        if ($type === "img"){
            $filename = $fileVersion->img_name;

            // Получаем объект файла по его имени
            $file = $repo->newFile($filename);

            // Если файл существует, получаем полный путь к нему
            if ($file && $file->exists()) {
                $localPath = $file->getLocalRefPath();
                $isOldFile = false; // явно указываем, что файл не из oldimage
            }
        }
        else if ($type === "oi"){

            $filename = $fileVersion->oi_archive_name;
            $fileTitle = Title::newFromText($fileVersion->oi_name, NS_FILE);
            if (!$fileTitle || !$fileTitle->exists()) {
                $logger->info( 'onPageSaveComplete: No Title obj for the file ('.$filename.')' );
            }

            // Получаем объект старой версии файла по его имени и времени создания
            $oldFile = OldLocalFile::newFromArchiveName($fileTitle, $repo, $fileVersion->oi_archive_name);

            // Если файл существует, получаем полный путь к нему
            if ($oldFile && $oldFile->exists()) {
                $localPath = $oldFile->getLocalRefPath();
                $isOldFile = true; // указываем, что файл из oldimage
            }
        }

        // Отправляем файл на новую ревизию
        self::applyNewRevisionToFile($filename, $fileVersion, $isOldFile, $localPath, $tagId, $revisionRecord, $repo, $logger);
        return true;
    }

    public static function applyNewRevisionToFile($filename, $fileVersion, $isOldFile, $localPath, $tagId, $revisionRecord, $repo, $logger) {

        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        // Создаём timestamp для нашей новой ревизии физического файла
//        $newRevisionTimestamp = MWTimestamp::now();
        // Получаем текущее время + 1 секунда в формате Unix timestamp, чтобы точно не было двух ревизий по этому файлу с одинаковым timestamp
        $unixNowTimestamp = time() + 1;
        // Конвертируем Unix timestamp в формат MediaWiki
        $mwTimestamp = MWTimestamp::getInstance($unixNowTimestamp);
        $newRevisionTimestamp = $mwTimestamp->getTimestamp(TS_MW);

        $imgFilename = null;

        $logger->info( 'onPageSaveComplete: applyNewRevisionToFile - isOldFile: '.$isOldFile );

        // Создаём путь для копирования
        if ($isOldFile === true){ // если файл из архива - нужно получить путь к файлу в основном архиве uploads
            $imgFilename = preg_replace('/^\d{14}!/', '', $filename);

            // Запрашиваем из БД данные по текущему файлу из image таблицы
            $currentImgDataRow = $dbw->selectRow(
                'image',
                ['*'], // Выбор всех столбцов
                [ 'img_name' => $imgFilename ],
                __METHOD__
            );

            if (!$currentImgDataRow){
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not find record for file in the table image' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file' )->text());
            }

            // Имя для будущей копии текущего файла из image
            $newArchiveFilename = $newRevisionTimestamp."!".$imgFilename;

            // Директория (и имя файла) img:
            $file = $repo->newFile($imgFilename);
            $imgLocalPath = $file->getLocalRefPath();

            // Директория (и имя файла) в архиве для копирования из uploads
            $archiveLocalPath = preg_replace('/[^\/]+?$/', '', $localPath);

            // Переносим файл из uploads в archive (с новым именем)
            // Сначала создаём директории, если их нет, чтобы не было ошибок в rename()
            if (!file_exists($archiveLocalPath)) {
                mkdir($archiveLocalPath, 0777, true);  // Рекурсивное создание директорий
            }

            // Добавляем имя файла к пути
            $archiveLocalPath .= '/'.$newArchiveFilename;

            // Сам перенос
            if (!rename($imgLocalPath, $archiveLocalPath)) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not copy file from uploads to archive' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-copy-file-from-uploads-to-archive-error' )->text());
            }

            // Копируем текущий файл из archive в uploads
            if (!copy($localPath, $imgLocalPath)) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not copy file from archive to uploads' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-copy-file-from-archive-to-uploads-error' )->text());
            }

            // Создаём запись в таблице oldimage для перенесённого файла из image
            // (timestamp сохраняется - это логика MW и, заодно, избавляет от неразберихи с ревизиями)
            // Подготовим данные для вставки в oldimage
            $data = [
                'oi_name'           => $currentImgDataRow->img_name,
                'oi_archive_name'   => $newArchiveFilename,
                'oi_size'           => $currentImgDataRow->img_size,
                'oi_width'          => $currentImgDataRow->img_width,
                'oi_height'         => $currentImgDataRow->img_height,
                'oi_bits'           => $currentImgDataRow->img_bits,
                'oi_description_id' => $currentImgDataRow->img_description_id,
                'oi_actor'          => $currentImgDataRow->img_actor,
                'oi_timestamp'      => $currentImgDataRow->img_timestamp,
                'oi_metadata'       => $currentImgDataRow->img_metadata,
                'oi_media_type'     => $currentImgDataRow->img_media_type,
                'oi_major_mime'     => $currentImgDataRow->img_major_mime,
                'oi_minor_mime'     => $currentImgDataRow->img_minor_mime,
                'oi_sha1'           => $currentImgDataRow->img_sha1,
                'oi_deleted'        => 0
            ];

            // Добавляем данные в oldimage
            $dbw->insert('oldimage', $data, __METHOD__);

            // Проверка на успешное добавление записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert into the oldimage table data from the image table' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-into-oldimage-from-image-error' )->text());
            }

            // Записываем данные в image.
            // Подготовим данные для обновления в image
            $data = [
                'img_size'           => $fileVersion->oi_size,
                'img_width'          => $fileVersion->oi_width,
                'img_height'         => $fileVersion->oi_height,
                'img_metadata'       => $fileVersion->oi_metadata,
                'img_bits'           => $fileVersion->oi_bits,
                'img_media_type'     => $fileVersion->oi_media_type,
                'img_major_mime'     => $fileVersion->oi_major_mime,
                'img_minor_mime'     => $fileVersion->oi_minor_mime,
                'img_description_id' => $fileVersion->oi_description_id,
                'img_actor'          => $fileVersion->oi_actor,
                'img_timestamp'      => $newRevisionTimestamp,
                'img_sha1'           => $fileVersion->oi_sha1
            ];

            // Условие для обновления
            $conditions = ['img_name' => $fileVersion->oi_name];

            // Обновляем запись в image
            $dbw->update('image', $data, $conditions, __METHOD__);


            // Проверка на успешное обновления записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert into the image table data from the oldimage table' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-into-image-from-oldimage-error' )->text());
            }

            // Создаём ревизию.
            // Подготовим данные для вставки в revision
            $data = [
                'rev_page'         => $file->getTitle()->getId(),
                'rev_comment_id'   => 0,
                'rev_actor'        => $fileVersion->oi_actor,
                'rev_timestamp'    => $newRevisionTimestamp,
                'rev_minor_edit'   => 0,
                'rev_deleted'      => 0,
                'rev_len'          => 0,
                'rev_parent_id'    => $revisionRecord->getId(),
                'rev_sha1'         => $revisionRecord->getSha1()
            ];

            // Вставляем данные в revision
            $dbw->insert('revision', $data, __METHOD__);

            // Проверка на успешное добавление записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert data into the revision table' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-into-revision-error' )->text());
            }

            $newRevId = $dbw->insertId();

            // Обновляем последнюю ревизию в таблице page
            $dbw->update(
                'page',
                [ 'page_latest' => $newRevId ], // обновляем ревизию
                [ 'page_id' => $file->getTitle()->getId() ], // в файле с указанным id
                __METHOD__
            );

            // Проверка на успешное добавление записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not update page_latest in the page table' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-update-page_latest-in-page-error' )->text());
            }

            // Удаляем метки на текущую ветку к данному файлу.
            // Запрос всех rev_id, соответствующих заданной странице
            $revIdsResult = $dbw->select(
                'revision',
                'rev_id',
                [ 'rev_page' => $file->getTitle()->getId() ],
                __METHOD__
            );

            // Записываем служебные данные для ревизии (слоты и комментарий) - просто скопировав их из предыдущей (которая связана с изменением текстовой страницы файла) ревизии
            $dbw->insert(
                'revision_comment_temp',
                [
                    'revcomment_rev' => $newRevId,
                    'revcomment_comment_id' => 1
                ],
                __METHOD__
            );

            // Проверка на успешное добавление записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert revision data into the revision_comment_temp table' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-into-revision_comment_temp-error' )->text());
            }

            // получаем contentId ревизии, которая записывает текст на страницу файла
            $slotContentId = $dbw->selectField(
                'slots',
                'slot_content_id',
                ['slot_revision_id' => $revisionRecord->getId()],
                __METHOD__
            );

            $dbw->insert(
                'slots',
                [
                    'slot_revision_id' => $newRevId,
                    'slot_role_id' => 1,
                    'slot_origin' => $revisionRecord->getId(),
                    'slot_content_id' => $slotContentId
                ],
                __METHOD__
            );

            // Проверка на успешное добавление записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert revision record into the slots table' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-into-slots-error' )->text());
            }

            // Само удаление записей с тегом по данному файлу с текущей ветке
            $revIds = [];
            foreach ($revIdsResult as $row) {
                $revIds[] = $row->rev_id;
            }

            if (!empty($revIds)) {
                $dbw->delete(
                    'change_tag',
                    [
                        'ct_tag_id' => $tagId,
                        'ct_rev_id' => $revIds   // Массив rev_ids, соответствующий условию IN в SQL
                    ],
                    __METHOD__
                );
                // Проверку не ставим, потому, что пустой ответ может быть, если ни одной записи нет
            }


            // Добавляем метку на нашу ветку к новой ревизии
            $dbw->insert(
                'change_tag',   // Название таблицы
                [
                    'ct_rev_id' => $newRevId,
                    'ct_tag_id' => $tagId
                ],
                __METHOD__
            );
            // Проверка на успешное удаление записей
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert new revision data into the change_tag table' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-new-revision-into-change_tag-error' )->text());
            }
        }
        else{ // Если файл был новый (из image)

            global $wgUploadDirectory;
            $imgFile = $repo->newFile($filename); // объект файла

            // создаём имя для будущего архивного файла
            // Имя для будущей копии текущего файла из image
            $newArchiveFilename = $newRevisionTimestamp."!".$filename;

            // Директория (и имя файла) oldimg:
            $archiveLocalPath = str_replace($wgUploadDirectory, $wgUploadDirectory . '/archive', dirname($localPath).'/'.$newArchiveFilename);
//            $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - 111111 '.$archiveLocalPath.' 22222222 '.$imgLocalPath );

            // Переносим файл из uploads в archive (с новым именем)
            // Сначала создаём директории, если их нет, чтобы не было ошибок в rename()
            $baseArchiveDir = dirname($archiveLocalPath);
            if (!file_exists($baseArchiveDir)) {
                mkdir($baseArchiveDir, 0777, true);  // Рекурсивное создание директорий
            }

            // Сам перенос
            if (!copy($localPath, $archiveLocalPath)) {
//                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not copy file from uploads ('.$localPath.') to archive ('.$archiveLocalPath.') (section - else)' );
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not copy file from uploads ('.$localPath.') to archive ('.$archiveLocalPath.') (section - else)' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-copy-file-from-uploads-to-archive-error' )->text());
            }

            // Создаём запись в таблице oldimage с данными из нашего файла.
            // Подготовим данные для вставки в oldimage
            $data = [
                'oi_name'           => $fileVersion->img_name,
                'oi_archive_name'   => $newArchiveFilename,
                'oi_size'           => $fileVersion->img_size,
                'oi_width'          => $fileVersion->img_width,
                'oi_height'         => $fileVersion->img_height,
                'oi_bits'           => $fileVersion->img_bits,
                'oi_description_id' => $fileVersion->img_description_id,
                'oi_actor'          => $fileVersion->img_actor,
                'oi_timestamp'      => $fileVersion->img_timestamp,
                'oi_metadata'       => $fileVersion->img_metadata,
                'oi_media_type'     => $fileVersion->img_media_type,
                'oi_major_mime'     => $fileVersion->img_major_mime,
                'oi_minor_mime'     => $fileVersion->img_minor_mime,
                'oi_sha1'           => $fileVersion->img_sha1,
                'oi_deleted'        => 0
            ];

            // Добавляем данные в oldimage
            $dbw->insert('oldimage', $data, __METHOD__);

            // Проверка на успешное добавление записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert into the oldimage table data from the image table (section - else)' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-into-oldimage-from-image-error' )->text());
            }

            // Обновляем timestamp на новый в image для текущего файла
            $dbw->update(
                'image',
                [ 'img_timestamp' => $newRevisionTimestamp ],
                [ 'img_name' => $fileVersion->img_name ],
                __METHOD__
            );

            // Проверка на успешное обновление записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not update field img_timestamp in the image table (section - else)' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-update-img_timestamp-in-image-error' )->text());
            }

            // Добавляем ревизию к данной записи. rev_sha1 берём у последней ревизии
            // Подготовим данные для вставки в revision
            $data = [
                'rev_page'         => $imgFile->getTitle()->getId(),
                'rev_comment_id'   => 0,
                'rev_actor'        => $fileVersion->img_actor,
                'rev_timestamp'    => $newRevisionTimestamp,
                'rev_minor_edit'   => 0,
                'rev_deleted'      => 0,
                'rev_len'          => 0,
                'rev_parent_id'    => $revisionRecord->getId(),
                'rev_sha1'         => $revisionRecord->getSha1()
            ];

            // Вставляем данные в revision
            $dbw->insert('revision', $data, __METHOD__);

            // Проверка на успешное добавление записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert data into the revision table (section - else)' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-into-revision-error' )->text());
            }

            $newRevId = $dbw->insertId();

            // Обновляем последнюю ревизию в таблице page
            $dbw->update(
                'page',
                [ 'page_latest' => $newRevId ], // обновляем ревизию
                [ 'page_id' => $imgFile->getTitle()->getId() ], // в файле с указанным id
                __METHOD__
            );

            // Проверка на успешное обновления записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not update page_latest in the page table (section - else)' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-update-page_latest-in-page-error' )->text());
            }

            // Записываем служебные данные для ревизии (слоты и комментарий) - просто скопировав их из предыдущей (которая связана с изменением текстовой страницы файла) ревизии
            $dbw->insert(
                'revision_comment_temp',
                [
                    'revcomment_rev' => $newRevId,
                    'revcomment_comment_id' => 1
                ],
                __METHOD__
            );

            // Проверка на успешное обновления записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert revision data into the revision_comment_temp table (section - else)' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-into-revision_comment_temp-error' )->text());
            }

            // получаем contentId ревизии, которая записывает текст на страницу файла
            $slotContentId = $dbw->selectField(
                'slots',
                'slot_content_id',
                ['slot_revision_id' => $revisionRecord->getId()],
                __METHOD__
            );

            $dbw->insert(
                'slots',
                [
                    'slot_revision_id' => $newRevId,
                    'slot_role_id' => 1,
                    'slot_origin' => $revisionRecord->getId(),
                    'slot_content_id' => $slotContentId
                ],
                __METHOD__
            );

            // Проверка на успешное обновления записи
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert revision record into the slots table (section - else)' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-into-slots-error' )->text());
            }


            // Удаляем метки на текущую ветку к данному файлу.
            // Запрос всех rev_id, соответствующих заданной странице
            $revIdsResult = $dbw->select(
                'revision',
                'rev_id',
                [ 'rev_page' => $imgFile->getTitle()->getId() ],
                __METHOD__
            );

            // Само удаление записей с тегом по данному файлу с текущей ветке
            $revIds = [];
            foreach ($revIdsResult as $row) {
                $revIds[] = $row->rev_id;
            }

            if (!empty($revIds)) {
                $dbw->delete(
                    'change_tag',
                    [
                        'ct_tag_id' => $tagId,
                        'ct_rev_id' => $revIds   // Массив rev_ids, соответствующий условию IN в SQL
                    ],
                    __METHOD__
                );
                // Проверку не ставим, потому, что пустой ответ может быть, если ни одной записи нет
            }

            // Добавляем метку на нашу ветку к новой ревизии
            $dbw->insert(
                'change_tag',   // Название таблицы
                [
                    'ct_rev_id' => $newRevId,
                    'ct_tag_id' => $tagId
                ],
                __METHOD__
            );
            // Проверка на успешное удаление записей
            if ($dbw->affectedRows() == 0) {
                $logger->error( 'onPageSaveComplete: applyNewRevisionToFile - could not insert new revision data into the change_tag table (section - else)' );
                throw new Exception(wfMessage( 'vcsystem-apply-new-revision-to-file-insert-new-revision-into-change_tag-error' )->text());
            }

        }
        return true;
    }
}