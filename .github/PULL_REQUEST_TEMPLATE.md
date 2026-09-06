## What does this change?

<!-- One or two sentences. Link the issue it closes, e.g. "Closes #12". -->

## Why?

<!-- What breaks or is missing without it. -->

## Type of change

- [ ] Bug fix (no behaviour change beyond the fix)
- [ ] New feature
- [ ] Breaking change (config fields, `mod_paystation_transactions` shape, or payment flow)
- [ ] Documentation only
- [ ] Refactor / cleanup

## Testing

Which environment did you test against?

- [ ] Sandbox
- [ ] Production (a real, small payment)
- [ ] Not tested against PayStation (documentation-only change)

WHMCS version: <!-- e.g. 8.13.1 -->
PHP version: <!-- e.g. 8.2 -->

Scenarios run:

- [ ] Successful payment marks the invoice paid exactly once
- [ ] Failed payment leaves the invoice unpaid and logs `Unsuccessful`
- [ ] Refreshing the callback URL after success does not double-credit
- [ ] An abandoned checkout is picked up by the next cron run
- [ ] Two Pay Now clicks produce two different `invoice_number` values
- [ ] The gateway log contains no plaintext merchant password

<!-- Describe anything else you exercised, and anything you could not test. -->

## Checklist

- [ ] Syntax is PHP 7.4 compatible (no typed properties, arrow functions, `??=`, `match`)
- [ ] `php -l` passes on every changed file
- [ ] No new runtime dependencies (no Composer packages, no bundled libraries)
- [ ] Docblocks added/updated on new or changed functions
- [ ] README updated if config fields, log statuses, transaction states or the flow changed
- [ ] `CHANGELOG.md` updated under `## [Unreleased]`
- [ ] Any new database column has an idempotent "add if missing" upgrade path

## Money-safety review

Confirm none of these were weakened (see CONTRIBUTING.md → *Rules that exist for a reason*):

- [ ] Payment outcome, amount and invoice number still come from the server-to-server status
      call, never from callback parameters
- [ ] TLS peer/host verification is still unconditional
- [ ] Credentials still pass through `Helper::maskSecrets()` before reaching the gateway log
- [ ] Settlement is still idempotent (`applied` flag + `tblaccounts.transid` lookup)
- [ ] Each checkout attempt still gets a fresh `invoice_number`
- [ ] WHMCS is still credited in the invoice currency, with the invoice balance
- [ ] Redirect targets are still restricted to HTTPS on `paystation.com.bd`
- [ ] The Pay Now token is still signed and re-validated server-side

<!-- If you ticked "Breaking change", describe the upgrade path for existing installations. -->
