<?php

namespace VCSystem\api;

use ApiBase;
use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\MediaWikiServices;

class ApiCreateBranch extends ApiBase
{
    public function execute()
    {

        $actionType = $this->getMain()->getVal('actionType');

        if ($actionType === 'validate') {
            $this->validateBranch();
        } elseif ($actionType === 'create') {
            $this->createBranch();
        } else {
            // Неверное значение actionType
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-new-branch-unknown-action')->text()]);
        }
    }

    private function validateBranch() {

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        // экземпляр LoadBalancer
        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        // соединение с основной базой данных
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        $user = $this->getUser();

        if (!$user->isAllowed('applychangetags')) {
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-new-branch-no-rights-to-create')->text()]);
            return false;
        }

        $branchName = $this->getMain()->getVal('name');

        if (strlen($branchName) < 3 || strlen($branchName) > 10) {
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-new-branch-required-chars-number')->text()]);
            return false;
        }

        if (!preg_match('/^[\w-]{3,10}$/i', $branchName)) {
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-new-branch-allowed-chars')->text()]);
            return false;
        }

        $exists = $dbw->selectField(
            'change_tag_def', // имя таблицы
            'ctd_name', // поле, которое мы хотим получить
            ['ctd_name' => $branchesDBPrefix.$branchName], // условие
            __METHOD__
        );

        if ($exists) {
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-new-branch-already-taken-branch-name')->text()]);
            return false;
        }

        $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 1, 'message' => '']);

        return true;
    }

    private function createBranch() {

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        // экземпляр LoadBalancer
        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        // соединение с основной базой данных
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        $user = $this->getUser();

        if (!$user->isAllowed('applychangetags')) {
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-new-branch-no-rights-to-create')->text()]);
            return false;
        }

        $branchName = $this->getMain()->getVal('name');

        if (strlen($branchName) < 3 || strlen($branchName) > 10) {
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-new-branch-required-chars-number')->text()]);
            return false;
        }

        if (!preg_match('/^[\w-]{3,10}$/i', $branchName)) {
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-new-branch-allowed-chars')->text()]);
            return false;
        }

        $exists = $dbw->selectField(
            'change_tag_def', // имя таблицы
            'ctd_name', // поле, которое мы хотим получить
            ['ctd_name' => $branchesDBPrefix.$branchName], // условие
            __METHOD__
        );

        if ($exists) {
            $this->getResult()->addValue(null, $this->getModuleName(), ['isSuccess' => 0, 'message' => wfMessage('vcsystem-new-branch-already-taken-branch-name')->text()]);
            return false;
        }

        // Добавляем тег в БД
        $dbw->insert(
            'change_tag_def',
            [
                'ctd_name' => $branchesDBPrefix.$branchName,
                'ctd_user_defined' => 1
            ],
            __METHOD__
        );

        $ctId = $dbw->insertId();

        $dbw->insert('vcsystem_main', [
            'owner_usr_id' => $user->getId(),
            'ctd_id' => $ctId,
            'vc_timestamp' => wfTimestampNow(),
            'isActive' => true
        ]);

        $result = ['IsSuccess' => 1, 'branchName' => $branchName];
        $this->getResult()->addValue(null, $this->getModuleName(), $result);

        return true;

    }

    public function isWriteMode() {
        return true;
    }

    public function mustBePosted() {
        return true;
    }

    public function getAllowedParams() {
        return [
            'name' => [
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => true,
                ApiBase::PARAM_HELP_MSG => 'vcsystem-apihelp-createbranch-field-name-rules',
            ],
            'actionType' => [
                ParamValidator::PARAM_TYPE => ['validate', 'create'],
                ParamValidator::PARAM_REQUIRED => true,
                ParamValidator::PARAM_ISMULTI => false,
            ],
        ];
    }


    public function getExamplesMessages() {
        return [
            'action=createbranch&name=testbranch' => 'vcsystem-apihelp-createbranch',
        ];
    }

}