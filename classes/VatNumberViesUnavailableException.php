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
    /** @var string VIES error code (for example MS_MAX_CONCURRENT_REQ), empty when there was none. */
    protected $viesCode;

    /**
     * @param string $message
     * @param string $viesCode VIES error code, empty when the failure had none
     *                         (network error, HTTP error, unreadable answer).
     */
    public function __construct(string $message, string $viesCode = '')
    {
        parent::__construct($message);
        $this->viesCode = $viesCode;
    }

    /**
     * @return string VIES error code, empty when there was none.
     */
    public function getViesCode(): string
    {
        return $this->viesCode;
    }

    /**
     * True when VIES answered that it is too busy right now: too many
     * requests at the same time, for one member state or for VIES as a
     * whole. Waiting a moment and asking again usually works.
     *
     * @return bool
     */
    public function isBusy(): bool
    {
        return strpos($this->viesCode, 'MAX_CONCURRENT_REQ') !== false;
    }

    /**
     * True when the problem lies with the database of one member state
     * (busy or down). Other countries can still be checked, and the SOAP
     * interface would ask the same database.
     *
     * @return bool
     */
    public function isMemberStateProblem(): bool
    {
        return strpos($this->viesCode, 'MS_') === 0;
    }
}
