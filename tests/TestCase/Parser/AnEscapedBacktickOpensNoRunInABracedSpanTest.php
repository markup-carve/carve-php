<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An escaped backtick opens no verbatim run, so a braced span's closer scan
 * must not pair it with a backtick further along the line and skip the closer.
 * carve-js and carve-rs render every case below the same way.
 */
class AnEscapedBacktickOpensNoRunInABracedSpanTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function caseProvider(): array
    {
        return [
            'underline, backtick later' => ['a {_\\`x_} b `', '<p>a <u>`x</u> b <code></code></p>'],
            'strong, backtick later' => ['a {*\\`x*} b `', '<p>a <strong>`x</strong> b <code></code></p>'],
            'nested braced span' => ['a {_{*\\`x*}_} b `', '<p>a <u><strong>`x</strong></u> b <code></code></p>'],
            'content ending in the marker' => ['q {_\\`__}` q', '<p>q <u>`_</u><code> q</code></p>'],
            'escaped backslash, then an escaped backtick' => ['a {_\\\\\\`x_} b `', '<p>a <u>\\`x</u> b <code></code></p>'],
            'escaped marker is content' => ['a {_x\\_y_} b', '<p>a <u>x_y</u> b</p>'],
        ];
    }

    /**
     * @param string $source
     * @param string $html
     */
    #[DataProvider('caseProvider')]
    public function testTheSpanFindsItsCloser(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
