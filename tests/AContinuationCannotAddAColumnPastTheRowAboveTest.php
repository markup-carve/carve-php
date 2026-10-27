<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AContinuationCannotAddAColumnPastTheRowAboveTest extends TestCase
{
    public function testACodeSpanCrossingTheJoinKeepsTheRowWidth(): void
    {
        $source = "| `a | z |\n+ b` | w |\n";
        $this->assertSame(1, $this->cellCount($source));
        $this->assertSame(preg_replace('/\n\z/', '', "<table>\n  <tbody>\n    <tr><td><code>a | z b</code></td></tr>\n  </tbody>\n</table>\n"), CarveConverter::create()->convert($source));
    }

    public function testAnExtraContinuationCellCannotCreateAColumn(): void
    {
        $this->assertSame(1, $this->cellCount("| a |\n+ b | w |\n"));
        $this->assertSame(2, $this->cellCount("| a | x |\n+ b | w |\n"));
    }

    public function testTheCodeSpanFormatsToAFixedPoint(): void
    {
        $source = "| `a | z |\n+ b` | w |\n";
        $written = CarveConverter::toCarve($source);

        // The join inside the code span is carried as cellWraps (CARVE-P12-069).
        $this->assertSame("| `a | z |\n+ b` |\n", $written);
        $this->assertSame($written, CarveConverter::toCarve($written));
        $this->assertSame($this->cellCount($source), $this->cellCount($written));
    }

    private function cellCount(string $source): int
    {
        $ast = (new AstCodec())->encode((new CarveConverter())->parse($source));

        return count($ast['children'][0]['rows'][0]['cells']);
    }
}
