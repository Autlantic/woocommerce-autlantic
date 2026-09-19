<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

use Autlantic\Billing\AutlanticBillingException;

/**
 * WooCommerce payment gateway for Autlantic Billing (USDC on Base).
 */
final class Gateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'autlantic';
        $this->method_title = __('Autlantic Billing', 'autlantic-billing');
        $this->method_description = __(
            'Accept USDC on Base. Buyers pay via hosted Autlantic checkout. Funds settle to your merchant payout wallet.',
            'autlantic-billing',
        );
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];

        if (Subscriptions::is_active()) {
            $this->supports = array_merge($this->supports, Subscriptions::gateway_supports());
        }

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', __('USDC (Autlantic)', 'autlantic-billing'));
        $this->description = $this->get_option(
            'description',
            __('Pay with USDC on Base. You will connect a wallet on Autlantic checkout.', 'autlantic-billing'),
        );
        $this->enabled = $this->get_option('enabled', 'no');

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options'],
        );
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_panel']);
    }

    public function init_form_fields(): void
    {
        $webhook_url = rest_url('autlantic/v1/webhook');

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable / Disable', 'autlantic-billing'),
                'type' => 'checkbox',
                'label' => __('Enable Autlantic Billing', 'autlantic-billing'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'autlantic-billing'),
                'type' => 'text',
                'description' => __('Shown to customers at checkout.', 'autlantic-billing'),
                'default' => __('USDC (Autlantic)', 'autlantic-billing'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'autlantic-billing'),
                'type' => 'textarea',
                'default' => __(
                    'Pay with USDC on Base. You will connect a wallet on Autlantic checkout.',
                    'autlantic-billing',
                ),
            ],
            'api_key' => [
                'title' => __('API key', 'autlantic-billing'),
                'type' => 'password',
                'description' => __(
                    'From the Autlantic merchant portal. Use abk_test_… for Test or abk_live_… for Live.',
                    'autlantic-billing',
                ),
                'default' => '',
                'desc_tip' => true,
            ],
            'webhook_secret' => [
                'title' => __('Webhook signing secret', 'autlantic-billing'),
                'type' => 'password',
                'description' => sprintf(
                    /* translators: %s: webhook URL */
                    __(
                        'Portal → Webhooks → endpoint secret for this store. Register this URL: %s',
                        'autlantic-billing',
                    ),
                    esc_html($webhook_url),
                ),
                'default' => '',
            ],
            'payout_address' => [
                'title' => __('Payout wallet (EVM)', 'autlantic-billing'),
                'type' => 'text',
                'description' => __(
                    'Optional override. Leave blank to use the payout address configured on the merchant in the portal.',
                    'autlantic-billing',
                ),
                'default' => '',
                'desc_tip' => true,
            ],
            'api_url' => [
                'title' => __('API base URL', 'autlantic-billing'),
                'type' => 'text',
                'description' => __('Default https://billing.autlantic.com. Change only for staging.', 'autlantic-billing'),
                'default' => 'https://billing.autlantic.com',
                'desc_tip' => true,
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'autlantic-billing'),
                'type' => 'text',
                'description' => __('Optional. Usually inferred from the API key.', 'autlantic-billing'),
                'default' => '',
                'desc_tip' => true,
            ],
            'order_status_on_paid' => [
                'title' => __('Order status after payment', 'autlantic-billing'),
                'type' => 'select',
                'options' => [
                    'processing' => __('Processing', 'autlantic-billing'),
                    'completed' => __('Completed', 'autlantic-billing'),
                ],
                'default' => 'processing',
            ],
            'logging' => [
                'title' => __('Debug log', 'autlantic-billing'),
                'type' => 'checkbox',
                'label' => __('Log Autlantic events to WooCommerce → Status → Logs', 'autlantic-billing'),
                'default' => 'no',
            ],
        ];
    }

    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }

        if (trim((string) $this->get_option('api_key', '')) === '') {
            return false;
        }

        $currency = strtoupper(get_woocommerce_currency());
        if (!in_array($currency, ['USD', 'USDC'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param int $order_id
     * @return array{result: string, redirect?: string, messages?: string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            wc_add_notice(__('Order not found.', 'autlantic-billing'), 'error');

            return ['result' => 'fail'];
        }

        if (!Order_Meta::currency_supported($order)) {
            wc_add_notice(
                __('Autlantic Billing only supports USD or USDC store currency.', 'autlantic-billing'),
                'error',
            );

            return ['result' => 'fail'];
        }

        $amount = Order_Meta::amount_usdc($order);
        if ($amount <= 0) {
            wc_add_notice(__('Order total must be greater than zero.', 'autlantic-billing'), 'error');

            return ['result' => 'fail'];
        }

        try {
            if (Subscriptions::order_contains_subscription($order)) {
                return $this->process_subscription_payment($order, $amount);
            }

            return $this->process_one_time_payment($order, $amount);
        } catch (AutlanticBillingException $e) {
            $this->log('process_payment failed: ' . $e->getMessage());
            wc_add_notice(
                sprintf(
                    /* translators: %s: error message */
                    __('Autlantic payment failed: %s', 'autlantic-billing'),
                    $e->getMessage(),
                ),
                'error',
            );

            return ['result' => 'fail'];
        } catch (\Throwable $e) {
            $this->log('process_payment unexpected: ' . $e->getMessage());
            wc_add_notice(__('Autlantic payment failed. Please try again.', 'autlantic-billing'), 'error');

            return ['result' => 'fail'];
        }
    }

    /**
     * @return array{result: string, redirect: string}
     */
    private function process_one_time_payment(\WC_Order $order, float $amount): array
    {
        $billing = Client_Factory::from_gateway($this);
        $merchant_ref_prefix = 'woo_' . $order->get_id();
        $success_url = $this->get_return_url($order);
        $cancel_url = $order->get_checkout_payment_url();

        $body = [
            'amountUsdc' => $amount,
            'merchantRefPrefix' => $merchant_ref_prefix,
            'description' => sprintf('WooCommerce order #%s', $order->get_order_number()),
            'maxUses' => 1,
            'successUrl' => $success_url,
            'cancelUrl' => $cancel_url,
            'collectEmail' => true,
            'metadata' => [
                'woo_order_id' => (string) $order->get_id(),
                'woo_order_key' => $order->get_order_key(),
                'woo_site' => home_url('/'),
            ],
        ];

        $payout = Client_Factory::payout_address($this);
        if ($payout !== '') {
            $body['payoutAddressEvm'] = $payout;
        }

        $created = $billing->createPaymentLink($body);
        $payment_link = is_array($created['paymentLink'] ?? null) ? $created['paymentLink'] : [];
        $url = (string) ($created['url'] ?? '');
        $link_id = (string) ($payment_link['id'] ?? '');

        if ($url === '' || $link_id === '') {
            throw new AutlanticBillingException(
                'Payment link response missing url or id',
                'api_error',
            );
        }

        Order_Meta::set($order, Order_Meta::PAYMENT_LINK_ID, $link_id);
        Order_Meta::set($order, Order_Meta::PAYMENT_LINK_URL, $url);
        Order_Meta::set($order, Order_Meta::CHECKOUT_URL, $url);
        Order_Meta::set($order, Order_Meta::MERCHANT_REF, $merchant_ref_prefix);
        Order_Meta::set($order, Order_Meta::MODE, $billing->mode);
        $order->update_status(
            'pending',
            __('Awaiting USDC payment via Autlantic checkout.', 'autlantic-billing'),
        );
        $order->save();

        $this->log(sprintf('Created payment link %s for order %d', $link_id, $order->get_id()));

        return [
            'result' => 'success',
            'redirect' => $url,
        ];
    }

    /**
     * @return array{result: string, redirect: string}
     */
    private function process_subscription_payment(\WC_Order $order, float $amount): array
    {
        $interval = Subscriptions::billing_interval_for_order($order);
        if ($interval === null) {
            throw new AutlanticBillingException(
                'Subscription interval must be week, month, or year for Autlantic',
                'validation',
            );
        }

        $billing = Client_Factory::from_gateway($this);
        $merchant_ref = 'woo_sub_' . $order->get_id();
        $success_url = $this->get_return_url($order);
        $cancel_url = $order->get_checkout_payment_url();

        $body = [
            'merchantRef' => $merchant_ref,
            'customerWallet' => Order_Meta::PENDING_CUSTOMER_WALLET,
            'amountUsdc' => $amount,
            'interval' => $interval,
            'successUrl' => $success_url,
            'cancelUrl' => $cancel_url,
            'metadata' => [
                'woo_order_id' => (string) $order->get_id(),
                'woo_order_key' => $order->get_order_key(),
                'woo_site' => home_url('/'),
                'woo_pending_wallet' => '1',
            ],
        ];

        $payout = Client_Factory::payout_address($this);
        if ($payout !== '') {
            $body['payoutAddressEvm'] = $payout;
        }

        $created = $billing->createSubscription($body);
        $subscription = is_array($created['subscription'] ?? null) ? $created['subscription'] : [];
        $invoice = is_array($created['invoice'] ?? null) ? $created['invoice'] : [];
        $checkout_url = (string) ($created['checkoutUrl'] ?? '');
        $subscription_id = (string) ($subscription['id'] ?? '');
        $invoice_id = (string) ($invoice['id'] ?? '');

        if ($checkout_url === '' || $subscription_id === '') {
            throw new AutlanticBillingException(
                'Subscription response missing checkoutUrl or id',
                'api_error',
            );
        }

        Order_Meta::set($order, Order_Meta::SUBSCRIPTION_ID, $subscription_id);
        Order_Meta::set($order, Order_Meta::INVOICE_ID, $invoice_id);
        Order_Meta::set($order, Order_Meta::CHECKOUT_URL, $checkout_url);
        Order_Meta::set($order, Order_Meta::MERCHANT_REF, $merchant_ref);
        Order_Meta::set($order, Order_Meta::MODE, $billing->mode);
        Subscriptions::attach_ids_to_wcs_subscriptions($order, $subscription_id);

        $order->update_status(
            'pending',
            __('Awaiting USDC subscription activation via Autlantic checkout.', 'autlantic-billing'),
        );
        $order->save();

        $this->log(sprintf('Created subscription %s for order %d', $subscription_id, $order->get_id()));

        return [
            'result' => 'success',
            'redirect' => $checkout_url,
        ];
    }

    /**
     * @param int $order_id
     * @param float|null $amount
     * @param string $reason
     */
    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('autlantic_refund', __('Order not found.', 'autlantic-billing'));
        }

        $invoice_id = Order_Meta::get($order, Order_Meta::INVOICE_ID);
        if ($invoice_id === '') {
            return new \WP_Error(
                'autlantic_refund',
                __(
                    'No Autlantic invoice on this order. One-time payment-link refunds are not available via API yet; refund from the Autlantic portal if supported.',
                    'autlantic-billing',
                ),
            );
        }

        try {
            $billing = Client_Factory::from_gateway($this);
            $body = [];
            if ($amount !== null && (float) $amount > 0) {
                $body['amountUsdc'] = (float) $amount;
            }
            $billing->refundInvoice($invoice_id, $body !== [] ? $body : null);
            $order->add_order_note(
                sprintf(
                    /* translators: 1: invoice id, 2: reason */
                    __('Autlantic refund requested for invoice %1$s. %2$s', 'autlantic-billing'),
                    $invoice_id,
                    $reason !== '' ? $reason : '',
                ),
            );
            $order->save();

            return true;
        } catch (AutlanticBillingException $e) {
            return new \WP_Error('autlantic_refund', $e->getMessage());
        }
    }

    public function thankyou_panel(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        if ($order->is_paid()) {
            echo '<p>' . esc_html__('USDC payment received. Thank you.', 'autlantic-billing') . '</p>';

            return;
        }

        $checkout = Order_Meta::get($order, Order_Meta::CHECKOUT_URL);
        if ($checkout === '') {
            return;
        }

        echo '<p>' . esc_html__(
            'If you have not finished paying, continue to Autlantic checkout:',
            'autlantic-billing',
        ) . '</p>';
        echo '<p><a class="button" href="' . esc_url($checkout) . '">';
        echo esc_html__('Complete USDC payment', 'autlantic-billing');
        echo '</a></p>';
    }

    public function log(string $message): void
    {
        if ($this->get_option('logging') !== 'yes') {
            return;
        }

        $logger = wc_get_logger();
        $logger->info($message, ['source' => 'autlantic']);
    }
}
