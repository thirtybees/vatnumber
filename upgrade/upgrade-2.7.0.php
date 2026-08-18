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
 * v2.7.0: local check cache table, REST client instead of SOAP, cron
 * endpoint, strict exemption mode, back office validation hooks.
 *
 * Existing VAT numbers on customer addresses are seeded as 'unverified' so
 * the cron verifies them in the background. Without this, enabling strict
 * mode would silently withdraw the exemption from every existing customer.
 *
 * @param VatNumber $module
 *
 * @return bool
 * @throws PrestaShopException
 */
function upgrade_module_2_7_0($module)
{
    $result = $module->executeSqlScript('install')
        && $module->registerHook('actionObjectAddressAddBefore')
        && $module->registerHook('actionObjectAddressUpdateBefore');

    if (Configuration::get('VATNUMBER_REQUIRE_VALIDATION') === false) {
        $result = Configuration::updateValue('VATNUMBER_REQUIRE_VALIDATION', 0) && $result;
    }
    if (!Configuration::get('VATNUMBER_CRON_TOKEN')) {
        $result = Configuration::updateValue('VATNUMBER_CRON_TOKEN', VatNumber::generateCronToken()) && $result;
    }

    $rows = Db::getInstance()->executeS(
        (new DbQuery())
            ->select('DISTINCT vat_number')
            ->from('address')
            ->where('deleted = 0')
            ->where('vat_number != \'\'')
            ->where('vat_number != \''.pSQL(VatNumber::VAT_EXEMPTION_FLAG).'\'')
    );
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $normalized = VatNumberCheck::normalize($row['vat_number']);
            if (strlen($normalized) < 4) {
                continue;
            }
            try {
                if (!VatNumberCheck::findByNumber($normalized)) {
                    VatNumberCheck::recordResult($normalized, substr($normalized, 0, 2), VatNumberCheck::STATUS_UNVERIFIED, VatNumberCheck::SOURCE_FORMAT);
                }
            } catch (Exception $e) {
                PrestaShopLogger::addLog('vatnumber upgrade: failed to seed '.$normalized.': '.$e->getMessage(), 3);
            }
        }
    }

    return $result;
}
