<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text that spells a link, a span, an image, a note reference, an autolink or
 * a comment is escaped on import, with the bytes the Carve writer writes for
 * the same text node (markup-carve/carve-php#2100).
 */
class AnInlineOpenerInImportedTextIsEscapedTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function texts(): array
    {
        $texts = [
            'a link' => 'x [a](u) y',
            'a reference' => 'x [a][b] y',
            'a collapsed reference' => 'x [a][] y',
            'a span' => 'x [a]{.c} y',
            'an image' => 'x ![a](u) y',
            'a note reference' => 'x [^1] y',
            'a URL autolink' => 'x <http://a.b/c> y',
            'a mail autolink' => 'x <a@b.c> y',
            'a delimited comment' => 'x {% c %} y',
            'a comment opener' => 'x %% y',
            'a longer comment opener' => 'x %%%% y',
            'a bracket run with no target' => 'x [a] y',
            'an image with no destination' => 'x ![a] y',
            'an unclosed destination' => 'x [a](u',
            'a bracket in a word' => 'x a[b]c y',
            'a pointy word' => 'x <notalink> y',
            'a percent alone' => 'x % y',
            'nested brackets' => 'x [a [b](u)](v) y',
            'a comment and a link' => 'x {% c %} [a](u) y',
        ];

        return array_map(static fn (string $text): array => [$text], $texts);
    }

    #[DataProvider('texts')]
    public function testTheImportMatchesTheWriter(string $text): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $text]]]],
        ]);

        $imported = (new HtmlToCarve())->convert('<p>' . htmlspecialchars($text, ENT_NOQUOTES) . '</p>');

        $this->assertSame((new CarveRenderer())->render($document), $imported);
    }

    #[DataProvider('texts')]
    public function testTheImportReadsBackAsTheHtmlHeldIt(string $text): void
    {
        $imported = (new HtmlToCarve())->convert('<p>' . htmlspecialchars($text, ENT_NOQUOTES) . '</p>');

        $this->assertSame(
            '<p>' . htmlspecialchars($text, ENT_NOQUOTES) . "</p>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    /**
     * A title's quotes are escaped as smart punctuation, which already stops
     * the link. The escape on the bracket is one the writer does not need, and
     * both spellings read back as the text the HTML held.
     */
    public function testATitledLinkTakesOneEscapeMoreThanTheWriter(): void
    {
        $text = 'x [a](u "t") y';
        $imported = (new HtmlToCarve())->convert('<p>' . htmlspecialchars($text, ENT_NOQUOTES) . '</p>');

        $this->assertSame("x \\[a](u \\\"t\\\") y\n", $imported);
        $this->assertSame(
            '<p>' . htmlspecialchars($text, ENT_NOQUOTES) . "</p>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    /**
     * BOUND: the importer's own markup keeps its brackets.
     */
    public function testTheImportersOwnConstructsAreNotEscaped(): void
    {
        $this->assertSame("x [a](u) y\n", (new HtmlToCarve())->convert('<p>x <a href="u">a</a> y</p>'));
        $this->assertSame("x ![a](u) y\n", (new HtmlToCarve())->convert('<p>x <img src="u" alt="a"> y</p>'));
        $this->assertSame("x [a]{.c} y\n", (new HtmlToCarve())->convert('<p>x <span class="c">a</span> y</p>'));
    }
}
