<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A task box is content, so a marker at a nested item's content column
 * remains inside that item (CARVE-P0-008, CARVE-P0-024).
 */
class NestedTaskItemContentColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'one marker-line child' => [
                "- [ ] - b\n  - [ ] c\n    - [ ] d\n",
                '<li><input type="checkbox" disabled aria-label="c"> c' . "\n" . '        <ul class="task-list">' . "\n" . '          <li><input type="checkbox" disabled aria-label="d"> d</li>',
            ],
            'two marker-line children' => [
                "- [ ] - a\n  - [ ] - b\n    - [ ] c\n      - [ ] d\n",
                '<li><input type="checkbox" disabled aria-label="c"> c' . "\n" . '            <ul class="task-list">' . "\n" . '              <li><input type="checkbox" disabled aria-label="d"> d</li>',
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testMarkerAtNestedTaskContentColumnOpensAChildList(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }
}
