<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scaling')]
final class ContinuationOwnerStackScaleTest extends TestCase
{
    public function testRepeatedQuoteAttachmentsKeepOnlyCurrentOwners(): void
    {
        $block = "> x\n+\n- c\n\n";
        $converter = new CarveConverter();
        self::assertSame(
            "<blockquote>\n  <p>x</p>\n  <ul>\n    <li>c</li>\n  </ul>\n</blockquote>",
            trim($converter->convert($block)),
        );

        $times = [];
        foreach ([8192, 32768] as $size) {
            $source = str_repeat($block, (int)ceil($size / strlen($block)));
            $converter->parse($source);
            $samples = [];
            for ($run = 0; $run < 3; $run++) {
                $start = hrtime(true);
                $converter->parse($source);
                $samples[] = hrtime(true) - $start;
            }
            $times[] = min($samples);

            LayoutWork::reset();
            LayoutWork::$on = true;
            try {
                $converter->parse($source);
            } finally {
                LayoutWork::$on = false;
            }
            self::assertLessThan(strlen($source) * 2, LayoutWork::$markerStackEntriesExamined);
        }

        self::assertLessThan(8, $times[1] / $times[0]);
    }
}
