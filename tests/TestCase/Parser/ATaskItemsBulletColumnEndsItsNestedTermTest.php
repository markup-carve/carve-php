<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Corpus 744's canonical spelling reads back as corpus 744 (carve-php#4190).
 * Expected output is the spec model's at carve main 5a82756e.
 */
final class ATaskItemsBulletColumnEndsItsNestedTermTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        $html = "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <dl>\n          <dt>t</dt>\n        </dl>\n      </li>\n    </ul>\n    : a\n  </li>\n</ul>";

        yield 'at the bullet content column' => ["- [ ] - :: t\n  : a\n", $html];

        yield 'one column past stays lazy in the term' => [
            "- [ ] - :: t\n   : a\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <dl>\n          <dt>t\n: a</dt>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('shapes')]
    public function testTheLineLeavesTheNestedItem(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }

    public function testCorpus744SurvivesTheWriter(): void
    {
        $source = "* [ ] - :: t\n      : a\n      :: sibling\n      tail\n";
        $formatted = CarveConverter::toCarve($source);

        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testALazyFrameBehindARebaseIndentNeverRenders(): void
    {
        $html = (new CarveConverter())->convert("- [ ] [^n]: :: t\n  : a\n\n[^n]\n");

        self::assertStringContainsString("<dt>t\n: a</dt>", $html);
        self::assertStringNotContainsString('&nbsp;L', $html);
    }
}
