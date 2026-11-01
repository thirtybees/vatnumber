{*
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
*}
<div class="panel">
    <h3><i class="icon icon-ban"></i> {l s='Rejected VAT numbers' mod='vatnumber'}</h3>
    <p>{l s='These numbers were rejected by VIES or cannot exist because of their format. Such a number never gets the VAT exemption. Customer and last order belong to the address the number was last seen on.' mod='vatnumber'}</p>
    <p>{l s='What to do: contact the customer for the correct number. If an order was placed without VAT under this number, check it and re-invoice the VAT if needed. A number the customer corrects is checked again automatically.' mod='vatnumber'}</p>
    {if $vatnumber_clear_rejected}
        <p><i class="icon icon-check"></i> {l s='Rejected numbers are removed from customer addresses automatically (setting on the Settings tab).' mod='vatnumber'}</p>
    {else}
        <p><i class="icon icon-info-circle"></i> {l s='Rejected numbers stay on the customer addresses. On the Settings tab you can have them removed automatically.' mod='vatnumber'}</p>
    {/if}
</div>
