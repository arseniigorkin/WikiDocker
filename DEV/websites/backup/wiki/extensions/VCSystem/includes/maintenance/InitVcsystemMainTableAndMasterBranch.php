<?php

namespace VCSystem\Maintenance;

use MediaWiki\MediaWikiServices;

class InitVcsystemMainTableAndMasterBranch {

    public static function doVcsystemMainTableAndMasterBranchDBUpdates() {

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        if(!$dbw->tableExists('vcsystem_main')){
            // Здесь код для создания таблицы, используя SQL файл
            $sourceFile = dirname(dirname(__DIR__)) . '/sql/vcsystem_main.sql';
            $dbw->sourceFile($sourceFile);
        }

        //TODO: добавить логику версий миграции при помощи регистрации их в таблице updatelog

        $masterBranchName = "Master";

        // Проверка на существование записи Master ветки в таблице change_tag_def
        $exists = $dbw->selectField(
            'change_tag_def',
            'ctd_id',
            [ 'ctd_name' => $branchesDBPrefix.$masterBranchName ],
            __METHOD__
        );

        if (!$exists) {
            $dbw->insert(
                'change_tag_def',
                [ 'ctd_name' => $branchesDBPrefix.$masterBranchName ],
                __METHOD__
            );
            $ctdId = $dbw->insertId();
        } else {
            $ctdId = $exists;
        }

        //Вставляем информацию о Мастер ветке в vcsystem_main
        $dbw->insert(
            'vcsystem_main',
            [
                'user_id' => 0,
                'owner_usr_id' => 0,
                'ctd_id' => $ctdId,
                'vc_timestamp' => wfTimestampNow(),
                'isActive' => 1
            ],
            __METHOD__
        );

        return true;
    }
}
