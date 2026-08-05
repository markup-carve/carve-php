<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
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
        // A note body is walked like any other content, so a reference inside one
        // takes the next number rather than being skipped.
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
}
