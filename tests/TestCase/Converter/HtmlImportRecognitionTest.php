<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Extension\DetailsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two recognition upgrades that both turn on what the report says as much as
 * on what the converter writes.
 *
 * `<details>` reached `::: details`, but its `<summary>` fell through as
 * ordinary block content - so the disclosure came back wearing the extension's
 * default label with the real one demoted to a paragraph. The summary is the
 * widget's label and the extension takes the label from the opener's quoted
 * title, so that is where it goes.
 *
 * `<q>` was already mapped to quote characters and still reported
 * `element-unwrapped`, which is a loss that does not happen.
 */
class HtmlImportRecognitionTest extends TestCase
{
    protected HtmlToCarve $converter;

    protected CarveConverter $carve;

    /**
     * The disclosure widget the round trip has to reach. Without the extension
     * a details block is a generic div, so an assertion on it would be about
     * the default admonition rendering rather than about this mapping.
     */
    protected CarveConverter $disclosure;

    protected function setUp(): void
    {
        $this->converter = new HtmlToCarve();
        $this->carve = new CarveConverter();
        $this->disclosure = new CarveConverter();
        $this->disclosure->addExtension(new DetailsExtension());
    }

    /**
     * @return list<string>
     */
    protected function diagnosticCodes(string $html): array
    {
        return array_map(
            static fn ($diagnostic): string => $diagnostic->code,
            (new HtmlToCarve())->convertWithReport($html)->diagnostics,
        );
    }

    // ==================== details / summary ====================

    public function testTheSummaryBecomesTheDisclosureLabel(): void
    {
        $carve = $this->converter->convert('<details><summary>More detail</summary><p>Body</p></details>');

        $this->assertSame("::: details \"More detail\"\nBody\n:::\n", $carve);
        $this->assertSame(
            "<details>\n  <summary>More detail</summary>\n  <p>Body</p>\n</details>\n",
            $this->disclosure->convert($carve),
        );
    }

    /**
     * The title goes through the inline path, so markup inside the summary
     * reaches the label rather than being flattened to its text.
     */
    public function testMarkupInTheSummaryReachesTheLabel(): void
    {
        $carve = $this->converter->convert('<details><summary>A <strong>b</strong></summary><p>c</p></details>');

        $this->assertSame("::: details \"A *b*\"\nc\n:::\n", $carve);
        $this->assertStringContainsString('<summary>A <strong>b</strong></summary>', $this->disclosure->convert($carve));
    }

    public function testNestedDisclosuresEachKeepTheirOwnLabel(): void
    {
        $rendered = $this->disclosure->convert($this->converter->convert(
            '<details><summary>Outer</summary><details><summary>Inner</summary><p>b</p></details></details>',
        ));

        $this->assertStringContainsString('<summary>Outer</summary>', $rendered);
        $this->assertStringContainsString('<summary>Inner</summary>', $rendered);
    }

    /**
     * `open` is carried by the attribute block onto the rendered element, so
     * the disclosure starts open again - it is reproduced, not dropped, and the
     * report said otherwise.
     */
    public function testOpenIsCarriedAndNotReportedAsDropped(): void
    {
        $carve = $this->converter->convert('<details open><summary>S</summary><p>Body</p></details>');

        $this->assertStringContainsString('<details open="">', $this->disclosure->convert($carve));
        $this->assertNotContains(
            'attribute-dropped',
            $this->diagnosticCodes('<details open><summary>S</summary><p>Body</p></details>'),
        );
    }

    /**
     * A summary the opener line cannot hold keeps its text as block content -
     * nothing is lost but the label role, and that loss is reported rather than
     * passing in silence.
     *
     * @return array<string, array{0: string}>
     */
    public static function unwritableSummaryProvider(): array
    {
        return [
            'holding the title delimiter' => ['<details><summary>He said "hi"</summary><p>Body</p></details>'],
            'holding several blocks' => ['<details><summary><p>one</p><p>two</p></summary><p>Body</p></details>'],
        ];
    }

    #[DataProvider('unwritableSummaryProvider')]
    public function testAnUnwritableSummaryKeepsItsTextAndReportsTheLabel(string $html): void
    {
        $carve = $this->converter->convert($html);

        $this->assertStringNotContainsString('::: details "', $carve);
        $this->assertContains('element-unwrapped', $this->diagnosticCodes($html));
    }

    /**
     * The delimiter really is unwritable, so the fallback is not a guess: the
     * escaped form does not open a fence at all and takes the whole block down
     * with it.
     */
    public function testTheEscapedTitleFormWouldNotHaveOpenedAFence(): void
    {
        $this->assertStringNotContainsString(
            '<details',
            $this->disclosure->convert("::: details \"He said \\\"hi\\\"\"\nBody\n:::\n"),
        );
    }

    /**
     * A pipe-table cell is one line of inline content, so the colon fence a
     * disclosure needs cannot open inside one and the container degrades to its
     * text. The degradation stands; going unreported did not.
     */
    public function testADisclosureInsideATableCellReportsItsDegradation(): void
    {
        $html = '<table><tr><td><details><summary>S</summary><p>B</p></details></td></tr></table>';

        $this->assertSame("| S B |\n", $this->converter->convert($html));
        $this->assertContains('element-unwrapped', $this->diagnosticCodes($html));
    }

    /**
     * CONTROL. The same disclosure outside a cell keeps its fence and reports
     * nothing, so the report is about the cell rather than about disclosures.
     */
    public function testADisclosureOutsideACellReportsNothing(): void
    {
        $html = '<details><summary>S</summary><p>B</p></details>';

        $this->assertSame("::: details \"S\"\nB\n:::\n", $this->converter->convert($html));
        $this->assertSame([], $this->diagnosticCodes($html));
    }

    /**
     * CONTROL. A details block with no summary has no label to write, and must
     * neither gain a title nor report a loss.
     */
    public function testADetailsWithoutASummaryIsUnchanged(): void
    {
        $html = '<details><p>Body</p></details>';

        $this->assertSame("::: details\nBody\n:::\n", $this->converter->convert($html));
        $this->assertSame([], $this->diagnosticCodes($html));
    }

    /**
     * CONTROL. An empty summary carries nothing, so there is nothing to report.
     */
    public function testAnEmptySummaryReportsNothing(): void
    {
        $this->assertSame([], $this->diagnosticCodes('<details><summary></summary><p>Body</p></details>'));
    }

    // ==================== q ====================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function inlineQuoteProvider(): array
    {
        return [
            'plain' => ['<p>He said <q>hi</q>.</p>', "He said \"hi\".\n"],
            'holding markup' => ['<p><q>a <strong>b</strong> c</q></p>', "\"a *b* c\"\n"],
        ];
    }

    #[DataProvider('inlineQuoteProvider')]
    public function testAnInlineQuoteBecomesQuoteCharacters(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($html));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function quoteReportsNothingProvider(): array
    {
        return [
            'plain' => ['<p>He said <q>hi</q>.</p>'],
            'holding markup' => ['<p><q>a <strong>b</strong> c</q></p>'],
            'nested' => ['<p><q>outer <q>inner</q></q></p>'],
            'empty' => ['<p><q></q></p>'],
        ];
    }

    #[DataProvider('quoteReportsNothingProvider')]
    public function testAnInlineQuoteReportsNoUnwrapping(string $html): void
    {
        $this->assertNotContains('element-unwrapped', $this->diagnosticCodes($html));
    }

    /**
     * CONTROL. Quote characters are what the source now says, and the renderer
     * turns them into the typographic pair - so the mapping survives the parse
     * rather than only the string comparison above.
     */
    public function testTheQuoteCharactersSurviveTheParse(): void
    {
        $this->assertSame(
            "<p>He said \u{201C}hi\u{201D}.</p>\n",
            $this->carve->convert($this->converter->convert('<p>He said <q>hi</q>.</p>')),
        );
    }

    /**
     * CONTROL. An element that really is unwrapped still says so, so the fix
     * narrowed the report rather than silencing it.
     */
    public function testAnActuallyUnwrappedElementStillReportsIt(): void
    {
        $this->assertContains('element-unwrapped', $this->diagnosticCodes('<p>a <bdi>text</bdi> b</p>'));
    }
}
