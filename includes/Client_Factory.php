<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

use Autlantic\Billing\AutlanticBilling;
use Autlantic\Billing\AutlanticBillingException;

/**
 * Builds the PHP Billing client from gateway settings.
 */
final class Client_Factory
{
    public static function from_gateway(?Gateway $gateway = null): AutlanticBilling
    {
        $gateway ??= self::gateway();
        if ($gateway === null) {
            throw new AutlanticBillingException(
                'Autlantic gateway is not available',
                'configuration',
            );
        }

        $api_key = trim((string) $gateway->get_option('api_key', ''));
        if ($api_key === '') {
            throw new AutlanticBillingException(
                'Autlantic API key is not configured',
                'configuration',
            );
        }

        $api_url = trim((string) $gateway->get_option('api_url', ''));
        if ($api_url === '') {
            $api_url = 'https://billing.autlantic.com';
        }

        $merchant_id = trim((string) $gateway->get_option('merchant_id', ''));

        return new AutlanticBilling(
            apiKey: $api_key,
            apiBaseUrl: $api_url,
            merchantId: $merchant_id !== '' ? $merchant_id : null,
        );
    }

    public static function gateway(): ?Gateway
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = $gateways['autlantic'] ?? null;

        return $gateway instanceof Gateway ? $gateway : null;
    }

    public static function webhook_secret(?Gateway $gateway = null): string
    {
        $gateway ??= self::gateway();
        if ($gateway === null) {
            return '';
        }

        return trim((string) $gateway->get_option('webhook_secret', ''));
    }

    public static function payout_address(?Gateway $gateway = null): string
    {
        $gateway ??= self::gateway();
        if ($gateway === null) {
            return '';
        }

        return trim((string) $gateway->get_option('payout_address', ''));
    }
}
