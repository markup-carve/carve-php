<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A closed `%%%` pair inside a line block hides its body and leaves no verse
 * line (CARVE-P9-047, carve#4469).
 */
class ACommentBlockInALineBlockLeavesNothingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'a pair alone' => [
                "::: |\n%%%\nsecret\n%%%\n:::\n",
                "<div class=\"line-block\">\n\n</div>",
            ],
            'a blank in the body splits no stanza' => [
                "::: |\na\n%%%\none\n\ntwo\n%%%\nc\n:::\n",
                "<div class=\"line-block\">\n  <p>a<br>\nc</p>\n</div>",
            ],
            'an unclosed opener stays a line comment' => [
                "::: |\na\n%%%\nc\n:::\n",
                "<div class=\"line-block\">\n  <p>a<br>\n<br>\nc</p>\n</div>",
            ],
            'a pair before the first line and an indented closer' => [
                "::: |\n%%% note\nx\n  %%%\na\n:::\n",
                "<div class=\"line-block\">\n  <p>a</p>\n</div>",
            ],
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testHtml(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testThePairSitsAfterItsLineBeforeTheBreak(): void
    {
        $document = (new BlockParser(false, false, false, true))->parse("::: |\na\n%%%\none\n\ntwo\n%%%\nc\n:::\n");
        $children = self::at((new AstCodec())->encode($document), 'children', 0, 'children', 0, 'children');

        $this->assertSame(['text', 'comment', 'hard_break', 'text'], array_column($children, 'type'));
        $this->assertSame("one\n\ntwo", self::at($children, 1)['content']);
        $this->assertSame('block', self::at($children, 1)['form']);
        $this->assertSame(['startLine' => 7, 'endLine' => 8, 'startColumn' => 4, 'endColumn' => 1, 'startOffset' => 24, 'endOffset' => 25], self::at($children, 2, 'pos'));
    }

    /**
     * @param array<mixed> $tree
     * @param string|int ...$path
     *
     * @return array<mixed>
     */
    private static function at(array $tree, string|int ...$path): array
    {
        foreach ($path as $key) {
            $tree = $tree[$key] ?? null;
            self::assertIsArray($tree);
        }

        return $tree;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'a pair alone' => ["::: |\n%%%\nsecret\n%%%\n:::\n"],
            'a pair between two lines' => ["::: |\na\n%%%\none\n\ntwo\n%%%\nc\n:::\n"],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testTheWriterKeepsThePairOnLinesOfItsOwn(string $source): void
    {
        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    public function testAPairNextToAnUnpairedRunTakesALongerFence(): void
    {
        $written = CarveConverter::toCarve("::: |\n%%%%\nx\n%%%%\n%%%x\n:::\n");
        $reparsed = (new AstCodec())->encode((new BlockParser())->parse($written));
        $original = (new AstCodec())->encode((new BlockParser())->parse("::: |\n%%%%\nx\n%%%%\n%%%x\n:::\n"));

        $this->assertSame($original, $reparsed);
    }
}
