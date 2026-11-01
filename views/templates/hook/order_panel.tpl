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
{* VAT panel in the right column of the order page, with the popup check. *}
<div class="panel" id="vatnumber-order-panel">
    <h3><i class="icon icon-euro"></i> {l s='VAT exemption' mod='vatnumber'}</h3>
    <p>
        {if $vatnumber_vat_charged}
            {l s='VAT was charged on this order.' mod='vatnumber'}
        {else}
            {l s='No VAT was charged on this order (exemption applied).' mod='vatnumber'}
        {/if}
    </p>
    {if $vatnumber_legacy}
        <p>{l s='This order was placed with the checkbox exemption of module versions before 2.7.4; there is no VAT number to verify.' mod='vatnumber'}</p>
    {else}
        <p>
            <b>{l s='VAT number on this order:' mod='vatnumber'}</b>
            {$vatnumber_number|escape:'html':'UTF-8'}
            <span class="vatnumber-badge"><span class="label {$vatnumber_badge.class|escape:'html':'UTF-8'}">{$vatnumber_badge.label|escape:'html':'UTF-8'}</span></span>
        </p>
        {if $vatnumber_status_date}
            <p><b>{l s='Current status since:' mod='vatnumber'}</b> {$vatnumber_status_date|escape:'html':'UTF-8'}</p>
            {if $vatnumber_trader_name}
                <p><b>{l s='Registered trader:' mod='vatnumber'}</b> {$vatnumber_trader_name|escape:'html':'UTF-8'}</p>
            {/if}
            {if $vatnumber_valid_date}
                <p>
                    <b>{l s='Last confirmed valid by VIES:' mod='vatnumber'}</b> {$vatnumber_valid_date|escape:'html':'UTF-8'}
                    {if $vatnumber_valid_proof}<br/>{l s='Consultation ID (proof):' mod='vatnumber'} {$vatnumber_valid_proof|escape:'html':'UTF-8'}{/if}
                </p>
            {/if}
            {if $vatnumber_status == 'invalid'}
                <div class="alert alert-danger">{l s='VIES rejected this number. If this order was exempted, review it and re-invoice the VAT if needed.' mod='vatnumber'}</div>
            {elseif $vatnumber_status == 'unverified'}
                <div class="alert alert-warning">{l s='This number is still awaiting verification. The scheduled task will verify it; you can also check it now.' mod='vatnumber'}</div>
            {/if}
        {else}
            <p>{l s='This number has never been checked against VIES.' mod='vatnumber'}</p>
        {/if}
        <p>
            <button type="button" class="btn btn-default" id="vatnumber-check-now"
                    data-url="{$vatnumber_check_url|escape:'html':'UTF-8'}"
                    data-id-order="{$vatnumber_id_order|intval}"
                    data-text-checking="{l s='Asking VIES, one moment please...' mod='vatnumber'}"
                    data-text-failed="{l s='The check could not be completed. Reload the page and try again.' mod='vatnumber'}"
                    data-text-trader="{l s='Trader name' mod='vatnumber'}"
                    data-text-address="{l s='Trader address' mod='vatnumber'}"
                    data-text-proof="{l s='Consultation ID (proof):' mod='vatnumber'}">
                <i class="icon icon-refresh"></i> {l s='Check now at VIES' mod='vatnumber'}
            </button>
        </p>
        <p class="help-block">{l s='This shows the current state of the number. Orders keep their own VAT totals; a later rejection does not change an invoice that was already issued.' mod='vatnumber'}</p>

        <div class="modal fade" id="vatnumber-check-modal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                        <h4 class="modal-title">{l s='VAT number check' mod='vatnumber'} {$vatnumber_number|escape:'html':'UTF-8'}</h4>
                    </div>
                    <div class="modal-body" id="vatnumber-check-result"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">{l s='Close' mod='vatnumber'}</button>
                    </div>
                </div>
            </div>
        </div>
    {/if}
</div>
