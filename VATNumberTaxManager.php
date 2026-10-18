<?php
/**
 * Copyright (C) 2017-2026 thirty bees
 * Copyright (C) 2007-2016 PrestaShop SA
 *
 * thirty bees is an extension to the PrestaShop software by PrestaShop SA.
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
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017-2026 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark of PrestaShop SA.
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

require_once __DIR__.'/vatnumber.php';

class VATNumberTaxManager implements TaxManagerInterface
{
    /**
     * Decide whether this address gets the 0% VAT treatment. This is the
     * last line of defence: it repeats the offline checks, because a number
     * can reach the database without passing validateNumber() (imports,
     * webservice, back office saves), and it fails closed whenever the
     * module is not configured enough to decide.
     *
     * @param Address $address
     * @return bool
     * @throws PrestaShopException
     */
    public static function isAvailableForThisAddress(Address $address)
    {
        if (empty($address->vat_number) || !Configuration::get('VATNUMBER_MANAGEMENT')) {
            return false;
        }

        // Without a configured home country the domestic rule cannot be
        // applied; exempting nobody is the safe side.
        $homeCountry = (int) Configuration::get('VATNUMBER_COUNTRY');
        if ($homeCountry === 0 || (int) $address->id_country === $homeCountry) {
            return false;
        }

        // Shape, country match and the legacy sentinel, offline.
        if (!VatNumber::isPlausibleForAddress($address)) {
            return false;
        }

        $status = VatNumberCheck::getStatus(VatNumberCheck::normalize($address->vat_number));

        // A number VIES actively rejected never gets the exemption, in any
        // configuration.
        if ($status === VatNumberCheck::STATUS_INVALID) {
            return false;
        }

        if (Configuration::get('VATNUMBER_CHECKING')) {
            // A number nobody has looked at yet is not exempt; the cron
            // queues and checks it within the hour.
            if ($status === null) {
                return false;
            }
            // Strict mode: only VIES confirmed numbers are exempt. Numbers
            // still awaiting verification keep paying VAT until the cron
            // confirms them.
            if (Configuration::get('VATNUMBER_REQUIRE_VALIDATION')) {
                return $status === VatNumberCheck::STATUS_VALID;
            }
        }

        return true;
    }

    /**
     * @return TaxCalculator
     * @throws PrestaShopException
     */
    public function getTaxCalculator()
    {
        $tax = new Tax();
        $tax->rate = 0;

        return new TaxCalculator([$tax]);
    }
}
