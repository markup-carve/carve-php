<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaskItemContinuationMarkerTest extends TestCase
{
    /**
     * @return array<string, array{source: string, html: string}>
     */
    public static function shapes(): array
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/task-item-shapes.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($rows as $index => $row) {
            $cases[(string)$index] = [$row['source'], $row['html']];
        }

        return $cases;
    }

    #[DataProvider('shapes')]
    public function testTaskItemMarkerShape(string $source, string $html): void
    {
        $this->assertSame(self::normalize($html), self::normalize((new CarveConverter())->convert($source)));
    }

    private static function normalize(string $html): string
    {
        $inPre = false;
        $lines = [];
        foreach (explode("\n", trim($html)) as $line) {
            if ($inPre) {
                $lines[] = $line;
            } elseif (trim($line) !== '') {
                $lines[] = trim($line);
            }
            if (preg_match('/<pre[ >]/', $line) === 1) {
                $inPre = true;
            }
            if (str_contains($line, '</pre>')) {
                $inPre = false;
            }
        }

        return implode("\n", $lines);
    }
}
