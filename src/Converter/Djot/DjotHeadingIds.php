<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;

final class DjotHeadingIds
{
    /**
     * @param string $carve
     * @param array<int, list<int>> $pairedQuotes
     *
     * @return array{list<string>, list<string>} Djot ids and Carve ids in document order
     */
    public static function fromCarve(string $carve, array $pairedQuotes = []): array
    {
        $document = (new BlockParser())->parse(str_replace("\0U\0", "\0", $carve));
        $renderer = new HtmlRenderer();
        $renderer->render($document);
        $tracker = $renderer->getHeadingIdTracker();
        $used = [];
        $djotIds = [];
        $carveIds = [];
        $pending = array_reverse($document->getChildren());
        $headingIndex = 0;
        while ($pending !== []) {
            $node = array_pop($pending);
            $explicit = $node->getAttribute('id');
            if ($explicit !== null && $explicit !== '') {
                $used[$explicit] = true;
            }
            if ($node instanceof Heading) {
                $sourceHeadingIndex = $headingIndex++;
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
                    $removeQuotes = $pairedQuotes[$sourceHeadingIndex] ?? [];
                    if ($removeQuotes !== []) {
                        preg_match_all('/[\x27"‘’“”]/u', $plain, $quotes, PREG_OFFSET_CAPTURE);
                        $quotePositions = $quotes[0];
                        foreach (array_reverse($removeQuotes) as $ordinal) {
                            if (isset($quotePositions[$ordinal])) {
                                [$quote, $offset] = $quotePositions[$ordinal];
                                $plain = substr_replace($plain, '', $offset, strlen($quote));
                            }
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
                    $djotIds[] = $id;
                }
            }
            foreach (array_reverse($node->getChildren()) as $child) {
                $pending[] = $child;
            }
        }

        return [$djotIds, $carveIds];
    }
}
