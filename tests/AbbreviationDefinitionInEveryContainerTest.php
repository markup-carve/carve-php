<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An abbreviation definition is a definition in every container (markup-carve/carve#4865).
 */
final class AbbreviationDefinitionInEveryContainerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function laterDefinitionWins(): iterable
    {
        yield 'footnote body before document' => ["[^f]: *[A]: first\n*[A]: second\n\nA [^f]\n", 'second'];
        yield 'footnote body line before document' => ["[^f]: n\n  *[A]: first\n*[A]: second\n\nA [^f]\n", 'second'];
        yield 'document before footnote body' => ["*[A]: first\n[^f]: *[A]: second\n\nA [^f]\n", 'second'];
        yield 'item before document' => ["- *[A]: first\n*[A]: second\n\nA\n", 'second'];
    }

    #[DataProvider('laterDefinitionWins')]
    public function testTheLaterSourceLineWins(string $source, string $expansion): void
    {
        $this->assertStringContainsString(
            '<abbr title="' . $expansion . '">A</abbr>',
            CarveConverter::create()->convert($source),
        );
    }

    public function testTheDefinitionListKeepsSourceOrderAcrossAFootnoteBody(): void
    {
        $document = CarveConverter::create()->parse("[^f]: *[A]: first\n*[A]: second\n\nA [^f]\n");

        $this->assertSame(
            [['abbr' => 'A', 'expansion' => 'first'], ['abbr' => 'A', 'expansion' => 'second']],
            $document->getAbbreviationDefinitions(),
        );
    }

    public function testTheHoistedNodeStandsInSourceOrderWithoutPositions(): void
    {
        $tree = (new AstCodec())->encode((new CarveConverter())->parse("- n\n  *[A]: b\n\nA\n"));

        $this->assertSame(
            ['list', 'abbreviation_def', 'paragraph'],
            array_map(static fn (array $child): string => $child['type'], $tree['children']),
        );
    }
}
