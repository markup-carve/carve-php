<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * The import report does not announce a loss that did not happen.
 *
 * `<blockquote cite="u">` is KEPT on import - the ruling on
 * markup-carve/carve#1286 - and comes back as `{cite=u}`. The same run also
 * emitted `attribute-dropped` for it, because the converter's keep rule and the
 * diagnostic's represented-attribute list were two different lists and only the
 * first one had `cite` (carve-php#1337).
 *
 * WHY A FALSE POSITIVE HERE IS WORSE THAN SILENCE. An unreported loss costs a
 * reader one attribute. A reported non-loss costs them the report: once a
 * consumer finds a row describing an attribute that is plainly still in the
 * output, every OTHER `attribute-dropped` row carries less weight - including
 * the ones that are real, like the event handler asserted below. That is the
 * defect, and it is why this is a plain bug rather than a preference.
 *
 * carve-js stopped reporting it for the same reason once it kept the value
 * (carve-js#1125).
 *
 * SCOPE. This fixes the FIRST half of carve-php#1337 only. The second half -
 * that carve-php keeps EVERY unknown attribute, on a blockquote and on other
 * elements too, so `<blockquote foo="bar">` survives as `{foo=bar}` - is an open
 * question nobody has ruled, and this test deliberately pins it UNCHANGED rather
 * than resolving it in either direction. See
 * {@see self::testAnUnruledUnknownAttributeIsLeftExactlyAsItWas()}.
 */
class CiteIsNotReportedAsDroppedTest extends TestCase
{
    /**
     * Every diagnostic as `[code, severity, message]`.
     *
     * Read off the TYPED `diagnostics` property rather than the `report()`
     * array, so a changed code or severity is a compile-time visible difference
     * here rather than an array key that quietly stops existing.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function diagnostics(string $html): array
    {
        $rows = [];
        foreach ((new HtmlToCarve())->convertWithReport($html)->diagnostics as $diagnostic) {
            $rows[] = [$diagnostic->code, $diagnostic->severity, $diagnostic->message];
        }

        return $rows;
    }

    private function carve(string $html): string
    {
        return trim((new HtmlToCarve())->convertWithReport($html)->value);
    }

    /**
     * Both halves of the contradiction, asserted in one test.
     *
     * Either assertion alone is satisfiable by the wrong fix: silencing the
     * diagnostic while ALSO dropping the attribute would pass a check that only
     * looked at the report, and the ticket is precisely about the two
     * disagreeing. So the kept value and the empty report are asserted together.
     */
    public function testCiteIsKeptAndNotReportedAsDropped(): void
    {
        $html = '<blockquote cite="u"><p>q</p></blockquote>';

        $this->assertSame("{cite=u}\n\n> q", $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * The control that keeps the fix from becoming "stop reporting anything".
     *
     * A genuinely unsupported attribute is still reported, at the same code and
     * severity as before. `<hr>` is the element to ask, because it emits no
     * attribute block at all - so `foo` really is gone from the output here, and
     * the diagnostic about it is true.
     */
    public function testAGenuinelyDroppedAttributeIsStillReported(): void
    {
        $html = '<hr foo="bar">';

        $this->assertSame('---', $this->carve($html));
        $this->assertSame(
            [['attribute-dropped', 'info', 'Dropped unsupported attribute foo on <hr>']],
            $this->diagnostics($html),
        );
    }

    /**
     * An event handler is still dropped AND still reported, on this very element.
     *
     * The narrowing is per attribute NAME, not per element: adding `cite` to the
     * blockquote's represented set must not make the blockquote a place where
     * attributes stop being inspected. This is the row that would go red if the
     * fix had been written as "skip the attribute walk for blockquote".
     */
    public function testAnEventHandlerOnTheSameElementIsStillDroppedAndReported(): void
    {
        $html = '<blockquote cite="u" onclick="evil()"><p>q</p></blockquote>';

        $carve = $this->carve($html);
        $this->assertStringContainsString('cite=u', $carve);
        $this->assertStringNotContainsString('onclick', $carve);
        $this->assertSame(
            [['attribute-dropped', 'warning', 'Dropped event-handler attribute onclick on <blockquote>']],
            $this->diagnostics($html),
        );
    }

    /**
     * THE OPEN QUESTION, pinned unchanged on purpose.
     *
     * carve-php keeps every unknown attribute, so `foo` survives as `{foo=bar}`
     * AND is reported as dropped - the same contradiction this PR fixes for
     * `cite`, one the ticket splits off as unruled because the fix could go
     * either way: narrow the keeping to the attributes the spec names, or widen
     * the spec and stop reporting. carve-js deliberately did not copy the
     * blanket keep.
     *
     * This test does not endorse the behavior. It records it, so that whichever
     * way markup-carve/carve-php#1337's second half is ruled, the change lands
     * on a red test instead of passing unnoticed - and so a reader does not
     * mistake the surviving contradiction for one this PR missed.
     */
    public function testAnUnruledUnknownAttributeIsLeftExactlyAsItWas(): void
    {
        $html = '<blockquote foo="bar"><p>q</p></blockquote>';

        $this->assertSame("{foo=bar}\n\n> q", $this->carve($html));
        $this->assertSame(
            [['attribute-dropped', 'info', 'Dropped unsupported attribute foo on <blockquote>']],
            $this->diagnostics($html),
        );
    }

    /**
     * `cite` elsewhere is untouched: the represented pair is tag AND name.
     *
     * `<q cite="u">` is a different element with a different conversion, so
     * adding the blockquote pair must not silence it. Without this, the fix
     * could have been written against the attribute name alone.
     */
    public function testCiteOnAnotherElementIsUnaffected(): void
    {
        $codes = array_column($this->diagnostics('<p><q cite="u">x</q></p>'), 0);

        $this->assertContains('attribute-dropped', $codes);
    }
}
