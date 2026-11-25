<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-187, C2-W-136 (carve#6637): an empty `body-rows` ends the head above the footer count.
 */
final class EmptyBodyRowsFootTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function emptyValues(): array
    {
        return [
            'bare' => ['body-rows'],
            'quoted empty' => ['body-rows=""'],
            'spaces only' => ['body-rows=" "'],
        ];
    }

    #[DataProvider('emptyValues')]
    public function testEmptyBodyRowsGivesAHeadAndAFoot(string $key): void
    {
        $source = "{footer-rows=2 $key}\n|= H |\n|= F |\n|= G |\n";
        $html = Carve::render($source);
        self::assertStringContainsString("<thead>\n    <tr><th scope=\"col\">H</th></tr>\n  </thead>\n  <tfoot>", $html);
        self::assertStringNotContainsString('<tbody>', $html);
        self::assertSame("{footer-rows=2 body-rows}\n|= H |\n|= F |\n|= G |\n", Carve::format($source));
        self::assertNotContains('table-row-count-clamped', array_map(static fn ($entry): string => $entry->code, Carve::lint($source)));
    }

    public function testACountOfAllRowsKeepsTheHead(): void
    {
        $html = Carve::render("{footer-rows=2 body-rows}\n|= H |\n|= F |\n");
        self::assertStringNotContainsString('<tfoot>', $html);
    }

    public function testADataCellFootNeedsNoBodyRows(): void
    {
        self::assertSame("{footer-rows=1}\n|= H |\n| F |\n", Carve::format("{footer-rows=1 body-rows}\n|= H |\n| F |\n"));
    }
}
