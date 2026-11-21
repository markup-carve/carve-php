<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\EditorSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The syntax tokens of an editor session snapshot follow carve-js (carve#6342). Offsets are bytes.
 */
final class EditorTokensTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function cases(): array
    {
        return [
            'empty link label' => ['[](url)', ['open-marker 0-1', 'close-marker 1-3', 'destination 3-6', 'close-marker 6-7']],
            'code link label' => [
                '[`x`](url)', ['open-marker 0-1', 'close-marker 4-6', 'destination 6-9', 'close-marker 9-10'],
            ],
            'smart punctuation link label' => [
                '[---](url)', ['open-marker 0-1', 'close-marker 4-6', 'destination 6-9', 'close-marker 9-10'],
            ],
            'link attribute' => [
                '[x](url){.foo}',
                ['open-marker 0-1', 'close-marker 2-4', 'destination 4-7', 'close-marker 7-8', 'attribute 8-14'],
            ],
            'nested label' => ['[*a*](u)', ['open-marker 0-1', 'close-marker 4-6', 'destination 6-7', 'close-marker 7-8']],
            'title parenthesis and attribute' => [
                '[x](u "a)b"){.c}',
                ['open-marker 0-1', 'close-marker 2-4', 'destination 4-5', 'close-marker 11-12', 'attribute 12-16'],
            ],
            'reference attribute' => ['[x][r]{.a}', ['open-marker 0-1', 'close-marker 2-6', 'attribute 6-10']],
            'escaped reference bracket' => ['[x][a\\]b]{.a}', ['open-marker 0-1', 'close-marker 2-9', 'attribute 9-13']],
            'link with a title' => [
                "[x](url \"title\")\n",
                ['open-marker 0-1', 'close-marker 2-4', 'destination 4-7', 'close-marker 15-16'],
            ],
            'link with parentheses in the destination' => [
                "[x](a(b))\n",
                ['open-marker 0-1', 'close-marker 2-4', 'destination 4-8', 'close-marker 8-9'],
            ],
            'escaped pipe in a cell' => [
                "| a \\| b | c |\n",
                ['table-marker 0-1', 'table-marker 9-10', 'table-marker 13-14'],
            ],
            'fence closer on its own line' => [
                "```\na\n```\n",
                ['fence-open 0-4', 'fence-close 6-9'],
            ],
            'equals heading' => [
                "= H\n",
                ['block-marker 0-2'],
            ],
            'attribute line above a paragraph' => [
                "{.k}\npara\n",
                ['attribute 0-4'],
            ],
            'attribute line above a heading' => [
                "{.k}\n= H\n",
                ['attribute 0-4', 'block-marker 5-7'],
            ],
            'attribute line above a fenced block' => [
                "{.k}\n```\na\n```\n",
                ['attribute 0-4', 'fence-open 5-9', 'fence-close 11-14'],
            ],
            'list item' => [
                "- item\n",
                ['block-marker 0-2'],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testTokens(string $source, array $expected): void
    {
        $found = [];
        foreach ((new EditorSession($source))->snapshot()['nodes'] as $node) {
            foreach ($node['tokens'] as $token) {
                $found[] = $token['role'] . ' ' . $token['startByte'] . '-' . $token['endByte'];
            }
        }

        $this->assertSame($expected, $found);
    }
}
