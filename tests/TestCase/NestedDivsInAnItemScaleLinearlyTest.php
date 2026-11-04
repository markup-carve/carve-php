<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scaling')]
final class NestedDivsInAnItemScaleLinearlyTest extends TestCase
{
    public function testManyNotesInAnItemHostedBoxParseInLinearTime(): void
    {
        $converter = new CarveConverter();
        self::assertSame(
            "<ul>\n  <li><p>head</p>\n    <div class=\"box\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <p>b</p>\n      </aside>\n    </div>\n  </li>\n</ul>",
            trim($converter->convert(self::source(1))),
        );

        $times = [];
        foreach ([500, 2000] as $notes) {
            $source = self::source($notes);
            $samples = [];
            for ($run = 0; $run < 3; $run++) {
                $start = hrtime(true);
                $converter->parse($source);
                $samples[] = hrtime(true) - $start;
            }
            $times[] = min($samples);
        }

        self::assertLessThan(8, $times[1] / $times[0]);
    }

    private static function source(int $notes): string
    {
        return "- head\n\n      ::: box\n" . str_repeat("      a\n     ::: note\n      b\n      :::\n", $notes);
    }
}
