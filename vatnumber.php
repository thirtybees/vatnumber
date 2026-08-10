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
    const VAT_EXEMPTION_FLAG = 'vatExemption';

    /**
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'vatnumber';
        $this->tab = 'billing_invoicing';
        $this->version = '2.7.0';
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
        $this->tb_versions_compliancy = '>= 1.0.8';
        $this->tb_min_version = '1.0.8';
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
            Configuration::updateValue('VATNUMBER_MANAGEMENT', 1) &&
            Configuration::updateValue('VATNUMBER_REQUIRE_VALIDATION', 0) &&
            Configuration::updateValue('VATNUMBER_CRON_TOKEN', Tools::passwdGen(16))
        );
    }

    /**
     * Execute one of the SQL scripts in sql/. Placeholders PREFIX_ and
     * ENGINE_TYPE get replaced with the shop's actual values.
     *
     * @param string $script Script name without path and extension.
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function executeSqlScript($script)
    {
        $file = __DIR__.'/sql/'.$script.'.sql';
        $content = file_get_contents($file);
        if ($content === false) {
            throw new PrestaShopException('Failed to read SQL script: '.$file);
        }
        $content = str_replace(['PREFIX_', 'ENGINE_TYPE'], [_DB_PREFIX_, _MYSQL_ENGINE_], $content);
        foreach (array_filter(array_map('trim', explode(';', $content))) as $statement) {
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
               && Configuration::deleteByName('VATNUMBER_CRON_TOKEN');
    }

    /**
     * @param bool $forceAll
     *
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function enable($forceAll = false)
    {
        parent::enable($forceAll);
        Configuration::updateValue('VATNUMBER_MANAGEMENT', 1);
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
            'IE' => 'IE', //Irland
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
                return array_key_exists($isoCode, static::getPrefixIntracomVAT());
            }
        }
        return false;
    }

    /**
     * Test wether a given number is a valid one. Validation tests largely
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
        /*
         * Handle the VAT exemption flag. In case we also have a VAT number,
         * we use that. Else we set vat_number to a standard value.
         *
         * Using this standard value avoids adding a boolean field
         * 'vat_exemption' to the Address class, keeping compatibility with
         * older versions.
         */
        if (Tools::getValue('vat_exemption') && $address->vat_number == '') {
            $address->vat_number = static::VAT_EXEMPTION_FLAG;
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

                // Check VAT number prefix
                $vatNumberPrefix = strtoupper(substr($vatNumber, 0, 2));
                $vatNumberIsoCode = array_search($vatNumberPrefix, static::getPrefixIntracomVAT());
                if ($vatNumberIsoCode === false) {
                    return Tools::displayError('Invalid VAT number prefix');
                }

                // Country validation with VAT number
                $vatNumberIsoCode = strtoupper($vatNumberIsoCode);
                $isoAddress = strtoupper(Country::getIsoById($countryId));
                if ($isoAddress !== $vatNumberIsoCode) {
                    return Tools::displayError('VAT number inconsistent with country.');
                }

                // Local format validation. Runs offline and catches typos
                // before VIES is ever contacted.
                $normalized = VatNumberCheck::normalize($vatNumber);
                if (!VatNumberFormat::isValid($vatNumberPrefix, substr($normalized, 2))) {
                    return Tools::displayError('VAT number format is invalid for this country.');
                }

                if (Configuration::get('VATNUMBER_CHECKING')) {
                    $errors = static::WebServiceCheck($normalized);
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
     *      network access.
     *   2. Otherwise VIES is asked (REST, strict timeouts).
     *   3. When VIES cannot answer, the number is accepted provisionally,
     *      recorded as 'unverified' and logged. The cron re-checks it later.
     *
     * @param string $vatNumber The VAT number, including country code,
     *                          preferably normalized.
     *
     * @return array Error messages. An empty array means the number is
     *               valid, cached valid, or provisionally accepted.
     *
     * @since 1.0.0
     * @since 2.7.0 Cache and outage fallback.
     */
    public static function WebServiceCheck($vatNumber)
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

            try {
                $result = VatNumberViesClient::checkVat($countryCode, $plainNumber);
            } catch (VatNumberViesUnavailableException $e) {
                // VIES is down. Accept provisionally instead of blocking the
                // customer, remember that verification is still owed and log
                // loudly so the merchant can follow up.
                VatNumberCheck::recordResult($normalized, $countryCode, VatNumberCheck::STATUS_UNVERIFIED, VatNumberCheck::SOURCE_FORMAT);
                PrestaShopLogger::addLog('vatnumber: VIES unavailable, VAT number '.$normalized.' accepted provisionally. Reason: '.$e->getMessage(), 3);

                return [];
            }

            if ($result['valid']) {
                VatNumberCheck::recordResult($normalized, $countryCode, VatNumberCheck::STATUS_VALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier'], $result['name'], $result['address']);

                return [];
            }

            VatNumberCheck::recordResult($normalized, $countryCode, VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier']);

            return [Tools::displayError('VAT number not registered at your tax authorities.')];
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber: VAT number check failed for '.$normalized.': '.$e->getMessage(), 3);

            return [sprintf(Tools::displayError('Failed to validate VAT number: %s'), $e->getMessage())];
        }
    }

    /**
     * True when a VAT number has a VIES confirmed 'valid' record in the
     * local database. Used by the tax manager in strict mode: exemption only
     * after successful verification.
     *
     * @param string $vatNumber
     *
     * @return bool
     * @throws PrestaShopException
     */
    public static function isVerifiedValid($vatNumber)
    {
        $check = VatNumberCheck::findByNumber(VatNumberCheck::normalize($vatNumber));

        return $check && $check->status === VatNumberCheck::STATUS_VALID;
    }

    /**
     * Work through VAT numbers that still need attention from VIES: numbers
     * accepted provisionally during an outage, and valid numbers whose last
     * check has become old (registrations expire). Called by the cron
     * controller.
     *
     * @param int $limit Maximum numbers to check in one run, to respect
     *                   VIES rate limits.
     *
     * @return array Summary: numbers checked, confirmed, rejected, and
     *               whether VIES was reachable.
     *
     * @throws PrestaShopException
     */
    public static function runBackgroundChecks($limit = 20)
    {
        $limit = max(1, (int) $limit);
        $rows = VatNumberCheck::getPending($limit);
        $remaining = $limit - count($rows);
        if ($remaining > 0) {
            $rows = array_merge($rows, VatNumberCheck::getStale($remaining));
        }

        $summary = [
            'checked'   => 0,
            'confirmed' => 0,
            'rejected'  => 0,
            'pending'   => 0,
            'vies_down' => false,
        ];
        $rejected = [];

        foreach ($rows as $row) {
            try {
                $result = VatNumberViesClient::checkVat($row['country_code'], substr($row['vat_number'], 2));
            } catch (VatNumberViesUnavailableException $e) {
                // Still down, or down again. Stop the run, the rest would
                // fail the same way. Numbers stay 'unverified'.
                PrestaShopLogger::addLog('vatnumber cron: VIES unavailable, stopping this run. Reason: '.$e->getMessage(), 2);
                $summary['vies_down'] = true;
                break;
            }

            $summary['checked']++;
            if ($result['valid']) {
                VatNumberCheck::recordResult($row['vat_number'], $row['country_code'], VatNumberCheck::STATUS_VALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier'], $result['name'], $result['address']);
                $summary['confirmed']++;
            } else {
                VatNumberCheck::recordResult($row['vat_number'], $row['country_code'], VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier']);
                $summary['rejected']++;
                $rejected[] = $row['vat_number'];
            }
        }

        if ($rejected) {
            // Severity 3 so merchants with email logging configured get
            // notified: these customers received an exemption that VIES now
            // says was not justified.
            PrestaShopLogger::addLog('vatnumber cron: VIES rejected VAT number(s) '.implode(', ', $rejected).'. Review the orders of these customers, VAT may have to be re-invoiced.', 3);
        }

        $summary['pending'] = VatNumberCheck::countPending();

        return $summary;
    }

    /**
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     * @since 1.0.0
     * @since 2.1.0 Added VATNUMBER_MANUAL handling.
     */
    public function getContent()
    {
        $echo = '';

        if (Tools::isSubmit('SAVE_SETTINGS')) {
            if (Configuration::updateValue('VATNUMBER_COUNTRY', (int) Tools::getValue('VATNUMBER_COUNTRY')) &&
                Configuration::updateValue('VATNUMBER_MANUAL', (bool) Tools::getValue('VATNUMBER_MANUAL')) &&
                Configuration::updateValue('VATNUMBER_CHECKING', (bool) Tools::getValue('VATNUMBER_CHECKING')) &&
                Configuration::updateValue('VATNUMBER_REQUIRE_VALIDATION', (bool) Tools::getValue('VATNUMBER_REQUIRE_VALIDATION'))
            ) {
                $echo = $this->displayConfirmation($this->l('Settings updated successfully.'));
            } else {
                $echo = $this->displayError($this->l('Failed to update settings.'));
            }
        }

        return $echo.$this->renderForm().$this->renderStatusPanel();
    }

    /**
     * Small status panel below the settings: how many numbers still await
     * verification, and the cron URL that works through them.
     *
     * @return string
     * @throws PrestaShopException
     */
    protected function renderStatusPanel()
    {
        $pending = VatNumberCheck::countPending();
        $cronUrl = $this->context->link->getModuleLink(
            'vatnumber',
            'cron',
            ['token' => Configuration::get('VATNUMBER_CRON_TOKEN')]
        );

        $html = '<div class="panel"><h3><i class="icon icon-time"></i> '.$this->l('Background verification').'</h3>';
        if ($pending) {
            $html .= '<p class="alert alert-warning">'.sprintf($this->l('%d VAT number(s) are still awaiting VIES verification (accepted while the service was unreachable).'), $pending).'</p>';
        } else {
            $html .= '<p class="alert alert-success">'.$this->l('No VAT numbers are awaiting verification.').'</p>';
        }
        $html .= '<p>'.$this->l('To verify pending numbers and re-check old ones automatically, call this URL from a scheduled task (for example every hour, via the cronjobs module or your server\'s crontab):').'</p>';
        $html .= '<p><code>'.Tools::safeOutput($cronUrl).'</code></p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @throws PrestaShopException
     * @throws SmartyException
     * @since 1.0.0
     * @since 2.1.0 Added VATNUMBER_MANUAL part.
     */
    public function renderForm()
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

        $settingsForm = [
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
                        'desc'      => $this->l('Enabling this adds a simple checkbox for VAT exemptions. Use this to allow VAT exemptions not related to a VAT number. You\'ll recognize a checked box by reading \''.static::VAT_EXEMPTION_FLAG.'\' in back office in the VAT number field in Customers -> Addresses.'),
                        'values' => [
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
                        ],
                    ],
                    [
                        'type'      => 'switch',
                        'label'     => $this->l('Check VAT number automatically'),
                        'name'      => 'VATNUMBER_CHECKING',
                        'is_bool'   => true,
                        'desc'      => $this->l('This uses VIES, the official web service of the European Community. Confirmed numbers are cached locally, so repeated checks need no network access. On web service outages (they have been seen quite frequently), VAT numbers get accepted provisionally to not disrupt the customer, and get re-checked automatically by the scheduled task below. Such events get logged; if you also want to receive email on such events, set \'Minimum severity level\' in Advanced Parameters -> Logs to 3 or lower.'),
                        'values' => [
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
                        ],
                    ],
                    [
                        'type'      => 'switch',
                        'label'     => $this->l('Exempt only after successful check'),
                        'name'      => 'VATNUMBER_REQUIRE_VALIDATION',
                        'is_bool'   => true,
                        'desc'      => $this->l('Disabled (default): customers get the VAT exemption immediately, even while their number is still awaiting verification. Enabled: customers keep paying VAT until VIES has confirmed their number. Enabled is safer for you, disabled is friendlier for the customer. Only relevant when automatic checking is enabled.'),
                        'values' => [
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
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'name' => 'SAVE_SETTINGS'
                ],
            ],
        ];

        $manualValidationForm = [
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
                        'is_bool'   => true,
                        'desc'      => $this->l('VAT number including country prefix.') . $this->getCheckResult(),
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Check'),
                    'name' => 'CHECK_VATNUMBER_PROCESS'
                ],
            ],
        ];

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
            $helper->generateForm([$settingsForm]) .
            $helper->generateForm([$manualValidationForm])
        );
    }

    /**
     * @throws PrestaShopException
     * @since 2.1.0 Added VATNUMBER_MANUAL handling.
     * @since 1.0.0
     */
    public function getConfigFieldsValues()
    {
        return [
            'VATNUMBER_COUNTRY' => Tools::getValue('VATNUMBER_COUNTRY', Configuration::get('VATNUMBER_COUNTRY')),
            'VATNUMBER_MANUAL' => Tools::getValue('VATNUMBER_MANUAL', Configuration::get('VATNUMBER_MANUAL')),
            'VATNUMBER_CHECKING' => Tools::getValue('VATNUMBER_CHECKING', Configuration::get('VATNUMBER_CHECKING')),
            'VATNUMBER_REQUIRE_VALIDATION' => Tools::getValue('VATNUMBER_REQUIRE_VALIDATION', Configuration::get('VATNUMBER_REQUIRE_VALIDATION')),
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
     *
     * @since 2.1.0
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
     *
     * @todo As soon as we have a suitable hook system (see comment in
     *       Address:validateController()), this should become a hook.
     *
     * @since 2.1.0
     */
    public static function adjustAddressForLayout($address)
    {
        // Don't display the VAT exemption text.
        if (Configuration::get('VATNUMBER_MANAGEMENT')
            && Configuration::get('VATNUMBER_MANUAL')
            && is_object($address)
            && $address->vat_number === static::VAT_EXEMPTION_FLAG
        ) {
            $address->vat_exemption = true;
            $address->vat_number = '';
        }
    }

    /**
     * @return string
     */
    protected function getCheckResult()
    {
        if (Tools::isSubmit('CHECK_VATNUMBER_PROCESS')) {
            $vatnumber = VatNumberCheck::normalize(Tools::getValue('CHECK_VATNUMBER'));
            $prefix = substr($vatnumber, 0, 2);
            if (!$prefix || !in_array($prefix, static::getPrefixIntracomVAT())) {
                return '<p class="alert alert-danger">'.Tools::displayError("Invalid vat number prefix").'</p>';
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
                    foreach ($res as $key => $value) {
                        $ret .= "<b>".Tools::safeOutput($key).":</b>&nbsp;".Tools::safeOutput($value)."<br/>";
                    }
                    $ret .= '</p>';
                    return $ret;
                } else {
                    VatNumberCheck::recordResult($vatnumber, $prefix, VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_VIES, $res['requestIdentifier']);
                    return '<p class="alert alert-warning">'.sprintf($this->l('%s is NOT a valid VAT number'), $vatnumber).'</p>';
                }
            } catch (VatNumberViesUnavailableException $e) {
                return '<p class="alert alert-warning">'.sprintf($this->l('VIES could not be reached (%s). Try again later.'), Tools::safeOutput($e->getMessage())).'</p>';
            } catch (Exception $e) {
                return '<p class="alert alert-danger">'.Tools::safeOutput($e->getMessage()).'</p>';
            }
        }
        return '';
    }

}
