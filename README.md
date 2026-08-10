# VAT Exemption Module

This module handles Value Added Tax (VAT) exemptions for a thirty bees shop.

## What it does

When a business customer in another EU country enters a valid VAT number on
their address, EU law says the sale is an intra-community supply: the shop
does not charge VAT ("reverse charge"). This module implements exactly that:

- The customer enters a VAT number on their address.
- The module validates the number (see below).
- Prices, cart, and invoices for that customer show 0% VAT.

It also supports manual exemptions without a VAT number (for example Group 12
of Schedule 8 of the Value Added Tax Act 1994 in the United Kingdom) via a
simple checkbox, enabled with the "Allow manual verification" setting.

## How a VAT number is validated

Validation happens in three layers, cheapest first:

1. **Local checks, no network needed.** Company name must be filled in, the
   country prefix (NL, DE, ...) must be a known EU code, the prefix must
   match the address country, and the number must match the official format
   of that country (for example NL: 9 digits, B, 2 digits). Typos are caught
   here, instantly.
2. **Local cache.** Numbers that VIES confirmed in the last 30 days are
   accepted from the local database without contacting VIES again.
3. **VIES.** Only when needed, the module asks VIES (the EU's official VAT
   number database) via its REST API, with strict timeouts (5s connect, 10s
   total). The VIES consultation ID is stored as proof of the check.

## What happens when VIES is down

VIES and the member state databases behind it have outages regularly. The
module never blocks a customer because of that:

- The number is accepted **provisionally** (it already passed the local
  format checks) and recorded as "unverified".
- The event is logged (severity 3). Set "Minimum severity level" in Advanced
  Parameters > Logs to 3 to get these by email.
- A cron job re-checks unverified numbers until VIES answers, and re-checks
  confirmed numbers every 180 days (VAT registrations expire). If VIES later
  rejects a number, a log entry (severity 3) tells you to review that
  customer's orders and re-invoice VAT if needed.

Set up the cron by calling the URL shown on the module's configuration page
from a scheduled task (for example hourly, via the cronjobs module or your
server's crontab).

## Orders while a number is unverified

You choose, with the "Exempt only after successful check" setting:

- **Disabled (default, customer friendly):** the exemption applies
  immediately. If the number later turns out invalid, you are notified and
  can re-invoice the VAT.
- **Enabled (merchant safe):** the customer keeps paying VAT until VIES has
  confirmed the number. Refund the VAT afterwards if you wish.

## Settings overview

| Setting | Meaning |
|---|---|
| Always add VAT for customers from | Your own country: domestic B2B sales always include VAT. |
| Allow manual verification | Adds a VAT exemption checkbox not tied to a VAT number. |
| Check VAT number automatically | Ask VIES to confirm numbers (recommended). |
| Exempt only after successful check | Withhold the exemption until VIES confirmed the number. |

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
