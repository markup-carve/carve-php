<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

final class DjotEmptyTaskItemTest extends TestCase
{
    public function testEmptyTaskItemsKeepTheirCheckboxAndListTightness(): void
    {
        $cases = [
            "- [ ]\n" => "- [ ] %%%%\n",
            "- [ ] a\n- [x]\n" => "- [ ] a\n- [x] %%%%\n",
        ];
        foreach ($cases as $djot => $carve) {
            $written = (new DjotToCarve())->convert($djot);
            self::assertSame($carve, $written);
            $html = (new CarveConverter())->convert($written);
            self::assertSame(1, substr_count($html, 'class="task-list"'));
            self::assertSame(substr_count($djot, '['), substr_count($html, 'type="checkbox"'));
            self::assertStringNotContainsString('<p>', $html);
        }
    }
}
