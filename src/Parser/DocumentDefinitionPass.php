<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * @internal
 */
final class DocumentDefinitionPass
{
    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \Closure(array<string>, array<int, true>, bool): array<string> $rebaseItemBlocks
     * @param \Closure(array<string>): array<string> $definitionReach
     * @param \Closure(string, array{lines: array<string>, lineMap: array<int, int>}): void $parseFootnoteBody
     * @param \Closure(\MarkupCarve\Carve\Node\Node): void $resolveReferences
     * @param \Closure(\MarkupCarve\Carve\Node\Document): void $settleCaptions
     * @param \Closure(string): bool $hasFootnote
     */
    public function __construct(
        private readonly BlockParserState $state,
        private readonly Closure $rebaseItemBlocks,
        private readonly Closure $definitionReach,
        private readonly Closure $parseFootnoteBody,
        private readonly Closure $resolveReferences,
        private readonly Closure $settleCaptions,
        private readonly Closure $hasFootnote,
    ) {
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<string> $lines
     */
    public function finish(Document $document, array $lines): void
    {
        // A footnote body may discover another definition. Visit until none remain.
        $finished = [];
        while (true) {
            $found = false;
            foreach ($this->state->session->discoveredFootnoteBodies as $label => $body) {
                if (isset($finished[$label])) {
                    continue;
                }
                $finished[$label] = true;
                $found = true;
                $collectedLines = $body['lines'];
                $plainBody = count($collectedLines) === 1 ? $collectedLines[0] : '';
                if (
                    $plainBody === ''
                    || !str_contains('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $plainBody[0])
                    || strspn($plainBody, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 .') !== strlen($plainBody)
                ) {
                    $body['lines'] = ($this->definitionReach)(
                        ($this->rebaseItemBlocks)(
                            $body['lines'],
                            array_fill_keys(array_keys($body['lines']), true),
                            true,
                        ),
                    );
                }
                foreach ($body['lines'] as $bodyIndex => $entry) {
                    if (
                        preg_match('/^%{3,}$/', $entry) === 1
                        && isset($collectedLines[$bodyIndex])
                        && IndentationHelper::getLeadingColumns($collectedLines[$bodyIndex]) > 0
                    ) {
                        $body['lines'][$bodyIndex] = ' ' . $entry;
                    }
                }
                ($this->parseFootnoteBody)((string)$label, $body);
            }
            if (!$found) {
                break;
            }
        }

        if ($this->state->session->discoveredAbbreviationLines !== []) {
            $firstAbbreviationLine = min(array_keys($this->state->session->discoveredAbbreviationLines));
            $firstBodyLine = null;
            foreach ($lines as $lineNumber => $line) {
                if (IndentationHelper::isBlankLine($line) || isset($this->state->session->discoveredAbbreviationLines[$lineNumber])) {
                    continue;
                }
                $firstBodyLine = $lineNumber;

                break;
            }
            $this->state->session->abbreviationsBeforeBody = $firstBodyLine === null || $firstAbbreviationLine < $firstBodyLine;
        }

        ($this->resolveReferences)($document);
        foreach ($this->state->session->footnotes as $footnote) {
            ($this->resolveReferences)($footnote);
        }
        ($this->settleCaptions)($document);
        $this->state->session->warnings = array_values(array_filter(
            $this->state->session->warnings,
            function (ParseWarning $warning): bool {
                if (
                    preg_match("/^Undefined footnote '(.+)'$/", $warning->getMessage(), $match) === 1
                    && ($this->hasFootnote)($match[1])
                ) {
                    return false;
                }
                if (
                    preg_match("/^Undefined reference '(.+)'$/", $warning->getMessage(), $match) === 1
                    && isset($this->state->session->references[$match[1]])
                ) {
                    return false;
                }

                return true;
            },
        ));
    }
}
