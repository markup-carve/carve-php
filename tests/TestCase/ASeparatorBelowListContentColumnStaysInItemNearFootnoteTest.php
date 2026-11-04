<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ASeparatorBelowListContentColumnStaysInItemNearFootnoteTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{int, bool}>
     */
    public static function columnsAndGaps(): array
    {
        $cases = [];
        foreach ([4, 5, 6] as $termColumn) {
            foreach ([false, true] as $blank) {
                $cases[$termColumn . ($blank ? ' with blank' : ' adjacent')] = [$termColumn, $blank];
            }
        }

        return $cases;
    }

    #[DataProvider('columnsAndGaps')]
    public function testTheSeparatorStaysInTheListItem(int $termColumn, bool $blank): void
    {
        $source = "r[^1]\n\n- [^1]: x\n" . ($blank ? "\n" : '')
            . str_repeat(' ', $termColumn) . ":: t\n : zq\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("</ul>\n<p>: zq</p>", $html);
    }

    public function testTheSeparatorAfterTheNoteLeadLeavesTheListItem(): void
    {
        $html = (new CarveConverter())->convert("r[^1]\n\n- [^1]: x\n : zq\n");

        self::assertStringContainsString("<p>x\n: zq<a", $html);
    }

    public function testTheSeparatorAfterANoteHeadingLeavesTheListItem(): void
    {
        $html = (new CarveConverter())->convert("r[^1]\n\n- [^1]: x\n    # h\n : zq\n");

        self::assertStringContainsString("</ul>\n<p>: zq</p>", $html);
    }

    public function testFollowingLazyLinesStayWithTheRecoveredItemParagraph(): void
    {
        $source = "r[^1]\n\n- [^1]: x\n    :: t\n : zq\n more\n : zr\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("<p>: zq\nmore\n: zr</p>", $html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nestedContainers(): array
    {
        return [
            'nested list' => ["r[^1]\n\n- outer\n  - [^1]: x\n      :: t\n   : zq\n", "</ul>\n    : zq\n  </li>"],
            'quoted list' => ["r[^1]\n\n> - [^1]: x\n>     :: t\n>  : zq\n", "</ul>\n  <p>: zq</p>"],
        ];
    }

    #[DataProvider('nestedContainers')]
    public function testTheSeparatorStaysInANestedListItem(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString($expected, $html);
    }

    public function testAShallowerNestedTermKeepsTheSeparatorInItsTerm(): void
    {
        $source = "r[^1]\n\n- outer\n  - [^1]: x\n     :: t\n   : zq\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("<dt>t</dt>\n        </dl>\n      </li>\n    </ul>\n    : zq", $html);
    }

    public function testASeparatorAtTheMarkerColumnLeavesTheRecoveredItem(): void
    {
        $source = "r[^1]\n\n- [^1]: x\n    :: t\n : zq\n: zr\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("</ul>\n<p>: zq\n: zr</p>", $html);
        self::assertStringContainsString('<dt>t</dt>', $html);
    }

    public function testAnUnindentedPlainLineContinuesTheItemButANewSeparatorDoesNot(): void
    {
        $source = "r[^1]\n\n- [^1]: x\n    :: t\n : zq\nmore\n: zr\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("</ul>\n<p>: zq\nmore\n: zr</p>", $html);
    }

    public function testABlankThenAChildMarkerKeepsTheChildUnderTheRecoveredItem(): void
    {
        $source = "r[^1]\n\n- [^1]: x\n    :: t\n : zq\n\n  - child\n- next\n";
        $html = (new CarveConverter())->convert($source);

        self::assertMatchesRegularExpression('~<p>: zq</p>\s*<ul>\s*<li>child</li>\s*<li>next</li>~', $html);
        self::assertStringContainsString("</ul>\n<p>: zq</p>", $html);
    }
}
