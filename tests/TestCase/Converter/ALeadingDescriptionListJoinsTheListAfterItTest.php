<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A `<dd>` before the first `<dt>` is written as blocks ahead of the list. When
 * those blocks end in a definition list, the two lists have no boundary in
 * Carve source, so they are merged and reported like two sibling `<dl>`s.
 */
class ALeadingDescriptionListJoinsTheListAfterItTest extends TestCase
{
    public function testTheTwoListsMergeWithARow(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<dl><dd><dl><dt>x</dt><dd>y</dd></dl></dd><dt>t</dt><dd>d</dd></dl>');

        $this->assertSame(":: x\n: y\n:: t\n: d\n", $result->value);
        $rows = array_map(
            static fn (array $row): array => [$row['code'], $row['path']],
            $result->report()['diagnostics'],
        );
        $this->assertSame([
            ['element-unwrapped', '/dl[1]'],
            ['element-unwrapped', '/dl[1]/dd[1]'],
        ], $rows);
        $this->assertStringStartsWith('Merged <dl> into the definition list before it', $result->report()['diagnostics'][0]['message']);
    }
}
