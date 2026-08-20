<?php
declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The doubled run is the canonical arrow, in both families
 * (markup-carve/carve#1442).
 *
 * The corpus pins the rule too, but not here yet: the vendored spec still
 * spells the old set, so `20-smart-typography-arrows-and-symbols` is declared
 * AHEAD_OF_PIN and the corpus cannot check the new answer until the submodule
 * moves. These cases carry it in the meantime, and stay afterwards because two
 * of them cover ordering that no corpus document happens to spell.
 */
class TheDoubledRunIsTheCanonicalArrowTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new CarveConverter();
    }

    protected function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    public function testTheCanonicalSetInBothFamilies(): void
    {
        $this->assertSame("<p>\u{2190} \u{2192} \u{2194}</p>", $this->html("<-- --> <-->\n"));
        $this->assertSame("<p>\u{21D0} \u{21D2} \u{21D4}</p>", $this->html("<== ==> <=>\n"));
    }

    public function testTheSingleHyphenFormsAreDeprecatedButStillRender(): void
    {
        $this->assertSame("<p>\u{2192} \u{2190} \u{2194}</p>", $this->html("-> <- <->\n"));
    }

    public function testAFatArrowIsCodeRatherThanAnArrow(): void
    {
        // Removed rather than deprecated: `key => value`, `x => x + 1` and
        // `Some(x) => x` are ordinary prose about code, and each silently
        // became an arrow in the rendered output only.
        $this->assertSame('<p>key =&gt; value</p>', $this->html("key => value\n"));
        $this->assertSame('<p>x =&gt; x + 1</p>', $this->html("x => x + 1\n"));
    }

    public function testAComparisonKeepsItsGlyph(): void
    {
        // Which is what forces the left double arrow to grow a character.
        $this->assertSame(
            "<p>p \u{2264} q, r \u{2265} s, x \u{2260} y</p>",
            $this->html("p <= q, r >= s, x != y\n"),
        );
    }

    /**
     * The fixed-token pass runs BEFORE the hyphen-run pass, and both directions
     * of that ordering are covered here: a run that is an arrow, and a run that
     * is not.
     */
    public function testAnArrowBeatsTheHyphenRunAndOnlyWhereItIsOne(): void
    {
        $this->assertSame("<p>a \u{2192} b</p>", $this->html("a --> b\n"));
        $this->assertSame("<p>a \u{2194} b</p>", $this->html("a <--> b\n"));
        // No `>`, so the run allocation still owns it.
        $this->assertSame("<p>pages 1\u{2013}10</p>", $this->html("pages 1--10\n"));
        $this->assertSame("<p>a \u{2014} b</p>", $this->html("a --- b\n"));
        // Three hyphens then `>` is not `-->`, so this clause does not claim it -
        // and the flag rule from markup-carve/carve#1443 then keeps the whole run
        // literal, because it is preceded by whitespace and followed by `>`. The
        // spec oracle renders it the same way. carve-js and carve-rs still say
        // `—>` here, and will agree once they implement that rule.
        $this->assertSame('<p>a ---&gt; b</p>', $this->html("a ---> b\n"));
    }

    public function testAHighlightStillPairsAroundTheseRuns(): void
    {
        $this->assertSame('<p>a <mark>hi</mark> b</p>', $this->html("a =hi= b\n"));
        // carve-js needed a guard here after `=>` stopped being an arrow; this
        // engine consumes `!=` before `=` can pair, so it never opened one.
        $this->assertSame("<p>a =&gt; b and c \u{2260} d</p>", $this->html("a => b and c != d\n"));
    }
}
