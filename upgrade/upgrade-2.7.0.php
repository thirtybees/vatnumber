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
    if (!VatNumber::getGlobal('VATNUMBER_CRON_TOKEN')) {
        $result = VatNumber::setGlobal('VATNUMBER_CRON_TOKEN', VatNumber::generateCronToken()) && $result;
    }

    // Queue every stored number in bulk (chunked reads, one INSERT IGNORE
    // per thousand rows), so an older shop with thousands of numbers does
    // not time out here. The cron verifies them in batches afterwards.
    try {
        $queued = VatNumberCheck::queueMissing(VatNumberCheck::getAddressNumbers(null), PHP_INT_MAX);
        PrestaShopLogger::addLog('vatnumber upgrade 2.7.0: queued '.$queued.' stored VAT number(s) for verification', 1);
    } catch (Exception $e) {
        // Not fatal: the scheduled task queues missing numbers on every run.
        PrestaShopLogger::addLog('vatnumber upgrade 2.7.0: seeding skipped: '.$e->getMessage(), 3);
    }

    return $result;
}
