<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegionMarkerSplitsListTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function regions(): iterable
    {
        $twoLists = "<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>";

        yield 'region opener' => ["- a\n%% #region x\n- b\n", $twoLists];
        yield 'region prefix followed by punctuation' => ["- a\n%% #region!\n- b\n", $twoLists];
        yield 'region prefix followed by an invalid include name' => ["- a\n%% #region 9\n- b\n", $twoLists];
        yield 'region closer after an ordinary comment' => ["- a\n%% note\n%% #endregion x\n- b\n", $twoLists];
        yield 'indented marker in item remains a child' => ["- a\n  %% #region x\n- b\n", "<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>"];
    }

    #[DataProvider('regions')]
    public function testRegionMarkerIsAListSiblingAtItsColumn(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testBlankBeforeNestedRegionMarkerDoesNotLoosenOuterItem(): void
    {
        $source = "- outer\n  - a\n  \n  %% #region x\n  - b\n";
        $expected = "<ul>\n  <li>outer\n    <ul>\n      <li>a</li>\n    </ul>\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testBlankBeforeRegionMarkerAndNewParagraphLoosensOuterItem(): void
    {
        $source = "- outer\n  - a\n  \n  %% #region x\n  text\n";
        $expected = "<ul>\n  <li><p>outer</p>\n    <ul>\n      <li>a</li>\n    </ul>\n    <p>text</p>\n  </li>\n</ul>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
