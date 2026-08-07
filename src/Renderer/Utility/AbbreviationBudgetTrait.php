<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Document;

/**
 * Bounds the cumulative bytes contributed by DERIVED-TEXT EXPANSION across a
 * single render, guarding against an output-amplification (memory) DoS.
 *
 * Two constructs republish text they did not pay for, and both charge here:
 *
 * - Each occurrence of an abbreviation re-emits its full definition (the `title`
 *   of an `<abbr>` element, the `(definition)` suffix in ANSI, etc.). A tiny
 *   source such as `*[HT]: <50KB of text>` followed by many `HT` occurrences
 *   would otherwise expand to `definition_len * occurrence_count` bytes
 *   (hundreds of MB), which PHP happily allocates - a true RAM-exhaustion DoS.
 * - A cross-reference republishes its target heading's whole display text while
 *   the reference itself costs only the slug, so K references to one long
 *   heading emit `K * heading_len` bytes (carve-php#1061).
 *
 * They share one budget because they amplify the same output through the same
 * renderers; a second mechanism would be a second thing to get wrong.
 *
 * Policy (MUST stay identical across carve-php, carve-js and carve-rs):
 *   budget = max(BUDGET_BASE, BUDGET_FACTOR * sourceByteLength)
 * Once the next occurrence's expansion would exceed the budget, that occurrence
 * (and every subsequent one) degrades gracefully to its plain key text only -
 * no `<abbr>` wrapper, no title. The budget sits far above any real document
 * and every corpus fixture, so normal output is byte-identical.
 *
 * The counter is reset per render call (resetAbbreviationBudget()).
 */
trait AbbreviationBudgetTrait
{
    /**
     * Base (floor) budget in bytes, applied even for tiny sources.
     *
     * @var int
     */
    protected const ABBREVIATION_BUDGET_BASE = 1000000;

    /**
     * Multiplier applied to the source byte length.
     *
     * @var int
     */
    protected const ABBREVIATION_BUDGET_FACTOR = 8;

    /**
     * Cumulative expansion bytes already emitted in the current render.
     */
    protected int $abbreviationExpansionBytes = 0;

    /**
     * Computed budget for the current render (max of base and factor*source).
     */
    protected int $abbreviationBudget = self::ABBREVIATION_BUDGET_BASE;

    /**
     * Reset the budget counter and (re)compute it for a fresh render of $document.
     *
     * Every renderer sizes its budget through this one call, so the length a
     * budget is sized from is chosen in exactly ONE place. It is deliberately
     * `getExpansionBudgetLength()` and not `getSourceLength()`: on the ingest
     * path the latter is what the PAYLOAD claims, and a tree that inflates it
     * widens the guard meant to bound it (carve-php#1052, fixed in
     * carve-php#1055). A new consumer that reached for the raw claim would
     * quietly reopen that.
     */
    protected function resetExpansionBudgetForDocument(Document $document): void
    {
        $this->resetAbbreviationBudget($document->getExpansionBudgetLength());
    }

    /**
     * Reset the budget counter and (re)compute the budget for a fresh render.
     */
    protected function resetAbbreviationBudget(int $sourceLength): void
    {
        $this->abbreviationExpansionBytes = 0;
        $this->abbreviationBudget = max(
            self::ABBREVIATION_BUDGET_BASE,
            self::ABBREVIATION_BUDGET_FACTOR * $sourceLength,
        );
    }

    /**
     * Charge a single abbreviation occurrence against the budget.
     *
     * @param string $expansion The definition text whose bytes are emitted.
     *
     * @return bool True if the expansion fits within budget and may be emitted
     *   (the bytes are charged); false if it would exceed the budget and the
     *   occurrence must degrade to plain key text.
     */
    protected function chargeAbbreviationExpansion(string $expansion): bool
    {
        return $this->chargeExpansion($expansion);
    }

    /**
     * Charge emitted expansion bytes against the per-render budget.
     *
     * @param string $emitted The text whose bytes this occurrence emits.
     *
     * @return bool True if it fits within budget and may be emitted (the bytes
     *   are charged); false if it would exceed the budget and the occurrence
     *   must degrade.
     */
    protected function chargeExpansion(string $emitted): bool
    {
        $cost = strlen($emitted);
        if ($this->abbreviationExpansionBytes + $cost > $this->abbreviationBudget) {
            return false;
        }

        $this->abbreviationExpansionBytes += $cost;

        return true;
    }
}
