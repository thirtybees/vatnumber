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

    /** The queue is filled this many batches ahead of what one run checks. */
    const CRON_QUEUE_AHEAD_FACTOR = 5;

    /** Wall clock budget of one cron run, seconds. */
    const CRON_TIME_BUDGET_SECONDS = 120;

    /**
     * Seconds the circuit breaker stays open after a VIES failure. While
     * open, no new VIES requests are made and numbers are accepted
     * provisionally right away, so a struggling VIES is not hammered.
     */
    const VIES_BREAKER_SECONDS = 300;

    /** Hours without a cron run before the config page shows a warning. */
    const CRON_STALE_HOURS = 24;

    /**
     * Distinct numbers one customer may have checked live per window. More
     * than that is a script, not a business correcting a typo; the save is
     * refused instead of being accepted provisionally.
     */
    const CUSTOMER_RATE_LIMIT = 5;
    const CUSTOMER_RATE_WINDOW_SECONDS = 3600;

    /**
     * Live checks per window for visitors that cannot be tied to a customer
     * yet (registration, guest checkout). Beyond this the number is still
     * accepted, but provisionally, and the cron verifies it: a flood from
     * anonymous sessions then costs the shop no VIES requests at all.
     */
    const ANONYMOUS_RATE_LIMIT = 30;

    /** Numbers named in one rejection log entry. */
    const MAX_REJECTED_IN_LOG = 20;

    /** Tolerance when deciding whether an order carried any VAT at all. */
    const VAT_CHARGED_EPSILON = 0.005;

    /** Entropy floor for the generated cron token. */
    const MIN_TOKEN_DISTINCT_CHARS = 8;

    /** @var array Controllers this module ships. */
    public $controllers = ['cron'];

    /**
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->name = 'vatnumber';
        $this->tab = 'billing_invoicing';
        $this->version = '2.7.7';
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
     * Install. Core has no rollback for a failed module install, so on any
     * failure after the module row exists the partial install is undone
     * here, instead of leaving a half installed module behind.
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        // A re-install over a populated table must not destroy the audit
        // trail when a later step fails.
        $tableExisted = static::checkTableExists();
        try {
            $ok = $this->executeSqlScript('install')
                && $this->registerHook('actionObjectAddressValidateController')
                && $this->registerHook('actionObjectAddressAddBefore')
                && $this->registerHook('actionObjectAddressUpdateBefore')
                && $this->registerHook('displayAdminOrder')
                && $this->installTab()
                && Configuration::updateValue('VATNUMBER_MANAGEMENT', 1)
                && Configuration::updateValue('VATNUMBER_CHECKING', 1)
                && Configuration::updateValue('VATNUMBER_REQUIRE_VALIDATION', 1)
                && Configuration::updateValue('VATNUMBER_COUNTRY', (int) Configuration::get('PS_COUNTRY_DEFAULT'))
                && Configuration::updateValue('VATNUMBER_OWN_NUMBER', '')
                && Configuration::updateValue('VATNUMBER_VERIFY_SINCE', '')
                && static::setGlobal('VATNUMBER_CRON_TOKEN', static::generateCronToken());
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber: install failed: '.$e->getMessage(), 3);
            $this->_errors[] = $e->getMessage();
            $ok = false;
        }
        if (!$ok) {
            if (!$this->_errors) {
                $this->_errors[] = 'One of the installation steps failed, see the shop log';
            }
            try {
                $this->uninstall($tableExisted);
            } catch (Exception $e) {
                PrestaShopLogger::addLog('vatnumber: rollback after failed install failed too: '.$e->getMessage(), 3);
                $this->_errors[] = $e->getMessage();
            }
        }

        return $ok;
    }

    /**
     * Whether the check table exists.
     *
     * @return bool
     * @throws PrestaShopException
     */
    public static function checkTableExists(): bool
    {
        $pattern = str_replace('_', '\_', _DB_PREFIX_.'vatnumber_check');
        $rows = Db::getInstance()->executeS('SHOW TABLES LIKE \''.pSQL($pattern).'\'');

        return is_array($rows) && count($rows) > 0;
    }

    /**
     * Back office menu entry for the how-to page, under Localization next
     * to Taxes. Idempotent: an existing tab is left alone. Access rights
     * are granted by core for the installing employee's profile; without an
     * employee context (CLI install) the tab is still created and rights
     * can be granted under Administration > Permissions.
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function installTab(): bool
    {
        if ((int) Tab::getIdFromClassName('AdminVatNumberHowTo')) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = 'AdminVatNumberHowTo';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentLocalization');
        $tab->active = true;
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'VAT numbers how-to';
        }
        // Tab::add() returns the result of initAccess(), which is false
        // without an employee context even though the tab row was created.
        $tab->add();

        return (bool) $tab->id;
    }

    /**
     * Remove the how-to tab, if present.
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function uninstallTab(): bool
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
    protected static function translate(string $string): string
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
    public static function generateCronToken(): string
    {
        $token = Tools::passwdGen(32);
        if (strlen($token) !== 32 || count(array_unique(str_split($token))) < static::MIN_TOKEN_DISTINCT_CHARS) {
            throw new PrestaShopException('Failed to generate a secure cron token');
        }

        return $token;
    }

    /**
     * Configuration values that describe the module as a whole (the VIES
     * breaker, the cron state, the cron secret) are stored in the global
     * scope on purpose: the check table is shared by all shops, and an
     * outage is an outage for every shop.
     *
     * @param string $key
     *
     * @return string|null|false
     * @throws PrestaShopException
     */
    public static function getGlobal(string $key)
    {
        return Configuration::getGlobalValue($key);
    }

    /**
     * Store a configuration value in the global scope, see getGlobal().
     *
     * @param string $key
     * @param mixed $value
     *
     * @return bool
     * @throws PrestaShopException
     */
    public static function setGlobal(string $key, $value): bool
    {
        return Configuration::updateGlobalValue($key, $value);
    }

    /**
     * Execute one of the SQL scripts in sql/. Placeholders PREFIX_,
     * ENGINE_TYPE, CHARSET_TYPE and COLLATE_TYPE get replaced with the
     * shop's actual values. Scripts are single statements or statements
     * separated by a semicolon at the end of a line.
     *
     * @param string $script Script name without path and extension.
     *
     * @return bool
     * @throws PrestaShopException
     */
    public function executeSqlScript(string $script): bool
    {
        $allowed = [
            'install',
            'uninstall',
            'upgrade-2.7.5-id-address',
            'upgrade-2.7.5-id-customer',
            'upgrade-2.7.5-valid-proof',
            'upgrade-2.7.5-status-index',
            'upgrade-2.7.6-customer-index',
            'upgrade-2.7.6-drop-status-index',
        ];
        if (!in_array($script, $allowed, true)) {
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
     * Whether the check table has a column. Used by upgrades to apply
     * schema scripts only once.
     *
     * @param string $column
     *
     * @return bool
     * @throws PrestaShopException
     */
    public static function checkTableHasColumn(string $column): bool
    {
        $rows = Db::getInstance()->executeS('SHOW COLUMNS FROM `'._DB_PREFIX_.'vatnumber_check` WHERE Field = \''.pSQL($column).'\'');

        return is_array($rows) && count($rows) > 0;
    }

    /**
     * Whether the check table has an index with this name. Used by upgrades
     * to apply schema scripts only once.
     *
     * @param string $index
     *
     * @return bool
     * @throws PrestaShopException
     */
    public static function checkTableHasIndex(string $index): bool
    {
        $rows = Db::getInstance()->executeS('SHOW INDEX FROM `'._DB_PREFIX_.'vatnumber_check` WHERE Key_name = \''.pSQL($index).'\'');

        return is_array($rows) && count($rows) > 0;
    }

    /**
     * Uninstall. The module's own objects go first, so a failure there
     * leaves the module row in place and the uninstall can be retried;
     * core's uninstall runs last.
     *
     * The check table is dropped, including the VIES consultation IDs. That
     * is documented on the how-to page and in the README: make a backup
     * before uninstalling if the proof is still needed.
     *
     * @param bool $keepTable Keep the check table, used by the install
     *                        rollback when the table existed before.
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function uninstall($keepTable = false)
    {
        // Core's uninstall calls disable(), which rewrites
        // VATNUMBER_MANAGEMENT; that key is therefore removed last.
        return ($keepTable || $this->executeSqlScript('uninstall'))
               && $this->uninstallTab()
               && Configuration::deleteByName('VATNUMBER_OWN_NUMBER')
               && Configuration::deleteByName('VATNUMBER_CHECKING')
               && Configuration::deleteByName('VATNUMBER_MANUAL')
               && Configuration::deleteByName('VATNUMBER_COUNTRY')
               && Configuration::deleteByName('VATNUMBER_REQUIRE_VALIDATION')
               && Configuration::deleteByName('VATNUMBER_VERIFY_SINCE')
               && Configuration::deleteByName('VATNUMBER_CRON_TOKEN')
               && Configuration::deleteByName('VATNUMBER_VIES_DOWN_UNTIL')
               && Configuration::deleteByName('VATNUMBER_CRON_LAST_RUN')
               && parent::uninstall()
               && Configuration::deleteByName('VATNUMBER_MANAGEMENT');
    }

    /**
     * Enable the module and switch VAT number management on.
     *
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
     * Disable the module and switch VAT number management off.
     *
     * @param bool $forceAll
     *
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
        if (isset($params['object']) && $params['object'] instanceof Address) {
            $error = static::validateNumber($params['object']);
            if (is_string($error)) {
                return [ $error ];
            }
        }
        return [];
    }

    /**
     * Back office address saves do not pass through validateController(),
     * so these Before hooks run the same validation there. See
     * validateAddressFromBackOffice() for how a failure is reported.
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
     * Before hooks cannot block the save, so a failed check is surfaced as
     * a controller error (shown to the employee after the save) and logged.
     * The tax manager independently refuses the exemption for numbers that
     * do not pass the same checks, so a bad number slipping into the
     * database never grants 0% VAT.
     *
     * Imports are skipped: a CSV with thousands of addresses must not fire
     * thousands of live VIES requests inside one request. The cron queues
     * and verifies imported numbers afterwards.
     *
     * @param array $params
     *
     * @return void
     * @throws PrestaShopException
     */
    protected function validateAddressFromBackOffice(array $params): void
    {
        $controller = $this->context->controller;
        if (!($controller instanceof Controller) || $controller->controller_type !== 'admin') {
            return;
        }
        if ($controller instanceof AdminImportController) {
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
     * @throws PrestaShopException
     */
    public function hookDisplayAdminOrder($params)
    {
        $idOrder = (int) (isset($params['id_order']) ? $params['id_order'] : 0);
        try {
            $order = new Order($idOrder);
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
            PrestaShopLogger::addLog('vatnumber: order panel failed for order '.$idOrder.': '.$e->getMessage(), 3);

            return '<div class="row"><div class="col-lg-12"><div class="panel"><h3><i class="icon icon-euro"></i> '.$this->l('VAT exemption').'</h3>'
                .'<div class="alert alert-danger">'.sprintf($this->l('Could not display the VAT status: %s'), Tools::safeOutput($e->getMessage())).'</div></div></div></div>';
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
    protected function renderOrderVatPanel(Order $order, string $vatNumber): string
    {
        $vatCharged = ((float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl) > static::VAT_CHARGED_EPSILON;

        $html = '<div class="row"><div class="col-lg-12"><div class="panel"><h3><i class="icon icon-euro"></i> '.$this->l('VAT exemption').'</h3>';
        $html .= '<p>'.($vatCharged
            ? $this->l('VAT was charged on this order.')
            : $this->l('No VAT was charged on this order (exemption applied).')).'</p>';

        if (VatNumberCheck::isLegacySentinel($vatNumber)) {
            $html .= '<p>'.$this->l('This order was placed with the checkbox exemption of module versions before 2.7.4; there is no VAT number to verify.').'</p>';
            $html .= '</div></div></div>';

            return $html;
        }

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
            $html .= '<p><b>'.$this->l('Current status since:').'</b> '.Tools::safeOutput($check->date_upd).'</p>';
            if ($check->trader_name !== '') {
                $html .= '<p><b>'.$this->l('Registered trader:').'</b> '.Tools::safeOutput($check->trader_name).'</p>';
            }
            if ($check->valid_date) {
                $html .= '<p><b>'.$this->l('Last confirmed valid by VIES:').'</b> '.Tools::safeOutput($check->valid_date);
                if ($check->valid_request_id !== '') {
                    $html .= ' - '.$this->l('consultation ID (proof):').' '.Tools::safeOutput($check->valid_request_id);
                }
                $html .= '</p>';
            }
            if ($check->status === VatNumberCheck::STATUS_INVALID) {
                $html .= '<div class="alert alert-danger">'.$this->l('VIES rejected this number. If this order was exempted, review it and re-invoice the VAT if needed.').'</div>';
            } elseif ($check->status === VatNumberCheck::STATUS_UNVERIFIED) {
                $html .= '<div class="alert alert-warning">'.$this->l('This number is still awaiting verification. The scheduled task will verify it; you can also check it now with the validation tool.').'</div>';
            }
            $html .= '<p class="help-block">'.$this->l('This shows the current state of the number. Orders keep their own VAT totals; a later rejection does not change an invoice that was already issued.').'</p>';
        } else {
            $html .= '<p>'.$this->l('This number has never been checked against VIES.').'</p>';
        }

        $configLink = $this->context->link->getAdminLink('AdminModules', true).'&configure='.$this->name;
        $html .= '<p><a href="'.Tools::safeOutput($configLink).'">'.$this->l('Open the module configuration page to check this number by hand.').'</a></p>';
        $html .= '</div></div></div>';

        return $html;
    }

    /**
     * Compatibility entry point. Core's AddressController and AuthController
     * only call assignTemplateVars() when this method exists (a guard that
     * predates 2.7.4). The checkbox exemption it used to serve is gone, so
     * there is nothing left to adjust; the method must stay for the guard.
     *
     * @param Address|null $address
     *
     * @return void
     */
    public static function adjustAddressForLayout($address)
    {
    }

    /**
     * Map of address country ISO code to VIES VAT number prefix.
     *
     * @return array<string, string>
     */
    public static function getPrefixIntracomVAT(): array
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
     *
     * @return bool
     * @throws PrestaShopException
     */
    public static function isApplicable(int $idCountry): bool
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
     * Whether an address country may carry a number with this VIES prefix.
     * Several ISO codes can map to one prefix (FR and FX), and XI numbers
     * belong to Northern Ireland businesses whose address country is GB.
     *
     * @param string|false $isoAddress Country ISO code of the address, false
     *                                 when the country is unknown.
     * @param string $prefix VIES prefix of the number.
     *
     * @return bool
     */
    public static function countryMatchesPrefix($isoAddress, string $prefix): bool
    {
        $isoAddress = strtoupper((string) $isoAddress);
        $prefix = strtoupper((string) $prefix);
        if ($prefix === 'XI' && $isoAddress === 'GB') {
            return true;
        }

        return in_array($isoAddress, array_keys(static::getPrefixIntracomVAT(), $prefix, true), true);
    }

    /**
     * Offline shape checks of a normalized number: known prefix and the
     * official format of that country. No network access.
     *
     * @param string $normalized Normalized number including prefix.
     *
     * @return string|null Translated error message, or null when the shape
     *                     is fine.
     */
    public static function checkNumberShape(string $normalized): ?string
    {
        $prefix = substr($normalized, 0, 2);
        if (strlen($normalized) < 3 || !in_array($prefix, static::getPrefixIntracomVAT(), true)) {
            return static::translate('Invalid VAT number prefix.');
        }
        if (!VatNumberFormat::isValid($prefix, substr($normalized, 2))) {
            return static::translate('VAT number format is invalid for this country.');
        }

        return null;
    }

    /**
     * The offline part of what makes a number acceptable on an address:
     * not the legacy sentinel, a valid shape, and a prefix that fits the
     * address country. The tax manager repeats this as a safety net for
     * numbers that reached the database without passing validateNumber()
     * (imports, webservice, back office saves).
     *
     * @param Address $address
     *
     * @return bool
     * @throws PrestaShopException
     */
    public static function isPlausibleForAddress(Address $address): bool
    {
        $vatNumber = trim((string) $address->vat_number);
        if ($vatNumber === '' || VatNumberCheck::isLegacySentinel($vatNumber)) {
            return false;
        }
        $normalized = VatNumberCheck::normalize($vatNumber);
        if (static::checkNumberShape($normalized) !== null) {
            return false;
        }
        if (!static::countryMatchesPrefix(Country::getIsoById((int) $address->id_country), substr($normalized, 0, 2))) {
            return false;
        }

        // Same business name rule as on save; matters for addresses that
        // bypassed validateNumber() (imports, webservice).
        return static::companyNameFor($address, (int) $address->id_customer) !== '';
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
        $vatNumber = $address->vat_number;
        if ($vatNumber === '') {
            return true;
        }

        // The legacy checkbox sentinel is customer typeable. It must never
        // pass as a VAT number.
        if (VatNumberCheck::isLegacySentinel($vatNumber)) {
            return static::translate('Invalid VAT number.');
        }

        if (!Configuration::get('VATNUMBER_MANAGEMENT')) {
            return true;
        }

        // Which customer is doing this? Core sets Address->id_customer only
        // after validateController(), and one step registration fills in a
        // placeholder id, so the address field alone cannot be trusted.
        $idCustomer = static::resolveCustomerId($address);

        // A VAT number belongs to a business. The theme may hide the
        // company field for some countries; fall back to the customer's
        // company before refusing.
        if (static::companyNameFor($address, $idCustomer) === '') {
            return static::translate('VAT number, but no company name given.');
        }

        $normalized = VatNumberCheck::normalize($vatNumber);
        $shapeError = static::checkNumberShape($normalized);
        if ($shapeError !== null) {
            return $shapeError;
        }

        $isoAddress = strtoupper((string) Country::getIsoById((int) $address->id_country));
        if (!static::countryMatchesPrefix($isoAddress, substr($normalized, 0, 2))) {
            return static::translate('VAT number inconsistent with country.');
        }

        // Domestic customers pay VAT anyway; their number only needs to be
        // well formed, VIES is not asked.
        if ((int) $address->id_country === (int) Configuration::get('VATNUMBER_COUNTRY')) {
            return true;
        }

        if (Configuration::get('VATNUMBER_CHECKING')) {
            $errors = static::webServiceCheck($normalized, (int) $address->id, $idCustomer);
            if (count($errors)) {
                return $errors[0];
            }
        }

        return true;
    }

    /**
     * The customer an address save belongs to, or 0 when unknown. The
     * address field is only trusted when it matches the logged in customer;
     * core assigns it after validation and uses a placeholder during one
     * step registration.
     *
     * @param AddressCore $address
     *
     * @return int
     */
    protected static function resolveCustomerId(AddressCore $address): int
    {
        $context = Context::getContext();
        $controller = ($context && isset($context->controller)) ? $context->controller : null;
        if ($controller instanceof Controller && $controller->controller_type === 'admin') {
            // Employees edit a real customer's address: the address field
            // is authoritative here.
            return (int) $address->id_customer;
        }
        if ($context && isset($context->customer) && $context->customer instanceof Customer && (int) $context->customer->id) {
            // Logged in front office customer, whatever the address says.
            return (int) $context->customer->id;
        }

        // Registration or guest checkout: no trustworthy customer yet.
        return 0;
    }

    /**
     * Company name for a VAT number check: the address company, or the
     * customer account's company when the address field is empty.
     *
     * @param AddressCore $address
     * @param int $idCustomer
     *
     * @return string
     * @throws PrestaShopException
     */
    protected static function companyNameFor(AddressCore $address, int $idCustomer): string
    {
        $company = trim((string) $address->company);
        if ($company === '' && $idCustomer) {
            $customer = new Customer($idCustomer);
            if (Validate::isLoadedObject($customer)) {
                $company = trim((string) $customer->company);
            }
        }

        return $company;
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
     *   2. A customer that keeps submitting different numbers is refused
     *      (CUSTOMER_RATE_LIMIT per hour): that is a script, and every live
     *      request it causes would count against the shop at VIES.
     *   3. When the circuit breaker is open (VIES failed within the last
     *      VIES_BREAKER_SECONDS), no request is attempted at all.
     *   4. Otherwise VIES is asked with the interactive time budget.
     *   5. When VIES cannot answer, the number is accepted provisionally,
     *      recorded as 'unverified' and logged. The cron re-checks it later.
     *      An existing 'valid' record is never downgraded by an outage: the
     *      last confirmed state and its consultation ID are kept.
     *
     * @param string $vatNumber The VAT number, including country code.
     * @param int $idAddress Address the number belongs to, if known.
     * @param int $idCustomer Customer submitting it, if known.
     *
     * @return string[] Error messages. An empty array means the number is
     *                  valid, cached valid, or provisionally accepted.
     *
     * @throws PrestaShopException
     */
    public static function webServiceCheck(string $vatNumber, int $idAddress = 0, int $idCustomer = 0): array
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

            if (!$check) {
                $recent = VatNumberCheck::countRecentByCustomer($idCustomer, static::CUSTOMER_RATE_WINDOW_SECONDS);
                if ($idCustomer && $recent >= static::CUSTOMER_RATE_LIMIT) {
                    PrestaShopLogger::addLog('vatnumber: customer '.(int) $idCustomer.' exceeded the VAT number check rate limit', 3);

                    return [static::translate('Too many different VAT numbers were entered in a short time. Please try again later.')];
                }
                if (!$idCustomer && $recent >= static::ANONYMOUS_RATE_LIMIT) {
                    // Anonymous flood (registration or guest checkout): the
                    // number is accepted, but VIES is not asked on its
                    // behalf; the cron verifies it.
                    PrestaShopLogger::addLog('vatnumber: anonymous VAT number check rate limit reached, '.$normalized.' queued instead of checked live', 2);
                    static::acceptProvisionally(null, $normalized, $countryCode, $idAddress, 0);

                    return [];
                }
            }

            if ((int) static::getGlobal('VATNUMBER_VIES_DOWN_UNTIL') > time()) {
                // Circuit breaker open: VIES failed very recently, do not
                // send another request, accept provisionally right away.
                static::acceptProvisionally($check, $normalized, $countryCode, $idAddress, $idCustomer);

                return [];
            }

            try {
                $result = VatNumberViesClient::checkVat($countryCode, $plainNumber, static::getRequester(), true);
            } catch (VatNumberViesUnavailableException $e) {
                static::openBreaker();
                static::acceptProvisionally($check, $normalized, $countryCode, $idAddress, $idCustomer);
                PrestaShopLogger::addLog('vatnumber: VIES unavailable, VAT number '.$normalized.' accepted provisionally. Reason: '.$e->getMessage(), 3);

                return [];
            } catch (PrestaShopException $e) {
                // Server misconfiguration (no curl, no soap). Never block
                // customers over it, but alert the merchant at the highest
                // severity: checking is effectively disabled until fixed.
                static::openBreaker();
                static::acceptProvisionally($check, $normalized, $countryCode, $idAddress, $idCustomer);
                PrestaShopLogger::addLog('vatnumber: VAT number checking is broken, '.$normalized.' accepted provisionally. Fix this server problem: '.$e->getMessage(), 4);

                return [];
            }

            if ($result['valid']) {
                VatNumberCheck::recordResult($normalized, $countryCode, VatNumberCheck::STATUS_VALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier'], $result['name'], $result['address'], $idAddress, $idCustomer);

                return [];
            }

            VatNumberCheck::recordResult($normalized, $countryCode, VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier'], '', '', $idAddress, $idCustomer);

            return [static::translate('VAT number not registered at your tax authorities.')];
        } catch (Exception $e) {
            // Log the full cause; show the customer only a generic message.
            // Raw exception text can contain database details.
            PrestaShopLogger::addLog('vatnumber: VAT number check failed for '.$normalized.': '.$e->getMessage(), 3);

            return [static::translate('Failed to validate the VAT number. Please try again later.')];
        }
    }

    /**
     * Open the circuit breaker: no VIES requests for VIES_BREAKER_SECONDS.
     *
     * @return void
     * @throws PrestaShopException
     */
    protected static function openBreaker(): void
    {
        static::setGlobal('VATNUMBER_VIES_DOWN_UNTIL', time() + static::VIES_BREAKER_SECONDS);
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
     * @param int $idCustomer
     *
     * @return void
     * @throws PrestaShopException
     */
    protected static function acceptProvisionally(?VatNumberCheck $check, string $normalized, string $countryCode, int $idAddress, int $idCustomer): void
    {
        if ($check && $check->status === VatNumberCheck::STATUS_VALID) {
            return;
        }
        VatNumberCheck::recordResult($normalized, $countryCode, VatNumberCheck::STATUS_UNVERIFIED, VatNumberCheck::SOURCE_FORMAT, '', '', '', $idAddress, $idCustomer);
    }

    /**
     * The verification start date from the settings, or null when not set
     * or not a valid date.
     *
     * @return string|null Date in Y-m-d format.
     * @throws PrestaShopException
     */
    public static function getVerifySince(): ?string
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
     * @return array{countryCode: string, number: string}|null For example
     *         ['countryCode' => 'NL', 'number' => '123456789B01'].
     * @throws PrestaShopException
     */
    public static function getRequester(): ?array
    {
        $own = VatNumberCheck::normalize((string) Configuration::get('VATNUMBER_OWN_NUMBER'));
        if (strlen($own) < 4) {
            return null;
        }

        return ['countryCode' => substr($own, 0, 2), 'number' => substr($own, 2)];
    }

    /**
     * One scheduled run: housekeeping, then verification of queued numbers
     * against VIES within a time budget. Called by the cron controller.
     *
     * @param int|null $limit Maximum numbers to check in one run, defaults
     *                        to CRON_BATCH_SIZE, capped at CRON_MAX_BATCH_SIZE.
     *
     * @return array{checked: int, confirmed: int, rejected: int, malformed: int, failed: int, pending: int, vies_down: bool, purged: int, dropped_old: int, queued: int, message: string}
     *               Summary: numbers checked, confirmed, rejected (by VIES),
     *               malformed (settled locally), failed, purged, dropped_old,
     *               queued, pending, vies_down, and a plain words message.
     *
     * @throws PrestaShopException
     */
    public static function runBackgroundChecks(?int $limit = null): array
    {
        $limit = min(static::CRON_MAX_BATCH_SIZE, max(1, (int) ($limit === null ? static::CRON_BATCH_SIZE : $limit)));
        $deadline = microtime(true) + static::CRON_TIME_BUDGET_SECONDS;

        $summary = array_merge([
            'checked'   => 0,
            'confirmed' => 0,
            'rejected'  => 0,
            'malformed' => 0,
            'failed'    => 0,
            'pending'   => 0,
            'vies_down' => false,
        ], static::runHousekeeping($limit));

        $rows = VatNumberCheck::getPending($limit);
        $remaining = $limit - count($rows);
        if ($remaining > 0) {
            $rows = array_merge($rows, VatNumberCheck::getStale($remaining));
        }

        $rejected = [];
        $malformed = [];
        foreach ($rows as $row) {
            if (microtime(true) > $deadline) {
                PrestaShopLogger::addLog('vatnumber cron: time budget exhausted, remaining numbers are checked next run', 1);
                break;
            }
            if (!static::checkQueuedRow($row, $summary, $rejected, $malformed)) {
                break;
            }
        }

        static::logRejections($rejected, $malformed);

        $summary['pending'] = VatNumberCheck::countPending();
        static::setGlobal('VATNUMBER_CRON_LAST_RUN', time());
        $summary['message'] = static::describeRun($summary);

        return $summary;
    }

    /**
     * Housekeeping before a run: purge orphaned rows, drop queued numbers
     * of customers inactive since the start date, and queue numbers found
     * on addresses that were never checked. Each step is isolated: a
     * failure is logged and never aborts the verification work.
     *
     * The address table is scanned once per set, and the sets are shared by
     * all steps.
     *
     * @param int $limit Batch size of this run.
     *
     * @return array{purged: int, dropped_old: int, queued: int}
     * @throws PrestaShopException
     */
    protected static function runHousekeeping(int $limit): array
    {
        $result = ['purged' => 0, 'dropped_old' => 0, 'queued' => 0];
        $verifySince = static::getVerifySince();

        try {
            // Every number on any address row, deleted ones included: those
            // are the historical addresses orders point at.
            $allNumbers = VatNumberCheck::getAddressNumbers(null, true);
            $result['purged'] = VatNumberCheck::purgeOrphaned($allNumbers);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber cron: purge skipped: '.$e->getMessage(), 3);
        }

        try {
            if ($verifySince !== null) {
                // Historical (soft deleted) addresses count as active too:
                // orders and their invoices still point at them.
                $result['dropped_old'] = VatNumberCheck::removeInactiveUnverified(VatNumberCheck::getAddressNumbers($verifySince, true));
            }
            // Only numbers on live addresses are queued for verification.
            $liveNumbers = VatNumberCheck::getAddressNumbers($verifySince);
            $result['queued'] = VatNumberCheck::queueMissing($liveNumbers, $limit * static::CRON_QUEUE_AHEAD_FACTOR);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber cron: queue maintenance skipped: '.$e->getMessage(), 3);
        }

        return $result;
    }

    /**
     * Verify one queued row. Numbers that cannot possibly validate (bad
     * prefix or format, e.g. seeded legacy garbage) are settled locally,
     * VIES would only say no.
     *
     * @param array{id_vatnumber_check:string,vat_number:string,country_code:string,status:string} $row
     *        Table row (all columns; only the ones used are listed).
     * @param array{checked:int,confirmed:int,rejected:int,malformed:int,failed:int,pending:int,vies_down:bool,purged:int,dropped_old:int,queued:int} $summary
     *        Updated in place.
     * @param string[] $rejected Numbers VIES rejected, collected for the log.
     * @param string[] $malformed Numbers settled locally, collected for the log.
     *
     * @return bool False when the run should stop (VIES unreachable).
     * @throws PrestaShopException
     */
    protected static function checkQueuedRow(array $row, array &$summary, array &$rejected, array &$malformed): bool
    {
        $number = $row['vat_number'];
        try {
            if ($row['status'] !== VatNumberCheck::STATUS_VALID && static::checkNumberShape($number) !== null) {
                VatNumberCheck::recordResult($number, substr($number, 0, 2), VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_FORMAT);
                $summary['malformed']++;
                $malformed[] = $number;

                return true;
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber cron: settling malformed '.$number.' failed: '.$e->getMessage(), 3);
            $summary['failed']++;
            static::rotateRow($row);

            return true;
        }

        try {
            $result = VatNumberViesClient::checkVat($row['country_code'], substr($number, 2), static::getRequester());
        } catch (PrestaShopException $e) {
            // No transport at all (no curl, no soap): every row would fail
            // the same way. Say it once, loudly, and stop.
            static::openBreaker();
            PrestaShopLogger::addLog('vatnumber cron: VAT number checking is broken, stopping the run. Fix this server problem: '.$e->getMessage(), 4);
            $summary['vies_down'] = true;

            return false;
        } catch (VatNumberViesUnavailableException $e) {
            // Still down, or down again. Stop the run, the rest would fail
            // the same way. The row is rotated to the back so one member
            // state being down cannot block the queue head forever.
            static::openBreaker();
            static::rotateRow($row);
            PrestaShopLogger::addLog('vatnumber cron: VIES unavailable, stopping this run. Reason: '.$e->getMessage(), 2);
            $summary['vies_down'] = true;

            return false;
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber cron: check of '.$number.' failed: '.$e->getMessage(), 3);
            $summary['failed']++;
            static::rotateRow($row);

            return true;
        }

        $summary['checked']++;
        try {
            if ($result['valid']) {
                VatNumberCheck::recordResult($number, $row['country_code'], VatNumberCheck::STATUS_VALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier'], $result['name'], $result['address']);
                $summary['confirmed']++;
            } else {
                VatNumberCheck::recordResult($number, $row['country_code'], VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_VIES, $result['requestIdentifier']);
                $summary['rejected']++;
                $rejected[] = $number;
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber cron: saving result for '.$number.' failed: '.$e->getMessage(), 3);
            $summary['failed']++;
            static::rotateRow($row);
        }

        return true;
    }

    /**
     * Move an unverified row to the back of the queue after a failed
     * attempt. Valid rows keep their date_upd: it is the audit timestamp of
     * the last confirmation.
     *
     * @param array{id_vatnumber_check:string,vat_number:string,country_code:string,status:string} $row
     *        Table row (all columns; only the ones used are listed).
     *
     * @return void
     * @throws PrestaShopException
     */
    protected static function rotateRow(array $row): void
    {
        if ($row['status'] !== VatNumberCheck::STATUS_UNVERIFIED) {
            return;
        }
        try {
            VatNumberCheck::touchRow((int) $row['id_vatnumber_check']);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber cron: could not rotate '.$row['vat_number'].': '.$e->getMessage(), 3);
        }
    }

    /**
     * Log the outcome of a run for the merchant: numbers VIES rejected need
     * follow up (severity 3, so email logging catches them), malformed
     * stored numbers are informational.
     *
     * @param string[] $rejected
     * @param string[] $malformed
     *
     * @return void
     * @throws PrestaShopException
     */
    protected static function logRejections(array $rejected, array $malformed): void
    {
        if ($rejected) {
            $shown = array_slice($rejected, 0, static::MAX_REJECTED_IN_LOG);
            $message = 'vatnumber cron: VIES rejected VAT number(s) '.implode(', ', $shown);
            if (count($rejected) > count($shown)) {
                $message .= sprintf(' and %d more', count($rejected) - count($shown));
            }
            $message .= '. Review the orders of these customers, VAT may have to be re-invoiced.';
            PrestaShopLogger::addLog($message, 3);
        }
        if ($malformed) {
            $shown = array_slice($malformed, 0, static::MAX_REJECTED_IN_LOG);
            $message = 'vatnumber cron: '.count($malformed).' stored number(s) do not match any EU VAT number format and were marked invalid without asking VIES: '.implode(', ', $shown);
            if (count($malformed) > count($shown)) {
                $message .= sprintf(' and %d more', count($malformed) - count($shown));
            }
            PrestaShopLogger::addLog($message, 2);
        }
    }

    /**
     * A plain words status line, so a run that had nothing to do does not
     * look broken to whoever reads the JSON.
     *
     * @param array{checked:int,confirmed:int,rejected:int,malformed:int,failed:int,pending:int,vies_down:bool,purged:int,dropped_old:int,queued:int} $summary
     *
     * @return string
     */
    protected static function describeRun(array $summary): string
    {
        if ($summary['vies_down']) {
            return 'VIES was unreachable, the run stopped early; pending numbers are retried next run';
        }
        if ($summary['checked'] === 0 && $summary['malformed'] === 0 && $summary['pending'] === 0) {
            return 'Queue empty, nothing to do';
        }

        return sprintf(
            'Checked %d number(s) at VIES: %d confirmed, %d rejected; %d malformed settled locally; %d still pending',
            $summary['checked'],
            $summary['confirmed'],
            $summary['rejected'],
            $summary['malformed'],
            $summary['pending']
        );
    }

    /**
     * Module configuration page.
     *
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function getContent()
    {
        $saveResult = '';
        $checkResult = '';
        // State changes only via POST, so a crafted link with a leaked
        // admin token cannot flip settings or trigger VIES lookups.
        $isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost && Tools::isSubmit('SAVE_SETTINGS')) {
            $saveResult = $this->processSaveSettings();
        }
        if ($isPost && Tools::isSubmit('CHECK_VATNUMBER_PROCESS')) {
            $checkResult = $this->processVatNumberCheck();
        }

        return $saveResult.$this->renderExplanationPanel().$this->renderForm($checkResult).$this->renderStatusPanel();
    }

    /**
     * A request value that must be a string (never an array).
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    protected static function getStringValue(string $key, string $default = ''): string
    {
        $value = Tools::getValue($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Short introduction at the top of the configuration page, pointing to
     * the full how-to page in the Localization menu.
     *
     * @return string
     * @throws PrestaShopException
     */
    protected function renderExplanationPanel(): string
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
    protected function processSaveSettings(): string
    {
        $verifySince = trim(static::getStringValue('VATNUMBER_VERIFY_SINCE'));
        if ($verifySince !== '' && (!Validate::isDate($verifySince) || !strtotime($verifySince))) {
            return $this->displayError($this->l('The verification start date must be a valid date (YYYY-MM-DD), or empty to verify all numbers.'));
        }

        $ownNumber = VatNumberCheck::normalize(static::getStringValue('VATNUMBER_OWN_NUMBER'));
        if ($ownNumber !== '' && static::checkNumberShape($ownNumber) !== null) {
            return $this->displayError($this->l('Your own VAT number is not a valid EU VAT number. Enter it with the country prefix, or leave it empty.'));
        }

        $idCountry = (int) Tools::getValue('VATNUMBER_COUNTRY');
        if ($idCountry && !Validate::isLoadedObject(new Country($idCountry))) {
            return $this->displayError($this->l('The selected country does not exist.'));
        }

        $previousSince = trim((string) Configuration::get('VATNUMBER_VERIFY_SINCE'));
        $updates = [
            'VATNUMBER_COUNTRY'            => $idCountry,
            'VATNUMBER_CHECKING'           => (int) (bool) Tools::getValue('VATNUMBER_CHECKING'),
            'VATNUMBER_REQUIRE_VALIDATION' => (int) (bool) Tools::getValue('VATNUMBER_REQUIRE_VALIDATION'),
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

        // Apply a new start date right away, so the merchant sees its effect
        // instead of waiting for the next cron run. A failure here must not
        // hide that the settings themselves were saved.
        if ($verifySince !== '' && $verifySince !== $previousSince) {
            try {
                $dropped = VatNumberCheck::removeInactiveUnverified(VatNumberCheck::getAddressNumbers($verifySince, true));
                $pending = VatNumberCheck::countPending();

                return $this->displayConfirmation(sprintf(
                    $this->l('Settings updated. %d queued number(s) fell outside the verification start date and were removed; %d remain pending.'),
                    $dropped,
                    $pending
                ));
            } catch (Exception $e) {
                PrestaShopLogger::addLog('vatnumber: applying the verification start date failed: '.$e->getMessage(), 3);

                return $this->displayConfirmation($this->l('Settings updated. The start date will be applied on the next scheduled run.'));
            }
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
    protected function renderStatusPanel(): string
    {
        $pending = VatNumberCheck::countPending();
        $lastRun = (int) static::getGlobal('VATNUMBER_CRON_LAST_RUN');
        $token = (string) static::getGlobal('VATNUMBER_CRON_TOKEN');
        $cronUrl = $this->context->link->getModuleLink($this->name, 'cron', ['token' => $token]);

        $html = '<div class="panel"><h3><i class="icon icon-time"></i> '.$this->l('Background verification').'</h3>';
        if ($pending) {
            $html .= '<div class="alert alert-warning">'.sprintf($this->l('%d VAT number(s) are awaiting VIES verification.'), $pending).'</div>';
        } else {
            $html .= '<div class="alert alert-success">'.$this->l('No VAT numbers are awaiting verification.').'</div>';
        }
        if ((time() - $lastRun) > static::CRON_STALE_HOURS * 3600) {
            $html .= '<div class="alert alert-danger">'.sprintf($this->l('The verification task has not run in the last %d hours. New numbers stay unverified and confirmed numbers are not re-checked until it runs. Set up the URL below as a scheduled task.'), static::CRON_STALE_HOURS).'</div>';
        } else {
            $html .= '<p>'.sprintf($this->l('Last verification run: %s'), Tools::safeOutput(date('Y-m-d H:i:s', $lastRun))).'</p>';
        }
        if (!Configuration::get('VATNUMBER_REQUIRE_VALIDATION')) {
            $html .= '<div class="alert alert-info">'.$this->l('Strict mode is off: numbers still awaiting verification already receive the exemption. Switch it on to charge VAT until VIES has confirmed a number.').'</div>';
        }
        if ($token === '') {
            $html .= '<div class="alert alert-danger">'.$this->l('No cron token is stored; the cron URL would be refused. Reset the module to generate one.').'</div>';
        }
        $verifySince = static::getVerifySince();
        if ($verifySince !== null) {
            $html .= '<p>'.sprintf($this->l('Verification start date active: queued numbers of customers inactive since %s are dropped automatically.'), Tools::safeOutput($verifySince)).'</p>';
        }
        $html .= '<p>'.$this->l('To verify pending numbers and re-check old ones automatically, call this URL from a scheduled task (for example every hour, via the cronjobs module or your server\'s crontab):').'</p>';
        $html .= '<p><code>'.Tools::safeOutput($cronUrl).'</code></p>';
        $html .= '<p>'.sprintf($this->l('Each run checks %d numbers. To drain a large queue faster, append &limit=%d to the URL (the maximum per run).'), static::CRON_BATCH_SIZE, static::CRON_MAX_BATCH_SIZE).'</p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Shared on/off values block for switch inputs. The admin form template
     * renders its own Yes/No labels.
     *
     * @return array<int, array{id: string, value: int}>
     */
    protected function getSwitchValues(): array
    {
        return [
            ['id' => 'active_on', 'value' => 1],
            ['id' => 'active_off', 'value' => 0],
        ];
    }

    /**
     * Settings form definition.
     *
     * @return array<string, mixed>
     * @throws PrestaShopException
     */
    protected function getSettingsForm(): array
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
                'id_form' => 'vatnumber_settings_form',
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
     * @param string $checkResultHtml Result of a check just performed.
     *
     * @return array<string, mixed>
     */
    protected function getManualCheckForm(string $checkResultHtml): array
    {
        return [
            'form' => [
                'id_form' => 'vatnumber_check_form',
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
                            .$checkResultHtml,
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
     * Render the settings form and the manual check form.
     *
     * @param string $checkResultHtml Result of a manual check just performed.
     *
     * @return string
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function renderForm(string $checkResultHtml = ''): string
    {
        /** @var AdminController $controller */
        $controller = $this->context->controller;
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = (bool) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');

        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value'  => $this->getConfigFieldsValues(),
            'languages'     => $controller->getLanguages(),
            'id_language'   => $this->context->language->id
        ];

        return (
            $helper->generateForm([$this->getSettingsForm()]) .
            $helper->generateForm([$this->getManualCheckForm($checkResultHtml)])
        );
    }

    /**
     * Current values for the settings and manual check forms; a submitted
     * value wins over the stored one.
     *
     * @return array<string, int|string>
     * @throws PrestaShopException
     */
    public function getConfigFieldsValues(): array
    {
        return [
            'VATNUMBER_COUNTRY' => (int) Tools::getValue('VATNUMBER_COUNTRY', Configuration::get('VATNUMBER_COUNTRY')),
            'VATNUMBER_CHECKING' => (int) Tools::getValue('VATNUMBER_CHECKING', Configuration::get('VATNUMBER_CHECKING')),
            'VATNUMBER_REQUIRE_VALIDATION' => (int) Tools::getValue('VATNUMBER_REQUIRE_VALIDATION', Configuration::get('VATNUMBER_REQUIRE_VALIDATION')),
            'VATNUMBER_OWN_NUMBER' => static::getStringValue('VATNUMBER_OWN_NUMBER', (string) Configuration::get('VATNUMBER_OWN_NUMBER')),
            'VATNUMBER_VERIFY_SINCE' => static::getStringValue('VATNUMBER_VERIFY_SINCE', (string) Configuration::get('VATNUMBER_VERIFY_SINCE')),
            'CHECK_VATNUMBER' => static::getStringValue('CHECK_VATNUMBER', ''),
        ];
    }

    /**
     * Assign template vars related to VAT number. Called by core from the
     * address, authentication and one page checkout controllers.
     *
     * vat_display: 0 = module inactive, 1 = not applicable for the current
     * country, 2 = applicable. Themes decide what to do with it; the
     * bundled theme drives field visibility through vatnumber_ajax_call
     * and ajax.php instead.
     *
     * @param Context $context
     *
     * @return void
     * @throws PrestaShopException
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
     * @throws PrestaShopException
     */
    protected function processVatNumberCheck(): string
    {
        $vatnumber = VatNumberCheck::normalize(static::getStringValue('CHECK_VATNUMBER'));
        $shapeError = static::checkNumberShape($vatnumber);
        if ($shapeError !== null) {
            return '<div class="alert alert-danger">'.$shapeError.'</div>';
        }
        $prefix = substr($vatnumber, 0, 2);
        try {
            $res = VatNumberViesClient::checkVat($prefix, substr($vatnumber, 2), static::getRequester());
            if ($res['valid']) {
                VatNumberCheck::recordResult($vatnumber, $prefix, VatNumberCheck::STATUS_VALID, VatNumberCheck::SOURCE_VIES, $res['requestIdentifier'], $res['name'], $res['address']);
                $ret = '<div class="alert alert-success">'.sprintf($this->l('%s is a valid VAT number'), $vatnumber).'</div>';
                $ret .= '<div class="well">';
                $ret .= '<b>'.$this->l('Trader name').':</b>&nbsp;'.Tools::safeOutput($res['name']).'<br/>';
                $ret .= '<b>'.$this->l('Trader address').':</b>&nbsp;'.Tools::safeOutput($res['address']).'<br/>';
                if ($res['name'] === '' && $res['address'] === '') {
                    $ret .= '<i>'.$this->l('Some countries (for example Germany) do not disclose trader name and address through VIES. The number is valid all the same.').'</i><br/>';
                }
                $ret .= '<b>'.$this->l('VIES consultation ID').':</b>&nbsp;'.Tools::safeOutput($res['requestIdentifier'] !== '' ? $res['requestIdentifier'] : $this->l('none - set your own VAT number in the settings to receive consultation IDs'));
                $ret .= '</div>';
                return $ret;
            }
            VatNumberCheck::recordResult($vatnumber, $prefix, VatNumberCheck::STATUS_INVALID, VatNumberCheck::SOURCE_VIES, $res['requestIdentifier']);

            return '<div class="alert alert-warning">'.sprintf($this->l('%s is NOT a valid VAT number'), $vatnumber).'</div>';
        } catch (VatNumberViesUnavailableException $e) {
            return '<div class="alert alert-warning">'.sprintf($this->l('VIES could not be reached (%s). Try again later.'), Tools::safeOutput($e->getMessage())).'</div>';
        } catch (Exception $e) {
            PrestaShopLogger::addLog('vatnumber: manual check of '.$vatnumber.' failed: '.$e->getMessage(), 3);

            return '<div class="alert alert-danger">'.sprintf($this->l('VAT number check failed: %s'), Tools::safeOutput($e->getMessage())).'</div>';
        }
    }
}
