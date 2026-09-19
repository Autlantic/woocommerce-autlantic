=== Autlantic Billing for WooCommerce ===
Contributors: autlantic
Tags: woocommerce, payments, usdc, crypto, subscriptions
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept USDC on Base at WooCommerce checkout via Autlantic Billing.

== Description ==

Autlantic Billing for WooCommerce is a payment gateway for USDC on Base.

* One-time orders redirect to hosted Autlantic checkout (single-use payment link).
* Signed webhooks mark the WooCommerce order paid. Recent deliveries are listed on the gateway settings screen.
* Invoice refunds when an Autlantic invoice id is stored on the order. One-time checkout refunds are not offered by the billing API yet.
* Optional WooCommerce Subscriptions: create, cancel, and record renewals from Autlantic. Other subscription features are not claimed.
* Test connection checks the API key against the hosted catalog.
* Cart and Checkout blocks, and High-Performance Order Storage (HPOS).

Store currency must be USD or USDC. You need an Autlantic merchant API key and a webhook signing secret from the merchant portal.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload, or copy `autlantic-billing` into `wp-content/plugins/`.
2. Activate **Autlantic Billing for WooCommerce**. WooCommerce must already be active.
3. Go to WooCommerce → Settings → Payments → Autlantic Billing.
4. Paste your API key (`abk_test_…` or `abk_live_…`) and webhook signing secret.
5. In the Autlantic merchant portal, register this webhook URL:

`https://your-store.example/wp-json/autlantic/v1/webhook`

Use the Test endpoint with a test key, and the Live endpoint with a live key.

== Frequently Asked Questions ==

= Does this replace the PHP SDK? =

No. The plugin uses the official `autlantic/billing` PHP client. The WordPress zip vendors that client so the store does not need Composer.

= Do I need WooCommerce Subscriptions? =

No. One-time checkout works without it. Install WooCommerce Subscriptions only if you sell week, month, or year plans. Other billing intervals are not supported.

= Where do funds settle? =

To the merchant payout wallet configured in the Autlantic portal, or the optional payout address in the gateway settings. Autlantic does not custody the payment.

== Changelog ==

= 1.1.0 =
* Merchant tools: live/test badge, test connection, webhook activity, and order sync.
* Checkout and settings use the Autlantic mark and wordmark.
* Subscriptions support is limited to create, cancel, and renewal recording.

= 1.0.0 =
* Initial gateway: one-time checkout, webhooks, refunds, optional WooCommerce Subscriptions, blocks checkout.
