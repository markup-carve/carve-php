<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ADjotSchemeAutolinkKeepsItsOwnSchemeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function autolinks(): array
    {
        return [
            // djot.js reads any angle autolink holding an "@" as an email and prefixes
            // "mailto:" a second time (jgm/djot.js#162). The Djot spec calls the content
            // "a URL or email address", so a scheme makes it a URL.
            'mailto scheme' => ["<mailto:a@b.c>\n", 'mailto:a@b.c'],
            'uppercase mailto scheme' => ["<MAILTO:a@b.c>\n", 'MAILTO:a@b.c'],
            'http url with userinfo' => ["<http://u@x/y>\n", 'http://u@x/y'],
            'bare email' => ["<a@b.c>\n", 'mailto:a@b.c'],
            'plain url' => ["<https://example.com>\n", 'https://example.com'],
        ];
    }

    #[DataProvider('autolinks')]
    public function testKeepsTheAutolinkAndItsHref(string $djot, string $href): void
    {
        $carve = (new DjotToCarve())->convert($djot);
        self::assertSame($djot, $carve);
        self::assertStringContainsString('href="' . $href . '"', (new CarveConverter())->convert($carve));
    }
}
