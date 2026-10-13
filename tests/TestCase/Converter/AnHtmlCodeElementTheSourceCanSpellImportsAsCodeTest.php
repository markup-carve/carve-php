<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown target writes an empty code span as `<code></code>` and a line
 * break in one as `<!---->&#10;<!---->` (CARVE-P11-064). The import reads both
 * back as code wherever Carve can spell the value at that position.
 */
class AnHtmlCodeElementTheSourceCanSpellImportsAsCodeTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{string, string}>}>
     */
    public static function provideSpellable(): array
    {
        return [
            'empty value ending its paragraph' => [
                "x{\\*`a*} and `b<code></code>\n",
                [['code', 'a*} and '], ['code', '']],
            ],
            'line break between text' => [
                "p <code>a<!---->&#10;<!---->b</code> q\n",
                [['code', "a\nb"]],
            ],
            'line break on a continuation line' => [
                "Text\n<code><!---->&#10;<!---->code</code>\n",
                [['code', "\ncode"]],
            ],
            'line break and an entity' => [
                "a <code>b<!---->&#10;<!---->c&#32;d</code>\n",
                [['code', "b\nc d"]],
            ],
            'line break mid paragraph' => [
                "a\nb <code>c<!---->&#10;<!---->d</code>\ne\n",
                [['code', "c\nd"]],
            ],
        ];
    }

    /**
     * @param string $markdown
     * @param array<array{string, string}> $expected
     */
    #[DataProvider('provideSpellable')]
    public function testImportsAsCode(string $markdown, array $expected): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);

        $this->assertSame($expected, $this->codes($result->value));
        $this->assertSame(0, $this->fallbacks($markdown));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRoundTrips(): array
    {
        return [
            'empty' => ["x{*`a*} and `b`\n"],
            'line break' => ["p `a\nb` q\n"],
            'unclosed run' => ["Text\n```\ncode\n"],
        ];
    }

    #[DataProvider('provideRoundTrips')]
    public function testRoundTripsThroughTheMarkdownTarget(string $source): void
    {
        $document = (new CarveConverter())->parse($source);
        $markdown = (new MarkdownRenderer())->setRoundTripMode(true)->render($document);
        $back = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($this->codes($source), $this->codes($back));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnspellable(): array
    {
        return [
            'empty inside emphasis' => ["~~<code></code>~~\n"],
            'empty with text after it' => ["<code></code> z\n"],
            'line break in a quote' => ["> <code>a<!---->&#10;<!---->b</code>\n"],
            'line break in a list item' => ["- <code>a<!---->&#10;<!---->b</code>\n"],
            'blank line in the value' => ["a <code>b<!---->&#10;<!----><!---->&#10;<!---->c</code>\n"],
            'line break in a heading' => ["# a <code>b<!---->&#10;<!---->c</code>\n"],
        ];
    }

    #[DataProvider('provideUnspellable')]
    public function testKeepsRawHtmlWithALoss(string $markdown): void
    {
        $value = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame(['raw_inline'], array_column($this->codes($value), 0));
        $this->assertSame(1, $this->fallbacks($markdown));
    }

    /**
     * @return array<array{string, string}>
     */
    private function codes(string $source): array
    {
        $out = [];
        $walk = function (Node $node) use (&$walk, &$out): void {
            if ($node instanceof Code) {
                $out[] = ['code', $node->getContent()];

                return;
            }
            if ($node instanceof RawInline) {
                $out[] = ['raw_inline', $node->getContent()];

                return;
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk((new CarveConverter())->parse($source));

        return $out;
    }

    private function fallbacks(string $markdown): int
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);

        return count(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'raw-code-fallback'));
    }
}
