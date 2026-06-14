<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * S4 cross-impl conformance: carve-php brought in line with carve-js/carve-rs.
 */
class S4ConformanceTest extends TestCase
{
    private CarveConverter $c;

    protected function setUp(): void
    {
        $this->c = new CarveConverter();
    }

    public function testBlockquoteSpaceAfterMarkerIsOptional(): void
    {
        $this->assertSame("<blockquote><p>tight</p></blockquote>\n", $this->c->convert('>tight'));
        // nested with no spaces
        $this->assertStringContainsString(
            '<blockquote>',
            $this->c->convert('>>>x'),
        );
    }

    public function testClassesAreNotDeduplicated(): void
    {
        // grammar §15: classes accumulate in source order, no de-dup
        $this->assertSame('<p><span class="a a">x</span></p>' . "\n", $this->c->convert('[x]{.a .a}'));
    }

    public function testBareHashIsNotAHeading(): void
    {
        $this->assertSame("<p>#</p>\n", $this->c->convert('#'));
        $this->assertSame("<p>##</p>\n", $this->c->convert('##'));
        $this->assertStringContainsString('<h1>x</h1>', $this->c->convert('# x'));
    }
}
