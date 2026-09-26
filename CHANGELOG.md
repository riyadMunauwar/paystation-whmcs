# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). For this module,
a **major** bump means a breaking change to the gateway configuration fields, the shape of
`mod_paystation_transactions`, or the payment flow.

## [Unreleased]

### Added

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
