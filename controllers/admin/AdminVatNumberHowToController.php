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
 * Back office how-to page (menu: Localization > VAT numbers how-to).
 * Read-only: explains what the module does, how to configure it, and
 * where to check a customer's VAT status.
 */
class AdminVatNumberHowToController extends ModuleAdminController
{
    /**
     * @throws PrestaShopException
     */
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * @return void
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function initContent()
    {
        parent::initContent();

        if (!$this->module instanceof VatNumber) {
            throw new PrestaShopException('The how-to page needs the vatnumber module instance');
        }
        $this->content .= $this->module->renderTabBar('howto');

        $this->context->smarty->assign([
            'vatnumber_orders_link' => $this->context->link->getAdminLink('AdminOrders', true),
            'vatnumber_addresses_link' => $this->context->link->getAdminLink('AdminAddresses', true),
            'vatnumber_logs_link' => $this->context->link->getAdminLink('AdminLogs', true),
            'vatnumber_pending' => VatNumberCheck::countPending(),
            'vatnumber_batch' => VatNumber::CRON_BATCH_SIZE,
            'vatnumber_max_batch' => VatNumber::CRON_MAX_BATCH_SIZE,
        ]);

        $this->content .= $this->createTemplate('howto.tpl')->fetch();
        $this->context->smarty->assign('content', $this->content);
    }
}
