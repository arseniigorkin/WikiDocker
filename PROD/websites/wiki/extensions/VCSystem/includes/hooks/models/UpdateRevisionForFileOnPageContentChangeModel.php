<?php
namespace VCSystem\hooks\models;

class UpdateRevisionForFileOnPageContentChangeModel
{
    public static $isNeedJob; // Индиактор задачи. Если true - значит было изменение контента на странице файла без изменения самого файла
    public static $tagId;
    public static $revisionRecord;
    public static $wikiPage;
    public static $tagData;

    // Сеттеры
    public static function setIsNeedJob($object) {
        self::$isNeedJob = $object;
    }
    public static function setTagId($object) {
        self::$tagId = $object;
    }
    public static function setRevisionRecord($object) {
        self::$revisionRecord = $object;
    }
    public static function setWikiPage($object) {
        self::$wikiPage = $object;
    }
    public static function setTagData($object) {
        self::$tagData = $object;
    }

    // Геттеры
    public static function getIsNeedJob() {
        return self::$isNeedJob;
    }
    public static function getTagId() {
        return self::$tagId;
    }
    public static function getRevisionRecord() {
        return self::$revisionRecord;
    }
    public static function getWikiPage() {
        return self::$wikiPage;
    }
    public static function getTagData() {
        return self::$tagData;
    }
}