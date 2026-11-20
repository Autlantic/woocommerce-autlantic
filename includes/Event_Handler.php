<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps Autlantic webhook payloads onto WooCommerce orders / subscriptions.
 */
final class Event_Handler
{
    /**
     * @param array<string, mixed> $data
     */
    public static function handle(string $type, array $data): void
    {
        match ($type) {
            'payment.paid' => self::payment_paid($data),
            'payment.created' => null,
            'payment.refunded' => self::payment_refunded($data),
            'invoice.paid' => self::invoice_paid($data),
            'invoice.payment_failed' => self::invoice_payment_failed($data),
            'invoice.refunded' => self::invoice_refunded($data),
            'subscription.activated' => self::subscription_activated($data),
            'subscription.canceled' => self::subscription_canceled($data),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function payment_paid(array $data): void
    {
        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : $data;
        $order = self::find_order_from_payment($payment);
        if ($order === null) {
            return;
        }

        if ($order->is_paid()) {
            return;
        }

        $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        $event_link_id = (string) ($metadata['paymentLinkId'] ?? '');
        $stored_link_id = Order_Meta::get($order, Order_Meta::PAYMENT_LINK_ID);
        $paid_amount = isset($payment['amountUsdc'])
            ? (float) $payment['amountUsdc']
            : (isset($payment['amount']) ? (float) $payment['amount'] : -1.0);
        $order_total = Order_Meta::amount_usdc($order);

        $checks = Order_Match::payment_paid_ok($paid_amount, $order_total, $event_link_id, $stored_link_id);
        if (($checks['ok'] ?? false) !== true) {
            $reason = (string) ($checks['reason'] ?? 'mismatch');
            self::hold_order(
                $order,
                sprintf(
                    'Autlantic payment.paid rejected (%s). Paid %s vs order %s. Link event=%s stored=%s',
                    $reason,
                    (string) $paid_amount,
                    (string) $order_total,
                    $event_link_id !== '' ? $event_link_id : 'n/a',
                    $stored_link_id !== '' ? $stored_link_id : 'n/a',
                ),
            );
            Activity_Log::add([
                'ok' => false,
                'type' => 'payment.paid',
                'message' => $reason,
            ]);

            return;
        }

        $payment_id = (string) ($payment['id'] ?? '');
        $tx = (string) ($payment['txHash'] ?? '');
        if ($payment_id !== '') {
            Order_Meta::set($order, Order_Meta::PAYMENT_ID, $payment_id);
        }
        if ($tx !== '') {
            Order_Meta::set($order, Order_Meta::TX_HASH, $tx);
        }

        $status = self::paid_status();
        $order->payment_complete($payment_id !== '' ? $payment_id : $tx);
        if ($order->get_status() !== $status && in_array($status, ['processing', 'completed'], true)) {
            $order->update_status($status);
        }
        $order->add_order_note(
            sprintf(
                /* translators: 1: payment id, 2: tx hash */
                __('Autlantic payment.paid (%1$s). Tx: %2$s', 'autlantic-billing-for-woocommerce'),
                $payment_id !== '' ? $payment_id : 'n/a',
                $tx !== '' ? $tx : 'n/a',
            ),
        );
        $order->save();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function payment_refunded(array $data): void
    {
        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : $data;
        $order = self::find_order_from_payment($payment);
        if ($order === null) {
            return;
        }

        $payment_id = (string) ($payment['id'] ?? '');
        $refund_amount = isset($data['refundAmountUsdc'])
            ? (float) $data['refundAmountUsdc']
            : (isset($payment['refundAmountUsdc'])
                ? (float) $payment['refundAmountUsdc']
                : (float) $order->get_total());

        if ($refund_amount > 0 && $order->get_remaining_refund_amount() > 0) {
            wc_create_refund([
                'amount' => min($refund_amount, (float) $order->get_remaining_refund_amount()),
                'reason' => sprintf('Autlantic payment.refunded (%s)', $payment_id !== '' ? $payment_id : 'n/a'),
                'order_id' => $order->get_id(),
                'refund_payment' => false,
            ]);
        }

        $order->add_order_note(
            sprintf(
                /* translators: %s: payment id */
                __('Autlantic payment.refunded (%s).', 'autlantic-billing-for-woocommerce'),
                $payment_id !== '' ? $payment_id : 'n/a',
            ),
        );
        $order->save();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function invoice_paid(array $data): void
    {
        $invoice = is_array($data['invoice'] ?? null) ? $data['invoice'] : $data;
        $subscription_id = (string) ($invoice['subscriptionId'] ?? '');
        $invoice_id = (string) ($invoice['id'] ?? '');
        $order = self::find_order_from_invoice($invoice, $subscription_id);
        if ($order === null) {
            return;
        }

        if ($invoice_id !== '') {
            Order_Meta::set($order, Order_Meta::INVOICE_ID, $invoice_id);
        }

        // Parent / first order still pending.
        if (!$order->is_paid()) {
            $order->payment_complete($invoice_id);
            $status = self::paid_status();
            if ($order->get_status() !== $status && in_array($status, ['processing', 'completed'], true)) {
                $order->update_status($status);
            }
            $order->add_order_note(
                sprintf(
                    /* translators: %s: invoice id */
                    __('Autlantic invoice.paid (%s).', 'autlantic-billing-for-woocommerce'),
                    $invoice_id !== '' ? $invoice_id : 'n/a',
                ),
            );
            $order->save();
        }

        if ($subscription_id !== '') {
            Subscriptions::mark_wcs_active_for_autlantic($subscription_id, $invoice_id);
            Subscriptions::record_renewal_if_needed($subscription_id, $invoice);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function invoice_payment_failed(array $data): void
    {
        $invoice = is_array($data['invoice'] ?? null) ? $data['invoice'] : $data;
        $subscription_id = (string) ($invoice['subscriptionId'] ?? '');
        $order = self::find_order_from_invoice($invoice, $subscription_id);
        if ($order === null) {
            return;
        }

        $order->add_order_note(__('Autlantic invoice.payment_failed.', 'autlantic-billing-for-woocommerce'));
        $order->save();

        if ($subscription_id !== '') {
            Subscriptions::mark_wcs_on_hold($subscription_id);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function invoice_refunded(array $data): void
    {
        $invoice = is_array($data['invoice'] ?? null) ? $data['invoice'] : $data;
        $invoice_id = (string) ($invoice['id'] ?? '');
        $order = self::find_order_from_invoice($invoice, (string) ($invoice['subscriptionId'] ?? ''));
        if ($order === null) {
            return;
        }

        $refund_amount = isset($invoice['refundAmountUsdc'])
            ? (float) $invoice['refundAmountUsdc']
            : (float) $order->get_total();

        if ($refund_amount > 0 && $order->get_remaining_refund_amount() > 0) {
            wc_create_refund([
                'amount' => min($refund_amount, (float) $order->get_remaining_refund_amount()),
                'reason' => sprintf('Autlantic invoice.refunded (%s)', $invoice_id),
                'order_id' => $order->get_id(),
                'refund_payment' => false,
            ]);
        }

        $order->add_order_note(
            sprintf(
                /* translators: %s: invoice id */
                __('Autlantic invoice.refunded (%s).', 'autlantic-billing-for-woocommerce'),
                $invoice_id !== '' ? $invoice_id : 'n/a',
            ),
        );
        $order->save();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function subscription_activated(array $data): void
    {
        $subscription = is_array($data['subscription'] ?? null) ? $data['subscription'] : $data;
        $subscription_id = (string) ($subscription['id'] ?? '');
        if ($subscription_id === '') {
            return;
        }

        $order = Order_Meta::find_by_meta(Order_Meta::SUBSCRIPTION_ID, $subscription_id);
        if ($order instanceof \WC_Order && !$order->is_paid()) {
            $order->payment_complete($subscription_id);
            $order->add_order_note(__('Autlantic subscription.activated.', 'autlantic-billing-for-woocommerce'));
            $order->save();
        }

        Subscriptions::mark_wcs_active_for_autlantic($subscription_id, '');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function subscription_canceled(array $data): void
    {
        $subscription = is_array($data['subscription'] ?? null) ? $data['subscription'] : $data;
        $subscription_id = (string) ($subscription['id'] ?? '');
        if ($subscription_id === '') {
            return;
        }

        Subscriptions::cancel_wcs_for_autlantic($subscription_id);
    }

    /**
     * @param array<string, mixed> $payment
     */
    private static function find_order_from_payment(array $payment): ?\WC_Order
    {
        $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        $event_link_id = (string) ($metadata['paymentLinkId'] ?? '');

        $woo_order_id = (string) ($metadata['woo_order_id'] ?? '');
        if ($woo_order_id !== '') {
            $order = wc_get_order((int) $woo_order_id);
            if ($order instanceof \WC_Order) {
                return self::accept_matched_order($order, $metadata, $event_link_id, 'payment');
            }
        }

        if ($event_link_id !== '') {
            $by_link = Order_Meta::find_by_meta(Order_Meta::PAYMENT_LINK_ID, $event_link_id);
            if ($by_link instanceof \WC_Order) {
                return self::accept_matched_order($by_link, $metadata, $event_link_id, 'payment');
            }
        }

        $merchant_ref = (string) ($payment['merchantRef'] ?? '');
        if ($merchant_ref !== '' && preg_match('/^woo_(\d+)/', $merchant_ref, $m)) {
            $order = wc_get_order((int) $m[1]);
            if ($order instanceof \WC_Order) {
                return self::accept_matched_order($order, $metadata, $event_link_id, 'payment');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private static function find_order_from_invoice(array $invoice, string $subscription_id): ?\WC_Order
    {
        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];

        $woo_order_id = (string) ($metadata['woo_order_id'] ?? '');
        if ($woo_order_id !== '') {
            $order = wc_get_order((int) $woo_order_id);
            if ($order instanceof \WC_Order) {
                return self::accept_matched_order($order, $metadata, null, 'invoice');
            }
        }

        if ($subscription_id !== '') {
            $by_sub = Order_Meta::find_by_meta(Order_Meta::SUBSCRIPTION_ID, $subscription_id);
            if ($by_sub instanceof \WC_Order) {
                // Subscription id is unique; still verify site/key when metadata is present.
                if (isset($metadata['woo_site']) || isset($metadata['woo_order_key'])) {
                    return self::accept_matched_order($by_sub, $metadata, null, 'invoice');
                }

                return $by_sub;
            }
        }

        $invoice_id = (string) ($invoice['id'] ?? '');
        if ($invoice_id !== '') {
            $by_invoice = Order_Meta::find_by_meta(Order_Meta::INVOICE_ID, $invoice_id);
            if ($by_invoice instanceof \WC_Order) {
                if (isset($metadata['woo_site']) || isset($metadata['woo_order_key'])) {
                    return self::accept_matched_order($by_invoice, $metadata, null, 'invoice');
                }

                return $by_invoice;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function accept_matched_order(
        \WC_Order $order,
        array $metadata,
        ?string $event_link_id,
        string $context,
    ): ?\WC_Order {
        $stored_link = Order_Meta::get($order, Order_Meta::PAYMENT_LINK_ID);
        $identity = Order_Match::identity_ok(
            $metadata,
            home_url('/'),
            $order->get_order_key(),
            $event_link_id,
            $context === 'payment' ? $stored_link : null,
        );

        // Invoice renewals may omit payment link; only enforce link when context is payment
        // or when event carries a link id / order has one and context is payment.
        if ($context === 'invoice') {
            $identity = Order_Match::identity_ok(
                $metadata,
                home_url('/'),
                $order->get_order_key(),
                null,
                null,
            );
        }

        if (($identity['ok'] ?? false) !== true) {
            $reason = (string) ($identity['reason'] ?? 'identity_mismatch');
            Activity_Log::add([
                'ok' => false,
                'type' => $context,
                'message' => 'match ' . $reason . ' order ' . $order->get_id(),
            ]);
            self::log('Order match rejected: ' . $reason . ' for order ' . $order->get_id());

            return null;
        }

        return $order;
    }

    private static function hold_order(\WC_Order $order, string $note): void
    {
        $order->update_status('on-hold', $note);
        $order->save();
    }

    private static function paid_status(): string
    {
        $gateway = Client_Factory::gateway();
        if ($gateway instanceof Gateway) {
            $status = $gateway->get_option('order_status_on_paid', 'processing');

            return in_array($status, ['processing', 'completed'], true) ? $status : 'processing';
        }

        return 'processing';
    }

    private static function log(string $message): void
    {
        $gateway = Client_Factory::gateway();
        if ($gateway instanceof Gateway) {
            $gateway->log($message);
        }
        $logger = function_exists('wc_get_logger') ? wc_get_logger() : null;
        if ($logger !== null) {
            $logger->error($message, ['source' => 'autlantic']);
        }
    }
}
