<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 section 22 R3 consumes an atom's attribute run before the arrow scan.
 */
class AnAttachedAttributeRunKeepsItsSubstitutionWholeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'code attribute in a strike' => [
            "{~`a`{k=\"~>\"}b~}\n",
            '<p><s><code k="~&gt;">a</code>b</s></p>',
        ];

        yield 'code attribute on the new side' => [
            "{~a~>`a`{k=\"~>\"}b~}\n",
            '<p><del>a</del><ins><code k="~&gt;">a</code>b</ins></p>',
        ];

        yield 'link attribute in a strike' => [
            "{~[a](/u){title=\"~>\"}b~}\n",
            '<p><s><a href="/u" title="~&gt;">a</a>b</s></p>',
        ];

        yield 'span attribute in a strike' => [
            "{~[a]{title=\"~>\"}b~}\n",
            '<p><s><span title="~&gt;">a</span>b</s></p>',
        ];
    }

    #[DataProvider('cases')]
    public function testTheAttachedRunHoldsNoArrow(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
