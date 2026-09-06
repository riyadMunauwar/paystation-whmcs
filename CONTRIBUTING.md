# Contributing

Thanks for taking the time to look at this module. It handles real money, so the bar for
changes is deliberately a little higher than for an average PHP library. This document explains
what that means in practice.

By participating you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).

---

## Ways to help

- **Report a bug** — open an [issue](https://github.com/riyadmunauwar/paystation-whmcs/issues)
  using the bug template.
- **Report a security issue** — do **not** open a public issue. Follow
  [SECURITY.md](SECURITY.md).
- **Suggest a feature** — open an issue first, before writing code. PayStation's API surface is
  small and this module deliberately does not implement things the API does not support (see
  *Known limitations* in the [README](README.md)).
- **Send a pull request** — see below.

---

## Development setup

There is no build step, no `composer install`, and no autoloader to generate. The module is
plain PHP that is copied into a WHMCS installation.

1. Get a WHMCS 8.x or 9.x installation you are allowed to break (a local or staging licence).
2. Clone this repository somewhere outside the WHMCS root.
3. Symlink or copy the two trees into the WHMCS root, preserving paths:

   ```
   modules/gateways/paystation.php
   modules/gateways/paystation/
   modules/gateways/callback/paystation.php
   includes/hooks/paystation_reconcile.php
   ```

   On Linux/macOS, symlinks make iteration much faster:

   ```bash
   ln -s /path/to/repo/modules/gateways/paystation.php      /path/to/whmcs/modules/gateways/paystation.php
   ln -s /path/to/repo/modules/gateways/paystation          /path/to/whmcs/modules/gateways/paystation
   ln -s /path/to/repo/modules/gateways/callback/paystation.php /path/to/whmcs/modules/gateways/callback/paystation.php
   ln -s /path/to/repo/includes/hooks/paystation_reconcile.php  /path/to/whmcs/includes/hooks/paystation_reconcile.php
   ```

4. Activate **PayStation** under *Configuration → System Settings → Payment Gateways*.
5. Enter **sandbox** credentials, tick **Sandbox Mode**, and turn on **Verbose Gateway Log**
   while you work.

Requirements: PHP 7.4+ with `curl` and `json`, and outbound HTTPS to
`sandbox.paystation.com.bd`.

---

## Coding standards

Match the surrounding code. Concretely:

- **PSR-12-ish**: 4 spaces, no tabs, LF endings, one blank line at end of file.
  [`.editorconfig`](.editorconfig) covers most of it.
- **PHP 7.4 compatible syntax.** WHMCS installations run on a wide range of PHP versions and
  the existing code intentionally avoids newer syntax (no typed properties, no arrow functions,
  no `??=`, no named arguments, no `match`). CI lints against 7.4 through 8.4.
- **Docblocks on every function and class method**, in the existing style, with `@param` and
  `@return`.
- **No new runtime dependencies.** No Composer packages, no bundled libraries. The module must
  keep working as a plain file copy into a WHMCS root.
- **Namespaced classes** live in `modules/gateways/paystation/lib/` under
  `WHMCS\Module\Gateway\Paystation` and are wired up by `lib/loader.php` — add new classes
  there rather than introducing an autoloader.
- **Gateway functions** (`paystation_MetaData`, `paystation_config`, `paystation_link`, …) must
  keep the `paystation_` prefix WHMCS requires.

If you have `php` on your PATH, this is the same syntax check CI runs:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

---

## Rules that exist for a reason

Please do not "simplify" these away in a pull request. Each one is load-bearing:

- **Never trust the callback.** Callback parameters may only be used to decide *which*
  transaction to verify. Status, amount and invoice number come from a server-to-server
  `POST /transaction-status`.
- **TLS verification stays on.** `CURLOPT_SSL_VERIFYPEER` / `CURLOPT_SSL_VERIFYHOST` must not
  become configurable. These calls carry credentials and decide whether money arrived.
- **Credentials stay inside `Api`** and must pass through `Helper::maskSecrets()` before they
  can reach the gateway log. Adding a new credential-ish field means adding its key to the mask
  list.
- **Settlement stays idempotent.** Payments are applied through the single guarded routine in
  `Helper`, protected by both the local `applied` flag and a `tblaccounts.transid` lookup. A
  refreshed callback, a duplicate notification and the cron sweep must never credit twice.
- **Every checkout attempt gets a fresh `invoice_number`.** PayStation rejects re-use with
  `status_code 1008`, and one WHMCS invoice can legitimately be attempted many times.
- **WHMCS is credited in the invoice currency.** Surcharges and BDT conversion only change the
  amount sent to PayStation, never the amount credited to the invoice.
- **Redirect targets are validated.** Only HTTPS URLs on `paystation.com.bd` are followed.
- **The Pay Now button stays signed.** `redirect.php` must keep verifying the HMAC over
  `(invoiceId, userId, expiry)` *and* re-checking invoice ownership, status and balance.

If you believe one of these is wrong, open an issue and make the argument there first.

---

## Database changes

`mod_paystation_transactions` is created on first run and is never dropped, so historical
transactions stay auditable after a deactivation. If a change needs a new column:

- add it in the same place the table is created, **and**
- add an idempotent "add column if missing" upgrade path, so existing installations are
  migrated on the next run,
- and note it in [CHANGELOG.md](CHANGELOG.md).

Never write a migration that drops a column or a table containing payment history.

---

## Pull requests

1. Branch off `main`.
2. Keep the change focused — one behavioural change per PR. Formatting-only churn in files you
   are not otherwise touching makes review harder; please leave it out.
3. Update the [README](README.md) if you change configuration fields, log statuses, transaction
   states, or the payment flow.
4. Add an entry under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
5. Fill in the PR template, including the testing checklist — say which of the scenarios you
   actually ran, and against sandbox or production.
6. Expect review questions on anything touching verification, amounts or idempotency.

### Testing checklist

Before marking a PR ready, run at least the sandbox scenarios from the README:

- [ ] Successful payment marks the invoice paid exactly once.
- [ ] Failed payment leaves the invoice unpaid and logs `Unsuccessful`.
- [ ] Refreshing the callback URL after success does not double-credit.
- [ ] An abandoned checkout is picked up by the next cron run.
- [ ] Two Pay Now clicks produce two different `invoice_number` values.
- [ ] The gateway log contains no plaintext merchant password.

---

## Releases

Maintainer notes:

1. Move `## [Unreleased]` entries into a new version heading in `CHANGELOG.md` with a date.
2. Bump `@version` in the module docblocks.
3. Tag `vX.Y.Z` and push the tag — the release workflow builds an install zip from
   `git archive` (repository furniture is excluded via `.gitattributes`) and attaches it to the
   GitHub release.

Versioning follows [Semantic Versioning](https://semver.org/): a breaking change to
configuration fields, the stored table shape, or the payment flow is a major bump.
