<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * `footnote_ref.number` and `inline_footnote.number` reach the wire.
 *
 * PART 12 §5 serializes footnote numbering: a consumer cannot recompute it
 * without reimplementing PART 9R. This engine kept the numbers in
 * `HtmlRenderer`'s render context - an array keyed by label, never on the node -
 * so the published tree carried none at all (carve-php#843). #846 fixed the
 * caption half; this is the footnote half.
 *
 * THE TWO KINDS SHARE ONE SEQUENCE. carve-js and carve-rs both number
 * `[^a] ^[x] [^a] ^[y]` as 1, 2, 1, 3. A pass that counted only references would
 * agree with every single-kind document and disagree with every mixed one - and
 * with this engine's own HTML, which numbers them in one walk.
 *
 * The rule is the renderer's, deliberately: first USE order, a repeat reusing its
 * number, an unresolved reference left unnumbered because it never formed a
 * footnote. Every row below was checked against carve-js and carve-rs.
 *
 * The golden wire fixture gains two fields and renames nothing, so unlike #846
 * there is no question of a version bump here: no payload loses a field it had.
 */
class FootnoteNumberIsSerializedTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function ast(string $source): array
    {
        return (new AstCodec())->encode((new CarveConverter())->parse($source));
    }

    /**
     * Every footnote-ish node as [kind, number], in document order.
     *
     * @param array<string, mixed> $tree
     *
     * @return array<int, array{0: string, 1: int|null}>
     */
    protected function numbers(array $tree): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (is_array($node)) {
                $type = $node['type'] ?? null;
                if ($type === 'footnote_ref' || $type === 'inline_footnote') {
                    $found[] = [(string)$type, $node['number'] ?? null];
                }
                foreach ($node as $value) {
                    $walk($value);
                }
            }
        };
        $walk($tree);

        return $found;
    }

    public function testARepeatedReferenceReusesItsNumber(): void
    {
        $this->assertSame(
            [['footnote_ref', 1], ['footnote_ref', 1]],
            $this->numbers($this->ast("[^a]: note\n\nsee[^a] again[^a]\n")),
        );
    }

    public function testTwoLabelsNumberInFirstUseOrder(): void
    {
        // Defined in the order b, a; USED in the order a, b - so `a` is 1.
        $this->assertSame(
            [['footnote_ref', 1], ['footnote_ref', 2]],
            $this->numbers($this->ast("[^b]: two\n[^a]: one\n\nsee[^a] then[^b]\n")),
        );
    }

    public function testAnInlineFootnoteSharesTheSequence(): void
    {
        // The case a reference-only counter gets wrong. carve-js and carve-rs
        // both produce exactly this.
        $this->assertSame(
            [
                ['footnote_ref', 1],
                ['inline_footnote', 2],
                ['footnote_ref', 1],
                ['inline_footnote', 3],
            ],
            $this->numbers($this->ast("[^a]: note\n\nsee[^a] then ^[one] then[^a] and ^[two]\n")),
        );
    }

    public function testAnUnresolvedReferenceIsNotNumbered(): void
    {
        // It never formed a footnote, so numbering it would invent one - PART 12
        // §3 forbids publishing a field the node does not have.
        $this->assertSame(
            [['footnote_ref', null]],
            $this->numbers($this->ast("see[^missing]\n")),
        );
    }

    public function testAReferenceInsideANoteBodyIsNumbered(): void
    {
        // A REFERENCED note's body is walked, after the document, so a reference
        // inside one takes the next number rather than being skipped.
        $this->assertSame(
            [['footnote_ref', 1], ['footnote_ref', 2]],
            $this->numbers($this->ast("[^a]: note [^b]\n[^b]: other\n\nsee[^a]\n")),
        );
    }

    public function testTheNumberSurvivesADecodeAndReEncode(): void
    {
        // A field the encoder writes and the decoder drops is worse than one that
        // was never there.
        $codec = new AstCodec();
        $once = $this->ast("[^a]: note\n\nsee[^a] then ^[x]\n");
        $twice = $codec->encode($codec->decode($once));

        $this->assertSame($this->numbers($once), $this->numbers($twice));
    }

    public function testTheHtmlStillNumbersTheSameWay(): void
    {
        // The published tree and the rendered document must not disagree: the
        // numbering pass is additional to the renderer's, not a replacement.
        $html = (new CarveConverter())->convert("[^a]: note\n\nsee[^a] then ^[one] then[^a]\n");

        // The inline footnote is number 2 in the HTML too - `id="fnref2"` with a
        // `<sup>2</sup>` - which is what the published tree now agrees with. The
        // repeat keeps number 1 and gets the `-2` backlink anchor.
        $this->assertStringContainsString('id="fnref1"', $html);
        $this->assertStringContainsString('id="fnref1-2"', $html);
        $this->assertStringContainsString('id="fnref2"', $html);
        $this->assertStringContainsString('<sup>2</sup>', $html);
    }

    public function testAnUnreferencedDefinitionBodyTakesNoNumber(): void
    {
        // `[^b]` is defined and never referenced, so it renders nowhere and the
        // note inside its body is not part of the document. Numbering it would
        // consume 2 and push the note in `[^a]`'s body - which DOES render - to
        // 3, disagreeing with this engine's own HTML.
        $this->assertSame(
            [['footnote_ref', 1], ['inline_footnote', null], ['inline_footnote', 2]],
            $this->numbers($this->ast("X[^a]\n\n[^b]: ^[u]\n\n[^a]: ^[r]\n")),
        );
    }

    public function testANoteBodyIsNumberedInFirstUseOrderNotDefinitionOrder(): void
    {
        // Definitions are hoisted in SOURCE order (b, a) and used in the order
        // a, b. The bodies are walked in use order, so the note in `[^a]`'s body
        // is 3 - which is the number the HTML gives it.
        $this->assertSame(
            [
                ['footnote_ref', 1],
                ['footnote_ref', 2],
                ['inline_footnote', 4],
                ['inline_footnote', 3],
            ],
            $this->numbers($this->ast("P[^a] Q[^b]\n\n[^b]: bee ^[nb]\n\n[^a]: ay ^[na]\n")),
        );
    }

    public function testTheHtmlAgreesWhichNoteGotWhichNumber(): void
    {
        // The claim the two tests above rest on, asserted rather than assumed:
        // the rendered endnote list numbers `na` 3 and `nb` 4.
        $html = (new CarveConverter())->convert("P[^a] Q[^b]\n\n[^b]: bee ^[nb]\n\n[^a]: ay ^[na]\n");
        $order = [];
        if (preg_match_all('/id="fn(\d+)"[^>]*>\s*(?:<p[^>]*>)?\s*([a-z]+)/', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $order[$hit[2]] = (int)$hit[1];
            }
        }

        $this->assertSame(3, $order['na'] ?? null, 'the note in [^a]s body renders as 3');
        $this->assertSame(4, $order['nb'] ?? null, 'the note in [^b]s body renders as 4');
    }

    public function testAReferenceInsideAnInlineNoteBodyIsNotNumbered(): void
    {
        // Not reachable by parsing - `[^b]` inside `^[...]` stays text - but
        // reachable from a decoded tree. carve-js never descends an inline
        // note's content looking for footnotes ("no footnotes inside notes"),
        // so neither does this. Numbering it would invent a footnote the
        // renderer does not emit.
        $codec = new AstCodec();
        $tree = $this->ast("see[^a] and ^[note]\n\n[^a]: body\n");
        $nested = null;
        $splice = function (array &$node) use (&$splice, &$nested): void {
            foreach ($node as $key => &$value) {
                if (!is_array($value)) {
                    continue;
                }
                if (($value['type'] ?? null) === 'footnote_ref') {
                    $nested = $value;
                    // Arrives unnumbered, so this pins that the pass does not
                    // NUMBER it - a narrower claim than clearing a number the
                    // pass never visits, which no engine does.
                    unset($nested['number']);
                }
                if (($value['type'] ?? null) === 'inline_footnote' && $nested !== null) {
                    $value['inline'][] = $nested;

                    return;
                }
                $splice($value);
            }
        };
        $splice($tree['children']);

        $this->assertSame(
            [['footnote_ref', 1], ['inline_footnote', 2], ['footnote_ref', null]],
            $this->numbers($codec->encode($codec->decode($tree))),
        );
    }

    public function testAReferenceWhoseDefinitionWasRemovedCarriesNoNumber(): void
    {
        // An editor deletes the definition and feeds the tree back. The
        // reference now renders as its literal source - `see[^a]` - so a
        // published number would name a footnote that is not in the document.
        // carve-js clears it for the same reason on the paths where its pass
        // runs (carve-js#698).
        $codec = new AstCodec();
        $tree = $this->ast("see[^a]\n\n[^a]: note\n");
        $tree['children'] = array_values(array_filter(
            $tree['children'],
            fn (array $node): bool => ($node['type'] ?? null) !== 'footnote',
        ));
        $document = $codec->decode($tree);

        $this->assertStringContainsString('see[^a]', (new HtmlRenderer())->render($document));
        $this->assertSame([['footnote_ref', null]], $this->numbers($codec->encode($document)));
    }
}
