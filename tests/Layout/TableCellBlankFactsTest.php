<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Layout;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class TableCellBlankFactsTest extends TestCase
{
    public function testTableCellBlocksHaveNoBlankCounts(): void
    {
        $layout = Carve::parseWithLayout("| a |\n^ Table\n")->layout;
        $wrong = array_values(array_filter($layout['nodes'], self::hasBadBlankFacts(...)));
        self::assertSame([], $wrong);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function hasBadBlankFacts(array $entry): bool
    {
        return str_starts_with($entry['path'], '/children/0/children/')
            && (isset($entry['blankLinesBefore']) || isset($entry['blankLinesAfter']));
    }
}
