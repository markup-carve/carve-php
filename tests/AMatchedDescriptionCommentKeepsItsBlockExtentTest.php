<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class AMatchedDescriptionCommentKeepsItsBlockExtentTest extends TestCase
{
    use RuleATodo;

    public function testCommentAtOuterBodyColumnEndsNestedDescription(): void
    {
        $source = "[r][] [^p] [^c] [^d]\n\n:: t\n: :: t\n  : para\n    more\n  %%%\n  c\n  %%%\n    tail\n";
        // The span closes no description, so `tail` stays in the inner one (carve#4862).
        $expected = '<p>[r][] [^p] [^c] [^d]</p><dl><dt>t</dt><dd><dl><dt>t</dt><dd><p>para more</p><p>tail</p></dd></dl></dd></dl>';
        self::assertSame(
            preg_replace('/\s+/', '', $expected),
            preg_replace('/\s+/', '', (new CarveConverter())->convert($source)),
        );
    }

    public function testMatchedCommentRemainsInsideNestedDescription(): void
    {
        $source = ":: t\n: :: t\n  : para\n %%%\n %%%\ntail\n";
        $expected = "<dl>\n<dt>t</dt>\n<dd>\n<dl>\n<dt>t</dt>\n<dd>para</dd>\n</dl>\n</dd>\n</dl>\n<p>tail</p>";
        self::assertSame(
            preg_replace('/\s+/', '', $expected),
            preg_replace('/\s+/', '', (new CarveConverter())->convert($source)),
        );

        $ast = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
        $comment = self::arrayAt(self::arrayAt($ast, 'children'), 1);
        self::assertSame('comment', $comment['type']);
        self::assertTrue($comment['block']);
        self::assertSame('', $comment['content']);
        self::assertSame('block', $comment['form']);
        $pos = self::arrayAt($comment, 'pos');
        self::assertSame(22, $pos['startOffset']);
        self::assertSame(30, $pos['endOffset']);
    }

    public function testMatchedCommentRetainsItsBodyAndExtent(): void
    {
        foreach ([1, 2] as $indent) {
            $pad = str_repeat(' ', $indent);
            $source = ":: t\n:  x\n{$pad}%%%\ny\n{$pad}%%%\nz\n";
            $ast = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
            $comment = self::arrayAt(self::arrayAt($ast, 'children'), 1);
            $pos = self::arrayAt($comment, 'pos');
            self::assertSame('comment', $comment['type']);
            self::assertSame('y', $comment['content']);
            self::assertTrue($comment['block']);
            self::assertSame('block', $comment['form']);
            self::assertSame(10 + $indent, $pos['startOffset']);
            self::assertSame(19 + 2 * $indent, $pos['endOffset']);
            $formatted = CarveConverter::toCarve($source);
            self::assertSame(":: t\n: x\n\n%%%\ny\n%%%\n\nz\n", $formatted);
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
