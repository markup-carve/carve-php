<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use PHPUnit\Framework\TestCase;

/**
 * Definition and fence prepasses copy a bounded amount of each source line.
 *
 * These walks used to hand every prefix element a fresh suffix, so N nested
 * markers copied O(N squared) bytes before block parsing began. Counts pin the
 * allocation shape without depending on machine timing.
 */
class TheRemainingPrepassesReadTheLineAtAnOffsetTest extends TestCase
{
    public function testTheirCopyWorkIsLinearInTheDocument(): void
    {
        $prefix = str_repeat('> - ', 500);
        $source = $prefix . "[r]: /u\n"
            . $prefix . "[^f]: note\n"
            . $prefix . "```\n"
            . $prefix . "%%%\n";

        LayoutWork::reset();
        LayoutWork::$on = true;
        try {
            CarveConverter::create()->parse($source);
        } finally {
            LayoutWork::$on = false;
        }

        $counts = [
            'fence' => LayoutWork::$fencePrescan,
            'comment' => LayoutWork::$commentPrescan,
            'reference' => LayoutWork::$referencePrescan,
            'footnote' => LayoutWork::$footnotePrescan,
        ];
        foreach ($counts as $name => $count) {
            $this->assertGreaterThan(0, $count, $name . ' counter is not counting');
            $this->assertLessThan(strlen($source) * 16, $count, sprintf(
                '%s copied %d bytes for a %d-byte document',
                $name,
                $count,
                strlen($source),
            ));
        }
    }
}
