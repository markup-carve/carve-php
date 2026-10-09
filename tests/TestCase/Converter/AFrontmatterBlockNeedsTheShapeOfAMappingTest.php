<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AFrontmatterBlockNeedsTheShapeOfAMappingTest extends TestCase
{
    #[DataProvider('shapeCases')]
    public function testOnlyAMappingShapeBecomesFrontmatter(string $source, bool $expected): void
    {
        // The report is the probe: `frontmatter-synthesized` is raised exactly
        // when the block was taken as frontmatter. A rejected block still
        // leaves a `---` thematic break at byte 0, so the bytes cannot answer.
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        $codes = array_column($result->report()['diagnostics'], 'code');
        self::assertSame($expected, in_array('frontmatter-synthesized', $codes, true), $result->value);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function shapeCases(): iterable
    {
        // Every shape case is spelled with a BARE `---`, the one opener that
        // collides with a thematic break and a setext underline. A typed
        // opener names the format, so the last two cases pin that it is
        // frontmatter whatever its payload looks like.

        yield 'cmark example 96 scalar' => ["---\nFoo\n---\nBar\n---\nBaz\n", false];
        yield 'a real mapping' => ["---\ntitle: Hi\n---\nBody\n", true];
        yield 'a key with a tab separator' => ["---\ntitle:\tHi\n---\nBody\n", true];
        yield 'a key with an empty value' => ["---\ntitle:\n---\nBody\n", true];
        yield 'a comment-only block' => ["---\n# just a note\n---\nBody\n", false];
        yield 'an empty block' => ["---\n\n---\nBody\n", false];
        yield 'a comment above a real key' => ["---\n# note\ntitle: Hi\n---\nBody\n", true];
        yield 'a double-quoted key' => ["---\n\"my title\": Hi\n---\nBody\n", true];
        yield 'a single-quoted key' => ["---\n'my title': Hi\n---\nBody\n", true];
        yield 'a bare key holding a colon' => ["---\na:b: Hi\n---\nBody\n", false];
        yield 'a list first line' => ["---\n- one\n- two\n---\nBody\n", false];
        yield 'an indented key' => ["---\n  title: Hi\n---\nBody\n", false];
        yield 'malformed but mapping-shaped' => ["---\ntitle: [unclosed\n---\nBody\n", true];
        yield 'a typed yaml opener over a scalar' => ["---yaml\nFoo\n---\nBody\n", true];
        yield 'a typed toml opener over a scalar' => ["---toml\nFoo\n---\nBody\n", true];
    }

    public function testExample96KeepsItsCommonMarkMeaning(): void
    {
        $carve = (new MarkdownToCarve())->convert("---\nFoo\n---\nBar\n---\nBaz\n");
        $html = (new CarveConverter())->convert($carve);
        $html = (string)preg_replace('/ id="[^"]*"/', '', $html);
        $html = (string)preg_replace('/\s+/', ' ', $html);
        self::assertStringContainsString('<hr>', $html);
        self::assertSame(2, substr_count($html, '<h2>'), $html);
        self::assertStringContainsString('<p>Baz</p>', $html);
    }

    public function testEveryConversionIsReported(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("---\ntitle: Hi\n---\nBody\n");
        self::assertContains('frontmatter-synthesized', array_column($result->report()['diagnostics'], 'code'));
    }

    public function testARejectedBlockIsNotReportedAsFrontmatter(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("---\nFoo\n---\nBar\n---\nBaz\n");
        self::assertNotContains('frontmatter-synthesized', array_column($result->report()['diagnostics'], 'code'));
    }

    public function testARejectedBlockCannotReopenFrontmatter(): void
    {
        // Two rules around a paragraph. A `---` left at byte 0 would gain a
        // frontmatter closer from the second rule and swallow the body, so the
        // importer's PART 11 section 1a guard keeps line 0 off `---`.
        $carve = (new MarkdownToCarve())->convert("---\nFoo\n\n---\n\nBar\n");
        self::assertFalse(str_starts_with($carve, '---'), $carve);
        $html = (new CarveConverter())->convert($carve);
        self::assertSame(2, substr_count($html, '<hr>'), $carve . "\n" . $html);
        self::assertStringContainsString('<p>Foo</p>', $html);
        self::assertStringContainsString('<p>Bar</p>', $html);
    }
}
