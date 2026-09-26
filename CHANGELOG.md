# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). For this module,
a **major** bump means a breaking change to the gateway configuration fields, the shape of
`mod_paystation_transactions`, or the payment flow.

## [Unreleased]

### Added

- **The reason a payment failed is now shown and logged.** Every failure path carries a stable
  error code (`PS-DECLINED`, `PS-TOKEN-EXPIRED`, `PS-NO-PHONE`, …) and a short reference, and is
  recorded in three places at once: a dated file under `modules/gateways/paystation/logs/`, the
  WHMCS gateway log, and the WHMCS activity log. The file log is written unconditionally, so it
  still exists when the gateway log has been switched off — previously the only record of a
  failure. Merchant credentials are masked, and customer phone numbers, emails, names and
  addresses are reduced to their shape.
- Verbose Gateway Log now also mirrors the full initiate-payment request and response into the
  module log file as a `PS-TRACE` entry.
- A README section, *Diagnosing a failure*, documenting the three log sinks and every error code.
- `modules/gateways/paystation/whmcs.json` module manifest. WHMCS 8.x/9.x reads this to build the
  gateway's entry under **Configuration → Apps & Integrations → Payments**; without it the module
  could be missing from that list even though the gateway file itself was valid. The release
  workflow now verifies the manifest is present in the archive.

### Changed

- Installation instructions now spell out that only the **contents** of the release folder go into
  the WHMCS root, and name the exact path to check. The previous wording claimed the zip unpacked
  straight to `modules/`/`includes/`, but `git archive` wraps them in a versioned folder — uploading
  that folder whole leaves the module somewhere WHMCS never scans.
- Added a troubleshooting entry for the gateway not appearing in the payment gateway list.

### Fixed

- **An invoice that does not name its own currency is payable again.** `redirect.php` read
  `tblinvoices.currency` and aborted with `PS-CURRENCY` ("Could not resolve the currency code for
  tblcurrencies id 0") whenever that column was `0` — which WHMCS leaves it at on invoices created
  by code paths that never set it, and which is all there is to read on schemas without the column.
  Every payment on such an invoice was impossible. The currency is now resolved the way WHMCS
  itself bills the invoice: the invoice's own currency, then the owning client's, then the default
  currency, then any currency row at all. Only an install with no usable `tblcurrencies` row still
  fails, and the failure now lists what each lookup returned. Falling back is not an error but is
  recorded as `PS-CURRENCY-FALLBACK` in the module log, naming the code used and its source.
- `paystation_link()` no longer renders a button whose amount summary says *"Set a conversion rate
  for  in the gateway configuration"* when WHMCS passes an empty currency: it resolves the currency
  the same way `redirect.php` does, so the invoice page and the checkout always agree.
- A failed payment no longer shows WHMCS's generic *"Unfortunately your payment attempt was not
  successful. Please try again or contact support."* That banner is WHMCS's own text for
  `viewinvoice.php?id=N&paymentfailed=true` and names no cause, which made every failure —
  wrong credentials, a missing conversion rate, an unreachable API, a declined wallet — look
  identical. `redirect.php` and the callback now return the customer to the invoice with the
  module's own message naming the actual cause, the error code and the reference. Admins, and
  anyone viewing with Verbose Gateway Log enabled, additionally see the full technical reason and
  the log file path on the page itself.
- Database exceptions in `Helper` are no longer silently swallowed. `ensureSchema()`,
  `createTransaction()`, `invoiceBalance()` and the lookup helpers all degraded to a bare `false`
  or `null`, which made a missing `CREATE` privilege indistinguishable from an ordinary empty
  result; the message is now captured, logged as `PS-INTERNAL`, and surfaced in the failure that
  follows.
- `redirect.php` now fails fast, and says so, when the ledger table cannot be created or the PHP
  cURL extension is missing, rather than proceeding to a call that cannot work. `paystation_link()`
  makes the same checks before rendering a button, and also catches an unset WHMCS System URL,
  which silently produced an unusable form action.
- The callback's failure paths now explain themselves too, and distinguish a genuine decline
  (`PS-CB-DECLINED`) from an unverified payment (`PS-CB-UNVERIFIED`) and from a verified payment
  that did not match the invoice (`PS-CB-MISMATCH`) — the last two tell the customer explicitly not
  to pay again.
- The callback endpoint now answers PayStation's Merchant IPN with an HTTP 2xx JSON
  acknowledgement instead of a `302` redirect. PayStation treats any non-2xx response as a failed
  delivery and retries, so pointing the merchant IPN URL at this file previously produced an
  endless retry loop even though every notification had in fact been processed. A browser coming
  back from the hosted checkout is still redirected to its invoice; the two are told apart by the
  IPN's `POST` + `application/json` request shape. Conditions a retry could genuinely resolve —
  unconfigured credentials, and a failed status lookup — answer `503` so the retry still happens.
- `paystation_link()` no longer renders a Pay Now button when the client has no usable phone
  number. PayStation requires `cust_phone`, so `redirect.php` aborted on it, and the customer was
  bounced straight back to the invoice with WHMCS's generic "your payment attempt was not
  successful" message and no indication of what to fix. The invoice now explains it up front.
- `redirect.php` now aborts with a clear gateway-log entry when the Merchant ID or password is
  blank, instead of generating a PayStation invoice number and posting a request that cannot
  authenticate. The callback already made this check.

### Removed

- The undocumented `VisibleDefault` key from `paystation_MetaData()`. It is not a WHMCS gateway
  metadata parameter and did not do what its comment claimed.

## [1.0.0] - 2026-09-06

Initial public release: a third-party (hosted checkout) PayStation Bangladesh gateway for
WHMCS 8.x and 9.x.

### Added

- **Gateway module** (`modules/gateways/paystation.php`) with configuration for merchant
  credentials, sandbox mode, invoice number prefix, gateway charge bearer, EMI, BDT conversion
  rate, percentage and fixed surcharges, cron reconciliation and verbose logging.
- **Hosted checkout redirect** (`modules/gateways/paystation/redirect.php`) that creates the
  PayStation session server-side via `POST /initiate-payment` and redirects the customer.
- **Signed Pay Now button** — an HMAC over `(invoiceId, userId, expiry)` keyed on the merchant
  credentials, with server-side re-checks of invoice ownership, status and balance, and a
  30-minute token lifetime.
- **Server-to-server verification** in the callback (`modules/gateways/callback/paystation.php`)
  via `POST /transaction-status`, falling back to `POST /v2/transaction-status` by `trxId`.
  Callback parameters are used only to select which transaction to verify.
- **Idempotent settlement** guarded by both a local `applied` flag and a `tblaccounts.transid`
  lookup, so refreshed callbacks, duplicate notifications and the cron sweep cannot double-credit.
- **Unique invoice numbers per attempt** — `{prefix}{invoiceId}-{timestamp}` with a collision
  suffix — to avoid PayStation's `status_code 1008` on re-used invoice numbers. The same value is
  sent as the `reference`.
- **Amount and invoice validation** before any payment is applied: the reported amount must cover
  the requested amount (1 paisa tolerance) and the returned `invoice_number` must match.
- **Redirect target validation** — only HTTPS URLs on `paystation.com.bd` are followed.
- **Credential masking** in the gateway log; TLS peer and host verification are always on and not
  configurable.
- **Surcharge and BDT conversion handling** — WHMCS is always credited in the invoice currency;
  percentage and fixed surcharges and the conversion rate affect only the amount sent to
  PayStation. Non-BDT invoices without a configured rate are refused with a clear message.
- **Cron reconciliation hook** (`includes/hooks/paystation_reconcile.php`) that re-verifies
  abandoned checkouts on every WHMCS cron run and marks anything still pending after 3 days as
  `abandoned`.
- **Transaction ledger** `mod_paystation_transactions`, created automatically on first run and
  retained on deactivation, with states `pending`, `processing`, `success`, `failed`, `refund`,
  `mismatch`, `orphaned` and `abandoned`.
- **Gateway log contexts** — `Payment Link Created`, `Success`, `Pending`, `Unsuccessful`,
  `Verification Failed` and `Cron Reconciliation Summary`.

### Known limitations

- No refund support — PayStation publishes a `refund` transaction status but no refund endpoint,
  so `paystation_refund()` is deliberately not implemented.
- No gateway fee capture — the Transaction Status API does not return the processing fee, so
  payments are recorded with a fee of `0`.
- No signed callback — PayStation provides no callback signature, which is why server-to-server
  verification is mandatory rather than optional.

[Unreleased]: https://github.com/riyadmunauwar/paystation-whmcs/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/riyadmunauwar/paystation-whmcs/releases/tag/v1.0.0
