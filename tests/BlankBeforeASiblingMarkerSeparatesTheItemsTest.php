<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L1c: the blank is read at the LIST's level, not the item's.
 *
 * L1's first disjunct asks what stands BETWEEN one item and the next sibling
 * marker. A blank line with nothing of the item after it stands between them
 * however the item's interior accounted for it - including where an
 * unterminated container carries it as content and the output shows it doing
 * so, as an unterminated code fence's empty payload line does.
 *
 * This engine already gave that answer for four of the six container kinds - a
 * div, an admonition, a raw block and a comment fence - because each stops its
 * collector at the blank and the list loop's own blank-skip then sees it. A code
 * fence and a tilde fence with no closer absorb the blank as a payload line
 * instead, so nothing saw it and the list stayed tight. That made the CLOSER
 * decide a rule that is not about closers: the same document with the closer
 * written loosened (markup-carve/carve-php#1445, markup-carve/carve#1383).
 *
 * The rejected reading was "did the container consume the blank". It makes a
 * structural answer depend on a detail the readers already spell differently,
 * and it cannot be asked of a div at all, where nothing in the output shows
 * which block took the line.
 */
class BlankBeforeASiblingMarkerSeparatesTheItemsTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * All six container kinds, unterminated, with the blank as the last line
     * before a sibling marker of the same list.
     *
     * The raw block's own payload is the ONE thing that still differs from the
     * executable spec here: this engine drops the trailing blank from a raw
     * block and keeps it in a code block. That difference is exactly why the
     * ruling reads the POSITION and not the payload, and it is out of scope -
     * the looseness answer is the same either way.
     *
     * @return array<string, array{string, string}>
     */
    public static function unterminatedContainers(): array
    {
        return [
            'code fence' => [
                "- ```\n  b\n\n- s\n",
                "<ul>\n  <li>\n    <pre><code>b\n\n</code></pre>\n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
            'tilde fence' => [
                "- ~~~\n  b\n\n- s\n",
                "<ul>\n  <li>\n    <pre><code>b\n\n</code></pre>\n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
            'div' => [
                "- :::\n  b\n\n- s\n",
                "<ul>\n  <li>\n    <div>\n      <p>b</p>\n    </div>\n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
            'admonition' => [
                "- ::: note\n  b\n\n- s\n",
                "<ul>\n  <li>\n    <aside class=\"admonition note\">\n      <p>b</p>\n    </aside>\n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
            'raw block' => [
                "- ```=html\n  b\n\n- s\n",
                "<ul>\n  <li>\n    b\n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
            'comment fence' => [
                "- %%%\n  b\n\n- s\n",
                "<ul>\n  <li><p>b</p></li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
            'an ordered list, whose marker width differs' => [
                "1. ```\n   b\n\n2. s\n",
                "<ol>\n  <li>\n    <pre><code>b\n\n</code></pre>\n  </li>\n  <li><p>s</p></li>\n</ol>\n",
            ],
            'the list reached through a quote' => [
                "> - ```\n>   b\n>\n> - s\n",
                "<blockquote>\n  <ul>\n    <li>\n      <pre><code>b\n\n</code></pre>\n    </li>\n    <li><p>s</p></li>\n  </ul>\n</blockquote>\n",
            ],
            'a run of two blanks, of which only the last precedes the marker' => [
                "- ```\n  b\n\n\n- s\n",
                "<ul>\n  <li>\n    <pre><code>b\n\n\n</code></pre>\n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('unterminatedContainers')]
    public function testABlankBeforeASiblingMarkerLoosensTheList(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }

    /**
     * THE BOUND, not a duplicate: the same documents with the closer written.
     *
     * These loosened before the fix and must still loosen after it. They are
     * what makes the defect visible in the first place - a rule about the
     * position of a blank line cannot have two answers depending on whether a
     * closer was typed.
     *
     * @return array<string, array{string, string}>
     */
    public static function terminatedContainers(): array
    {
        return [
            'code fence' => [
                "- ```\n  b\n  ```\n\n- s\n",
                "<ul>\n  <li>\n    <pre><code>b\n</code></pre>\n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
            'div' => [
                "- :::\n  b\n  :::\n\n- s\n",
                "<ul>\n  <li>\n    <div>\n      <p>b</p>\n    </div>\n  </li>\n  <li><p>s</p></li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('terminatedContainers')]
    public function testTheClosedSpellingStillLoosens(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }

    /**
     * INTENDED SURVIVORS. Every one of these has a blank line somewhere near a
     * marker and must stay tight, so a fix that loosens on the blank alone
     * fails here rather than in the corpus.
     *
     * `carve#326 C` is the first two: content follows the interior blank before
     * the marker, so nothing stands between the items. The clause's own stated
     * reason is the discriminator, and it holds for an interior blank whether
     * or not the fence is ever closed.
     *
     * The next three are the §11 axis controls: the marker after the blank must
     * belong to THIS list. A different bullet character, a different list type,
     * and a marker indented into the previous item's body each answer no, the
     * last one so firmly that the fence swallows the marker outright.
     *
     * The last two are the other half of L1: an item followed by a blank that
     * reaches no sibling at all ends the list, and `87-compact-list-blocks-6`
     * pins that pair.
     *
     * @return array<string, array{string, string}>
     */
    public static function tightShapes(): array
    {
        return [
            'an interior blank, closer written' => [
                "- ```\n  a\n\n  b\n  ```\n- c\n",
                "<ul>\n  <li>\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n  <li>c</li>\n</ul>\n",
            ],
            'an interior blank, no closer' => [
                "- ```\n  a\n\n  b\n- c\n",
                "<ul>\n  <li>\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n  <li>c</li>\n</ul>\n",
            ],
            'a different bullet character starts a different list' => [
                "- a\n- ```\n  b\n\n* s\n",
                "<ul>\n  <li>a</li>\n  <li>\n    <pre><code>b\n\n</code></pre>\n  </li>\n</ul>\n<ul>\n  <li>s</li>\n</ul>\n",
            ],
            'an ordered marker is not a sibling of a bullet item' => [
                "- a\n- ```\n  b\n\n1. s\n",
                "<ul>\n  <li>a</li>\n  <li>\n    <pre><code>b\n\n</code></pre>\n  </li>\n</ul>\n<ol>\n  <li>s</li>\n</ol>\n",
            ],
            'a marker at the content column is the fence\'s content' => [
                "- a\n- ```\n  b\n\n  - s\n",
                "<ul>\n  <li>a</li>\n  <li>\n    <pre><code>b\n\n- s\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'no blank line at all' => [
                "- ```\n  b\n- s\n",
                "<ul>\n  <li>\n    <pre><code>b\n</code></pre>\n  </li>\n  <li>s</li>\n</ul>\n",
            ],
            'the blank reaches a paragraph, not a marker' => [
                "- a\n- ```\n  b\n\ns\n",
                "<ul>\n  <li>a</li>\n  <li>\n    <pre><code>b\n\n</code></pre>\n  </li>\n</ul>\n<p>s</p>\n",
            ],
            'the blank ends the list' => [
                "- a\n- ```\n  b\n\n",
                "<ul>\n  <li>a</li>\n  <li>\n    <pre><code>b\n\n</code></pre>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('tightShapes')]
    public function testTheListStaysTight(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }
}
