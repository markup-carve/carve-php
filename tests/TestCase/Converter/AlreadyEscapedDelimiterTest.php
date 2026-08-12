<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A delimiter the source already escaped is not escaped again
 * (markup-carve/carve-php#1212).
 *
 * Doubling it is worse than leaving it: the doubled backslash renders as a
 * literal backslash AND frees the delimiter to open the construct the first
 * escape was suppressing, so the output gains a character the author never
 * wrote plus the markup they escaped away.
 *
 * Source arriving already escaped is the normal case, because Djot escapes with
 * a backslash too.
 */
class AlreadyEscapedDelimiterTest extends TestCase
{
    private DjotToCarve $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotToCarve();
    }

    private function render(string $djot): string
    {
        return trim(CarveConverter::create()->convert($this->converter->convert($djot)));
    }

    /**
     * Asserted through the RENDERER, because the claim is about what the reader
     * sees rather than which escape the converter chose. Each expectation is
     * what djot itself renders for the same source.
     */
    public function testAnAlreadyEscapedDelimiterKeepsItsMeaning(): void
    {
        $this->assertSame('<p>a #y b</p>', $this->render("a \\#y b\n"));
        $this->assertSame('<p>a =b= c</p>', $this->render("a \\=b= c\n"));
        $this->assertSame('<p>a /b/ c</p>', $this->render("a \\/b/ c\n"));
    }

    /**
     * BOUND: the delimiters whose rules do not run on the Djot path were
     * correct before and stay correct. No mutation of the guard breaks these -
     * they are here so the fix is not credited with rows it did not change.
     */
    public function testTheDelimitersThatWereAlreadyCorrectStayCorrect(): void
    {
        $this->assertSame('<p>a *b* c</p>', $this->render("a \\*b* c\n"));
        $this->assertSame('<p>a _b_ c</p>', $this->render("a \\_b_ c\n"));
        $this->assertSame('<p>a {,x,} b</p>', $this->render("a \\{,x,} b\n"));
    }

    /**
     * BOUND, and the row a careless guard breaks: an UNESCAPED delimiter must
     * still be escaped, or the converter stops doing its job entirely.
     */
    public function testAnUnescapedDelimiterIsStillEscaped(): void
    {
        $this->assertSame("a \\#y b\n", $this->converter->convert("a #y b\n"));
        $this->assertSame("a \\=b= c\n", $this->converter->convert("a =b= c\n"));
        $this->assertSame("a \\/b/ c\n", $this->converter->convert("a /b/ c\n"));
    }

    /**
     * BOUND: an EVEN run is a literal backslash, so the delimiter after it is
     * not escaped and still needs escaping. Passes before the fix too, since
     * the old rule escaped unconditionally - it is here to pin the parity half
     * of the guard, not to prove the fix.
     */
    public function testAnEvenBackslashRunStillEscapesTheDelimiter(): void
    {
        $this->assertSame("a \\\\\\#y b\n", $this->converter->convert("a \\\\#y b\n"));
    }
}
