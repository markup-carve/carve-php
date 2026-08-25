<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AN ORDERED LIST HOLDING A TASK CHECKBOX IMPORTS, AND AT THE RIGHT COLUMN
 * (`markup-carve/carve-php#1712`).
 *
 * The importer used to THROW on this shape. `processList()` computed a
 * continuation indent as the prefix width minus the checkbox width, which is
 * safe only where the prefix CONTAINS the checkbox - and the ordered branch
 * built a prefix that did not, so `1. ` (three columns) minus `[ ] ` (four)
 * went negative and `str_repeat()` refused it. Every ordered task list reached
 * it, not a corner case.
 *
 * IT IS ALREADY FIXED, and not by a clamp. `markup-carve/carve-php#1695` and
 * `markup-carve/carve-php#1706` replaced the subtraction with the BARE MARKER's
 * width, which is the content-column rule both branches share: a checkbox is
 * CONTENT, so it does not move the column, and with one width for both branches
 * the arithmetic is right by construction rather than guarded. Nothing pinned
 * the ordered shape afterwards, which is what this file is for - a crash class
 * with no test is a crash class waiting to come back.
 *
 * THE COLUMN IS ASSERTED, NOT JUST THE ABSENCE OF A THROW. A clamp to zero
 * would also stop the throw and would write the body at column 0, where it
 * closes the item and leaves the list; a test that only caught the exception
 * could not tell the two apart. Every expectation below is what carve-js writes
 * for the same input, byte for byte.
 */
class AnOrderedTaskItemImportsAtItsContentColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function orderedProvider(): array
    {
        return [
            // The input from the ticket, which used to throw.
            'unchecked' => [
                '<ol><li><input type="checkbox"> a</li></ol>',
                "1. a\n",
            ],
            'checked' => [
                '<ol><li><input type="checkbox" checked> a</li></ol>',
                "1. a\n",
            ],
            // THE COLUMN, which is the half a clamp would have got wrong. `1. `
            // is three wide, so the body sits at column 3 and stays inside the
            // item; at column 0 it would close the list.
            'a continuation block sits at the marker width' => [
                '<ol><li><input type="checkbox"> a<p>body</p></li></ol>',
                "1. a\n\n   body\n",
            ],
            // MARKER ATTRIBUTES sit in the prefix too, and they are metadata:
            // they do not widen the bare marker's content column either
            // (`markup-carve/carve#1701`), so the body stays at 3.
            'marker attributes do not move it' => [
                '<ol><li class="c"><input type="checkbox"> a<p>body</p></li></ol>',
                "1.{.c} a\n\n   body\n",
            ],
            // The other ordered spellings go through the same branch, so one
            // fix covers them - asserted rather than assumed.
            'alphabetic markers' => [
                '<ol type="a"><li><input type="checkbox"> a<p>body</p></li></ol>',
                "a. a\n\n   body\n",
            ],
            'roman markers' => [
                '<ol type="i"><li><input type="checkbox"> a<p>body</p></li></ol>',
                "i. a\n\n   body\n",
            ],
            // A WIDER MARKER MOVES THE COLUMN WITH IT: `10. ` is four wide.
            // This is the case that would still pass under a fix that hard-coded
            // three, so it is worth its own row.
            'a two-digit start widens the column' => [
                '<ol start="10"><li><input type="checkbox"> a<p>body</p></li></ol>',
                "10. a\n\n    body\n",
            ],
        ];
    }

    #[DataProvider('orderedProvider')]
    public function testAnOrderedTaskItemImports(string $html, string $expected): void
    {
        $this->assertSame($expected, (new HtmlToCarve())->convert($html));
    }

    /**
     * THE BULLET BRANCH IS THE ONE THAT WAS ALWAYS RIGHT, and it has to stay
     * right: it is the branch whose prefix DOES contain the checkbox, so a fix
     * that made the two agree could have moved this one instead.
     *
     * `- [ ] ` is six wide and its content column is 2, because the checkbox is
     * content and does not move it.
     */
    public function testTheBulletBranchKeepsItsOwnColumn(): void
    {
        $this->assertSame(
            "- [ ] a\n\n  body\n",
            (new HtmlToCarve())->convert('<ul><li><input type="checkbox"> a<p>body</p></li></ul>'),
        );
    }

    /**
     * THE BODY IS STILL IN THE ITEM. The column assertions above are exact
     * bytes, and exact bytes can be right about the spaces and wrong about what
     * they mean, so this reads the source back: a body at the wrong column comes
     * back as a sibling of the list rather than a block inside its item.
     */
    public function testTheContinuationBlockStaysInsideTheItem(): void
    {
        $carve = (new HtmlToCarve())->convert('<ol><li><input type="checkbox"> a<p>body</p></li></ol>');
        $html = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString('body', $html);
        $this->assertSame(1, substr_count($html, '<li>'), $html);
        // The body is inside the item, so the list closes after it.
        $this->assertStringNotContainsString('</ol>', substr($html, 0, (int)strpos($html, 'body')), $html);
    }
}
