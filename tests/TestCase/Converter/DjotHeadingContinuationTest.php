<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotHeadingContinuationTest extends TestCase
{
    public function testHeadingContinuations(): void
    {
        foreach (
            [
                ["# Heading\n# continued\n", "# Heading continued\n"],
                ["# Heading\nlazy\n", "# Heading lazy\n"],
                ["# Heading\nlazy\n# more\nlazy\n\ntext\n", "# Heading lazy more lazy\n\ntext\n"],
                ["## A\n## B\nC", '## A B C'],
                ["# A\n  # B\n  C", '# A B C'],
            ] as [$source, $expected]
        ) {
            $this->assertSame($expected, (new DjotToCarve())->convert($source));
        }
    }

    public function testBlocksAndCodeStopFolding(): void
    {
        foreach (['', '## B', '#', '- item', '1. item', '> quote', '```', '~~~', '[r]: /url', '::: div', '  - item', '***', '---', '* * *', '^ x', '%%%'] as $next) {
            $source = "# A\n$next\n";
            $this->assertSame("# A\n" . (new DjotToCarve())->convert("$next\n"), (new DjotToCarve())->convert($source));
        }
        $this->assertSame("para\n\\# A\nB\n", (new DjotToCarve())->convert("para\n# A\nB\n"));
        $this->assertSame("# A\n", (new DjotToCarve())->convert("# A\n{.class}\n"));
        foreach (["```\n# A\n# B\n```\n", "`x\n# A\n# B\ny`\n", "# A\\\nB\n"] as $source) {
            $this->assertSame($source, (new DjotToCarve())->convert($source));
        }
    }
}
