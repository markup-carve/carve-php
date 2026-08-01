<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A row's own attributes survive the bridge.
 *
 * `renderTableRows` builds the `tableRow` node directly instead of going through
 * the generic block path, which is what attaches `attrs` everywhere else - so
 * `{.head}` on a row was dropped across a round trip. Introduced with the
 * placeholder-cell model (carve-php#557) and measured by the corpus sweep
 * (carve-php#519).
 */
class TableRowAttributesTest extends TestCase
{
    public function testARowsAttributesSurviveTheRoundTrip(): void
    {
        $source = "| Name | Score |{.head}\n|------|-------|\n| Ann  | 9     |{.win}\n";

        $document = (new CarveConverter())->parse($source);
        $expected = (new CarveConverter())->render($document);

        $proseMirror = (new ProseMirrorRenderer())->render($document);
        $actual = (new CarveConverter())->render((new ProseMirrorToCarve())->convert($proseMirror));

        $this->assertSame($expected, $actual);
    }

    public function testTheRowAttributeReachesTheEditorPayload(): void
    {
        // Asserted on the payload as well as the round trip: a bridge that
        // dropped the attribute on the way out and guessed it back on the way in
        // would pass the test above while still losing it for a real editor.
        $document = (new CarveConverter())->parse("| a |{.head}\n|---|\n| b |\n");
        $payload = (new ProseMirrorRenderer())->render($document);

        $rows = $payload['content'][0]['content'] ?? [];
        $this->assertSame(['class' => 'head'], $rows[0]['attrs'] ?? null);
    }
}
