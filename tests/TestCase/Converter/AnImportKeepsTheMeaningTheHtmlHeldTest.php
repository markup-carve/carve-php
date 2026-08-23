<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Shapes an HTML import came back from meaning something the HTML never said
 * (markup-carve/carve#1601, minimized in markup-carve/carve#1608, filed as
 * carve-php#1615).
 *
 * They sit together because they are one FAILURE reached several ways: an
 * import that reports nothing and still changes the document. Two of them are
 * PART 11 §2 - the writer escapes a character if and only if omitting it would
 * change the re-parse - and the third is a drop that was not observable.
 */
class AnImportKeepsTheMeaningTheHtmlHeldTest extends TestCase
{
    protected HtmlToCarve $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new HtmlToCarve();
    }

    /**
     * A caption line reaches BACK across one blank line and makes a figure of
     * the block above it, and one blank line is exactly what this importer
     * writes between blocks - so a paragraph beginning `^ ` stopped being one.
     *
     * @return array<string, array{0: string}>
     */
    public static function captionHostProvider(): array
    {
        return [
            'a table' => ['<table><tr><td>a</td></tr></table>'],
            'a block quote' => ['<blockquote><p>q</p></blockquote>'],
            'a code block' => ['<pre><code>x</code></pre>'],
            'an image' => ['<img src="g.jpg" alt="G">'],
        ];
    }

    /**
     * @param string $host
     */
    #[DataProvider('captionHostProvider')]
    public function testACaretParagraphAfterACaptionHostIsEscaped(string $host): void
    {
        $imported = $this->converter->convert($host . '<p>^ c</p>');

        $this->assertStringContainsString(
            '\^ c',
            $imported,
            "the caret must be hardened after a captionable block; imported source was:\n" . $imported,
        );
        // The escape is only worth writing if it restores the input, so the
        // paragraph is asserted back rather than the bytes alone.
        $this->assertStringContainsString('<p>^ c</p>', (new CarveConverter())->convert($imported));
    }

    /**
     * The other half of PART 11 §2, and the half a writer gets wrong silently:
     * an idle escape passes every gate aimed at the missing one.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noCaptionHostProvider(): array
    {
        return [
            'nothing before it' => ['', "^ c\n"],
            'a paragraph' => ['<p>t</p>', "t\n\n^ c\n"],
            'a heading' => ['<h2>h</h2>', "## h\n\n^ c\n"],
            'a list' => ['<ul><li>a</li></ul>', "- a\n\n^ c\n"],
        ];
    }

    /**
     * @param string $before
     * @param string $expected
     */
    #[DataProvider('noCaptionHostProvider')]
    public function testACaretParagraphIsLeftAloneWhereNothingCanCaptionIt(string $before, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($before . '<p>^ c</p>'));
    }

    /**
     * A bare caret is not a caption opener - the line needs content after it -
     * so it needs no escape even directly under a host.
     */
    public function testABareCaretNeedsNoEscape(): void
    {
        $this->assertSame(
            "| a |\n\n^\n",
            $this->converter->convert('<table><tr><td>a</td></tr></table><p>^</p>'),
        );
    }

    /**
     * The importer's OWN caption lines are written through the caption slot and
     * must stay bare - escaping those would destroy the caption it just built.
     */
    public function testTheImportersOwnCaptionLinesStayBare(): void
    {
        $this->assertSame(
            "![a](i.png)\n^ cap\n",
            $this->converter->convert('<figure><img src="i.png" alt="a"><figcaption>cap</figcaption></figure>'),
        );
        $this->assertSame(
            "| a |\n^ cap\n",
            $this->converter->convert('<table><caption>cap</caption><tr><td>a</td></tr></table>'),
        );
    }

    /**
     * A span and an inline link both write their content in a bracket run, and
     * `[^x]` is a note reference (PART 11 §2).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function noteReferenceLabelProvider(): array
    {
        return [
            'a semantic span' => [
                '<p><abbr title="y">^1</abbr></p>',
                "[\\^1]{abbr=y}\n",
                '<p><abbr title="y">^1</abbr></p>',
            ],
            // The slot is the bracket run rather than the element.
            'a plain span' => [
                '<p><span class="c">^1</span></p>',
                "[\\^1]{.c}\n",
                '<p><span class="c">^1</span></p>',
            ],
            // An anchor loses its DESTINATION to the same collision.
            'an anchor' => [
                '<p><a href="u">^1</a></p>',
                "[\\^1](u)\n",
                '<p><a href="u">^1</a></p>',
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $expected
     * @param string $rendersBack
     */
    #[DataProvider('noteReferenceLabelProvider')]
    public function testALabelThatOpensANoteReferenceIsEscaped(
        string $html,
        string $expected,
        string $rendersBack,
    ): void {
        $this->assertSame($expected, $this->converter->convert($html));
        $this->assertStringContainsString($rendersBack, (new CarveConverter())->convert($expected));
    }

    /**
     * Only the LABELED half collides, and over-escaping here is as wrong as
     * under-escaping.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noteReferenceCarveOutProvider(): array
    {
        return [
            // A reference needs at least one character after the caret.
            'a bare caret' => ['<p><abbr title="y">^</abbr></p>', "[^]{abbr=y}\n"],
            // A caret anywhere else is ordinary punctuation.
            'a caret past the first position' => ['<p><abbr title="y">a^1</abbr></p>', "[a^1]{abbr=y}\n"],
            // An image label is a different slot: the `!` takes the `[` first.
            'an image label' => ['<p><img src="u" alt="^1"></p>', "![^1](u)\n"],
        ];
    }

    /**
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('noteReferenceCarveOutProvider')]
    public function testALabelThatOpensNoNoteReferenceKeepsNoEscape(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($html));
    }

    /**
     * An empty `<ins>` or `<del>` is dropped, and the drop is observable.
     *
     * Dropping is the right half of the answer - the other engine wrote an
     * empty brace pair, which is not a construct - but a silent drop is still
     * an element that left the document.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function emptyChangeTrackingProvider(): array
    {
        return [
            'an empty insertion' => ['<p><ins></ins></p>', '/p[1]/ins[1]'],
            'an empty deletion' => ['<p><del></del></p>', '/p[1]/del[1]'],
            'one between two runs of text' => ['<p>x<ins></ins>y</p>', '/p[1]/ins[2]'],
        ];
    }

    /**
     * @param string $html
     * @param string $path
     */
    #[DataProvider('emptyChangeTrackingProvider')]
    public function testAnEmptyChangeTrackingElementReportsItsDrop(string $html, string $path): void
    {
        $report = $this->converter->convertWithReport($html);
        $codes = array_map(static fn ($row): string => $row->code, $report->diagnostics);

        $this->assertSame(['element-dropped'], $codes);
        $this->assertSame($path, $report->diagnostics[0]->path);
    }

    /**
     * A non-empty one is untouched, and reports nothing: it has a marker of its
     * own and nothing is lost.
     */
    public function testANonEmptyChangeTrackingElementIsUnchangedAndSilent(): void
    {
        $this->assertSame("{+a+}\n", $this->converter->convert('<p><ins>a</ins></p>'));
        $this->assertSame("{-a-}\n", $this->converter->convert('<p><del>a</del></p>'));
        $this->assertSame([], $this->converter->convertWithReport('<p><ins>a</ins></p>')->diagnostics);
    }
}
