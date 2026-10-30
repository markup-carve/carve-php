<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%` comment in a combined shape ends at the shape's closer and pairing reads
 * the shape without it (carve#4042). A guard of a kind already held is no entry,
 * so it blocks its opener (carve#3973 X1, carve#4021, carve#4022), and a guard
 * that is an open entry does not block a combined opener (carve#4050).
 */
class ACommentAndAHeldGuardReadThePairingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        yield 'a comment in a shape that does not form' => ["/x /*a %% b*/ y\n", '<p><em>x /<strong>a</strong></em> y</p>'];
        yield 'the first closer token ends the comment' => ["/x /*a %% b* c*/ y\n", '<p><em>x /<strong>a</strong></em> y</p>'];
        yield 'a strong closes at the cut' => ["*x /*a %% b*/ y\n", '<p><strong>x /*a</strong>/ y</p>'];
        yield 'an outer span closes past the shape' => ["_x /*a %% b*/ y_\n", '<p><u>x <strong><em>a</em></strong> y</u></p>'];
        yield 'no shape: the comment takes the line' => ["/x *a %% b*/ y\n", '<p>/x *a</p>'];
        yield 'an alnum before the opener forms no shape' => ["a/*b %% c*/ d\n", '<p>a/*b</p>'];
        yield 'a held guard leaves the block as text' => ["*_a _~b~{t=*} z*\n", '<p><strong>_a _~b~{t=</strong>} z*</p>'];
        yield 'the second guarded child' => ["*_~a~{title=\"*_\"} _~a~{title=\"*_\"} z*\n", '<p><strong>_<s title="*_">a</s> _~a~{title=“</strong>_”} z*</p>'];
        yield 'the third guarded child' => ["*_~a~{title=\"*_\"} _~a~{title=\"*_\"} _~a~{title=\"*_\"} z*\n", '<p><strong>_<s title="*_">a</s> _~a~{title=“</strong><u>“} _~a~{title=“*</u>”} z*</p>'];
        yield 'a closed kind is held no longer' => ["*_a_ _~b~{t=*} z*\n", '<p><strong><u>a</u> _<s t="*">b</s> z</strong></p>'];
        yield 'a guard chain holds nothing of its own' => ["*/_~b~{t=*} z*\n", '<p><strong>/_<s t="*">b</s> z</strong></p>'];
        yield 'the combined walk' => ["/*_a _~b~{t=*/} z*/\n", '<p><strong><em>_a _~b~{t=</em></strong>} z*/</p>'];
        yield 'an open guard before a combined opener' => ["_/*~*/\n", '<p>_<strong><em>~</em></strong></p>'];
    }

    #[DataProvider('rows')]
    public function testItRendersTheModelsHtml(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
