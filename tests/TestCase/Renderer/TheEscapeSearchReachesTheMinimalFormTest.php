<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §2: the escape narrowing search leaves no idle escape where it can
 * finish (markup-carve/carve-js#2168).
 */
class TheEscapeSearchReachesTheMinimalFormTest extends TestCase
{
    public function testABracketPairSplitByANestedLinkIsWrittenBare(): void
    {
        $html = '<p class="n">* a</p><p><span class="c">[<a href="/u">x</a>]</span></p>';

        $this->assertSame("{.n}\n\\* a\n\n[[[x](/u)]]{.c}\n", (new HtmlToCarve())->convert($html));
    }

    public function testTheSearchFinishesOnALongDocumentWhoseFailingUnitsAreSparse(): void
    {
        $body = '';
        for ($i = 0; $i < 1000; $i++) {
            $body .= $i % 50 === 0 ? "<p class=\"n\">* item {$i} and 1. two</p>" : "<p>note ({$i}) here.</p>";
        }
        $carve = (new HtmlToCarve())->convert('<body>' . $body . '</body>');

        $this->assertSame(20, substr_count($carve, '\\* item'));
        $this->assertStringNotContainsString('\\(', $carve);
        $this->assertStringNotContainsString('1\\.', $carve);
    }
}
