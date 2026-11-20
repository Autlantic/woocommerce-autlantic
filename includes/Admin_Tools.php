<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

use Autlantic\Billing\AutlanticBilling;
use Autlantic\Billing\AutlanticBillingException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merchant tools: connection test, webhook activity, and order status sync.
 */
final class Admin_Tools
{
    public static function init(): void
    {
        add_action('wp_ajax_autlantic_test_connection', [self::class, 'test_connection']);
        add_action('admin_post_autlantic_sync_order', [self::class, 'sync_order']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('admin_notices', [Checkout_Errors::class, 'maybe_admin_notice']);
    }

    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen gate
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen gate
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        if ($tab !== 'checkout' || $section !== 'autlantic') {
            return;
        }

        $handle = 'autlantic-admin-tools';
        wp_enqueue_script(
            $handle,
            AUTLANTIC_WC_PLUGIN_URL . 'assets/js/admin-tools.js',
            [],
            AUTLANTIC_WC_VERSION,
            true,
        );
        wp_localize_script($handle, 'autlanticAdminTools', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('autlantic_test_connection'),
            'checking' => __('Checking readiness…', 'autlantic-billing-for-woocommerce'),
        ]);
    }

    public static function render_panel(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $gateway = Client_Factory::gateway();
        $key = $gateway instanceof Gateway ? (string) $gateway->get_option('api_key', '') : '';
        $mode = $key === '' ? 'unconfigured' : AutlanticBilling::billingModeFromApiKey($key);

        echo '<p><span class="button" style="pointer-events:none;">' . esc_html(strtoupper($mode)) . '</span> ';
        echo '<button type="button" class="button button-secondary" id="autlantic-test-connection">'
            . esc_html__('Test readiness', 'autlantic-billing-for-woocommerce') . '</button> ';
        echo '<span id="autlantic-test-result"></span></p>';

        Checkout_Errors::render_panel();

        $rows = array_reverse(Activity_Log::all());
        echo '<h3>' . esc_html__('Recent webhooks', 'autlantic-billing-for-woocommerce') . '</h3>';
        if ($rows === []) {
            echo '<p>' . esc_html__('No webhook deliveries recorded yet.', 'autlantic-billing-for-woocommerce') . '</p>';

            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('When', 'autlantic-billing-for-woocommerce')
            . '</th><th>' . esc_html__('Result', 'autlantic-billing-for-woocommerce')
            . '</th><th>' . esc_html__('Event', 'autlantic-billing-for-woocommerce')
            . '</th><th>' . esc_html__('Detail', 'autlantic-billing-for-woocommerce') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['at'] > 0 ? wp_date('Y-m-d H:i:s', $row['at']) : '') . '</td>';
            echo '<td>' . esc_html($row['ok'] ? __('Accepted', 'autlantic-billing-for-woocommerce') : __('Rejected', 'autlantic-billing-for-woocommerce')) . '</td>';
            echo '<td><code>' . esc_html($row['type'] !== '' ? $row['type'] : '-') . '</code></td>';
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
            $gateway = Client_Factory::gateway();
            if ($gateway === null) {
                wp_send_json_error(['message' => 'Gateway not available'], 400);
            }

            $secret = Client_Factory::webhook_secret($gateway);
            if ($secret === '') {
                wp_send_json_error([
                    'message' => __(
                        'Webhook signing secret is missing. Paste the portal endpoint secret, then try again.',
                        'autlantic-billing-for-woocommerce',
                    ),
                ], 400);
            }

            $billing = Client_Factory::from_gateway($gateway);
            $listed = $billing->listProducts();
            $products = is_array($listed['products'] ?? null) ? $listed['products'] : [];

            $body = [
                'amountUsdc' => 1.0,
                'merchantRefPrefix' => 'woo_readiness_' . time(),
                'description' => 'Autlantic WooCommerce readiness check',
                'maxUses' => 1,
                'metadata' => [
                    'woo_readiness' => '1',
                    'woo_site' => home_url('/'),
                ],
            ];
            $payout = Client_Factory::payout_address($gateway);
            if ($payout !== '') {
                $body['payoutAddressEvm'] = $payout;
            }

            $created = $billing->createPaymentLink($body);
            $payment_link = is_array($created['paymentLink'] ?? null) ? $created['paymentLink'] : [];
            $link_id = (string) ($payment_link['id'] ?? '');
            if ($link_id === '') {
                throw new AutlanticBillingException(
                    'Readiness check: payment link response missing id',
                    'api_error',
                );
            }

            try {
                $billing->disablePaymentLink($link_id);
            } catch (AutlanticBillingException $disable_error) {
                // Link was created; disable failure should not fail readiness.
                unset($disable_error);
            }

            Checkout_Errors::clear();
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: 1: test or live, 2: product count */
                    __(
                        'Ready (%1$s). API key ok, webhook secret set, billing account (payout wallet) ok. %2$d catalog products.',
                        'autlantic-billing-for-woocommerce',
                    ),
                    $billing->mode,
                    count($products),
                ),
            ]);
        } catch (AutlanticBillingException $e) {
            Checkout_Errors::record_exception($e);
            wp_send_json_error(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            Checkout_Errors::record_exception($e);
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    public static function sync_order(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot sync this order.', 'autlantic-billing-for-woocommerce'));
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        check_admin_referer('autlantic_sync_order_' . $order_id);

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            wp_die(esc_html__('Order not found.', 'autlantic-billing-for-woocommerce'));
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
