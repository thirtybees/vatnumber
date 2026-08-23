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
     * Legacy sentinel that versions before 2.7.4 stored in Address->vat_number
     * for the removed checkbox based exemption. Kept only to recognize and
     * reject it: it never grants an exemption anymore.
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
        $this->version = '2.7.4';
        $this->author = 'thirty bees';
        $this->need_instance = true;

        $this->tax_manager_class = 'VATNumberTaxManager';

        $this->bootstrap = true;
        parent::__construct();

        if ((int) Configuration::get('VATNUMBER_COUNTRY') === 0) {
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
            $this->registerHook('displayAdminOrder') &&
            $this->installTab() &&
            Configuration::updateValue('VATNUMBER_MANAGEMENT', 1) &&
            Configuration::updateValue('VATNUMBER_CHECKING', 1) &&
            Configuration::updateValue('VATNUMBER_REQUIRE_VALIDATION', 1) &&
            Configuration::updateValue('VATNUMBER_OWN_NUMBER', '') &&
            Configuration::updateValue('VATNUMBER_VERIFY_SINCE', '') &&
            Configuration::updateValue('VATNUMBER_CRON_TOKEN', static::generateCronToken())
        );
    }

    /**
     * Back office menu entry for the how-to page, under Localization next
     * to Taxes. Idempotent: an existing tab is left alone.
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function installTab()
    {
        if ((int) Tab::getIdFromClassName('AdminVatNumberHowTo')) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = 'AdminVatNumberHowTo';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentLocalization');
        $tab->active = 1;
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'VAT numbers how-to';
        }

        return (bool) $tab->add();
    }

    /**
     * @return bool
     * @throws PrestaShopException
     */
    public function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminVatNumberHowTo');
        if (!$idTab) {
            return true;
        }
        $tab = new Tab($idTab);

        return (bool) $tab->delete();
    }

    /**
     * Translate a string from a static context. Tools::displayError() only
     * consults the core error catalogue, so module strings passed through it
     * can never be translated; this helper uses the module catalogue in
     * translations/, like $this->l() does.
     *
     * @param string $string
     *
     * @return string
     */
    protected static function translate($string)
    {
        return Translate::getModuleTranslation('vatnumber', $string, 'vatnumber');
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
               && $this->uninstallTab()
               && Configuration::deleteByName('VATNUMBER_OWN_NUMBER')
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
     * Panel on the back office order page: the VAT number this order was
     * placed with, its current verification status, and the stored proof.
     * Answers "was this order rightfully VAT exempt?" without leaving the
     * order.
     *
     * @param array $params Hook parameters, contains 'id_order'.
     *
     * @return string
     */
    public function hookDisplayAdminOrder($params)
    {
        try {
            $order = new Order((int) (isset($params['id_order']) ? $params['id_order'] : 0));
            if (!Validate::isLoadedObject($order)) {
                return '';
            }
            $address = new Address((int) $order->id_address_invoice);
            if (!Validate::isLoadedObject($address)) {
                return '';
            }
            $vatNumber = trim((string) $address->vat_number);
            if ($vatNumber === '') {
                return '';
            }

            return $this->renderOrderVatPanel($order, $vatNumber);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber: order panel failed for order '.(int) (isset($params['id_order']) ? $params['id_order'] : 0).': '.$e->getMessage(), 3);

            return '<div class="panel"><h3><i class="icon icon-euro"></i> '.$this->l('VAT exemption').'</h3>'
                .'<p class="alert alert-danger">'.sprintf($this->l('Could not display the VAT status: %s'), Tools::safeOutput($e->getMessage())).'</p></div>';
        }
    }

    /**
     * Build the order page VAT panel.
     *
     * @param Order $order
     * @param string $vatNumber Trimmed VAT number of the invoice address.
     *
     * @return string
     * @throws PrestaShopException
     */
    protected function renderOrderVatPanel($order, $vatNumber)
    {
        $vatCharged = ((float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl) > 0.005;

        $html = '<div class="panel"><h3><i class="icon icon-euro"></i> '.$this->l('VAT exemption').'</h3>';
        $html .= '<p>'.($vatCharged
            ? $this->l('VAT was charged on this order.')
            : $this->l('No VAT was charged on this order (exemption applied).')).'</p>';

        $normalized = VatNumberCheck::normalize($vatNumber);
        $check = VatNumberCheck::findByNumber($normalized);

        if (!$check) {
            $badge = '<span class="label label-default">'.$this->l('Never checked').'</span>';
        } elseif ($check->status === VatNumberCheck::STATUS_VALID) {
            $badge = '<span class="label label-success">'.$this->l('Valid, confirmed by VIES').'</span>';
        } elseif ($check->status === VatNumberCheck::STATUS_INVALID) {
            $badge = '<span class="label label-danger">'.$this->l('Invalid, rejected by VIES').'</span>';
        } else {
            $badge = '<span class="label label-warning">'.$this->l('Awaiting verification').'</span>';
        }

        $html .= '<p><b>'.$this->l('VAT number on this order:').'</b> '.Tools::safeOutput($normalized).' '.$badge.'</p>';

        if ($check) {
            $html .= '<p><b>'.$this->l('Last checked:').'</b> '.Tools::safeOutput($check->date_upd).'</p>';
            if ($check->trader_name !== '') {
                $html .= '<p><b>'.$this->l('Registered trader:').'</b> '.Tools::safeOutput($check->trader_name).'</p>';
            }
            if ($check->vies_request_id !== '') {
                $html .= '<p><b>'.$this->l('VIES consultation ID (proof):').'</b> '.Tools::safeOutput($check->vies_request_id).'</p>';
            }
            if ($check->status === VatNumberCheck::STATUS_INVALID) {
                $html .= '<p class="alert alert-danger">'.$this->l('VIES rejected this number. If this order was exempted, review it and re-invoice the VAT if needed.').'</p>';
            } elseif ($check->status === VatNumberCheck::STATUS_UNVERIFIED) {
                $html .= '<p class="alert alert-warning">'.$this->l('This number was accepted while VIES was unreachable. The scheduled task will verify it; you can also check it now with the validation tool.').'</p>';
            }
        } else {
            $html .= '<p>'.$this->l('This number has never been checked against VIES.').'</p>';
        }

        $configLink = $this->context->link->getAdminLink('AdminModules', true).'&configure='.$this->name;
        $html .= '<p><a href="'.Tools::safeOutput($configLink).'">'.$this->l('Open the module configuration page to check this number by hand.').'</a></p>';
        $html .= '</div>';

        return $html;
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
        $address->vat_number = trim((string) $address->vat_number);

        // The legacy checkbox sentinel is customer typeable. It must never
        // pass as a VAT number.
        if ($address->vat_number === static::VAT_EXEMPTION_FLAG) {
            return static::translate('Invalid VAT number.');
        }

        if (Configuration::get('VATNUMBER_MANAGEMENT')) {
            $vatNumber = $address->vat_number;
            $countryId = (int)$address->id_country;
            $excludedCountry = (int)Configuration::get('VATNUMBER_COUNTRY');
            if ($vatNumber !== '' && $excludedCountry !== $countryId) {

                // Check that company is set
                $company = trim((string)$address->company);
                if ($company === '') {
                    return static::translate('VAT number, but no company name given.');
                }

                // Check the prefix on the normalized number, so that spaces,
                // dots and the common GR-instead-of-EL spelling for Greece
                // do not trip the customer up.
                $normalized = VatNumberCheck::normalize($vatNumber);
                $vatNumberPrefix = substr($normalized, 0, 2);
                $vatNumberIsoCode = array_search($vatNumberPrefix, static::getPrefixIntracomVAT());
                if ($vatNumberIsoCode === false) {
                    return static::translate('Invalid VAT number prefix.');
                }

                // Country validation with VAT number. XI numbers belong to
                // Northern Ireland businesses, whose address country is GB.
                $vatNumberIsoCode = strtoupper($vatNumberIsoCode);
                $isoAddress = strtoupper(Country::getIsoById($countryId));
                $countryMatches = ($isoAddress === $vatNumberIsoCode)
                    || ($vatNumberPrefix === 'XI' && $isoAddress === 'GB');
                if (!$countryMatches) {
                    return static::translate('VAT number inconsistent with country.');
                }

                // Local format validation. Runs offline and catches typos
                // before VIES is ever contacted.
                if (!VatNumberFormat::isValid($vatNumberPrefix, substr($normalized, 2))) {
                    return static::translate('VAT number format is invalid for this country.');
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
                return [static::translate('VAT number not registered at your tax authorities.')];
            }

            if ((int) Configuration::get('VATNUMBER_VIES_DOWN_UNTIL') > time()) {
                // Circuit breaker open: VIES failed very recently, do not
                // send another request, accept provisionally right away.
                static::acceptProvisionally($check, $normalized, $countryCode, $idAddress);

                return [];
            }

            try {
                $result = VatNumberViesClient::checkVat($countryCode, $plainNumber, static::getRequester());
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

            return [static::translate('VAT number not registered at your tax authorities.')];
        } catch (Exception $e) {
            // Log the full cause; show the customer only a generic message.
            // Raw exception text can contain database details.
            PrestaShopLogger::addLog('vatnumber: VAT number check failed for '.$normalized.': '.$e->getMessage(), 3);

            return [static::translate('Failed to validate the VAT number. Please try again later.')];
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
        $verifySince = static::getVerifySince();
        if ($verifySince !== null) {
            try {
                $summary['dropped_old'] = VatNumberCheck::removeInactiveUnverified($verifySince);
            } catch (Exception $e) {
                PrestaShopLogger::addLog('vatnumber cron: dropping inactive numbers failed: '.$e->getMessage(), 3);
            }
        }

        // Numbers on addresses that have no check record yet (stored while
        // checking was off, imported, or otherwise missed) are queued, so
        // the queue heals itself instead of depending on the save hook only.
        $summary['queued'] = 0;
        try {
            $summary['queued'] = VatNumberCheck::queueMissing($verifySince, static::CRON_MAX_BATCH_SIZE * 5);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber cron: queueing missing numbers failed: '.$e->getMessage(), 3);
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
                $result = VatNumberViesClient::checkVat($row['country_code'], substr($row['vat_number'], 2), static::getRequester());
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

        // A plain-words status line, so a run that had nothing to do does
        // not look broken to whoever reads the JSON.
        if ($summary['vies_down']) {
            $summary['message'] = 'VIES was unreachable, the run stopped early; pending numbers are retried next run';
        } elseif ($summary['checked'] === 0 && $summary['pending'] === 0) {
            $summary['message'] = 'Queue empty, nothing to do';
        } else {
            $summary['message'] = sprintf('Checked %d number(s), %d confirmed, %d rejected, %d still pending', $summary['checked'], $summary['confirmed'], $summary['rejected'], $summary['pending']);
        }

        return $summary;
    }

    /**
     * The verification start date from the settings, or null when not set
     * or not a valid date.
     *
     * @return string|null Date in Y-m-d format.
     */
    public static function getVerifySince()
    {
        $date = trim((string) Configuration::get('VATNUMBER_VERIFY_SINCE'));
        if ($date === '' || !Validate::isDate($date)) {
            return null;
        }

        return $date;
    }

    /**
     * The shop's own VAT number as configured, split for the VIES requester
     * fields, or null when not configured. Sending it makes VIES return a
     * consultation ID with every check.
     *
     * @return array|null ['countryCode' => 'NL', 'number' => '123456789B01']
     */
    public static function getRequester()
    {
        $own = VatNumberCheck::normalize(Configuration::get('VATNUMBER_OWN_NUMBER'));
        if (strlen($own) < 4) {
            return null;
        }

        return ['countryCode' => substr($own, 0, 2), 'number' => substr($own, 2)];
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
     * Short introduction at the top of the configuration page, pointing to
     * the full how-to page in the Localization menu.
     *
     * @return string
     * @throws PrestaShopException
     */
    protected function renderExplanationPanel()
    {
        $howToLink = $this->context->link->getAdminLink('AdminVatNumberHowTo', true);

        $html = '<div class="panel"><h3><i class="icon icon-info-circle"></i> '.$this->l('How this module works').'</h3>';
        $html .= '<p>'.$this->l('Business customers in another EU country pay no VAT when they provide a valid VAT number (reverse charge). The customer enters the number on their address, the module validates it (first locally, then against VIES, the official EU database), and prices, cart and invoices for that customer switch to 0% VAT.').'</p>';
        $html .= '<p>'.$this->l('Customers from your own country always pay VAT. Spaces and dots in the number are fine, Greek numbers typed with the GR prefix are treated as EL, and Northern Ireland businesses (XI number, GB address) are supported.').'</p>';
        $html .= '<p><a class="btn btn-default" href="'.Tools::safeOutput($howToLink).'"><i class="icon icon-book"></i> '.$this->l('Read the full how-to: settings, what happens when VIES is down, and where to check a customer').'</a></p>';
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

        $ownNumber = VatNumberCheck::normalize(Tools::getValue('VATNUMBER_OWN_NUMBER'));
        if ($ownNumber !== '') {
            $ownPrefix = substr($ownNumber, 0, 2);
            if (!in_array($ownPrefix, static::getPrefixIntracomVAT()) || !VatNumberFormat::isValid($ownPrefix, substr($ownNumber, 2))) {
                return $this->displayError($this->l('Your own VAT number is not a valid EU VAT number. Enter it with the country prefix, or leave it empty.'));
            }
        }

        $updates = [
            'VATNUMBER_COUNTRY'            => (int) Tools::getValue('VATNUMBER_COUNTRY'),
            'VATNUMBER_CHECKING'           => (bool) Tools::getValue('VATNUMBER_CHECKING'),
            'VATNUMBER_REQUIRE_VALIDATION' => (bool) Tools::getValue('VATNUMBER_REQUIRE_VALIDATION'),
            'VATNUMBER_OWN_NUMBER'         => $ownNumber,
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

        // Apply the start date right away, so the merchant sees its effect
        // instead of waiting for the next cron run.
        if ($verifySince !== '') {
            $dropped = VatNumberCheck::removeInactiveUnverified($verifySince);
            $pending = VatNumberCheck::countPending();

            return $this->displayConfirmation(sprintf(
                $this->l('Settings updated. %d queued number(s) fell outside the verification start date and were removed; %d remain pending.'),
                $dropped,
                $pending
            ));
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
                        'desc'      => $this->l('In EU legislation, this should be the country where the business is located, usually your own country.'),
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
                        'desc'      => $this->l('Enabled (default): customers keep paying VAT until VIES has confirmed their number. Disabled: customers get the VAT exemption immediately, even while their number is still awaiting verification. Enabled is safer for you, disabled is friendlier for the customer. Only relevant when automatic checking is enabled. Numbers that VIES rejected never get the exemption.'),
                        'values'    => $this->getSwitchValues(),
                    ],
                    [
                        'type'      => 'text',
                        'label'     => $this->l('Your own VAT number'),
                        'name'      => 'VATNUMBER_OWN_NUMBER',
                        'desc'      => $this->l('The VAT number of this shop, including country prefix. When set, it is sent along with every VIES check and VIES then returns a consultation ID: the formal proof of the check that tax authorities ask for. Leave empty to check without proof IDs.'),
                    ],
                    [
                        'type'      => 'date',
                        'label'     => $this->l('Only verify numbers used since'),
                        'name'      => 'VATNUMBER_VERIFY_SINCE',
                        'desc'      => $this->l('Date in YYYY-MM-DD format, or empty to verify everything. Queued numbers of customers with no address change and no order since this date are dropped from the verification queue, immediately when you save and again on every scheduled run. Useful after upgrading a shop with years of old addresses. Dropped numbers are simply checked live again if such a customer ever returns.'),
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
                        'desc'      => $this->l('Use this to verify a customer\'s VAT number by hand: for example after a log entry says VIES rejected a number, before you refund or re-invoice VAT, or when a customer claims their number is valid after all. The result is stored in the local cache, so a number confirmed here immediately counts as verified for that customer in the shop.')
                            .'<br/>'
                            .sprintf($this->l('Enter the full number including the two letter country prefix, for example %s. Spaces, dots and dashes are fine. Use EL or GR for Greece and XI for Northern Ireland.'), 'NL822090313B01')
                            .$this->checkResultHtml,
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
            'VATNUMBER_CHECKING' => Tools::getValue('VATNUMBER_CHECKING', Configuration::get('VATNUMBER_CHECKING')),
            'VATNUMBER_REQUIRE_VALIDATION' => Tools::getValue('VATNUMBER_REQUIRE_VALIDATION', Configuration::get('VATNUMBER_REQUIRE_VALIDATION')),
            'VATNUMBER_OWN_NUMBER' => Tools::getValue('VATNUMBER_OWN_NUMBER', (string) Configuration::get('VATNUMBER_OWN_NUMBER')),
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
            $vatDisplay = static::isApplicable((int) Tools::getCountry()) ? 2 : 1;
        }

        $context->smarty->assign([
            'vatnumber_ajax_call' => true,
            'vat_display'         => $vatDisplay,
        ]);
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
            return '<p class="alert alert-danger">'.$this->l('Invalid VAT number prefix.').'</p>';
        }
        if (!VatNumberFormat::isValid($prefix, substr($vatnumber, 2))) {
            return '<p class="alert alert-danger">'.$this->l('VAT number format is invalid for this country.').'</p>';
        }
        try {
            $res = VatNumberViesClient::checkVat($prefix, substr($vatnumber, 2), static::getRequester());
            if ($res['valid']) {
                VatNumberCheck::recordResult($vatnumber, $prefix, VatNumberCheck::STATUS_VALID, VatNumberCheck::SOURCE_VIES, $res['requestIdentifier'], $res['name'], $res['address']);
                $ret = '<p class="alert alert-success">'.sprintf($this->l('%s is a valid VAT number'), $vatnumber).'</p>';
                $ret .= '<p class="well">';
                $ret .= '<b>'.$this->l('Trader name').':</b>&nbsp;'.Tools::safeOutput($res['name']).'<br/>';
                $ret .= '<b>'.$this->l('Trader address').':</b>&nbsp;'.Tools::safeOutput($res['address']).'<br/>';
                if ($res['name'] === '' && $res['address'] === '') {
                    $ret .= '<i>'.$this->l('Some countries (for example Germany) do not disclose trader name and address through VIES. The number is valid all the same.').'</i><br/>';
                }
                $ret .= '<b>'.$this->l('VIES consultation ID').':</b>&nbsp;'.Tools::safeOutput($res['requestIdentifier'] !== '' ? $res['requestIdentifier'] : $this->l('none - set your own VAT number in the settings to receive consultation IDs'));
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
