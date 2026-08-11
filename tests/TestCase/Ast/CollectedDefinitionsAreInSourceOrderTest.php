<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §7: "Definitions appear in DOCUMENT ORDER by source position."
 *
 * Collection moves a definition to the document and §4 keeps the `pos` it was
 * written at, so the published order follows that `pos`. This engine appended
 * the footnote table and then the link-definition table, so a footnote preceded
 * a link definition whatever the author wrote and `pos` ran backwards between
 * two adjacent siblings. markup-carve/carve#746.
 *
 * The measurement that hides it is a single document whose footnote happens to
 * be written first, where kind order and source order agree.
 */
class CollectedDefinitionsAreInSourceOrderTest extends TestCase
{
    /**
     * Positions are opt-in in this engine (PART 12 §4 permits that), and the
     * order under test is BY position, so the probe has to ask for them - the
     * same thing `bin/carve --json` does. Without it every `pos` is absent and
     * a check on the published offsets cannot fail.
     *
     * @return array<string, mixed>
     */
    private function encode(string $source): array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));

        return (new AstCodec())->encode($converter->parse($source));
    }

    /**
     * @return array<int, string>
     */
    private function kinds(string $source): array
    {
        $encoded = $this->encode($source);
        $kinds = [];
        foreach ($encoded['children'] ?? [] as $child) {
            self::assertIsArray($child);
            $kinds[] = (string)($child['type'] ?? '');
        }

        return $kinds;
    }

    public function testAFootnoteWrittenFirstPrecedesALinkDefinition(): void
    {
        self::assertSame(
            ['paragraph', 'footnote', 'link_reference_definition'],
            $this->kinds("[^a]: note\n[r]: /u\n\nsee[^a] and [t][r]\n"),
        );
    }

    public function testALinkDefinitionWrittenFirstPrecedesAFootnote(): void
    {
        self::assertSame(
            ['paragraph', 'link_reference_definition', 'footnote'],
            $this->kinds("[r]: /u\n[^a]: note\n\nsee[^a] and [t][r]\n"),
        );
    }

    public function testThreeDefinitionsOfTwoKindsFollowSourcePosition(): void
    {
        self::assertSame(
            ['paragraph', 'link_reference_definition', 'footnote', 'link_reference_definition'],
            $this->kinds("[r]: /u\n[^a]: note\n[s]: /v\n\nsee[^a] and [t][r] and [u][s]\n"),
        );
    }

    public function testAnAbbreviationDefinitionKeepsItsAuthoredPosition(): void
    {
        // An `abbreviation_def` is NOT collected out of the document - §7
        // refuses that specifically, since hoisting it would empty the line
        // rather than relocate visible output - so it stays where it was
        // written and is not drawn into the collected tail.
        self::assertSame(
            ['abbreviation_def', 'paragraph', 'link_reference_definition', 'footnote'],
            $this->kinds(
                "*[HTML]: HyperText Markup Language\n[r]: /u\n[^a]: note\n\nsee[^a] and [t][r] and HTML\n",
            ),
        );
    }

    /**
     * Both writing orders, because either one alone agrees with kind order for
     * one of the two engines' answers and so cannot tell them apart.
     */
    public function testThePublishedPositionsAscendAcrossTheCollectedTail(): void
    {
        $sources = [
            "[^a]: note\n[r]: /u\n\nsee[^a] and [t][r]\n",
            "[r]: /u\n[^a]: note\n\nsee[^a] and [t][r]\n",
        ];
        foreach ($sources as $source) {
            $offsets = [];
            foreach ($this->encode($source)['children'] ?? [] as $child) {
                self::assertIsArray($child);
                if (in_array($child['type'] ?? '', ['footnote', 'link_reference_definition'], true)) {
                    self::assertIsArray($child['pos'] ?? null, 'the probe must publish positions');
                    $offsets[] = $child['pos']['startOffset'];
                }
            }
            self::assertCount(2, $offsets, $source);

            $sorted = $offsets;
            sort($sorted);
            self::assertSame($sorted, $offsets, $source);
        }
    }
}
