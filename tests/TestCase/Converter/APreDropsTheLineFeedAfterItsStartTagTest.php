<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An HTML parser drops one line feed right after `<pre>` (carve-php#2522).
 */
class APreDropsTheLineFeedAfterItsStartTagTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function blocks(): array
    {
        return [
            'one line feed' => ["<pre>\nx\n</pre>", "```\nx\n```\n"],
            'only the first of two' => ["<pre>\n\nx</pre>", "```\n\nx\n```\n"],
            'not after a code start tag' => ["<pre><code>\nx</code></pre>", "```\n\nx\n```\n"],
            'none to drop' => ['<pre>x</pre>', "```\nx\n```\n"],
        ];
    }

    #[DataProvider('blocks')]
    public function testTheLeadingLineFeed(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }
}
