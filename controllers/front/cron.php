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
 * while VIES was down, re-verifies old 'valid' numbers, and queues numbers
 * found on addresses that were never checked.
 *
 * Call from a scheduled task, for example hourly. The exact URL including
 * the token is shown on the module's configuration page.
 *
 * This controller overrides run() completely: the job must also work while
 * the shop is in maintenance mode, must not be redirected to HTTPS mid-call,
 * and needs none of the session or template machinery of a normal front
 * office page. The module-inactive check of ModuleFrontController's
 * constructor still applies.
 */
class VatnumberCronModuleFrontController extends ModuleFrontController
{
    /** Seconds that must pass between two runs. */
    const MIN_RUN_INTERVAL = 60;

    /**
     * @return void
     * @throws PrestaShopException
     */
    public function run()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, private');

        $token = Tools::getValue('token');
        $expected = (string) VatNumber::getGlobal('VATNUMBER_CRON_TOKEN');
        if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
            $this->respond(403, ['error' => 'Invalid token']);
        }

        // One run at a time. A second caller gets told to come back later
        // instead of doubling the VIES traffic on the same rows. The lock
        // name carries the database and prefix: MySQL advisory locks are
        // server wide, and several shops may share one server.
        $lockName = 'vatnumber_cron_'.md5(_DB_NAME_.'|'._DB_PREFIX_);
        $lock = (int) Db::getInstance()->getValue('SELECT GET_LOCK(\''.pSQL($lockName).'\', 0)');
        if (!$lock) {
            $this->respond(429, ['skipped' => 'Another run is in progress']);
        }

        // A token gated batch job may legitimately outlive the request time
        // limit of a web page; runBackgroundChecks() has its own budget.
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        // No respond() before the lock release below: PHP does not run
        // finally blocks on exit, so the response is built first and sent
        // last.
        $httpCode = 200;
        $body = null;
        try {
            try {
                $lastRun = (int) VatNumber::getGlobal('VATNUMBER_CRON_LAST_RUN');
                if ((time() - $lastRun) < static::MIN_RUN_INTERVAL) {
                    $httpCode = 429;
                    $body = ['skipped' => 'Ran less than '.static::MIN_RUN_INTERVAL.' seconds ago'];
                } else {
                    // Optional &limit= override, clamped by runBackgroundChecks
                    // to CRON_MAX_BATCH_SIZE. Lets a merchant drain a large
                    // queue faster than the default batch.
                    $limit = (int) Tools::getValue('limit');
                    $body = VatNumber::runBackgroundChecks($limit > 0 ? $limit : null);
                }
            } catch (Exception $e) {
                // Log the full cause; the HTTP response stays generic, the
                // caller is a scheduler, not a debugger.
                PrestaShopLogger::addLog('vatnumber cron: run failed: '.$e->getMessage(), 3);
                $httpCode = 500;
                $body = ['error' => 'Run failed, see the shop log for details'];
            }
        } finally {
            try {
                Db::getInstance()->getValue('SELECT RELEASE_LOCK(\''.pSQL($lockName).'\')');
            } catch (Exception $e) {
                // The connection is gone; MySQL releases the lock with it.
                PrestaShopLogger::addLog('vatnumber cron: lock release failed: '.$e->getMessage(), 2);
            }
        }

        $this->respond($httpCode, $body);
    }

    /**
     * Send a JSON response and stop.
     *
     * @param int $httpCode
     * @param array<string,mixed>|null $body
     *
     * @return void
     */
    protected function respond(int $httpCode, ?array $body): void
    {
        http_response_code($httpCode);
        $json = json_encode($body);
        die($json !== false ? $json : '{"error":"Response encoding failed"}');
    }
}
