<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * The fields of the writer a pass of the repair decides (carve-js `Snapshot` of the `state` fields, write-passes.ts).
 * A field of `WriterState` or `Shared` that a pass changes and that is not a cache belongs here.
 */
final class PassState
{
    /**
     * The places in `fields()` of the fields `isAsked` puts back after its trials: `lines`, `escapes`, `escaped`,
     * `starts`.
     *
     * @var list<int>
     */
    public const TRIAL_FIELDS = [0, 1, 2, 6];

    /**
     * The place of `bracedAnyway` in `fields()`.
     *
     * @var int
     */
    public const BRACED = 5;

    /**
     * @param list<string> $lines
     * @param array<int, list<int>> $escapes
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Node> $escaped
     * @param array<int, int> $forms
     * @param array<int, true> $glued
     * @param array<int, true> $bracedAnyway
     * @param list<array{\MarkupCarve\Carve\Writer\Check\Node, int}> $starts
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Attempt> $attempts
     * @param bool $blankFirst
     * @param list<array{\MarkupCarve\Carve\Writer\Check\Node, string|null}> $lossLog
     * @param array<int, true> $lost
     * @param array<int, list<string>> $lostKeys
     * @param \MarkupCarve\Carve\Writer\Check\Node|null $parsed
     * @param bool $linkFallback
     * @param bool $sawLink
     * @param string|null $planWhy
     * @param list<array{\MarkupCarve\Carve\Writer\Check\Node, int}> $cappedRuns
     * @param array<int, string> $contentKeys
     * @param array<string, true> $reportedBraceKinds
     */
    private function __construct(
        public readonly array $lines,
        public readonly array $escapes,
        public readonly array $escaped,
        public readonly array $forms,
        public readonly array $glued,
        public readonly array $bracedAnyway,
        public readonly array $starts,
        public readonly array $attempts,
        public readonly bool $blankFirst,
        public readonly array $lossLog,
        public readonly array $lost,
        public readonly array $lostKeys,
        public readonly ?Node $parsed,
        public readonly bool $linkFallback,
        public readonly bool $sawLink,
        public readonly ?string $planWhy,
        public readonly array $cappedRuns,
        public readonly array $contentKeys,
        public readonly array $reportedBraceKinds,
    ) {
    }

    public static function take(WriterState $w): self
    {
        return new self(
            $w->lines,
            $w->escapes,
            $w->escaped,
            $w->forms,
            $w->glued,
            $w->bracedAnyway,
            $w->starts,
            self::copyAttempts($w->attempts),
            $w->blankFirst,
            $w->lossLog,
            $w->lost,
            $w->lostKeys,
            $w->parsed,
            $w->linkFallback,
            $w->sawLink,
            $w->planWhy,
            $w->shared->cappedRuns,
            $w->shared->contentKeys,
            $w->shared->reportedBraceKinds,
        );
    }

    /**
     * Puts the state back; the attempts are copied, as the rounds change them in place.
     */
    public function restore(WriterState $w): void
    {
        $w->lines = $this->lines;
        $w->escapes = $this->escapes;
        $w->escaped = $this->escaped;
        $w->forms = $this->forms;
        $w->glued = $this->glued;
        $w->bracedAnyway = $this->bracedAnyway;
        $w->starts = $this->starts;
        $w->attempts = self::copyAttempts($this->attempts);
        $w->blankFirst = $this->blankFirst;
        $w->lossLog = $this->lossLog;
        $w->lost = $this->lost;
        $w->lostKeys = $this->lostKeys;
        $w->parsed = $this->parsed;
        $w->linkFallback = $this->linkFallback;
        $w->sawLink = $this->sawLink;
        $w->planWhy = $this->planWhy;
        $w->shared->cappedRuns = $this->cappedRuns;
        $w->shared->contentKeys = $this->contentKeys;
        $w->shared->reportedBraceKinds = $this->reportedBraceKinds;
    }

    /**
     * The fields in a fixed order, for a comparison field by field.
     *
     * @return list<mixed>
     */
    public function fields(): array
    {
        return [
            $this->lines,
            $this->escapes,
            $this->escaped,
            $this->forms,
            $this->glued,
            $this->bracedAnyway,
            $this->starts,
            $this->attempts,
            $this->blankFirst,
            $this->lossLog,
            $this->lost,
            $this->lostKeys,
            $this->parsed,
            $this->linkFallback,
            $this->sawLink,
            $this->planWhy,
            $this->cappedRuns,
            $this->contentKeys,
            $this->reportedBraceKinds,
        ];
    }

    /**
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Attempt> $attempts
     *
     * @return array<int, \MarkupCarve\Carve\Writer\Check\Attempt>
     */
    private static function copyAttempts(array $attempts): array
    {
        $copy = [];
        foreach ($attempts as $id => $attempt) {
            $copy[$id] = new Attempt($attempt->step, $attempt->escapes, $attempt->node);
        }

        return $copy;
    }
}
