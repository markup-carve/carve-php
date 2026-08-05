<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Footnote as FootnoteBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §5 serializes footnote numbering (carve-php#843).
 *
 * The number was assigned into `RenderContext::$footnoteNumbers` while emitting
 * HTML, so a document that was never rendered - which is every AST consumer -
 * saw `footnote_ref` with no number at all. The rules below were measured
 * against the reference implementation rather than inferred, because an
 * implementation that guesses will differ on every one of them.
 */
class FootnoteNumbersOnTheWireTest extends TestCase
{
    /**
     * @return array<string, int|null>
     */
    private function numbers(Document|string $source): array
    {
        $document = $source instanceof Document ? $source : (new CarveConverter())->parse($source);
        $encoded = (new AstCodec())->encode($document);
        $found = [];
        $walk = function (array $node) use (&$walk, &$found): void {
            $type = $node['type'] ?? '';
            if ($type === 'footnote_ref') {
                $found[] = ($node['id'] ?? '') . '=' . ($node['number'] ?? 'none');
            } elseif ($type === 'inline_footnote') {
                $found[] = 'inline=' . ($node['number'] ?? 'none');
            }
            foreach (['children', 'inline'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    if (is_array($child)) {
                        $walk($child);
                    }
                }
            }
        };
        $walk($encoded);

        return $found;
    }

    public function testAReferenceCarriesItsNumber(): void
    {
        $this->assertSame(['a=1', 'b=2'], $this->numbers("X[^a] Y[^b]\n\n[^a]: one\n\n[^b]: two\n"));
    }

    public function testARepeatedReferenceSharesItsLabelsNumber(): void
    {
        $this->assertSame(['a=1', 'a=1', 'b=2'], $this->numbers("X[^a] Y[^a] Z[^b]\n\n[^a]: one\n\n[^b]: two\n"));
    }

    public function testAnInlineNoteDrawsFromTheSameSequence(): void
    {
        $this->assertSame(['a=1', 'inline=2', 'b=3'], $this->numbers("X[^a] Y^[note] Z[^b]\n\n[^a]: one\n\n[^b]: two\n"));
    }

    public function testAnUnresolvedReferenceGetsNoNumber(): void
    {
        // It renders as literal text, so there is no note for a number to point
        // at - and numbering it would make the next reference's number wrong.
        $this->assertSame(['missing=none', 'a=1'], $this->numbers("X[^missing] Y[^a]\n\n[^a]: one\n"));
    }

    public function testAnUnreferencedDefinitionsBodyContributesNoNumbers(): void
    {
        // `[^b]` is never referenced, so it renders nowhere - and a note inside
        // it must not take the number the next real one gets. Numbering follows
        // RENDER order (a definition's body is numbered once its reference has a
        // number), not source order, which is where a source-order walk diverged
        // from this engine's own HTML and from carve-js.
        $this->assertSame(
            ['a=1', 'inline=none', 'inline=2'],
            $this->numbers("X[^a]\n\n[^b]: ^[u]\n\n[^a]: ^[r]\n"),
        );
    }

    public function testReEncodingAnEditedDocumentDoesNotKeepStaleNumbers(): void
    {
        // The pass only STAMPS, so a caller encoding the same Document twice
        // with edits in between would otherwise keep the first encode's numbers
        // wherever the second no longer reaches - here, a note inside a body
        // whose only reference was removed.
        $document = (new CarveConverter())->parse("X[^a]\n\n[^a]: ^[r]\n");
        $codec = new AstCodec();
        $codec->encode($document);

        foreach ($document->getChildren() as $child) {
            if ($child instanceof Paragraph) {
                $document->removeChild($child);

                break;
            }
        }

        $numbers = [];
        $walk = function (array $node) use (&$walk, &$numbers): void {
            if (($node['type'] ?? '') === 'inline_footnote') {
                $numbers[] = $node['number'] ?? 'none';
            }
            foreach (['children', 'inline'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    if (is_array($child)) {
                        $walk($child);
                    }
                }
            }
        };
        $walk($codec->encode($document));

        $this->assertSame(['none'], $numbers);
    }

    public function testNumberingUsesTheSamePredicateAsTheRenderer(): void
    {
        // The renderer decides "is this a note?" from `isUnresolved()` alone, so
        // numbering must too. On a tree edited after parsing the two possible
        // checks disagree, and the published number has to describe what the
        // renderer will do: a reference still marked unresolved renders as
        // literal `[^id]` even once a definition exists.
        $document = (new CarveConverter())->parse("X[^gone]\n");
        $document->appendChild(new FootnoteBlock('gone'));

        $this->assertSame(['gone=none'], $this->numbers($document));
    }

    public function testTheNumberSurvivesTheRoundTrip(): void
    {
        $source = "X[^a] Y[^a] Z^[note]\n\n[^a]: one\n";
        $codec = new AstCodec();
        $encoded = $codec->encode((new CarveConverter())->parse($source));

        $this->assertSame($encoded, $codec->encode($codec->decode($encoded)));
    }
}
