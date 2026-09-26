# PayStation Payment Gateway for WHMCS

[![Lint](https://github.com/riyadmunauwar/paystation-whmcs/actions/workflows/lint.yml/badge.svg)](https://github.com/riyadmunauwar/paystation-whmcs/actions/workflows/lint.yml)
[![Latest release](https://img.shields.io/github/v/release/riyadmunauwar/paystation-whmcs?sort=semver)](https://github.com/riyadmunauwar/paystation-whmcs/releases/latest)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 7.4+](https://img.shields.io/badge/php-7.4%2B-777bb4.svg)](https://www.php.net/)
[![WHMCS 8.x | 9.x](https://img.shields.io/badge/whmcs-8.x%20%7C%209.x-005c9c.svg)](https://www.whmcs.com/)

A third-party (hosted checkout) payment gateway module integrating
[PayStation Bangladesh](https://paystation.com.bd/documentation) with WHMCS 8.x and 9.x.

Supports the checkout channels PayStation exposes — bKash, Nagad, Rocket, Upay, cards and EMI —
through PayStation's hosted checkout page, with server-to-server verification before any invoice
is marked as paid.

---

## Files

```
modules/gateways/paystation.php                     Gateway module (config + Pay Now button)
modules/gateways/paystation/whmcs.json              Module manifest (WHMCS needs this to list it)
modules/gateways/paystation/redirect.php            Creates the checkout session, redirects
modules/gateways/paystation/lib/loader.php          Class bootstrap
modules/gateways/paystation/lib/Api.php             PayStation REST client
modules/gateways/paystation/lib/Helper.php          Ledger, amounts, tokens, settlement
modules/gateways/callback/paystation.php            Return-URL handler + verification
includes/hooks/paystation_reconcile.php             Cron reconciliation of abandoned checkouts
```

Copy the `modules/` and `includes/` trees into your WHMCS root, preserving the paths above.

Requirements: PHP 7.4+ with `curl` and `json`, and outbound HTTPS to `api.paystation.com.bd`
(or `sandbox.paystation.com.bd`).

---

## Installation

Download the latest [release zip](https://github.com/riyadmunauwar/paystation-whmcs/releases/latest),
or clone this repository.

1. Extract the zip. It unpacks into a single versioned folder, e.g.
   `paystation-whmcs-1.0.0/`, which contains the `modules/` and `includes/` trees.
2. Upload the **contents** of that folder — the `modules/` and `includes/` directories
   themselves — into your WHMCS root, merging with the directories already there. Do **not**
   upload the `paystation-whmcs-1.0.0/` folder itself: WHMCS only scans `modules/gateways/`
   directly under its own root, so a nested copy is never detected.

   When it is in the right place, this path exists:

   ```
   <whmcs-root>/modules/gateways/paystation.php
   ```

3. Go to **Configuration → Apps & Integrations → Payments** (WHMCS 8.x/9.x), or
   **Configuration → System Settings → Payment Gateways → All Payment Gateways**.
4. Select **PayStation** to activate it, fill in the configuration (below) and
   **Save Changes**.
5. Give PayStation your callback URL if their dashboard asks for a whitelisted one:

   ```
   https://your-whmcs-domain.com/modules/gateways/callback/paystation.php
   ```

The module creates its own ledger table, `mod_paystation_transactions`, the first time it runs.
No manual SQL is needed. Deactivating the gateway leaves the table in place so historical
transactions remain auditable.

---

## Configuration

| Setting | Notes |
|---|---|
| **Merchant ID** | Issued by PayStation, e.g. `204-16537301811`. |
| **Merchant Password** | Issued by PayStation. Never leaves the server. |
| **Sandbox Mode** | Uses `sandbox.paystation.com.bd`. Sandbox and production credentials are **not** interchangeable. |
| **Invoice Number Prefix** | Optional. Prepended to every PayStation invoice number — useful when several systems share one merchant account. Letters, digits, `-` and `_` only. |
| **Gateway Charge Borne By** | Maps to `pay_with_charge`. Customer = `1`, Merchant = `0`. |
| **Request EMI** | Sends `emi=1`. Only enable if EMI is active on your merchant account. |
| **BDT Conversion Rate** | Only used for non-BDT invoices. How many BDT one unit of the invoice currency is worth (e.g. `120` for 1 USD = 120 BDT). |
| **Additional Service Charge (%)** | Percentage added to the amount sent to PayStation. |
| **Additional Service Charge (fixed)** | Flat amount added to the amount sent to PayStation, in the invoice currency. |
| **Cron Reconciliation** | Recommended on. Re-checks abandoned checkouts on every cron run. |
| **Verbose Gateway Log** | Logs full requests/responses (credentials masked). Turn off in production once verified. |

---

## Payment flow

```
Invoice page                 paystation_link()
  └─ signed "Pay Now" ──────► modules/gateways/paystation/redirect.php
                                 ├─ verify HMAC token, invoice, ownership, balance
                                 ├─ generate UNIQUE invoice_number
                                 ├─ INSERT pending row in mod_paystation_transactions
                                 ├─ POST /initiate-payment   (credentials, server-side only)
                                 └─ 302 ─────────────────────► PayStation hosted checkout
                                                                    │
                                            customer pays / fails    │
                                                                    ▼
                              modules/gateways/callback/paystation.php
                                 ├─ resolve transaction from callback data
                                 ├─ POST /transaction-status  (server-to-server)
                                 ├─ validate status + invoice + amount
                                 ├─ addInvoicePayment()  (idempotent)
                                 └─ 302 ─────────────────────► viewinvoice.php
```

If the customer never returns, `includes/hooks/paystation_reconcile.php` performs the same
verification on the next cron run.

---

## Invoice numbers

PayStation rejects a re-used `invoice_number` with `status_code 1008`, but a single WHMCS
invoice can legitimately be attempted many times (declined card, abandoned checkout, retry).

Every checkout attempt therefore gets a fresh invoice number built from the **WHMCS invoice ID
plus the current Unix timestamp**:

```
{prefix}{invoiceId}-{timestamp}          e.g.  1042-1757145600
{prefix}{invoiceId}-{timestamp}-{n}      collision suffix, same-second retries
```

The same value is also sent as the PayStation `reference` field, so it comes back on both
Transaction Status endpoints and can be used to reconcile a callback even if PayStation only
echoes the reference.

Mapping back to WHMCS never relies on parsing: `mod_paystation_transactions` is the source of
truth, and the string parse is only a last-resort fallback for returning the customer to the
right page.

---

## Security model

The module follows PayStation's own production checklist:

- **Credentials stay server-side.** They are only ever handled inside `Api`, injected at request
  time, and masked (`***masked***`) before anything reaches the gateway log.
- **The browser is never believed.** The callback is used only to work out *which* transaction to
  look at. Outcome, amount and status all come from `POST /transaction-status` (or
  `/v2/transaction-status` by `trxId` as a fallback).
- **TLS verification is always on.** `CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` are not configurable —
  these calls carry credentials and decide whether money was received.
- **Amount validation.** A payment is applied only when the amount PayStation reports covers the
  amount the module asked it to collect (1 paisa tolerance). Shortfalls are logged as a mismatch
  and **not** applied.
- **Invoice validation.** The `invoice_number` returned by PayStation must match the one the
  module generated for that transaction.
- **Signed Pay Now button.** `redirect.php` only creates checkout sessions for requests carrying a
  valid HMAC over `(invoiceId, userId, expiry)`, keyed on the merchant credentials, and
  additionally re-checks invoice ownership, status and balance server-side.
- **Redirect target validation.** The module refuses to redirect to a `payment_url` that is not an
  HTTPS URL on `paystation.com.bd`.
- **Idempotent settlement.** A single routine applies payments, guarded by both the local `applied`
  flag and a `tblaccounts.transid` lookup, so a refreshed callback, a duplicate notification and
  the cron sweep can never credit the same money twice.

---

## Amounts, surcharges and currency

WHMCS is **always credited in the invoice currency** with the invoice balance. Surcharges and
BDT conversion only affect the amount sent to PayStation:

```
invoice_amount   = invoice balance                         → credited to the WHMCS invoice
surcharge        = invoice_amount × pct% + fixed           → invoice currency
chargeable       = invoice_amount + surcharge
gateway_amount   = chargeable × conversionRate             → sent to PayStation, in BDT
```

For a BDT invoice with no surcharge configured (the default), all four are the same number.

`payment_amount` is sent with two decimal places (e.g. `1500.00`). PayStation's own status
responses return decimal amounts, so this matches their behaviour; if your merchant account is
provisioned to accept whole numbers only, keep BDT invoice totals integral.

---

## Gateway log

Everything lands under **Billing → Gateway Log**, keyed by context:

| Status | Meaning |
|---|---|
| `Payment Link Created` | Checkout session created, customer redirected. |
| `Success` | Verified and applied to the invoice. |
| `Pending` | PayStation reports `processing`; cron will retry. |
| `Unsuccessful` | `failed`, amount mismatch, invoice mismatch, or an initiation error. |
| `Verification Failed` | The status lookup itself failed; the row stays pending for cron. |
| `Cron Reconciliation Summary` | Sweep results, logged only when something changed. |

---

## Transaction states

`mod_paystation_transactions.status`:

| Value | Meaning |
|---|---|
| `pending` | Session created, customer sent to checkout. |
| `processing` | PayStation reports the payment is in flight. |
| `success` | Verified successful (see `applied` for whether WHMCS was credited). |
| `failed` | PayStation reports failure. |
| `refund` | PayStation reports a refund. |
| `mismatch` | Verified successful but the amount or invoice number did not match — **needs manual review**. |
| `orphaned` | The WHMCS invoice no longer exists. |
| `abandoned` | Still pending after 3 days; the customer never completed checkout. |

---

## Known limitations

- **No refund support.** PayStation's published API documents a `refund` transaction *status* but
  no refund *endpoint*, so `paystation_refund()` is deliberately not implemented. Issue refunds in
  the PayStation dashboard, then record them manually in WHMCS.
- **No gateway fee capture.** The Transaction Status API does not return the processing fee, so
  payments are recorded with a fee of `0`. If the merchant bears the charge
  (`pay_with_charge = 0`), reconcile fees from your PayStation settlement reports.
- **Callback payload is unspecified.** PayStation does not publish a callback parameter schema, so
  the callback accepts several common spellings of the invoice number and transaction id. This
  costs nothing in security terms because the callback is never trusted — it only selects which
  transaction to verify.
- **No signed callback.** PayStation provides no callback signature to verify, which is exactly
  why server-to-server verification is mandatory here rather than optional.

---

## Troubleshooting

**PayStation does not appear in the payment gateway list at all** — WHMCS only scans
`modules/gateways/` directly beneath its own root. Confirm that
`<whmcs-root>/modules/gateways/paystation.php` exists; if the release folder was uploaded whole
you will instead have `<whmcs-root>/paystation-whmcs-1.0.0/modules/gateways/paystation.php`, which
WHMCS never looks at. Also confirm `modules/gateways/paystation/whmcs.json` was uploaded — WHMCS
8.x/9.x uses that manifest to build the entry under **Apps & Integrations → Payments**. Clear any
PHP opcode cache after uploading.

**"PayStation is not fully configured"** — Merchant ID or password is blank in the gateway config.

**"Invalid or expired payment token"** — The invoice page sat open longer than 30 minutes. Reload
the invoice and click Pay Now again.

**Client has no usable phone number** — PayStation requires `cust_phone`. Add a phone number to
the client's profile.

**`status_code 2001` on verification** — Merchant ID mismatch, or the credentials belong to the
other environment. Confirm the Sandbox Mode setting matches the credentials in use.

**cURL error 60 (certificate problem)** — The server's CA bundle is stale. Fix it at the PHP level
(`curl.cainfo` in `php.ini`); TLS verification is intentionally not disable-able in this module.

**Payments taken but invoices unpaid** — Check that WHMCS cron is running and **Cron Reconciliation**
is enabled, then look for `Cron Reconciliation Summary` entries in the gateway log.

---

## Testing checklist

- [ ] Sandbox: successful payment marks the invoice paid exactly once.
- [ ] Sandbox: failed payment leaves the invoice unpaid and logs `Unsuccessful`.
- [ ] Refresh the callback URL after a successful payment — no double credit.
- [ ] Abandon the checkout, then run cron — the payment is picked up and applied.
- [ ] Two Pay Now clicks produce two different `invoice_number` values.
- [ ] Gateway log contains no plaintext merchant password.
- [ ] Non-BDT invoice with no conversion rate is refused with a clear message.
- [ ] Switch to production credentials, take one small live payment, then refund it manually.

---

## Contributing

Bug reports and pull requests are welcome. Please read
[CONTRIBUTING.md](CONTRIBUTING.md) first — it documents the development setup, the PHP 7.4
syntax floor, and the rules around verification, idempotency and credential handling that
changes must not weaken. All participants are expected to follow the
[Code of Conduct](CODE_OF_CONDUCT.md).

Release history lives in [CHANGELOG.md](CHANGELOG.md).

---

## Security

This module moves money. **Do not report security issues in a public issue or pull request** —
follow [SECURITY.md](SECURITY.md), which explains the private reporting route and what is in
scope.

---

## Support

This is community-maintained software with no warranty. Before opening an issue:

- **Module bugs** — [open an issue](https://github.com/riyadmunauwar/paystation-whmcs/issues)
  with your module, WHMCS and PHP versions, and redacted gateway log entries.
- **Merchant account, credentials, EMI activation, settlement reports, refunds** — these are
  PayStation's, not this module's: <https://paystation.com.bd/>
- **WHMCS itself, licensing, cron setup** — <https://www.whmcs.com/support/>

---

## License

[MIT](LICENSE) © 2026 Riyad Munauwar.

Not affiliated with, endorsed by, or supported by PayStation or WHMCS Ltd. "PayStation" and
"WHMCS" are the trademarks of their respective owners.
