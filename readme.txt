=== Zirkel Virtual IBAN for WooCommerce ===
Contributors: dsturm
Tags: bank transfer, vorkasse, banküberweisung, sepa, iban
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept reconciled bank transfer payments in WooCommerce via Stripe. Each order gets unique virtual bank account details, reconciled automatically.

== Description ==

Zirkel Virtual IBAN for WooCommerce lets your store accept bank transfers the modern way: powered by Stripe's customer_balance funding flow, every order receives its **own unique virtual bank account** (SEPA IBAN, UK Bacs, US ACH, Mexican SPEI or Japanese Zengin). When the customer sends the transfer, a Stripe webhook marks the order paid **automatically** — no manual bank-statement matching.

Built DACH-first: SEPA / EUR is the default, with a German (Sie and Du) interface.

**Free means free.** This plugin processes real, live payments out of the box. There is no test-mode-only limitation and no paywall on taking money.

**Features**

* Per-order virtual bank account details shown on the thank-you page and in order emails.
* Automatic reconciliation via Stripe webhooks (paid, failed, cancelled, processing, partially funded).
* **GiroCode (EPC-QR)** on the thank-you page — customers scan it with their banking app to pre-fill the transfer.
* Full and partial **refunds** straight from the WooCommerce order screen.
* Works in both the **block-based checkout** and the classic checkout.
* A dedicated "Awaiting Bank Transfer" order status, or choose your own (On hold / Pending payment).
* Underpayment detection: partial transfers are flagged on the order.
* Optional reuse of Stripe API keys already configured by the official WooCommerce Stripe Gateway, Payment Plugins for Stripe WooCommerce, or Payment Gateway Stripe and WooCommerce Integration.
* German translations, informal (Du) and formal (Sie).
* HPOS (High-Performance Order Storage) compatible.

This plugin requires a Stripe account and the WooCommerce plugin. Stripe is a third-party payment service; by using this plugin payment data is transmitted to Stripe (see the Stripe [Privacy Policy](https://stripe.com/privacy) and [Terms](https://stripe.com/legal)).

== External services ==

This plugin connects to the Stripe API to create a payment intent and a virtual bank account for an order, and to receive confirmation when the transfer arrives. Without that connection the plugin cannot do its job, so the service is required rather than optional.

**Service:** Stripe (Stripe, Inc. / Stripe Payments Europe, Ltd.)

**When data is sent:** when a customer places an order and chooses this payment method, when an order is refunded, and when the shop owner opens the customer balance panel in the admin.

**What is sent:** the order number, order total and currency, the shop name, the billing country, and the customer's name and email address. The name and email are used to create a Stripe customer, which the bank-transfer flow requires because the virtual account belongs to that customer record.

**What is received:** the virtual bank account details (IBAN/BIC or the equivalent for other rails) that are shown to the customer, and webhook notifications about the payment status.

No data is sent to Stripe until the customer actively selects this payment method. This plugin sends nothing to any other third party, and it does not load any script or font from a remote host.

Stripe [Terms of Service](https://stripe.com/legal) · Stripe [Privacy Policy](https://stripe.com/privacy) · Stripe [Data Processing Agreement](https://stripe.com/legal/dpa)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Go to WooCommerce → Settings → Payments → Bank Transfer and enable it.
3. Enter your Stripe secret key (test and live), or let the plugin reuse keys from an existing Stripe plugin.
4. In your Stripe Dashboard, add a webhook endpoint pointing to the URL shown in the gateway settings, and paste the signing secret into the Webhook Secret field.

== Frequently Asked Questions ==

= Do I need a Stripe account? =
Yes. This plugin uses Stripe's bank-transfer (customer_balance) feature; availability of specific bank-transfer types depends on your Stripe account country and currency.

= Does it work with the official WooCommerce Stripe gateway installed? =
Yes. It can reuse the API credentials stored by the official WooCommerce Stripe Gateway or by Payment Plugins for Stripe WooCommerce, so you do not have to enter keys twice.

= Is the webhook required? =
Yes, for automatic reconciliation. A signing secret must be configured — the plugin refuses to process unverified webhook requests.

= Can I really take live payments with the free version? =
Yes. The free version is not limited to Stripe test mode. Stripe's own transaction fees apply, as with any payment method.

= It works in test mode but not live. Why? =
Bank transfers must be enabled on your Stripe account for live payments, which is a separate thing from anything in this plugin. In the Stripe Dashboard go to Settings → Payment methods and enable **Bank transfers** (Banküberweisungen). Availability also depends on your account's country and currency, and some accounts need Stripe to approve it first. Until that is done, Stripe rejects the payment with a capability error.

= How long does payment take to arrive? =
Stripe confirms bank transfers within roughly 0–3 business days, depending on the customer's bank. The order stays in "Awaiting Bank Transfer" until the money lands, then the webhook marks it paid automatically.

= Is there a minimum order amount? =
Yes. Stripe requires at least 0.50 EUR (or the equivalent) for a bank transfer. Below that the payment method is hidden at checkout, since it could not be completed. You can change the threshold with the `btpw_minimum_amount` filter.

= Are chargebacks possible? =
Bank transfers do not support disputes in the way cards do, which is part of their appeal for merchants. Refunds are supported, including partial refunds, from the WooCommerce order screen.

= What if a customer transfers too little? =
Stripe holds the part-payment in the customer balance and waits for the rest. The plugin records the shortfall as an order note so you can follow up.

= My customer's bank says the recipient name does not match. What now? =
Since 9 October 2025 every SEPA transfer in the EU/EEA goes through Verification of Payee: the customer's bank checks the recipient name against the IBAN before releasing the payment. Nothing is required of your shop technically, the bank does this on its own. The account that receives the money is held by Stripe, so the name to enter is the one shown as "Account Holder Name" in the payment instructions, not your shop name. That is the exact name Stripe answers the check with, and it comes from Business Name under Settings → Business details in your Stripe Dashboard. If a customer reports a mismatch, check that field first. For Belgian virtual IBANs Stripe currently advises customers to confirm the warning after verifying the details are correct.

= Does it support WooCommerce Subscriptions? =
Bank transfers cannot be charged automatically, so subscription renewals are always manual: each renewal issues a fresh virtual bank account for the customer to pay. This is available as an optional add-on feature.

== Changelog ==

= 1.1.0 =
* Improved: Updated to the latest Stripe API version and Stripe PHP library.

= 1.0.2 =
* Improved: Smaller download, a developer configuration file is no longer included.

= 1.0.1 =
* Improved: Tested with WordPress 7.1.
* Improved: Payment instructions now explain the SEPA recipient-name check (Verification of Payee) so customers know a name notice from their bank is normal.
* Improved: The account holder name is shown first in the bank details, as it is the field banks now verify.
* Fixed: The GiroCode is no longer generated with a guessed recipient name when Stripe does not supply one.

= 1.0.0 =
* Initial release: Stripe bank-transfer gateway with per-order virtual bank accounts and automatic webhook reconciliation.
* Added: GiroCode (EPC-QR) on the thank-you page for SEPA payments.
* Added: Full and partial refunds from the order screen.
* Added: Block-based checkout support.
* Added: Configurable awaiting-payment order status.
* Added: Underpayment detection for partially funded transfers.
