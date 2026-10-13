<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Roundtrip Markdown export and import edges ruled in markup-carve/carve#3120
 * (CARVE-P11-028, CARVE-P11-063, CARVE-P11-033 T1), ported from carve-js.
 */
class RoundtripMarkdownReferenceAndMarkerEdgesTest extends TestCase
{
    public function testPlainExportKeepsTheInlineForm(): void
    {
        $this->assertSame("[a](/u)\n", $this->export("[a][r]\n\n[r]: /u\n", false));
    }

    public function testTheTitleIsWrittenOnTheDefinitionLineOnly(): void
    {
        $this->assertSame("[a][r]\n\n[r]: /u \"T\"\n", $this->export("[a][r]\n\n[r]: /u \"T\"\n"));
    }

    public function testLabelsMarkdownWouldCaseFoldTogetherStayInline(): void
    {
        $source = <<<'CARVE'
[a][r] [b][R]

[r]: /u

[R]: /v

CARVE;
        $markdown = $this->export($source);

        $this->assertStringContainsString('[a](/u) [b](/v)', $markdown);
        $converter = new CarveConverter();
        $this->assertSame($converter->convert($source), $converter->convert((new MarkdownToCarve())->convert($markdown)));
    }

    public function testACollapsedReferenceIsWrittenInFullWhenTheTextIsNotTheLabel(): void
    {
        $this->assertStringContainsString('[**r**][*r*]', $this->export("[*r*][]\n\n[*r*]: /u\n"));
    }

    public function testABareDivInsideANamedOnePairs(): void
    {
        $source = <<<'CARVE'
::: note
::::
x
::::
:::

CARVE;

        $this->assertStringContainsString("<!-- carve: :::: -->\nx", $this->export($source));
        $this->assertSame($source, $this->back($source));
        $this->assertSame(":::\ninside\n:::\n", $this->back(":::\ninside\n:::\n"));
    }

    public function testThePaddedBareOpenerAnOlderExportWroteIsStillRead(): void
    {
        $markdown = <<<'MD'
<!-- carve: :::  -->
inside

<!-- carve: ::: -->

MD;

        $this->assertSame(":::\ninside\n:::\n", (new MarkdownToCarve())->convert($markdown));
    }

    public function testAWrappedTermKeepsItsLineBreak(): void
    {
        $source = <<<'CARVE'
:: A term that
wraps onto the next line
: its definition

CARVE;

        $this->assertStringContainsString("<!-- carve: :: A term that\nwraps onto the next line -->", $this->export($source));
        $this->assertSame($source, $this->back($source));
    }

    public function testAPayloadSpanningLinesIsReadBehindABlockQuote(): void
    {
        $source = <<<'CARVE'
> :: A term that
> wraps here
> : its definition

CARVE;

        $this->assertSame($source, $this->back($source));
    }

    public function testAnAbbrADefinitionResolvesFoldsBackToThePlainTerm(): void
    {
        $source = <<<'CARVE'
*[HTML]: Hyper Text

HTML here

CARVE;

        $this->assertSame($source, $this->back($source));
    }

    public function testAnAbbrWhoseTitleNoDefinitionGivesItsTextIsKept(): void
    {
        $markdown = <<<'MD'
*[HTML]: Hyper Text

<abbr title="Other">HTML</abbr>

MD;

        $this->assertSame("*[HTML]: Hyper Text\n\n[HTML]{abbr=\"Other\"}\n", (new MarkdownToCarve())->convert($markdown));
    }

    public function testAnAbbrIsKeptWhenTheDocumentDefinesNothing(): void
    {
        $this->assertSame("[HTML]{abbr=\"Hyper Text\"}\n", (new MarkdownToCarve())->convert("<abbr title=\"Hyper Text\">HTML</abbr>\n"));
    }

    public function testTheDefinitionLineStaysTextWhenAbbreviationsAreTurnedOff(): void
    {
        $this->assertSame(
            "\\*[HTML]: HyperText\n\nHTML here",
            (new MarkdownToCarve(convertAbbreviations: false))->convert("*[HTML]: HyperText\n\nHTML here"),
        );
    }

    private function export(string $source, bool $roundtrip = true): string
    {
        return (new CarveConverter(renderer: new MarkdownRenderer(), roundTripMode: $roundtrip))->convert($source);
    }

    private function back(string $source): string
    {
        return (new MarkdownToCarve())->convert($this->export($source));
    }
}
