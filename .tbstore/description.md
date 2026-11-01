This module handles the EU reverse charge for business customers: when a customer in another EU country enters a valid VAT number on their address, prices, cart and invoices for that customer switch to 0% VAT.

Numbers are validated offline first (format per country) and then confirmed against VIES, the official EU database, with a local cache, a circuit breaker for VIES outages, a scheduled re-check of stored numbers, and a back office how-to page with everything a merchant needs to know. When VIES is down, customers are never blocked.
