<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Definition descriptions and footnote bodies continue like list items:
 * form A (indented block after a blank line) and form B (a lone `+` that
 * attaches the following flush-left block). Mirrors the carve-js oracle.
 */
class DefinitionLooseContinuationTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testFormAMultiParagraphDefinition(): void
    {
        $out = rtrim($this->converter->convert(":: term\n:  First para.\n\n   Second para."), "\n");
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>First para.</p>\n    <p>Second para.</p>\n  </dd>\n</dl>",
            $out,
        );
    }

    public function testFormBPlusAttachesFlushLeftParagraph(): void
    {
        $out = rtrim($this->converter->convert(":: term\n:  First para.\n+\nSecond para."), "\n");
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>First para.</p>\n    <p>Second para.</p>\n  </dd>\n</dl>",
            $out,
        );
    }

    public function testFormBPlusAttachesFlushLeftBlock(): void
    {
        $out = rtrim($this->converter->convert(":: term\n:  Intro.\n+\n> a quote"), "\n");
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>Intro.</p>\n    <blockquote><p>a quote</p></blockquote>\n  </dd>\n</dl>",
            $out,
        );
    }

    public function testMultipleDefinitionsStaySeparate(): void
    {
        $out = rtrim($this->converter->convert(":: term\n:  a\n:  b"), "\n");
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>a</dd>\n  <dd>b</dd>\n</dl>",
            $out,
        );
    }

    public function testSingleParagraphDefinitionStaysTight(): void
    {
        $out = rtrim($this->converter->convert(":: term\n:  just one"), "\n");
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>just one</dd>\n</dl>",
            $out,
        );
    }

    public function testEntrySeparatorBlankBeforeNextTerm(): void
    {
        $out = rtrim($this->converter->convert(":: t1\n:  a\n\n:: t2\n:  b"), "\n");
        $this->assertSame(
            "<dl>\n  <dt>t1</dt>\n  <dd>a</dd>\n  <dt>t2</dt>\n  <dd>b</dd>\n</dl>",
            $out,
        );
    }

    public function testFootnoteBodyAcceptsPlusContinuation(): void
    {
        $out = rtrim($this->converter->convert("X.[^a]\n\n[^a]: First.\n+\nSecond."), "\n");
        $this->assertSame(
            "<p>X.<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
                . "<section role=\"doc-endnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <p>First.</p>\n      <p>Second.<a href=\"#fnref1\" role=\"doc-backlink\">↩</a></p>\n"
                . "    </li>\n  </ol>\n</section>",
            $out,
        );
    }

    public function testLazyContinuationFoldsFlushLeftLine(): void
    {
        $out = rtrim($this->converter->convert(":: term\n:  A definition wrapped\nonto the next line."), "\n");
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>A definition wrapped\nonto the next line.</dd>\n</dl>",
            $out,
        );
    }

    public function testBlockOpenerEndsDefinitionInsteadOfFolding(): void
    {
        $out = rtrim($this->converter->convert(":: term\n:  def\n# heading"), "\n");
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>def</dd>\n</dl>\n<section id=\"heading\">\n  <h1>heading</h1>\n</section>",
            $out,
        );
    }

    public function testBlankLineClosesParagraphSoFlushLeftEndsDefinition(): void
    {
        $out = rtrim($this->converter->convert(":: term\n:  def\n\nafter blank"), "\n");
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n  <dd>def</dd>\n</dl>\n<p>after blank</p>",
            $out,
        );
    }

    public function testFirstBlockFormOpensBlockBodiedDefinition(): void
    {
        $out = rtrim($this->converter->convert(":: t\n:  +\n> a quote"), "\n");
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote><p>a quote</p></blockquote>\n  </dd>\n</dl>",
            $out,
        );
    }

    public function testEscapedPlusKeepsLiteralContent(): void
    {
        $out = rtrim($this->converter->convert(":: t\n:  \\+"), "\n");
        $this->assertSame("<dl>\n  <dt>t</dt>\n  <dd>+</dd>\n</dl>", $out);
    }

    public function testTermFoldsWrappedLineAndKeepsDefinition(): void
    {
        $out = rtrim($this->converter->convert(":: A term that\nwraps\n:  def"), "\n");
        $this->assertSame(
            "<dl>\n  <dt>A term that\nwraps</dt>\n  <dd>def</dd>\n</dl>",
            $out,
        );
    }

    public function testBlockOpenerAfterTermEndsList(): void
    {
        $out = rtrim($this->converter->convert(":: term\n> quote"), "\n");
        $this->assertSame(
            "<dl>\n  <dt>term</dt>\n</dl>\n<blockquote><p>quote</p></blockquote>",
            $out,
        );
    }
}
