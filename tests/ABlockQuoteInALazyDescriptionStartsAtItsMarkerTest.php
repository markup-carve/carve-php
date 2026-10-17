<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class ABlockQuoteInALazyDescriptionStartsAtItsMarkerTest extends TestCase
{
    public function testQuoteSpanStartsAtItsOwnMarker(): void
    {
        foreach ([":: t\n: > para\n # H\n", ":: t\n: > para\n ^ cap\n"] as $source) {
            $ast = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
            $quote = self::arrayAt(self::arrayAt(self::arrayAt(self::arrayAt(self::arrayAt($ast, 'children'), 0), 'items'), 1), 'children');
            $quote = self::arrayAt($quote, 0);
            $pos = self::arrayAt($quote, 'pos');
            self::assertSame('block_quote', $quote['type']);
            self::assertSame(7, $pos['startOffset']);
            self::assertSame(3, $pos['startColumn']);
        }
    }

    /**
     * @return array<mixed>
     */
    private static function arrayAt(mixed $source, string|int $key): array
    {
        self::assertIsArray($source);
        $value = $source[$key] ?? null;
        self::assertIsArray($value);

        return $value;
    }
}
