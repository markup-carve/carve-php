<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A straight quote in HTML text is escaped, or it reads back as smart
 * punctuation, and a caret before `[` is escaped, or it opens an inline note
 * (PART 11 §5).
 */
class AQuoteOrNoteCaretInHtmlTextImportsAsTextTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'a double quote' => ['<p>a"b</p>', "a\\\"b\n"],
            'a quoted word' => ['<p>"a" b</p>', "\\\"a\\\" b\n"],
            'an apostrophe' => ["<p>it's</p>", "it\\'s\n"],
            'a single-quoted word' => ["<p>'a' b</p>", "\\'a\\' b\n"],
            'a caret before a bracket' => ['<p>x ^[n] y</p>', "x \\^[n] y\n"],
            'a caret before a link' => ['<p>x ^<a href="u">n</a></p>', "x \\^[n](u)\n"],
            'a caret before a span' => ['<p>x^<span class="k">n</span></p>', "x\\^[n]{.k}\n"],
            'a backslash and a caret before a link' => ['<p>a\\^<a href="u">n</a></p>', "a\\\\\\^[n](u)\n"],
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportIsWritten(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportReadsBackAsTheHtml(string $html, string $carve): void
    {
        $this->assertSame($html, trim(CarveConverter::create()->convert((new HtmlToCarve())->convert($html))));
    }
}
