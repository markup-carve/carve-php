<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lone `+` the note, description or quote refuses after its attached list ends that list (PART 9 §17 L3, carve#5583, carve#5588).
 */
final class AHostRefusedPlusEndsTheAttachedListTest extends TestCase
{
    /**
     * @var string
     */
    private const NOTE_HEAD = "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
        . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n";

    /**
     * @var string
     */
    private const NOTE_TAIL = "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
        . "    </li>\n  </ol>\n</section>";

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'note: a paragraph past the marker column' => [
            "[^a]: +\n- li\n+\n p\n\nsee[^a]\n",
            "<p>p</p>\n" . self::NOTE_HEAD . "      <ul>\n        <li>li</li>\n      </ul>\n" . self::NOTE_TAIL,
        ];

        yield 'description: a paragraph past the marker column' => [
            ":: t\n: +\n- li\n+\n p\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>li</li>\n    </ul>\n  </dd>\n</dl>\n<p>p</p>",
        ];

        yield 'note: a fence in the attached list' => [
            "[^a]: +\n- li\n  ```\n+\n\nsee[^a]\n",
            self::NOTE_HEAD . "      <ul>\n        <li>li\n          <pre><code></code></pre>\n        </li>\n      </ul>\n" . self::NOTE_TAIL,
        ];

        yield 'description: a fence in the attached list' => [
            ":: t\n: +\n- li\n  ```\n+\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>li\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>",
        ];

        // carve#5890 ruling B: the refused `+` ends the note's list, so a marker below the body column is the root's.
        yield 'note: a sibling item after the fence leaves the ended list' => [
            "[^a]: +\n- li\n  ```\n+\n - sib\n\nsee[^a]\n",
            "<ul>\n  <li>sib</li>\n</ul>\n" . self::NOTE_HEAD . "      <ul>\n        <li>li\n          <pre><code></code></pre>\n        </li>\n      </ul>\n"
                . self::NOTE_TAIL,
        ];

        yield 'note: a sibling term stays in the description list' => [
            "[^a]: +\n:: term\n: desc\n+\n:: t\n: d\n\nsee[^a]\n",
            self::NOTE_HEAD . "      <dl>\n        <dt>term</dt>\n        <dd>desc</dd>\n        <dt>t</dt>\n        <dd>d</dd>\n      </dl>\n"
                . self::NOTE_TAIL,
        ];

        yield 'quote: a line past the marker column' => [
            "> +\n- li\n+\n p\n",
            "<blockquote>\n  <ul>\n    <li>li</li>\n  </ul>\n</blockquote>\n<p>p</p>",
        ];

        yield 'note: a sibling item leaves the ended list' => [
            "[^a]: +\n- li\n+\n - sib\n\nsee[^a]\n",
            "<ul>\n  <li>sib</li>\n</ul>\n" . self::NOTE_HEAD . "      <ul>\n        <li>li</li>\n      </ul>\n" . self::NOTE_TAIL,
        ];
    }

    #[DataProvider('examples')]
    public function testTheRefusedMarkerEndsTheAttachedList(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
