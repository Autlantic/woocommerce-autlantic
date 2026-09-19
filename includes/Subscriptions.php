<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

use Autlantic\Billing\AutlanticBillingException;

/**
 * Soft integration with WooCommerce Subscriptions.
 * Autlantic is the renewal source of truth (vault charges).
 * WC Subscriptions is the storefront catalog and customer UI.
 */
final class Subscriptions
{
    public static function init(): void
    {
        if (!self::is_active()) {
            return;
        }

        add_action(
            'woocommerce_subscription_status_cancelled',
            [self::class, 'on_wcs_cancelled'],
            10,
            1,
        );
        add_action(
            'woocommerce_subscription_status_pending-cancel',
            [self::class, 'on_wcs_pending_cancel'],
            10,
            1,
        );

        // Autlantic renews on its schedule; do not let Woo charge card-style renewals.
        add_action(
            'woocommerce_scheduled_subscription_payment_autlantic',
            [self::class, 'on_scheduled_payment'],
            10,
            2,
        );
    }

    public static function is_active(): bool
    {
        return class_exists(\WC_Subscriptions::class)
            || class_exists(\WC_Subscriptions_Plugin::class)
            || function_exists('wcs_get_subscriptions_for_order');
    }

    /**
     * @return list<string>
     */
    public static function gateway_supports(): array
    {
        return [
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'multiple_subscriptions',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
        ];
    }

    public static function order_contains_subscription(\WC_Order $order): bool
    {
        if (!self::is_active()) {
            return false;
        }

        if (function_exists('wcs_order_contains_subscription')) {
            return (bool) wcs_order_contains_subscription($order);
        }

        return false;
    }

    /**
     * Map Woo subscription period to Autlantic BillingInterval.
     */
    public static function billing_interval_for_order(\WC_Order $order): ?string
    {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return null;
        }

        $subs = wcs_get_subscriptions_for_order($order, ['order_type' => 'any']);
        $sub = reset($subs);
        if (!$sub instanceof \WC_Subscription) {
            // Parent order may not have created WCS objects yet; read cart/product.
            return self::interval_from_order_items($order);
        }

        return self::map_period((string) $sub->get_billing_period(), (int) $sub->get_billing_interval());
    }

    private static function interval_from_order_items(\WC_Order $order): ?string
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $period = (string) $product->get_meta('_subscription_period', true);
            $interval = (int) $product->get_meta('_subscription_period_interval', true);
            if ($period !== '') {
                return self::map_period($period, $interval > 0 ? $interval : 1);
            }
        }

        return null;
    }

    private static function map_period(string $period, int $interval): ?string
    {
        $period = strtolower($period);
        if ($interval !== 1) {
            // Autlantic supports single-step week/month/year only.
            return null;
        }

        return match ($period) {
            'week' => 'week',
            'month' => 'month',
            'year' => 'year',
            default => null,
        };
    }

    public static function attach_ids_to_wcs_subscriptions(\WC_Order $order, string $autlantic_subscription_id): void
    {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return;
        }

        $subs = wcs_get_subscriptions_for_order($order, ['order_type' => 'any']);
        foreach ($subs as $sub) {
            if (!$sub instanceof \WC_Subscription) {
                continue;
            }
            Order_Meta::set($sub, Order_Meta::SUBSCRIPTION_ID, $autlantic_subscription_id);
            $sub->set_requires_manual_renewal(true);
            $sub->save();
        }
    }

    public static function mark_wcs_active_for_autlantic(string $autlantic_subscription_id, string $invoice_id): void
    {
        $sub = self::find_wcs($autlantic_subscription_id);
        if ($sub === null) {
            return;
        }

        if ($invoice_id !== '') {
            Order_Meta::set($sub, Order_Meta::INVOICE_ID, $invoice_id);
        }

        if (!$sub->has_status('active')) {
            $sub->update_status('active', __('Activated via Autlantic Billing.', 'autlantic-billing'));
        }
        $sub->save();
    }

    public static function mark_wcs_on_hold(string $autlantic_subscription_id): void
    {
        $sub = self::find_wcs($autlantic_subscription_id);
        if ($sub === null) {
            return;
        }

        if (!$sub->has_status('on-hold')) {
            $sub->update_status('on-hold', __('Autlantic invoice payment failed.', 'autlantic-billing'));
            $sub->save();
        }
    }

    public static function cancel_wcs_for_autlantic(string $autlantic_subscription_id): void
    {
        $sub = self::find_wcs($autlantic_subscription_id);
        if ($sub === null) {
            return;
        }

        if (!$sub->has_status(['cancelled', 'expired', 'trash'])) {
            $sub->update_status('cancelled', __('Canceled via Autlantic Billing.', 'autlantic-billing'));
            $sub->save();
        }
    }

    /**
     * @param array<string, mixed> $invoice
     */
    public static function record_renewal_if_needed(string $autlantic_subscription_id, array $invoice): void
    {
        $sub = self::find_wcs($autlantic_subscription_id);
        if ($sub === null || !function_exists('wcs_create_renewal_order')) {
            return;
        }

        // Skip first invoice: parent order already paid.
        $invoice_id = (string) ($invoice['id'] ?? '');
        $known = Order_Meta::get($sub, Order_Meta::INVOICE_ID);
        if ($invoice_id !== '' && $invoice_id === $known) {
            return;
        }

        // If parent order is the only order and just paid, first invoice was handled there.
        $related = $sub->get_related_orders('all', 'any');
        if (count($related) <= 1 && $sub->get_date('start') && (time() - strtotime((string) $sub->get_date('start'))) < 3600) {
            if ($invoice_id !== '') {
                Order_Meta::set($sub, Order_Meta::INVOICE_ID, $invoice_id);
                $sub->save();
            }

            return;
        }

        if ($invoice_id !== '' && Order_Meta::find_by_meta(Order_Meta::INVOICE_ID, $invoice_id)) {
            return;
        }

        $renewal = wcs_create_renewal_order($sub);
        if (!$renewal instanceof \WC_Order) {
            return;
        }

        $renewal->set_payment_method('autlantic');
        if ($invoice_id !== '') {
            Order_Meta::set($renewal, Order_Meta::INVOICE_ID, $invoice_id);
            Order_Meta::set($sub, Order_Meta::INVOICE_ID, $invoice_id);
        }
        Order_Meta::set($renewal, Order_Meta::SUBSCRIPTION_ID, $autlantic_subscription_id);
        $renewal->payment_complete($invoice_id);
        $renewal->add_order_note(__('Renewal recorded from Autlantic invoice.paid.', 'autlantic-billing'));
        $renewal->save();
        $sub->save();
    }

    /**
     * Woo scheduled renewal hook. Autlantic already charges; mark as processed / wait for webhook.
     *
     * @param float $amount
     * @param \WC_Order $order
     */
    public static function on_scheduled_payment($amount, $order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }

        $order->add_order_note(
            __(
                'WooCommerce scheduled a renewal. Autlantic Billing charges on its own schedule; waiting for invoice.paid webhook.',
                'autlantic-billing',
            ),
        );
        $order->save();
    }

    /**
     * @param \WC_Subscription $subscription
     */
    public static function on_wcs_cancelled($subscription): void
    {
        self::cancel_autlantic_from_wcs($subscription, immediate: true);
    }

    /**
     * @param \WC_Subscription $subscription
     */
    public static function on_wcs_pending_cancel($subscription): void
    {
        self::cancel_autlantic_from_wcs($subscription, immediate: false);
    }

    /**
     * @param \WC_Subscription $subscription
     */
    private static function cancel_autlantic_from_wcs($subscription, bool $immediate): void
    {
        if (!$subscription instanceof \WC_Subscription) {
            return;
        }

        $autlantic_id = Order_Meta::get($subscription, Order_Meta::SUBSCRIPTION_ID);
        if ($autlantic_id === '') {
            return;
        }

        try {
            $billing = Client_Factory::from_gateway();
            $billing->cancelSubscription($autlantic_id, ['immediate' => $immediate]);
            $subscription->add_order_note(
                sprintf(
                    /* translators: %s: Autlantic subscription id */
                    __('Requested Autlantic cancel for %s.', 'autlantic-billing'),
                    $autlantic_id,
                ),
            );
            $subscription->save();
        } catch (AutlanticBillingException $e) {
            $subscription->add_order_note(
                sprintf(
                    /* translators: %s: error */
                    __('Autlantic cancel failed: %s', 'autlantic-billing'),
                    $e->getMessage(),
                ),
            );
            $subscription->save();
        }
    }

    private static function find_wcs(string $autlantic_subscription_id): ?\WC_Subscription
    {
        $found = Order_Meta::find_by_meta(Order_Meta::SUBSCRIPTION_ID, $autlantic_subscription_id);
        if ($found instanceof \WC_Subscription) {
            return $found;
        }

        if ($found instanceof \WC_Order && function_exists('wcs_get_subscriptions_for_order')) {
            $subs = wcs_get_subscriptions_for_order($found, ['order_type' => 'any']);
            $sub = reset($subs);

            return $sub instanceof \WC_Subscription ? $sub : null;
        }

        return null;
    }
}
