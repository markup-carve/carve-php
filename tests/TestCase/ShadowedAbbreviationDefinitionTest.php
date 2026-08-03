<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A repeated abbreviation definition: the LAST one wins (PART 9R), and the
 * shadowed one is still a line the author wrote.
 *
 * This engine kept abbreviations in a term-to-expansion MAP, so the earlier
 * definition was gone before the tree was built: the formatter printed one line
 * where two were written, and the serialized tree carried one node where
 * carve-js carries two. PART 12 section 3a says the serialized tree is
 * PRE-RESOLVE - which definition wins is a resolution result, not a reason to
 * drop the loser.
 *
 * Found by the corpus case markup-carve/carve#553 added: all three engines
 * agree on the HTML and only this one disagreed on the canonical source.
 */
class ShadowedAbbreviationDefinitionTest extends TestCase
{
    public function testTheFormatterKeepsBothDefinitions(): void
    {
        $carve = CarveConverter::toCarve("*[A]: a\n*[A]: b\n\nA here.\n");

        $this->assertSame("*[A]: a\n\n*[A]: b\n\nA here.\n", $carve);
    }

    public function testTheLastDefinitionStillWinsInHtml(): void
    {
        $html = (new CarveConverter())->convert("*[A]: a\n*[A]: b\n\nA here.\n");

        $this->assertStringContainsString('<abbr title="b">A</abbr>', $html);
    }

    public function testTheTreeCarriesOneNodePerAuthoredDefinition(): void
    {
        $document = (new BlockParser())->parse("*[A]: a\n*[A]: b\n\nA here.\n");
        $codec = new AstCodec();
        $encoded = $codec->encode($document);

        $defs = array_values(array_filter(
            $encoded['children'],
            fn (array $child): bool => ($child['type'] ?? null) === 'abbreviation_def',
        ));

        $this->assertCount(2, $defs);
        $this->assertSame('a', $defs[0]['expansion']);
        $this->assertSame('b', $defs[1]['expansion']);
    }

    public function testADecodedTreeStillResolvesToTheLastDefinition(): void
    {
        $document = (new BlockParser())->parse("*[A]: a\n*[A]: b\n\nA here.\n");
        $codec = new AstCodec();
        $decoded = $codec->decode($codec->encode($document));

        $this->assertSame(['A' => 'b'], $decoded->getAbbreviations());
    }
}
