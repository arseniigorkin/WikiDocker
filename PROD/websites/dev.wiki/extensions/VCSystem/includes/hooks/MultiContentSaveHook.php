<?php
namespace VCSystem\hooks;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RenderedRevision;
use MediaWiki\User\UserIdentity;
use CommentStoreComment;
use Status;

class MultiContentSaveHook {

    // Перед тем, как страница записана
    public static function onMultiContentSave(
        RenderedRevision $renderedRevision,
        UserIdentity $user,
        CommentStoreComment $summary,
        $flags,
        Status $hookStatus
    ) {
        global $wgRequest;

        $config = MediaWikiServices::getInstance()->getMainConfig();
        $branchesDBPrefix = $config->get( 'vcsPrefix' );

        // Получаем значение куки
        $tagFromCookie = $wgRequest->getCookie('brnch', 'vcs_');

        if (!$tagFromCookie) {
            $errorMessage = wfMessage('vcsystem-branch-not-selected')->text();
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $hookStatus->fatal($errorMessage);
            return false;
        }

        // Проверка на соответствие формату имени тега
        if (!preg_match('/^[\w-]{3,10}$/i', $tagFromCookie)) {
            $errorMessage = wfMessage('vcsystem-branch-invalid-name')->text();
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $hookStatus->fatal($errorMessage);

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
            $errorMessage = wfMessage('vcsystem-branch-not-found')->text();
            $wgRequest->response()->clearCookie('brnch', ['prefix' => 'vcs_']);
            $hookStatus->fatal($errorMessage);

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
            $hookStatus->fatal($errorMessage);

            return false;
        }

        return true;
    }
}

