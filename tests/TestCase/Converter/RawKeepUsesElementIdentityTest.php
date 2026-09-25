<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RawKeepUsesElementIdentityTest extends TestCase
{
    /**
     * @return array<string, array{string, array<int, array{string, string}>}>
     */
    public static function twins(): array
    {
        return [
            'raw first' => [
                '<li onclick="x()">t</li><ul><li onclick="x()">t</li></ul>',
                [
                    ['attribute-preserved', '/li[1]'],
                    ['raw-preserved', '/li[1]'],
                    ['attribute-dropped', '/ul[2]/li[1]'],
                ],
            ],
            'spelled first' => [
                '<ul><li onclick="x()">t</li></ul><li onclick="x()">t</li>',
                [
                    ['attribute-dropped', '/ul[1]/li[1]'],
                    ['attribute-preserved', '/li[2]'],
                    ['raw-preserved', '/li[2]'],
                ],
            ],
        ];
    }

    /**
     * @param string $html
     * @param array<int, array{string, string}> $expected
     */
    #[DataProvider('twins')]
    public function testIdenticalElementsHaveSeparateOutcomes(string $html, array $expected): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);
        $rows = array_map(
            static fn (array $row): array => [$row['code'], $row['path']],
            $result->report()['diagnostics'],
        );

        $this->assertSame($expected, $rows);
    }

    public function testRawIdentityIsClearedBetweenImports(): void
    {
        $converter = new HtmlToCarve(importMode: 'roundtrip');
        $converter->convertWithReport('<li onclick="x()">t</li>');
        $result = $converter->convertWithReport('<ul><li onclick="x()">t</li></ul>');

        $this->assertSame(
            [['attribute-dropped', '/ul[1]/li[1]']],
            array_map(
                static fn (array $row): array => [$row['code'], $row['path']],
                $result->report()['diagnostics'],
            ),
        );
    }

    public function testSanitizedRawImageDoesNotClaimRemovedHandler(): void
    {
        $converter = new HtmlToCarve(importMode: 'roundtrip');
        $kept = $converter->convertWithReport('<img src="x" alt="[">');
        $sanitized = $converter->convertWithReport('<img src="x" alt="[" onload="x()">');

        $this->assertSame(['raw-preserved'], array_column($kept->report()['diagnostics'], 'code'));
        $this->assertSame(['attribute-dropped'], array_column($sanitized->report()['diagnostics'], 'code'));
    }
}
