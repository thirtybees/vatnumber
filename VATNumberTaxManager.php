<?php
/**
 * Copyright (C) 2017-2024 thirty bees
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
 * @copyright 2017-2024 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark of PrestaShop SA.
 */

require_once __DIR__.'/vatnumber.php';

class VATNumberTaxManager implements TaxManagerInterface
{
    /**
     * @param Address $address
     * @return bool
     * @throws PrestaShopException
     */
    public static function isAvailableForThisAddress(Address $address)
    {
        return (!empty($address->vat_number)
            && ($address->id_country != Configuration::get('VATNUMBER_COUNTRY')
                || $address->vat_number === VatNumber::VAT_EXEMPTION_FLAG)
            && Configuration::get('VATNUMBER_MANAGEMENT')
        );
    }

    /**
     * @return TaxCalculator
     * @throws Exception
     */
    public function getTaxCalculator()
    {
        // If the address matches the european vat number criterias no taxes are applied
        $tax = new Tax();
        $tax->rate = 0;

        return new TaxCalculator([$tax]);
    }
}
