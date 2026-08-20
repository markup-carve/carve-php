<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every block opener survives inside a wide bullet (carve-php#580, #594).
 *
 * `BulletMarkerContentColumnTest` pins the COLUMN - where the content of a
 * wide bullet starts, and that a task keeps the bullet width. This file pins
 * what the column is for, which is the part the issue understated: while the
 * column was assumed rather than measured, a wide bullet's body was
 * under-dedented by the extra spaces, and an indented block opener is a
 * paragraph. The reported symptom was a heading staying literal; every opener
 * was affected, and some of them were corrupted rather than merely declined.
 *
 * Measured against the parser before #594: a fence rendered as a paragraph
 * holding an inline code span, a table as a paragraph of pipes, a blockquote as
 * a paragraph beginning with an escaped `>`, a div as a paragraph of its own
 * source, and a thematic break as an em dash by way of smart typography.
 *
 * Paragraphs and nested lists came through unharmed, which is why it stayed
 * hidden for so long: they are the two shapes that do not need the column to be
 * right. A test that only covered those two would have passed throughout.
 */
class WideBulletBlockOpenersTest extends TestCase
{
    private function html(string $source): string
    {
        $converter = CarveConverter::create();

        return $converter->render($converter->parse($source));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockOpenerProvider(): array
    {
        return [
            'a heading' => ['# Wide', '<h1'],
            'a fence' => ["``` php\nx = 1;\n```", '<pre><code class="language-php">'],
            'a thematic break' => ['---', '<hr>'],
            'a blockquote' => ['> quoted', '<blockquote>'],
            'a table' => ["| a | b |\n|---|---|\n| c | d |", '<table>'],
            'a div' => ["::: note\nbody\n:::", '<aside class="admonition note" aria-label="Note">'],
            // The two that always worked. Here so the sweep says which shapes
            // were load-bearing rather than implying all of them were.
            'a paragraph' => ['second para', '<p>second para</p>'],
            'a nested list' => ['- inner', '<ul>'],
        ];
    }

    /**
     * The wide form is compared against the NARROW one rather than against
     * pinned html: the two inputs differ only in the marker's width, so any
     * difference in the output is the defect itself, and the assertion cannot
     * drift as unrelated rendering changes.
     */
    #[DataProvider('blockOpenerProvider')]
    public function testAWideBulletHoldsEveryBlockOpener(string $body, string $expected): void
    {
        $indent = static fn (string $text, int $columns): string => (string)preg_replace(
            '/^/m',
            str_repeat(' ', $columns),
            $text,
        );

        $narrow = $this->html('- item' . "\n\n" . $indent($body, 2));
        $wide = $this->html('-' . str_repeat(' ', 3) . 'item' . "\n\n" . $indent($body, 4));

        $this->assertStringContainsString($expected, $narrow, 'the narrow form is the reference');
        $this->assertSame($narrow, $wide);
    }

    /**
     * The index and the element agree about a wide bullet's heading.
     *
     * Two things read the content column: the parser, which decides whether a
     * heading exists, and the implicit-heading scan, which decides whether
     * `[Wide][]` resolves. While they disagreed, the scan was pinned to the
     * parser's answer on purpose - an index entry the renderer never emits is a
     * dangling href, worse than declining. This is the pair, asserted together
     * so neither can drift back alone.
     */
    public function testTheReferenceAndTheHeadingAppearTogether(): void
    {
        $html = $this->html('-' . str_repeat(' ', 3) . "item\n\n    # Wide\n\n    See [Wide][].");

        $this->assertStringContainsString('id="Wide"', $html);
        $this->assertStringContainsString('href="#Wide"', $html);
    }

    /**
     * And the linter agrees with both.
     */
    public function testAWideBulletHeadingRaisesNoFalseWarning(): void
    {
        $parser = new BlockParser();
        $parser->setCollectWarnings(true);
        CarveConverter::create($parser)->parse(
            '-' . str_repeat(' ', 3) . "item\n\n    # Wide\n\n    See [Wide][] and [x](#Wide).",
        );

        $messages = array_map(static fn ($warning): string => $warning->getMessage(), $parser->getWarnings());
        $this->assertSame([], $messages);
    }
}
