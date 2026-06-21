<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Converter\HtmlToCarve;
use Carve\Node\Block\CodeBlock;
use Carve\Node\Block\Footnote;
use Carve\Node\Block\Paragraph;
use Carve\Node\Document;
use Carve\Node\Inline\Abbreviation;
use Carve\Node\Inline\FootnoteRef;
use Carve\Node\Inline\Image;
use Carve\Node\Inline\Link;
use Carve\Node\Inline\Math;
use Carve\Node\Inline\Text;
use Carve\Renderer\AnsiRenderer;
use Carve\Renderer\MarkdownRenderer;
use Carve\Renderer\PlainTextRenderer;
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

        foreach ([$markdown, $plain, $ansi] as $out) {
            $this->assertStringNotContainsString("\x1b", $out);
        }
    }

    public function testHtmlImportDropsEventHandlers(): void
    {
        $carve = (new HtmlToCarve())->convert('<a href="https://e.com" onerror="alert(1)" onfocus="x">y</a>');
        $this->assertStringNotContainsString('onerror', $carve);
        $this->assertStringNotContainsString('onfocus', $carve);
    }
}
