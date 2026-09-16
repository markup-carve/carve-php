<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#2021. `identifier` admits a leading `_`, and only
 * `boolean_attribute` refuses it, where it collides with forced underline. The
 * block-attribute gate refused the whole payload on its first character, so
 * every key=value with an underscore key stayed literal.
 */
class AKeyValueAttributeMayStartWithAnUnderscoreTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return rtrim($this->converter->convert($source), "\n");
    }

    public function testABareUnderscoreKeyAttachesToTheBlockBelow(): void
    {
        $this->assertSame('<p _="x=_">para</p>', $this->html("{_=x=_}\n\npara\n"));
    }

    public function testItRendersNothingWithNoBlockBelow(): void
    {
        $this->assertSame('', $this->html("{_=x=_}\n"));
    }

    public function testAnUnderscoreKeyWithMoreCharactersAttachesToo(): void
    {
        $this->assertSame('<p _k="1">para</p>', $this->html("{_k=1}\n\npara\n"));
        $this->assertSame('<p __="1">para</p>', $this->html("{__=1}\n\npara\n"));
        $this->assertSame('<p _-a="1">para</p>', $this->html("{_-a=1}\n\npara\n"));
    }

    public function testAQuotedValueOnAnUnderscoreKeyReadsToo(): void
    {
        $this->assertSame('<p _="on click">para</p>', $this->html("{_=\"on click\"}\n\npara\n"));
    }

    public function testItInterruptsAParagraphLikeAnyOtherAttributeLine(): void
    {
        $this->assertSame(
            "<p>para</p>\n<p _k=\"1\">para2</p>",
            $this->html("para\n{_k=1}\npara2\n"),
        );
        $this->assertSame(
            "<p>para</p>\n<p _=\"x=_\">para2</p>",
            $this->html("para\n{_=x=_}\npara2\n"),
        );
    }

    public function testItEndsAListItemContinuationLikeAnyOtherAttributeLine(): void
    {
        $this->assertSame(
            "<ul>\n  <li>item</li>\n</ul>\n<p _k=\"1\">more</p>",
            $this->html("- item\n{_k=1}\nmore\n"),
        );
        $this->assertSame(
            "<ul>\n  <li>item</li>\n</ul>\n<p _=\"x=_\">more</p>",
            $this->html("- item\n{_=x=_}\nmore\n"),
        );
    }

    public function testTheBooleanFormStaysAnUnderline(): void
    {
        $this->assertSame("<p><u>x</u></p>\n<p>para</p>", $this->html("{_x_}\n\npara\n"));
        $this->assertSame("<p><u> x </u></p>\n<p>para</p>", $this->html("{_ x _}\n\npara\n"));
    }

    public function testAnUnderscoreNameWithNoValueAndNoUnderlineStaysText(): void
    {
        $this->assertSame("<p>{_foo}</p>\n<p>para</p>", $this->html("{_foo}\n\npara\n"));
        $this->assertSame("<p>{_}</p>\n<p>para</p>", $this->html("{_}\n\npara\n"));
    }

    public function testMidLineItIsStillAnInline(): void
    {
        $this->assertSame('<p><u><mark>x</mark></u> y</p>', $this->html("{_=x=_} y\n"));
        $this->assertSame('<p>y <u><mark>x</mark></u></p>', $this->html("y {_=x=_}\n"));
    }

    public function testTheWriterKeepsTheAttributeLine(): void
    {
        $this->assertSame("{_=x=_}\npara\n", $this->converter->toCarve("{_=x=_}\n\npara\n"));
        $this->assertSame("{_k=1}\npara\n", $this->converter->toCarve("{_k=1}\n\npara\n"));
    }
}
