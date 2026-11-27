<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-I-047: the backslash of a written hard break is no whitespace, so a `/` or `*` before it can open.
 * Below a hard-break opener the break is the bare line ending (C2-W-080), a line edge. carve-php#6103, as carve-js
 * and carve-rs write it.
 */
final class DelimiterBeforeHardBreakTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'slash' => ["x(/u)\\/\\\n", "x(\\/u)\\/\\\n"],
            'star' => ["x(*u)\\*\\\nfoo", "x(\\*u)\\*\\\nfoo\n"],
            'slash in a list in a quote' => ["> - x(/u)\\/\\\n>   y", "> - x(\\/u)\\/\\\n>   y\n"],
            'star in the class form of hard breaks' => [
                "{.hardbreaks}\n:::\nx(*u)\\*\\\nfoo\n:::\n",
                "::: hardbreaks\nx(\\*u)\\*\\\nfoo\n:::\n",
            ],
            'slash below a hard-break opener' => ["::: \\\nx(/u)\\/\nfoo\n:::\n", "::: \\\nx(\\/u)/\nfoo\n:::\n"],
            'slash after a word character cannot open' => ["x \\/u\\/\\\nb\n", "x \\/u/\\\nb\n"],
            'star after a word character cannot open' => ["x \\*u\\*\\\nb\n", "x \\*u*\\\nb\n"],
            'emphasis holding a slash before the break' => ["x(/u) \\/\\\nfoo/\n", "x({/u) /\\\nfoo/}\n"],
            'strong holding a star before the break, in a quote' => ["> a *b \\*\\\n> foo*\n", "> a {*b *\\\n> foo*}\n"],
            'emphasis below a hard-break opener stays bare' => [
                "::: \\\na /b \\/\nfoo/\n:::\n",
                "::: \\\na /b /\nfoo/\n:::\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testADelimiterBeforeAHardBreak(string $source, string $expected): void
    {
        $written = Carve::format($source);
        self::assertSame($expected, $written);
        self::assertSame(self::tree($source), self::tree($written));
        self::assertSame($written, Carve::format($written));
    }

    /**
     * @return array<mixed>
     */
    private static function tree(string $source): array
    {
        $tree = json_decode(Carve::toJson($source), true);
        self::assertIsArray($tree);

        return self::stripPositions($tree);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function stripPositions(array $node): array
    {
        unset($node['pos'], $node['srcByteLength']);
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::stripPositions($value);
            }
        }

        return $node;
    }
}
