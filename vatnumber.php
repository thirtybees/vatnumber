<?php
/**
 * Copyright (C) 2017-2019 thirty bees
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
 * @copyright 2017-2019 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark of PrestaShop SA.
 */

use \GuzzleHttp\Exception\RequestException;

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
        $this->version = '2.3.0';
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
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => '1.6.1.99'];
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

    /**
     * Validate a VAT number using the EC's web service.
     *
     * @param string $vatNumber The VAT number, including country code.
     *
     * @return array Error messages. An empty array means the given number is a
     *               valid, registered VAT number.
     *
     * @since 1.0.0
     *
     */
    public static function WebServiceCheck($vatNumber)
    {
        // Retrocompatibility for module version < 2.1.0 (07/2018).
        if (empty($vatNumber)) {
            return [];
        }

        $countryCode = substr($vatNumber, 0, 2);
        $vatNumber = substr(str_replace(' ', '', $vatNumber), 2);

        /**
         * PHP's SoapClient ...
         *
         *  - can parse the WSDL service description,
         *  - can form a valid request,
         *  - can't process such a request,
         *  - means an additional installation dependency.
         *
         * PHP's SimpleXMLElement ...
         *
         *  - can't form a valid request,
         *  - can't parse a response.
         *
         * With this in mind, the following was re-engineered. Service
         * description is in the WSDL file at
         *
         *   http://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl
         *
         * A request looks like:
         *
         *   <?xml version="1.0" encoding="UTF-8"?>
         *   <SOAP-ENV:Envelope
         *     xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"
         *     xmlns:ns1="urn:ec.europa.eu:taxud:vies:services:checkVat:types"
         *   >
         *     <SOAP-ENV:Body>
         *       <ns1:checkVat>
         *         <ns1:countryCode>DE</ns1:countryCode>
         *         <ns1:vatNumber>171017618</ns1:vatNumber>
         *       </ns1:checkVat>
         *     </SOAP-ENV:Body>
         *   </SOAP-ENV:Envelope>
         *
         * Response is described as
         *
         *   struct checkVatResponse {
         *     string countryCode;
         *     string vatNumber;
         *     date requestDate;
         *     boolean valid;
         *     string name;
         *     string address;
         *   }
         *
         * A response to a successful request looks like:
         *
         *   <soap:Envelope
         *     xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
         *   >
         *     <soap:Body>
         *       <checkVatResponse
         *         xmlns="urn:ec.europa.eu:taxud:vies:services:checkVat:types"
         *       >
         *         <countryCode>DE</countryCode>
         *         <vatNumber>171017618</vatNumber>
         *         <requestDate>2019-03-11+01:00</requestDate>
         *         <valid>true</valid>
         *         <name>---</name>
         *         <address>---</address>
         *       </checkVatResponse>
         *     </soap:Body>
         *   </soap:Envelope>'
         *
         * A response to a failed request looks like:
         *
         *   <soap:Envelope
         *     xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
         *   >
         *     <soap:Body>
         *       <soap:Fault>
         *         <faultcode>soap:Server</faultcode>
         *         <faultstring>SERVICE_UNAVAILABLE</faultstring>
         *       </soap:Fault>
         *     </soap:Body>
         *   </soap:Envelope>
         *
         * Other error messages:
         *
         *  - INVALID_INPUT: The provided CountryCode is invalid or the VAT
         *    number is empty;
         *  - GLOBAL_MAX_CONCURRENT_REQ: Your Request for VAT validation has
         *    not been processed; the maximum number of concurrent requests has
         *    been reached. [...] Please try again later.
         *  - MS_MAX_CONCURRENT_REQ: [about the same].
         *  - SERVICE_UNAVAILABLE: an error was encountered either at the
         *    network level or the Web application level, try again later;
         *  - MS_UNAVAILABLE: The application at the Member State is not
         *    replying or not available [...], try again later.
         *  - TIMEOUT: The application did not receive a reply within the
         *    allocated time period, try again later.
         */

        $response = [
            // valid response
            //'countryCode' => null,
            //'vatNumber'   => null,
            //'requestDate' => null,
            'valid'       => null,
            //'name'        => null,
            //'address'     => null,
            // error report
            'faultstring' => null,
        ];

        $body = false;
        $guzzle = new \GuzzleHttp\Client([
            'base_uri'    => 'http://ec.europa.eu/taxation_customs/vies/',
            'timeout'     => 20,
            'headers'     => [
                'Content-Type' => 'application/soap+xml; charset=UTF-8',
            ],
        ]);

        // VIES doesn't like too many newlines. Assemble the string to get
        // some code formatting anyways.
        $postBody = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<SOAP-ENV:Envelope'
              .' xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
              .' xmlns:ns1="urn:ec.europa.eu:taxud:vies:services:checkVat:types"'
            .'>'
              .'<SOAP-ENV:Body>'
                .'<ns1:checkVat>'
                  .'<ns1:countryCode>'.$countryCode.'</ns1:countryCode>'
                  .'<ns1:vatNumber>'.$vatNumber.'</ns1:vatNumber>'
                .'</ns1:checkVat>'
              .'</SOAP-ENV:Body>'
            .'</SOAP-ENV:Envelope>';

        try {
            $body = $guzzle->post('services/checkVatService', [
                'body'  => $postBody,
            ])->getBody()->getContents();
        } catch (RequestException $e) {
            $body = '';
            $response['faultstring'] = $e->getMessage();
        }

        // Unfortunately, SimpleXMLElement can't parse the response. Do it
        // 'by hand', using regular expressions.
        foreach ($response as $key => $value) {
            $preg = '/<'.$key.'>(.*)<\/'.$key.'>/';
            if (preg_match($preg, $body, $matches)) {
                $response[$key] = $matches[1];
            }
        }

        if ($response['faultstring'] === 'INVALID_INPUT') {
            return [Tools::displayError('Malformed VAT number.')];
        } elseif ($response['faultstring']) {
            /**
             * A web service failure. Service failures stop customers from
             * completing an order (unless they accept to pay VAT), so
             * accepting such a VAT number, but also logging it as being
             * unvalidated is probably the best we can do to minimize
             * disruption of the customer.
             *
             * Logger can also send email on new log entries.
             */
            $message = sprintf(
                'VAT number %s%s could not get validated due to a validation web service outage (%s).',
                $countryCode, $vatNumber,
                $response['faultstring']
            );
            Logger::addLog($message, 4);
        } elseif ($response['valid'] !== 'true') {
            return [Tools::displayError('VAT number not registered at your tax authorities.')];
        }

        return [];
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
                        'desc'      => $this->l('This uses the official web service of the European Community. On web service outages (they have been seen quite frequently), VAT numbers get accepted anyways to not disrupt the customer. Such events get logged to allow you to verify the number manually. If you also want to receive email on such events, set \'Minimum severity level\' in Advanced Parameters -> Logs to 4 or lower.'),
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
     * @param Address $address Alias of the address to display. May be different
     *                         on return. Can be NULL for a new address.
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
            && is_object($address)
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
