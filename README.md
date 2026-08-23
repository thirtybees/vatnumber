# VAT Exemption Module

This module handles Value Added Tax (VAT) exemptions for a thirty bees shop.

## What it does

When a business customer in another EU country enters a valid VAT number on
their address, EU law says the sale is an intra-community supply: the shop
does not charge VAT ("reverse charge"). This module implements exactly that:

- The customer enters a VAT number on their address.
- The module validates the number (see below).
- Prices, cart, and invoices for that customer show 0% VAT.

The VAT number is the only route to an exemption. The checkbox based
exemption without a VAT number of versions before 2.7.4 has been removed:
it was a self declaration without any check, and while enabled it disabled
all VAT number checks. Upgrading clears its flag from addresses and logs how
many customers were affected.

The back office has a how-to page under Localization > VAT numbers how-to
with the settings explained, the outage behavior, and where to check a
customer (order page, address, manual check, log).

## How a VAT number is validated

Validation happens in three layers, cheapest first:

1. **Local checks, no network needed.** Company name must be filled in, the
   country prefix (NL, DE, ...) must be a known EU code, the prefix must
   match the address country, and the number must match the official format
   of that country (for example NL: 9 digits, B, 2 digits). Typos are caught
   here, instantly. Common input quirks are corrected automatically: spaces,
   dots and dashes are ignored, Greek numbers typed with the GR prefix are
   treated as EL, legacy 9 digit Belgian numbers get their leading zero
   restored, and Northern Ireland businesses (GB address, XI number) are
   supported.
2. **Local cache.** Numbers that VIES confirmed in the last 30 days are
   accepted from the local database without contacting VIES again.
3. **VIES.** Only when needed, the module asks VIES (the EU's official VAT
   number database) via its REST API, with strict timeouts (5s connect, 10s
   total). The VIES consultation ID is stored as proof of the check. If the
   REST interface fails and the PHP soap extension is available, the legacy
   SOAP interface is tried once as a silent fallback (same database, same
   answers) before the outage handling below kicks in.

## What happens when VIES is down

VIES and the member state databases behind it have outages regularly. The
module never blocks a customer because of that:

- The number is accepted **provisionally** (it already passed the local
  format checks) and recorded as "unverified". A number that VIES confirmed
  earlier keeps its confirmed status and its consultation ID; an outage
  never destroys proof of a past verification.
- The event is logged (severity 3). Set "Minimum severity level" in Advanced
  Parameters > Logs to 3 to get these by email.
- A circuit breaker stops all VIES requests for 5 minutes after a failure,
  so a struggling VIES is not hammered and your shop's IP does not get rate
  limited.
- A cron job re-checks unverified numbers until VIES answers, and re-checks
  confirmed numbers every 180 days (VAT registrations expire). If VIES later
  rejects a number, a log entry (severity 3) tells you to review that
  customer's orders and re-invoice VAT if needed - and from that moment the
  number no longer receives the exemption, in any mode.

The cron works in batches of 20 numbers per run (append `&limit=100` to
the cron URL to drain a large queue faster; 100 is the maximum per run).
After upgrading, all VAT numbers already stored on addresses are queued -
on an older shop that can be thousands. Use the "Only verify numbers used
since" setting to drop queued numbers of customers with no address change
and no order since that date: the queue then only contains customers who
still matter, and a dormant customer who returns simply gets checked live
at that moment.

Set up the cron by calling the URL shown on the module's configuration page
from a scheduled task (for example hourly, via the cronjobs module or your
server's crontab). The configuration page warns you when numbers are
waiting and the task has not run in the last 24 hours. The endpoint also
works while the shop is in maintenance mode.

## Orders while a number is unverified

You choose, with the "Exempt only after successful check" setting:

- **Enabled (default, merchant safe):** the customer keeps paying VAT until
  VIES has confirmed the number. Refund the VAT afterwards if you wish.
- **Disabled (customer friendly):** the exemption applies immediately. If the
  number later turns out invalid, you are notified, the exemption stops for
  future orders, and you can re-invoice the VAT for orders already placed.

When upgrading from an older module version, all VAT numbers already stored
on customer addresses are queued for background verification, so enabling
the strict mode does not suddenly withdraw the exemption from existing
customers before their numbers were ever checked.

Back office address edits by employees are checked the same way since
2.7.0, but a failed check warns the employee instead of blocking the save;
the exemption is refused regardless. CSV imports are not checked live; the
cron queues and verifies imported numbers afterwards.

## Known limitations

- Uninstalling drops the check table, including every VIES consultation ID.
  Export or back up the table first if the proof is still needed.
- In strict mode a customer whose number is not confirmed yet pays VAT on
  that order and is not told why by the module; mention it in your terms or
  refund afterwards.
- The bundled thirty bees theme only posts the VAT number field when it is
  visible (company filled in, EU country selected), and one of its templates
  uses a different DOM id for the field. A guest who changes country or
  clears the company can lose the number they typed; that is theme
  behaviour, reported upstream.
- A customer entering more than a handful of different VAT numbers within
  an hour is asked to try again later, to keep scripts from abusing VIES.
- Settings are per shop in multistore; the check table, the cron token, the
  cron state and the VIES circuit breaker are shared by all shops.

## Settings overview

| Setting | Meaning |
|---|---|
| Always add VAT for customers from | Your own country: domestic B2B sales always include VAT. |
| Check VAT number automatically | Ask VIES to confirm numbers (default on). |
| Exempt only after successful check | Withhold the exemption until VIES confirmed the number (default on). |
| Your own VAT number | Sent along with every VIES check; VIES then returns a consultation ID, the formal proof of the check. |
| Only verify numbers used since | Drop queued numbers of customers inactive since that date; applied on save and on every cron run. |

The cron also queues numbers that exist on customer addresses without a check
record (stored while checking was off, imported, or missed), so the queue
heals itself. Its JSON answer carries a plain words `message`, and all zeros
simply means the queue is empty.

## License

This software is published under the
[Academic Free License 3.0](https://opensource.org/licenses/afl-3.0.php)

## Contributing

thirty bees modules are Open Source extensions to the thirty bees e-commerce
solution. Everyone is welcome and even encouraged to contribute with their
own improvements.

For details, see
[CONTRIBUTING.md](https://github.com/thirtybees/thirtybees/blob/1.0.x/CONTRIBUTING.md)
in the thirty bees core repository.

## Packaging

To build a package for the thirty bees distribution machinery or suitable
for importing it into a shop, run `tools/buildmodule.sh` of the thirty bees
core repository from inside the module root directory.

For module development, one clones this repository into `modules/` of the
shop, alongside the other modules. It should work fine without packaging.
