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
 * v2.7.4: the checkbox based exemption without a VAT number is removed,
 * own VAT number setting (consultation IDs), how-to page in the back
 * office, self healing verification queue.
 *
 * Addresses still carrying the old checkbox sentinel lose it here; those
 * customers pay VAT again unless they provide a VAT number. The count is
 * logged so the merchant knows who is affected.
 *
 * @param VatNumber $module
 *
 * @return bool
 * @throws PrestaShopException
 */
function upgrade_module_2_7_4($module)
{
    $result = $module->installTab();

    // Candidates by a cheap LIKE, the exact match (any spelling or spacing
    // of the sentinel) by the same rule the runtime uses.
    $rows = Db::getInstance()->executeS(
        (new DbQuery())
            ->select('id_address, vat_number')
            ->from('address')
            ->where('vat_number LIKE \'%xempt%\'')
    );
    if ($rows === false) {
        throw new PrestaShopException('vatnumber upgrade 2.7.4: failed to read addresses: '.Db::getInstance()->getMsgError());
    }
    $flagged = [];
    foreach ($rows as $row) {
        if (VatNumberCheck::isLegacySentinel($row['vat_number'])) {
            $flagged[] = (int) $row['id_address'];
        }
    }
    if ($flagged) {
        if (!Db::getInstance()->update('address', ['vat_number' => ''], 'id_address IN ('.implode(', ', $flagged).')')) {
            throw new PrestaShopException('vatnumber upgrade 2.7.4: failed to clear the legacy exemption flag from addresses: '.Db::getInstance()->getMsgError());
        }
        PrestaShopLogger::addLog('vatnumber upgrade 2.7.4: the checkbox exemption was removed; cleared its flag from '.count($flagged).' address(es). Those customers now pay VAT unless they provide a valid VAT number.', 2);
    }

    $result = Configuration::deleteByName('VATNUMBER_MANUAL') && $result;
    if (Configuration::get('VATNUMBER_CHECKING') === false) {
        $result = Configuration::updateValue('VATNUMBER_CHECKING', 1) && $result;
    }
    if (Configuration::get('VATNUMBER_OWN_NUMBER') === false) {
        $result = Configuration::updateValue('VATNUMBER_OWN_NUMBER', '') && $result;
    }

    return $result;
}
