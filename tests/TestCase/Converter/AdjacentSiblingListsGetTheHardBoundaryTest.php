<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two adjacent sibling lists are parted by the HARD LIST BOUNDARY - three blank
 * lines, PART 9 §11 N1a - and every list keeps the marker it was
 * authored with.
 *
 * The importer used to ALTERNATE the marker across adjacent siblings instead:
 * `-`/`*` for bullets, `.`/`)` for ordered lists (carve-php#1290), because two
 * same-marker lists parted by a single blank line reparse as one list. That
 * invented a marker the source HTML never carried, it could only ever separate
 * TWO lists, and it disagreed with the importers in carve-js and carve-rs. The
 * boundary says the same thing in the language's own words, so the marker axis
 * is free again.
 */
class AdjacentSiblingListsGetTheHardBoundaryTest extends TestCase
{
    protected HtmlToCarve $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new HtmlToCarve();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function boundaryProvider(): array
    {
        return [
            'two bullet lists' => [
                '<ul><li>apples</li></ul><ul><li>oranges</li></ul>',
                "- apples\n\n\n\n- oranges\n",
            ],
            'two ordered lists' => [
                '<ol><li>apples</li></ol><ol><li>oranges</li></ol>',
                "1. apples\n\n\n\n1. oranges\n",
            ],
            'three bullet lists in a row' => [
                '<ul><li>a</li></ul><ul><li>b</li></ul><ul><li>c</li></ul>',
                "- a\n\n\n\n- b\n\n\n\n- c\n",
            ],
            'three ordered lists in a row' => [
                '<ol><li>a</li></ol><ol><li>b</li></ol><ol><li>c</li></ol>',
                "1. a\n\n\n\n1. b\n\n\n\n1. c\n",
            ],
            // The boundary is not a top-level affair: two sublists inside one
            // item merge just as readily, and at that depth the old alternation
            // was the only thing keeping them apart.
            'adjacent lists nested in an item' => [
                '<ul><li>x<ul><li>a</li></ul><ul><li>b</li></ul></li></ul>',
                "- x\n  - a\n\n\n\n  - b\n",
            ],
            // An explicit marker is round-trip fidelity and survives: the
            // boundary is what separates the two, so the marker does not have
            // to be spent on saying it.
            'explicit marker on both lists' => [
                '<ul data-marker="*"><li>a</li></ul><ul data-marker="*"><li>b</li></ul>',
                "* a\n\n\n\n* b\n",
            ],
            'two task lists' => [
                '<ul class="task-list"><li><input type="checkbox">a</li></ul>'
                . '<ul class="task-list"><li><input type="checkbox">b</li></ul>',
                "- [ ] a\n\n\n\n- [ ] b\n",
            ],
            // INSIDE A BLOCK QUOTE the boundary is three `>` lines. Three EMPTY
            // lines would end the quote and drop the second list out of it, so
            // the sentinel is expanded with whatever prefix ended up to its
            // left rather than as bare blank lines.
            'two lists inside a block quote' => [
                '<blockquote><ul><li>a</li></ul><ul><li>b</li></ul></blockquote>',
                ">\n> - a\n>\n>\n>\n> - b\n",
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('boundaryProvider')]
    public function testTheImportSpellsTheBoundary(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($html));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noBoundaryProvider(): array
    {
        return [
            // Different list types: nothing to merge in the first place.
            'bullet then ordered' => [
                '<ul><li>a</li></ul><ol><li>b</li></ol>',
                "- a\n\n1. b\n",
            ],
            // The markers already differ, so the single blank line is enough
            // and the boundary would be noise.
            'markers differ' => [
                '<ul><li>a</li></ul><ul data-marker="*"><li>b</li></ul>',
                "- a\n\n* b\n",
            ],
            // Same delimiter, different numbering style - the third axis
            // `CarveRenderer::listsWouldMerge()` reads.
            'numbering styles differ' => [
                '<ol><li>a</li></ol><ol type="a"><li>b</li></ol>',
                "1. a\n\na. b\n",
            ],
            // A block between the lists separates them by itself.
            'a paragraph between the lists' => [
                '<ul><li>a</li></ul><p>hi</p><ul><li>b</li></ul>',
                "- a\n\nhi\n\n- b\n",
            ],
            // A task list is its own list type: `- [ ] a` and `- b` parse as
            // two lists however they are laid out, so the marker they share
            // never merges them.
            'a task list next to a plain one' => [
                '<ul class="task-list"><li><input type="checkbox">a</li></ul><ul><li>b</li></ul>',
                "- [ ] a\n\n- b\n",
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('noBoundaryProvider')]
    public function testListsThatAlreadyDifferKeepTheOrdinarySeparation(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($html));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'two bullet lists' => ['<ul><li>a</li></ul><ul><li>b</li></ul>', 2, 'ul'],
            'three bullet lists' => ['<ul><li>a</li></ul><ul><li>b</li></ul><ul><li>c</li></ul>', 3, 'ul'],
            'two ordered lists' => ['<ol><li>a</li></ol><ol><li>b</li></ol>', 2, 'ol'],
            'three ordered lists' => ['<ol><li>a</li></ol><ol><li>b</li></ol><ol><li>c</li></ol>', 3, 'ol'],
            'adjacent lists nested in an item' => [
                '<ul><li>x<ul><li>a</li></ul><ul><li>b</li></ul></li></ul>',
                3,
                'ul',
            ],
            'two lists inside a block quote' => [
                '<blockquote><ul><li>a</li></ul><ul><li>b</li></ul></blockquote>',
                2,
                'ul',
            ],
            'a task list next to a plain one' => [
                '<ul class="task-list"><li><input type="checkbox">a</li></ul><ul><li>b</li></ul>',
                2,
                'ul',
            ],
        ];
    }

    /**
     * @param string $html
     * @param int $lists
     * @param string $tag
     */
    #[DataProvider('roundTripProvider')]
    public function testTheImportedSourceRendersTheListsBack(string $html, int $lists, string $tag): void
    {
        $imported = $this->converter->convert($html);
        $back = (new CarveConverter())->convert($imported);

        $this->assertSame(
            $lists,
            substr_count($back, '<' . $tag . '>'),
            "the boundary must keep the lists apart; imported source was:\n" . $imported,
        );
    }

    /**
     * An element between the lists that RENDERS TO NOTHING (carve-php#1617).
     *
     * `noBoundaryProvider`'s paragraph case says "a block between the lists
     * separates them by itself", and that is true of a block that WRITES
     * something. An empty one writes nothing, so nothing stands between the two
     * markers in the emitted source and they merge back into one list - the
     * exact damage the boundary exists to prevent, reached by the one route
     * that skipped the question.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function writesNothingProvider(): array
    {
        return [
            'an empty paragraph' => ['<p></p>', "- a\n\n\n\n- b\n"],
            'a paragraph of one space' => ['<p> </p>', "- a\n\n\n\n- b\n"],
            'a paragraph of one newline' => ["<p>\n</p>", "- a\n\n\n\n- b\n"],
            'a paragraph holding an empty span' => ['<p><span></span></p>', "- a\n\n\n\n- b\n"],
            'an empty div' => ['<div></div>', "- a\n\n\n\n- b\n"],
            'an empty span' => ['<span></span>', "- a\n\n\n\n- b\n"],
            'an empty anchor' => ['<a></a>', "- a\n\n\n\n- b\n"],
            'an empty emphasis' => ['<em></em>', "- a\n\n\n\n- b\n"],
            'an empty table' => ['<table></table>', "- a\n\n\n\n- b\n"],
            // All text, none of it written.
            'a script' => ['<script>x()</script>', "- a\n\n\n\n- b\n"],
            'a style' => ['<style>p{}</style>', "- a\n\n\n\n- b\n"],
            // The walk steps over as many as it meets, not just one.
            'two empty paragraphs' => ['<p></p><p></p>', "- a\n\n\n\n- b\n"],
        ];
    }

    /**
     * @param string $between
     * @param string $expected
     */
    #[DataProvider('writesNothingProvider')]
    public function testAnElementThatWritesNothingDoesNotSeparateTheLists(string $between, string $expected): void
    {
        $html = '<ul><li>a</li></ul>' . $between . '<ul><li>b</li></ul>';

        $this->assertSame($expected, $this->converter->convert($html));
        $this->assertSame(
            2,
            substr_count((new CarveConverter())->convert($this->converter->convert($html)), '<ul>'),
            'the two lists must come back as two',
        );
    }

    /**
     * The other half: what DOES write something still separates them, and the
     * walk must not step over it (carve-php#1617).
     *
     * Over-stepping is the failure this direction catches, and it is the one
     * that passes every gate aimed at the shape above: an inserted boundary
     * where content already parts the lists is damage the fix would cause
     * rather than repair. A text node counts as content, which is why the walk
     * reads every node type and not just elements.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function writesSomethingProvider(): array
    {
        return [
            'a paragraph with text' => ['<p>hi</p>', "- a\n\nhi\n\n- b\n"],
            'a heading' => ['<h2>h</h2>', "- a\n\n## h\n\n- b\n"],
            'a block quote' => ['<blockquote><p>q</p></blockquote>', "- a\n\n> q\n\n- b\n"],
            'a code block' => ['<pre><code>c</code></pre>', "- a\n\n```\nc\n```\n\n- b\n"],
            // An element with no text that still stands for itself.
            'an image' => ['<img src="i.png" alt="i">', "- a\n\n![i](i.png)\n\n- b\n"],
            // A bare text node between the lists is content the walk stops at.
            'a run of text' => ['text', "- a\n\ntext\n\n- b\n"],
        ];
    }

    /**
     * @param string $between
     * @param string $expected
     */
    #[DataProvider('writesSomethingProvider')]
    public function testWhatWritesSomethingStillSeparatesTheLists(string $between, string $expected): void
    {
        $html = '<ul><li>a</li></ul>' . $between . '<ul><li>b</li></ul>';

        $this->assertSame($expected, $this->converter->convert($html));
    }

    /**
     * The boundary is not a top-level affair, and neither is the walk
     * (carve-php#1617). A comment writes nothing at any depth.
     */
    public function testTheWalkHoldsInsideAContainerAndInsideAnItem(): void
    {
        $quoted = $this->converter->convert(
            '<blockquote><ul><li>a</li></ul><p></p><ul><li>b</li></ul></blockquote>',
        );
        $this->assertSame(">\n> - a\n>\n>\n>\n> - b\n", $quoted);
        $this->assertSame(2, substr_count((new CarveConverter())->convert($quoted), '<ul>'));

        // Three lists in the source - the item's own, and the two inside it.
        $item = $this->converter->convert(
            '<ul><li><ul><li>a</li></ul><p></p><ul><li>b</li></ul></li></ul>',
        );
        $this->assertSame(3, substr_count((new CarveConverter())->convert($item), '<ul>'));

        // A COMMENT WRITES A BLOCK NOW (`markup-carve/carve#1709`), so it is
        // what parts the two lists and the hard boundary is not needed behind
        // it. This used to assert the boundary's three blank lines, because the
        // importer deleted the comment and nothing else stood between them.
        //
        // The lists must still come back as TWO, which is the property the
        // boundary existed to hold - so that is asserted rather than assumed.
        $commented = $this->converter->convert('<ul><li>a</li></ul><!-- c --><ul><li>b</li></ul>');
        $this->assertSame("- a\n\n%%%\n c \n%%%\n\n- b\n", $commented);
        $this->assertSame(2, substr_count((new CarveConverter())->convert($commented), '<ul>'));
    }
}
