<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotLineInitialPeriodTest extends TestCase
{
    #[DataProvider('sourceProvider')]
    public function testPeriodStaysText(string $source, string $expected, string $html): void
    {
        $carve = (new DjotToCarve())->convert($source . "\n");
        self::assertSame($expected . "\n", $carve);
        $actual = preg_replace('/>\s+</', '><', trim((new CarveConverter())->convert($carve)));
        self::assertSame($html, $actual);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'space' => ['. a', '\. a', '<p>. a</p>'],
            'two spaces' => ['.  a', '\.  a', '<p>.  a</p>'],
            'indent' => ['  . a', '  \. a', '<p>. a</p>'],
            'quote' => ['> . a', '> \. a', '<blockquote><p>. a</p></blockquote>'],
            'bullet' => ['- . a', '- \. a', '<ul><li>. a</li></ul>'],
            'ordered' => ['1. . a', '1. \. a', '<ol><li>. a</li></ol>'],
            'continuation' => [
                'a
. b', 'a
\. b', '<p>a
. b</p>',
            ],
            'tab' => [".\ta", "\\.\ta", "<p>.\ta</p>"],
            'before brace' => ['.{=a=}', '\.{=a=}', '<p>.<mark>a</mark></p>'],
            'end of line' => ['.', '\.', '<p>.</p>'],
            'word' => ['.a', '.a', '<p>.a</p>'],
            'midline' => ['a . b', 'a . b', '<p>a . b</p>'],
            'three periods' => ['... a', '... a', '<p>… a</p>'],
            'inline code' => ['`. a`', '`. a`', '<p><code>. a</code></p>'],
            'fenced code' => [
                '```
. a
```', '```
. a
```', '<pre><code>. a
</code></pre>',
            ],
            'ordered list' => ['1. a', '1. a', '<ol><li>a</li></ol>'],
        ];
    }
}
