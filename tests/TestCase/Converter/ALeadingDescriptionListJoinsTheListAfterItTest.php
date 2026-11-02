<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A `<dd>` before the first `<dt>` is written as blocks ahead of the list. When
 * those blocks end in a definition list, the two lists stay apart with N1a's
 * three-blank boundary (carve#4759, replacing the merge).
 */
class ALeadingDescriptionListJoinsTheListAfterItTest extends TestCase
{
    public function testTheTwoListsStayApart(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<dl><dd><dl><dt>x</dt><dd>y</dd></dl></dd><dt>t</dt><dd>d</dd></dl>');

        $this->assertSame(":: x\n: y\n\n\n\n:: t\n: d\n", $result->value);
        $rows = array_map(
            static fn (array $row): array => [$row['code'], $row['path']],
            $result->report()['diagnostics'],
        );
        $this->assertSame([
            ['element-unwrapped', '/dl[1]/dd[1]'],
        ], $rows);
    }
}
