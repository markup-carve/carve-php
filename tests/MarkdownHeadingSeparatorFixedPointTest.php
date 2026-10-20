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
            'cm-062' => ["# foo\n## foo\n### foo\n#### foo\n##### foo\n###### foo\n", "# foo\n\n## foo\n\n### foo\n\n#### foo\n\n##### foo\n\n###### foo\n", '44d8325266822c5faf252c292955c603ee7d1186e5ade12dbc7f449d16924ad9'],
            'cm-068' => [" ### foo\n  ## foo\n   # foo\n", "### foo\n\n## foo\n\n# foo\n", '52294a2d581b67f1eef9cea784284b746df1850eeab72ae5f832f7cd9d9d177b'],
            'cm-071' => ["## foo ##\n  ###   bar    ###\n", "## foo\n\n### bar\n", '2417aeac297a0c5d555dbd05df9ea074e5b700ab923079183f6773c4f3f3b691'],
            'cm-072' => ["# foo ##################################\n##### foo ##\n", "# foo\n\n##### foo\n", '5ed580270d4bacdcc0cd4f6baab32712733e56189cce0c53235873b043d6bcfc'],
            'cm-076' => ["### foo \\###\n## foo #\\##\n# foo \\#\n", "### foo \\###\n\n## foo #\\##\n\n# foo \\#\n", 'fe6062a5dea175b35eeab24e3b7e2fef2ff49b958f5c2952df468295e11982e9'],
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
