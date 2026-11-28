<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * The choices of a group a pass can take back (carve-js `SavedGroup`); the losses are a mark, not a copy.
 */
final class GroupSnapshot
{
    /**
     * @param list<string> $lines
     * @param array<int, list<int>> $escapes
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Node> $escaped
     * @param array<int, int> $forms
     * @param array<int, true> $glued
     * @param array<int, true> $bracedAnyway
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Attempt> $attempts
     * @param bool $blankFirst
     * @param int $losses
     * @param \MarkupCarve\Carve\Writer\Check\Node|null $parsed
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $escapes,
        public readonly array $escaped,
        public readonly array $forms,
        public readonly array $glued,
        public readonly array $bracedAnyway,
        public readonly array $attempts,
        public readonly bool $blankFirst,
        public readonly int $losses,
        public readonly ?Node $parsed,
    ) {
    }
}
