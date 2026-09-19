<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

use Autlantic\Billing\Webhook;

/**
 * REST webhook receiver for Autlantic Billing events.
 */
final class Webhook_Controller
{
    public static function register_routes(): void
    {
        register_rest_route('autlantic/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function handle(\WP_REST_Request $request)
    {
        $raw = $request->get_body();
        $signature = $request->get_header('x-autlantic-signature');
        if ($signature === null || $signature === '') {
            $signature = $request->get_header('X-Autlantic-Signature');
        }

        $secret = Client_Factory::webhook_secret();
        $verified = Webhook::verifyDetailed($secret, $raw, $signature);
        if (($verified['ok'] ?? false) !== true) {
            $reason = (string) ($verified['reason'] ?? 'unknown');
            self::log('Webhook rejected: ' . $reason);
            Activity_Log::add([
                'ok' => false,
                'type' => '',
                'message' => 'signature ' . $reason,
            ]);

            return new \WP_Error(
                'autlantic_webhook_invalid',
                'Invalid webhook signature',
                ['status' => 401],
            );
        }

        $event = Webhook::parseEvent($raw);
        if ($event === null) {
            return new \WP_Error(
                'autlantic_webhook_body',
                'Invalid webhook body',
                ['status' => 400],
            );
        }

        $event_id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        if ($event_id !== '' && self::already_processed($event_id)) {
            Activity_Log::add([
                'ok' => true,
                'type' => $type,
                'message' => 'duplicate ' . $event_id,
            ]);

            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }

        try {
            Event_Handler::handle($type, is_array($event['data'] ?? null) ? $event['data'] : []);
            if ($event_id !== '') {
                self::mark_processed($event_id);
            }
            Activity_Log::add([
                'ok' => true,
                'type' => $type,
                'message' => $event_id !== '' ? $event_id : 'accepted',
            ]);
        } catch (\Throwable $e) {
            self::log('Webhook handler error: ' . $e->getMessage());
            Activity_Log::add([
                'ok' => false,
                'type' => $type,
                'message' => $e->getMessage(),
            ]);

            return new \WP_Error(
                'autlantic_webhook_handler',
                $e->getMessage(),
                ['status' => 500],
            );
        }

        return new \WP_REST_Response(['received' => true], 200);
    }

    private static function already_processed(string $event_id): bool
    {
        $seen = get_option('autlantic_wc_processed_events', []);
        if (!is_array($seen)) {
            return false;
        }

        return isset($seen[$event_id]);
    }

    private static function mark_processed(string $event_id): void
    {
        $seen = get_option('autlantic_wc_processed_events', []);
        if (!is_array($seen)) {
            $seen = [];
        }
        $seen[$event_id] = time();
        // Keep last 500 event ids.
        if (count($seen) > 500) {
            asort($seen);
            $seen = array_slice($seen, -500, null, true);
        }
        update_option('autlantic_wc_processed_events', $seen, false);
    }

    private static function log(string $message): void
    {
        $gateway = Client_Factory::gateway();
        if ($gateway instanceof Gateway) {
            $gateway->log($message);
        }
    }
}
