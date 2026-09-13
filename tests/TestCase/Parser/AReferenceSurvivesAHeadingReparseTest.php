<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reference written ABOVE its definition survives the second parse.
 *
 * An unresolved collapsed reference makes the parser run again with the
 * heading index seeded, and that second pass rebuilt the tree without ever
 * collecting the definitions or repairing the forward references the first
 * pass had already repaired - on a tree it then threw away. Every reference
 * below its definition in such a document rendered as its own source text
 * (carve-php#1937).
 *
 * Each case pairs `convert()` with `parse()` + `render()` deliberately: a
 * configured fast path answered some of these documents without parsing at
 * all, so the bug was invisible through `convert()` for anything simple
 * enough to take it, and only the second call exercises the parser.
 */
class AReferenceSurvivesAHeadingReparseTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function documentProvider(): iterable
    {
        yield 'heading, then a forward reference' => ["# H\n\n[r][ref]\n\n[ref]: /t\n"];
        yield 'a block between the heading and the use' => ["# H\n\nx\n\n[r][ref]\n\n[ref]: /t\n"];
        yield 'a container between the heading and the use' => ["# H\n\n::: note\nx\n:::\n\n[r][ref]\n\n[ref]: /t\n"];
        yield 'a container between the use and the definition' => ["# H\n\n[r][ref]\n\n::: note\nx\n:::\n\n[ref]: /t\n"];
        yield 'a quote between the heading and the use' => ["# H\n\n> q\n\n[r][ref]\n\n[ref]: /t\n"];
    }

    #[DataProvider('documentProvider')]
    public function testAForwardReferenceResolvesOnBothPaths(string $source): void
    {
        $this->assertStringContainsString('href="/t"', (new CarveConverter())->convert($source));

        $converter = new CarveConverter();
        $this->assertStringContainsString('href="/t"', $converter->render($converter->parse($source)));
    }

    public function testTheDefinitionIsStillHoistedOutOfTheRenderedText(): void
    {
        $converter = new CarveConverter();
        $html = $converter->render($converter->parse("# H\n\n[r][ref]\n\n[ref]: /t\n"));

        // The definition is not content: resolving it must not also print it.
        $this->assertStringNotContainsString('[ref]:', $html);
    }
}
