<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088: a reported code span escapes the first backtick of every run of its block, over the texts of the block;
 * a block opener behind the marker of the line's block takes its own escape; the line test reads the whole line.
 */
final class BacktickKindRoundsTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function paragraphs(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $break = ['type' => 'soft_break'];
        $span = static fn (string $type, string $value): array => ['type' => $type, 'children' => [$text($value)]];

        return [
            'every run of one text' => [[$text('a `b` c')], "a \\`b\\` c\n"],
            'a rest that still pairs' => [[$text('a `b`` c `d`')], "a \\`b\\`\\` c \\`d\\`\n"],
            'longer runs' => [[$text('``a` `b``')], "\\`\\`a\\` \\`b\\``\n"],
            'runs of a text and a strong span' => [[$text('```js '), $span('strong', 'b ```js')], "\\```js *b \\```js*\n"],
            'a fence line and a later run' => [[$text('```js'), $break, $text('q ``')], "\\`\\``js\nq \\``\n"],
            'a fence line whose rest pairs with nothing' => [[$text('````'), $break, $text('q ````')], "\\````\nq ````\n"],
            'a fence behind a list marker' => [[$text('- ```x'), $break, $text('c')], "\\- \\```x\nc\n"],
            'a fence behind an ordered marker' => [[$text('1. ```'), $break, $text('q ```')], "1\\. \\```\nq ```\n"],
            'a list behind a quote marker' => [[$text('> - x'), $break, $text('c')], "\\> \\- x\nc\n"],
            'a run a code span keeps from a fence' => [
                [$text('a'), $break, $text('``` '), ['type' => 'code', 'value' => 'z']],
                "a\n``` `z`\n",
            ],
            'a pipe line into emphasis' => [[$text('a'), $break, $text('| ```'), $span('emphasis', 'b')], "a\n| ```/b/\n"],
            'a pipe line closed behind emphasis' => [
                [$text('a'), $break, $text('| '), $span('emphasis', 'b'), $text(' |')],
                "a\n\\| /b/ |\n",
            ],
        ];
    }

    /**
     * @param array<mixed> $inlines
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('paragraphs')]
    public function testWritesAndReadsBack(array $inlines, string $source): void
    {
        $tree = [['type' => 'paragraph', 'children' => $inlines]];
        $result = Writer::writeBlocksWithDiagnostics($tree);

        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->diagnostics);
        $read = json_decode(Carve::toJson($source), true);
        $this->assertIsArray($read);
        $this->assertSame(self::strip($tree), self::strip($read['children']));
    }

    /**
     * @param mixed $node
     *
     * @return mixed
     */
    private static function strip(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        unset($node['pos']);
        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = self::strip($value);
        }
        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
