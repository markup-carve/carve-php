<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#6014: the construct assessment models a dropped
 * destination as exactly what the import writes, so a block it empties or
 * formatted image alt text keeps the located report instead of falling back.
 */
class AMarkdownDroppedDestinationIsAssessedAsItsContentTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<array{string, string, string, string, string}>}>
     */
    public static function reportProvider(): array
    {
        $dropped = ['attribute-dropped', 'warning', 'dropped', 'exact', 'line:1'];
        $paragraph = ['markdown-paragraph', 'info', 'preserved', 'exact', 'line:1'];

        return [
            'an image that is the whole paragraph' => ["![](data:x)\n", "\n", [$dropped]],
            'formatted alt text' => ["x ![a *b*](data:x) y\n", "x a b y\n", [$paragraph, $dropped]],
            'an empty link that is the whole paragraph' => ["[](javascript:x)\n", "\n", [$dropped]],
            'two empty links' => ["[](javascript:x) [](javascript:y)\n", "\n", [$dropped, $dropped]],
            'an empty link inside emphasis' => ["a *[](javascript:x)* b\n", "a  b\n", [$paragraph, $dropped]],
            'an emphasis the drop empties' => ["*![](data:x)*\n", "\n", [$dropped]],
            'trailing text trimmed' => ["x ![](data:x)\n", "x\n", [$paragraph, $dropped]],
            'alt with a code span' => ["x ![a `c`](data:x) y\n", "x a c y\n", [$paragraph, $dropped]],
            'alt with an entity' => ["x ![a &amp; b](data:x) y\n", "x a & b y\n", [$paragraph, $dropped]],
            'alt with an escape' => ["x ![a \\* b](data:x) y\n", "x a * b y\n", [$paragraph, $dropped]],
            'a quote the drop empties' => ["> ![](data:x)\n", ">\n", [['markdown-block-quote', 'info', 'preserved', 'exact', 'line:1'], $dropped]],
            'an emptied paragraph after another' => ["p\n\n![](data:x)\n", "p\n", [$paragraph, ['attribute-dropped', 'warning', 'dropped', 'exact', 'line:3']]],
            'a reference image with formatted alt' => ["x ![a *b*][r] y\n\n[r]: data:x\n", "x a b y\n", [$paragraph, ['attribute-dropped', 'warning', 'dropped', 'exact', 'line:3']]],
        ];
    }

    /**
     * @param string $markdown
     * @param string $carve
     * @param list<array{string, string, string, string, string}> $rows
     */
    #[DataProvider('reportProvider')]
    public function testTheReportIsLocatedWithoutAFallback(string $markdown, string $carve, array $rows): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);

        $this->assertSame($carve, $result->value);
        $this->assertSame($rows, array_map(static fn (MigrationDiagnostic $row): array => [$row->code, $row->severity, $row->fidelity, $row->confidence, (string)$row->path], $result->diagnostics));
    }
}
