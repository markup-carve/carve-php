<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TheMarkdownImporterRespellsACollidingBreakTest extends TestCase
{
    #[DataProvider('spellingCases')]
    public function testACollidingBreakTakesTheWritersSpelling(string $source, string $expected): void
    {
        self::assertSame($expected, (new MarkdownToCarve())->convert($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellingCases(): iterable
    {
        yield 'an empty non-mapping block' => ["---\n---\nBody\n", "***\n\n***\n\nBody\n"];
        yield 'a rejected block whose body still holds a bare break' => [
            "---\nFoo\n---\n\n```\n---\n```\n",
            "***\n\n## Foo\n\n```\n---\n```\n",
        ];

        // Document-wide, per PART 11 section 1a: section 1's invariant outranks
        // the per-construct spelling, so the second break is respelled too.
        yield 'every break in the document' => [
            "***\n\n***\n\n```\n---\n```\n",
            "***\n\n***\n\n```\n---\n```\n",
        ];

        yield 'a break that is not at byte 0' => ["> ***\n\n```\n---\n```\n", "> ---\n\n```\n---\n```\n"];
        yield 'real frontmatter and a later break' => [
            "---yaml\ntitle: Hi\n---\n\nBody\n\n***\n\nMore\n",
            "---yaml\ntitle: Hi\n---\n\nBody\n\n---\n\nMore\n",
        ];
    }

    /**
     * The assertion that counts: import output has to BE canonical Carve, or a
     * repo gating on `carve fmt --check` fails on its own migration output.
     */
    #[DataProvider('roundTripCases')]
    public function testImportOutputRoundTripsThroughFmt(string $source): void
    {
        $imported = (new MarkdownToCarve())->convert($source);

        self::assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roundTripCases(): iterable
    {
        yield 'an empty non-mapping block' => ["---\n---\nBody\n"];
        yield 'a rejected block whose body still holds a bare break' => ["---\nFoo\n---\n\n```\n---\n```\n"];
        yield 'real frontmatter and a later break' => ["---yaml\ntitle: Hi\n---\n\nBody\n\n***\n\nMore\n"];
    }

    public function testRealFrontmatterIsStillSynthesized(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("---yaml\ntitle: Hi\n---\n\nBody\n\n***\n\nMore\n");
        $codes = array_column($result->report()['diagnostics'], 'code');

        self::assertContains('frontmatter-synthesized', $codes, $result->value);
    }
}
