# Changelog

All notable changes to this project are documented here. This file is for
developers; the end-user changelog lives in `readme.txt`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- stripe-php 22 and Stripe API version `2026-09-30.endive` (from 21.3.2 and `2026-08-26.dahlia`). Endive removed the writable `payment_method_types` on PaymentIntents, so the bank transfer intent now passes `allowed_payment_method_types: ['customer_balance']`. Dynamic payment methods were not an option: the intent is confirmed on creation with a `customer_balance` payment method, and without an explicit list Stripe falls back to the dashboard's payment methods and demands a `return_url`. The allowed list cannot be combined with `payment_method_configuration`, so it replaces the dashboard configuration rather than filtering it, and the gateway keeps working when a merchant has bank transfers switched off there, as before.

## [1.0.2] - 2026-10-09

### Fixed
- `phpunit.integration.xml` is no longer packaged: `.distignore` only excluded `phpunit.xml`.
- The release deploy copied all of `.wordpress-org/assets` into SVN, so `banner.svg` and the README landed there next to the served art. `bin/stage-wporg-assets.sh` now holds the one list of deployable files, used by both deploy workflows, and the assets deploy deletes anything in SVN `assets/` that is not on it.
- The release deploy installs `dist-archive` with the same pin, auth and retry fixes as the test workflow.

### Added
- CI now runs Plugin Check against the built zip and a translation completeness
  check. Both cover failure modes nothing caught before: "Tested up to" drift is
  silent until the plugin disappears from wp.org search, and a lost `msgstr` still
  parses, still builds a `.mo`, and is invisible to the test suite.

## [1.0.1] - 2026-08-31

### Fixed
- `translate:pot` now scans `templates/` as well. It only looked at the plugin file
  and `src/`, so every string in the awaiting-transfer email templates was dropped
  from the POT, and their German translations were deleted from both locales on any
  `bun run translate`. Latent since the email templates were added.
- The GiroCode no longer falls back to the shop name when Stripe omits
  `account_holder_name`. Encoding a beneficiary the IBAN is not registered under
  guarantees a Verification of Payee mismatch on a payment we generated ourselves,
  so the QR code is now dropped instead and the customer uses the details table.

### Changed
- Updated the bundled Stripe SDK to stripe-php 21 and moved the pinned Stripe API
  version from `2023-10-16` to `2026-08-26.dahlia` to match it. The two are coupled:
  stripe-php 21 raises Stripe's "outdated API version" notice as an `E_USER_WARNING`
  on every API call, so an SDK bump alone would emit a PHP warning per order on a
  live store. A unit test now fails if the pin drifts from the SDK's target.
- Tested up to WordPress 7.1. Dev-only toolchain updated to match: `wordpress-stubs`
  7.1, `woocommerce-stubs` 11.0, PHPStan 2.2.10. None of these ship in the plugin zip.
- "Account Holder Name" is now the first row for SEPA (`iban`) and Bacs
  (`sort_code`) addresses. Since VoP became mandatory on 9 October 2025 it is the
  field the customer must transcribe most carefully.
- SEPA payment instructions carry a Verification of Payee note explaining that the
  payer's bank checks the recipient name against the IBAN, and that the payee of
  record is the Stripe business name rather than the shop name. Filterable via
  `btpw_vop_notice`; shown on the thank-you page and in both HTML and plain-text
  emails.

## [1.0.0] - 2026-08-13

Initial release.

### Added
- WooCommerce Cart/Checkout **blocks** support (the gateway is otherwise invisible in
  block-based checkout, which is the WooCommerce default).
- Full and partial **refunds** via `process_refund()` / the `refunds` gateway capability.
- **GiroCode (EPC069-12)** QR on the thank-you page for SEPA/EUR orders, rendered from a
  locally vendored MIT QR library (no CDN, DSGVO-safe).
- Handling for `payment_intent.partially_funded` (underpayments), with a
  `btpw_payment_partially_funded` hook.
- Configurable awaiting-payment order status.
- Optional manual-renewal WooCommerce Subscriptions support.
- `Features` capability layer providing the declarative free/Pro tier split.
- Stripe bank-transfer payment gateway using the `customer_balance` funding flow.
- Per-order virtual bank account details (SEPA / ACH / Bacs / SPEI) on the
  thank-you page and in order emails.
- Automatic reconciliation via signed Stripe webhooks (succeeded, failed,
  cancelled, processing, requires_action).
- Custom "Awaiting Bank Transfer" order status.
- Pluggable Stripe-credential reuse via an adapter registry: the official
  WooCommerce Stripe Gateway, Payment Plugins for Stripe WooCommerce, and
  WP Swings' Payment Gateway Stripe and WooCommerce Integration — extendable
  through the `btpw_stripe_plugin_adapters` filter.
- Admin customer balance / virtual-account view on the user profile screen,
  driven by a capability-checked REST endpoint.
- HPOS (High-Performance Order Storage) compatibility.

### Security
- Webhooks are rejected unless a signing secret is configured and the Stripe
  signature verifies.
- The bundled Stripe PHP SDK is namespace-scoped at build time (Strauss) to
  avoid class collisions with other Stripe plugins.
