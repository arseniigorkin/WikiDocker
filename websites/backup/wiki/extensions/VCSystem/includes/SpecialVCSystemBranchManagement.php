<?php
namespace VCSystem;

use Exception;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use Title;

class SpecialVCSystemBranchManagement extends SpecialPage {
    public function __construct() {
        // Название служебной страницы
        parent::__construct('VCSystemBranches');
    }

    public function execute($subPage)
    {

        $out = $this->getOutput();
//        $out->setPageTitle($this->msg('vcsystem-specialpage-branch-management-moniker'));
        $out->addModules('ext.VCSystem.vcsbranchmanagement'); // загрузится внизу

        // Начало вывода
        $out = $this->getOutput();
        $this->setHeaders();

        $user = $this->getUser();
        if (!$user->isAllowed('vcs-manage-branches-permission')) {  // проверяем разрешение
            $out->showErrorPage('vcsystem-specialpage-branch-management-permissionerror-title', 'vcsystem-specialpage-branch-management-nopermissiontoexecute');
            return false;
        }

        global $wgRequest;

        $loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $loadBalancer->getConnection(DB_PRIMARY);

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        // Обработка запроса на изменения статуса через GET
        $branchNameGET = $wgRequest->getVal('toggleBranch');
        if ($branchNameGET && !preg_match('/^[\w-]{3,10}$/i', $branchNameGET)){
            $out->showErrorPage('vcsystem-specialpage-branch-management-invalidparam-title', 'vcsystem-specialpage-branch-management-incorrect-or-empty');
            return false;
        }
        else if ($branchNameGET && preg_match('/^[\w-]{3,10}$/i', $branchNameGET)) {

            if ($branchNameGET === 'Master'){
                $out->showErrorPage('vcsystem-specialpage-branch-management-prohibited-to-toggle-the-master-title', 'vcsystem-specialpage-branch-management-prohibited-to-toggle-the-master');
                return false;
            }

            // Получаем ID и isActive для данного тега
            $res_tag_data = $dbw->selectRow(
                [ 'vcs' => 'vcsystem_main', 'ctd' => 'change_tag_def' ],
                [ 'vcs.isActive', 'vcs.ctd_id' ],
                ['ctd.ctd_name' => $branchesDBPrefix.$branchNameGET],
                __METHOD__,
                [],
                [
                    'ctd' => [ 'INNER JOIN', 'vcs.ctd_id = ctd.ctd_id' ]
                ]
            );

            if ($res_tag_data === false) {
                $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-could-not-read-from-db' )->text() . 'vcsystem_main</div>';
                $out->addHTML($errorMessage);
                return false;
            }

            // Важная заметка на полях:
            // Почему нельзя удалять записи с этим тегом: потому, что может оказаться, что ревизия только дна существует для данного тега и придётся перелопатить
            // много документов, чтобы корректно её удалить. Это может быть опасно тем, что может где-то что-то нарушиться. Лучше просто отключить тег и всё.
            try {
                $dbw->startAtomic( __METHOD__ );

                $newValue = $res_tag_data->isActive == 1 ? false : true;
                $dbw->update(
                    'vcsystem_main',
                    [ 'isActive' => $newValue ],
                    [ 'ctd_id' => $res_tag_data->ctd_id ],
                    __METHOD__
                );

                $dbw->endAtomic( __METHOD__ );
            } catch (Exception $e) {
                $dbw->rollback( __METHOD__ );
                $out->addHTML('<div class="errorbox">' . wfMessage( 'vcsystem-db-error', $e->getMessage() )->text() . '</div>');
                return false;
            }

            // Перебрасываем снова на страницу настроек, но без query string
            $thisSpecialPageTitle = $this->getPageTitle();
            $url = $thisSpecialPageTitle->getFullURL();

            $out = $this->getOutput();
            $out->redirect($url);
        } // конец обработки запроса GET

        // Получаем пары ID и имя всех тегов из vcsystem_main таблицы.
        $res_vc_all_tags = $dbw->select(
            [ 'vcs' => 'vcsystem_main', 'ctd' => 'change_tag_def' ],
            [ 'ctd.ctd_name', 'vcs.isActive' ],
            [],
            __METHOD__,
            [],
            [
                'ctd' => [ 'INNER JOIN', 'vcs.ctd_id = ctd.ctd_id' ]
            ]
        );

        $vc_all_tags = [];
        foreach ( $res_vc_all_tags as $row ) {
            $vc_all_tags[$row->ctd_name] = $row->isActive;
        }

        if (empty($vc_all_tags)) {
            $errorMessage = '<div class="errorbox">' . wfMessage( 'vcsystem-could-not-read-from-db' )->text() . 'vcsystem_main</div>';
            $out->addHTML($errorMessage);
            return false;
        }

        $out->addHTML('</tbody>');
        $out->addHTML('</table>');

        $out->addHTML('<h2>' . wfMessage('vcsystem-specialpage-branch-management-table-title')->text() . '</h2>');
        $out->addHTML('<table class="table-striped" style="width:100%;">');
        $out->addHTML('<thead>');
        $out->addHTML('<tr>');
        $out->addHTML('<th style="width:90%; min-width:300px;">' . wfMessage('vcsystem-specialpage-branch-management-table-column-title')->text() . '</th>');
        $out->addHTML('<th style="width:10%; min-width:200px; text-align: center;">' . wfMessage('vcsystem-specialpage-branch-management-pages-table-column-actions')->text() . '</th>');
        $out->addHTML('</tr>');
        $out->addHTML('</thead>');
        $out->addHTML('<tbody>');

        foreach ($vc_all_tags as $tageName => $isActive){

            $actionButton =  '<button class="btn disabled">' . wfMessage('vcsystem-specialpage-branch-management-no-actions-instead-of-button')->text() . '</button>';

            // Удаляем префикс для вывода на экран
            if (strpos($tageName, $branchesDBPrefix) === 0) {
                $tageName = substr($tageName, strlen($branchesDBPrefix));
            }

            if($tageName != "Master") {
                if ($isActive == 1) {
                    $actionButton = '<button class="btn btn-danger vcs-toggle-branch" data-branch="' . $tageName . '">' . wfMessage('vcsystem-specialpage-branch-management-deactivate-branch-button-label')->text() . '</button>';
                } else {
                    $actionButton = '<button class="btn btn-success vcs-toggle-branch" data-branch="' . $tageName . '">' . wfMessage('vcsystem-specialpage-branch-management-reactivate-branch-button-label')->text() . '</button>';
                }
            }

            $out->addHTML('<tr>');
            $out->addHTML('<td><code>' . $tageName . '</code>');
            $out->addHTML('</td>');
            $out->addHTML('<td style="text-align: center;">');
            $out->addHTML($actionButton);
            $out->addHTML('</td>');
            $out->addHTML('</tr>');
        }

        $out->addHTML('</tbody>');
        $out->addHTML('</table>');

        return true;
    }
}
