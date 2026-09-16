<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An EMPTY code span has one spelling, a backtick run that its container ends,
 * and inside an emphasis only the braced closer ends it: a bare closer sits in
 * the open run and is read as content (markup-carve/carve#2051).
 */
class AnEmphasisEndingInAnEmptyCodeSpanWritesTheBracedCloserTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    protected function fmt(string $source): string
    {
        return CarveConverter::toCarve($source);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function bracedProvider(): array
    {
        return [
            'the ruled case' => ["{~` ~}\n", "{~``~}\n"],
            'text on both sides' => ["a {~` ~} b\n", "a {~``~} b\n"],
            'a strong span' => ["{*`  *}\n", "{*``*}\n"],
            'no space at all' => ["{~`~}\n", "{~``~}\n"],
            'an emphasis span' => ["{/` /}\n", "{/``/}\n"],
            'an underline span' => ["{_` _}\n", "{_``_}\n"],
            'a highlight span' => ["{=` =}\n", "{=``=}\n"],
            'an attribute block after it' => ["{~` ~}{.x}\n", "{~``~}{.x}\n"],
            'nested in another emphasis' => ["{*x {~` ~}*}\n", "*x {~``~}*\n"],
        ];
    }

    #[DataProvider('bracedProvider')]
    public function testTheEmphasisWritesTheBracedCloser(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->fmt($source));
    }

    #[DataProvider('bracedProvider')]
    public function testTheWrittenFormRoundTrips(string $source, string $expected): void
    {
        $this->assertSame($this->html($source), $this->html($expected));
    }

    #[DataProvider('bracedProvider')]
    public function testTheWrittenFormIsStable(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->fmt($this->fmt($source)));
    }

    /**
     * @return array<string, array<string>>
     */
    public static function bareProvider(): array
    {
        return [
            'a filled span closes itself' => ["{~`a`~}\n", "~`a`~\n"],
            'a padded span closes itself' => ["{~` a `~}\n", "~`a`~\n"],
            'an empty span carrying attributes is not one' => ["{~` `{.c}~}\n", "~` `{.c}~\n"],
        ];
    }

    #[DataProvider('bareProvider')]
    public function testAnEmphasisEndingInAClosedSpanStaysBare(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->fmt($source));
    }

    public function testAnEmptyCodeSpanStandingAloneKeepsItsBareRun(): void
    {
        $this->assertSame("``\n", $this->fmt("`` \n"));
    }

    /**
     * Attributes attach to a span's CLOSING run, which an empty span has not
     * got, so the shape has no Carve spelling and the braces would not give it
     * one. No source reaches it; a tree an importer built can.
     */
    public function testAnEmptyCodeSpanCarryingAttributesTakesNoBraces(): void
    {
        $code = new Code('');
        $code->setAttribute('class', 'c');
        $strike = new Strike();
        $strike->appendChild($code);
        $paragraph = new Paragraph();
        $paragraph->appendChild($strike);
        $document = new Document();
        $document->appendChild($paragraph);

        $this->assertSame("~``{.c}~\n", (new CarveRenderer())->render($document));
    }
}
