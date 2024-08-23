<?php

namespace VCSystem\hooks;

use SkinTemplate;

class SkinTemplateNavigationHook {
    public static function onSkinTemplateNavigation_Universal(SkinTemplate $sktemplate, array &$links) {

        $rev_id = $sktemplate->getRequest()->getVal('revisionId');

        if (isset($links['views']['edit'])) {
            $links['views']['edit']['href'] = $links['views']['edit']['href'].'&oldid='.$rev_id.'#editform'; //новая_ссылка_для_править
        }

        if (isset($links['views']['ve-edit'])) {
            $links['views']['ve-edit']['href'] = $links['views']['ve-edit']['href'].'&oldid='.$rev_id.'#editform';//новая_ссылка_для_править VE
        }
    }
}
