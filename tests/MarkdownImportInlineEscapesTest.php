<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Escapes the Markdown importer adds must not land inside a code span, and a
 * bare `_` or `*` run must not turn into Carve markup.
 */
class MarkdownImportInlineEscapesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideImports(): array
    {
        return [
            'code span over a lazy item line' => ["- `c\n d`\n", "- `c d`\n", "<ul>\n  <li><code>c d</code></li>\n</ul>"],
            'code span over a lazy quote line' => ["> `a\n b`\n", "> `a b`\n", '<blockquote><p><code>a b</code></p></blockquote>'],
            'marker line inside a code span' => ["` tag\n    *   x\n    *   `\n", "`tag *   x *  `\n", '<p><code>tag *   x *  </code></p>'],
            'definition line inside a code span' => ["* a `c\n [a]: b`\n", "* a `c [a]: b`\n", "<ul>\n  <li>a <code>c [a]: b</code></li>\n</ul>"],
            'thematic break inside a code span' => ["1. a ``c\n---``\n", "1. a `c ---`\n", "<ol>\n  <li>a <code>c ---</code></li>\n</ol>"],
            'bare underscores Carve would pair' => ["._!___a\n", ".\\_!\\_\\_\\_a\n", '<p>._!___a</p>'],
            'bare stars Carve would pair' => ["a *!**b\n", "a \\*!\\*\\*b\n", '<p>a *!**b</p>'],
            'bare underscores between hyphens' => ["-_-___a\n", "-\\_-\\_\\_\\_a\n", '<p>-_-___a</p>'],
            'intraword underscores stay bare' => ["a_b_c\n", "a_b_c\n", '<p>a_b_c</p>'],
            'autolink in an image description' => ["![<http://example.com>](logo.png)\n", "![http://example.com](logo.png)\n", '<img src="logo.png" alt="http://example.com">'],
            'hard break in an image description' => ["![a\\\nb](u)\n", "![a\nb](u)\n", "<img src=\"u\" alt=\"a\nb\">"],
            'emphasis before an autolink in an image description' => ["![_y_<a@b.co>](u)\n", "![ya@b.co](u)\n", '<img src="u" alt="ya@b.co">'],
        ];
    }

    #[DataProvider('provideImports')]
    public function testImport(string $markdown, string $carve, string $html): void
    {
        $converter = new MarkdownToCarve();
        self::assertSame($carve, $converter->convert($markdown));
        self::assertSame($carve, $converter->convertWithFidelityReport($markdown)->value);
        self::assertSame($html, trim((new CarveConverter())->convert($carve)));
    }

    public function testAnInputHoldingTheEscapeMarkerKeepsItsBytes(): void
    {
        $carve = (new MarkdownToCarve())->convert("a\x02b\n    - c\n");

        self::assertStringContainsString("a\x02b", $carve);
        self::assertStringContainsString('\\- c', $carve);
    }

    public function testADecodedEscapeMarkerKeepsItsCharacter(): void
    {
        foreach (["&#2;\n", "&#x2;\n    - c\n"] as $markdown) {
            $main = (new MarkdownToCarve())->convert($markdown);

            self::assertStringNotContainsString("\\\n", $main);
            self::assertStringNotContainsString('\\\\', $main);
        }
    }
}
