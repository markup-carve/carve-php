<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Corpus;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5680 (ruled), C2-W-088, C2-I-017: a backtick run of text takes a backslash only where it would pair with a
 * run of its length behind it in the leaf or open a fence at a line start.
 */
final class LoneTickRunTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function paragraphs(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $soft = ['type' => 'soft_break'];

        return [
            'a run on a later line' => [[$text('a'), $soft, $text('- ```'), $soft, $text('c')], "a\n- ```\nc\n"],
            'a run on one line' => [[$text('x ```')], "x ```\n"],
            'a fence on a later line' => [[$text('a'), $soft, $text('```'), $soft, $text('c')], "a\n\\```\nc\n"],
            'a run that pairs with one in emphasis' => [
                [$text('a `'), ['type' => 'emphasis', 'children' => [$text('b `')]]],
                "a \\`/b \\`/\n",
            ],
            'a run beside one of another length in strong' => [
                [$text('x`'), ['type' => 'strong', 'children' => [$text('b ```')]]],
                "x`*b ```*\n",
            ],
            'a run glued before a code span' => [[$text('a `'), ['type' => 'code', 'value' => 'x']], "a \\``x`\n"],
            'a run of another length before a code span' => [[$text('a `` b '), ['type' => 'code', 'value' => 'x']], "a `` b `x`\n"],
        ];
    }

    /**
     * @param array<mixed> $inlines
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('paragraphs')]
    public function testRunTakesOnlyTheBackslashesItNeeds(array $inlines, string $source): void
    {
        $result = Writer::writeBlocksWithDiagnostics([['type' => 'paragraph', 'children' => $inlines]]);

        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->diagnostics);
    }

    /**
     * C2-W-092: behind the backslash of a fence at a line start the rest of the run is one shorter, and here pairs.
     */
    public function testRestOfAnEscapedFenceRunReadsBack(): void
    {
        $children = [
            [

                'type' => 'paragraph',
                'children' => [
                    ['type' => 'text', 'value' => '```js'], ['type' => 'soft_break'], ['type' => 'text', 'value' => 'q ``'],
                ],
            ],
        ];
        $result = Writer::writeBlocksWithDiagnostics($children);
        $tree = json_decode(Carve::toJson($result->source), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($tree);
        $this->assertSame([], $result->diagnostics);
        $this->assertEquals(Corpus::withoutPositions($children), Corpus::withoutPositions($tree['children']));
    }
}
