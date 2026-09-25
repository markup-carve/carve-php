<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block marker after a task checkbox is text of the item's paragraph. GFM's
 * task-list extension takes a checkbox off a PARAGRAPH and leaves the rest of
 * that line inside it, so `- [ ] > foo` holds no quote and `- [ ] # foo` no
 * heading. Carve reads the marker there, so the import grew a block the source
 * did not have (carve-php#2343).
 *
 * A quoted line four columns past the item's content column is continuation
 * text, whatever marker it opens with - the checkbox is content and moves no
 * content column. A held `> ---` there was read as a rule in a quote and the
 * paragraph lost its last line to it.
 *
 * PINNED AT THE RENDERED LEVEL: the converter corpus compares an importer by
 * rendering its output.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (carve#2187). Its answers were taken for every shape below; commonmark 0.31.2
 * cannot arbitrate, having no task-list extension.
 *
 * carve-js `d8ca1cd` writes a quote here too (markup-carve/carve-js#2023). The
 * two engines agreeing is not an answer, so the shape this file lands on is the
 * escape - the marker stays text, as cmark-gfm reads it.
 */
final class AMarkerAfterATaskCheckboxIsParagraphTextTest extends TestCase
{
    /**
     * Every marker shape the checkbox can be followed by, swept rather than
     * sampled: each one opens a block in Carve and none does in GFM.
     *
     * @return array<string, array{string, string}>
     */
    public static function markersProvider(): array
    {
        return [
            'a quote marker' => ["- [ ] > foo\n", '&gt; foo'],
            'a heading marker' => ["- [ ] # foo\n", '# foo'],
            'a bullet' => ["- [ ] - foo\n", '- foo'],
            'a star bullet' => ["- [ ] * foo\n", '* foo'],
            'an ordered marker' => ["- [ ] 1. foo\n", '1. foo'],
            'a thematic break' => ["- [ ] ***\n", '***'],
            'a checked box' => ["- [x] > foo\n", '&gt; foo'],
            'a star task item' => ["* [ ] > foo\n", '&gt; foo'],
        ];
    }

    #[DataProvider('markersProvider')]
    public function testTheMarkerStaysTextOfTheParagraph(string $markdown, string $text): void
    {
        $html = $this->render($markdown);

        $this->assertStringContainsString($text, $html);
        $this->assertStringNotContainsString('<blockquote>', $html);
        $this->assertStringNotContainsString('<h1>', $html);
        $this->assertStringNotContainsString('<hr>', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    /**
     * An ORDERED task item takes the same escape, and its marker stays text.
     *
     * Asserted without the checkbox: Carve renders no checkbox on an ordered
     * item where cmark-gfm does, which is a divergence of its own and outside
     * this ticket. What matters here is that the `>` opens nothing either way.
     */
    public function testAnOrderedTaskItemKeepsItsMarkerAsText(): void
    {
        $html = $this->render("1. [ ] > foo\n");

        $this->assertStringContainsString('&gt; foo', $html);
        $this->assertStringNotContainsString('<blockquote>', $html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function continuationsProvider(): array
    {
        return [
            'a quoted continuation four columns in' => [
                "- [ ] > foo\n      > bar\n",
                '&gt; foo &gt; bar',
            ],
            'a break four columns in stays text' => [
                "- [ ] > foo\n      > bar\n      > ---\n",
                '&gt; foo &gt; bar &gt; ---',
            ],
            'a star break four columns in stays text' => [
                "- [ ] > foo\n      > bar\n      > ***\n",
                '&gt; foo &gt; bar &gt; ***',
            ],
            // No checkbox: the same columns, the same reading. This is what
            // shows the rule is the content column rather than the checkbox.
            'a break four columns into a plain item stays text' => [
                "- text\n      > bar\n      > ---\n",
                'text &gt; bar &gt; ---',
            ],
        ];
    }

    #[DataProvider('continuationsProvider')]
    public function testAContinuationFourColumnsInStaysText(string $markdown, string $text): void
    {
        $html = $this->render($markdown);

        $this->assertStringContainsString($text, $html);
        $this->assertStringNotContainsString('<hr>', $html);
        $this->assertStringNotContainsString('<blockquote>', $html);
    }

    /**
     * What held before the change and holds after it: the checkbox only reaches
     * its own line, and a quote below the item's text is still a quote.
     *
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            'a quote below the task text' => [
                "- [ ] text\n  > foo\n",
                '<blockquote><p>foo</p></blockquote>',
            ],
            'a quote below a plain item text' => [
                "- text\n  > foo\n",
                '<blockquote><p>foo</p></blockquote>',
            ],
            'a plain item still opens its quote' => [
                "- > foo\n  > bar\n",
                '<blockquote><p>foo bar</p></blockquote>',
            ],
            'a break at the container column is still a break' => [
                "- > foo\n  > ***\n",
                '<hr>',
            ],
            'a top-level quoted break is still a break' => [
                "> foo\n> ***\n",
                '<hr>',
            ],
        ];
    }

    #[DataProvider('controlsProvider')]
    public function testWhatAlreadyHeldStillHolds(string $markdown, string $fragment): void
    {
        $this->assertStringContainsString($fragment, $this->render($markdown));
    }

    private function render(string $markdown): string
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $html = (new CarveConverter())->convert($carve);

        return trim((string)preg_replace(['/\s+id="[^"]*"/', '/\s+aria-label="[^"]*"/', '/>\s+</', '/\s+/'], ['', '', '><', ' '], $html));
    }
}
