# Security Policy

This module takes payments. A defect in it can mean money is credited that was never received,
or received money that is never credited. Security reports are welcome and taken seriously.

## Supported versions

| Version | Supported |
|---|---|
| 1.x | ✅ Yes — fixes are released from `main` |
| < 1.0 | ❌ No |

Only the latest 1.x release is supported. There is no long-term-support branch.

## Reporting a vulnerability

**Please do not open a public issue, pull request or discussion for a security problem.**

Report it privately through GitHub:

1. Go to <https://github.com/riyadmunauwar/paystation-whmcs/security/advisories/new>
   (**Security → Advisories → Report a vulnerability**).
2. Describe the issue, the impact, and the steps to reproduce it.

If GitHub private reporting is not available to you, open a public issue that says only
*"security report, please provide a private contact"* — with **no technical detail** — and a
maintainer will follow up.

### What to include

- Affected file(s) and, if you have it, the module version or commit.
- WHMCS version and PHP version.
- A concrete attack path: what an attacker sends, and what they get.
- Whether you tested against sandbox or production credentials.
- Redact merchant IDs, merchant passwords, transaction ids and customer data from anything you
  attach. Gateway log excerpts often contain live values — please scrub them.

### What to expect

- Acknowledgement within **5 working days**.
- An assessment, and an intended fix window, within **14 days**.
- Credit in the release notes and the advisory, unless you ask to stay anonymous.

This is a volunteer-maintained project. There is no bug bounty and no paid reward.

## Scope

**In scope** — anything in this repository, in particular:

- Bypassing server-to-server verification, or getting an invoice marked paid without a verified
  successful payment.
- Double-crediting a payment (breaking settlement idempotency).
- Forging or replaying the signed Pay Now token, or paying another client's invoice.
- Amount or currency manipulation between the invoice, PayStation and the applied payment.
- Leaking merchant credentials into the gateway log, HTTP responses or redirect URLs.
- Open redirects out of `redirect.php`, SQL injection, or XSS in module output.

**Out of scope**:

- Vulnerabilities in WHMCS itself — report those to
  [WHMCS](https://www.whmcs.com/security-policy/).
- Vulnerabilities in PayStation's API or hosted checkout page — report those to
  [PayStation](https://paystation.com.bd/).
- Findings that require an already-compromised server, database or WHMCS admin account.
- Missing hardening headers on your own WHMCS installation.
- Automated scanner output with no demonstrated impact.

## Deployment notes

Two things about this module that matter when you assess it:

- **TLS verification is intentionally not configurable.** If you see certificate errors, fix the
  server's CA bundle (`curl.cainfo`); a patch that adds a "disable SSL verification" option will
  not be accepted.
- **The callback is untrusted by design.** PayStation publishes no callback signature. Callback
  parameters only select which transaction to verify; the outcome always comes from a
  server-to-server status call. A report that "the callback can be forged" needs to show that
  the forgery survives that verification step.

Operationally: keep **Verbose Gateway Log** off in production once you have verified the
integration, and restrict access to *Billing → Gateway Log*.
