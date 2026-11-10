<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class ARebuiltEndnoteReportsOnlyAuthoredLossTest extends TestCase
{
    public function testRebuiltItemReportsItsLostAttributesAndNotItsRole(): void
    {
        $html = '<p><a href="#fn1" role="doc-noteref">1</a></p>'
            . '<section role="doc-endnotes"><ol><li id="fn1" role="doc-endnote" hidden><p>body</p></li></ol></section>';

        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);

            self::assertSame("[^1]\n\n[^1]: body\n", $result->value);
            self::assertSame(
                ['attribute-dropped info Dropped unsupported attribute hidden on <li>'],
                array_map(
                    static fn (array $row): string => $row['code'] . ' ' . $row['severity'] . ' ' . $row['message'],
                    $result->report()['diagnostics'],
                ),
            );
        }
    }

    public function testStructuralRoleAloneIsNotReported(): void
    {
        $html = '<p><a href="#fn1" role="doc-noteref">1</a></p>'
            . '<section role="doc-endnotes"><ol><li id="fn1" role="doc-endnote"><p>body</p></li></ol></section>';

        self::assertSame([], (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics']);
    }

    public function testEmptyNonBooleanValuesCarryNoLoss(): void
    {
        $html = '<p><a href="#fn1" role="doc-noteref">1</a></p>'
            . '<section role="doc-endnotes"><ol><li id="fn1" class="" title=""><p>body</p></li></ol></section>';

        self::assertSame([], (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics']);
    }
}
