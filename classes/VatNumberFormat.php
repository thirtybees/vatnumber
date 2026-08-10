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
 * Offline VAT number format validation.
 *
 * One regular expression per VIES country code, describing the officially
 * published VAT number format of that member state. This runs without any
 * network access and catches the vast majority of invalid entries (typos)
 * before VIES is ever contacted.
 */
class VatNumberFormat
{
    /**
     * Patterns keyed by VIES country code (the two letter number prefix, so
     * Greece is 'EL' and Northern Ireland is 'XI'). The pattern describes the
     * part of the number after the prefix, uppercase, without spaces or dots.
     *
     * @var string[]
     */
    protected static $patterns = [
        'AT' => 'U[0-9]{8}',
        'BE' => '[01][0-9]{9}',
        'BG' => '[0-9]{9,10}',
        'CY' => '[0-9]{8}[A-Z]',
        'CZ' => '[0-9]{8,10}',
        'DE' => '[0-9]{9}',
        'DK' => '[0-9]{8}',
        'EE' => '[0-9]{9}',
        'EL' => '[0-9]{9}',
        'ES' => '[A-Z0-9][0-9]{7}[A-Z0-9]',
        'FI' => '[0-9]{8}',
        'FR' => '[A-Z0-9]{2}[0-9]{9}',
        'HR' => '[0-9]{11}',
        'HU' => '[0-9]{8}',
        'IE' => '[0-9]{7}[A-Z]{1,2}|[0-9][A-Z+*][0-9]{5}[A-Z]',
        'IT' => '[0-9]{11}',
        'LT' => '[0-9]{9}|[0-9]{12}',
        'LU' => '[0-9]{8}',
        'LV' => '[0-9]{11}',
        'MT' => '[0-9]{8}',
        'NL' => '[0-9]{9}B[0-9]{2}',
        'PL' => '[0-9]{10}',
        'PT' => '[0-9]{9}',
        'RO' => '[0-9]{2,10}',
        'SE' => '[0-9]{12}',
        'SI' => '[0-9]{8}',
        'SK' => '[0-9]{10}',
        'XI' => '[0-9]{9}|[0-9]{12}|(GD|HA)[0-9]{3}',
    ];

    /**
     * Validate the format of a VAT number for a given country. Purely local,
     * no network access.
     *
     * @param string $countryCode VIES country code, e.g. 'NL' or 'EL'.
     * @param string $number Number part after the country prefix, already
     *                       normalized (uppercase, alphanumeric only).
     *
     * @return bool
     */
    public static function isValid($countryCode, $number)
    {
        $countryCode = strtoupper((string) $countryCode);
        if (!isset(static::$patterns[$countryCode])) {
            return false;
        }

        return (bool) preg_match('/^(?:'.static::$patterns[$countryCode].')$/', (string) $number);
    }
}
