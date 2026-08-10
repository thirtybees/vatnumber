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
 * Cron endpoint. Re-checks VAT numbers that were accepted provisionally
 * while VIES was down, and re-verifies old 'valid' numbers.
 *
 * Call from a scheduled task, for example hourly:
 *   index.php?fc=module&module=vatnumber&controller=cron&token=...
 * The exact URL including the token is shown on the module's configuration
 * page.
 */
class VatnumberCronModuleFrontController extends ModuleFrontController
{
    /** Numbers checked per run, keeps the run short and respects VIES rate limits. */
    const BATCH_SIZE = 20;

    /**
     * @return void
     */
    public function initContent()
    {
        header('Content-Type: application/json');

        $token = (string) Tools::getValue('token');
        $expected = (string) Configuration::get('VATNUMBER_CRON_TOKEN');
        if ($expected === '' || !hash_equals($expected, $token)) {
            header('HTTP/1.1 403 Forbidden');
            die(json_encode(['error' => 'Invalid token']));
        }

        try {
            $summary = VatNumber::runBackgroundChecks(static::BATCH_SIZE);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber cron: run failed: '.$e->getMessage(), 3);
            header('HTTP/1.1 500 Internal Server Error');
            die(json_encode(['error' => $e->getMessage()]));
        }

        die(json_encode($summary));
    }
}
