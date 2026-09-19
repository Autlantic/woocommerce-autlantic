<?php
/**
 * Plugin Name:       Autlantic Billing for WooCommerce
 * Plugin URI:        https://github.com/Autlantic/payments-sdk/tree/main/integrations/woocommerce
 * Description:       Accept USDC on Base via Autlantic Billing. One-time checkout and optional WooCommerce Subscriptions.
 * Version:           1.1.1
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Autlantic Limited
 * Author URI:        https://autlantic.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       autlantic-billing
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AUTLANTIC_WC_VERSION', '1.1.1');
define('AUTLANTIC_WC_PLUGIN_FILE', __FILE__);
define('AUTLANTIC_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AUTLANTIC_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

$autlantic_autoload = AUTLANTIC_WC_PLUGIN_DIR . 'vendor/autoload.php';
if (is_readable($autlantic_autoload)) {
    require_once $autlantic_autoload;
} else {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'Autlantic Billing for WooCommerce needs Composer dependencies. Run composer install in integrations/woocommerce.',
            'autlantic-billing',
        );
        echo '</p></div>';
    });
    return;
}

/**
 * Bootstrap after WooCommerce loads.
 */
add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p>';
            echo esc_html__(
                'Autlantic Billing for WooCommerce requires WooCommerce to be installed and active.',
                'autlantic-billing',
            );
            echo '</p></div>';
        });
        return;
    }

    \Autlantic\WooCommerce\Plugin::instance()->init();
}, 20);

register_activation_hook(__FILE__, static function (): void {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__(
                'Autlantic Billing for WooCommerce requires WooCommerce.',
                'autlantic-billing',
            ),
        );
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});
