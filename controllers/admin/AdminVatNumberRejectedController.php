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
 * Back office list of rejected VAT numbers: numbers VIES rejected and
 * stored numbers with an impossible format, with the customer and the last
 * order placed with the address the number was seen on. Read-only; reached
 * through the module's tab bar.
 */
class AdminVatNumberRejectedController extends ModuleAdminController
{
    /**
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'vatnumber_check';
        $this->identifier = 'id_vatnumber_check';
        $this->className = 'VatNumberCheck';
        $this->list_no_link = true;
        $this->_defaultOrderBy = 'date_upd';
        $this->_defaultOrderWay = 'DESC';
        $this->_select = 'CONCAT(c.`firstname`, \' \', c.`lastname`) AS `customer_name`, c.`company` AS `customer_company`,'
            .' (SELECT MAX(o.`id_order`) FROM `'._DB_PREFIX_.'orders` o WHERE a.`id_address` > 0 AND o.`id_address_invoice` = a.`id_address`) AS `last_order`';
        $this->_join = 'LEFT JOIN `'._DB_PREFIX_.'customer` c ON c.`id_customer` = a.`id_customer`';

        // The module, and with it its classes, is only loaded by the parent
        // constructor.
        parent::__construct();

        $this->_where = 'AND a.`status` = \''.pSQL(VatNumberCheck::STATUS_INVALID).'\'';

        $this->fields_list = [
            'vat_number' => [
                'title' => $this->l('VAT number'),
            ],
            'country_code' => [
                'title'    => $this->l('Country'),
                'align'    => 'center',
                'class'    => 'fixed-width-xs',
                'callback' => 'renderCountry',
            ],
            'source' => [
                'title'       => $this->l('Reason'),
                'type'        => 'select',
                'list'        => [
                    VatNumberCheck::SOURCE_VIES   => $this->l('Rejected by VIES'),
                    VatNumberCheck::SOURCE_FORMAT => $this->l('Impossible format'),
                ],
                'filter_key'  => 'a!source',
                'callback'    => 'renderReason',
            ],
            'customer_name' => [
                'title'        => $this->l('Customer'),
                'havingFilter' => true,
            ],
            'customer_company' => [
                'title'        => $this->l('Company'),
                'havingFilter' => true,
            ],
            'last_order' => [
                'title'    => $this->l('Last order'),
                'search'   => false,
                'align'    => 'center',
                'callback' => 'renderOrderLink',
            ],
            'date_upd' => [
                'title' => $this->l('Rejected on'),
                'type'  => 'datetime',
            ],
        ];
    }

    /**
     * List page with the module's tab bar and a short explanation on top.
     *
     * @return void
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function initContent()
    {
        if (!$this->module instanceof VatNumber) {
            throw new PrestaShopException('The rejected numbers page needs the vatnumber module instance');
        }
        $this->content = $this->module->renderTabBar('rejected');
        $this->context->smarty->assign([
            'vatnumber_clear_rejected' => (bool) Configuration::get('VATNUMBER_CLEAR_REJECTED'),
        ]);
        $this->content .= $this->createTemplate('intro.tpl')->fetch();

        parent::initContent();
    }

    /**
     * The list is read-only: no "Add new" button.
     *
     * @return void
     * @throws PrestaShopException
     */
    public function initToolbar()
    {
        parent::initToolbar();
        unset($this->toolbar_btn['new']);
    }

    /**
     * List callback: the VIES country code, or '--' for a number whose first
     * two characters are no country code at all (impossible format).
     *
     * @param string|null $value Raw country_code column.
     * @param array<string,mixed> $row
     *
     * @return string
     */
    public function renderCountry($value, $row): string
    {
        return in_array((string) $value, VatNumber::getPrefixIntracomVAT(), true) || $value === 'XI' ? (string) $value : '--';
    }

    /**
     * List callback: the reason in words.
     *
     * @param string|null $value Raw source column.
     * @param array<string,mixed> $row
     *
     * @return string
     */
    public function renderReason($value, $row): string
    {
        return $value === VatNumberCheck::SOURCE_VIES ? $this->l('Rejected by VIES') : $this->l('Impossible format');
    }

    /**
     * List callback: link to the last order placed with the address the
     * number was seen on.
     *
     * @param string|null $value Order id, or null when there is none.
     * @param array<string,mixed> $row
     *
     * @return string
     * @throws PrestaShopException
     */
    public function renderOrderLink($value, $row): string
    {
        $idOrder = (int) $value;
        if (!$idOrder) {
            return '--';
        }
        $url = $this->context->link->getAdminLink('AdminOrders', true).'&id_order='.$idOrder.'&vieworder';

        return '<a href="'.Tools::safeOutput($url).'">'.$idOrder.'</a>';
    }
}
