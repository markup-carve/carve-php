<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownHtmlAndCharacterReferenceTest extends TestCase
{
    private function render(string $markdown, ?MarkdownToCarve $converter = null): string
    {
        $carve = ($converter ?? new MarkdownToCarve())->convert($markdown);

        return trim(CarveConverter::create()->convert($carve));
    }

    public function testNamedAndNumericCharacterReferencesBecomeText(): void
    {
        $this->assertSame(
            '<p>Copyright © © © and &lt;b&gt;not a tag&lt;/b&gt;.</p>',
            $this->render("Copyright &copy; &#169; &#xA9; and &lt;b&gt;not a tag&lt;/b&gt;.\n"),
        );
    }

    public function testDecodedMarkupPunctuationCannotBecomeMarkup(): void
    {
        $this->assertSame('<p>*not strong* and #not-a-tag</p>', $this->render('&ast;not strong&ast; and &num;not-a-tag'));
    }

    public function testInvalidReferencesAndCodeSpanReferencesStayLiteral(): void
    {
        $this->assertSame(
            '<p>&amp;bogus; and <code>&amp;copy;</code></p>',
            $this->render('&bogus; and `&copy;`'),
        );
    }

    public function testArbitraryPairedAndVoidHtmlUseTheAuditedHtmlImporter(): void
    {
        $converter = new MarkdownToCarve(convertRawHtml: true);
        $this->assertSame(
            "<div class=\"details\">\n  <p class=\"admonition-title\">More</p>\n  <p>body</p>\n</div>",
            $this->render('<details><summary>More</summary>body</details>', $converter),
        );
        $this->assertSame("<p>Press <kbd>Enter</kbd><br>\nnow.</p>", $this->render('Press <kbd>Enter</kbd><br>now.', $converter));
        $this->assertSame('', $this->render('<?processing instruction?>', $converter));
        $this->assertSame('', $this->render('<!DOCTYPE html>', $converter));
        $this->assertSame('', $this->render('<!-- hidden -->', $converter));
    }

    public function testMultilineHtmlIsImportedAsOneStructureInsideContainers(): void
    {
        $converter = new MarkdownToCarve(convertRawHtml: true);
        $markdown = "> <details>\n> <summary>More</summary>\n> body\n> </details>\n";

        $this->assertSame(
            "<blockquote>\n  <div class=\"details\">\n    <p class=\"admonition-title\">More</p>\n    <p>body</p>\n  </div>\n</blockquote>",
            $this->render($markdown, $converter),
        );

        $this->assertSame(
            "<ul>\n  <li>\n    <div class=\"details\">\n      <p class=\"admonition-title\">More</p>\n      <p>body</p>\n    </div>\n  </li>\n</ul>",
            $this->render("- <details>\n  <summary>More</summary>\n  body\n  </details>\n", $converter),
        );
    }

    public function testRawHtmlPassesThroughVerbatimByDefault(): void
    {
        $converter = new MarkdownToCarve();

        $this->assertSame('`<span>inline</span>`{=html} rest', $converter->convert('<span>inline</span> rest'));
        $this->assertSame(
            "```=html\n<details><summary>More</summary>body</details>\n```",
            $converter->convert('<details><summary>More</summary>body</details>'),
        );
        $this->assertSame(
            "```=html\n<div class=\"x\">\n```\n\ntext\n\n```=html\n</div>\n```\n",
            $converter->convert("<div class=\"x\">\n\ntext\n\n</div>\n"),
        );
    }
}
