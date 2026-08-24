<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition body is an indented-block collector like a list item and a block
 * quote, so a line BELOW the body's content column does not fold into an OPEN
 * FENCE.
 *
 * S1 stops at the DEFINITION ENTRY, S2 FENCED BODY never fires, and S4's lazy
 * branch wants an open PARAGRAPH. A verbatim body is not one, so the containers
 * close and the tail re-parses at document level, byte for byte the answer
 * corpus 276 already pins for the list spelling.
 */
class DefinitionBodyFenceBelowTheColumnTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function belowTheColumnProvider(): array
    {
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>\n</code></pre>\n  </dd>\n</dl>\n<p>body\n<code></code></p>\n";

        return [
            'at column 0' => [
                ":: t\n:  ```\nbody\n```\n",
                $expected,
            ],
            'at column 1' => [
                ":: t\n:  ```\n body\n ```\n",
                $expected,
            ],
            'at column 2' => [
                ":: t\n:  ```\n  body\n  ```\n",
                $expected,
            ],
        ];
    }

    #[DataProvider('belowTheColumnProvider')]
    public function testABelowColumnLineDoesNotFoldIntoAnOpenFence(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    public function testABelowColumnTildeLineDoesNotFoldIntoAnOpenFence(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>\n</code></pre>\n  </dd>\n</dl>\n<p>body\n~~~</p>\n",
            $this->html(":: t\n:  ~~~\nbody\n~~~\n"),
        );
    }

    /**
     * AT OR PAST the column the line is the fence's content - and PAST the
     * column it keeps the columns it wrote past it, because the body is
     * dedented by the description's content column and by nothing more.
     *
     * The `past the column` row used to expect the at-the-column rendering,
     * which needed the body to arrive `ltrim`ed. carve-js writes the residual
     * column into the payload and never closes the fence on an indented closer,
     * so the two rows differ there; both were compared against carve-js
     * `ba42673` and are byte-identical to it
     * (markup-carve/carve-php#1650).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function atOrPastTheColumnProvider(): array
    {
        return [
            'at the column' => [
                ":: t\n:  ```\n   body\n   ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>body\n</code></pre>\n  </dd>\n</dl>\n",
            ],
            'past the column, keeping the column it wrote past it' => [
                ":: t\n:  ```\n    body\n    ```\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code> body\n ```\n</code></pre>\n  </dd>\n</dl>\n",
            ],
        ];
    }

    #[DataProvider('atOrPastTheColumnProvider')]
    public function testAtOrPastTheColumnTheLineIsStillTheFencesContent(
        string $source,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->html($source));
    }

    public function testTheGuardIsOnTheOpenFenceNotOnTheMarkerLine(): void
    {
        // This row fails if the guard asks "did the marker line open a fence"
        // instead of "is a fence open now": the fence closed at the column and
        // a paragraph reopened, so the below-column line folds in as it always
        // did.
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>x\n</code></pre>\n    <p>para\nbelow</p>\n  </dd>\n</dl>\n",
            $this->html(":: t\n:  ```\n   x\n   ```\n   para\nbelow\n"),
        );
    }

    /**
     * A CLOSED fence with nothing after it is no more an open paragraph than an
     * open one, so the body ends there too.
     *
     * Raised by `codex review` against the first version of this fix, which
     * asked only about `inFence`. S4's lazy branch wants an open PARAGRAPH, and
     * a finished code block is not one - which is why the list and block-quote
     * spellings both put this line at document level and only the definition
     * spelling kept it inside the container. Asking the question the other two
     * collectors ask closes the last gap rather than half of it.
     */
    public function testAClosedFenceWithNoParagraphAfterItEndsTheBody(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>x\n</code></pre>\n  </dd>\n</dl>\n<p>below</p>\n",
            $this->html(":: t\n:  ```\n   x\n   ```\nbelow\n"),
        );
        // The two spellings it now agrees with.
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code>x\n</code></pre>\n  </li>\n</ul>\n<p>below</p>\n",
            $this->html("- ```\n  x\n  ```\nbelow\n"),
        );
        $this->assertSame(
            "<blockquote>\n  <pre><code>x\n</code></pre>\n</blockquote>\n<p>below</p>\n",
            $this->html("> ```\n> x\n> ```\nbelow\n"),
        );
    }

    public function testAPlainBodyStillTakesALazyLine(): void
    {
        // CONTROL for lazy continuation surviving the change.
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>para\nbelow</dd>\n</dl>\n",
            $this->html(":: t\n:  para\nbelow\n"),
        );
    }

    public function testTheOtherTwoCollectorsAgree(): void
    {
        // The two spellings that already answered this way, so the definition
        // spelling closes the set rather than being enumerated beside them.
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n<p>x\n<code></code></p>\n",
            $this->html("- ```\nx\n```\n"),
        );
        $this->assertSame(
            "<blockquote>\n  <pre><code>\n</code></pre>\n</blockquote>\n<p>x\n<code></code></p>\n",
            $this->html("> ```\nx\n```\n"),
        );
    }
}
