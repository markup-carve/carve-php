<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A quoted Markdown definition with a destination and a wrapped title
 * becomes titled inline links, as it does unquoted (carve-php#3480).
 */
final class QuotedWrappedTitleDefinitionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provider(): array
    {
        return [
            'quoted continuation' => ["> [r]: /u \"a\n> b\"\n>\n> [t][r]\n", "> [t](/u \"a\n> b\")", "a\nb"],
            'lazy continuation' => ["> [r]: /u \"a\nb\"\n>\n> [t][r]\n", "> [t](/u \"a\n> b\")", "a\nb"],
            'nested quote' => ["> > [r]: /u \"a\n> > b\"\n> >\n> > [t][r]\n", "> > [t](/u \"a\n> > b\")", "a\nb"],
            'title on its own line' => ["> [r]: /u\n> \"a\n> b\"\n>\n> [t][r]\n", "> [t](/u \"a\n> b\")", "a\nb"],
            'escaped angle destination' => ["> [r]: <a\\>b> \"a\n> b\"\n>\n> [t][r]\n", "> [t](a>b \"a\n> b\")", "a\nb"],
            'angle destination' => ["> [r]: <a b> 'a\n> b'\n>\n> [t][r]\n", "> [t](a%20b 'a\n> b')", "a\nb"],
        ];
    }

    #[DataProvider('provider')]
    public function testQuotedDefinitionWithAWrappedTitleBecomesInlineLinks(string $source, string $expected, string $title): void
    {
        $carve = (new MarkdownToCarve())->convert($source);
        self::assertSame($expected, rtrim((string)preg_replace('/\A(?:[> ]*\n)+/', '', $carve), "\n"));
        self::assertStringContainsString(' title="' . $title . '">t</a>', (new CarveConverter())->convert($carve));
    }

    public function testOneLineTitleStaysAReferenceDefinition(): void
    {
        $carve = (new MarkdownToCarve())->convert("> [r]: /u \"a b\"\n>\n> [t][r]\n");
        self::assertStringContainsString('> [t][r]', $carve);
        self::assertStringContainsString('[r]: /u "a b"', $carve);
        self::assertStringContainsString('<a href="/u" title="a b">t</a>', (new CarveConverter())->convert($carve));
    }
}
