<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-W-002: a backtick run left bare in one text can close a run in a text of another inline node, so a
 * text whose runs are planned alone escapes every run when another node of the content holds a backtick.
 */
final class TickRunsAcrossSpansTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function paragraphs(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $span = static fn (string $type, string $value): array => ['type' => $type, 'children' => [$text($value)]];

        return [
            'emphasis before two strong spans' => [
                [$span('emphasis', 'a{'), $span('strong', ' `x`'), $span('strong', 'a `x`')],
                "/a\\{/{* \\`x\\`*}*a \\`x\\`*\n",
            ],
            'with tabs' => [
                [$span('emphasis', "a\t{"), $span('strong', ' `x`'), $span('strong', "a\t `x`")],
                "/a\t\\{/{* \\`x\\`*}*a\t \\`x\\`*\n",
            ],
            'one strong' => [[$span('emphasis', 'a{'), $span('strong', ' `x`')], "/a\\{/{* \\`x\\`*}\n"],
            'a lone text keeps its spelling' => [[$text('a `x` b `y`')], "a \\`x\\` b \\`y\\`\n"],
        ];
    }

    /**
     * @param array<mixed> $inlines
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('paragraphs')]
    public function testRunsInOtherNodesDoNotPair(array $inlines, string $source): void
    {
        $result = Writer::writeBlocksWithDiagnostics([['type' => 'paragraph', 'children' => $inlines]]);

        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->diagnostics);
    }
}
