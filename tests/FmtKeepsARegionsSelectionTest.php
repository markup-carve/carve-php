<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * CARVE-P11-073 (carve#3330): fmt keeps a region marker on its line. The
 * fixture is a copy of the spec's tests/fixtures/region-marker-fmt.json.
 */
class FmtKeepsARegionsSelectionTest extends TestCase
{
    /**
     * @throws \RuntimeException
     *
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        $json = file_get_contents(__DIR__ . '/fixtures/region-marker-fmt.json');
        if ($json === false) {
            throw new RuntimeException('Cannot read the region-marker fixture.');
        }
        $fixture = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($fixture) || !is_array($fixture['rows'] ?? null)) {
            throw new RuntimeException('Expected a rows array.');
        }
        foreach ($fixture['rows'] as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['source'] ?? null) || !is_string($row['fmt'] ?? null)) {
                throw new RuntimeException('Expected id, source and fmt strings.');
            }

            yield $row['id'] => [$row['source'], $row['fmt']];
        }
    }

    #[DataProvider('rows')]
    public function testFmtWritesTheFixtureBytes(string $source, string $fmt): void
    {
        $this->assertSame($fmt, CarveConverter::toCarve($source));
        $this->assertSame($fmt, CarveConverter::toCarve($fmt));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function readings(): iterable
    {
        // A comment that is not a marker clears X1 retention at the content
        // column, so the marker under it stays there too.
        yield 'below a non-marker comment' => ["- a\n%% note\n%% #endregion r\n", "- a\n  %% note\n  %% #endregion r\n"];

        // A marker retained in a nested item goes back to that item's marker column.
        yield 'in a nested item' => ["- x\n  - y\n%% #endregion r\n", "- x\n  - y\n  %% #endregion r\n"];

        // Below a list the closer cannot be written adjacent: it would join the item.
        yield 'below a list' => ["- a\n\n%% #endregion r\n", "- a\n\n%% #endregion r\n"];

        // A paragraph holding only an image is written as a block image.
        yield 'below an indented image' => [" ![Apollo](a.jpg)\n%% #region r\n", "![Apollo](a.jpg)\n%% #region r\n"];
    }

    #[DataProvider('readings')]
    public function testTheReadingIsKept(string $source, string $expected): void
    {
        $written = CarveConverter::toCarve($source);
        $this->assertSame($expected, $written);
        $this->assertSame($written, CarveConverter::toCarve($written));
        $converter = new CarveConverter();
        $this->assertSame($converter->convert($source), $converter->convert($written));
    }
}
