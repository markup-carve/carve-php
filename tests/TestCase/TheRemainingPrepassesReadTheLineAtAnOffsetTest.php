<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use PHPUnit\Framework\TestCase;

/**
 * Definition discovery uses the block parser's bounded layout work.
 *
 * The removed definition prepasses must stay removed: their counters remain
 * zero. The structural walk shares the ordinary indentation machinery, whose
 * counted work remains linear in this adversarial nested-prefix document.
 */
class TheRemainingPrepassesReadTheLineAtAnOffsetTest extends TestCase
{
    public function testTheRemovedPrepassesStayRemoved(): void
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

        $removed = [
            'fence' => LayoutWork::$fencePrescan,
            'comment' => LayoutWork::$commentPrescan,
            'reference' => LayoutWork::$referencePrescan,
            'footnote' => LayoutWork::$footnotePrescan,
        ];
        foreach ($removed as $name => $count) {
            $this->assertSame(0, $count, $name . ' prepass unexpectedly ran');
        }
    }
}
