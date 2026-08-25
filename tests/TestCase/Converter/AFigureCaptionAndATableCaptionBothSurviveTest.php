<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TWO CAPTIONS AND ONE SLOT (ruling `markup-carve/carve-js#1488`).
 *
 * A `<figure>` around a `<table>` that carries its own `<caption>` arrives with
 * two captions, and Carve has exactly one `^ ` line to spell them with: on a
 * table the caret becomes the table's OWN `<caption>`, so the wrapper has
 * nothing left to carry the `<figcaption>` with.
 *
 * THIS ENGINE WROTE BOTH LINES, and the second re-read as a literal paragraph -
 * so the imported document came back holding a `^` its author never typed, in
 * every mode. That is `markup-carve/carve-php#1731`'s failure one construct
 * over: a lossy mode may lose the figure, and no mode may add a character.
 * carve-js failed the other way and threw the `<figcaption>` away.
 *
 * THE ASSERTIONS ARE ON THE RE-RENDER, not on the emitted source. A test
 * pinning output bytes passes an implementation that trades one corruption for
 * another, and that half-fix has appeared repeatedly in this family. What the
 * ruling claims is a property of the document that comes back: both authored
 * strings are in it, and no caret is.
 */
class AFigureCaptionAndATableCaptionBothSurviveTest extends TestCase
{
    /**
     * @var string
     */
    protected const HTML = '<figure id="f"><table><caption>TableCap</caption><tr><td>a</td></tr></table>'
        . '<figcaption>FigCap</figcaption></figure>';

    /**
     * @var string
     */
    protected const DETACHED = 'Detached a <figcaption> into a paragraph after the table: '
        . "the table's own <caption> fills Carve's one caption slot, "
        . "so the figure's caption keeps its text and loses its role";

    protected function carve(string $html, string $mode): string
    {
        return (new HtmlToCarve(importMode: $mode))->convert($html);
    }

    protected function reread(string $html, string $mode): string
    {
        return (new CarveConverter())->convert($this->carve($html, $mode));
    }

    /**
     * @return list<array{code: string, message: string, severity: string, path: string}>
     */
    protected function rows(string $html, string $mode): array
    {
        return array_map(
            static fn (HtmlImportDiagnostic $diagnostic): array => [
                'code' => $diagnostic->code,
                'message' => $diagnostic->message,
                'severity' => $diagnostic->severity,
                'path' => $diagnostic->path,
            ],
            (new HtmlToCarve(importMode: $mode))->convertWithReport($html)->diagnostics,
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modes(): array
    {
        return ['safe' => ['safe'], 'semantic' => ['semantic'], 'roundtrip' => ['roundtrip']];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lossyModes(): array
    {
        return ['safe' => ['safe'], 'semantic' => ['semantic']];
    }

    #[DataProvider('modes')]
    public function testBothCaptionTextsSurvive(string $mode): void
    {
        $rendered = $this->reread(self::HTML, $mode);

        $this->assertStringContainsString('TableCap', $rendered);
        $this->assertStringContainsString('FigCap', $rendered);
    }

    /**
     * THE CORRUPTION, and the highest-value half of this change. A `^` reaching
     * rendered text is the document saying something its author did not.
     */
    #[DataProvider('modes')]
    public function testNoCaretReachesTheRenderedDocument(string $mode): void
    {
        $this->assertStringNotContainsString('^', $this->reread(self::HTML, $mode));
    }

    /**
     * `roundtrip` PRESERVES AND THE OTHER TWO DO NOT (`markup-carve/carve#1704`):
     * rebuild where a Carve spelling reproduces the element, preserve where none
     * does. Two captions and one slot means none does, so the mode whose job is
     * fidelity keeps the bytes and the lossy modes take the declared loss.
     */
    public function testRoundtripPreservesTheWholeFigure(): void
    {
        $this->assertSame(['raw-preserved'], array_column($this->rows(self::HTML, 'roundtrip'), 'code'));

        $rendered = $this->reread(self::HTML, 'roundtrip');
        $this->assertStringContainsString('<figcaption>FigCap</figcaption>', $rendered);
        $this->assertStringContainsString('<caption>TableCap</caption>', $rendered);
        $this->assertStringContainsString('id="f"', $rendered);
    }

    #[DataProvider('lossyModes')]
    public function testTheLossyModesRebuildRatherThanPreserve(string $mode): void
    {
        $carve = $this->carve(self::HTML, $mode);

        $this->assertStringNotContainsString('=html', $carve);
        $this->assertSame("{#f}\n| a |\n^ TableCap\n\nFigCap\n", $carve);
        $this->assertStringContainsString('<p>FigCap</p>', $this->reread(self::HTML, $mode));
    }

    /**
     * ONE ROW, NAMING THE FIGCAPTION. Not `structure-unspellable`, which is the
     * row for the wrapper that disappears when a figure around a table is BUILT
     * - nothing is built here - and not `table-degraded`, which says a table was
     * degraded and nothing about where a caption went.
     */
    #[DataProvider('lossyModes')]
    public function testTheLostCaptionRoleIsDeclaredOnce(string $mode): void
    {
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => self::DETACHED,
                    'severity' => 'warning',
                    'path' => '/figure[1]/figcaption[2]',
                ],
            ],
            $this->rows(self::HTML, $mode),
        );
    }

    /**
     * The figure's own attributes ride onto the table, exactly as they do on the
     * ordinary rebuild (carve-php#1728). carve-js used to drop them here with no
     * row at all, which is the silence `markup-carve/carve#1721` removed.
     */
    #[DataProvider('lossyModes')]
    public function testTheWrittenTableCarriesTheFiguresId(string $mode): void
    {
        $this->assertStringContainsString('<table id="f">', $this->reread(self::HTML, $mode));
    }

    /**
     * A CAPTION THAT SPELLS NOTHING IS NOT A CAPTION (`markup-carve/carve-js#1423`),
     * so an empty `<caption>` fills no slot and the figure's caption takes it -
     * the ordinary rebuild, unchanged, with no second caption to detach.
     */
    #[DataProvider('modes')]
    public function testAnEmptyTableCaptionLeavesTheSlotToTheFigure(string $mode): void
    {
        $html = '<figure id="f"><table><caption></caption><tr><td>a</td></tr></table>'
            . '<figcaption>Cap</figcaption></figure>';

        $this->assertSame("{#f}\n| a |\n^ Cap\n", $this->carve($html, $mode));
        $this->assertStringNotContainsString('^', $this->reread($html, $mode));
    }

    /**
     * And an empty `<figcaption>` is not a caption to detach, so the wrapper
     * unwraps to the table it holds - the boundary all three engines draw.
     */
    #[DataProvider('modes')]
    public function testAnEmptyFigcaptionUnwrapsInstead(string $mode): void
    {
        $html = '<figure id="f"><table><caption>TableCap</caption><tr><td>a</td></tr></table>'
            . '<figcaption></figcaption></figure>';

        $this->assertSame("| a |\n^ TableCap\n", $this->carve($html, $mode));
        $this->assertSame(
            ['element-unwrapped', 'attribute-dropped'],
            array_column($this->rows($html, $mode), 'code'),
        );
    }

    /**
     * A `<figcaption>` THAT CONVERTS TO NOTHING WAS NOT DETACHED, so it does not
     * get the row saying it was. The DOM test that picks this arm answers yes to
     * an empty `<span>` - it has to, or the caption is converted twice and every
     * flattened element inside it is reported twice - and what happens then is
     * the ordinary rebuild, which is what the row has to say.
     */
    public function testACaptionThatWritesNothingIsNotReportedAsDetached(): void
    {
        $html = '<figure id="f"><table><caption>T</caption><tr><td>a</td></tr></table>'
            . '<figcaption><span></span></figcaption></figure>';

        $this->assertSame("{#f}\n| a |\n^ T\n", $this->carve($html, 'safe'));
        $this->assertSame(['structure-unspellable'], array_column($this->rows($html, 'safe'), 'code'));
    }

    /**
     * AND THE SLOT IS DECIDED ON WHAT THE TABLE WROTE, not on what its
     * `<caption>` holds. A `<caption>` holding only an empty `<span>` answers
     * yes to the DOM test - which is the test that has to be used, because
     * converting a caption to ask a question about it reports everything inside
     * it twice - and writes no `^ ` line at all. Reading the DOM answer as final
     * detached a caption the table had left room for.
     */
    #[DataProvider('lossyModes')]
    public function testATableCaptionThatWritesNothingLeavesTheSlotFree(string $mode): void
    {
        $html = '<figure id="f"><table><caption><span></span></caption><tr><td>a</td></tr></table>'
            . '<figcaption>FigCap</figcaption></figure>';

        $this->assertSame("{#f}\n| a |\n^ FigCap\n", $this->carve($html, $mode));
        $this->assertStringContainsString('<caption>FigCap</caption>', $this->reread($html, $mode));
    }

    /**
     * THE CONTROL. A figure around a table with NO caption of its own is not in
     * this ruling at all: one caption for one slot, the figure's caret lands on
     * the table, nothing changes. Without it the fix could satisfy every
     * assertion above by routing all figure-wrapped tables through the new arm.
     */
    #[DataProvider('modes')]
    public function testAnUncaptionedTableRebuildsUnchanged(string $mode): void
    {
        $html = '<figure id="f"><table><tr><td>a</td></tr></table><figcaption>Cap</figcaption></figure>';

        $this->assertSame("{#f}\n| a |\n^ Cap\n", $this->carve($html, $mode));
        $this->assertSame(['structure-unspellable'], array_column($this->rows($html, $mode), 'code'));
        $this->assertStringContainsString('<caption>Cap</caption>', $this->reread($html, $mode));
    }

    /**
     * THE TABLE IS THE ONLY TARGET IN THE COLLISION. A quote, a code block and
     * an image have no caption of their own, so the figure's `^ ` line is
     * uncontested on all three - and a swept check is what says so, rather than
     * a reading of the target list that goes stale the day one is added.
     *
     * @return array<string, array{0: string}>
     */
    public static function uncontestedTargets(): array
    {
        return [
            'quote' => ['<blockquote><p>q</p></blockquote>'],
            'code block' => ['<pre><code>x</code></pre>'],
            'image' => ['<img src="a.png" alt="a">'],
        ];
    }

    #[DataProvider('uncontestedTargets')]
    public function testEveryOtherFigureTargetIsUncontested(string $target): void
    {
        $html = '<figure id="f">' . $target . '<figcaption>FigCap</figcaption></figure>';

        $this->assertSame([], $this->rows($html, 'safe'));
        $this->assertStringContainsString('<figcaption>FigCap</figcaption>', $this->reread($html, 'safe'));
    }
}
