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
     *
     * @return array{list<string>, list<string>} Djot ids and Carve ids in document order
     */
    public static function fromCarve(string $carve, array $reservedBeforeHeading = [], bool $pinnableOnly = false): array
    {
        $lines = explode("\n", $carve);
        $document = (new BlockParser(trackPositions: true))->parse(str_replace("\0U\0", "\0", $carve));
        $renderer = new HtmlRenderer();
        $renderer->render($document);
        $tracker = $renderer->getHeadingIdTracker();
        $used = [];
        $djotIds = [];
        $carveIds = [];
        $pending = array_map(static fn ($node): array => [$node, false], array_reverse($document->getChildren()));
        $nodes = [];
        while ($pending !== []) {
            [$node, $inFootnote] = array_pop($pending);
            $nodes[] = ['node' => $node, 'order' => count($nodes), 'inFootnote' => $inFootnote];
            foreach (array_reverse($node->getChildren()) as $child) {
                $pending[] = [$child, $inFootnote || $node instanceof Footnote];
            }
        }
        usort($nodes, static function (array $a, array $b): int {
            $left = $a['node']->getPos();
            $right = $b['node']->getPos();

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
            if ($explicit !== null && $explicit !== '' && !($reservedBeforeHeading !== [] && $entry['inFootnote'])) {
                $used[$explicit] = true;
            }
            if ($node instanceof Heading) {
                foreach ($reservedBeforeHeading[$headingIndex++] ?? [] as $reservedId) {
                    $used[$reservedId] = true;
                }
                $position = $node->getPos();
                $line = $position === null ? '' : ($lines[$position->startLine - 1] ?? '');
                if ($pinnableOnly && preg_match('/^\[\^[^\]\n]+\]:[ \t]*#{1,6}[ \t]/', $line) === 1) {
                    $unpinable[] = count($carveIds);
                }
                $carveIds[] = $tracker->getIdForHeading($node);
                $descendants = array_reverse($node->getChildren());
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
                    $djotIds[] = $explicit;
                } else {
                    $plain = $tracker->getPlainText($node, SmartTypographyMode::Source);
                    $punctuation = '][~!@#$%^&*(){}`,.<>\\|=+/?';
                    $base = trim(preg_replace('/\s+/u', ' ', strtr($plain, array_fill_keys(str_split($punctuation), ' '))) ?? $plain);
                    $base = str_replace(' ', '-', $base);
                    $id = $base;
                    $suffix = 0;
                    while ($id === '' || isset($used[$id])) {
                        $id = ($base === '' ? 's' : $base) . '-' . ++$suffix;
                    }
                    $used[$id] = true;
                    $djotIds[] = $id;
                }
            }
        }

        foreach ($unpinable as $index) {
            unset($djotIds[$index], $carveIds[$index]);
        }

        return [array_values($djotIds), array_values($carveIds)];
    }
}
