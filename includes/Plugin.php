<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

/**
 * Plugin bootstrap.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function init(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_action('rest_api_init', [Webhook_Controller::class, 'register_routes']);
        add_action('before_woocommerce_init', [$this, 'declare_compatibility']);

        if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            add_action('woocommerce_blocks_loaded', [$this, 'register_blocks']);
        }

        Subscriptions::init();
        Admin_Order_Meta::init();
        Admin_Tools::init();
    }

    /**
     * @param array<int, string> $gateways
     * @return array<int, string>
     */
    public function register_gateway(array $gateways): array
    {
        $gateways[] = Gateway::class;

        return $gateways;
    }

    public function declare_compatibility(): void
    {
        if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            AUTLANTIC_WC_PLUGIN_FILE,
            true,
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            AUTLANTIC_WC_PLUGIN_FILE,
            true,
        );
    }

    public function register_blocks(): void
    {
        if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class)) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            static function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry): void {
                $registry->register(new Blocks_Payment_Method());
            },
        );
    }
}
