<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reference definition is ANCHORED AT END OF LINE.
 *
 * `reference_definition = '[', reference_label, ']', ':', space,
 * link_destination, [link_title], [space, attributes], newline` ends in
 * `newline` and always did. What follows the destination and the optional title
 * is not ignored: it makes the production FAIL, and the line is then an ordinary
 * paragraph (markup-carve/carve#911). All three engines and the executable spec
 * read `[a]: /u zzz` as a definition with trailing junk, and nothing in the
 * grammar authorized it.
 *
 * BOTH HALVES OF EVERY SHAPE. A case here asserts the line rendering AND that a
 * `[a][]` below it no longer resolves. Checking only the first would pass for a
 * parser that printed the line and registered it anyway, which is the exact
 * defect this ruling is about - visible AND active is the outcome no reading
 * produces.
 *
 * THE PREDICATE SWEEP IS THE LIKELIEST WAY A CORRECT-LOOKING PATCH BREAKS
 * SOMETHING ELSE. The line is also asked "is this a definition?" by the
 * paragraph-interruption rule and by the block parser's consume pass. While the
 * pattern ended in a swallow-everything tail those could test the RAW line and
 * be right by accident, because `[a]: /u {.c}` matched it raw. Anchored, an
 * open-coded copy would have to split the trailing attribute block off first, or
 * `[a]: /u {.c}` stops interrupting a paragraph - so both cases below are here
 * and both directions are asserted.
 */
class ReferenceDefinitionIsAnchoredTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * Content after the production, one row per way of writing it.
     *
     * @return array<string, array{0: string}>
     */
    public static function trailingContentProvider(): array
    {
        return [
            'a bare word' => ['[a]: /u zzz'],
            'a word after a title' => ['[a]: /u "T" zzz'],
            'a word after an attribute block' => ['[a]: /u {.c} zzz'],
            'an unclosed attribute block' => ['[a]: /u {.c'],
            'a second attribute block' => ['[a]: /u {.c} {.d}'],
            'a no-break space' => ["[a]: /u\u{00A0}"],
            'an en quad' => ["[a]: /u\u{2000}"],
            'a form feed' => ["[a]: /u\u{000C}"],
            // BOTH mixed runs, at BOTH slots. A rule written as "the first
            // character must be a space" passes the tab-first fixture and admits
            // <SP><TAB>; written as "the last character must be a space" it
            // admits <TAB><SP> instead. Both spellings have been written for
            // real in this org.
            'a tab before a title' => ["[a]: /u\t\"T\""],
            'a space then a tab before a title' => ["[a]: /u \t\"T\""],
            'a tab then a space before a title' => ["[a]: /u\t \"T\""],
            'a tab before an attribute block' => ["[a]: /u\t{.c}"],
            'a space then a tab before an attribute block' => ["[a]: /u \t{.c}"],
            'a tab then a space before an attribute block' => ["[a]: /u\t {.c}"],
        ];
    }

    #[DataProvider('trailingContentProvider')]
    public function testTheLineIsProseAndDefinesNothing(string $line): void
    {
        $out = $this->html($line . "\n\n[a][]\n");

        $this->assertStringNotContainsString('<a ', $out);
        $this->assertStringContainsString('[a][]', $out);
    }

    /**
     * Every legal form, which an over-eager anchor would reject.
     *
     * The failure mode of this fix is on the other side, so the controls carry
     * as much weight as the rows above. `[a]: /u{.c}` is the trap: nothing is
     * left over there, because `link_destination` simply reads the braces, so it
     * is a different SHAPE from the two-space form rather than another spelling
     * of it. (Written out rather than shown: the formatter collapses a literal
     * double space in a doc block, which would turn the example into its own
     * opposite.)
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function legalFormProvider(): array
    {
        return [
            'destination only' => ['[a]: /u', '<a href="/u">a</a>'],
            'a title' => ['[a]: /u "T"', '<a href="/u" title="T">a</a>'],
            'an attribute block' => ['[a]: /u {.c}', '<a href="/u" class="c">a</a>'],
            'both' => ['[a]: /u "T" {.c}', '<a href="/u" title="T" class="c">a</a>'],
            'braces glued to the destination' => ['[a]: /u{.c}', '<a href="/u{.c}">a</a>'],
            'a trailing space' => ['[a]: /u ', '<a href="/u">a</a>'],
            'a trailing tab' => ["[a]: /u\t", '<a href="/u">a</a>'],
            'a trailing space, tab and space' => ["[a]: /u \t ", '<a href="/u">a</a>'],
            'a trailing space after an attribute block' => ['[a]: /u {.c} ', '<a href="/u" class="c">a</a>'],
        ];
    }

    #[DataProvider('legalFormProvider')]
    public function testALegalFormStillDefines(string $line, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->html($line . "\n\n[a][]\n"));
    }

    /**
     * A block that CLOSES at the end of the line but is not `attributes` is
     * handed BACK as content, so the anchor makes the line prose.
     *
     * THIS ANSWER MOVED with markup-carve/carve#933. It used to read "consumed
     * and valid are separate questions", which made the anchor unable to see the
     * failure: the balance scan peels the block off before anything validates
     * it, so a rejected block was already discarded when the anchor looked at
     * what was left, and the line defined with the author's braces gone from the
     * page. The remedy is a THIRD outcome from the scan, distinct both from
     * "there was no block" and from "the block was empty" - where those two are
     * one value, the rejection has nowhere to be observed.
     *
     * @return array<string, array{0: string}>
     */
    public static function rejectedAttributeBlockProvider(): array
    {
        return [
            // `attribute_list` needs at least one attribute; the blessed EMPTY
            // block is the inline span's and `item_attributes`', not this slot's.
            'an empty block' => ['[a]: /u {}'],
            'a space-only block' => ['[a]: /u { }'],
            'no identifier after the hash' => ['[a]: /u {#}'],
            'a bare equals' => ['[a]: /u {=}'],
            'a block with one invalid name' => ['[a]: /u {.a\\}b}'],
        ];
    }

    #[DataProvider('rejectedAttributeBlockProvider')]
    public function testARejectedAttributeBlockUnmakesTheDefinition(string $line): void
    {
        $out = $this->html($line . "\n\n[a][]\n");

        $this->assertStringNotContainsString('<a href="/u">a</a>', $out);
        // The braces survive on the page rather than being eaten: the whole
        // point of handing the block back rather than swallowing it.
        $this->assertStringContainsString('{', $out);
        $this->assertStringContainsString('<p>[a][]</p>', $out);
    }

    public function testAnAttributedDefinitionStillInterruptsAParagraph(): void
    {
        // The predicate side. `[a]: /u {.c}` renders nothing, so it ends the
        // paragraph above it - and a fix that tested the anchored line without
        // splitting the attribute block off would stop it doing so.
        $this->assertSame(
            "<p>text</p>\n<p><a href=\"/u\" class=\"c\">a</a></p>\n",
            $this->html("text\n[a]: /u {.c}\n\n[a][]\n"),
        );
    }

    public function testALineWithTrailingContentDoesNotInterruptAParagraph(): void
    {
        // The same predicate, the other direction: the line renders something
        // now, so it belongs to the paragraph above rather than ending it.
        $this->assertSame(
            "<p>text\n[a]: /u zzz</p>\n<p>[a][]</p>\n",
            $this->html("text\n[a]: /u zzz\n\n[a][]\n"),
        );
    }

    /**
     * Every container that carries its own copy of the collection rule.
     *
     * A definition is hoisted out of a list item, a definition list and a block
     * quote, and each path reaches the line through different code. A fix
     * landing on the top-level path alone leaves the others reading trailing
     * junk.
     *
     * @return array<string, array{0: string}>
     */
    public static function containerProvider(): array
    {
        return [
            'in a list item' => ["- text\n  [a]: /u zzz\n"],
            'in a definition list' => [":: term\n:  def\n[a]: /u zzz\n"],
            'lazy under a block quote' => ["> text\n[a]: /u zzz\n"],
            'inside a block quote' => ["> text\n> [a]: /u zzz\n"],
            'after a blank in a list item' => ["- text\n\n  [a]: /u zzz\n"],
        ];
    }

    #[DataProvider('containerProvider')]
    public function testAContainerReadsTheAnchorToo(string $source): void
    {
        $out = $this->html($source . "\n[a][]\n");

        $this->assertStringNotContainsString('<a ', $out);
        $this->assertStringContainsString('[a][]', $out);
    }

    public function testEveryShapeIsStillCovered(): void
    {
        // A row dropped from a provider would take its shape's coverage with it
        // and nothing else here would fail.
        $this->assertCount(14, self::trailingContentProvider());
        $this->assertCount(9, self::legalFormProvider());
        $this->assertCount(5, self::rejectedAttributeBlockProvider());
        $this->assertCount(5, self::containerProvider());
    }
}
