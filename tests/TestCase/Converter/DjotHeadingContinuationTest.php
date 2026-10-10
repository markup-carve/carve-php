<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
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
        foreach (['', '## B', '#', '- item', '1. item', '> quote', '```', '~~~', '[r]: /url', '::: div', '  - item', '***', '---', '* * *', '^ x'] as $next) {
            $source = "# A\n$next\n";
            $this->assertSame("# A\n" . (new DjotToCarve())->convert("$next\n"), (new DjotToCarve())->convert($source));
        }
        $this->assertSame("para\n\\# A\nB\n", (new DjotToCarve())->convert("para\n# A\nB\n"));
        $this->assertSame("# A\n", (new DjotToCarve())->convert("# A\n{.class}\n"));
        foreach (["```\n# A\n# B\n```\n", "`x\n# A\n# B\ny`\n", "# A\\\nB\n"] as $source) {
            $this->assertSame($source, (new DjotToCarve())->convert($source));
        }
    }

    /**
     * A percent run is not a block starter in Djot, so it folds onto the
     * heading like any other lazy continuation line (carve-php#3060). The
     * two-sign run always folded; the three-sign one did not, because the
     * folder's block-starter list was spelled from Carve's grammar, where
     * `%%%` opens a comment fence.
     */
    public function testAPercentRunFoldsOntoAHeadingAtEveryRunLength(): void
    {
        foreach (
            [
                ["# A\n%\n", "# A %\n", "<section id=\"A\">\n  <h1>A %</h1>\n</section>"],
                ["# A\n%%\n", "# A \\%%\n", "<section id=\"A\">\n  <h1>A %%</h1>\n</section>"],
                ["# A\n%%%\n", "# A \\%%%\n", "<section id=\"A\">\n  <h1>A %%%</h1>\n</section>"],
                ["# A\n%%%%\n", "# A \\%%%%\n", "<section id=\"A\">\n  <h1>A %%%%</h1>\n</section>"],
                ["## A\n%%%\n", "## A \\%%%\n", "<section id=\"A\">\n  <h2>A %%%</h2>\n</section>"],
                ["###### A\n%%%\n", "###### A \\%%%\n", "<section id=\"A\">\n  <h6>A %%%</h6>\n</section>"],
                ["# A\nB\n", "# A B\n", "<section id=\"A-B\">\n  <h1>A B</h1>\n</section>"],
                ["A\n%%%\n", "A\n\\%%%\n", "<p>A\n%%%</p>"],
                ["%%%\nX\n", "\\%%%\nX\n", "<p>%%%\nX</p>"],
            ] as [$djot, $carve, $html]
        ) {
            $this->assertSame($carve, (new DjotToCarve())->convert($djot));
            $this->assertSame($html, trim((new CarveConverter())->convert($carve)));
        }
    }
}
