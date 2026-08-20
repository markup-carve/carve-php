<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * carve#1450. An identifier may start with `_`, so `{_x_}` is two constructs at
 * once: the boolean attribute `_x_`, and a forced underline. carve-js, carve-rs
 * and the executable spec read a lone `{_x_}` line as a block attribute line and
 * rendered `<p _x_="">` on the block below -- or, with no block below, nothing
 * at all. This engine already read the underline, and the ruling settled it that
 * way, so what is pinned here is the READING this engine has and the WRITER rule
 * that follows from it.
 */
class ABooleanAttributeDoesNotStartWithAnUnderscoreTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    public function testALoneBracedPairIsAnUnderline(): void
    {
        $this->assertSame('<p><u>x</u></p>', $this->html("{_x_}\n"));
        $this->assertSame("<p><u>x</u>\npara</p>", $this->html("{_x_}\npara\n"));
    }

    public function testItIsAnUnderlineMidLineToo(): void
    {
        $this->assertSame('<p><u>x</u> y</p>', $this->html("{_x_} y\n"));
        $this->assertSame('<p>y <u>x</u></p>', $this->html("y {_x_}\n"));
    }

    public function testABareUnderscoreFirstWordIsText(): void
    {
        // It has no underline reading either -- it does not end `_}` -- so it
        // renders literally rather than becoming something else.
        $this->assertSame("<p>{_foo}\npara</p>", $this->html("{_foo}\npara\n"));
        $this->assertSame('<p>[x]{_u}</p>', $this->html("[x]{_u}\n"));
    }

    public function testEveryOtherAttributeFormKeepsItsUnderscore(): void
    {
        $this->assertSame(
            '<p id="_id" class="_c" _k="1" _="on click">para</p>',
            $this->html("{#_id ._c _k=1 _=\"on click\"}\npara\n"),
        );
        $this->assertSame('<p><span id="_u">x</span></p>', $this->html("[x]{#_u}\n"));
        $this->assertSame('<p><span class="_u">x</span></p>', $this->html("[x]{._u}\n"));
        $this->assertSame('<p><span _u="1">x</span></p>', $this->html("[x]{_u=1}\n"));
    }

    public function testAnOrdinaryBooleanAttributeStillReads(): void
    {
        $this->assertSame('<p disabled="">para</p>', $this->html("{disabled}\npara\n"));
        $this->assertSame('<p><kbd>x</kbd></p>', $this->html("[x]{kbd}\n"));
    }

    public function testTheWriterKeepsTheEmptyValueOnAnUnderscoreName(): void
    {
        // PART 11 section 6c shortens a value-less attribute to its bare name
        // and cannot here: `{_u}` is text and `{_x_}` is an underline, either
        // way a document the writer changed, which section 1 forbids.
        $this->assertSame('[x]{_u=""}' . "\n", $this->converter->toCarve('[x]{_u=""}' . "\n"));
        $this->assertSame(
            $this->html('[x]{_u=""}' . "\n"),
            $this->html($this->converter->toCarve('[x]{_u=""}' . "\n")),
        );
        // An ordinary name still shortens.
        $this->assertSame("[x]{kbd}\n", $this->converter->toCarve('[x]{kbd=""}' . "\n"));
    }
}
