<?php

namespace VCSystem\hooks;

use DatabaseUpdater;

class LoadExtensionSchemaUpdatesHook implements
    \MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook
{
    public function onLoadExtensionSchemaUpdates($updater)
    {
//        $updater->addExtensionTable('vcsystem_main', dirname(dirname(__DIR__)) . '/sql/vcsystem_main.sql');

        $updater->addExtensionUpdate([
            [ 'VCSystem\\Maintenance\\InitVcsystemMainTableAndMasterBranch', 'doVcsystemMainTableAndMasterBranchDBUpdates' ]
        ]);
    }
}
