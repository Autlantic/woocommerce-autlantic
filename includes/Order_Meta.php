<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

/**
 * Order meta keys and helpers shared across gateway, webhooks, and admin.
 */
final class Order_Meta
{
    public const PAYMENT_LINK_ID = '_autlantic_payment_link_id';
    public const PAYMENT_LINK_URL = '_autlantic_payment_link_url';
    public const PAYMENT_ID = '_autlantic_payment_id';
    public const SUBSCRIPTION_ID = '_autlantic_subscription_id';
    public const INVOICE_ID = '_autlantic_invoice_id';
    public const CHECKOUT_URL = '_autlantic_checkout_url';
    public const TX_HASH = '_autlantic_tx_hash';
    public const MODE = '_autlantic_mode';
    public const MERCHANT_REF = '_autlantic_merchant_ref';

    /**
     * Pending wallet used only until hosted checkout replaces it.
     * Hosted UI calls POST /checkout/subscribe/:id/wallet before pay.
     */
    public const PENDING_CUSTOMER_WALLET = '0x0000000000000000000000000000000000000001';

    public static function set(\WC_Order $order, string $key, string $value): void
    {
        $order->update_meta_data($key, $value);
    }

    public static function get(\WC_Order $order, string $key): string
    {
        return (string) $order->get_meta($key, true);
    }

    public static function find_by_meta(string $key, string $value): ?\WC_Order
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'type' => ['shop_order', 'shop_subscription'],
            'meta_key' => $key,
            'meta_value' => $value,
            'return' => 'objects',
        ]);

        $order = $orders[0] ?? null;

        return $order instanceof \WC_Order ? $order : null;
    }

    /**
     * Order total as USDC amount. Store currency must be USD (or USDC).
     */
    public static function amount_usdc(\WC_Order $order): float
    {
        return round((float) $order->get_total(), 6);
    }

    public static function currency_supported(\WC_Order $order): bool
    {
        $currency = strtoupper($order->get_currency());

        return in_array($currency, ['USD', 'USDC'], true);
    }
}
