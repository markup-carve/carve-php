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

    public function testANoteInAnUnresolvedReferenceTakesNoNumber(): void
    {
        // PART 9R R2 (markup-carve/carve#1198): R1 degrades the reference to its
        // literal source, so the text it held is discarded and a note in that
        // text "is not a reference" - no number, no endnote, no backlink. The
        // render half already agreed (carve-php#1257); the wire did not.
        $source = "a [t[^1]][nope] b\n\n[^1]: n\n";

        $this->assertSame([['footnote_ref', null]], $this->numbers($this->ast($source)));
        $this->assertSame(
            "<p>a [t[^1]][nope] b</p>\n",
            (new CarveConverter())->convert($source),
            'the whole construct renders as its source, with no endnotes section',
        );
    }

    public function testADiscardedUseDoesNotShareTheLiveUsesNumber(): void
    {
        // The case that makes the field a WRONG VALUE rather than a harmless
        // extra: the document renders exactly one noteref, the bare `[^1]`, and
        // publishing `number: 1` on the discarded one too duplicated the number
        // of the reference a reader can actually see.
        $source = "a [t[^1]][nope] b [^1] c\n\n[^1]: n\n";

        $this->assertSame(
            [['footnote_ref', null], ['footnote_ref', 1]],
            $this->numbers($this->ast($source)),
        );

        $html = (new CarveConverter())->convert($source);
        $this->assertSame(1, substr_count($html, 'role="doc-noteref"'), 'exactly one noteref renders');
        $this->assertStringContainsString('<sup>1</sup>', $html);
    }

    public function testAnInlineNoteInAnUnresolvedReferenceTakesNoNumber(): void
    {
        // The same clause, the other note spelling. `^[n]` has no label to lose,
        // so a fix keyed on an undefined label would miss it entirely.
        $source = "a [t^[n]][nope] b\n";

        $this->assertSame([['inline_footnote', null]], $this->numbers($this->ast($source)));
        $this->assertSame("<p>a [t^[n]][nope] b</p>\n", (new CarveConverter())->convert($source));
    }

    public function testANoteInABracketedRunWithNoTailKeepsItsNumber(): void
    {
        // The control a bracket-keyed fix would break. `[t[^1]]` never carried a
        // reference tail, so PART 9 §14 leaves it a bracketed run of ordinary
        // text and the note inside it IS a reference - numbered 1, with an
        // endnote the reader sees.
        $source = "a [t[^1]] b\n\n[^1]: n\n";

        $this->assertSame([['footnote_ref', 1]], $this->numbers($this->ast($source)));
        $this->assertStringContainsString('role="doc-noteref"', (new CarveConverter())->convert($source));
    }

    public function testANoteInAReferenceThatResolvesKeepsItsNumber(): void
    {
        // The second control: the discarding is the UNRESOLVED half of the rule,
        // not the reference-link half. PART 9 §16 keeps the note here, and a pass
        // that skipped every reference link's text would clear this one too.
        $source = "a [t[^1]][r] b\n\n[r]: /u\n\n[^1]: n\n";

        $this->assertSame([['footnote_ref', 1]], $this->numbers($this->ast($source)));
        $this->assertStringContainsString('href="/u"', (new CarveConverter())->convert($source));
    }

    public function testAnUnresolvedReferenceNestedInAResolvedOneStillDiscards(): void
    {
        // The outer reference resolves and renders its children, so the walk
        // reaches the inner one - which does not. The clearing is per node, not
        // per document.
        $source = "a [x [t[^1]][nope] y][r] b\n\n[r]: /u\n\n[^1]: n\n";

        $this->assertSame([['footnote_ref', null]], $this->numbers($this->ast($source)));
        $this->assertSame(
            "<p>a <a href=\"/u\">x [t[^1]][nope] y</a> b</p>\n",
            (new CarveConverter())->convert($source),
        );
    }

    public function testAWireNumberInsideAnUnresolvedReferenceIsCleared(): void
    {
        // Parsing never puts a number there, so skipping the subtree would pass
        // every case above while still republishing whatever an editor sent in.
        // Asserted on the ENCODED tree, not on source text.
        $codec = new AstCodec();
        $tree = $this->ast("a [t[^1]][nope] b\n\n[^1]: n\n");
        $stamp = function (array &$node) use (&$stamp): void {
            foreach ($node as &$value) {
                if (!is_array($value)) {
                    continue;
                }
                if (($value['type'] ?? null) === 'footnote_ref') {
                    $value['number'] = 99;
                }
                $stamp($value);
            }
        };
        $stamp($tree['children']);
        $this->assertSame([['footnote_ref', 99]], $this->numbers($tree), 'the doctored tree really carries it');

        $this->assertSame([['footnote_ref', null]], $this->numbers($codec->encode($codec->decode($tree))));
    }
}
