<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `<figure>` around a table writes its caption ON the table, and says so.
 *
 * `<table><caption>` is the idiomatic HTML for a captioned table, so this shape
 * REBUILDS rather than preserving - the one deliberate carve-out in the figure
 * rule (`markup-carve/carve#1704`). The rebuild had not been written: the shape
 * reached the generic fallback, which writes a caption's content as ordinary
 * BLOCKS, so `Cap` left the figure and landed as its own paragraph. The caption
 * stopped being the table's caption and became body prose, a paragraph the
 * document never had appeared in its place, and the report was EMPTY either way
 * (carve-php#1722).
 *
 * A `^ ` line after the pipe rows reads back as the table's own `<caption>`,
 * which is the closest the syntax comes and what carve-js and carve-rs both
 * write. The figure is still gone, so the rebuild is a ceiling rather than a
 * lossless spelling, and `structure-unspellable` is the row that declares it.
 *
 * The wording is carve-js's, verbatim. carve-rs reports the same code at the
 * same severity but says the written table carries the figure's ATTRIBUTES too,
 * which is not true of this engine - it drops a figure's own attributes on every
 * rebuild arm and reports each one separately, so that sentence would be a false
 * statement about this output. `theFiguresOwnAttributesAreStillDropped` pins
 * that as a measured divergence rather than leaving it implied.
 */
class AFigureAroundATableKeepsItsCaptionTest extends TestCase
{
    /**
     * @var string
     */
    protected const MESSAGE = 'A figure wrapping a table has no Carve spelling; '
        . 'the caption is written on the table, which renders <caption> inside it';

    /**
     * @return list<array{code: string, message: string, severity: string, path: string}>
     */
    protected function rows(string $html, string $mode = 'roundtrip'): array
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

    protected function carve(string $html, string $mode = 'roundtrip'): string
    {
        return (new HtmlToCarve(importMode: $mode))->convert($html);
    }

    /**
     * The ticket's own input, and both halves of its claim: the caption line is
     * written, and the wrapper it left is declared.
     */
    public function testWritesTheCaptionLineAndDeclaresTheWrapper(): void
    {
        $html = '<figure><table><tr><td>a</td></tr></table><figcaption>Cap</figcaption></figure>';

        $this->assertSame("| a |\n^ Cap\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'structure-unspellable',
                    'message' => self::MESSAGE,
                    'severity' => 'warning',
                    'path' => '/figure[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * THE ASSOCIATION IS WHAT WAS LOST, so the row that matters is what the
     * source reads back as. A detached paragraph is body prose; a caption line
     * is the table's `<caption>`.
     */
    public function testTheCaptionReadsBackInsideTheTable(): void
    {
        $carve = $this->carve('<figure><table><tr><td>a</td></tr></table><figcaption>Cap</figcaption></figure>');
        $rendered = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString('<caption>Cap</caption>', $rendered);
        $this->assertStringNotContainsString('<p>Cap</p>', $rendered);
    }

    /**
     * A header row is part of the table, not of the figure, so the caption line
     * still lands after the whole of it.
     */
    public function testAHeaderRowDoesNotDisplaceTheCaptionLine(): void
    {
        $html = '<figure><table><thead><tr><th>H</th></tr></thead><tbody><tr><td>a</td></tr></tbody></table>'
            . '<figcaption>Cap</figcaption></figure>';

        $this->assertSame("|= H |\n| a |\n^ Cap\n", $this->carve($html));
        $this->assertSame([self::MESSAGE], array_column($this->rows($html), 'message'));
    }

    /**
     * The caption is what the content is captioned WITH, not content, so where
     * it stands among the children decides nothing.
     */
    public function testACaptionWrittenFirstIsStillTheCaption(): void
    {
        $html = '<figure><figcaption>Cap</figcaption><table><tr><td>a</td></tr></table></figure>';

        $this->assertSame("| a |\n^ Cap\n", $this->carve($html));
        $this->assertSame([self::MESSAGE], array_column($this->rows($html), 'message'));
    }

    /**
     * The rebuild is not a mode: `safe` has no preserved block to fall back on
     * either, and the caption belongs on the table in every mode.
     *
     * @return array<string, array{0: string}>
     */
    public static function modes(): array
    {
        return ['safe' => ['safe'], 'semantic' => ['semantic'], 'roundtrip' => ['roundtrip']];
    }

    #[DataProvider('modes')]
    public function testTheRebuildIsTheSameInEveryMode(string $mode): void
    {
        $html = '<figure><table><tr><td>a</td></tr></table><figcaption>Cap</figcaption></figure>';

        $this->assertSame("| a |\n^ Cap\n", $this->carve($html, $mode));
        $this->assertSame([self::MESSAGE], array_column($this->rows($html, $mode), 'message'));
    }

    /**
     * A CAPTION IS WHAT MAKES A FIGURE (PART 9 §4b). With none there is nothing
     * to write on the table, so the wrapper unwraps to the bare table and the
     * row is the unwrap rather than this one - the boundary carve-js and
     * carve-rs both draw.
     */
    public function testAnUncaptionedTableFigureUnwrapsInstead(): void
    {
        $html = '<figure><table><tr><td>a</td></tr></table></figure>';

        $this->assertSame("| a |\n", $this->carve($html));
        $this->assertSame(['element-unwrapped'], array_column($this->rows($html), 'code'));
    }

    /**
     * THE CARVE-OUT IS EXACTLY AS WIDE AS THE REBUILD. A figure a table merely
     * stands IN rebuilds nothing, so it is preserved like any other shape with
     * no spelling instead of dropping through to the fallback and losing its
     * caption to a paragraph. carve-rs preserves both of these too.
     *
     * @return array<string, array{0: string}>
     */
    public static function figuresATableOnlyStandsIn(): array
    {
        return [
            'beside a paragraph' => [
                '<figure><table><tr><td>a</td></tr></table><p>x</p><figcaption>Cap</figcaption></figure>',
            ],
            'beside a second table' => [
                '<figure><table><tr><td>a</td></tr></table><table><tr><td>b</td></tr></table>'
                    . '<figcaption>Cap</figcaption></figure>',
            ],
        ];
    }

    #[DataProvider('figuresATableOnlyStandsIn')]
    public function testAFigureThatCannotRebuildIsPreservedInstead(string $html): void
    {
        $this->assertSame(['raw-preserved'], array_column($this->rows($html), 'code'));
        $this->assertStringNotContainsString("\n\nCap\n", $this->carve($html));
    }

    /**
     * The measured divergence from both sibling engines: they write the figure's
     * own attributes onto the table, this engine drops them. It drops them on
     * the image and quote arms too, so it is one pre-existing behavior rather
     * than something this rebuild introduces - and the drop is DECLARED, which
     * is the ceiling it sits inside.
     */
    public function testTheFiguresOwnAttributesAreStillDropped(): void
    {
        $html = '<figure id="f" class="c"><table><tr><td>a</td></tr></table><figcaption>Cap</figcaption></figure>';

        $this->assertSame("| a |\n^ Cap\n", $this->carve($html));
        $this->assertSame(
            ['structure-unspellable', 'attribute-dropped', 'attribute-dropped'],
            array_column($this->rows($html), 'code'),
        );
    }
}
