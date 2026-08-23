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
                "- x\n\n  - a\n\n\n\n  - b\n",
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
}
