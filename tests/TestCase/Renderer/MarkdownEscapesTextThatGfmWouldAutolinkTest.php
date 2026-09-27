<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 8i: the `:` of an http, https or ftp scheme and the `.` of
 * `www.` are escaped where a GFM reader would autolink them, decided on the
 * emitted line.
 */
class MarkdownEscapesTextThatGfmWouldAutolinkTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'scheme' => ['a https://x.io b', 'a https\://x.io b'],
            'scheme in any case' => ['HtTp://x.io', 'HtTp\://x.io'],
            'ftp' => ['ftp://x.io', 'ftp\://x.io'],
            'a digit before the scheme' => ['1https://x.io', '1https\://x.io'],
            'a letter before the scheme' => ['ahttps://x.io', 'ahttps://x.io'],
            'no slashes' => ['https:x', 'https:x'],
            'www after punctuation' => ['(www.x.io) /www.y.io', '(www\.x.io) /www\.y.io'],
            'www after a no-break space' => ["a\u{00A0}www.x.io", "a\u{00A0}www\.x.io"],
            'www after a letter or digit' => ['awww.x 1www.y', 'awww.x 1www.y'],
            'a url split across spans' => ['[http]{.a}[s://x.io]{.b} [ww]{.a}[w.y]{.b}', 'https\://x.io www\.y'],
            'link text' => ['[https://x.io www.y](/u)', '[https://x.io www.y](/u)'],
            'image alt' => ['![https://x.io www.y](i.png)', '![https://x.io www.y](i.png)'],
            'a code span' => ['`https://x.io`', '`https://x.io`'],
            'a list-marker line keeps its own escape' => ["a\n1. www", "a\n1\\. www"],
            'a list marker split across spans' => ['[1]{.a}[. x]{.b}', '1\. x'],
            'an empty item marker split across spans' => ['[1]{.a}[.]{.b}', '1\.'],
        ];
    }

    #[DataProvider('cases')]
    public function testTheFormIsEscapedOnlyWhereItWouldLink(string $carve, string $markdown): void
    {
        $this->assertSame($markdown . "\n", CarveConverter::markdown()->convert($carve . "\n"));
    }
}
