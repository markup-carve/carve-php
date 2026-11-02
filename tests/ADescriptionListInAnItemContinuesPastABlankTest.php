<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A description after a blank stays in the item's description list: after a body
 * continuation line, and past an invisible line between the two (carve#4716).
 */
class ADescriptionListInAnItemContinuesPastABlankTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array
    {
        return [
            'after a continuation line in a loose item' => ["- o\n\n  :: t\n  : x\n    more\n\n  : d\n"],
            'past a comment' => ["- o\n  :: t\n  : x\n\n  %% c\n  : d\n"],
            'past a comment between blanks' => ["- o\n  :: t\n  : x\n\n  %% c\n\n  : d\n"],
            'past a link definition' => ["- o\n  :: t\n  : x\n\n  [r]: /u\n  : d\n"],
            'past a comment after a continuation line' => ["- o\n\n  :: t\n  : x\n    more\n\n  %% c\n  : d\n"],
            'past a comment span' => ["- o\n  :: t\n  : x\n\n  %%%\n  h\n  %%%\n  : d\n"],
            'past a comment span and a blank' => ["- o\n  :: t\n  : x\n  %%%%\n  h\n  %%%%\n\n  : d\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheSecondDescriptionJoinsTheList(string $source): void
    {
        $html = CarveConverter::create()->convert($source);

        $this->assertSame(1, substr_count($html, '<dl>'));
        $this->assertStringContainsString("<dd>d</dd>\n    </dl>", $html);
        $this->assertStringNotContainsString(': d', $html);
    }

    public function testThreeBlanksStillEndTheList(): void
    {
        $html = CarveConverter::create()->convert("- o\n\n  :: t\n  : x\n    more\n\n\n\n  :: u\n  : d\n");

        $this->assertSame(2, substr_count($html, '<dl>'));
    }
}
