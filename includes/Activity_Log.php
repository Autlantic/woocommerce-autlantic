<?php

declare(strict_types=1);

namespace Autlantic\WooCommerce;

/**
 * Ring buffer of webhook outcomes for the merchant admin. No raw bodies or secrets.
 */
final class Activity_Log
{
    private const OPTION = 'autlantic_wc_activity';
    private const MAX = 30;

    /**
     * @param array{ok: bool, type?: string, message: string} $entry
     */
    public static function add(array $entry): void
    {
        $rows = self::all();
        $rows[] = [
            'at' => time(),
            'ok' => $entry['ok'] === true,
            'type' => substr((string) ($entry['type'] ?? ''), 0, 64),
            'message' => substr((string) $entry['message'], 0, 180),
        ];
        if (count($rows) > self::MAX) {
            $rows = array_slice($rows, -self::MAX);
        }
        update_option(self::OPTION, $rows, false);
    }

    /**
     * @return list<array{at: int, ok: bool, type: string, message: string}>
     */
    public static function all(): array
    {
        $rows = get_option(self::OPTION, []);
        if (!is_array($rows)) {
            return [];
        }

        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean[] = [
                'at' => (int) ($row['at'] ?? 0),
                'ok' => ($row['ok'] ?? false) === true,
                'type' => (string) ($row['type'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
            ];
        }

        return $clean;
    }
}
