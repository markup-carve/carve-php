<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A setext heading inside a quote that an item inside a quote holds folds into
 * the one ATX line Carve spells it with.
 *
 * Two columns come off before the fold can read a line at this depth: the
 * item's content column, and then the held quote's own prefix. The quoted fold
 * stripped only its own, so the held marker stayed text of the line it stood on
 * and the fold refused - which lost the heading AND left a rule the source
 * never spelled, since nothing above the underline closed (carve-php#2355).
 *
 * PINNED AT THE RENDERED LEVEL, since the converter corpus compares an importer
 * by rendering its output.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (carve#2187); commonmark 0.31.2 agrees on every case here.
 */
final class AQuoteAQuotedItemHoldsFoldsItsSetextHeadingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function foldProvider(): array
    {
        return [
            // The dash form is the severe half: the heading is lost and a rule
            // appears in its place.
            'a hyphen underline makes a level-two heading' => [
                "> - > foo\n>   >     # bar\n>   > ---\n",
                '<blockquote><ul><li><blockquote><h2>foo # bar</h2></blockquote></li></ul></blockquote>',
            ],
            'an equals underline makes a level-one heading' => [
                "> - > foo\n>   >     # bar\n>   > ===\n",
                '<blockquote><ul><li><blockquote><h1>foo # bar</h1></blockquote></li></ul></blockquote>',
            ],
            // At the held quote's own column rather than four past it, which is
            // the plain continuation line and folds for the same reason.
            'a line at the held quote content column folds' => [
                "> - > foo\n>   > bar\n>   > ---\n",
                '<blockquote><ul><li><blockquote><h2>foo bar</h2></blockquote></li></ul></blockquote>',
            ],
            // Two quotes deep, which shows the reference is the item's column
            // inside whatever container holds it rather than one fixed depth.
            'two quotes deep' => [
                "> > - > foo\n> >   >     # bar\n> >   > ---\n",
                '<blockquote><blockquote><ul><li><blockquote><h2>foo # bar</h2>'
                    . '</blockquote></li></ul></blockquote></blockquote>',
            ],
            // An ordered holder sets a wider content column, so the same fold
            // has a different pair of columns to take off.
            'an ordered item holds the quote' => [
                "> 1. > foo\n>      >     # bar\n>      > ---\n",
                '<blockquote><ol><li><blockquote><h2>foo # bar</h2></blockquote></li></ol></blockquote>',
            ],
            // A fence is text of the paragraph at four columns, so it joins the
            // heading rather than opening a code block.
            'a tilde fence four columns in joins the heading' => [
                "> - > foo\n>   >     ~~~\n>   > ---\n",
                '<blockquote><ul><li><blockquote><h2>foo ~~~</h2></blockquote></li></ul></blockquote>',
            ],
        ];
    }

    #[DataProvider('foldProvider')]
    public function testTheHeldQuoteFoldsItsHeading(string $markdown, string $html): void
    {
        // Element AND text: a lost heading leaves text behind that reads as if
        // nothing went wrong, and an absence assertion cannot tell the two apart.
        $this->assertSame($html, $this->render($markdown));
        // The underline is a bare hyphen run, which reaches smart typography and
        // would show a reader an em dash where three hyphens were typed.
        $this->assertSame($html, $this->render($markdown, SmartTypographyMode::Source));
    }

    /**
     * What held before the change and holds after it.
     *
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            // The same shape one container out, which already folded.
            'an item-held quoted fold at the top level still folds' => [
                "- > foo\n  >     # bar\n  > ---\n",
                '<ul><li><blockquote><h2>foo # bar</h2></blockquote></li></ul>',
            ],
            'a quoted fold with no item still folds' => [
                "> foo\n>     # bar\n> ---\n",
                '<blockquote><h2>foo # bar</h2></blockquote>',
            ],
            // No quote held by the item, so the fold has one column to take off
            // and the branch this adds must not reach it.
            'a plain item in a quote still folds at its own column' => [
                "> - foo\n>       # bar\n>    ---\n",
                '<blockquote><ul><li><h2>foo # bar</h2></li></ul></blockquote>',
            ],
            // Nothing underlines, so nothing folds and the text survives whole.
            'with no underline the held line stays paragraph text' => [
                "> - > foo\n>   >     # bar\n",
                '<blockquote><ul><li><blockquote><p>foo # bar</p></blockquote></li></ul></blockquote>',
            ],
            // A blank quote line closes the paragraph, so the run below it is a
            // real thematic break and must stay one.
            'a rule under a closed held paragraph stays a rule' => [
                "> - > foo\n>   >\n>   > ---\n",
                '<blockquote><ul><li><blockquote><p>foo</p><hr></blockquote></li></ul></blockquote>',
            ],
            // The fold must not reach past the item into the document: line two
            // leaves the item, so the heading below belongs to nothing above it.
            'the fold does not reach past the item' => [
                "> - > foo\n> >     # bar\n> > ---\n",
                '<blockquote><ul><li><blockquote><p>foo</p></blockquote></li></ul>'
                    . '<blockquote><pre><code># bar </code></pre><hr></blockquote></blockquote>',
            ],
        ];
    }

    #[DataProvider('controlsProvider')]
    public function testWhatAlreadyHeldStillHolds(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
        $this->assertSame($html, $this->render($markdown, SmartTypographyMode::Source));
    }

    /**
     * A delimiter row answerable under the line above makes the two a table, so
     * the fold refuses. What this engine builds there is itself wrong
     * (carve-php#2342's shape at this depth), so only the refusal and the text
     * are asserted rather than the whole rendering.
     */
    public function testAnAnswerableDelimiterRowStillRefusesTheFold(): void
    {
        $html = $this->render("> - > a | b\n>   > | - | - |\n>   > ---\n");

        $this->assertStringNotContainsString('<h2>', $html);
        $this->assertStringContainsString('a | b', $html);
    }

    private function render(string $markdown, ?SmartTypographyMode $typography = null): string
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $converter = new CarveConverter();
        $renderer = $converter->getRenderer();
        if ($typography !== null && $renderer instanceof HtmlRenderer) {
            $renderer->setSmartTypography($typography);
        }
        $html = $converter->convert($carve);

        return trim((string)preg_replace(['/\s+id="[^"]*"/', '/>\s+</', '/\s+/'], ['', '><', ' '], $html));
    }
}
