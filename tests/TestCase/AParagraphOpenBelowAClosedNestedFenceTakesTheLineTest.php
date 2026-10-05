<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph left open below a closed nested fence takes a flush-left line.
 *
 * PART 9 §10 lazy continuation, which the carve#2734 ruling leaves alone
 * because §10 already governs it: the line joins the paragraph in the INNER
 * item rather than becoming the outer item's own block, which is what
 * distinguishes this from that ruling's fold arm (markup-carve/carve-php#2903).
 *
 * Expectations are measured against the executable spec at the revision
 * `tests/spec` is pinned to (`scripts/spec/layout.mjs` into
 * `scripts/spec/html.mjs`), never read back from this engine.
 */
class AParagraphOpenBelowAClosedNestedFenceTakesTheLineTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    public function testTheOpenParagraphTakesTheFlushLeftLine(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> y x </li> </ul> </li> </ul>',
            $this->html("- - ```\n    ```\n    y\nx\n"),
        );
    }

    public function testATildeFenceAboveTheParagraphReadsTheSameWay(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> y x </li> </ul> </li> </ul>',
            $this->html("- - ~~~\n    ~~~\n    y\nx\n"),
        );
    }

    public function testTheParagraphKeepsTakingFurtherLines(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> y x z </li> </ul> </li> </ul>',
            $this->html("- - ```\n    ```\n    y\nx\nz\n"),
        );
    }

    /**
     * A blank line closes the paragraph, so the line below it is nobody's
     * continuation and leaves the list. This needs no case in the fix: the
     * tracker the fix reuses already ends a paragraph on a blank.
     */
    public function testABlankLineEndsTheParagraphAndTheLineLeaves(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> y </li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - ```\n    ```\n    y\n\nx\n"),
        );
    }

    /**
     * The anti-widening control. With NO paragraph open the line is carve#2734's
     * fold arm, so it must not land in the INNER item, which is where this fix
     * puts a line. Asserted structurally because which of the two outer homes
     * it takes is that ruling's question and not this one's.
     */
    public function testWithNoParagraphOpenTheLineDoesNotJoinTheInnerItem(): void
    {
        $html = $this->html("- - ```\n    ```\nx\n");
        $this->assertStringContainsString('<pre><code></code></pre> </li>', $html);
        $this->assertStringNotContainsString('<pre><code></code></pre> x', $html);
    }

    /**
     * §10 was already reached where no fence sits above the paragraph, and
     * where the fence is the item's OWN rather than a nested lead's. Pinned so
     * the fix is visibly about the nested closed fence and nothing else.
     */
    public function testAPlainNestedLeadWasAlreadyContinued(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li>y x</li> </ul> </li> </ul>',
            $this->html("- - y\nx\n"),
        );
    }

    public function testAnItemsOwnFenceAboveTheParagraphWasAlreadyContinued(): void
    {
        $this->assertSame(
            '<ul> <li> <pre><code></code></pre> y x </li> </ul>',
            $this->html("- ```\n  ```\n  y\nx\n"),
        );
    }
}
