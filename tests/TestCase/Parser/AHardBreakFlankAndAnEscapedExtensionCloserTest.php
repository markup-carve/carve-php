<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4511: a hard break or an escaped space after a dash run or a
 * quote is whitespace to the typography flank. markup-carve/carve#4513: an
 * extension's content closes at its first unescaped `]`.
 */
final class AHardBreakFlankAndAnEscapedExtensionCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'quote after a dash before a hard break' => ["\"a-\"\\\nb\n", "<p>“a-”<br>\nb</p>"],
            'single quote after a dash before a hard break' => ["'a-'\\\nb\n", "<p>‘a-’<br>\nb</p>"],
            'quote after a dash before an escaped space' => ["\"a-\"\\ b\n", '<p>“a-”&nbsp;b</p>'],
            'dash run before a hard break' => ["x --\\\nb\n", "<p>x –<br>\nb</p>"],
            'dash run before an escaped space' => ["x --\\ y\n", '<p>x –&nbsp;y</p>'],
            'dash run before an escaped punctuation stays a flag' => ["x --\\.\n", '<p>x --.</p>'],
            'an escaped backslash before a space is no flank' => ["x --\\\\ b\n", '<p>x --\\ b</p>'],
            'an escaped backslash before a line end is no flank' => ["\"a-\"\\\\\nb\n", "<p>“a-“\\\nb</p>"],
            'escaped closer is content' => [":foo[a\\]b]\n", '<p><span class="ext-foo">a]b</span></p>'],
            'escaped backslash leaves the closer' => [":foo[a\\\\]b]\n", '<p><span class="ext-foo">a\\</span>b]</p>'],
            'odd backslash run escapes the closer' => [":foo[a\\\\\\]b]\n", '<p><span class="ext-foo">a\\]b</span></p>'],
            'only an escaped closer' => [":foo[\\]]\n", '<p><span class="ext-foo">]</span></p>'],
            'no unescaped closer' => [":foo[a\\]\n", '<p>:foo[a]</p>'],
            'inside link text' => ["[:foo[a\\]b]](/u)\n", '<p><a href="/u"><span class="ext-foo">a]b</span></a></p>'],
            'nested bracket still closes early' => [":foo[a [b] c]\n", '<p><span class="ext-foo">a [b</span> c]</p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testRender(string $source, string $html): void
    {
        self::assertSame($html, rtrim((new CarveConverter())->convert($source)));
    }
}
