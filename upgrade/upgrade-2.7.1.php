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
 * v2.7.1: explanation panel in the back office, verification start date
 * setting, adjustable cron batch size.
 *
 * Shops that installed a 2.7.0 development snapshot may miss pieces that
 * later 2.7.0 builds added; everything here is idempotent insurance.
 *
 * @param VatNumber $module
 *
 * @return bool
 * @throws PrestaShopException
 */
function upgrade_module_2_7_1(VatNumber $module): bool
{
    $result = $module->registerHook('actionObjectAddressAddBefore')
        && $module->registerHook('actionObjectAddressUpdateBefore');

    if (Configuration::get('VATNUMBER_VERIFY_SINCE') === false) {
        $result = Configuration::updateValue('VATNUMBER_VERIFY_SINCE', '') && $result;
    }
    if (!Configuration::get('VATNUMBER_CRON_TOKEN')) {
        $result = Configuration::updateValue('VATNUMBER_CRON_TOKEN', VatNumber::generateCronToken()) && $result;
    }

    return $result;
}
