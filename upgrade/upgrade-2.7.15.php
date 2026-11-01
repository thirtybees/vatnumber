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
 * v2.7.15: the order page panel moves from the full width bottom area to
 * the right column and gets a "Check now at VIES" popup. Every profile that
 * may view orders gets the same rights on the module's pages, which core
 * had only granted to SuperAdmin and the installing employee's profile.
 *
 * @param VatNumber $module
 *
 * @return bool
 * @throws PrestaShopException
 */
function upgrade_module_2_7_15(VatNumber $module): bool
{
    $result = $module->registerHook('displayAdminOrderRight');
    if ($module->isRegisteredInHook('displayAdminOrder')) {
        $result = $module->unregisterHook('displayAdminOrder') && $result;
    }
    $result = $module->installTab() && $result;
    foreach (['AdminVatNumberHowTo', 'AdminVatNumberRejected'] as $className) {
        $result = VatNumber::copyTabAccess((int) Tab::getIdFromClassName($className), 'AdminOrders') && $result;
    }

    return (bool) $result;
}
