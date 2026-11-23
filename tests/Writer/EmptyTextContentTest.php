<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-117, C2-W-002: no parse gives an empty text, so an inline node whose content is only empty texts, or spans
 * that hold nothing else, reads back without it and is reported. Outputs and losses are those of carve-js `next2`.
 */
final class EmptyTextContentTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function inlines(): array
    {
        $empty = ['type' => 'text', 'value' => ''];
        $span = static fn (string $type, array ...$children): array => ['type' => $type, 'children' => $children];
        $at = ['structure-unspellable /children/0/children/1'];

        return [
            'emphasis' => [$span('emphasis', $empty), "a{//}b\n", $at],
            'strong' => [$span('strong', $empty), "a{**}b\n", $at],
            'two empty texts' => [$span('emphasis', $empty, $empty), "a{//}b\n", $at],
            'delete' => [$span('delete', $empty), "a{--}b\n", $at],
            'superscript' => [$span('superscript', $empty), "a{^^}b\n", $at],
            'link' => [['type' => 'link', 'href' => '/u', 'children' => [$empty]], "a[](/u)b\n", $at],
            'span' => [['type' => 'span', 'attrs' => ['classes' => ['c']], 'children' => [$empty]], "a[]{.c}b\n", $at],
            'emphasis of an empty strong' => [
                $span('emphasis', $span('strong')),
                "a{//}b\n",
                ['structure-unspellable /children/0/children/1/children/0', 'structure-unspellable /children/0/children/1'],
            ],
            'emphasis of an empty link keeps it' => [
                $span('emphasis', ['type' => 'link', 'href' => '/u', 'children' => []]),
                "a{/[](/u)/}b\n",
                [],
            ],
        ];
    }

    /**
     * @param array<mixed> $inline
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('inlines')]
    public function testContentOfOnlyEmptyTextIsLost(array $inline, string $source, array $losses): void
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $result = Writer::writeBlocksWithDiagnostics([['type' => 'paragraph', 'children' => [$text('a'), $inline, $text('b')]]]);

        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
    }
}
