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
modules/gateways/paystation/logs/                   Failure log, created automatically at runtime
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

### Merchant IPN (optional)

The callback file doubles as a PayStation Merchant IPN receiver, so you can give PayStation the
same URL as your IPN URL:

```
https://your-whmcs-install.example/modules/gateways/callback/paystation.php
```

An IPN is a server-to-server `POST` with a JSON body rather than a browser redirect, and is told
apart from a returning customer by that request shape. It is verified against the Transaction
Status API exactly like a browser callback — the notification itself is never trusted — and then
answered with an HTTP `200` JSON acknowledgement instead of a redirect. PayStation retries any
non-2xx response, so unconfigured credentials and a failed status lookup answer `503` to keep the
retry useful, while every settled outcome (including a genuinely failed payment) answers `200`.

Configuring the IPN is optional: the browser callback and cron reconciliation already settle every
payment between them. It mainly shortens the delay on payments the customer abandons after paying.

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

### Which currency an invoice is billed in

`tblinvoices.currency` is the first choice, but WHMCS leaves it at `0` on invoices created by code
paths that never set it, and older schemas have no such column at all. A `0` there is not an error,
so the module resolves the currency the way WHMCS itself bills the invoice:

1. `tblinvoices.currency` → `tblcurrencies.code`
2. the currency of the client the invoice belongs to (`tblclients.currency`)
3. the default currency (`tblcurrencies.default = 1`)
4. whichever currency row exists, lowest id first

Anything past step 1 is recorded as `PS-CURRENCY-FALLBACK` in the module log, naming the code used
and where it came from. Only an install with no usable `tblcurrencies` row at all fails, as
`PS-CURRENCY`. The resolved code decides whether a conversion rate is needed, so if the client is on
a non-BDT currency, set **Conversion Rate** in the gateway configuration.

---

## Diagnosing a failure

Every failure in this module is recorded in three places and shown on screen, so a payment that
does not go through never leaves you guessing.

**On the invoice page.** Instead of WHMCS's generic *"Unfortunately your payment attempt was not
successful"*, the module renders its own message naming the actual cause, an **error code** such as
`PS-DECLINED`, and a **reference** such as `PS9F2A41C7`. Customers see the plain explanation; an
admin, or anyone viewing while **Verbose Gateway Log** is enabled, additionally sees the full
technical reason and the path of the log file.

**In `modules/gateways/paystation/logs/paystation-YYYY-MM-DD.log`.** Written on every failure,
regardless of whether the WHMCS gateway log is enabled, and the first place to look:

```
[2026-02-14 09:31:07 UTC] PS-DECLINED  ref=PS9F2A41C7  invoice=1043
    reason: PayStation rejected the checkout request at https://api.paystation.com.bd/initiate-payment
            with status_code "2001" and status "failed": Invalid Credential [HTTP 200] - status_code
            2001 means the Merchant ID or Merchant Password is wrong for this environment.
    endpoint: https://api.paystation.com.bd/initiate-payment
    request: {"invoice_number":"1043-1739525467","cust_phone":"***5133 (11 digits)", ...}
    response: {"status_code":"2001","status":"failed","message":"Invalid Credential"}
```

Credentials are masked, and customer phone numbers, emails, names and addresses are reduced to
their shape. The directory is created on first use with an `.htaccess` and an `index.php` that deny
web access; on nginx, add an equivalent `location` deny rule.

**In the WHMCS logs.** The full context goes to **Billing → Gateway Log**, and a one-line summary
carrying the code, reference and log file path goes to **Utilities → Logs → Activity Log** — which,
unlike the gateway log, cannot be switched off.

### Error codes

| Code | What it means |
|---|---|
| `PS-NOT-ACTIVATED` | The gateway module is not activated in WHMCS. |
| `PS-NO-CREDENTIALS` | Merchant ID or Merchant Password is blank. |
| `PS-NO-SYSTEM-URL` | WHMCS System URL is unset, so the form and callback URLs cannot be built. |
| `PS-NO-CURL` | The PHP cURL extension is not loaded on this server. |
| `PS-DB-SCHEMA` | `mod_paystation_transactions` is missing and could not be created. |
| `PS-DB-WRITE` / `PS-DB-READ` | The ledger row or the invoice/client row could not be written or read. |
| `PS-TOKEN-EXPIRED` | The invoice page sat open longer than 30 minutes before Pay Now. |
| `PS-TOKEN-INVALID` | The signed Pay Now token did not verify (credentials changed, or a forged post). |
| `PS-NO-INVOICE` / `PS-INVOICE-MISSING` | No invoice id in the submission, or no such invoice. |
| `PS-INVOICE-OWNER` | The invoice belongs to a different client. |
| `PS-INVOICE-STATUS` | The invoice is Cancelled, Draft or Refunded. |
| `PS-CURRENCY` | No currency could be resolved at all — the invoice, the client and the default currency were all empty. |
| `PS-AMOUNT` | Nothing left to pay, or a non-BDT invoice with no conversion rate set. |
| `PS-NO-PHONE` | The client has no phone number; PayStation requires `cust_phone`. |
| `PS-TRANSPORT` | The request never reached PayStation (DNS, firewall, TLS, timeout). |
| `PS-DECLINED` | PayStation answered with a rejection; its own message is included. |
| `PS-NO-URL` | PayStation accepted the request but returned no `payment_url`. |
| `PS-BAD-URL` | The returned checkout URL was not HTTPS on a `paystation.com.bd` host. |
| `PS-CB-NO-MATCH` | A callback arrived that matches no local transaction. |
| `PS-CB-UNVERIFIED` | The status lookup failed; cron will retry, so do not pay again. |
| `PS-CB-DECLINED` | PayStation reported the payment did not succeed. |
| `PS-CB-MISMATCH` | Verified, but the amount or invoice number did not match — **manual review**. |
| `PS-CB-ORPHANED` | The payment succeeded but its WHMCS invoice no longer exists. |
| `PS-INTERNAL` | A swallowed database exception; the message names the operation that failed. |
| `PS-CURRENCY-FALLBACK` | Not a failure — the invoice named no currency, so the client's or the default one was used. |
| `PS-TRACE` | Not a failure — a full request/response trace, written only with verbose logging on. |

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

**"Unfortunately your payment attempt was not successful"** — That is WHMCS's own generic text for
`viewinvoice.php?id=N&paymentfailed=true`, and it says nothing about the cause. This module no
longer uses that redirect: it returns you to the invoice with its own message naming the real
reason, plus an error code and reference. If you are still seeing WHMCS's wording, either an older
copy of the module is installed, or the failed payment came from a *different* gateway.

To see the full technical reason on the page itself, tick **Verbose Gateway Log** in the gateway
configuration, or reproduce the failure while logged in as an admin. Either way the detail is in
`modules/gateways/paystation/logs/paystation-YYYY-MM-DD.log` — see
[Diagnosing a failure](#diagnosing-a-failure) for the error-code table. If the failure happened
*immediately* on clicking Pay Now, the PayStation checkout was never reached and the entry comes
from `redirect.php`; if it happened after returning from PayStation, it comes from the callback.

**`status_code 1001` / "Invalid Credential" on initiate-payment** — The credentials belong to the
other environment. Sandbox and production credentials are not interchangeable, and the endpoint is
chosen solely by the **Sandbox Mode** checkbox. You can confirm a credential pair outside WHMCS:

```bash
curl -X POST "https://api.paystation.com.bd/initiate-payment" \
  -d "merchantId=YOUR_ID" -d "password=YOUR_PASSWORD" \
  -d "invoice_number=test-$(date +%s)" -d "currency=BDT" -d "payment_amount=10" \
  -d "cust_name=Test" -d "cust_phone=01726315133" -d "cust_email=test@example.com" \
  -d "callback_url=https://example.com/cb.php"
```

Use `https://sandbox.paystation.com.bd` instead if Sandbox Mode is ticked. A `status_code` of
`"200"` plus a `payment_url` means the credentials and environment match.

**`PS-CURRENCY`** — Nothing in the database named a currency for this invoice: not the invoice, not
the client it belongs to, and no default currency. Add one under **Configuration → System Settings →
Currencies** and set it on the client. An invoice whose own `currency` column is `0` is *not* this
error — the module uses the client's currency and logs `PS-CURRENCY-FALLBACK` instead.

**`PS-NO-CREDENTIALS`** — Merchant ID or password is blank in the gateway config.

**`PS-TOKEN-EXPIRED`** — The invoice page sat open longer than 30 minutes. Reload the invoice and
click Pay Now again. **`PS-TOKEN-INVALID`** instead means the Merchant ID or password was changed
after the page was rendered — reload the invoice.

**`PS-NO-PHONE`** — PayStation requires `cust_phone`. Add a phone number to the client's profile.

**`PS-TRANSPORT`** — The request never reached PayStation at all. Check outbound HTTPS from the web
server; the log entry carries the exact cURL error.

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
