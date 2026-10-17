<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class AMatchedDescriptionCommentKeepsItsBlockExtentTest extends TestCase
{
    public function testMatchedCommentRetainsItsBodyAndExtent(): void
    {
        foreach ([1, 2] as $indent) {
            $pad = str_repeat(' ', $indent);
            $source = ":: t\n:  x\n{$pad}%%%\ny\n{$pad}%%%\nz\n";
            $ast = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
            $children = self::arrayAt(self::arrayAt(self::arrayAt(self::arrayAt(self::arrayAt($ast, 'children'), 0), 'items'), 1), 'children');
            $comment = self::arrayAt($children, 1);
            $pos = self::arrayAt($comment, 'pos');
            self::assertSame('comment', $comment['type']);
            self::assertSame('y', $comment['content']);
            self::assertTrue($comment['block']);
            self::assertSame('block', $comment['form']);
            self::assertSame(10 + $indent, $pos['startOffset']);
            self::assertSame(19 + 2 * $indent, $pos['endOffset']);
            $formatted = CarveConverter::toCarve($source);
            self::assertSame(":: t\n: x\n\n  %%%\n  y\n  %%%\n\n  z\n", $formatted);
            self::assertSame($formatted, CarveConverter::toCarve($formatted));
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
