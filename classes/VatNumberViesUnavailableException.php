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
 * Thrown when the VIES service cannot deliver an answer (network failure,
 * timeout, service outage, member state database down, rate limiting).
 *
 * This is deliberately a distinct exception type: callers must be able to
 * tell "VIES says the number is invalid" apart from "VIES could not be
 * asked". The first is a hard rejection, the second triggers the provisional
 * acceptance fallback.
 *
 * Extends plain Exception, not PrestaShopException: this signal is routine,
 * expected control flow, not a fatal error, and it must never end up on
 * core's fatal error page.
 */
class VatNumberViesUnavailableException extends Exception
{
}
