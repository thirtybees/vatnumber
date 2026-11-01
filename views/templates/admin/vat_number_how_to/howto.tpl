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
    <h3><i class="icon icon-info-circle"></i> {l s='VAT numbers: how it works' mod='vatnumber'}</h3>
    <p>{l s='Business customers in another EU country pay no VAT when they provide a valid VAT number (reverse charge). The customer enters the number on their address, the module validates it (first locally, then against VIES, the official EU database), and prices, cart and invoices for that customer switch to 0% VAT.' mod='vatnumber'}</p>
    <p>{l s='Customers from your own country always pay VAT. Spaces and dots in the number are fine, Greek numbers typed with the GR prefix are treated as EL, and Northern Ireland businesses (XI number, GB address) are supported.' mod='vatnumber'}</p>
</div>

<div class="panel">
    <h3><i class="icon icon-cogs"></i> {l s='The settings' mod='vatnumber'}</h3>
    <table class="table">
        <thead>
            <tr>
                <th>{l s='Setting' mod='vatnumber'}</th>
                <th>{l s='Meaning' mod='vatnumber'}</th>
                <th>{l s='Advice' mod='vatnumber'}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{l s='Always add VAT for customers from' mod='vatnumber'}</td>
                <td>{l s='Your own country: domestic customers always pay VAT.' mod='vatnumber'}</td>
                <td>{l s='Your own country' mod='vatnumber'}</td>
            </tr>
            <tr>
                <td>{l s='Check VAT number automatically' mod='vatnumber'}</td>
                <td>{l s='Have numbers confirmed by VIES.' mod='vatnumber'}</td>
                <td>{l s='On' mod='vatnumber'}</td>
            </tr>
            <tr>
                <td>{l s='Exempt only after successful check' mod='vatnumber'}</td>
                <td>{l s='Customers keep paying VAT until VIES has confirmed their number.' mod='vatnumber'}</td>
                <td>{l s='On is safer for you, off is friendlier for the customer' mod='vatnumber'}</td>
            </tr>
            <tr>
                <td>{l s='Also check numbers from your own country' mod='vatnumber'}</td>
                <td>{l s='Domestic numbers are confirmed by VIES on address save too, so a non-existing number cannot be saved.' mod='vatnumber'}</td>
                <td>{l s='On when a wrong number causes trouble in your accounting' mod='vatnumber'}</td>
            </tr>
            <tr>
                <td>{l s='Remove rejected numbers from addresses' mod='vatnumber'}</td>
                <td>{l s='A number VIES rejects is removed from the customer address; orders already placed keep their address as it was.' mod='vatnumber'}</td>
                <td>{l s='On when rejected numbers must not reach invoices' mod='vatnumber'}</td>
            </tr>
            <tr>
                <td>{l s='Your own VAT number' mod='vatnumber'}</td>
                <td>{l s='Sent along with every check; VIES then returns a consultation ID, the formal proof of the check.' mod='vatnumber'}</td>
                <td>{l s='Fill in' mod='vatnumber'}</td>
            </tr>
            <tr>
                <td>{l s='Only verify numbers used since' mod='vatnumber'}</td>
                <td>{l s='Drops queued numbers of customers with no address change and no order since that date.' mod='vatnumber'}</td>
                <td>{l s='A year or two back after an upgrade, otherwise empty' mod='vatnumber'}</td>
            </tr>
        </tbody>
    </table>
    <p>{l s='A number that VIES rejected never gets the exemption, as long as automatic checking is on.' mod='vatnumber'}</p>
</div>

<div class="panel">
    <h3><i class="icon icon-warning"></i> {l s='When VIES is down' mod='vatnumber'}</h3>
    <ul>
        <li>{l s='The number is accepted provisionally (its format was already checked) and marked as awaiting verification. The customer is never blocked.' mod='vatnumber'}</li>
        <li>{l s='For 5 minutes no new VIES requests are sent, so your server IP does not get rate limited.' mod='vatnumber'}</li>
        <li>{l s='When VIES or the database of one country is busy (too many requests at the same time), the module waits two seconds and asks once more. A busy country is skipped by the scheduled task until the next run; the other countries are checked as usual.' mod='vatnumber'}</li>
        <li>{l s='The scheduled task re-checks the number later. If VIES then rejects it, you get a log entry and the exemption stops for future orders; orders already placed must be reviewed and re-invoiced with VAT yourself.' mod='vatnumber'}</li>
        <li>{l s='Want these events by email? Set the minimum severity level to 3 in Advanced Parameters > Logs.' mod='vatnumber'}</li>
    </ul>
</div>

<div class="panel">
    <h3><i class="icon icon-time"></i> {l s='The scheduled task (important)' mod='vatnumber'}</h3>
    <p>{l s='The module settings page shows a cron URL under "Background verification". Call it every hour from a scheduled task (the cronjobs module or your server crontab). The task:' mod='vatnumber'}</p>
    <ul>
        <li>{l s='verifies numbers that are waiting (%d per run; append &limit=%d to the URL to drain a large queue faster);' sprintf=[$vatnumber_batch, $vatnumber_max_batch] mod='vatnumber'}</li>
        <li>{l s='queues numbers found on customer addresses that were never checked;' mod='vatnumber'}</li>
        <li>{l s='re-checks confirmed numbers every 180 days, because VAT registrations expire;' mod='vatnumber'}</li>
        <li>{l s='cleans up old, unused check results after 90 days.' mod='vatnumber'}</li>
    </ul>
    <p>{l s='Numbers currently awaiting verification: %d' sprintf=[$vatnumber_pending] mod='vatnumber'}</p>
    <p>{l s='The settings page warns you when numbers are waiting and the task has not run in the last 24 hours. An answer of all zeros from the cron simply means the queue is empty.' mod='vatnumber'}</p>
</div>

<div class="panel">
    <h3><i class="icon icon-search"></i> {l s='Checking a customer in the back office' mod='vatnumber'}</h3>
    <ol>
        <li>
            <b>{l s='The order' mod='vatnumber'}</b> ({l s='answers: was this order rightfully VAT exempt?' mod='vatnumber'}):
            {l s='open the order under' mod='vatnumber'} <a href="{$vatnumber_orders_link|escape:'html':'UTF-8'}">{l s='Orders' mod='vatnumber'}</a>.
            {l s='In the right column, the "VAT exemption" panel shows whether VAT was charged, the number used for this order with a colored status (green = confirmed by VIES, red = rejected, orange = awaiting verification, grey = never checked), when the number was last confirmed valid and the consultation ID of that confirmation as proof. Red comes with a warning to review the order and re-invoice VAT if needed. With "Check now at VIES" you check the number in a popup, without leaving the order.' mod='vatnumber'}
        </li>
        <li>
            <b>{l s='The address' mod='vatnumber'}</b> ({l s='answers: what is the current number?' mod='vatnumber'}):
            {l s='the VAT number always lives on the customer address, not on the customer card. Open it under' mod='vatnumber'} <a href="{$vatnumber_addresses_link|escape:'html':'UTF-8'}">{l s='Customers > Addresses' mod='vatnumber'}</a>.
            {l s='What is stored here decides the exemption of new orders; existing orders keep their own copy of the address as it was at the time of ordering. Saving an address with a wrong number in the back office shows a warning; the exemption is refused as long as the number is wrong.' mod='vatnumber'}
        </li>
        <li>
            <b>{l s='The rejected numbers' mod='vatnumber'}</b>:
            {l s='the tab "Rejected numbers" lists every number VIES rejected or that cannot exist because of its format, with customer, company, last order and date. Ask the customer for the correct number, and re-invoice VAT on orders placed without VAT if needed.' mod='vatnumber'}
        </li>
        <li>
            <b>{l s='The manual check' mod='vatnumber'}</b>:
            {l s='on the module settings page, under "VAT number validation", enter any number and click Check. Green means valid and the result counts as verified for that customer right away; red means contact the customer and re-invoice with VAT if needed. Some countries (for example Germany) do not disclose the trader name and address; the number is valid all the same.' mod='vatnumber'}
        </li>
        <li>
            <b>{l s='The log' mod='vatnumber'}</b>:
            {l s='every outage, rejection and failed check is logged with the prefix "vatnumber" under' mod='vatnumber'} <a href="{$vatnumber_logs_link|escape:'html':'UTF-8'}">{l s='Advanced Parameters > Logs' mod='vatnumber'}</a>.
        </li>
    </ol>
</div>

<div class="panel">
    <h3><i class="icon icon-lightbulb-o"></i> {l s='Good to know' mod='vatnumber'}</h3>
    <ul>
        <li>{l s='Confirmed numbers are served from the local cache for 30 days and re-verified every 180 days, because VAT registrations expire.' mod='vatnumber'}</li>
        <li>{l s='Uninstalling the module deletes the check results table, including the consultation IDs. Make a database backup first.' mod='vatnumber'}</li>
        <li>{l s='The checkbox based exemption without a VAT number of older versions has been removed; the VAT number is the only route to an exemption.' mod='vatnumber'}</li>
        <li>{l s='Keep "Company" in the address format of EU countries (Localization > Countries): a VAT number without a company name is refused. If the field is missing, the company name of the customer account is used instead.' mod='vatnumber'}</li>
        <li>{l s='With "Exempt only after successful check" on, a customer whose number could not be confirmed yet pays VAT on that order without being told why. Mention it in your terms, or refund the VAT once the number is confirmed.' mod='vatnumber'}</li>
        <li>{l s='A customer who enters more than a handful of different VAT numbers within an hour is asked to try again later; that is a script, not a typo.' mod='vatnumber'}</li>
    </ul>
</div>
