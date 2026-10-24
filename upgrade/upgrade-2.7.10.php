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
 * v2.7.10: rejected numbers page, domestic check and removal settings.
 *
 * Creates the hidden admin page for the rejected numbers list, sets both
 * new settings to off (existing behaviour), and links rejected rows that
 * were queued without an address to the address carrying the number, so
 * the list can name the customer right away.
 *
 * @param VatNumber $module
 *
 * @return bool
 * @throws PrestaShopException
 */
function upgrade_module_2_7_10(VatNumber $module): bool
{
    $result = $module->installTab();
    if (Configuration::get('VATNUMBER_CHECK_DOMESTIC') === false) {
        $result = Configuration::updateValue('VATNUMBER_CHECK_DOMESTIC', 0) && $result;
    }
    if (Configuration::get('VATNUMBER_CLEAR_REJECTED') === false) {
        $result = Configuration::updateValue('VATNUMBER_CLEAR_REJECTED', 0) && $result;
    }

    $rows = Db::getInstance()->executeS(
        (new DbQuery())
            ->select('`id_vatnumber_check`, `vat_number`')
            ->from('vatnumber_check')
            ->where('`status` = \''.pSQL(VatNumberCheck::STATUS_INVALID).'\'')
            ->where('`id_address` = 0')
    );
    if ($rows === false) {
        throw new PrestaShopException('vatnumber upgrade 2.7.10: failed to read rejected numbers: '.Db::getInstance()->getMsgError());
    }
    $linked = 0;
    foreach ($rows as $row) {
        $address = VatNumberCheck::findPrimaryAddress((string) $row['vat_number']);
        if (!$address) {
            continue;
        }
        $updated = Db::getInstance()->update(
            'vatnumber_check',
            ['id_address' => $address['id_address'], 'id_customer' => $address['id_customer']],
            '`id_vatnumber_check` = '.(int) $row['id_vatnumber_check']
        );
        if (!$updated) {
            throw new PrestaShopException('vatnumber upgrade 2.7.10: failed to link rejected number '.(int) $row['id_vatnumber_check'].': '.Db::getInstance()->getMsgError());
        }
        $linked++;
    }
    if ($linked) {
        PrestaShopLogger::addLog('vatnumber upgrade 2.7.10: linked '.$linked.' rejected number(s) to the address that carries them', 1);
    }

    return $result;
}
