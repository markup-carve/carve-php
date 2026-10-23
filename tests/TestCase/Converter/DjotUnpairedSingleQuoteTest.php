<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotUnpairedSingleQuoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function quotes(): array
    {
        return [
            'unpaired after slash' => ["a/'", '<p>a/’</p>'],
            'unpaired before letter' => ["a/'b", '<p>a/’b</p>'],
            'two closing quotes' => ["a/'b'", '<p>a/’b’</p>'],
            'unpaired before space' => ["a/' x", '<p>a/’ x</p>'],
            'forced opener' => ["{'a/'", '<p>‘a/‘</p>'],
            'forced opener across space' => ["{'a b/'", '<p>‘a b/‘</p>'],
            'forced opener across paragraphs' => ["{'a\n\nb/'", "<p>‘a</p>\n<p>b/‘</p>"],
            'plain closing brace does not close forced quote' => ["{'a} b/'", '<p>‘a} b/‘</p>'],
            'forced quote closes before later slash' => ["{'a'} b/'", '<p>‘a’ b/’</p>'],
            'forced closer after slash' => ["a/'}", '<p>a/’</p>'],
            'forced pair closing after slash' => ["{'a/'}", '<p>‘a/’</p>'],
            'forced marker inside code' => ["`{'` a/'", '<p><code>{\'</code> a/’</p>'],
            'escaped forced marker' => ["\\{'a b/'", '<p>{’a b/’</p>'],
            'image alt keeps source apostrophe' => ["![a/' {'x'}](i.png)", '<p><img src="i.png" alt="a/&apos; x"></p>'],
        ];
    }

    #[DataProvider('quotes')]
    public function testSingleQuoteDirectionMatchesDjot(string $source, string $expected): void
    {
        $html = trim((new CarveConverter())->convert((new DjotToCarve())->convert($source)));

        self::assertSame($expected, $html);
    }

    public function testHeadingIdKeepsTheAuthoredApostrophe(): void
    {
        $carve = (new DjotToCarve())->convert("## x/'y\n");
        $html = (new CarveConverter())->convert($carve);

        self::assertStringContainsString('{id="x-\'y"}', $carve);
        self::assertStringContainsString('<h2>x/’y</h2>', $html);
    }
}
