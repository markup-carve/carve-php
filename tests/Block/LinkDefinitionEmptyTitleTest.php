<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6615, C2-B-083, C2-I-068: a link definition takes the empty quoted title an inline link tail takes.
 */
final class LinkDefinitionEmptyTitleTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'double quotes' => ["[a]: u \"\"\n\n[x][a]\n", '<p><a href="u" title="">x</a></p>'],
            'single quotes and attributes' => ["[a]: u '' {.c}\n\n[x][a]\n", '<p><a href="u" title="" class="c">x</a></p>'],
            'on a continuation line' => ["[a]: u\n  \"\"\n\n[x][a]\n", '<p><a href="u" title="">x</a></p>'],
            'text after the title is no definition' => ["[a]: u \"\"x\n\n[x][a]\n", "<p>[a]: u ““x</p>\n<p>[x][a]</p>"],
        ];
    }

    #[DataProvider('sources')]
    public function testTheDefinitionIsRead(string $source, string $expected): void
    {
        $this->assertSame($expected, Carve::toHtml($source));
    }
}
