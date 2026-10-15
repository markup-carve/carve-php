<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An escaped bracket inside a Markdown angle destination does not close it
 * (carve-php#3484).
 */
final class EscapedAngleDestinationDefinitionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'quoted, titled' => ["> [r]: <a\\>b> \"x\"\n>\n> [t][r]\n", '<a href="a%3Eb" title="x">t</a>'],
            'quoted, untitled' => ["> [r]: <a\\>b>\n>\n> [t][r]\n", '<a href="a%3Eb">t</a>'],
            'quoted, escaped opener' => ["> [r]: <a\\<b> \"x\"\n>\n> [t][r]\n", '<a href="a%3Cb" title="x">t</a>'],
            'quoted, title wraps' => ["> [r]: <a\\>b> \"x\n> y\"\n>\n> [t][r]\n", "<a href=\"a%3Eb\" title=\"x\ny\">t</a>"],
            'unquoted, titled' => ["[r]: <a\\>b> \"x\"\n\n[t][r]\n", '<a href="a%3Eb" title="x">t</a>'],
            'unquoted, untitled' => ["[r]: <a\\>b>\n\n[t][r]\n", '<a href="a%3Eb">t</a>'],
            'unquoted, title wraps' => ["[r]: <a\\>b> \"x\ny\"\n\n[t][r]\n", "<a href=\"a%3Eb\" title=\"x\ny\">t</a>"],
            'escaped backslash closes' => ["[r]: <a\\\\>\n\n[t][r]\n", '<a href="a%5C">t</a>'],
        ];
    }

    #[DataProvider('provider')]
    public function testDefinitionIsReadAndTheUseBecomesALink(string $source, string $link): void
    {
        $carve = (new MarkdownToCarve())->convert($source);
        self::assertStringNotContainsString('\\[r]', $carve);
        self::assertStringContainsString($link, (new CarveConverter())->convert($carve));
    }
}
