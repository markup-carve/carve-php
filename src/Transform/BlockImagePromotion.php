<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Node;
use function count;

/**
 * THE BLOCK-IMAGE PROMOTION PHASE (PART 9R R7, PART 12 section 23,
 * markup-carve/carve-php#1800).
 *
 * Block-image status is a property of the RESOLVED tree, not of the source line:
 * `![a][r]` is a block image where `[r]: /u` is written and ordinary prose where
 * it is not, and the definition may sit anywhere in the document. The question
 * used to be asked in four places at once - a parser pass, the renderer's own
 * predicate, and the two call sites that reached through it to choose a quote's
 * frame and a list item's `<p>`. This is the one place it is answered, and the
 * renderers read the answer.
 *
 * THREE ENTRY POINTS, ONE PREDICATE, and they are not redundant:
 *
 * - {@see \MarkupCarve\Carve\Parser\BlockParser::promoteBlockImages()} runs it
 *   over a freshly parsed document, once every definition is known.
 * - {@see \MarkupCarve\Carve\Ast\AstCodec::decode()} runs `promoteWhereAbsent()`
 *   over an ingested one, which is the ingest rule section 23 states.
 * - {@see \MarkupCarve\Carve\Renderer\HtmlRenderer::render()} runs
 *   `promoteWhereAbsent()` before anything is serialized, so a tree that reached
 *   the renderer without passing either - one an editor, an extension or a test
 *   built by hand - still gets the answer instead of silently losing its bare
 *   `<img>`.
 */
final class BlockImagePromotion
{
    /**
     * Does this paragraph's whole content resolve to a single image?
     *
     * THE SEMANTIC PREDICATE, and the only copy of it. An UNRESOLVED reference
     * image carries no destination and renders as its literal source (PART 12
     * section 3a), so it stays inside its paragraph.
     *
     * UNGATED ON COLUMN, deliberately. A lone-image paragraph renders as a bare
     * block `<img>` at EVERY column, which is what the published HTML says and
     * what carve-js and carve-rs also emit. PART 9 section 15's strict column-0
     * rule governs something else: whether the paragraph is REPLACED by an image
     * node in the tree, and whether its source attributes move onto the image.
     * Reading one gate for both answers would either withhold the field from a
     * paragraph the HTML treats as an image, or publish an image node where the
     * tree must show a paragraph.
     *
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     */
    public static function isBlockImage(Paragraph $paragraph): bool
    {
        $children = $paragraph->getChildren();

        return count($children) === 1
            && $children[0] instanceof Image
            && ($children[0]->getRawReferenceLabel() === null || $children[0]->getSource() !== '');
    }

    /**
     * Answer for every paragraph in the tree, overwriting any previous answer.
     *
     * Recomputed rather than accumulated, so promoting one tree twice cannot
     * leave a stale `true` behind.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     */
    public static function promote(Node $node): void
    {
        self::walk($node, false);
    }

    /**
     * Answer only where no answer is recorded: TRUST what is already there.
     *
     * The ingest rule of PART 12 section 23, and the same rule serves a
     * hand-built tree. Absence is not a claim: every AST JSON document written
     * before the phase existed omits the field, so a paragraph without it says
     * the producer did not run the phase, NOT that it is ordinary - which is
     * also why a tree is never refused for omitting it.
     *
     * ONE-DIRECTIONAL, so a `true` that arrived on a payload survives. The
     * producer resolved against ITS document's definitions, and re-deciding here
     * would substitute this tree's (possibly edited-down) reference table for
     * the answer the producer published.
     *
     * A recorded `false` is indistinguishable from an absent field, and that is
     * sound rather than lucky: the schema pins the field at `const: true`, so a
     * payload carrying `blockImage: false` is refused at decode. The only two
     * shapes that reach here are `true` and nothing at all.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     */
    public static function promoteWhereAbsent(Node $node): void
    {
        self::walk($node, true);
    }

    /**
     * The walk both entry points share.
     *
     * ITERATIVE, and that is load-bearing rather than a style choice: a lone
     * image can sit inside any container, so this descends as deep as the
     * document, and the renderer runs it on documents whose whole point is
     * depth. A recursive walk exhausted the C stack and took the process down
     * with SIGSEGV - a crash rather than the typed refusal the depth guards
     * exist to produce. carve-js and carve-rs spell their walk the same way, for
     * the same reason.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param bool $whereAbsent Leave a recorded `true` alone.
     */
    private static function walk(Node $node, bool $whereAbsent): void
    {
        $stack = [$node];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($current->getChildren() as $child) {
                if ($child instanceof Paragraph && !($whereAbsent && $child->isBlockImage())) {
                    $child->setBlockImage(self::isBlockImage($child));
                }
                $stack[] = $child;
            }
        }
    }
}
