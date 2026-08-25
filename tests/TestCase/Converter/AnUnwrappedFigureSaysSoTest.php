<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `<figure>` this importer unwraps now says that it did.
 *
 * A CAPTION IS WHAT MAKES A FIGURE (PART 9 §4b), so a wrapper that writes no
 * `^ ` line did not survive as a figure whatever else came through. The target
 * is in the output, the element around it is not, and the re-render shows a
 * bare list, image, quote or code block where the input had a figure. No
 * content is lost and there is no other output to write - an uncaptioned
 * wrapper genuinely has no Carve spelling - so the whole of the defect was the
 * silence: the report came back EMPTY, while this engine reports the equivalent
 * unwrap for every other element (carve-php#1723).
 *
 * The row is `element-unwrapped` at `info` reading
 * `Unwrapped unsupported <figure> element`, which is carve-rs's row byte for
 * byte and this file's own wording for every other unwrapped element.
 *
 * ALL THREE ENGINES NOW AGREE ON IT. carve-js used to report the same code at
 * `warning` with a figure-specific message split in two by target, and this
 * file recorded that as a measured divergence. The ruling in
 * markup-carve/carve#1716 closed it and carve-js#1477 landed the wording, so
 * the figure row there is the generic sentence at `info` too - measured on
 * carve-js `e0b1332`, built from source, byte for byte identical to this
 * engine's.
 *
 * What the last test below guards is therefore the RULE rather than the words:
 * a figure that did not survive is spelled like every other element that did
 * not survive, with no severity and no sentence of its own. That is the
 * property the ruling settled, and it is checked against this engine's own
 * generic row so that reintroducing a figure-specific wording fails here.
 *
 * The CROSS-ENGINE half is gated where every other import contract is - the
 * shared `tests/spec/tests/html-import` fixtures both engines run - and no
 * fixture there covers a figure unwrap yet (carve-php#1735).
 */
class AnUnwrappedFigureSaysSoTest extends TestCase
{
    /**
     * @var string
     */
    protected const MESSAGE = 'Unwrapped unsupported <figure> element';

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
     * The ticket's own input, and the whole of its claim: the list survives and
     * the wrapper does not, and until this fix the report said nothing at all.
     */
    public function testNamesTheUnwrappedFigureItsSeverityAndItsPath(): void
    {
        $html = '<figure><ul><li>a</li></ul></figure>';

        $this->assertSame("- a\n", $this->carve($html));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => self::MESSAGE,
                    'severity' => 'info',
                    'path' => '/figure[1]',
                ],
            ],
            $this->rows($html),
        );
    }

    /**
     * The three arms that CAN write a caption line reach the same outcome when
     * there is no caption to write: the target comes through on its own and the
     * figure is gone from the re-render. Each was silent too, which is why the
     * fix is not scoped to the generic fallback.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function uncaptionedTargets(): array
    {
        return [
            'image' => ['<figure><img src="i.png" alt="x"></figure>', "![x](i.png)\n"],
            'quote' => ['<figure><blockquote><p>q</p></blockquote></figure>', "> q\n"],
            'code' => ['<figure><pre><code>c</code></pre></figure>', "```\nc\n```\n"],
            'table' => ['<figure><table><tr><td>a</td></tr></table></figure>', "| a |\n"],
            'paragraph' => ['<figure><p>a</p></figure>', "a\n"],
        ];
    }

    #[DataProvider('uncaptionedTargets')]
    public function testEveryUncaptionedTargetReportsTheUnwrap(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->carve($html));
        $this->assertSame(
            [['code' => 'element-unwrapped', 'message' => self::MESSAGE, 'severity' => 'info', 'path' => '/figure[1]']],
            $this->rows($html),
        );
    }

    /**
     * An empty `<figcaption>` is not a caption: it spells nothing, so no `^ `
     * line is written and the wrapper leaves exactly as it does with no caption
     * element at all. The row follows the OUTPUT rather than the presence of the
     * element.
     */
    public function testAnEmptyCaptionIsNotACaption(): void
    {
        $html = '<figure><ul><li>a</li></ul><figcaption></figcaption></figure>';

        $this->assertSame("- a\n", $this->carve($html));
        $this->assertSame([self::MESSAGE], array_column($this->rows($html), 'message'));
    }

    /**
     * Outside `roundtrip` there is no preserved block to keep a captioned
     * wrapper either, so the caption comes through as prose and the figure
     * unwraps. carve-js and carve-rs both report the unwrap here as well.
     */
    public function testACaptionedWrapperOutsideRoundtripReportsTheUnwrapToo(): void
    {
        $html = '<figure><ul><li>a</li></ul><figcaption>Cap</figcaption></figure>';

        $this->assertSame("- a\n\nCap\n", $this->carve($html, 'safe'));
        $this->assertSame([self::MESSAGE], array_column($this->rows($html, 'safe'), 'message'));
    }

    /**
     * The row stands AHEAD of the rows about the element's own attributes,
     * which is the order both sibling engines report: what happened to the
     * element first, what happened to what it carried after.
     */
    public function testTheUnwrapIsReportedBeforeTheAttributesItCarried(): void
    {
        $rows = $this->rows('<figure id="f"><ul><li>a</li></ul></figure>');

        $this->assertSame(['element-unwrapped', 'attribute-dropped'], array_column($rows, 'code'));
        $this->assertSame(self::MESSAGE, $rows[0]['message']);
    }

    /**
     * A figure that KEEPS its caption is still a figure, and reports nothing.
     * This is the boundary the fix must not cross: the row follows the missing
     * `^ ` line, not the tag.
     */
    public function testACaptionedFigureThatSurvivesReportsNothing(): void
    {
        $html = '<figure><img src="i.png" alt="x"><figcaption>Cap</figcaption></figure>';

        $this->assertSame("![x](i.png)\n^ Cap\n", $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * A figure kept BYTE FOR BYTE was not unwrapped, and must not collect a row
     * saying it was: `raw-preserved` is the whole report for that arm
     * (carve-php#1713).
     */
    public function testAPreservedFigureIsNotAnUnwrappedOne(): void
    {
        $rows = $this->rows('<figure><p>a</p><figcaption>Cap</figcaption></figure>');

        $this->assertSame(['raw-preserved'], array_column($rows, 'code'));
    }

    /**
     * A trial write is a QUESTION, not an exit. Probing a figure's target must
     * not leave a record behind that declares an unwrap the real write never
     * took: this figure is kept BYTE FOR BYTE, so `raw-preserved` is the whole
     * of its report and an `element-unwrapped` beside it would be a false
     * statement about a success.
     */
    public function testATrialWriteLeavesNoRecordBehind(): void
    {
        $html = '<figure><noscript><img src="i.png" alt="x"></noscript><figcaption>Cap</figcaption></figure>';

        $this->assertSame(['raw-preserved'], array_column($this->rows($html), 'code'));
    }

    /**
     * THE SECOND HALF OF THE SAME DEFECT, and the one this engine was alone in
     * (ruling markup-carve/carve#1723): `element-unwrapped` fires when an element did
     * not survive into the output, that is the whole condition, and nesting
     * does not exempt it.
     *
     * Two `<figure>` elements go in and ONE comes out - the caption line on the
     * image makes a figure of it - so one of the two is gone. The outer is the
     * survivor, because the caption that rebuilt the figure was the outer one's,
     * and the inner is the element with nothing left standing for it.
     *
     * IT REACHES NEITHER CALL SITE IN `processFigure()`. The outer figure looks
     * PAST its body to the image behind it, so the inner element is never
     * written and never records anything; the outer writes its caption line and
     * is correctly silent. That is why the row is recorded where the target is
     * chosen rather than where a figure is written.
     *
     * ALL THREE MODES, because the divergence was in all three: the shape
     * rebuilds as an image plus a caption line everywhere, so `roundtrip` has
     * nothing to preserve and takes the same arm.
     *
     * @param string $mode
     */
    #[DataProvider('everyImportMode')]
    public function testAnInnerFigureThatDidNotSurviveIsReported(string $mode): void
    {
        $html = '<figure><figure><img src="a.png"></figure><figcaption>Cap</figcaption></figure>';

        $this->assertSame("![](a.png)\n^ Cap\n", $this->carve($html, $mode));
        $this->assertSame(
            [
                [
                    'code' => 'element-unwrapped',
                    'message' => self::MESSAGE,
                    'severity' => 'info',
                    'path' => '/figure[1]/figure[1]',
                ],
            ],
            $this->rows($html, $mode),
        );
    }

    /**
     * AN UNWRAPPED ELEMENT INSIDE ANOTHER UNWRAPPED ONE IS STILL AN UNWRAPPED
     * ELEMENT. With no caption anywhere neither wrapper is a figure, so both
     * are gone and both are named, outer first - the report walk is in document
     * order. A three-deep nest under one caption reports the two INSIDE the
     * survivor and not the survivor itself, which is the same rule read twice.
     *
     * @param string $html
     * @param list<string> $paths
     */
    #[DataProvider('nestedFigures')]
    public function testEveryFigureThatDidNotSurviveIsNamedOnce(string $html, array $paths): void
    {
        $this->assertSame("![](a.png)\n", substr($this->carve($html, 'safe'), 0, 11));
        $this->assertSame($paths, array_column($this->rows($html, 'safe'), 'path'));
        $this->assertSame(
            array_fill(0, count($paths), self::MESSAGE),
            array_column($this->rows($html, 'safe'), 'message'),
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function nestedFigures(): array
    {
        return [
            'neither carries a caption' => [
                '<figure><figure><img src="a.png"></figure></figure>',
                ['/figure[1]', '/figure[1]/figure[1]'],
            ],
            'the outer caption spells nothing' => [
                '<figure><figure><img src="a.png"></figure><figcaption></figcaption></figure>',
                ['/figure[1]', '/figure[1]/figure[1]'],
            ],
            'two inside a captioned outer' => [
                '<figure><figure><figure><img src="a.png"></figure></figure><figcaption>Cap</figcaption></figure>',
                ['/figure[1]/figure[1]', '/figure[1]/figure[1]/figure[1]'],
            ],
        ];
    }

    /**
     * THE CONTROL, and the way to get this wrong. The condition is "this
     * element did not survive", never "this element was a figure": a figure
     * whose image sits behind an ordinary wrapper looks past that wrapper the
     * same way, and reports nothing, because the one figure in the input is the
     * one in the output. An implementation that walked for `<figure>` targets
     * generally, or that reported every element passed over, turns a missing
     * row into a spurious one here.
     *
     * An inner figure that keeps its OWN caption survives as a figure too, so
     * only the outer - which lost its caption to prose - is named.
     *
     * READ OVER THE FIGURE ROWS ALONE. A `<picture>` passed over already
     * collects a row of its own, from the generic handler that answers for
     * every element this importer has no construct for, and that row is not
     * this one: the assertion is that no FIGURE was declared lost where none
     * was.
     *
     * @param string $html
     * @param list<string> $paths
     */
    #[DataProvider('figuresThatSurvive')]
    public function testAnElementThatSurvivedIsNotReported(string $html, array $paths): void
    {
        $rows = array_values(array_filter(
            $this->rows($html, 'safe'),
            static fn (array $row): bool => $row['message'] === self::MESSAGE,
        ));

        $this->assertSame($paths, array_column($rows, 'path'));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function figuresThatSurvive(): array
    {
        return [
            'a paragraph wrapper is passed over silently' => [
                '<figure><p><img src="a.png"></p><figcaption>Cap</figcaption></figure>',
                [],
            ],
            'a picture wrapper is passed over silently' => [
                '<figure><picture><img src="a.png"></picture><figcaption>Cap</figcaption></figure>',
                [],
            ],
            'an inner figure with its own caption survives' => [
                '<figure><figure><img src="a.png"><figcaption>Inner</figcaption></figure><figcaption>Cap</figcaption></figure>',
                ['/figure[1]'],
            ],
        ];
    }

    /**
     * THE TWO NEIGHBOURING SHAPES THE RULING LEFT ALONE, pinned here so this
     * change cannot move them. They converged across all three engines before
     * this one did (markup-carve/carve#1723): both report the outer unwrap in the lossy
     * modes and neither reports it in `roundtrip`, where the element is kept
     * byte for byte instead. A fix that only checked the new row would pass
     * while silently rewriting these.
     *
     * @param string $html
     */
    #[DataProvider('theNeighbouringShapes')]
    public function testTheNeighbouringShapesDoNotMove(string $html): void
    {
        foreach (['safe', 'semantic'] as $mode) {
            $this->assertSame(
                [['code' => 'element-unwrapped', 'message' => self::MESSAGE, 'severity' => 'info', 'path' => '/figure[1]']],
                $this->rows($html, $mode),
            );
        }

        $this->assertSame(['raw-preserved'], array_column($this->rows($html, 'roundtrip'), 'code'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function theNeighbouringShapes(): array
    {
        return [
            'two body blocks under one caption' => ['<figure><p>a</p><p>b</p><figcaption>Cap</figcaption></figure>'],
            'a div body' => ['<figure><div>x</div><figcaption>Cap</figcaption></figure>'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyImportMode(): array
    {
        return ['safe' => ['safe'], 'semantic' => ['semantic'], 'roundtrip' => ['roundtrip']];
    }

    /**
     * A FIGURE IS NOT A SPECIAL CASE OF ITSELF. The row a lost `<figure>`
     * collects is the row any lost element collects: same code, same severity,
     * and the same sentence with the tag substituted into it.
     *
     * Measured against this engine's OWN generic row rather than against a
     * quoted literal, because a literal is what let the previous version of
     * this test die. It asserted `assertNotSame` on a carve-js message carve-js
     * had already stopped emitting, so it passed for every string except the
     * one string it could no longer see - a check that could not fail while it
     * documented a divergence that had closed (carve-php#1735, the shape
     * markup-carve/carve#755 catalogs).
     *
     * Now the two rows are compared to each other, so reintroducing a
     * figure-specific severity or wording fails here, and so does changing the
     * generic sentence without changing the figure with it.
     */
    public function testAFigureUnwrapIsSpelledLikeEveryOtherUnwrap(): void
    {
        $figure = $this->rows('<figure><ul><li>a</li></ul></figure>')[0];
        $section = $this->rows('<section><p>a</p></section>')[0];

        $this->assertSame('element-unwrapped', $section['code']);
        $this->assertSame($section['code'], $figure['code']);
        $this->assertSame('info', $section['severity']);
        $this->assertSame($section['severity'], $figure['severity']);
        $this->assertSame(
            str_replace('<section>', '<figure>', $section['message']),
            $figure['message'],
        );
        $this->assertSame(self::MESSAGE, $figure['message']);
    }
}
