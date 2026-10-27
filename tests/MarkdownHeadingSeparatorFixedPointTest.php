<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownHeadingSeparatorFixedPointTest extends TestCase
{
    public function testAdjacentHeadings(): void
    {
        $rows = [
            'cm-062' => ["# foo\n## foo\n### foo\n#### foo\n##### foo\n###### foo\n", "# foo\n\n## foo\n\n### foo\n\n#### foo\n\n##### foo\n\n###### foo\n", '1266860430a1f1c8ecb0b702a32765f3c8a465610716c38ce72e4c2d8dc5f1fc'],
            'cm-068' => [" ### foo\n  ## foo\n   # foo\n", "### foo\n\n## foo\n\n# foo\n", 'b64e69d021262a6aff9797e131d32d078e68e162b779f06c05ba204953c036ce'],
            'cm-071' => ["## foo ##\n  ###   bar    ###\n", "## foo\n\n### bar\n", '6ad3ec734f47aa8105aac2311beacf6933cad83be9f004d09d5048aa4bbb0dff'],
            'cm-072' => ["# foo ##################################\n##### foo ##\n", "# foo\n\n##### foo\n", '0adc4142780de292dad00482c29a3d073104e11f5e27f71d0d1281f35d897b1b'],
            'cm-076' => ["### foo \\###\n## foo #\\##\n# foo \\#\n", "### foo \\###\n\n## foo #\\##\n\n# foo \\#\n", '7c8a5f4a81e33472923a94d689fba88fad53caf3a133612e6ae1b3ce5b3b555b'],
        ];

        foreach ($rows as $id => [$source, $expected, $htmlHash]) {
            $converter = new CarveConverter();
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame($htmlHash, hash('sha256', $converter->convert($actual)), $id);
        }
    }
}
