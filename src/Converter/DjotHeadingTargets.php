<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;

final class DjotHeadingTargets
{
    /**
     * @return array<string, string>
     */
    public static function fromCarve(string $carve): array
    {
        $parser = new BlockParser();
        $document = $parser->parse(str_replace("\0U\0", "\0", $carve));
        $renderer = new HtmlRenderer();
        $renderer->render($document);
        $tracker = $renderer->getHeadingIdTracker();
        $actual = self::headings($document);
        $occupied = array_fill_keys(mb_str_split($carve), true);
        foreach ($actual as $heading) {
            foreach (mb_str_split($tracker->getPlainText($heading, SmartTypographyMode::Source)) as $character) {
                $occupied[$character] = true;
            }
        }
        $marker = null;
        foreach ([[0xE000, 0xF8FF], [0xF0000, 0xFFFFD], [0x100000, 0x10FFFD]] as [$first, $last]) {
            for ($point = $first; $point <= $last; $point++) {
                $candidate = mb_chr($point);
                if (!isset($occupied[$candidate])) {
                    $marker = $candidate;

                    break 2;
                }
            }
        }
        if ($marker === null) {
            return [];
        }
        $marked = self::headings($parser->parse(str_replace(["\0U\0", "\0"], $marker, $carve)));
        if (count($marked) !== count($actual)) {
            return [];
        }
        $targets = [];
        foreach ($marked as $index => $heading) {
            $text = str_replace($marker, "\0", $tracker->getPlainText($heading, SmartTypographyMode::Source));
            $key = preg_replace('/[ \t\r\n]+/', ' ', trim($text, " \t\r\n")) ?? $text;
            $targets[$key] ??= '#' . $tracker->getIdForHeading($actual[$index]);
        }

        return $targets;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Block\Heading>
     */
    private static function headings(Node $document): array
    {
        $found = [];
        $pending = array_reverse($document->getChildren());
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof Heading) {
                $found[] = $node;
            }
            foreach (array_reverse($node->getChildren()) as $child) {
                $pending[] = $child;
            }
        }

        return $found;
    }
}
