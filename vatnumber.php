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

require_once __DIR__.'/classes/VatNumberFormat.php';
require_once __DIR__.'/classes/VatNumberCheck.php';
require_once __DIR__.'/classes/VatNumberViesUnavailableException.php';
require_once __DIR__.'/classes/VatNumberViesClient.php';

/**
 * VatNumber module
 */
class VatNumber extends TaxManagerModule
{
    /**
     * Sentinel stored in Address->vat_number for manual (checkbox based) VAT
     * exemptions. Only the module itself may set it; typed input matching it
     * is rejected unless manual exemptions are enabled.
     */
    const VAT_EXEMPTION_FLAG = 'vatExemption';

    /** VAT numbers checked per cron run, to respect VIES rate limits. */
    const CRON_BATCH_SIZE = 20;

    /**
     * Upper bound for the cron's optional &limit= override. Larger batches
     * drain a big queue faster, but stay polite towards VIES.
     */
    const CRON_MAX_BATCH_SIZE = 100;

    /**
     * Seconds the circuit breaker stays open after a VIES failure. While
     * open, no new VIES requests are made and numbers are accepted
     * provisionally right away, so a struggling VIES is not hammered.
     */
    const VIES_BREAKER_SECONDS = 300;

    /** Hours without a cron run before the config page shows a warning. */
    const CRON_STALE_HOURS = 24;

    /** @var array Controllers this module ships. */
    public $controllers = ['cron'];

    /** @var string HTML result of the manual BO check, set by getContent(). */
    protected $checkResultHtml = '';

    /**
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'vatnumber';
        $this->tab = 'billing_invoicing';
        $this->version = '2.7.1';
        $this->author = 'thirty bees';
        $this->need_instance = true;

        $this->tax_manager_class = 'VATNumberTaxManager';

        $this->bootstrap = true;
        parent::__construct();

        if (!Configuration::get('VATNUMBER_MANUAL')
            && (int) Configuration::get('VATNUMBER_COUNTRY') === 0) {
            $this->warning = $this->l('No default country set.');
        }

        $this->displayName = $this->l('VAT Exemption Module');
        $this->description = $this->l('This module adds handling of VAT exemptions for various tax laws.');
        // The actionObjectAddressValidateController hook this module depends
        // on was introduced in thirty bees 1.6.0. On older cores the module
        // would install but never validate anything.
        $this->tb_versions_compliancy = '>= 1.6.0';
        $this->tb_min_version = '1.6.0';
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function install()
    {
        return (
            parent::install() &&
            $this->executeSqlScript('install') &&
            $this->registerHook('actionObjectAddressValidateController') &&
            $this->registerHook('actionObjectAddressAddBefore') &&
            $this->registerHook('actionObjectAddressUpdateBefore') &&
            Configuration::updateValue('VATNUMBER_MANAGEMENT', 1) &&
            Configuration::updateValue('VATNUMBER_REQUIRE_VALIDATION', 0) &&
            Configuration::updateValue('VATNUMBER_VERIFY_SINCE', '') &&
            Configuration::updateValue('VATNUMBER_CRON_TOKEN', static::generateCronToken())
        );
    }

    /**
     * Generate the secret for the cron endpoint and verify it actually is
     * random: core's passwdGen() silently degrades to a constant string when
     * the system random source fails, which would leave the endpoint open.
     *
     * @return string
     * @throws PrestaShopException
     */
    public static function generateCronToken()
    {
        $token = Tools::passwdGen(32);
        if (strlen($token) !== 32 || count(array_unique(str_split($token))) < 8) {
            throw new PrestaShopException('Failed to generate a secure cron token');
        }

        return $token;
    }

    /**
     * Execute one of the SQL scripts in sql/. Placeholders PREFIX_,
     * ENGINE_TYPE, CHARSET_TYPE and COLLATE_TYPE get replaced with the
     * shop's actual values.
     *
     * @param string $script Script name without path and extension.
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function executeSqlScript($script)
    {
        if (!in_array($script, ['install', 'uninstall'], true)) {
            throw new PrestaShopException('Unknown SQL script: '.$script);
        }
        $file = __DIR__.'/sql/'.$script.'.sql';
        if (!file_exists($file)) {
            throw new PrestaShopException('SQL script not found: '.$file);
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new PrestaShopException('Failed to read SQL script: '.$file);
        }
        $content = str_replace(
            ['PREFIX_', 'ENGINE_TYPE', 'CHARSET_TYPE', 'COLLATE_TYPE'],
            [_DB_PREFIX_, _MYSQL_ENGINE_, 'utf8mb4', 'utf8mb4_unicode_ci'],
            $content
        );
        foreach (array_filter(array_map('trim', preg_split("/;\s*[\r\n]+/", $content))) as $statement) {
            if (!Db::getInstance()->execute($statement)) {
                throw new PrestaShopException('SQL script '.$script.' failed: '.Db::getInstance()->getMsgError());
            }
        }

        return true;
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function uninstall()
    {
        return parent::uninstall()
               && $this->executeSqlScript('uninstall')
               && Configuration::deleteByName('VATNUMBER_CHECKING')
               && Configuration::deleteByName('VATNUMBER_MANUAL')
               && Configuration::deleteByName('VATNUMBER_COUNTRY')
               && Configuration::deleteByName('VATNUMBER_MANAGEMENT')
               && Configuration::deleteByName('VATNUMBER_REQUIRE_VALIDATION')
               && Configuration::deleteByName('VATNUMBER_VERIFY_SINCE')
               && Configuration::deleteByName('VATNUMBER_CRON_TOKEN')
               && Configuration::deleteByName('VATNUMBER_VIES_DOWN_UNTIL')
               && Configuration::deleteByName('VATNUMBER_CRON_LAST_RUN');
    }

    /**
     * @param bool $forceAll
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function enable($forceAll = false)
    {
        return parent::enable($forceAll)
            && Configuration::updateValue('VATNUMBER_MANAGEMENT', 1);
    }

    /**
     * @param bool $forceAll
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function disable($forceAll = false)
    {
        parent::disable($forceAll);
        Configuration::updateValue('VATNUMBER_MANAGEMENT', 0);
    }

    /**
     * Front office address validation. Core collects the returned error
     * strings and blocks the address save with them.
     *
     * @param array $params
     *
     * @return string[]
     * @throws PrestaShopException
     */
    public function hookActionObjectAddressValidateController($params)
    {
        if (isset($params['object'])) {
            $error = static::validateNumber($params['object']);
            if (is_string($error)) {
                return [ $error ];
            }
        }
        return [];
    }

    /**
     * Back office address saves do not pass through validateController(), so
     * without this an employee could store an unchecked VAT number. These
     * Before hooks cannot return errors; throwing blocks the save loudly.
     *
     * @param array $params
     *
     * @return void
     * @throws PrestaShopException
     */
    public function hookActionObjectAddressAddBefore($params)
    {
        $this->validateAddressFromBackOffice($params);
    }

    /**
     * @param array $params
     *
     * @return void
     * @throws PrestaShopException
     */
    public function hookActionObjectAddressUpdateBefore($params)
    {
        $this->validateAddressFromBackOffice($params);
    }

    /**
     * Validate an address on back office saves only. Front office saves are
     * already covered by the ValidateController hook; running twice would
     * double the VIES traffic.
     *
     * These Before hooks cannot block the save, so a failed check is
     * surfaced as a controller error (shown to the employee after the save)
     * and logged. The tax manager independently refuses the exemption for
     * numbers that are invalid or, in strict mode, unconfirmed - so a bad
     * number slipping into the database never grants 0% VAT.
     *
     * @param array $params
     *
     * @return void
     * @throws PrestaShopException
     */
    protected function validateAddressFromBackOffice($params)
    {
        $controller = $this->context->controller;
        if (!($controller instanceof Controller) || $controller->controller_type !== 'admin') {
            return;
        }
        if (!isset($params['object']) || !($params['object'] instanceof Address)) {
            return;
        }
        $address = $params['object'];
        if ($address->deleted) {
            // Address::delete() soft-deletes via update(); a stale VAT
            // number must never make an address undeletable.
            return;
        }
        $error = static::validateNumber($address);
        if (is_string($error)) {
            PrestaShopLogger::addLog('vatnumber: back office save of address '.(int) $address->id.' with a VAT number that fails validation: '.$error, 3);
            if ($controller instanceof AdminController) {
                $controller->errors[] = sprintf($this->l('VAT number check: %s'), $error);
            }
        }
    }

    /**
     * Map of address country ISO code to VIES VAT number prefix.
     *
     * @return string[]
     */
    public static function getPrefixIntracomVAT()
    {
        $intracomArray = [
            'AT' => 'AT', //Austria
            'BE' => 'BE', //Belgium
            'DK' => 'DK', //Denmark
            'FI' => 'FI', //Finland
            'FR' => 'FR', //France
            'FX' => 'FR', //France metropolitaine
            'DE' => 'DE', //Germany
            'GR' => 'EL', //Greece
            'IE' => 'IE', //Ireland
            'IT' => 'IT', //Italy
            'LU' => 'LU', //Luxembourg
            'NL' => 'NL', //Netherlands
            'PT' => 'PT', //Portugal
            'ES' => 'ES', //Spain
            'SE' => 'SE', //Sweden
            'CY' => 'CY', //Cyprus
            'EE' => 'EE', //Estonia
            'HU' => 'HU', //Hungary
            'LV' => 'LV', //Latvia
            'LT' => 'LT', //Lithuania
            'MT' => 'MT', //Malta
            'PL' => 'PL', //Poland
            'SK' => 'SK', //Slovakia
            'CZ' => 'CZ', //Czech Republic
            'SI' => 'SI', //Slovenia
            'RO' => 'RO', //Romania
            'BG' => 'BG', //Bulgaria
            'HR' => 'HR', //Croatia
            'XI' => 'XI' // Northern Ireland
        ];

        return $intracomArray;
    }

    /**
     * Whether the VAT number field applies to a country. GB is included
     * because Northern Ireland businesses have GB addresses but XI VAT
     * numbers under the EU-UK protocol; thirty bees has no separate XI
     * country.
     *
     * @param int $idCountry
     * @return boolean
     * @throws PrestaShopException
     */
    public static function isApplicable($idCountry)
    {
        $idCountry = (int)$idCountry;
        if ($idCountry) {
            $isoCode = Country::getIsoById($idCountry);
            if ($isoCode) {
                return $isoCode === 'GB'
                    || array_key_exists($isoCode, static::getPrefixIntracomVAT());
            }
        }
        return false;
    }

    /**
     * Test whether a given number is a valid one. Validation tests largely
     * depend on the current module configuration. This method works for all
     * configuration modes.
     *
     * @param Address $address The address with the VAT number to verify.
     *
     * @return true|string Boolean true for a valid number. Error string on
     *                     validation failure or an invalid number.
     *
     * @throws PrestaShopException
     */
    public static function validateNumber(AddressCore $address)
    {
        // Store the trimmed form, so later strict comparisons against the
        // exemption sentinel cannot be dodged with padding.
        $address->vat_number = trim((string) $address->vat_number);

        $manualAllowed = Configuration::get('VATNUMBER_MANAGEMENT')
            && Configuration::get('VATNUMBER_MANUAL');

        /*
         * Handle the VAT exemption flag. In case we also have a VAT number,
         * we use that. Else we set vat_number to a standard value.
         *
         * Using this standard value avoids adding a boolean field
         * 'vat_exemption' to the Address class, keeping compatibility with
         * older versions.
         */
        if ($manualAllowed && Tools::getValue('vat_exemption') && $address->vat_number == '') {
            $address->vat_number = static::VAT_EXEMPTION_FLAG;
        }

        /*
         * The sentinel is stored in a customer writable field, so it can
         * also be typed in directly. That equals ticking the exemption
         * checkbox, which is only acceptable while manual exemptions are
         * enabled. Otherwise it would be a self service tax exemption.
         */
        if (trim((string)$address->vat_number) === static::VAT_EXEMPTION_FLAG && !$manualAllowed) {
            return Tools::displayError('Invalid VAT number.');
        }

        if (Configuration::get('VATNUMBER_MANAGEMENT') && !Configuration::get('VATNUMBER_MANUAL')) {
            $vatNumber = trim((string)$address->vat_number);
            $countryId = (int)$address->id_country;
            $excludedCountry = (int)Configuration::get('VATNUMBER_COUNTRY');
            if ($vatNumber !== '' && $vatNumber !== static::VAT_EXEMPTION_FLAG && $excludedCountry !== $countryId) {

                // Check that company is set
                $company = trim((string)$address->company);
                if ($company === '') {
                    return Tools::displayError('VAT number, but no company name given.');
                }

                // Check the prefix on the normalized number, so that spaces,
                // dots and the common GR-instead-of-EL spelling for Greece
                // do not trip the customer up.
                $normalized = VatNumberCheck::normalize($vatNumber);
                $vatNumberPrefix = substr($normalized, 0, 2);
                $vatNumberIsoCode = array_search($vatNumberPrefix, static::getPrefixIntracomVAT());
                if ($vatNumberIsoCode === false) {
                    return Tools::displayError('Invalid VAT number prefix.');
                }

                // Country validation with VAT number. XI numbers belong to
                // Northern Ireland businesses, whose address country is GB.
                $vatNumberIsoCode = strtoupper($vatNumberIsoCode);
                $isoAddress = strtoupper(Country::getIsoById($countryId));
                $countryMatches = ($isoAddress === $vatNumberIsoCode)
                    || ($vatNumberPrefix === 'XI' && $isoAddress === 'GB');
                if (!$countryMatches) {
                    return Tools::displayError('VAT number inconsistent with country.');
                }

                // Local format validation. Runs offline and catches typos
                // before VIES is ever contacted.
                if (!VatNumberFormat::isValid($vatNumberPrefix, substr($normalized, 2))) {
                    return Tools::displayError('VAT number format is invalid for this country.');
                }

                if (Configuration::get('VATNUMBER_CHECKING')) {
                    $errors = static::webServiceCheck($normalized, (int) $address->id);
                    if (count($errors)) {
                        return $errors[0];
                    }
                }
            }
        }

        return true;
    }

    /**
     * Validate a VAT number using the EC's VIES web service, with a local
     * cache and a graceful fallback on service outages.
     *
     * Flow:
     *   1. A VIES confirmed 'valid' result younger than
     *      VatNumberCheck::CACHE_DAYS is served from the local database, no
     *      network access. A recent 'invalid' answer is also served from
     *      cache for a short while, so repeated saves do not hammer VIES.
     *   2. When the circuit breaker is open (VIES failed within the last
     *      VIES_BREAKER_SECONDS), no request is attempted at all.
     *   3. Otherwise VIES is asked (REST first, SOAP fallback).
     *   4. When VIES cannot answer, the number is accepted provisionally,
     *      recorded as 'unverified' and logged. The cron re-checks it later.
     *      An existing 'valid' record is never downgraded by an outage: the
     *      last confirmed state and its consultation ID are kept.
     *
     * @param string $vatNumber The VAT number, including country code.
     * @param int $idAddress Address the number belongs to, if known.
     *
     * @return array Error messages. An empty array means the number is
     *               valid, cached valid, or provisionally accepted.
     *
     * @throws PrestaShopException
     */
    public static function webServiceCheck($vatNumber, $idAddress = 0)
    {
        $normalized = VatNumberCheck::normalize($vatNumber);
        if ($normalized === '') {
            return [];
        }
        $countryCode = substr($normalized, 0, 2);
        $plainNumber = substr($normalized, 2);

        try {
            $check = VatNumberCheck::findByNumber($normalized);
            if ($check && $check->isFreshValid()) {
                return [];
            }
            if ($check && $check->isFreshInvalid()) {
                return [Tools::displayError('VAT number not registered at your tax authorities.')];
            }

            if ((int) Configuration::get('VATNUMBER_VIES_DOWN_UNTIL') > time()) {
                // Circuit breaker open: VIES failed very recently, do not
                // send another request, accept provisionally right away.
                static::acceptProvisionally($check, $normalized, $countryCode, $idAddress);

                return [];
            }

            try {
                $result = VatNumberViesClient::checkVat($countryCode, $plainNumber);
            } catch (VatNumberViesUnavailableException $e) {
                Configuration::updateValue('VATNUMBER_VIES_DOWN_UNTIL', time() + static::VIES_BREAKER_SECONDS);
                static::acceptProvisionally($check, $normalized, $countryCode, $idAddress);
                PrestaShopLogger::addLog('vatnumber: VIES unavailable, VAT number '.$normalized.' accepted provisionally. Reason: '.$e->getMessage(), 3);

                return [];
            } catch (PrestaShopException $e) {
                // Server misconfiguration (no curl, no soap). Never block
                // customers over it, but alert the merchant at the highest
                // severity: checking is effectively disabled until fixed.
                Configuration::updateValue('VATNUMBER_VIES_DOWN_UNTIL', time() + static::VIES_BREAKER_SECONDS);
                static::acceptProvisionally($check, $normalized, $countryCode, $idAddress);
                PrestaShopLogger::addLog('vatnumber: VAT number checking is broken, '.$normalized.' accepted provisionally. Fix this server problem: '.$e->getMessage(), 4);

                return [];
            }

            if ($result['valid']) {
                VatNumberCheck::recordResult($normalized, $countryCode, VatNumberCheck::STATUS_VALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier'], $result['name'], $result['address'], $idAddress);

                return [];
            }

            VatNumberCheck::recordResult($normalized, $countryCode, VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier'], '', '', $idAddress);

            return [Tools::displayError('VAT number not registered at your tax authorities.')];
        } catch (Exception $e) {
            // Log the full cause; show the customer only a generic message.
            // Raw exception text can contain database details.
            PrestaShopLogger::addLog('vatnumber: VAT number check failed for '.$normalized.': '.$e->getMessage(), 3);

            return [Tools::displayError('Failed to validate the VAT number. Please try again later.')];
        }
    }

    /**
     * Record a provisional acceptance during a VIES outage. An existing
     * 'valid' record is left untouched, so an outage never destroys a
     * confirmed verification or its consultation ID.
     *
     * @param VatNumberCheck|null $check Existing record, if any.
     * @param string $normalized
     * @param string $countryCode
     * @param int $idAddress
     *
     * @return void
     * @throws PrestaShopException
     */
    protected static function acceptProvisionally($check, $normalized, $countryCode, $idAddress)
    {
        if ($check && $check->status === VatNumberCheck::STATUS_VALID) {
            return;
        }
        VatNumberCheck::recordResult($normalized, $countryCode, VatNumberCheck::STATUS_UNVERIFIED, VatNumberCheck::SOURCE_FORMAT, '', '', '', $idAddress);
    }

    /**
     * Work through VAT numbers that still need attention from VIES: numbers
     * accepted provisionally during an outage, and valid numbers whose last
     * check has become old (registrations expire). Also purges old check
     * records that no address references anymore. Called by the cron
     * controller.
     *
     * @param int|null $limit Maximum numbers to check in one run, defaults
     *                        to CRON_BATCH_SIZE.
     *
     * @return array Summary: numbers checked, confirmed, rejected, failed,
     *               purged, dropped_old (removed by the verification start
     *               date), still pending, and whether VIES was reachable.
     *
     * @throws PrestaShopException
     */
    public static function runBackgroundChecks($limit = null)
    {
        $limit = min(static::CRON_MAX_BATCH_SIZE, max(1, (int) ($limit === null ? static::CRON_BATCH_SIZE : $limit)));

        $summary = [
            'checked'   => 0,
            'confirmed' => 0,
            'rejected'  => 0,
            'failed'    => 0,
            'purged'    => 0,
            'pending'   => 0,
            'vies_down' => false,
        ];

        // Housekeeping must never abort the verification work.
        try {
            $summary['purged'] = VatNumberCheck::purgeOrphaned();
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber cron: purge failed: '.$e->getMessage(), 3);
        }

        // Optional start date: drop queued numbers of customers that have
        // not been active since then, so the queue stays focused on
        // customers who still order.
        $summary['dropped_old'] = 0;
        $verifySince = trim((string) Configuration::get('VATNUMBER_VERIFY_SINCE'));
        if ($verifySince !== '' && Validate::isDate($verifySince)) {
            try {
                $summary['dropped_old'] = VatNumberCheck::removeInactiveUnverified($verifySince);
            } catch (Exception $e) {
                PrestaShopLogger::addLog('vatnumber cron: dropping inactive numbers failed: '.$e->getMessage(), 3);
            }
        }

        $rows = VatNumberCheck::getPending($limit);
        $remaining = $limit - count($rows);
        if ($remaining > 0) {
            $rows = array_merge($rows, VatNumberCheck::getStale($remaining));
        }

        $rejected = [];
        foreach ($rows as $row) {
            // Numbers that cannot possibly validate (bad prefix or format,
            // e.g. seeded legacy garbage) are settled locally, VIES would
            // only answer INVALID_INPUT anyway.
            $prefix = substr($row['vat_number'], 0, 2);
            if ($row['status'] !== VatNumberCheck::STATUS_VALID
                && !VatNumberFormat::isValid($prefix, substr($row['vat_number'], 2))) {
                try {
                    VatNumberCheck::recordResult($row['vat_number'], $row['country_code'], VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_FORMAT);
                    $summary['rejected']++;
                    $rejected[] = $row['vat_number'];
                } catch (Exception $e) {
                    PrestaShopLogger::addLog('vatnumber cron: saving result for '.$row['vat_number'].' failed: '.$e->getMessage(), 3);
                    $summary['failed']++;
                }
                continue;
            }

            try {
                $result = VatNumberViesClient::checkVat($row['country_code'], substr($row['vat_number'], 2));
            } catch (VatNumberViesUnavailableException $e) {
                // Still down, or down again. Stop the run, the rest would
                // likely fail the same way. Rotate the failing row to the
                // back of the queue: a single number whose member state is
                // down for days must not block all other pending numbers.
                // Only unverified rows are rotated; date_upd on a valid row
                // is the audit timestamp of its last confirmation.
                if ($row['status'] === VatNumberCheck::STATUS_UNVERIFIED) {
                    VatNumberCheck::touchRow((int) $row['id_vatnumber_check']);
                }
                Configuration::updateValue('VATNUMBER_VIES_DOWN_UNTIL', time() + static::VIES_BREAKER_SECONDS);
                PrestaShopLogger::addLog('vatnumber cron: VIES unavailable, stopping this run. Reason: '.$e->getMessage(), 2);
                $summary['vies_down'] = true;
                break;
            } catch (Exception $e) {
                PrestaShopLogger::addLog('vatnumber cron: check of '.$row['vat_number'].' failed: '.$e->getMessage(), 3);
                $summary['failed']++;
                continue;
            }

            $summary['checked']++;
            try {
                if ($result['valid']) {
                    VatNumberCheck::recordResult($row['vat_number'], $row['country_code'], VatNumberCheck::STATUS_VALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier'], $result['name'], $result['address']);
                    $summary['confirmed']++;
                } else {
                    VatNumberCheck::recordResult($row['vat_number'], $row['country_code'], VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier']);
                    $summary['rejected']++;
                    $rejected[] = $row['vat_number'];
                }
            } catch (Exception $e) {
                PrestaShopLogger::addLog('vatnumber cron: saving result for '.$row['vat_number'].' failed: '.$e->getMessage(), 3);
                $summary['failed']++;
            }
        }

        if ($rejected) {
            // Severity 3 so merchants with email logging configured get
            // notified: these customers received an exemption that VIES now
            // says was not justified.
            $shown = array_slice($rejected, 0, 20);
            $message = 'vatnumber cron: VIES rejected VAT number(s) '.implode(', ', $shown);
            if (count($rejected) > count($shown)) {
                $message .= sprintf(' and %d more', count($rejected) - count($shown));
            }
            $message .= '. Review the orders of these customers, VAT may have to be re-invoiced.';
            PrestaShopLogger::addLog($message, 3);
        }

        $summary['pending'] = VatNumberCheck::countPending();
        Configuration::updateValue('VATNUMBER_CRON_LAST_RUN', time());

        return $summary;
    }

    /**
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function getContent()
    {
        $echo = '';

        if (Tools::isSubmit('SAVE_SETTINGS')) {
            $echo = $this->processSaveSettings();
        }
        $this->checkResultHtml = '';
        if (Tools::isSubmit('CHECK_VATNUMBER_PROCESS')) {
            $this->checkResultHtml = $this->processVatNumberCheck();
        }

        return $echo.$this->renderExplanationPanel().$this->renderForm().$this->renderStatusPanel();
    }

    /**
     * Explanation panel at the top of the configuration page: what the
     * module does and how it behaves, so the merchant does not need the
     * external documentation for day to day use.
     *
     * @return string
     */
    protected function renderExplanationPanel()
    {
        $html = '<div class="panel"><h3><i class="icon icon-info-circle"></i> '.$this->l('How this module works').'</h3>';

        $html .= '<p>'.$this->l('Business customers in another EU country pay no VAT when they provide a valid VAT number (reverse charge). The customer enters the number on their address, the module validates it (first locally, then against VIES, the official EU database), and prices, cart and invoices for that customer switch to 0% VAT.').'</p>';
        $html .= '<p>'.$this->l('Customers from your own country always pay VAT. Spaces and dots in the number are fine, Greek numbers typed with the GR prefix are treated as EL, and Northern Ireland businesses (XI number, GB address) are supported.').'</p>';

        $html .= '<h4>'.$this->l('When VIES is down').'</h4>';
        $html .= '<ul>';
        $html .= '<li>'.$this->l('The number is accepted provisionally (its format was already checked) and marked as awaiting verification. The customer is never blocked.').'</li>';
        $html .= '<li>'.$this->l('For 5 minutes no new VIES requests are sent, so your server IP does not get rate limited.').'</li>';
        $html .= '<li>'.$this->l('The scheduled task below re-checks the number later. If VIES then rejects it, you get a log entry and the exemption stops for future orders; orders already placed must be reviewed and re-invoiced with VAT yourself.').'</li>';
        $html .= '<li>'.$this->l('Want these events by email? Set the minimum severity level to 3 in Advanced Parameters > Logs.').'</li>';
        $html .= '</ul>';

        $html .= '<h4>'.$this->l('Good to know').'</h4>';
        $html .= '<ul>';
        $html .= '<li>'.$this->l('When VIES provides a consultation ID with a check, it is stored as proof towards the tax authorities. VIES does not provide this ID with every request.').'</li>';
        $html .= '<li>'.$this->l('Uninstalling the module deletes the check results table, including that proof. Make a database backup first.').'</li>';
        $html .= '<li>'.$this->l('Confirmed numbers are served from the local cache for 30 days and re-verified every 180 days, because VAT registrations expire.').'</li>';
        $html .= '<li>'.$this->l('You can check any number by hand with the validation tool at the bottom of this page.').'</li>';
        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Save the settings form. Reports the exact keys that failed instead of
     * a generic error, and never skips remaining settings after a failure.
     *
     * @return string Confirmation or error HTML.
     * @throws PrestaShopException
     */
    protected function processSaveSettings()
    {
        $verifySince = trim((string) Tools::getValue('VATNUMBER_VERIFY_SINCE'));
        if ($verifySince !== '' && !Validate::isDate($verifySince)) {
            return $this->displayError($this->l('The verification start date must be a valid date (YYYY-MM-DD), or empty to verify all numbers.'));
        }

        $updates = [
            'VATNUMBER_COUNTRY'            => (int) Tools::getValue('VATNUMBER_COUNTRY'),
            'VATNUMBER_MANUAL'             => (bool) Tools::getValue('VATNUMBER_MANUAL'),
            'VATNUMBER_CHECKING'           => (bool) Tools::getValue('VATNUMBER_CHECKING'),
            'VATNUMBER_REQUIRE_VALIDATION' => (bool) Tools::getValue('VATNUMBER_REQUIRE_VALIDATION'),
            'VATNUMBER_VERIFY_SINCE'       => $verifySince,
        ];
        $failed = [];
        foreach ($updates as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                $failed[] = $key;
            }
        }
        if ($failed) {
            return $this->displayError(sprintf($this->l('Failed to update setting(s): %s'), implode(', ', $failed)));
        }

        return $this->displayConfirmation($this->l('Settings updated successfully.'));
    }

    /**
     * Status panel below the settings: how many numbers still await
     * verification, whether the cron actually runs, and the cron URL.
     *
     * @return string
     * @throws PrestaShopException
     */
    protected function renderStatusPanel()
    {
        $pending = VatNumberCheck::countPending();
        $lastRun = (int) Configuration::get('VATNUMBER_CRON_LAST_RUN');
        $cronUrl = $this->context->link->getModuleLink(
            $this->name,
            'cron',
            ['token' => Configuration::get('VATNUMBER_CRON_TOKEN')]
        );

        $html = '<div class="panel"><h3><i class="icon icon-time"></i> '.$this->l('Background verification').'</h3>';
        if ($pending) {
            $html .= '<p class="alert alert-warning">'.sprintf($this->l('%d VAT number(s) are still awaiting VIES verification (accepted while the service was unreachable).'), $pending).'</p>';
        } else {
            $html .= '<p class="alert alert-success">'.$this->l('No VAT numbers are awaiting verification.').'</p>';
        }
        if ($pending && (time() - $lastRun) > static::CRON_STALE_HOURS * 3600) {
            $html .= '<p class="alert alert-danger">'.sprintf($this->l('The verification task has not run in the last %d hours. Numbers will stay unverified until it runs. Set up the URL below as a scheduled task.'), static::CRON_STALE_HOURS).'</p>';
        } elseif ($lastRun) {
            $html .= '<p>'.sprintf($this->l('Last verification run: %s'), Tools::safeOutput(date('Y-m-d H:i:s', $lastRun))).'</p>';
        }
        $verifySince = trim((string) Configuration::get('VATNUMBER_VERIFY_SINCE'));
        if ($verifySince !== '') {
            $html .= '<p>'.sprintf($this->l('Verification start date active: queued numbers of customers inactive since %s are dropped automatically.'), Tools::safeOutput($verifySince)).'</p>';
        }
        $html .= '<p>'.$this->l('To verify pending numbers and re-check old ones automatically, call this URL from a scheduled task (for example every hour, via the cronjobs module or your server\'s crontab):').'</p>';
        $html .= '<p><code>'.Tools::safeOutput($cronUrl).'</code></p>';
        $html .= '<p>'.sprintf($this->l('Each run checks %d numbers. To drain a large queue faster, append &limit=%d to the URL (the maximum per run).'), static::CRON_BATCH_SIZE, static::CRON_MAX_BATCH_SIZE).'</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Shared on/off values block for switch inputs.
     *
     * @return array
     */
    protected function getSwitchValues()
    {
        return [
            [
                'id'    => 'active_on',
                'value' => 1,
                'label' => $this->l('Enabled')
            ],
            [
                'id'    => 'active_off',
                'value' => 0,
                'label' => $this->l('Disabled')
            ],
        ];
    }

    /**
     * Settings form definition.
     *
     * @return array
     * @throws PrestaShopException
     */
    protected function getSettingsForm()
    {
        $countries = Country::getCountries($this->context->language->id);
        $countriesFmt = [
            0 => [
                'id' => 0,
                'name' => $this->l('-- Choose a country --')
            ],
        ];
        foreach ($countries as $country) {
            $countriesFmt[] = [
                'id'    => $country['id_country'],
                'name'  => $country['name'],
            ];
        }

        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                    'icon'  => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type'      => 'select',
                        'label'     => $this->l('Always add VAT for customers from:'),
                        'desc'      => $this->l('In EU legislation, this should be the country where the business is located, usually your own country. Ignored for manual VAT exemption requests.'),
                        'name'      => 'VATNUMBER_COUNTRY',
                        'required'  => false,
                        'default_value' => (int) $this->context->country->id,
                        'options'   => [
                            'query'     => $countriesFmt,
                            'id'        => 'id',
                            'name'      => 'name',
                        ],
                    ],
                    [
                        'type'      => 'switch',
                        'label'     => $this->l('Allow manual verification'),
                        'name'      => 'VATNUMBER_MANUAL',
                        'is_bool'   => true,
                        'desc'      => sprintf($this->l('Enabling this adds a simple checkbox for VAT exemptions. Use this to allow VAT exemptions not related to a VAT number. You\'ll recognize a checked box by reading \'%s\' in back office in the VAT number field in Customers -> Addresses.'), static::VAT_EXEMPTION_FLAG),
                        'values'    => $this->getSwitchValues(),
                    ],
                    [
                        'type'      => 'switch',
                        'label'     => $this->l('Check VAT number automatically'),
                        'name'      => 'VATNUMBER_CHECKING',
                        'is_bool'   => true,
                        'desc'      => $this->l('This uses VIES, the official web service of the European Community. Confirmed numbers are cached locally, so repeated checks need no network access. On web service outages (they have been seen quite frequently), VAT numbers get accepted provisionally to not disrupt the customer, and get re-checked automatically by the scheduled task below. Such events get logged; if you also want to receive email on such events, set \'Minimum severity level\' in Advanced Parameters -> Logs to 3 or lower.'),
                        'values'    => $this->getSwitchValues(),
                    ],
                    [
                        'type'      => 'switch',
                        'label'     => $this->l('Exempt only after successful check'),
                        'name'      => 'VATNUMBER_REQUIRE_VALIDATION',
                        'is_bool'   => true,
                        'desc'      => $this->l('Disabled (default): customers get the VAT exemption immediately, even while their number is still awaiting verification. Enabled: customers keep paying VAT until VIES has confirmed their number. Enabled is safer for you, disabled is friendlier for the customer. Only relevant when automatic checking is enabled. Numbers that VIES rejected never get the exemption, in either mode.'),
                        'values'    => $this->getSwitchValues(),
                    ],
                    [
                        'type'      => 'text',
                        'label'     => $this->l('Only verify numbers used since'),
                        'name'      => 'VATNUMBER_VERIFY_SINCE',
                        'desc'      => $this->l('Date in YYYY-MM-DD format, or empty to verify everything. Queued numbers of customers with no address change and no order since this date are dropped from the verification queue. Useful after upgrading a shop with years of old addresses. Dropped numbers are simply checked live again if such a customer ever returns.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name' => 'SAVE_SETTINGS'
                ],
            ],
        ];
    }

    /**
     * Manual check tool form definition.
     *
     * @return array
     */
    protected function getManualCheckForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('VAT number validation'),
                    'icon'  => 'icon-check'
                ],
                'input' => [
                    [
                        'type'      => 'text',
                        'label'     => $this->l('VAT number'),
                        'name'      => 'CHECK_VATNUMBER',
                        'desc'      => $this->l('VAT number including country prefix.').$this->checkResultHtml,
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Check'),
                    'name' => 'CHECK_VATNUMBER_PROCESS'
                ],
            ],
        ];
    }

    /**
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderForm()
    {
        /** @var AdminController $controller */
        $controller = $this->context->controller;
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitForm';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value'  => $this->getConfigFieldsValues(),
            'languages'     => $controller->getLanguages(),
            'id_language'   => $this->context->language->id
        ];

        return (
            $helper->generateForm([$this->getSettingsForm()]) .
            $helper->generateForm([$this->getManualCheckForm()])
        );
    }

    /**
     * @return array
     * @throws PrestaShopException
     */
    public function getConfigFieldsValues()
    {
        return [
            'VATNUMBER_COUNTRY' => Tools::getValue('VATNUMBER_COUNTRY', Configuration::get('VATNUMBER_COUNTRY')),
            'VATNUMBER_MANUAL' => Tools::getValue('VATNUMBER_MANUAL', Configuration::get('VATNUMBER_MANUAL')),
            'VATNUMBER_CHECKING' => Tools::getValue('VATNUMBER_CHECKING', Configuration::get('VATNUMBER_CHECKING')),
            'VATNUMBER_REQUIRE_VALIDATION' => Tools::getValue('VATNUMBER_REQUIRE_VALIDATION', Configuration::get('VATNUMBER_REQUIRE_VALIDATION')),
            'VATNUMBER_VERIFY_SINCE' => Tools::getValue('VATNUMBER_VERIFY_SINCE', (string) Configuration::get('VATNUMBER_VERIFY_SINCE')),
            'CHECK_VATNUMBER' => Tools::getValue('CHECK_VATNUMBER', ''),
        ];
    }

    /**
     * Assign template vars related to VAT number. Works for all configuration
     * modes.
     *
     * @param Context $context
     *
     * @throws PrestaShopException
     *
     * @todo As soon as we have a suitable hook system (see comment in
     *       Address:validateController()), this should become a hook.
     */
    public static function assignTemplateVars(Context $context)
    {
        $vatDisplay = 0;
        if (Configuration::get('VATNUMBER_MANAGEMENT')) {
            if (Configuration::get('VATNUMBER_MANUAL')) {
                $vatDisplay = 3;
            } elseif (static::isApplicable((int) Tools::getCountry())) {
                $vatDisplay = 2;
            } else {
                $vatDisplay = 1;
            }
        }

        $context->smarty->assign([
            'vatnumber_ajax_call' => true,
            'vat_display'         => $vatDisplay,
        ]);
    }

    /**
     * Adjust an address for layout. This module derives property
     * 'vat_exemption' from other properties, see comment in validateNumber().
     *
     * @param Address $address Alias of the address to display. May be different
     *                         on return. Can be NULL for a new address.
     *
     * @throws PrestaShopException
     *
     * @todo When the updater has learned to do database upgrades it's likely
     *       we want to store the 'vat_exemption' flag directly in the
     *       database, making this method obsolete. This would also make all
     *       the code for finding and calling this method in core obsolete.
     */
    public static function adjustAddressForLayout($address)
    {
        // Don't display the VAT exemption text.
        if (Configuration::get('VATNUMBER_MANAGEMENT')
            && Configuration::get('VATNUMBER_MANUAL')
            && is_object($address)
            && trim((string) $address->vat_number) === static::VAT_EXEMPTION_FLAG
        ) {
            $address->vat_exemption = true;
            $address->vat_number = '';
        }
    }

    /**
     * Run the manual check from the module configuration page and build the
     * result HTML. Performs a live VIES request and stores the result in the
     * local cache, like a front office check would.
     *
     * @return string
     */
    protected function processVatNumberCheck()
    {
        $vatnumber = VatNumberCheck::normalize(Tools::getValue('CHECK_VATNUMBER'));
        $prefix = substr($vatnumber, 0, 2);
        if (!$prefix || !in_array($prefix, static::getPrefixIntracomVAT())) {
            return '<p class="alert alert-danger">'.Tools::displayError('Invalid VAT number prefix.').'</p>';
        }
        if (!VatNumberFormat::isValid($prefix, substr($vatnumber, 2))) {
            return '<p class="alert alert-danger">'.Tools::displayError('VAT number format is invalid for this country.').'</p>';
        }
        try {
            $res = VatNumberViesClient::checkVat($prefix, substr($vatnumber, 2));
            if ($res['valid']) {
                VatNumberCheck::recordResult($vatnumber, $prefix, VatNumberCheck::STATUS_VALID, VatNumberCheck::SOURCE_VIES, $res['requestIdentifier'], $res['name'], $res['address']);
                $ret = '<p class="alert alert-success">'.sprintf($this->l('%s is a valid VAT number'), $vatnumber).'</p>';
                $ret .= '<p class="well">';
                $ret .= '<b>'.$this->l('Trader name').':</b>&nbsp;'.Tools::safeOutput($res['name']).'<br/>';
                $ret .= '<b>'.$this->l('Trader address').':</b>&nbsp;'.Tools::safeOutput($res['address']).'<br/>';
                $ret .= '<b>'.$this->l('VIES consultation ID').':</b>&nbsp;'.Tools::safeOutput($res['requestIdentifier'] !== '' ? $res['requestIdentifier'] : $this->l('none (SOAP fallback)'));
                $ret .= '</p>';
                return $ret;
            } else {
                VatNumberCheck::recordResult($vatnumber, $prefix, VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_VIES, $res['requestIdentifier']);
                return '<p class="alert alert-warning">'.sprintf($this->l('%s is NOT a valid VAT number'), $vatnumber).'</p>';
            }
        } catch (VatNumberViesUnavailableException $e) {
            return '<p class="alert alert-warning">'.sprintf($this->l('VIES could not be reached (%s). Try again later.'), Tools::safeOutput($e->getMessage())).'</p>';
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber: manual check of '.$vatnumber.' failed: '.$e->getMessage(), 3);
            return '<p class="alert alert-danger">'.sprintf($this->l('VAT number check failed: %s'), Tools::safeOutput($e->getMessage())).'</p>';
        }
    }

}
