# Autlantic Billing for WooCommerce

Full WooCommerce payment gateway for **USDC on Base** via the hosted Autlantic Billing API.

Source of truth: `integrations/woocommerce` in [payments-sdk](https://github.com/Autlantic/payments-sdk). Depends on [`autlantic/billing`](../../sdks/php) (PHP SDK).

## What it does

| Feature | Behavior |
|---------|----------|
| One-time checkout | Creates a single-use payment link, redirects to hosted Autlantic checkout |
| Webhooks | `POST /wp-json/autlantic/v1/webhook` verifies `x-autlantic-signature`, marks orders paid |
| Refunds | Invoice refunds via API when an Autlantic invoice id is on the order |
| WooCommerce Subscriptions | Soft dependency. Autlantic vault renewals are source of truth; WC Subscriptions is catalog/UI |
| Blocks checkout | Registered payment method |
| HPOS | Declared compatible |

## Requirements

- WordPress 6.2+
- WooCommerce 8.0+
- PHP 8.1+ with `ext-curl`, `ext-json`, `ext-hash`
- Store currency **USD** or **USDC**
- Autlantic merchant portal API key (`abk_test_…` or `abk_live_…`) and webhook endpoint secret

Optional: [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) for subscription products (interval week / month / year only).

## Install (monorepo / development)

```bash
cd integrations/woocommerce
composer install
```

Symlink this directory into `wp-content/plugins/autlantic-billing`, then activate in wp-admin. The path Composer repo points at `sdks/php`.

## WordPress zip (no Composer on the store)

```bash
bash integrations/woocommerce/bin/package.sh
# writes integrations/woocommerce/dist/autlantic-billing-1.1.1.zip
```

The zip vendors `autlantic/billing` and runs a local smoke check (webhook verify + key mode). Upload it with Plugins → Add New. Do not run `composer install` inside that zip.

## Mirror

Source of truth stays in this repo. On tag `integrations/woocommerce/v*`, [`.github/workflows/sync-woocommerce-mirror.yml`](../../.github/workflows/sync-woocommerce-mirror.yml) pushes a Packagist-style tree to `Autlantic/woocommerce-autlantic` and attaches the vendored zip as a GitHub release.

One-time: create the empty public repo `Autlantic/woocommerce-autlantic`, then add Actions secret **`WOOCOMMERCE_MIRROR_TOKEN`** (PAT with `contents:write` on that repo).

## Configure

1. WooCommerce → Settings → Payments → **Autlantic Billing**
2. Paste API key and webhook signing secret
3. Optional payout wallet override (otherwise portal merchant payout is used)
4. In the Autlantic portal → Webhooks, register:

   `https://your-store.example/wp-json/autlantic/v1/webhook`

   Use the Test or Live endpoint that matches the API key mode.

## Checkout flow

**One-time**

1. Customer places order with Autlantic selected
2. Plugin creates `POST /v1/payment-links` (`maxUses: 1`, metadata `woo_order_id`)
3. Customer pays on hosted checkout
4. `payment.paid` webhook → order `processing` / `completed`

**Subscriptions** (WooCommerce Subscriptions active)

1. Plugin creates `POST /v1/subscriptions` with a temporary wallet; hosted checkout updates the wallet before pay
2. Customer activates on `/checkout/subscribe/:id`
3. `subscription.activated` / `invoice.paid` → parent order paid, WC subscription active
4. Later `invoice.paid` events create renewal orders
5. Cancel in Woo or Autlantic stays in sync via webhooks / cancel API

## Development notes

- Do not put Autlantic platform URLs or secrets in this plugin. Merchants use their own portal credentials.
- Billing logic stays in billing-api; this plugin only maps Woo orders ↔ Autlantic IDs.
- For WordPress.org distribution, vendor `autlantic/billing` into the zip (path repo is for monorepo only).

## License

MIT · Autlantic Limited (UK company no. 17422039)
