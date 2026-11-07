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
            // A marker that misses the note body closes it and opens a list in the description (carve#4860).
            self::assertStringContainsString("<dd>\n    <ul>\n      <li>child", $html);
            self::assertStringContainsString('<p>alpha<a href', $html);
        }
    }

    public function testListAtNoteFloorStaysInNote(): void
    {
        $source = ":: term\n:  [^f]: alpha\n     - child\n\nSee[^f]\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString('<dd></dd>', $html);
        // The note opens its own list under its paragraph (carve#4860).
        self::assertStringContainsString("<p>alpha</p>\n      <ul>\n        <li>child</li>", $html);
    }

    public function testQuotedDescriptionSeparatesNoteBodyFromLazySibling(): void
    {
        $source = "> :: term\n> :  [^f]: alpha\n>      - child\nSee[^f]\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<dd></dd>\n  </dl>\n</blockquote>", $html);
        self::assertStringNotContainsString("alpha\n- child", $html);

        $source = "> :: term\n> :  [^f]: alpha\n>    - child\nSee[^f]\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<li>child\nSee", $html);
    }

    public function testFirstBlockHeadingLeavesNoteOpenForBodyFloorList(): void
    {
        $source = "- see[^n]\n  [^n]: +\n  # h\n    - z\n\nsee[^n]\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<h1 id=\"h\">h</h1>\n      <ul>\n        <li>z</li>", $html);
        self::assertStringContainsString('<li>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></li>', $html);
    }
}
