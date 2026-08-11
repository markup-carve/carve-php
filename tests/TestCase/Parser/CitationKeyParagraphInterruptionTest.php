<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A citation-key line does not interrupt an open paragraph.
 *
 * `[@key]: …` is not a link reference definition here - `@` is excluded from a
 * label so the line stays with CitationsExtension - and it renders visibly. A
 * line that produces a visible block is ordinary paragraph text under PART 9
 * §17, so it folds in as lazy continuation.
 *
 * It did not: the interruption predicate accepted the line, the definition
 * parser then rejected it, and what came back was a second paragraph
 * (carve-php#619). carve-js and carve-rs both continued the paragraph, so a
 * bibliography block after a hard-wrapped line rendered differently per engine.
 */
class CitationKeyParagraphInterruptionTest extends TestCase
{
    public function testCitationKeyLineContinuesTheParagraph(): void
    {
        $html = (new CarveConverter())->convert("Some prose here.\n[@x]: tail text\n");

        // One paragraph, with the citation line inside it.
        $this->assertSame(1, substr_count($html, '<p>'));
        $this->assertStringContainsString("Some prose here.\n", $html);
        $this->assertStringContainsString(']: tail text</p>', $html);
    }

    public function testConsecutiveCitationKeyLinesAreOneParagraph(): void
    {
        // The bibliography shape CitationsExtension collects, seen by the core
        // parser with the extension off.
        $html = (new CarveConverter())->convert("[@durusau2022]: Durusau, P. (2022).\n[@gruber2004]: Gruber, J. (2004).\n");

        $this->assertSame(1, substr_count($html, '<p>'));
    }

    /**
     * In 0.2, invisible-looking constructs also stay literal inside an open
     * paragraph; they only become structural at a blank boundary.
     *
     * @return array<string, array{string, string}>
     */
    public static function noLongerInterruptsProvider(): array
    {
        return [
            'reference definition' => ["Prose.\n[b]: https://example.com\n", "Prose.\n[b]: https://example.com</p>"],
            'footnote definition' => ["Prose.\n[^f]: A note.\n", "Prose.\n[^f]: A note.</p>"],
            'line comment' => ["Prose.\n%% hidden\n", "Prose.\n%% hidden</p>"],
        ];
    }

    #[DataProvider('noLongerInterruptsProvider')]
    public function testAnInvisibleConstructDoesNotInterrupt(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }

    public function testAnEmptyDestinationStillFoldsIn(): void
    {
        // Not a definition either, and it was already handled correctly - the
        // predicate and the parser agreed on this one.
        $html = (new CarveConverter())->convert("Prose.\n[b]:\n");

        $this->assertSame(1, substr_count($html, '<p>'));
    }

    public function testAttributesBeforeARealDefinitionFloatPastIt(): void
    {
        // The same predicate decides whether a block-attribute line belongs to
        // a following definition, so this is the other side of the change.
        // Under §15 A2a it belongs to neither: it floats past the definition to
        // the next VISIBLE block.
        $html = (new CarveConverter())->convert("{.c}\nSee [b][].\n\n[b]: https://example.com\n");

        $this->assertStringContainsString('<p class="c">', $html);
        $this->assertStringNotContainsString('class="c">b</a>', $html);
    }

    public function testAttributesBeforeACitationKeyLineAreNotSwallowed(): void
    {
        // `[@x]: …` is not a definition, so a preceding attribute line has no
        // definition to attach to and floats to the next block as usual.
        $html = (new CarveConverter())->convert("{.c}\n[@x]: tail\n");

        $this->assertStringContainsString('<p class="c">', $html);
    }
}
