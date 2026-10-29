<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

final class RuleATodoLedger
{
    /**
     * @var array<string, string>|null
     */
    private static ?array $entries = null;

    public static function ticketFor(string $class, string $test): ?string
    {
        if (self::$entries === null) {
            self::$entries = [];
            $ledger = json_decode((string)file_get_contents(__DIR__ . '/rule-a-todo.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($ledger['entries'] as $entry) {
                self::$entries[$entry['test']] = $entry['ticket'];
            }
        }

        return self::$entries[$class . '::' . $test] ?? null;
    }
}
