<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Variants of the list-table clause cases CC-2416 to CC-2425 (C2-X-078, C2-I-029; markup-carve/carve#6835).
 */
final class ListTableClaimTest extends TestCase
{
    /**
     * @var string
     */
    private const TABLE = "::: list-table\n- - a\n  - b\n- - c\n  - d\n:::\n";

    /**
     * @var string
     */
    private const PLAIN = "<table>\n  <tbody>\n    <tr><td>a</td><td>b</td></tr>\n    <tr><td>c</td><td>d</td></tr>\n  </tbody>\n</table>";

    /**
     * @param string $source
     */
    private static function html(string $source): string
    {
        return Carve::toHtml($source, ['extensions' => ['carve.listTable'], 'renderers' => ['carve.listTable' => 'standard']]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noEffect(): array
    {
        $cases = [];
        foreach (['header-rows', 'footer-rows', 'header-cols'] as $key) {
            foreach (['bare' => '', 'empty' => '=""', 'zero' => '=0', 'negative' => '=-1', 'word' => '=abc'] as $name => $value) {
                $cases[$key . ' ' . $name] = [$key . $value];
            }
        }
        $cases['all three bare'] = ['header-rows footer-rows header-cols'];

        return $cases;
    }

    #[DataProvider('noEffect')]
    public function testAKeyWithoutADecimalAboveZeroHasNoEffect(string $attr): void
    {
        $this->assertSame(self::PLAIN, self::html('{' . $attr . "}\n" . self::TABLE));
    }

    public function testABareKeyBesideAValueLeavesTheValuedKey(): void
    {
        $html = self::html("{header-rows=1 footer-rows}\n" . self::TABLE);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringNotContainsString('<tfoot>', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unclaimed(): array
    {
        return [
            'paragraph after the first row' => ["- - a\n\n  extra\n- - b\n"],
            'paragraph after a middle row' => ["- - a\n- - b\n\n  extra\n- - c\n"],
            'second list, other marker' => ["- - a\n\n  1) b\n"],
            'ordered list after' => ["1. - a\n\n   1. b\n"],
            'quote after' => ["- - a\n\n  > q\n"],
            'code after' => ["- - a\n\n  ```\n  x\n  ```\n"],
        ];
    }

    #[DataProvider('unclaimed')]
    public function testARowItemWithMoreThanItsListLeavesACoreDiv(string $rows): void
    {
        $source = "::: list-table\n" . $rows . ":::\n";
        $html = self::html($source);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertSame(Carve::toHtml($source), $html);
    }

    public function testARowItemWithMoreThanItsListInsideAQuoteLeavesACoreDiv(): void
    {
        $source = "> ::: list-table\n> - - a\n>\n>   extra\n> :::\n";
        $this->assertSame(Carve::toHtml($source), self::html($source));
    }

    public function testABlockIndentedIntoTheCellStaysClaimed(): void
    {
        $html = self::html("- item\n\n  ::: list-table\n  - - a\n\n      extra\n  :::\n");
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<p>extra</p>', $html);
    }
}
