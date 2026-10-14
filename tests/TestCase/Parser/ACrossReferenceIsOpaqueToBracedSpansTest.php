<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 E2a: a braced inline never pairs across a cross-reference, so a
 * closer inside `</#id>` cannot close a span opened before it.
 */
class ACrossReferenceIsOpaqueToBracedSpansTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a forced strong' => ["{*a </#x*}> b*}\n", '<p><strong>a &lt;/#x*}&gt; b</strong></p>'],
            'a forced emphasis' => ["{/a </#x/}> b/}\n", '<p><em>a &lt;/#x/}&gt; b</em></p>'],
            'an insertion' => ["{+a </#x+}> b+}\n", '<p><ins>a &lt;/#x+}&gt; b</ins></p>'],
        ];
    }

    #[DataProvider('shapes')]
    public function testABracedCloserInsideACrossReferenceDoesNotClose(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
