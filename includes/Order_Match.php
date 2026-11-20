<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure helpers for matching Autlantic webhook payloads to Woo orders.
 * Callable from bin/unit.php after defining ABSPATH.
 */
final class Order_Match
{
    public const AMOUNT_TOLERANCE = 0.000001;

    /**
     * @param array<string, mixed> $metadata
     */
    public static function site_matches(array $metadata, string $expected_home_url): bool
    {
        $site = trim((string) ($metadata['woo_site'] ?? ''));
        if ($site === '') {
            return false;
        }

        return self::normalize_url($site) === self::normalize_url($expected_home_url);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function order_key_matches(array $metadata, string $order_key): bool
    {
        $key = trim((string) ($metadata['woo_order_key'] ?? ''));
        if ($key === '' || $order_key === '') {
            return false;
        }

        return hash_equals($order_key, $key);
    }

    /**
     * When either side has a payment link id, they must match.
     */
    public static function payment_link_matches(?string $event_link_id, ?string $stored_link_id): bool
    {
        $event = trim((string) $event_link_id);
        $stored = trim((string) $stored_link_id);

        if ($event === '' && $stored === '') {
            return true;
        }
        if ($event === '' || $stored === '') {
            return false;
        }

        return hash_equals($stored, $event);
    }

    public static function amount_matches(float $paid_usdc, float $order_total): bool
    {
        if ($paid_usdc < 0 || $order_total < 0) {
            return false;
        }

        return abs($paid_usdc - $order_total) <= self::AMOUNT_TOLERANCE;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{ok: bool, reason?: string}
     */
    public static function identity_ok(
        array $metadata,
        string $expected_home_url,
        string $order_key,
        ?string $event_link_id,
        ?string $stored_link_id,
    ): array {
        if (!self::site_matches($metadata, $expected_home_url)) {
            return ['ok' => false, 'reason' => 'woo_site_mismatch'];
        }
        if (!self::order_key_matches($metadata, $order_key)) {
            return ['ok' => false, 'reason' => 'woo_order_key_mismatch'];
        }
        if (!self::payment_link_matches($event_link_id, $stored_link_id)) {
            return ['ok' => false, 'reason' => 'payment_link_mismatch'];
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, reason?: string}
     */
    public static function payment_paid_ok(
        float $paid_usdc,
        float $order_total,
        ?string $event_link_id,
        ?string $stored_link_id,
    ): array {
        if (!self::amount_matches($paid_usdc, $order_total)) {
            return ['ok' => false, 'reason' => 'amount_mismatch'];
        }
        if (!self::payment_link_matches($event_link_id, $stored_link_id)) {
            return ['ok' => false, 'reason' => 'payment_link_mismatch'];
        }

        return ['ok' => true];
    }

    public static function normalize_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $path = rtrim($path, '/') . '/';

        return $scheme . '://' . $host . $port . $path;
    }
}
