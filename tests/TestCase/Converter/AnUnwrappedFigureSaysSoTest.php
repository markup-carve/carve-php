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
 * byte and this file's own wording for every other unwrapped element. carve-js
 * reports the same CODE at `warning` with a figure-specific message, split in
 * two by whether the target was one it can write a caption line for. That split
 * follows carve-js's target set rather than this one's - carve-js writes a
 * caption line on a bare paragraph and this engine preserves that shape instead
 * - so copying its words would import a distinction this engine does not draw.
 * `theWordingDivergesFromCarveJs` below pins that as a measured difference
 * rather than leaving it to the next sweep.
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
     * The measured difference from carve-js, kept in the suite so it is a fact
     * rather than a comment: carve-js reports the same code at `warning` with a
     * figure-specific message. carve-rs reports what this engine now reports.
     */
    public function testTheWordingDivergesFromCarveJs(): void
    {
        $rows = $this->rows('<figure><ul><li>a</li></ul></figure>');

        $this->assertSame('info', $rows[0]['severity']);
        $this->assertNotSame('Unwrapped figure without a representable target', $rows[0]['message']);
    }
}
