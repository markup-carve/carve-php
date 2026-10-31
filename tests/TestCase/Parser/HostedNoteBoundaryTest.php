<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

final class HostedNoteBoundaryTest extends TestCase
{
    use RuleATodo;

    public function testListInDescriptionBandStaysWithDescription(): void
    {
        foreach ([3, 4] as $column) {
            $source = ":: term\n:  [^f]: alpha\n" . str_repeat(' ', $column) . "- child\nSee[^f]\n";
            $html = (new CarveConverter())->convert($source);
            self::assertStringContainsString('<dd></dd>', $html);
        }
    }

    public function testListAtNoteFloorStaysInNote(): void
    {
        $source = ":: term\n:  [^f]: alpha\n     - child\n\nSee[^f]\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString('<dd></dd>', $html);
        self::assertStringContainsString("alpha\n- child", $html);
    }

    public function testQuotedDescriptionSeparatesNoteBodyFromLazySibling(): void
    {
        $source = "> :: term\n> :  [^f]: alpha\n>      - child\nSee[^f]\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<dd></dd>\n  </dl>\n</blockquote>\n<p>See", $html);
        self::assertStringContainsString("alpha\n- child", $html);

        $source = "> :: term\n> :  [^f]: alpha\n>    - child\nSee[^f]\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<li>child\nSee", $html);
    }

    public function testFirstBlockHeadingLeavesNoteOpenForBodyFloorList(): void
    {
        $source = "- see[^n]\n  [^n]: +\n  # h\n    - z\n\nsee[^n]\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<h1 id=\"h\">h</h1>\n      <ul>\n        <li>z</li>", $html);
        self::assertStringNotContainsString("<li>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>\n", $html);
    }
}
