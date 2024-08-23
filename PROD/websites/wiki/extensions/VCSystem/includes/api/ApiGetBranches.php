<?php

namespace VCSystem\api;
use ApiBase;
use MediaWiki\MediaWikiServices;

class ApiGetBranches extends ApiBase {

    public function execute() {

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

//        $dbPrefix = $GLOBALS['wgDBprefix'];

        $res = $dbw->select(
            [
                'vm' => 'vcsystem_main',
                'ctd' => 'change_tag_def'],
            ['ctd_name'],
            ['vm.isActive != 0'],
            __METHOD__,
            [],
            [
                'ctd' => ['JOIN', ['vm.ctd_id='.'ctd.ctd_id']]
            ]
        );

        $branches = [];
        foreach ($res as $row) {
            $branchName = $row->ctd_name;

            // Если $row->ctd_name начинается с $branchesDBPrefix, обрезаем его
            if (strpos($branchName, $branchesDBPrefix) === 0) {
                $branchName = substr($branchName, strlen($branchesDBPrefix));
            }

            $branches[] = ['branchname' => $branchName];
        }

        if ($res->numRows() === 0) { // Изменена проверка
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-get-branches-nothing-found')->text()]);
            return;
        }

        // Формирование вывода
        $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 1, 'entities' => $branches]);
    }

    public function getAllowedParams() {
        return [];
    }

    public function mustBePosted() {
        return true;
    }
}


