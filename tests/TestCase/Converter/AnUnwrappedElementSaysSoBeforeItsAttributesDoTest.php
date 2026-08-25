<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two halves of one defect: the `element-unwrapped` row had no single site.
 *
 * This file wrote the row from two places in the walk. A register consulted
 * BEFORE the attribute loop covered the sectioning wrappers and the unwrapped
 * figures; a generic outcome test made AFTER it covered every element this
 * importer has no mapping for. Neither site covered the other's case, and both
 * symptoms in carve-php#1737 fall out of that:
 *
 * - A `<section>` is listed as a KNOWN element, so the generic test never asked
 *   about it, and nothing put it in the register either. It fell through the gap
 *   between the two sites and reported nothing at all - for every shape,
 *   attributed or bare, at every depth.
 * - A `<video>` is not a known element, so its row came from the LATE site and
 *   stood after the rows naming its attributes. A consumer reading rows in order
 *   was told what happened to the `src` before it was told the `<video>` was
 *   gone.
 *
 * markup-carve/carve#1723 states the condition over the INPUT: the row fires
 * when an element did not survive into the output, and nesting does not exempt
 * it. A `<section>` that unwraps did not survive - Carve has no spelling for
 * one, and what reaches the output is the heading the renderer will build a new
 * section around. So the register takes it, which gives it the row AND gives the
 * row the position every other one already had.
 *
 * carve-js and carve-rs agree with each other on both halves, and this file is
 * measured against them.
 */
class AnUnwrappedElementSaysSoBeforeItsAttributesDoTest extends TestCase
{
    /**
     * @return list<array{code: string, message: string, severity: string, path: string}>
     */
    protected function rows(string $html, string $mode = 'safe'): array
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

    protected function carve(string $html, string $mode = 'safe'): string
    {
        return (new HtmlToCarve(importMode: $mode))->convert($html);
    }

    /**
     * THE FIRST HALF, on the ticket's own input. The element row is there, and
     * it stands ahead of the two attributes it took with it.
     */
    public function testASectionWithAttributesReportsTheUnwrapBeforeThem(): void
    {
        $html = '<section id="f" class="c"><p>x</p></section>';

        $this->assertSame("x\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => 'Unwrapped unsupported <section> element',
                    'severity' => 'info',
                    'path' => '/section[1]',
                ],
                [
                    'code' => 'attribute-dropped',
                    'message' => 'Dropped unsupported attribute id on <section>',
                    'severity' => 'info',
                    'path' => '/section[1]',
                ],
                [
                    'code' => 'attribute-dropped',
                    'message' => 'Dropped unsupported attribute class on <section>',
                    'severity' => 'info',
                    'path' => '/section[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * A BARE ONE REPORTS TOO. There is nothing for the attribute rows to say,
     * and the element is gone all the same - the row is about the element.
     */
    public function testABareSectionReportsTheUnwrap(): void
    {
        $html = '<section><p>x</p></section>';

        $this->assertSame("x\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => 'Unwrapped unsupported <section> element',
                    'severity' => 'info',
                    'path' => '/section[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * NESTING DOES NOT EXEMPT IT, which is the half of carve#1723's ruling that
     * closed the inner-figure shape. Two sections go in, neither comes back, and
     * each one is named at its own path.
     */
    public function testEachNestedSectionIsNamedAtItsOwnPath(): void
    {
        $rows = $this->rows('<section id="a"><section id="b"><p>x</p></section></section>');

        $this->assertSame(
            ['element-unwrapped', 'attribute-dropped', 'element-unwrapped', 'attribute-dropped'],
            array_column($rows, 'code'),
        );
        $this->assertSame(
            ['/section[1]', '/section[1]', '/section[1]/section[1]', '/section[1]/section[1]'],
            array_column($rows, 'path'),
        );
    }

    /**
     * IT IS NOT A MODE. Both sibling engines unwrap a `<section>` in all three,
     * and none of them preserves one as raw HTML.
     *
     * @return array<string, array{0: string}>
     */
    public static function modes(): array
    {
        return ['safe' => ['safe'], 'semantic' => ['semantic'], 'roundtrip' => ['roundtrip']];
    }

    #[DataProvider('modes')]
    public function testTheSectionRowIsTheSameInEveryMode(string $mode): void
    {
        $rows = $this->rows('<section id="f" class="c"><p>x</p></section>', $mode);

        $this->assertSame('element-unwrapped', $rows[0]['code']);
        $this->assertSame('Unwrapped unsupported <section> element', $rows[0]['message']);
        $this->assertSame('info', $rows[0]['severity']);
    }

    /**
     * A SECTION AROUND A HEADING IS STILL AN UNWRAP, and this is the shape where
     * it is least obvious. The authored id comes back on the heading, so the
     * ATTRIBUTE survived and is correctly silent - but the element the author
     * wrote is gone either way, and a row that depended on an attribute would be
     * a third answer where both siblings already agree.
     */
    public function testASectionAroundAHeadingStillReportsTheUnwrap(): void
    {
        $html = '<section id="zz"><h1>A</h1><p>b</p></section>';

        $this->assertSame("{#zz}\n# A\n\nb\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => 'Unwrapped unsupported <section> element',
                    'severity' => 'info',
                    'path' => '/section[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * THE ONE EXEMPTION, and it is on the ROLE rather than on the tag. A
     * `<section role="doc-endnotes">` is DERIVED: the renderer writes one around
     * the notes whenever a document has any, so the author never wrote it and
     * nothing of theirs goes when it is unwrapped (carve-php#1588,
     * markup-carve/carve#1558). Both sibling engines scope it exactly this way,
     * and it holds whichever way the import then goes - rebuilt into footnote
     * definitions, or degraded to the `<hr>` and `<ol>` it is built from.
     *
     * @return array<string, array{0: string}>
     */
    public static function endnotesSections(): array
    {
        return [
            'rebuilt into footnote definitions' => [
                '<p>a<a href="#fn1" id="fnref1" role="doc-noteref"><sup>1</sup></a></p>'
                    . '<section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1">'
                    . '<p>n<a href="#fnref1" role="doc-backlink">back</a></p></li></ol></section>',
            ],
            'degraded to the hr and ol it is built from' => [
                '<section role="doc-endnotes"><hr><ol><li id="fn1"><p>n</p></li></ol></section>',
            ],
        ];
    }

    #[DataProvider('endnotesSections')]
    public function testADerivedEndnotesSectionStaysSilent(string $html): void
    {
        $this->assertSame(
            [],
            array_values(array_filter(
                $this->rows($html),
                static fn (array $row): bool => $row['code'] === 'element-unwrapped',
            )),
        );
    }

    /**
     * THE SECOND HALF. `<video>` reaches the generic outcome site, and that site
     * used to write its row last.
     */
    public function testAVideoReportsTheUnwrapBeforeItsAttributes(): void
    {
        $html = '<video src="v.mp4" controls class="a b"><p>fallback</p></video>';

        $this->assertSame("fallback\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => 'Replaced unsupported <video> element with Carve span metadata',
                    'severity' => 'info',
                    'path' => '/video[1]',
                ],
                [
                    'code' => 'attribute-dropped',
                    'message' => 'Dropped unsupported attribute src on <video>',
                    'severity' => 'info',
                    'path' => '/video[1]',
                ],
                [
                    'code' => 'attribute-dropped',
                    'message' => 'Dropped unsupported attribute class on <video>',
                    'severity' => 'info',
                    'path' => '/video[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * IT WAS NEVER ONLY `<video>`. Every element reaching the generic outcome
     * site wrote its row last, so the ordering is asserted over the family.
     *
     * @return array<string, array{0: string}>
     */
    public static function genericallyUnwrappedElements(): array
    {
        return [
            'video' => ['<video id="k" class="c"><p>x</p></video>'],
            'canvas' => ['<canvas id="k" class="c">x</canvas>'],
            'marquee' => ['<marquee id="k" class="c">x</marquee>'],
            'object' => ['<object id="k" class="c"><p>x</p></object>'],
        ];
    }

    #[DataProvider('genericallyUnwrappedElements')]
    public function testEveryGenericallyUnwrappedElementReportsItsRowFirst(string $html): void
    {
        $codes = array_column($this->rows($html), 'code');

        $this->assertSame('element-unwrapped', $codes[0]);
        $this->assertSame(
            ['attribute-dropped', 'attribute-dropped'],
            array_slice($codes, 1),
        );
    }

    /**
     * A DROPPED ELEMENT MOVES WITH IT, because it is the same site. The element
     * row is the one naming what happened to the element, and it belongs ahead
     * of the rows naming what happened to what it carried whichever of the two
     * outcomes the element had. This engine's other `element-dropped` sites -
     * an active element, a `<colgroup>`, an orphan caption - already report the
     * element first and then stop.
     */
    public function testADroppedElementReportsItsRowFirstToo(): void
    {
        $rows = $this->rows('<p>a<progress id="p" value="1"></progress>b</p>');

        $this->assertSame(
            ['element-dropped', 'attribute-dropped', 'attribute-dropped'],
            array_column($rows, 'code'),
        );
        $this->assertSame('Dropped unsupported <progress> element', $rows[0]['message']);
        $this->assertSame('warning', $rows[0]['severity']);
    }

    /**
     * THE CONTROL. The elements that already reported correctly did not move:
     * the sectioning wrappers and the unwrapped figures come from the early
     * site, which this change did not touch, and a `<div>` that maps to a
     * container fence still reports nothing at all.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function unmovedShapes(): array
    {
        return [
            'article' => ['<article id="k" class="c"><p>x</p></article>', ['element-unwrapped', 'attribute-dropped', 'attribute-dropped']],
            'nav' => ['<nav id="k"><p>x</p></nav>', ['element-unwrapped', 'attribute-dropped']],
            'main' => ['<main id="k"><p>x</p></main>', ['element-unwrapped', 'attribute-dropped']],
            'uncaptioned figure' => ['<figure id="g"><img src="a.png" alt="a"></figure>', ['element-unwrapped', 'attribute-dropped']],
            'a div is a container fence' => ['<div id="d" class="k"><p>x</p></div>', []],
            'a colgroup is dropped whole' => ['<table id="t"><colgroup><col span="2"></colgroup><tr><td>a</td></tr></table>', ['element-dropped']],
        ];
    }

    /**
     * @param string $html
     * @param list<string> $codes
     */
    #[DataProvider('unmovedShapes')]
    public function testTheShapesThatAlreadyReportedCorrectlyDidNotMove(string $html, array $codes): void
    {
        $this->assertSame($codes, array_column($this->rows($html), 'code'));
    }
}
