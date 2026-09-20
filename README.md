<p align="center">
  <img src="https://autlantic.com/brand/autlantic-icon-1024-master.png" alt="Autlantic" width="96" height="96" />
</p>

<h1 align="center">Autlantic Billing — WooCommerce</h1>

<p align="center">
  <strong>USDC payments on Base</strong><br />
  Official WooCommerce payment gateway: payment links, hosted checkout, webhooks, and optional subscriptions.
</p>

<p align="center">
  <a href="https://docs.autlantic.com/guide/commerce"><img src="https://img.shields.io/badge/docs-docs.autlantic.com-5672cd?style=flat-square" alt="Docs" /></a>
  <a href="https://github.com/Autlantic/woocommerce-autlantic/releases"><img src="https://img.shields.io/badge/release-zip-5672cd?style=flat-square" alt="Release zip" /></a>
  <a href="https://github.com/Autlantic/payments-sdk/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue?style=flat-square" alt="MIT License" /></a>
  <a href="https://autlantic.com"><img src="https://img.shields.io/badge/product-autlantic.com-111827?style=flat-square" alt="Autlantic" /></a>
</p>

---

Part of [Autlantic Payments SDK](https://github.com/Autlantic/payments-sdk). Depends on [`autlantic/billing`](../../sdks/php). Distribution zip: [woocommerce-autlantic releases](https://github.com/Autlantic/woocommerce-autlantic/releases) (**1.1.1**).

USDC settles to your merchant payout wallet. Autlantic does not custody checkout funds.

## Why this plugin

Same hosted Billing API as Magento and the PHP SDK — create a payment link, redirect to hosted checkout, verify `x-autlantic-signature`, mark the Woo order paid. Secrets stay in WooCommerce settings.

## Install

**WordPress zip (recommended for stores)**

```bash
bash integrations/woocommerce/bin/package.sh
# → integrations/woocommerce/dist/autlantic-billing-1.1.1.zip
```

Upload via Plugins → Add New. The zip vendors `autlantic/billing` (no Composer on the store). Or download the [GitHub release](https://github.com/Autlantic/woocommerce-autlantic/releases).

**Monorepo / development**

```bash
cd integrations/woocommerce
composer install
```

Symlink into `wp-content/plugins/autlantic-billing`, then activate. Requires WordPress **6.2+**, WooCommerce **8.0+**, PHP **8.1+**. Store currency **USD** or **USDC**.

Optional: [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) for week / month / year plans (interval 1).

## Quick start

1. WooCommerce → Settings → Payments → **Autlantic Billing**
2. Paste API key and webhook signing secret
3. Portal → Webhooks → register:

   `https://your-store.example/wp-json/autlantic/v1/webhook`

4. Place a test order → pay on hosted checkout → order moves to processing / completed

## What it does

| Feature | Behavior |
|---------|----------|
| One-time checkout | Single-use payment link → hosted Autlantic checkout |
| Webhooks | `POST /wp-json/autlantic/v1/webhook` · activity on the settings screen |
| Refunds | Invoice refunds when an Autlantic invoice id is on the order; one-time payment-link refunds are manual |
| WooCommerce Subscriptions | Soft dependency; Autlantic renewals are source of truth |
| Blocks / HPOS | Registered payment method; HPOS compatible |

## Webhooks

Verify `x-autlantic-signature` with the portal endpoint secret that matches the API key mode (Test or Live).

## Documentation

| | |
|--|--|
| [Commerce plugins](https://docs.autlantic.com/guide/commerce) | Woo / Magento / Shopify |
| [Languages](https://docs.autlantic.com/guide/languages) | All SDK surfaces |
| [Webhooks](https://docs.autlantic.com/guide/webhooks) | Signature & events |
| [PHP SDK](https://docs.autlantic.com/api/php) | `autlantic/billing` |
| [Terms](https://autlantic.com/terms) · [Privacy](https://autlantic.com/privacy) · [Security](https://autlantic.com/security) | Legal |

## Develop

```bash
cd integrations/woocommerce && composer install
php bin/smoke.php
```

On tag `integrations/woocommerce/v*`, [`.github/workflows/sync-woocommerce-mirror.yml`](../../.github/workflows/sync-woocommerce-mirror.yml) syncs [woocommerce-autlantic](https://github.com/Autlantic/woocommerce-autlantic) and attaches the zip.

## License

MIT · Operated by **Autlantic Limited** (UK company no. 17422039).

Part of [Autlantic Payments SDK](https://github.com/Autlantic/payments-sdk).
