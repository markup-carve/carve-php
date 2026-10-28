<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P11-077: the shared rows for a paragraph line of dashes alone.
 */
class EmDashLineFmtFixtureTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function rows(): iterable
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../../spec/tests/fixtures/em-dash-line-fmt.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['rows'] as $row) {
            yield $row['id'] => [$row];
        }
    }

    /**
     * @param array<string, string> $row
     */
    #[DataProvider('rows')]
    public function testTheWriterSpellsTheRow(array $row): void
    {
        $this->assertSame($row['fmt'], CarveConverter::toCarve($row['source']));
        $this->assertSame($row['fmt'], CarveConverter::toCarve($row['fmt']));
    }

    /**
     * @param array<string, string> $row
     */
    #[DataProvider('rows')]
    public function testTheHyphenSpellingIsAThematicBreak(array $row): void
    {
        if (!isset($row['hyphenSpelling'])) {
            $this->assertArrayNotHasKey('hyphenSpelling', $row);

            return;
        }
        $this->assertStringContainsString('<hr>', (new CarveConverter())->convert($row['hyphenSpelling']));
    }
}
