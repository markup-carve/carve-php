<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvalidLineRangeInlineHostTest extends TestCase
{
    #[DataProvider('hostProvider')]
    public function testInvalidDirectiveStaysLiteralInInlineHosts(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostProvider(): iterable
    {
        yield 'paragraph without code mode' => [
            'before {{ x.crv @lines:0-2 }} after',
            '<p>before {{ x.crv @lines:0-2 }} after</p>',
        ];

        yield 'invalid range after another option error' => [
            'before {{ x.crv @unknown:yes @lines:01-2 }} after',
            '<p>before {{ x.crv @unknown:yes @lines:01-2 }} after</p>',
        ];

        yield 'nested quote' => [
            '> > before {{ x.crv @lines:2-1 }} after',
            "<blockquote>\n  <blockquote><p>before {{ x.crv @lines:2-1 }} after</p></blockquote>\n</blockquote>",
        ];

        yield 'list with sibling directive' => [
            '- before {{ x.crv @lines:0-2 }} and {{ y.crv @lines:1-02 }} after',
            "<ul>\n  <li>before {{ x.crv @lines:0-2 }} and {{ y.crv @lines:1-02 }} after</li>\n</ul>",
        ];

        yield 'div' => [
            "::: note\nbefore {{ x.crv @lines:0-2 }} after\n:::",
            "<aside class=\"admonition note\" aria-label=\"Note\">\n  <p>before {{ x.crv @lines:0-2 }} after</p>\n</aside>",
        ];
    }
}
