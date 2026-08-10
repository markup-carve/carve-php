<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Non-HTML render targets are safe-by-default too: Markdown output cannot carry
 * XSS into a downstream Markdown -> HTML render, and ANSI/plain output cannot
 * inject terminal escape sequences.
 */
class NonHtmlRendererSecurityTest extends TestCase
{
    protected function md(string $djot): string
    {
        $c = new CarveConverter();

        return trim((new MarkdownRenderer())->render($c->parse($djot)));
    }

    protected function ansi(string $djot): string
    {
        $c = new CarveConverter();

        return (new AnsiRenderer())->render($c->parse($djot));
    }

    public function testMarkdownBlanksDangerousLinkScheme(): void
    {
        $this->assertSame('[x]()', $this->md('[x](javascript:alert)'));
        $this->assertStringContainsString('[ok](https://e.com)', $this->md('[ok](https://e.com)'));
    }

    public function testMarkdownEscapesRawHtml(): void
    {
        $out = $this->md("```=html\n<script>alert(1)</script>\n```");
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testMarkdownEscapesEmbeddedHtmlInTextAndFallbackTags(): void
    {
        $this->assertStringNotContainsString('<img', $this->md('plain <img onerror=x> text'));

        // `<` and `>` take the entity form and `&` does not (carve#1071). The
        // reason: an entity in Markdown TEXT decodes to a CHARACTER, and a
        // character cannot open a tag, so text authored as `&lt;script&gt;`
        // comes back as the four characters a reader sees rather than as live
        // markup - which is exactly what a bare `<` would be.
        $this->assertSame('a &lt; b & c', trim($this->md('a < b & c')));
        $this->assertSame('a &lt;script&gt; b', trim($this->md('a &lt;script&gt; b')));
        // Superscript HTML fallback: children are HTML-escaped.
        $sup = $this->md('{^<img src=x onerror=alert(1)>^}');
        $this->assertStringContainsString('<sup>', $sup);
        $this->assertStringNotContainsString('<img', $sup);
    }

    public function testAnsiStripsTerminalEscapeBytes(): void
    {
        $out = $this->ansi("hi \x1b[31mX\x1b[0m \x07 there");
        $this->assertStringNotContainsString("\x1b[31m", $out);
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringContainsString('there', $out);
    }

    public function testAnsiAndPlainTextStripControlBytesFromLinkDestinations(): void
    {
        $doc = new Document();
        $paragraph = new Paragraph();
        $link = new Link("https://example.com/\x1b]8;;evil\x07");
        $link->appendChild(new Text('link'));
        $paragraph->appendChild($link);
        $doc->appendChild($paragraph);

        $ansi = (new AnsiRenderer(useColors: false))->render($doc);
        $plain = (new PlainTextRenderer())->render($doc);

        $this->assertStringNotContainsString("\x1b", $ansi);
        $this->assertStringNotContainsString("\x07", $ansi);
        $this->assertStringNotContainsString("\x1b", $plain);
        $this->assertStringNotContainsString("\x07", $plain);
    }

    public function testMarkdownEscapesLinkAndImageTitles(): void
    {
        $doc = new Document();
        $paragraph = new Paragraph();
        $link = new Link('https://example.com', 'a "quote" and \\ slash');
        $link->appendChild(new Text('link'));
        $paragraph->appendChild($link);
        $paragraph->appendChild(new Text(' '));
        $paragraph->appendChild(new Image('image.png', 'alt', 'a "quote" and \\ slash'));
        $doc->appendChild($paragraph);

        $markdown = trim((new MarkdownRenderer())->render($doc));

        $this->assertStringContainsString('[link](https://example.com "a \\"quote\\" and \\\\ slash")', $markdown);
        $this->assertStringContainsString('![alt](image.png "a \\"quote\\" and \\\\ slash")', $markdown);
    }

    public function testMarkdownEscapesImageAltLabelMetacharacters(): void
    {
        $doc = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Image('safe.png', 'x](url)![y\\z'));
        $doc->appendChild($paragraph);

        $markdown = trim((new MarkdownRenderer())->render($doc));

        $this->assertSame('![x\\](url)!\\[y\\\\z](safe.png)', $markdown);
        $this->assertStringNotContainsString('](url)![', $markdown);
    }

    /**
     * PART 9 §29 splits this by TARGET. The terminal is the one consumer that
     * ACTS on the character - ESC introduces a sequence that moves the cursor,
     * rewrites earlier output or reaches the clipboard - so it strips, and the
     * assertion below is the one that stayed. Markdown and plain text EMIT the
     * non-whitespace C0 controls (§29 T2, T3): they are read by a parser and by
     * a text serialization, not by a device, and deleting content there makes
     * this engine the lossy party.
     *
     * DEL and the C1 controls are NOT §29's subject and stay refused on all
     * three, this engine's own strictness: CSI (U+009B) and OSC (U+009D) are
     * single-character forms of the sequences §25 exists to stop. Measured
     * today, carve-rs cdac42c refuses them on Markdown and plain as well.
     */
    public function testNonHtmlRenderersStripControlBytesFromAuthorLeafFields(): void
    {
        $doc = new Document();
        $doc->appendChild(new CodeBlock("code\x1b[31m", "php\x1b"));

        $footnote = new Footnote("fn\x1b");
        $footnotePara = new Paragraph();
        $footnotePara->appendChild(new Text('note'));
        $footnote->appendChild($footnotePara);
        $doc->appendChild($footnote);

        $paragraph = new Paragraph();
        $link = new Link('https://example.com', "title\x1b");
        $link->appendChild(new Text('link'));
        $paragraph->appendChild($link);
        $paragraph->appendChild(new Image('image.png', "alt\x1b", "img title\x1b"));
        $paragraph->appendChild(new Math("x\x1b+y"));
        $paragraph->appendChild(new FootnoteRef("fn\x1b"));
        $abbr = new Abbreviation("expansion\x1b");
        $abbr->appendChild(new Text('abbr'));
        $paragraph->appendChild($abbr);
        $doc->appendChild($paragraph);

        $markdown = (new MarkdownRenderer())->render($doc);
        $plain = (new PlainTextRenderer())->render($doc);
        $ansi = (new AnsiRenderer(useColors: false))->render($doc);

        // The device target, unchanged.
        $this->assertStringNotContainsString("\x1b", $ansi);

        // The two serialization targets keep what the author wrote - once per
        // leaf field the fixture put one in, so this also says the emission is
        // not confined to a paragraph's text.
        foreach ([$markdown, $plain] as $out) {
            $this->assertStringContainsString("\x1b", $out);
        }

        // And none of the three lets DEL or a C1 control out.
        $c1 = new Document();
        $c1Para = new Paragraph();
        $c1Para->appendChild(new Text("a\u{007F}b\u{0080}c\u{009B}d\u{009D}e"));
        $c1->appendChild($c1Para);
        foreach ([new MarkdownRenderer(), new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
            $out = $renderer->render($c1);
            foreach (["\u{007F}", "\u{0080}", "\u{009B}", "\u{009D}"] as $blocked) {
                $this->assertStringNotContainsString($blocked, $out);
            }
        }
    }

    public function testHtmlImportDropsEventHandlers(): void
    {
        $carve = (new HtmlToCarve())->convert('<a href="https://e.com" onerror="alert(1)" onfocus="x">y</a>');
        $this->assertStringNotContainsString('onerror', $carve);
        $this->assertStringNotContainsString('onfocus', $carve);
    }
}
