<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A figure and its quote, code block or table target share one attribute line,
 * so an id both set keeps the target's, and the row says why (markup-carve/carve#2386).
 */
class AFigureSharingItsTargetsLineNamesTheLostIdTest extends TestCase
{
    public function testTheDroppedFigureIdNamesTheSharedLine(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<figure id="f"><blockquote id="g"><p>q</p></blockquote><figcaption>Quote</figcaption></figure>',
        );
        $rows = [];
        foreach ($result->diagnostics as $diagnostic) {
            $rows[] = [$diagnostic->code, $diagnostic->message];
        }

        $this->assertSame([
            [
                'attribute-dropped',
                'Dropped one id on <figure>: the figure and its target both set id, and their two attribute lines merge into a single value',
            ],
        ], $rows);
    }

    public function testAnImageTargetKeepsItsOwnSlot(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<figure id="f"><img id="i" src="a.png" alt="a"><figcaption>Image</figcaption></figure>',
        );

        $this->assertSame([], $result->diagnostics);
    }
}
