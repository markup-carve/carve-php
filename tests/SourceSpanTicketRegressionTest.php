<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class SourceSpanTicketRegressionTest extends TestCase
{
    public function testIndentedFencedCommentStartsAtItsOpener(): void
    {
        $ast = self::parse("::: |\n  %%%\n  secret\n  %%%\n:::\n");
        self::assertSame([8, 26], self::offsets($ast['children'][0]['children'][0]));
    }

    public function testHoistedAbbreviationStartsAtItsOpener(): void
    {
        $ast = self::parse("- a\n  *[HTML]: Hyper Text\n\nThe HTML spec.\n");
        self::assertSame([6, 25], self::offsets($ast['children'][1]));
    }

    public function testSecondStandaloneContinuationDoesNotExtendPriorItem(): void
    {
        $ast = self::parse("- q\n+\n+\n\n- r\n");
        self::assertSame([0, 5], self::offsets($ast['children'][0]['items'][0]));
    }

    public function testSameLineNestedItemStartsAtItsOwnMarker(): void
    {
        $ast = self::parse("* * +\n x\n");
        self::assertSame([2, 5], self::offsets($ast['children'][0]['items'][0]['children'][0]['items'][0]));
    }

    /**
     * Parse with source positions for the AST assertions.
     *
     * @return array<string, mixed>
     */
    private static function parse(string $source): array
    {
        return (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
    }

    /**
     * Read the two source offsets from a placed node.
     *
     * @param array<string, mixed> $node
     *
     * @return array{int, int}
     */
    private static function offsets(array $node): array
    {
        self::assertIsArray($node['pos'] ?? null);

        return [$node['pos']['startOffset'], $node['pos']['endOffset']];
    }
}
