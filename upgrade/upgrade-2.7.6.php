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
 * v2.7.6: re-audit fixes.
 *
 * Schema: the customer_date index gets its own guard, the single column
 * status index that fresh installs no longer create is dropped, and the
 * table is (re)created first so the probes below always have something
 * to look at.
 *
 * Data: rows that were already VIES confirmed get their proof columns
 * (valid_request_id, valid_date) filled from the existing consultation ID
 * and check date, so a later rejection cannot erase the evidence for
 * orders placed under that confirmation.
 *
 * @param VatNumber $module
 *
 * @return bool
 * @throws PrestaShopException
 */
function upgrade_module_2_7_6(VatNumber $module): bool
{
    $result = $module->executeSqlScript('install');

    if (!VatNumber::checkTableHasIndex('customer_date')) {
        $result = $module->executeSqlScript('upgrade-2.7.6-customer-index') && $result;
    }
    if (VatNumber::checkTableHasIndex('status')) {
        $result = $module->executeSqlScript('upgrade-2.7.6-drop-status-index') && $result;
    }

    $backfill = 'UPDATE `'._DB_PREFIX_.'vatnumber_check`'
        .' SET valid_request_id = vies_request_id, valid_date = date_upd'
        .' WHERE status = \''.pSQL(VatNumberCheck::STATUS_VALID).'\''
        .' AND source = \''.pSQL(VatNumberCheck::SOURCE_VIES).'\''
        .' AND valid_date IS NULL';
    if (!Db::getInstance()->execute($backfill)) {
        throw new PrestaShopException('vatnumber upgrade 2.7.6: failed to backfill verification proof: '.Db::getInstance()->getMsgError());
    }

    return $result;
}
