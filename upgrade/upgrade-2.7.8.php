<?php
/**
 * Copyright (C) 2017-2026 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2017-2026 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * v2.7.8: the how-to menu entry gets its name in every language instead of
 * the English name in all of them.
 *
 * @param VatNumber $module
 *
 * @return bool
 * @throws PrestaShopException
 */
function upgrade_module_2_7_8(VatNumber $module): bool
{
    $idTab = (int) Tab::getIdFromClassName('AdminVatNumberHowTo');
    if (!$idTab) {
        return $module->installTab();
    }
    $tab = new Tab($idTab);
    $tab->name = VatNumber::getHowToTabNames();

    return (bool) $tab->update();
}
