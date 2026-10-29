<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedTermReleasesItsSourceColumnTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function markerColumns(): array
    {
        return [
            'bullet marker column' => ["- - :: t\n  : a\n", '<dt>t</dt>', "</ul>\n    : a"],
            'ordered marker column' => ["1. 1. :: t\n   : a\n", '<dt>t</dt>', "</ol>\n    : a"],
            'task marker column' => ["- - [ ] :: t\n  : a\n", '<dt>t</dt>', "</ul>\n    : a"],
            'quote host' => ["> - - :: t\n>   : a\n", '<dt>t</dt>', "</ul>\n      : a"],
        ];
    }

    #[DataProvider('markerColumns')]
    public function testMarkerColumnReturnsToOuterItem(string $source, string $term, string $outer): void
    {
        $html = CarveConverter::create()->convert($source);
        self::assertStringContainsString($term, $html);
        self::assertStringContainsString($outer, $html);
        self::assertStringNotContainsString("<dt>t\n: a</dt>", $html);
    }

    public function testOneColumnPastMarkerStillFoldsIntoTerm(): void
    {
        self::assertStringContainsString(
            "<dt>t\n: a</dt>",
            CarveConverter::create()->convert("- - :: t\n   : a\n"),
        );
    }

    public function testBelowNestedTermColumnReturnsToDescription(): void
    {
        self::assertSame(
            "<ul>\n  <li>a\n    <dl>\n      <dt>outer</dt>\n      <dd>\n        <p>detail</p>\n        <dl>\n          <dt>inner</dt>\n        </dl>\n        <p>:  tail</p>\n      </dd>\n    </dl>\n  </li>\n</ul>",
            CarveConverter::create()->convert("- a\n  :: outer\n  :  detail\n     :: inner\n    :  tail\n"),
        );
    }

    public function testAtNestedTermColumnStillOpensDescription(): void
    {
        self::assertStringContainsString(
            "<dt>inner</dt>\n          <dd>tail</dd>",
            CarveConverter::create()->convert("- a\n  :: outer\n  :  detail\n     :: inner\n     :  tail\n"),
        );
    }

    public function testQuoteBelowDescriptionColumnFoldsIntoOpenTerm(): void
    {
        $html = CarveConverter::create()->convert(":: outer\n:  detail\n     :: inner\n  > quote\n");
        self::assertStringContainsString("<dt>inner\n&gt; quote</dt>", $html);
        self::assertStringNotContainsString('<blockquote>', $html);
    }

    public function testQuotePastDescriptionColumnKeepsItsSourceOffsetInTerm(): void
    {
        $html = CarveConverter::create()->convert(":: outer\n:  detail\n     :: inner\n    > quote\n");
        self::assertStringContainsString("<dt>inner\n &gt; quote</dt>", $html);
        self::assertStringNotContainsString('<blockquote>', $html);
    }

    public function testQuotedSeparatorBelowNestedTermReturnsToDescription(): void
    {
        $html = CarveConverter::create()->convert("> :: outer\n> :  detail\n>     :: inner\n>  :  tail\n");
        self::assertStringContainsString('<dt>inner</dt>', $html);
        self::assertStringContainsString('<p>:  tail</p>', $html);
        self::assertStringNotContainsString("<dt>inner\n:  tail</dt>", $html);
    }

    public function testQuoteAtDescriptionColumnOpensThere(): void
    {
        $html = CarveConverter::create()->convert(":: outer\n:  detail\n   :: inner\n   > quote\n");
        self::assertStringContainsString('<dt>inner</dt>', $html);
        self::assertStringContainsString('<blockquote><p>quote</p></blockquote>', $html);
    }
}
