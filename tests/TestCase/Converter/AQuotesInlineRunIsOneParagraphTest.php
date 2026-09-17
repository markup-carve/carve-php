<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Consecutive inline children of a `<blockquote>` are one paragraph, and a
 * block child still stands on its own (markup-carve/carve-php#2102).
 */
class AQuotesInlineRunIsOneParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function importProvider(): array
    {
        return [
            'text around an inline element' => [
                '<blockquote>a <b>b</b> c</blockquote>',
                "> a *b* c\n",
            ],
            'a hard break' => [
                '<blockquote>a<br>b</blockquote>',
                "> a\\\n> b\n",
            ],
            'an inline run before a paragraph' => [
                '<blockquote>a <b>b</b><p>para</p></blockquote>',
                "> a *b*\n>\n> para\n",
            ],
            'an inline run after a paragraph' => [
                '<blockquote><p>para</p>tail <em>i</em></blockquote>',
                "> para\n>\n> tail /i/\n",
            ],
            'a nested quote' => [
                '<blockquote>outer<blockquote>inner</blockquote></blockquote>',
                "> outer\n>\n> > inner\n",
            ],
            'nothing but text' => [
                '<blockquote>just text</blockquote>',
                "> just text\n",
            ],
            'layout between two inline elements' => [
                '<blockquote><b>a</b> <b>b</b></blockquote>',
                "> *a* *b*\n",
            ],
            'layout between two paragraphs' => [
                "<blockquote><p>a</p>\n<p>b</p></blockquote>",
                "> a\n>\n> b\n",
            ],
        ];
    }

    #[DataProvider('importProvider')]
    public function testTheImportIsWritten(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    public function testAnInlineRunReadsBackAsOneParagraph(): void
    {
        $imported = (new HtmlToCarve())->convert('<blockquote>a <b>b</b> c</blockquote>');

        $this->assertSame(
            "<blockquote><p>a <strong>b</strong> c</p></blockquote>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function openerProvider(): array
    {
        return [
            'an opener starting the run' => ['<blockquote>- x</blockquote>', "> \\- x\n"],
            'a quote opener after a hard break' => ['<blockquote>a<br>&gt; q</blockquote>', "> a\\\n> \\> q\n"],
            // A bullet does not interrupt an open paragraph, so escaping it
            // below the hard break would be idle.
            'a bullet after a hard break' => ['<blockquote>a<br>- x</blockquote>', "> a\\\n> - x\n"],
            // Idle under the quote marker, escaped without one, so this pins
            // the marker the run is written under.
            'an opener the quote marker disarms' => ['<blockquote>*[b]: x</blockquote>', "> *[b]: x\n"],
        ];
    }

    #[DataProvider('openerProvider')]
    public function testABlockOpenerInTheRunIsStillEscaped(string $html, string $carve): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($carve, $imported);
        $this->assertStringNotContainsString('<ul>', (new CarveConverter())->convert($imported));
    }
}
