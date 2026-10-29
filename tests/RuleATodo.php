<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use PHPUnit\Framework\Attributes\PreCondition;
use Throwable;

/**
 * Runs a test listed in tests/rule-a-todo.json as an expected failure.
 *
 * After setup, the hook runs the listed test itself: a failure marks it
 * incomplete with its ticket, and a pass fails, so a fix has to remove the
 * ledger entry.
 */
trait RuleATodo
{
    #[PreCondition]
    public function runRuleATodoEntry(): void
    {
        $ticket = RuleATodoLedger::ticketFor(static::class, $this->nameWithDataSet());
        if ($ticket === null) {
            return;
        }
        try {
            $this->{$this->name()}(...array_values($this->providedData()));
        } catch (Throwable $failure) {
            self::markTestIncomplete(sprintf('Expected failure, tests/rule-a-todo.json (%s): %s', $ticket, strtok($failure->getMessage(), "\n")));
        }

        self::fail(sprintf('Listed in tests/rule-a-todo.json (%s) but passes now: remove its entry.', $ticket));
    }
}
