<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class SkippedNoteWrappedAttributeTest extends TestCase
{
    public function testAHostKeepsAnAttributeRunAfterASkippedNote(): void
    {
        $expected = <<<'HTML'
<ul>
  <li><p class="x y">p</p></li>
</ul>
HTML;

        foreach (['{.x .y}', "{.x\n .y}"] as $run) {
            $source = "- +\n[^m]: n\n$run\np\n";
            self::assertSame($expected, trim((new CarveConverter())->convert($source)), $run);
        }
    }
}
