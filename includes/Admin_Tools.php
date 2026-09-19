<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

use Autlantic\Billing\AutlanticBilling;
use Autlantic\Billing\AutlanticBillingException;

/**
 * Merchant tools: connection test, webhook activity, and order status sync.
 */
final class Admin_Tools
{
    public static function init(): void
    {
        add_action('wp_ajax_autlantic_test_connection', [self::class, 'test_connection']);
        add_action('admin_post_autlantic_sync_order', [self::class, 'sync_order']);
    }

    public static function render_panel(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $gateway = Client_Factory::gateway();
        $key = $gateway instanceof Gateway ? (string) $gateway->get_option('api_key', '') : '';
        $mode = $key === '' ? 'unconfigured' : AutlanticBilling::billingModeFromApiKey($key);
        $nonce = wp_create_nonce('autlantic_test_connection');

        echo '<p><span class="button" style="pointer-events:none;">' . esc_html(strtoupper($mode)) . '</span> ';
        echo '<button type="button" class="button button-secondary" id="autlantic-test-connection">'
            . esc_html__('Test connection', 'autlantic-billing') . '</button> ';
        echo '<span id="autlantic-test-result"></span></p>';
        echo '<script>(function(){var b=document.getElementById("autlantic-test-connection");var o=document.getElementById("autlantic-test-result");if(!b)return;b.addEventListener("click",function(){o.textContent="'
            . esc_js(__('Checking…', 'autlantic-billing')) . '";var body=new URLSearchParams();body.set("action","autlantic_test_connection");body.set("_ajax_nonce","'
            . esc_js($nonce) . '");fetch(ajaxurl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body.toString()}).then(function(r){return r.json()}).then(function(data){o.textContent=(data&&data.data&&data.data.message)?data.data.message:(data&&data.success?"OK":"Failed");}).catch(function(){o.textContent="Request failed";});});})();</script>';

        $rows = array_reverse(Activity_Log::all());
        echo '<h3>' . esc_html__('Recent webhooks', 'autlantic-billing') . '</h3>';
        if ($rows === []) {
            echo '<p>' . esc_html__('No webhook deliveries recorded yet.', 'autlantic-billing') . '</p>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('When', 'autlantic-billing')
            . '</th><th>' . esc_html__('Result', 'autlantic-billing')
            . '</th><th>' . esc_html__('Event', 'autlantic-billing')
            . '</th><th>' . esc_html__('Detail', 'autlantic-billing') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['at'] > 0 ? wp_date('Y-m-d H:i:s', $row['at']) : '') . '</td>';
            echo '<td>' . esc_html($row['ok'] ? __('Accepted', 'autlantic-billing') : __('Rejected', 'autlantic-billing')) . '</td>';
            echo '<td><code>' . esc_html($row['type'] !== '' ? $row['type'] : '—') . '</code></td>';
            echo '<td>' . esc_html($row['message']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function test_connection(): void
    {
        check_ajax_referer('autlantic_test_connection');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        try {
            $billing = Client_Factory::from_gateway();
            $listed = $billing->listProducts();
            $products = is_array($listed['products'] ?? null) ? $listed['products'] : [];
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: 1: test or live, 2: product count */
                    __('Connected (%1$s). %2$d catalog products.', 'autlantic-billing'),
                    $billing->mode,
                    count($products),
                ),
            ]);
        } catch (AutlanticBillingException $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    public static function sync_order(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot sync this order.', 'autlantic-billing'));
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        check_admin_referer('autlantic_sync_order_' . $order_id);

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            wp_die(esc_html__('Order not found.', 'autlantic-billing'));
        }

        $note = self::pull_remote_status($order);
        $order->add_order_note($note);
        $order->save();

        wp_safe_redirect($order->get_edit_order_url());
        exit;
    }

    private static function pull_remote_status(\WC_Order $order): string
    {
        try {
            $billing = Client_Factory::from_gateway();
            $payment_id = Order_Meta::get($order, Order_Meta::PAYMENT_ID);
            if ($payment_id !== '') {
                $remote = $billing->getPayment($payment_id);
                $payment = is_array($remote['payment'] ?? null) ? $remote['payment'] : $remote;
                $status = (string) ($payment['status'] ?? '');
                if ($status === 'paid') {
                    Event_Handler::handle('payment.paid', ['payment' => $payment]);
                }

                return sprintf('Autlantic payment %s is %s.', $payment_id, $status !== '' ? $status : 'unknown');
            }

            $subscription_id = Order_Meta::get($order, Order_Meta::SUBSCRIPTION_ID);
            if ($subscription_id !== '') {
                $remote = $billing->getSubscription($subscription_id);
                $subscription = is_array($remote['subscription'] ?? null) ? $remote['subscription'] : $remote;
                $status = (string) ($subscription['status'] ?? '');
                if ($status === 'active') {
                    Event_Handler::handle('subscription.activated', ['subscription' => $subscription]);
                }

                return sprintf('Autlantic subscription %s is %s.', $subscription_id, $status !== '' ? $status : 'unknown');
            }

            $link_id = Order_Meta::get($order, Order_Meta::PAYMENT_LINK_ID);
            if ($link_id !== '') {
                $remote = $billing->getPaymentLink($link_id);
                $link = is_array($remote['paymentLink'] ?? null) ? $remote['paymentLink'] : $remote;
                $status = (string) ($link['status'] ?? '');

                return sprintf(
                    'Autlantic payment link %s is %s. Payment confirmation still arrives by webhook.',
                    $link_id,
                    $status !== '' ? $status : 'unknown',
                );
            }

            return 'No Autlantic ids on this order yet.';
        } catch (AutlanticBillingException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
