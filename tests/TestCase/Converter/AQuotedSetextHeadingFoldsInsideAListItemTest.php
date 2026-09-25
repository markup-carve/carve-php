<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line four columns into the paragraph a quote holds is continuation text,
 * and the underline below it makes one heading of the paragraph. That held at
 * the top level and not inside a list item: the heading vanished and a rule
 * nobody wrote took its place, so the imported document said something its
 * source did not (carve-php#2333).
 *
 * PINNED AT THE RENDERED LEVEL, because what was wrong is what a reader sees.
 * The Carve spelling of the same fold differs between engines - carve-js
 * dedents and escapes where this one keeps the indentation - while both oracles
 * read the same HTML.
 *
 * Measured against commonmark 0.31.2 and carve-js `9f81a0a`; every folding case
 * here matches commonmark, and the depth-one ones match carve-js byte for byte.
 */
final class AQuotedSetextHeadingFoldsInsideAListItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function foldsProvider(): array
    {
        return [
            // Every marker shape the held line can take. Each one opens a block
            // of its own three columns in and is text at four, which is the
            // whole question, so they are swept rather than sampled.
            'a heading marker' => [
                "- > foo\n  >     # bar\n  > ---\n",
                '<ul><li><blockquote><h2>foo # bar</h2></blockquote></li></ul>',
            ],
            'a bullet' => [
                "- > foo\n  >     - bar\n  > ---\n",
                '<ul><li><blockquote><h2>foo - bar</h2></blockquote></li></ul>',
            ],
            'a quote marker' => [
                "- > foo\n  >     > bar\n  > ---\n",
                '<ul><li><blockquote><h2>foo &gt; bar</h2></blockquote></li></ul>',
            ],
            'an ordered marker' => [
                "- > foo\n  >     1. bar\n  > ---\n",
                '<ul><li><blockquote><h2>foo 1. bar</h2></blockquote></li></ul>',
            ],
            'a fence opener' => [
                "- > foo\n  >     ```\n  > ---\n",
                '<ul><li><blockquote><h2>foo ```</h2></blockquote></li></ul>',
            ],
            'a thematic break' => [
                "- > foo\n  >     ***\n  > ---\n",
                '<ul><li><blockquote><h2>foo ***</h2></blockquote></li></ul>',
            ],
            'a pipe row' => [
                "- > foo\n  >     | bar\n  > ---\n",
                '<ul><li><blockquote><h2>foo | bar</h2></blockquote></li></ul>',
            ],
            'a reference definition' => [
                "- > foo\n  >     [a]: /u\n  > ---\n",
                '<ul><li><blockquote><h2>foo [a]: /u</h2></blockquote></li></ul>',
            ],
            'an equals underline gives h1' => [
                "- > foo\n  >     # bar\n  > ===\n",
                '<ul><li><blockquote><h1>foo # bar</h1></blockquote></li></ul>',
            ],
            'more than two paragraph lines' => [
                "- > foo\n  >     # bar\n  >     * baz\n  > ---\n",
                '<ul><li><blockquote><h2>foo # bar * baz</h2></blockquote></li></ul>',
            ],
            // Depth, which is what shows the fix is about the container rather
            // than about one column count.
            'two items deep' => [
                "- - > foo\n    >     # bar\n    > ---\n",
                '<ul><li><ul><li><blockquote><h2>foo # bar</h2></blockquote></li></ul></li></ul>',
            ],
            'four items deep' => [
                "- - - - > foo\n        >     # bar\n        > ---\n",
                '<ul><li><ul><li><ul><li><ul><li><blockquote><h2>foo # bar</h2>'
                    . '</blockquote></li></ul></li></ul></li></ul></li></ul>',
            ],
            'an ordered item' => [
                "1. > foo\n   >     1. bar\n   > ---\n",
                '<ol><li><blockquote><h2>foo 1. bar</h2></blockquote></li></ol>',
            ],
            'a marker with wide padding' => [
                "-   > foo\n    >     # bar\n    > ---\n",
                '<ul><li><blockquote><h2>foo # bar</h2></blockquote></li></ul>',
            ],
            'a tab for the item indent' => [
                "-\t> foo\n\t>     # bar\n\t> ---\n",
                '<ul><li><blockquote><h2>foo # bar</h2></blockquote></li></ul>',
            ],
            'a quote inside the quote' => [
                "- >> foo\n  >>     # bar\n  >> ---\n",
                '<ul><li><blockquote><blockquote><h2>foo # bar</h2></blockquote></blockquote></li></ul>',
            ],
            // The item's FIRST line is one code path and any later line is
            // another, so a fix to one leaves the other reading the four
            // columns as indented code.
            'a quote below the item text' => [
                "- text\n\n  > foo\n  >     # bar\n  > ---\n",
                '<ul><li><p>text</p><blockquote><h2>foo # bar</h2></blockquote></li></ul>',
            ],
            'a quoted fold with no indent at all' => [
                "- > foo\n  > bar\n  > ---\n",
                '<ul><li><blockquote><h2>foo bar</h2></blockquote></li></ul>',
            ],
            'a second quoted paragraph' => [
                "- > foo\n  >\n  > bar\n  >     # bar\n  > ---\n",
                '<ul><li><blockquote><p>foo</p><h2>bar # bar</h2></blockquote></li></ul>',
            ],
        ];
    }

    #[DataProvider('foldsProvider')]
    public function testAnIndentedLineFoldsIntoTheQuotedHeadingTheItemHolds(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            // Right before the change and right after it, which is what shows
            // the fold reached the item instead of rewriting the quoted fold as
            // a whole. Only the top-level one qualifies: the item's own
            // no-indent case was broken too, so it sits above as a fix.
            'a quoted fold at the top level' => [
                "> foo\n>     # bar\n> ---\n",
                '<blockquote><h2>foo # bar</h2></blockquote>',
            ],
            // Boundaries. Four columns in, an equals line is the paragraph's
            // own text rather than an underline, and a spaced rule is a rule.
            'an equals line four columns in stays text' => [
                "- > foo\n  >     bar\n  >     ===\n",
                '<ul><li><blockquote><p>foo bar ===</p></blockquote></li></ul>',
            ],
            'a spaced rule stays a rule' => [
                "- > foo\n  >     # bar\n  > - - -\n",
                '<ul><li><blockquote><p>foo # bar</p><hr></blockquote></li></ul>',
            ],
        ];
    }

    #[DataProvider('controlsProvider')]
    public function testWhatAlreadyHeldStillHolds(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * Three columns is not four, and the fold may not reach it.
     *
     * Asserted as the ABSENCE of the fold rather than as the whole rendering,
     * because what this engine does with the three-column case is itself wrong:
     * commonmark and carve-js both let the marker interrupt the paragraph, and
     * this engine folds it into the paragraph text. That is #2340, measured at
     * the top level too, and pinning the current HTML here would bake it in.
     */
    public function testTheFoldDoesNotReachThreeColumns(): void
    {
        $this->assertStringNotContainsString('<h2>', $this->render("- > foo\n  >    # bar\n  > ---\n"));
        $this->assertStringNotContainsString('<h1>', $this->render("- > foo\n  >    # bar\n  > ===\n"));
    }

    private function render(string $markdown): string
    {
        $result = (new MarkdownToCarve())->convert($markdown);
        $carve = is_string($result) ? $result : $result->getCarve();
        $html = (new CarveConverter())->convert($carve);

        return trim((string)preg_replace(['/\s+id="[^"]*"/', '/>\s+</', '/\s+/'], ['', '><', ' '], $html));
    }
}
