<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ANoteHostedItemReadsItsColonCloserTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function closers(): array
    {
        return [
            'three colons at the content column' => ["[^f]: - :::\n        :::\n\n[^f]\n"],
            'five colons at the content column' => ["[^f]: - :::::\n        :::::\n\n[^f]\n"],
            'five colons past the content column' => ["[^f]: - :::::\n         :::::\n\n[^f]\n"],
        ];
    }

    #[DataProvider('closers')]
    public function testTheCloserEndsItsDiv(string $source): void
    {
        self::assertSame(
            "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n"
            . "  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>\n          <div>\n\n          </div>\n"
            . "        </li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n  </ol>\n</section>",
            CarveConverter::create()->convert($source),
        );
    }

    public function testACaptionAtTheSameColumnStaysText(): void
    {
        $html = CarveConverter::create()->convert("[^f]: - :::::\n        ^ cap\n\n[^f]\n");
        self::assertStringContainsString("<div>\n            <p>^ cap</p>\n          </div>", $html);
    }

    public function testTheNoteKeepsTheCloserWhenItStartsInAListItem(): void
    {
        $html = CarveConverter::create()->convert("- [^f]: - :::::\n          :::::\n\n[^f]\n");
        self::assertStringContainsString("<div>\n\n          </div>", $html);
        self::assertStringNotContainsString('<p>:::::</p>', $html);
    }
}
