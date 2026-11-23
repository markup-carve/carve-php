<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6320: a line feed in a supplied inline value is reported at the node that does not read back (C2-W-124).
 */
final class InlineValueLineFeedLossTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function losses(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $braced = static fn (string $value): array => ['type' => 'comment', 'form' => 'braced', 'value' => $value];
        $lineBlock = static fn (array $inlines): array => [
            'type' => 'line_block',
            'children' => [['type' => 'line', 'children' => $inlines]],
        ];
        $paragraph = static fn (array $inlines): array => ['type' => 'paragraph', 'children' => $inlines];

        return [
            'text with a blank line' => [
                $paragraph([$text("a\n\nb")]),
                "a\n\nb\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'text in a line block' => [
                $lineBlock([$text("a\nb")]),
                "::: |\na\nb\n:::\n",
                ['field-unspellable /children/0/children/0/children/0/value'],
            ],
            'braced comment in a line block' => [
                $lineBlock([$text('a'), $braced("c\nd")]),
                "::: |\na{% c\nd %}\n:::\n",
                ['structure-unspellable /children/0/children/0/children/1'],
            ],
            'braced comment closing the line block' => [
                $lineBlock([$text('a'), $braced("c\n:::")]),
                "::: |\na{% c\n::: %}\n:::\n",
                ['structure-unspellable /children/0/children/0/children/1'],
            ],
            'critic comment in a line block' => [
                $lineBlock([$text('a'), ['type' => 'critic_comment', 'value' => "c\nd"]]),
                "::: |\na{#c\nd#}\n:::\n",
                ['structure-unspellable /children/0/children/0/children/1'],
            ],
            'code span in a line block' => [
                $lineBlock([$text('a'), ['type' => 'code', 'value' => "c\nd"]]),
                "::: |\na`c\nd`\n:::\n",
                ['structure-unspellable /children/0/children/0/children/1'],
            ],
            'code span with a space before the line feed in a line block' => [
                $lineBlock([$text('a'), ['type' => 'code', 'value' => "c \nd"]]),
                "::: |\na`c \nd`\n:::\n",
                ['structure-unspellable /children/0/children/0/children/1'],
            ],
            'math in emphasis in a quoted line block' => [
                [
                    'type' => 'block_quote',
                    'children' => [
                        $lineBlock([
                            ['type' => 'emphasis', 'children' => [['type' => 'math', 'display' => false, 'value' => "c\nd"]]],
                        ]),
                    ],
                ],
                "> ::: |\n> /$`c\nd`/\n> :::\n",
                ['structure-unspellable /children/0/children/0/children/0/children/0/children/0'],
            ],
            'braced comment with a space before the line feed' => [
                $paragraph([$text('a'), $braced("c \nd")]),
                "a{% c \nd %}\n",
                ['field-unspellable /children/0/children/1/value'],
            ],
            'critic comment with a tab after the line feed' => [
                $paragraph([$text('a'), ['type' => 'critic_comment', 'value' => "c\n\td"]]),
                "a{#c\n\td#}\n",
                ['field-unspellable /children/0/children/1/value'],
            ],
            'braced comment with two backslashes before the space' => [
                $paragraph([$text('a'), $braced("c\\\\ \nd")]),
                "a{% c\\\\ \nd %}\n",
                ['field-unspellable /children/0/children/1/value'],
            ],
            'braced comment with a CR LF' => [
                $paragraph([$text('a'), $braced("c\r\nd")]),
                "a{% c\r\nd %}\n",
                ['field-unspellable /children/0/children/1/value'],
            ],
            'code span with a CR LF in a heading' => [
                ['type' => 'heading', 'level' => 1, 'children' => [$text('a'), ['type' => 'code', 'value' => "c\r\nd"]]],
                "= a`c\r\n  d`\n",
                ['field-unspellable /children/0/children/1/value'],
            ],
            'line comment in a line block' => [
                $lineBlock([$text('a'), ['type' => 'comment', 'form' => 'line', 'value' => "c\nd"]]),
                "::: |\na\n:::\n",
                ['structure-unspellable /children/0/children/0/children/1'],
            ],
        ];
    }

    /**
     * @param array<mixed> $block
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('losses')]
    public function testLossIsReportedAtTheNode(array $block, string $source, array $losses): void
    {
        $result = Writer::writeBlocksWithDiagnostics([$block]);

        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
        $read = TreeEncoder::decodedForWriter(Carve::parse($result->source));
        $this->assertFalse(TreeEquality::equal([$block], $read['children']));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function keptLineFeeds(): array
    {
        return [
            'plain' => ["c\nd", "a{% c\nd %}\n"],
            'escaped space before the line feed' => ["c\\ \nd", "a{% c\\ \nd %}\n"],
        ];
    }

    /**
     * @param string $value
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('keptLineFeeds')]
    public function testBracedCommentKeepsItsLineFeedInAParagraph(string $value, string $source): void
    {
        $block = [
            'type' => 'paragraph',
            'children' => [
                ['type' => 'text', 'value' => 'a'],
                ['type' => 'comment', 'form' => 'braced', 'value' => $value],
            ],
        ];

        $result = Writer::writeBlocksWithDiagnostics([$block]);

        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->codes());
        $read = TreeEncoder::decodedForWriter(Carve::parse($result->source));
        $this->assertTrue(TreeEquality::equal([$block], $read['children']));
    }
}
