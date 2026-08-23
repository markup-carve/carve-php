<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A delimiter this converter owns is owned per OCCURRENCE, not per CHARACTER.
 *
 * `HANDLED_MARKDOWN` names the characters `MarkdownToCarve` rewrites itself, so
 * they are held back from escaping. A backtick is on that list because a
 * matched pair is a code span the pass carries over - and an UNMATCHED backtick
 * is ordinary text in CommonMark and GFM, which the character-keyed table has
 * no way to say. Carried across bare it opened a Carve code span, and the
 * UNCLOSED RUN clause runs that span to the end of the block, so everything
 * after the character became verbatim content (carve-php#1624).
 *
 * `{#id}` splits the same way across SOURCE LANGUAGES rather than occurrences:
 * an attribute block in Djot is one the author wrote, and in Markdown the same
 * characters are text.
 *
 * PART 11 §2 is the rule in both halves: escape a character if and only if
 * omitting the escape would change the re-parsed AST. Pinned by render, the way
 * MarkdownCarveConstructsStayTextTest next door pins its own claim.
 */
class DelimiterOwnershipIsPerOccurrenceTest extends TestCase
{
    protected function render(string $markdown, bool $attributes = false): string
    {
        $carve = (new MarkdownToCarve(convertAttributes: $attributes))->convert($markdown);

        return rtrim((new CarveConverter())->convert($carve), "\n");
    }

    public function testAnUnmatchedBacktickIsText(): void
    {
        $this->assertSame('<p>a ' . chr(96) . 'b</p>', $this->render('a ' . chr(96) . "b\n"));
    }

    /**
     * Every backtick of the run, not only the first: what is left of a partly
     * escaped run is a shorter run, and it opens a span just the same.
     */
    public function testAnUnmatchedRunOfBacktialsIsTextRightThrough(): void
    {
        $this->assertSame('<p>a ' . str_repeat(chr(96), 2) . 'b</p>', $this->render('a ' . str_repeat(chr(96), 2) . "b\n"));
    }

    public function testABracedIdIsText(): void
    {
        $this->assertSame('<p>a {#id} b</p>', $this->render("a {#id} b\n"));
    }

    // ==================== The bounds ====================

    /**
     * A MATCHED BACKTICK IS STILL A DELIMITER. The whole point of the character
     * being on the handled list is that this pass carries the pair over, and an
     * escape here would freeze a code span into literal text.
     */
    public function testAMatchedBacktickIsStillACodeSpan(): void
    {
        $this->assertSame('<p>a <code>b</code> c</p>', $this->render('a ' . chr(96) . 'b' . chr(96) . " c\n"));
    }

    public function testAMatchedRunOfBacktialsIsStillACodeSpan(): void
    {
        $run = str_repeat(chr(96), 2);
        $this->assertSame('<p>a <code>b</code> c</p>', $this->render('a ' . $run . 'b' . $run . " c\n"));
    }

    /**
     * A BACKTICK INSIDE A FENCED BLOCK IS CONTENT and never reaches the inline
     * pass at all.
     */
    public function testABacktickInsideAFencedBlockIsUntouched(): void
    {
        $fence = str_repeat(chr(96), 3);
        $markdown = $fence . "\na " . chr(96) . " b\n" . $fence . "\n";

        $this->assertSame(
            '<pre><code>a ' . chr(96) . " b\n</code></pre>",
            $this->render($markdown),
        );
    }

    /**
     * A CALLER THAT ASKED FOR MARKDOWN ATTRIBUTE EXTENSIONS still means the
     * braces. The escape is gated on the same flag the attaching form is.
     */
    public function testTheAttributesFlagOptsTheBracedIdBackIn(): void
    {
        $this->assertNotSame('<p>a {#id} b</p>', $this->render("a {#id} b\n", attributes: true));
    }

    /**
     * A HEADING IS NOT A BRACED ID. The rule escapes a brace that opens an
     * attribute block, and nothing else that carries a `#`.
     */
    public function testAHeadingIsUnchanged(): void
    {
        $this->assertStringContainsString('<h1>Title</h1>', $this->render("# Title\n"));
    }

    /**
     * A CLASS OR KEY-VALUE LIST IS NOT AN ATTRIBUTE BLOCK OPENER. `{.cls}` was
     * already literal to the parser, so escaping its brace would put a
     * backslash in front of a character that needed none.
     */
    public function testAClassListInProseIsUnchanged(): void
    {
        // Asserted on the SOURCE, not the render: a needless escape renders
        // the same literal text and would pass a render-only check, while
        // leaving a backslash in the migrated document the author has to
        // delete by hand.
        $this->assertSame("a {.cls} b\n", (new MarkdownToCarve())->convert("a {.cls} b\n"));
        $this->assertSame('<p>a {.cls} b</p>', $this->render("a {.cls} b\n"));
    }

    /**
     * DJOT IS THE OTHER SIDE OF THE SPLIT. `{#id}` in Djot source is an
     * attribute block the author wrote, so its converter must carry it across
     * bare - this fix must not reach it.
     */
    public function testDjotCarriesABracedIdAcrossBare(): void
    {
        $this->assertSame("a {#id} b\n", (new DjotToCarve())->convert("a {#id} b\n"));
    }

    /**
     * And a Djot backtick is a code span delimiter in its own language, matched
     * or not, so nothing escapes it there either.
     */
    public function testDjotCarriesAnUnmatchedBacktickAcrossBare(): void
    {
        $this->assertSame('a ' . chr(96) . "b\n", (new DjotToCarve())->convert('a ' . chr(96) . "b\n"));
    }
}
