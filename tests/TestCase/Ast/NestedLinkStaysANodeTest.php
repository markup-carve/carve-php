<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §3a, A NESTED LINK AND AN AUTOLINK STAY NODES (carve#817).
 *
 * "Links never nest" is a RENDERING rule: an anchor may not contain another
 * anchor, so it binds the renderer and not the encoder. A `link` or an
 * `autolink` inside a link's label is published as the node the author wrote,
 * and every render target unwraps it at its own render seam.
 *
 * These assertions go through CarveConverter::parse(), which is the layer the
 * earlier all-clear on this missed: the parse tree and the PUBLISHED tree were
 * different in this engine, so a probe against the parser alone reported "nodes
 * kept" and was right about the parser while the wire was still lossy
 * (carve-php#1059).
 *
 * Flattening is strictly lossier than the case §3a opens with. `[[x](y)](z)`
 * published as a link to `z` whose only child is the text `x` has lost `y` from
 * the tree entirely, so `fmt` on the parsed document writes `[[x](y)](z)` back
 * while `fmt` through the AST writes `[x](z)` - two spellings of one source,
 * which is PART 11 §6's round trip failing. HTML is byte-identical either way,
 * which is why no rendering fixture caught it.
 */
class NestedLinkStaysANodeTest extends TestCase
{
    /**
     * The published tree, via the same path `bin/carve --json` takes.
     *
     * @param string $source
     *
     * @return array<string, mixed>
     */
    protected function published(string $source): array
    {
        return (new AstCodec())->encode((new CarveConverter())->parse($source));
    }

    /**
     * Every node of one type, in document order, as `type=destination`.
     *
     * @param array<string, mixed> $node
     * @param list<string> $types
     *
     * @return list<string>
     */
    protected function collect(array $node, array $types): array
    {
        $found = [];
        if (in_array($node['type'] ?? null, $types, true)) {
            $found[] = $node['type'] . '=' . (string)($node['href'] ?? $node['target'] ?? '');
        }

        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }
            if (isset($value['type'])) {
                $found = array_merge($found, $this->collect($value, $types));

                continue;
            }
            foreach ($value as $item) {
                if (is_array($item)) {
                    $found = array_merge($found, $this->collect($item, $types));
                }
            }
        }

        return $found;
    }

    /**
     * @param string $source
     *
     * @return list<string>
     */
    protected function links(string $source): array
    {
        return $this->collect($this->published($source), ['link', 'autolink', 'heading_ref']);
    }

    /**
     * The shape the ticket measured: the inner destination was absent entirely.
     *
     * @return void
     */
    public function testANestedLinkKeepsTheInnerNode(): void
    {
        $this->assertSame(['link=z', 'link=y'], $this->links("[[x](y)](z)\n"));
    }

    /**
     * An autolink flattened the same way returns as a bare URL, and that is a
     * DIFFERENT document: a bare URL stays literal where an autolink is a link.
     *
     * @return void
     */
    public function testAnAutolinkInALabelKeepsItsNode(): void
    {
        $this->assertSame(
            ['link=z', 'autolink=https://e.com/i'],
            $this->links("[<https://e.com/i>](z)\n"),
        );
    }

    /**
     * Corpus `03-links-12.crv`, the second document the three-way panel named.
     * The flattened form spliced the autolink's DISPLAY text in and coalesced
     * the three runs around it into one; the node is published instead, and the
     * runs either side of it stay separate because they no longer touch.
     *
     * @return void
     */
    public function testTheCorpusAutolinkDocumentKeepsItsNode(): void
    {
        $source = "[pre <http://h> post](/u)\n";

        $this->assertSame(['link=/u', 'autolink=http://h'], $this->links($source));

        $label = $this->published($source)['children'][0]['children'][0]['children'];
        $this->assertSame(['text', 'autolink', 'text'], array_column($label, 'type'));
        $this->assertSame('pre ', $label[0]['value']);
        $this->assertSame(' post', $label[2]['value']);
    }

    /**
     * The walk carries the inside-a-link flag through every other inline, so an
     * inner link one level down inside emphasis was flattened too.
     *
     * @return void
     */
    public function testALinkInsideEmphasisInsideALabelKeepsItsNode(): void
    {
        $this->assertSame(['link=z', 'link=y'], $this->links("[/[x](y)/](z)\n"));
    }

    /**
     * Three levels: the middle node is the one a single-level guard would keep.
     *
     * @return void
     */
    public function testTripleNestingKeepsEveryNode(): void
    {
        $this->assertSame(
            ['link=d', 'link=c', 'link=b'],
            $this->links("[[[a](b)](c)](d)\n"),
        );
    }

    /**
     * CONTROL, not a fix. §3a calls the `heading_ref` exemption "the precedent
     * inside this rule already" - it was never flattened on the publish path,
     * because that half of the walk needs a resolved id and so runs only on the
     * render path. It is asserted here so a later change that reintroduces a
     * parse-path unwrap cannot take it down with the rest.
     *
     * @return void
     */
    public function testAHeadingRefInALabelIsUnchangedControl(): void
    {
        $this->assertSame(
            ['link=z', 'heading_ref=h'],
            $this->links("[see </#h> here](z)\n\n# H {#h}\n"),
        );
    }

    /**
     * An UNRESOLVED reference is a `link` node that never renders as an anchor,
     * so the render seam already leaves it alone. Pinned as a CONTROL: it was
     * correct before this change and must stay correct after it.
     *
     * @return void
     */
    public function testAnUnresolvedReferenceInALabelIsUnchangedControl(): void
    {
        $this->assertSame(['link=/z', 'link='], $this->links("[[x][missing]](/z)\n"));
    }

    /**
     * RENDERED OUTPUT DOES NOT MOVE. Each of the four targets runs
     * CrossReferenceResolver::resolve(), which is where the render seam unwraps,
     * so this is a wire-format change with no HTML consequence. Without this,
     * removing the parse-path unwrap could have leaked a nested anchor.
     *
     * @return void
     */
    public function testTheRenderedAnchorIsStillNotNested(): void
    {
        $converter = new CarveConverter();

        $this->assertSame(
            "<p><a href=\"z\">x</a></p>\n",
            $converter->convert("[[x](y)](z)\n"),
        );
        // The autolink's display text survives the render seam whole, which is
        // what the flattening used to guarantee by splicing it in early. An
        // email autolink drops its `mailto:` scheme in the display, so the seam
        // has to unwrap to the DISPLAY and not to the destination.
        $this->assertSame(
            "<p><a href=\"/u\">pre http://h post</a></p>\n",
            $converter->convert("[pre <http://h> post](/u)\n"),
        );
        $this->assertSame(
            "<p><a href=\"/u\">pre a@b.example post</a></p>\n",
            $converter->convert("[pre <a@b.example> post](/u)\n"),
        );
    }

    /**
     * PART 11 §6: `fmt` on the parsed document and `fmt` through the AST are the
     * same bytes. This is the acceptance pin §3a names, and it is what actually
     * fails when the encoder folds - the two paths disagreed on `[x](z)` versus
     * `[[x](y)](z)`.
     *
     * @return void
     */
    public function testFmtThroughTheAstAgreesWithFmtOnTheDocument(): void
    {
        $codec = new AstCodec();

        foreach (["[[x](y)](z)\n", "[<https://e.com/i>](z)\n", "[[[a](b)](c)](d)\n"] as $source) {
            $direct = CarveConverter::toCarve($source);
            $viaAst = CarveConverter::carve()->getRenderer()->render(
                $codec->decode($codec->encode((new CarveConverter())->parse($source))),
            );

            $this->assertSame($direct, $viaAst, 'fmt disagreed through the AST for: ' . $source);
        }
    }
}
