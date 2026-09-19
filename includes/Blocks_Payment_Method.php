<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Cart & checkout blocks registration for the Autlantic gateway.
 */
final class Blocks_Payment_Method extends AbstractPaymentMethodType
{
    protected $name = 'autlantic';

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_autlantic_settings', []);
    }

    public function is_active(): bool
    {
        return ($this->settings['enabled'] ?? 'no') === 'yes';
    }

    /**
     * @return list<string>
     */
    public function get_payment_method_script_handles(): array
    {
        wp_register_script(
            'autlantic-blocks',
            AUTLANTIC_WC_PLUGIN_URL . 'assets/js/blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            AUTLANTIC_WC_VERSION,
            true,
        );

        return ['autlantic-blocks'];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        return [
            'title' => $this->settings['title'] ?? __('USDC (Autlantic)', 'autlantic-billing'),
            'description' => $this->settings['description'] ?? '',
            'icon' => AUTLANTIC_WC_PLUGIN_URL . 'assets/img/mark-64.png',
            'supports' => ['products', 'refunds'],
        ];
    }
}
