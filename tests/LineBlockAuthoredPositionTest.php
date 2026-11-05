<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class LineBlockAuthoredPositionTest extends TestCase
{
    public function testBreakAfterHiddenPayloadHasOrderedAuthoredSpan(): void
    {
        $breaks = self::nodes("- - ::: |\n    %% c\nb\n", 'hard_break');
        self::assertCount(1, $breaks);
        self::assertIsArray($breaks[0]['pos'] ?? null);
        self::assertSame(18, $breaks[0]['pos']['startOffset'] ?? null);
        self::assertSame(19, $breaks[0]['pos']['endOffset'] ?? null);
        self::assertSame(2, $breaks[0]['pos']['startLine'] ?? null);
        self::assertSame(3, $breaks[0]['pos']['endLine'] ?? null);
    }

    public function testDefinitionShapedPayloadMapsClosingQuoteToItsOwnLine(): void
    {
        $punctuation = self::nodes("- :: u\n  :  ::: |\n       [x]: /u \"t\"\nb\n", 'smart_punctuation');
        self::assertCount(2, $punctuation);
        self::assertIsArray($punctuation[1]['pos'] ?? null);
        self::assertSame(35, $punctuation[1]['pos']['startOffset'] ?? null);
        self::assertSame(36, $punctuation[1]['pos']['endOffset'] ?? null);
        self::assertSame(3, $punctuation[1]['pos']['startLine'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function nodes(string $source, string $type): array
    {
        $parser = new BlockParser();
        $parser->enablePositionTracking();
        $ast = (new AstCodec())->encode($parser->parse($source));
        $found = [];
        $walk = static function (mixed $node) use (&$walk, &$found, $type): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === $type) {
                $found[] = $node;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($ast);

        return $found;
    }
}
