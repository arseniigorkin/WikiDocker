<?php

namespace VCSystem\hooks;

use Html;
use OOUI;
use Skin;

class SkinAfterPortletHook {
    public static function onSkinAfterPortlet(Skin $skin, $portletName, &$html) {
        if ($portletName !== 'navigation') { // Пример использования портлета "Инструменты"
            return true;
        }

        $user = $skin->getUser();
        if (!$user->isAllowed('read')) {
            return false;
        }
        $sectionTitle = Html::element('a', [
            'class' => 'nav-link disabled',
            'role' => 'button',
        ], 'VCSystem');

        $branchSelectContainer = Html::rawElement(
            'div',
            [
                'id' => 'branchSelectContainer',
                'style' => 'display: inline-block; width: 150px; vertical-align: middle;'
            ]
        );

        $branchSelectButton = Html::element('button', [
            'id' => 'selectBranchBtn',
            'class' => 'btn btn-success',
            'type' => 'submit',
            'disabled' => true
        ], 'Перейти');

        $branchSelectorAndSwitch = Html::rawElement('div', ['class' => 'vcsystem-flex-container'],
            $branchSelectContainer . $branchSelectButton
        );


        $branchValidationMsg = Html::element('span', [
            'id' => 'getBranchesValidatorMsg',
            'class' => 'text-danger mt-2',
            'style' => 'display: none;'
        ]);

        // Управление только для администратора VCSystem (vcsadmin) с правами vcs-merge-versions-permission

        if ($user->isAllowed('vcs-merge-versions-permission')) {  // проверяем разрешение

            $editSwtichContainer = Html::rawElement(
                    'div',
                    [
                        'id' => 'vcseditswitchcontainer',
                        'style' => 'display: inline-block; vertical-align: middle;'
                    ]
                ) . Html::rawElement(
                    'span',
                    [
                        'style' => 'margin-left: 10px; vertical-align: middle;'
                    ],
                    wfMessage('vcsystem-edit-mode-switch-descr')->text()
                );

            $editSwtichContainer = '<br />'.$editSwtichContainer;


            $branchInput = Html::rawElement('div', ['class' => 'input-group mt-2'],
                Html::element('input', [
                    'type' => 'text',
                    'id' => 'branchNameInput',
                    'class' => 'form-control',
                    'placeholder' => wfMessage('vcsystem-new-branch-enter-new-branch-name-placeholder')->text(),
                    'maxlength' => '10',  // Максимальное количество символов: 10
                    'size' => '20'       // Видимая ширина поля: 10 символов
                ]) .
                Html::rawElement('div', ['class' => 'input-group-append'],
                    Html::element('button', [
                        'id' => 'addBranchBtn',
                        'class' => 'btn btn-success',
                        'type' => 'submit'
                    ], '+')
                )
            );

            $newbranchValidationMsg = Html::element('span', [
                'id' => 'newBranchValidationMsg',
                'class' => 'text-danger mt-2',
                'style' => 'display: none;'
            ]);
        }

        // Создаем ссылку
        $settingsLink = Html::element('a', [
            'href' => '/index.php/Special:VCSystemSettings',
            'title' => wfMessage('vcsystem-open-vcsystem-special-settings-page-link-title')->text(),
            'style' => 'font-weight: bold; color: var(--bs-success); text-align: center;'
        ], wfMessage('vcsystem-open-vcsystem-special-settings-page-link-text')->text());

        $settingsLink = '<br />'. $settingsLink;

        $combinedHtml = Html::rawElement('div', [
            'id' => 'VCSystemBranchElements',
            'style' => 'display: inline-block;
                        background-color: whitesmoke;
                        padding: 15px;
                        border-radius: 7px;
                        width: 100%;'
        ], $branchSelectorAndSwitch  . $editSwtichContainer . $branchValidationMsg . $branchInput . $newbranchValidationMsg . $settingsLink);

        $html .= $sectionTitle . $combinedHtml;

        return true;
    }
}

