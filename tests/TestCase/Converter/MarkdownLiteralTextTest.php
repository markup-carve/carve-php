<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownLiteralTextTest extends TestCase
{
    public function testLiteralTextKeepsItsMeaning(): void
    {
        $cases = [
            ['\\`not code`', '<p>`not code`</p>'],
            ['<https://x/\\`a`>', '<p><a href="https://x/%5C%60a%60">https://x/\\`a`</a></p>'],
            ['a&#13;# b', '<p>a # b</p>'],
            ['a&#10;# b', '<p>a # b</p>'],
            ['a&#13;&#13;b', '<p>a  b</p>'],
            ['&#1; &#x7f; &#128; &#65534; &#1114111;', "<p>\u{1} \u{7f} \u{80} \u{fffe} \u{10ffff}</p>"],
            ['&#34; &#39;', '<p>" \'</p>'],
            ['&#0; &#xD800; &#1114112;', '<p>� � �</p>'],
            ['&#87654321; &#x1234567;', '<p>&amp;#87654321; &amp;#x1234567;</p>'],
            ['&notanentity; &angmsdaa;', '<p>&amp;notanentity; ⦨</p>'],
        ];
        foreach ($cases as [$source, $expected]) {
            $carve = (new MarkdownToCarve())->convert($source);
            $this->assertSame($expected, trim((new CarveConverter())->convert($carve)), $source);
        }
    }
}
