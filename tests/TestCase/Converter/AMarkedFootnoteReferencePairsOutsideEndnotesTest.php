<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class AMarkedFootnoteReferencePairsOutsideEndnotesTest extends TestCase
{
    public function testMarkedReferencePairsWithAnAside(): void
    {
        $html = '<p><a href="#n1" role="doc-noteref">1</a></p>'
            . '<aside id="n1" role="doc-footnote"><p>body</p></aside>';

        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            self::assertSame(
                "[^1]\n\n[^1]: body\n",
                $result->value,
            );
            self::assertSame([], $result->report()['diagnostics']);
        }
    }

    public function testMarkedReferencePairsWithAnOrdinaryListItem(): void
    {
        $html = '<p><a href="#fn1" role="doc-noteref">1</a></p>'
            . '<ol><li id="fn1"><p>body</p><p>more</p></li></ol>';

        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            self::assertSame(
                "[^1]\n\n[^1]: body\n\n  more\n",
                $result->value,
            );
            self::assertSame([], $result->report()['diagnostics']);
        }
    }
}
