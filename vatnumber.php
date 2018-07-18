<?php
/**
 * Copyright (C) 2017-2018 thirty bees
 * Copyright (C) 2007-2016 PrestaShop SA
 *
 * thirty bees is an extension to the PrestaShop software by PrestaShop SA.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017-2018 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark of PrestaShop SA.
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

class VatNumber extends TaxManagerModule
{
    const VAT_EXEMPTION_FLAG = 'vatExemption';

    public function __construct()
    {
        $this->name = 'vatnumber';
        $this->tab = 'billing_invoicing';
        $this->version = '2.1.0';
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
        $this->tb_versions_compliancy = '> 1.0.0';
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
               && Configuration::updateValue('VATNUMBER_MANAGEMENT', 1);
    }

    public function uninstall()
    {
        return parent::uninstall()
               && Configuration::deleteByName('VATNUMBER_CHECKING')
               && Configuration::deleteByName('VATNUMBER_MANUAL')
               && Configuration::deleteByName('VATNUMBER_COUNTRY')
               && Configuration::deleteByName('VATNUMBER_MANAGEMENT');
    }

    public function enable($forceAll = false)
    {
        parent::enable($forceAll);
        Configuration::updateValue('VATNUMBER_MANAGEMENT', 1);
    }

    public function disable($forceAll = false)
    {
        parent::disable($forceAll);
        Configuration::updateValue('VATNUMBER_MANAGEMENT', 0);
    }

    public static function getPrefixIntracomVAT()
    {
        $intracomArray = [
            'AT' => 'AT',
            //Austria
            'BE' => 'BE',
            //Belgium
            'DK' => 'DK',
            //Denmark
            'FI' => 'FI',
            //Finland
            'FR' => 'FR',
            //France
            'FX' => 'FR',
            //France métropolitaine
            'DE' => 'DE',
            //Germany
            'GR' => 'EL',
            //Greece
            'IE' => 'IE',
            //Irland
            'IT' => 'IT',
            //Italy
            'LU' => 'LU',
            //Luxembourg
            'NL' => 'NL',
            //Netherlands
            'PT' => 'PT',
            //Portugal
            'ES' => 'ES',
            //Spain
            'SE' => 'SE',
            //Sweden
            'GB' => 'GB',
            //United Kingdom
            'CY' => 'CY',
            //Cyprus
            'EE' => 'EE',
            //Estonia
            'HU' => 'HU',
            //Hungary
            'LV' => 'LV',
            //Latvia
            'LT' => 'LT',
            //Lithuania
            'MT' => 'MT',
            //Malta
            'PL' => 'PL',
            //Poland
            'SK' => 'SK',
            //Slovakia
            'CZ' => 'CZ',
            //Czech Republic
            'SI' => 'SI',
            //Slovenia
            'RO' => 'RO',
            //Romania
            'BG' => 'BG',
            //Bulgaria
            'HR' => 'HR',
            //Croatia
        ];

        return $intracomArray;
    }

    public static function isApplicable($idCountry)
    {
        return (((int) $idCountry && array_key_exists(Country::getIsoById($idCountry), self::getPrefixIntracomVAT())) ? 1 : 0);
    }

    /**
     * Test wether a given number is a valid one. Validation tests largely
     * depend on the current module configuration. This method works for all
     * configuration modes.
     *
     * @param Address $address The address with the VAT number to verify.
     *
     * @return bool|string Boolean true for a valid number. Error string on
     *                     validation failure or an invalid number.
     *
     * @todo As soon as we have a suitable hook system (see comment in
     *       Address:validateController()), this should become a hook.
     *
     * @since 2.1.0
     */
    public static function validateNumber(Address &$address)
    {
        $result = false;

        /*
         * Handle the VAT exemption flag. In case we also have a VAT number,
         * we use that. Else we set vat_number to a standard value.
         *
         * Using this standard value avoids adding a boolean field
         * 'vat_exemption' to the Address class, keeping compatibility with
         * older versions.
         */
        if (Tools::getValue('vat_exemption')
            && $address->vat_number == '') {
            $address->vat_number = static::VAT_EXEMPTION_FLAG;
        }

        if (Configuration::get('VATNUMBER_MANAGEMENT')
            && !Configuration::get('VATNUMBER_MANUAL')) {
            if ($address->company != '') {
                if (Configuration::get('VATNUMBER_CHECKING')) {
                    $errors = static::WebServiceCheck($address->vat_number);
                    if (count($errors)) {
                        $result = $errors[0];
                    } else {
                        $result = true;
                    }
                } else {
                    $result = true;
                }
            } else {
                if ($address->vat_number != '') {
                    $result = Tools::displayError('VAT number, but no company name given.');
                } else {
                    $result = true;
                }
            }
        } else {
            $result = true;
        }

        return $result;
    }

    public static function WebServiceCheck($vatNumber)
    {
        // Retrocompatibility for module version < 2.1.0 (07/2018).
        if (empty($vatNumber)) {
            return [];
        }

        $vatNumber = str_replace(' ', '', $vatNumber);
        $prefix = Tools::substr($vatNumber, 0, 2);
        if (array_search($prefix, self::getPrefixIntracomVAT()) === false) {
            return [ Tools::displayError('Invalid VAT number') ];
        }
        $vat = Tools::substr($vatNumber, 2);
        $url = 'http://ec.europa.eu/taxation_customs/vies/viesquer.do?ms='.urlencode($prefix).'&iso='.urlencode($prefix).'&vat='.urlencode($vat);
        @ini_set('default_socket_timeout', 2);
        for ($i = 0; $i < 3; $i++) {
            if ($pageRes = Tools::file_get_contents($url)) {
                if (preg_match('/invalid VAT number/i', $pageRes)) {
                    @ini_restore('default_socket_timeout');

                    return [Tools::displayError('VAT number not found')];
                } elseif (preg_match('/valid VAT number/i', $pageRes)) {
                    @ini_restore('default_socket_timeout');

                    return [];
                } else {
                    ++$i;
                }
            } else {
                sleep(1);
            }
        }
        @ini_restore('default_socket_timeout');

        return [Tools::displayError('VAT number validation service unavailable')];
    }

    /**
     * @since 1.0.0
     * @since 2.1.0 Added VATNUMBER_MANUAL handling.
     */
    public function getContent()
    {
        $echo = '';

        if (Tools::isSubmit('submitVatNumber')) {
            if (Configuration::updateValue('VATNUMBER_COUNTRY',
                                           (int) Tools::getValue('VATNUMBER_COUNTRY'))
                && Configuration::updateValue('VATNUMBER_MANUAL',
                                              (bool) Tools::getValue('VATNUMBER_MANUAL'))
                && Configuration::updateValue('VATNUMBER_CHECKING',
                                              (bool) Tools::getValue('VATNUMBER_CHECKING'))) {
                $echo .= $this->displayConfirmation($this->l('Settings updated successfully.'));
            } else {
                $echo .= $this->displayError($this->l('Failed to update settings.'));
            }
        }

        return $echo.$this->renderForm();
    }

    /**
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

        $fieldsForm = [
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
                        'label'     => $this->l('Enable checking of the VAT number with the web service'),
                        'name'      => 'VATNUMBER_CHECKING',
                        'is_bool'   => true,
                        'desc'      => $this->l('Verification by the web service is slow. Enabling it slows down creating or updating an address.'),
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
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitVatNumber';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value'  => $this->getConfigFieldsValues(),
            'languages'     => $this->context->controller->getLanguages(),
            'id_language'   => $this->context->language->id
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * @since 1.0.0
     * @since 2.1.0 Added VATNUMBER_MANUAL handling.
     */
    public function getConfigFieldsValues()
    {
        return [
            'VATNUMBER_COUNTRY'   =>
                Tools::getValue('VATNUMBER_COUNTRY', Configuration::get('VATNUMBER_COUNTRY')),
            'VATNUMBER_MANUAL'    =>
                Tools::getValue('VATNUMBER_MANUAL', Configuration::get('VATNUMBER_MANUAL')),
            'VATNUMBER_CHECKING'  =>
                Tools::getValue('VATNUMBER_CHECKING', Configuration::get('VATNUMBER_CHECKING')),
        ];
    }

    /**
     * Assign template vars related to VAT number. Works for all configuration
     * modes.
     *
     * @param string $context Context.
     *
     * @todo As soon as we have a suitable hook system (see comment in
     *       Address:validateController()), this should become a hook.
     *
     * @since 2.1.0
     */
    public static function assignTemplateVars($context)
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
     * @param string $address Alias of the address to display. May be different
     *                        on return.
     *
     * @todo When the updater has learned to do database upgrades it's likely
     *       we want to store the 'vat_exemption' flag directly in the
     *       database, making this method obsolete. This would also make all
     *       the code for finding and calling this method in core obsolete.
     * @todo As soon as we have a suitable hook system (see comment in
     *       Address:validateController()), this should become a hook.
     *
     * @since 2.1.0
     */
    public static function adjustAddressForLayout(&$address)
    {
        // Don't display the VAT exemption text.
        if (Configuration::get('VATNUMBER_MANAGEMENT')
            && Configuration::get('VATNUMBER_MANUAL')
            && $address->vat_number === static::VAT_EXEMPTION_FLAG) {
            $address->vat_exemption = true;
            $address->vat_number = '';
        }
    }

    /**
     * Note: this hook currently doesn't get triggered anywhere.
     */
    public function hookActionValidateCustomerAddressForm(&$params)
    {
        $fieldCompany = $params['form']->getField('company');
        $fieldNumber = $params['form']->getField('vat_number');

        $result = static::validateNumber($fieldCompany->getValue(),
                                         $fieldNumber->getValue());
        if (is_string($result)) {
            $fieldNumber->addError($result);
            $result = false;
        }

        return $result;
    }
}
