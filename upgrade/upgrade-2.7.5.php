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
 * v2.7.5: audit fixes.
 *
 * Schema: columns id_address (missing on 2.7.0 snapshots), id_customer,
 * valid_request_id and valid_date, plus the status_date index. Each change
 * is applied only when it is missing, so the script is safe to re-run.
 *
 * Configuration: the home country gets a default, and the module wide
 * values (cron token, cron state, VIES breaker) move to the global scope.
 *
 * @param VatNumber $module
 *
 * @return bool
 * @throws PrestaShopException
 */
function upgrade_module_2_7_5($module)
{
    // Make sure the table exists before probing it; a shop that lost it
    // gets it back with the complete current schema.
    $result = $module->executeSqlScript('install');

    if (!VatNumber::checkTableHasColumn('id_address')) {
        $result = $module->executeSqlScript('upgrade-2.7.5-id-address') && $result;
    }
    if (!VatNumber::checkTableHasColumn('id_customer')) {
        $result = $module->executeSqlScript('upgrade-2.7.5-id-customer') && $result;
    }
    if (!VatNumber::checkTableHasColumn('valid_request_id')) {
        $result = $module->executeSqlScript('upgrade-2.7.5-valid-proof') && $result;
    }
    if (!VatNumber::checkTableHasIndex('status_date')) {
        $result = $module->executeSqlScript('upgrade-2.7.5-status-index') && $result;
    }

    if ((int) Configuration::get('VATNUMBER_COUNTRY') === 0) {
        $result = Configuration::updateValue('VATNUMBER_COUNTRY', (int) Configuration::get('PS_COUNTRY_DEFAULT')) && $result;
    }

    foreach (['VATNUMBER_CRON_TOKEN', 'VATNUMBER_CRON_LAST_RUN', 'VATNUMBER_VIES_DOWN_UNTIL'] as $key) {
        if ((string) VatNumber::getGlobal($key) === '' && (string) Configuration::get($key) !== '') {
            $result = VatNumber::setGlobal($key, Configuration::get($key)) && $result;
        }
    }
    if ((string) VatNumber::getGlobal('VATNUMBER_CRON_TOKEN') === '') {
        $result = VatNumber::setGlobal('VATNUMBER_CRON_TOKEN', VatNumber::generateCronToken()) && $result;
    }

    $result = $module->registerHook('displayAdminOrder') && $result;

    if ((string) Configuration::get('VATNUMBER_CHECKING') === '0') {
        PrestaShopLogger::addLog('vatnumber upgrade 2.7.5: automatic VAT number checking is switched off in the settings; numbers are not verified against VIES and rejected numbers are not detected. Switch it on unless that is intended.', 2);
    }

    return $result;
}
