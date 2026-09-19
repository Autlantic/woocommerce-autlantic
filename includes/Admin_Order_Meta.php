<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

/**
 * Admin order metabox showing Autlantic IDs and checkout link.
 */
final class Admin_Order_Meta
{
    public static function init(): void
    {
        add_action('add_meta_boxes', [self::class, 'register'], 40);
    }

    public static function register(): void
    {
        $screens = ['shop_order', 'woocommerce_page_wc-orders'];
        foreach ($screens as $screen) {
            add_meta_box(
                'autlantic-billing',
                __('Autlantic Billing', 'autlantic-billing'),
                [self::class, 'render'],
                $screen,
                'side',
                'default',
            );
        }
    }

    /**
     * @param \WP_Post|\WC_Order $post_or_order
     */
    public static function render($post_or_order): void
    {
        $order = $post_or_order instanceof \WC_Order
            ? $post_or_order
            : wc_get_order($post_or_order->ID ?? 0);

        if (!$order instanceof \WC_Order) {
            echo '<p>' . esc_html__('Order not found.', 'autlantic-billing') . '</p>';

            return;
        }

        $rows = [
            __('Mode', 'autlantic-billing') => Order_Meta::get($order, Order_Meta::MODE),
            __('Payment link', 'autlantic-billing') => Order_Meta::get($order, Order_Meta::PAYMENT_LINK_ID),
            __('Payment', 'autlantic-billing') => Order_Meta::get($order, Order_Meta::PAYMENT_ID),
            __('Subscription', 'autlantic-billing') => Order_Meta::get($order, Order_Meta::SUBSCRIPTION_ID),
            __('Invoice', 'autlantic-billing') => Order_Meta::get($order, Order_Meta::INVOICE_ID),
            __('Tx hash', 'autlantic-billing') => Order_Meta::get($order, Order_Meta::TX_HASH),
            __('Merchant ref', 'autlantic-billing') => Order_Meta::get($order, Order_Meta::MERCHANT_REF),
        ];

        echo '<table class="widefat striped" style="margin-top:4px"><tbody>';
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            echo '<tr><th style="text-align:left;width:40%">' . esc_html((string) $label) . '</th>';
            echo '<td style="word-break:break-all"><code>' . esc_html($value) . '</code></td></tr>';
        }
        echo '</tbody></table>';

        $checkout = Order_Meta::get($order, Order_Meta::CHECKOUT_URL);
        if ($checkout !== '' && !$order->is_paid()) {
            echo '<p style="margin-top:8px"><a class="button" target="_blank" rel="noopener noreferrer" href="'
                . esc_url($checkout) . '">';
            echo esc_html__('Open checkout', 'autlantic-billing');
            echo '</a></p>';
        }
    }
}
