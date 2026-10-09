<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Import output has to BE canonical Carve, or a repo gating on
 * `carve fmt --check` fails on its own migration output (carve-php#2984,
 * carve-php#2989).
 */
final class TheMarkdownImporterSeparatesBlocksLikeTheWriterTest extends TestCase
{
    #[DataProvider('separatorCases')]
    public function testTheImportTakesTheWritersSeparator(string $source, string $expected): void
    {
        self::assertSame($expected, (new MarkdownToCarve())->convert($source));
    }

    /**
     * The assertion that counts is the ROUND TRIP, not the bytes alone.
     */
    #[DataProvider('separatorCases')]
    public function testTheImportIsAWriterFixedPoint(string $source, string $expected): void
    {
        $imported = (new MarkdownToCarve())->convert($source);

        self::assertSame($expected, $imported);
        self::assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function separatorCases(): iterable
    {
        // carve-php#2984.
        yield 'a closer directly above a block' => [
            "---yaml\ntitle: Hi\n---\nBody\n",
            "---yaml\ntitle: Hi\n---\n\nBody\n",
        ];

        yield 'a closer as the last line' => ["---yaml\ntitle: Hi\n---\n", "---yaml\ntitle: Hi\n---\n"];

        // The separator is not written twice, so the fix cannot work by always
        // adding one.
        yield 'a document that already separates them' => [
            "---yaml\ntitle: Hi\n---\n\nBody\n",
            "---yaml\ntitle: Hi\n---\n\nBody\n",
        ];

        // carve-php#2989.
        yield 'a break under a paragraph' => ["a\n***\nb\n", "a\n\n---\n\nb\n"];
        yield 'a break under a list' => [
            "---\n- one\n- two\n---\nBody\n",
            "***\n\n- one\n- two\n\n***\n\nBody\n",
        ];

        // The separator sits at the container the break is in, not at column 0.
        yield 'a break inside a block quote' => ["> a\n> ***\n> b\n", "> a\n>\n> ---\n>\n> b\n"];

        // Where the collision guard from carve-php#2982 meets the separator:
        // the frontmatter survives AND the later break stays a `---`.
        yield 'real frontmatter and a later break' => [
            "---\ntitle: Hi\n---\nBody\n***\nMore\n",
            "---yaml\ntitle: Hi\n---\n\nBody\n\n---\n\nMore\n",
        ];
    }
}
