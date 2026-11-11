<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refused run's opener is literal text since carve#5830, so the writer escapes it like a one-line `{#one` (carve#5950).
 */
class ARefusedDjotBraceRunEscapesItsOpenerTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function runs(): array
    {
        return [
            'unindented continuation' => ["{#one\nkey=1}\nalpha\n", "\\{\\#one\nkey=1}\nalpha\n", "<p>{#one\nkey=1}\nalpha</p>\n"],
            'quoted' => ["> {#one\n> key=1}\n> alpha\n", "> \\{\\#one\n> key=1}\n> alpha\n", "<blockquote><p>{#one\nkey=1}\nalpha</p></blockquote>\n"],
            'indented continuation is a run' => ["{#one\n key=1}\nalpha\n", "{#one key=\"1\"}\nalpha\n", "<p id=\"one\" key=\"1\">alpha</p>\n"],
        ];
    }

    #[DataProvider('runs')]
    public function testTheOpenerStaysText(string $djot, string $carve, string $html): void
    {
        $converted = (new DjotToCarve())->convert($djot);

        $this->assertSame($carve, $converted);
        $this->assertSame($html, rtrim((new CarveConverter())->convert($converted), "\n") . "\n");
    }
}
