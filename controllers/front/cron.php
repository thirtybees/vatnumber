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
 * Call from a scheduled task, for example hourly. The exact URL including
 * the token is shown on the module's configuration page.
 *
 * This controller overrides run() completely: the job must also work while
 * the shop is in maintenance mode, must not be redirected to HTTPS mid-call,
 * and needs none of the session or template machinery of a normal front
 * office page.
 */
class VatnumberCronModuleFrontController extends ModuleFrontController
{
    /** Seconds that must pass between two runs. */
    const MIN_RUN_INTERVAL = 60;

    /**
     * @return void
     */
    public function run()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, private');

        $token = Tools::getValue('token');
        $expected = (string) Configuration::get('VATNUMBER_CRON_TOKEN');
        if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            die(json_encode(['error' => 'Invalid token']));
        }

        // One run at a time. A second caller gets told to come back later
        // instead of doubling the VIES traffic on the same rows.
        $lock = (int) Db::getInstance()->getValue('SELECT GET_LOCK(\'vatnumber_cron\', 0)');
        if (!$lock) {
            http_response_code(429);
            die(json_encode(['skipped' => 'Another run is in progress']));
        }

        try {
            $lastRun = (int) Configuration::get('VATNUMBER_CRON_LAST_RUN');
            if ((time() - $lastRun) < static::MIN_RUN_INTERVAL) {
                http_response_code(429);
                die(json_encode(['skipped' => 'Ran less than '.static::MIN_RUN_INTERVAL.' seconds ago']));
            }

            try {
                $summary = VatNumber::runBackgroundChecks();
            } catch (Exception $e) {
                // Log the full cause; the HTTP response stays generic, the
                // caller is a scheduler, not a debugger.
                PrestaShopLogger::addLog('vatnumber cron: run failed: '.$e->getMessage(), 3);
                http_response_code(500);
                die(json_encode(['error' => 'Run failed, see the shop log for details']));
            }

            die(json_encode($summary));
        } finally {
            Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'vatnumber_cron\')');
        }
    }
}
