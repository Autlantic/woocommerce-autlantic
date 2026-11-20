<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

use Autlantic\Billing\AutlanticBillingException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persist and surface last checkout failure for store owners (A1).
 */
final class Checkout_Errors
{
    public const OPTION = 'autlantic_wc_last_checkout_error';
    public const NOTICE_TRANSIENT = 'autlantic_wc_admin_notice';

    /**
     * @param array{message: string, code?: string|null, request_id?: string|null, at?: int} $entry
     */
    public static function record(array $entry): void
    {
        $row = [
            'message' => substr((string) ($entry['message'] ?? ''), 0, 500),
            'code' => substr((string) ($entry['code'] ?? ''), 0, 64),
            'request_id' => substr((string) ($entry['request_id'] ?? ''), 0, 64),
            'at' => (int) ($entry['at'] ?? time()),
        ];
        update_option(self::OPTION, $row, false);
    }

    public static function record_exception(\Throwable $e): void
    {
        $code = null;
        $request_id = null;
        if ($e instanceof AutlanticBillingException) {
            $code = $e->codeName;
            $request_id = $e->requestId;
        }
        self::record([
            'message' => $e->getMessage(),
            'code' => $code,
            'request_id' => $request_id,
            'at' => time(),
        ]);

        if (self::is_configuration_error($e)) {
            set_transient(self::NOTICE_TRANSIENT, [
                'message' => $e->getMessage(),
                'at' => time(),
            ], DAY_IN_SECONDS);
        }
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * @return array{message: string, code: string, request_id: string, at: int}|null
     */
    public static function last(): ?array
    {
        $row = get_option(self::OPTION, null);
        if (!is_array($row) || trim((string) ($row['message'] ?? '')) === '') {
            return null;
        }

        return [
            'message' => (string) ($row['message'] ?? ''),
            'code' => (string) ($row['code'] ?? ''),
            'request_id' => (string) ($row['request_id'] ?? ''),
            'at' => (int) ($row['at'] ?? 0),
        ];
    }

    public static function is_configuration_error(\Throwable $e): bool
    {
        if ($e instanceof AutlanticBillingException) {
            $code = (string) ($e->codeName ?? '');
            if ($code === 'configuration' || $code === 'validation') {
                return true;
            }
        }
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'billing account')
            || str_contains($msg, 'payout')
            || str_contains($msg, 'api key')
            || str_contains($msg, 'webhook');
    }

    public static function maybe_admin_notice(): void
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }
        $notice = get_transient(self::NOTICE_TRANSIENT);
        if (!is_array($notice) || trim((string) ($notice['message'] ?? '')) === '') {
            return;
        }
        delete_transient(self::NOTICE_TRANSIENT);

        $message = (string) $notice['message'];
        echo '<div class="notice notice-error is-dismissible"><p>';
        echo '<strong>' . esc_html__('Autlantic Billing', 'autlantic-billing-for-woocommerce') . ':</strong> ';
        echo esc_html($message);
        echo ' ';
        echo esc_html__(
            'Check WooCommerce → Settings → Payments → Autlantic Billing, and the Billing account (payout wallet) in the Autlantic portal Billing settings.',
            'autlantic-billing-for-woocommerce',
        );
        echo '</p></div>';
    }

    public static function render_panel(): void
    {
        $last = self::last();
        if ($last === null) {
            return;
        }

        echo '<h3>' . esc_html__('Last checkout error', 'autlantic-billing-for-woocommerce') . '</h3>';
        echo '<p class="description">';
        echo esc_html($last['message']);
        if ($last['code'] !== '') {
            echo ' <code>' . esc_html($last['code']) . '</code>';
        }
        if ($last['request_id'] !== '') {
            echo ' <code>' . esc_html($last['request_id']) . '</code>';
        }
        if ($last['at'] > 0) {
            echo '<br />' . esc_html(wp_date('Y-m-d H:i:s', $last['at']));
        }
        echo '</p>';
    }
}
