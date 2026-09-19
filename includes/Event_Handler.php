<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

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
                __('Autlantic payment.paid (%1$s). Tx: %2$s', 'autlantic-billing'),
                $payment_id !== '' ? $payment_id : 'n/a',
                $tx !== '' ? $tx : 'n/a',
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
                    __('Autlantic invoice.paid (%s).', 'autlantic-billing'),
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

        $order->add_order_note(__('Autlantic invoice.payment_failed.', 'autlantic-billing'));
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
                __('Autlantic invoice.refunded (%s).', 'autlantic-billing'),
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
            $order->add_order_note(__('Autlantic subscription.activated.', 'autlantic-billing'));
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
        $woo_order_id = (string) ($metadata['woo_order_id'] ?? '');
        if ($woo_order_id !== '') {
            $order = wc_get_order((int) $woo_order_id);
            if ($order instanceof \WC_Order) {
                return $order;
            }
        }

        $link_id = (string) ($metadata['paymentLinkId'] ?? '');
        if ($link_id !== '') {
            $by_link = Order_Meta::find_by_meta(Order_Meta::PAYMENT_LINK_ID, $link_id);
            if ($by_link instanceof \WC_Order) {
                return $by_link;
            }
        }

        $merchant_ref = (string) ($payment['merchantRef'] ?? '');
        if ($merchant_ref !== '' && preg_match('/^woo_(\d+)/', $merchant_ref, $m)) {
            $order = wc_get_order((int) $m[1]);
            if ($order instanceof \WC_Order) {
                return $order;
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
                return $order;
            }
        }

        if ($subscription_id !== '') {
            $by_sub = Order_Meta::find_by_meta(Order_Meta::SUBSCRIPTION_ID, $subscription_id);
            if ($by_sub instanceof \WC_Order) {
                return $by_sub;
            }
        }

        $invoice_id = (string) ($invoice['id'] ?? '');
        if ($invoice_id !== '') {
            return Order_Meta::find_by_meta(Order_Meta::INVOICE_ID, $invoice_id);
        }

        return null;
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
}
