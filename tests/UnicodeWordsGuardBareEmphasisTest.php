<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4806: the bare emphasis guards read Unicode letters and numbers.
 */
final class UnicodeWordsGuardBareEmphasisTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function renderings(): array
    {
        return [
            'ticket reproduction' => [
                "Москва/Питер/Киев\n日本/中国/韩国\nснейк_кейс_тут\nä/ö/ü\n",
                "<p>Москва/Питер/Киев\n日本/中国/韩国\nснейк_кейс_тут\nä/ö/ü</p>",
            ],
            'braced span inside a word' => ['日本{/中国/}韩国', '<p>日本<em>中国</em>韩国</p>'],
            'accented flank before' => ['é*x*', '<p>é*x*</p>'],
            'accented flank after' => ['y *x*é', '<p>y *x*é</p>'],
            'astral letter before' => ['𐐀*x*', '<p>𐐀*x*</p>'],
            'astral letter after' => ['y *x*𐐀', '<p>y *x*𐐀</p>'],
            'astral digit before' => ['𝟘/x/', '<p>𝟘/x/</p>'],
            'combined span' => ['é/*x*/ y /*x*/é', '<p>é/<strong>x</strong>/ y /<strong>x</strong>/é</p>'],
            'non-ASCII punctuation still flanks' => ['«*b*»', '<p>«<strong>b</strong>»</p>'],
            'non-ASCII content still pairs' => ['*é* /中国/', '<p><strong>é</strong> <em>中国</em></p>'],
        ];
    }

    #[DataProvider('renderings')]
    public function testTheGuardReadsUnicodeWords(string $source, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writerShapes(): array
    {
        return [
            'braced before a letter' => ['日本{/中国/}韩国'],
            'braced after an accent' => ['é{*x*} y {*x*}é'],
            'braced astral flank' => ['𐐀{*x*}𐐀'],
            'braced combined' => ['é{/*x*/}é'],
        ];
    }

    #[DataProvider('writerShapes')]
    public function testTheWriterKeepsTheBraces(string $source): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        $formatted = (new CarveRenderer())->render($converter->parse($source));

        $this->assertSame($html, $converter->convert($formatted));
    }
}
