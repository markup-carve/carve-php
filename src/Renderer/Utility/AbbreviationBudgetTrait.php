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
     * Bytes each already-rendered cross-reference label cost, keyed by resolved
     * target id, for the current render.
     *
     * @var array<string, int>
     */
    protected array $labelExpansionCosts = [];

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
        $this->labelExpansionCosts = [];
        $this->abbreviationBudget = max(
            self::ABBREVIATION_BUDGET_BASE,
            self::ABBREVIATION_BUDGET_FACTOR * $sourceLength,
        );
    }

    /**
     * Whether this target's label can still be emitted, asked BEFORE it is
     * built.
     *
     * The budget caps the bytes a render writes; it did not cap the work spent
     * reaching them. A label costs the same on every reference to one target
     * within a render, so the first reference's cost answers every later one:
     * once it no longer fits, the derivation and the render would only be
     * discarded (carve-php#2647).
     *
     * True for a target whose cost is not known yet, so the first reference to
     * it renders and nothing degrades on a guess.
     */
    protected function labelExpansionStillAffordable(string $id): bool
    {
        $cost = $this->labelExpansionCosts[$id] ?? null;

        return $cost === null
            || $this->abbreviationExpansionBytes + $cost <= $this->abbreviationBudget;
    }

    /**
     * Charge a rendered cross-reference label and record what it cost, so the
     * next reference to the same target can consult the budget first.
     *
     * @param string $id Resolved target id.
     * @param string $emitted The label bytes this reference emits.
     *
     * @return bool True if it fits within budget and may be emitted (the bytes
     *   are charged); false if the reference must degrade.
     */
    protected function chargeLabelExpansion(string $id, string $emitted): bool
    {
        $this->labelExpansionCosts[$id] = strlen($emitted);

        return $this->chargeExpansion($emitted);
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
