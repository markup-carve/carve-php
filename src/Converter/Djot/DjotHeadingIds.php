<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;

final class DjotHeadingIds
{
    /**
     * @param string $carve
     * @param array<int, list<string>> $reservedBeforeHeading
     * @param bool $pinnableOnly
     * @param list<array{line: int, note: string}> $sourceHeadingOrder
     * @param array<int, array{remove: list<int>, quotes: list<string>}> $pairedQuotes
     *
     * @return array{list<string>, list<string>} Djot ids and Carve ids in document order
     */
    public static function fromCarve(
        string $carve,
        array $reservedBeforeHeading = [],
        bool $pinnableOnly = false,
        array $sourceHeadingOrder = [],
        array $pairedQuotes = [],
    ): array {
        $lines = explode("\n", $carve);
        $document = (new BlockParser(trackPositions: true))->parse(str_replace("\0U\0", "\0", $carve));
        $renderer = new HtmlRenderer();
        $renderer->render($document);
        $tracker = $renderer->getHeadingIdTracker();
        $used = [];
        $djotIds = [];
        $carveIds = [];
        $pending = array_map(static fn ($node): array => [$node, ''], array_reverse($document->getChildren()));
        $nodes = [];
        while ($pending !== []) {
            [$node, $note] = array_pop($pending);
            $nodes[] = ['node' => $node, 'order' => count($nodes), 'note' => $note];
            foreach (array_reverse($node->getChildren()) as $child) {
                $pending[] = [$child, $node instanceof Footnote ? $node->getLabel() : $note];
            }
        }
        $headingOrder = [];
        $groups = [];
        $headingsInCarveOrder = [];
        foreach ($nodes as $index => $entry) {
            if ($entry['node'] instanceof Heading) {
                $groups[$entry['note']][] = $index;
                $headingsInCarveOrder[] = $entry['node'];
            }
        }
        usort($headingsInCarveOrder, static function (Heading $a, Heading $b): int {
            $left = $a->getPos();
            $right = $b->getPos();
            $leftLine = $left === null ? PHP_INT_MAX : $left->startLine;
            $rightLine = $right === null ? PHP_INT_MAX : $right->startLine;
            $leftColumn = $left === null ? PHP_INT_MAX : $left->startColumn;
            $rightColumn = $right === null ? PHP_INT_MAX : $right->startColumn;

            return $leftLine <=> $rightLine ?: $leftColumn <=> $rightColumn;
        });
        foreach ($headingsInCarveOrder as $order => $heading) {
            $headingOrder[spl_object_id($heading)] = $order;
        }
        if ($sourceHeadingOrder !== []) {
            $sourceCounts = [];
            foreach ($sourceHeadingOrder as $heading) {
                $sourceCounts[$heading['note']] = ($sourceCounts[$heading['note']] ?? 0) + 1;
            }
            foreach ($groups as $note => $indexes) {
                if (count($indexes) !== ($sourceCounts[$note] ?? 0)) {
                    $sourceHeadingOrder = [];
                    $reservedBeforeHeading = [];

                    break;
                }
            }
            if (count($sourceHeadingOrder) !== count($headingsInCarveOrder)) {
                $sourceHeadingOrder = [];
                $reservedBeforeHeading = [];
            }
        }
        $sourceLines = [];
        if ($sourceHeadingOrder !== []) {
            $next = [];
            foreach ($sourceHeadingOrder as $heading) {
                $note = $heading['note'];
                $index = $groups[$note][$next[$note] ?? 0];
                $sourceLines[$index] = $heading['line'];
                $next[$note] = ($next[$note] ?? 0) + 1;
            }
        }
        usort($nodes, static function (array $a, array $b) use ($sourceLines): int {
            $left = $a['node']->getPos();
            $right = $b['node']->getPos();
            if (isset($sourceLines[$a['order']]) || isset($sourceLines[$b['order']])) {
                return ($sourceLines[$a['order']] ?? PHP_INT_MAX) <=> ($sourceLines[$b['order']] ?? PHP_INT_MAX)
                    ?: $a['order'] <=> $b['order'];
            }

            $leftLine = $left === null ? PHP_INT_MAX : $left->startLine;
            $rightLine = $right === null ? PHP_INT_MAX : $right->startLine;
            $leftColumn = $left === null ? PHP_INT_MAX : $left->startColumn;
            $rightColumn = $right === null ? PHP_INT_MAX : $right->startColumn;

            return $leftLine <=> $rightLine
                ?: $leftColumn <=> $rightColumn
                ?: $a['order'] <=> $b['order'];
        });
        $headingIndex = 0;
        $unpinable = [];
        foreach ($nodes as $entry) {
            $node = $entry['node'];
            $explicit = $node->getAttribute('id');
            if ($explicit !== null && $explicit !== '' && !($reservedBeforeHeading !== [] && $sourceHeadingOrder !== [] && !$node instanceof Heading)) {
                $used[$explicit] = true;
            }
            if ($node instanceof Heading) {
                $sourceHeadingIndex = $headingIndex++;
                foreach ($reservedBeforeHeading[$sourceHeadingIndex] ?? [] as $reservedId) {
                    $used[$reservedId] = true;
                }
                $order = $headingOrder[spl_object_id($node)];
                $position = $node->getPos();
                $line = $position === null ? '' : ($lines[$position->startLine - 1] ?? '');
                if ($pinnableOnly && preg_match('/^(?:[ \t]*>[ \t]?)*[ \t]*\[\^[^\]\n]+\]:[ \t]*#{1,6}[ \t]/', $line) === 1) {
                    $unpinable[] = $order;
                }
                $carveIds[$order] = $tracker->getIdForHeading($node);
                $descendants = $reservedBeforeHeading === [] ? array_reverse($node->getChildren()) : [];
                while ($descendants !== []) {
                    $child = array_pop($descendants);
                    $childId = $child->getAttribute('id');
                    if ($childId !== null && $childId !== '') {
                        $used[$childId] = true;
                    }
                    foreach (array_reverse($child->getChildren()) as $descendant) {
                        $descendants[] = $descendant;
                    }
                }
                if ($explicit !== null && $explicit !== '') {
                    $djotIds[$order] = $explicit;
                } else {
                    $plain = $tracker->getPlainText($node, SmartTypographyMode::Source);
                    $quoteRule = $pairedQuotes[$sourceHeadingIndex] ?? ['remove' => [], 'quotes' => []];
                    preg_match_all('/[\x27"‘’“”]/u', $plain, $quotes, PREG_OFFSET_CAPTURE);
                    $quotePositions = $quotes[0];
                    if (count($quotePositions) === count($quoteRule['quotes'])) {
                        foreach (array_reverse(array_keys($quotePositions)) as $ordinal) {
                            [$quote, $offset] = $quotePositions[$ordinal];
                            $replacement = in_array($ordinal, $quoteRule['remove'], true)
                                ? '' : $quoteRule['quotes'][$ordinal];
                            $plain = substr_replace($plain, $replacement, $offset, strlen($quote));
                        }
                    }
                    $punctuation = '][~!@#$%^&*(){}`,.<>\\|=+/?';
                    $base = trim(preg_replace('/\s+/u', ' ', strtr($plain, array_fill_keys(str_split($punctuation), ' '))) ?? $plain);
                    $base = str_replace(' ', '-', $base);
                    $id = $base;
                    $suffix = 0;
                    while ($id === '' || isset($used[$id])) {
                        $id = ($base === '' ? 's' : $base) . '-' . ++$suffix;
                    }
                    $used[$id] = true;
                    $djotIds[$order] = $id;
                }
            }
        }

        ksort($djotIds);
        ksort($carveIds);
        foreach ($unpinable as $index) {
            unset($djotIds[$index], $carveIds[$index]);
        }

        return [array_values($djotIds), array_values($carveIds)];
    }
}
