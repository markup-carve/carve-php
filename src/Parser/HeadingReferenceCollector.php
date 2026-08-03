<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;

/**
 * Build the implicit `[Heading][]` reference index from the PARSED TREE.
 *
 * PART 11 R1 puts a document's headings in this index keyed by their rendered
 * text, and declines exactly one case: a heading with a BLOCKQUOTE ANCESTOR,
 * in either nesting order. Quoted text names the quoted document's headings,
 * not this one's, and a quotation is the one container whose wording the
 * author does not control. Everything else - divs, admonitions, list items,
 * definitions - is the author's own grouping and resolves.
 *
 * This replaces a line-based pre-scan that matched `^#{1,6}` at column 0.
 * Which headings that found came down to source indentation rather than
 * structure: a div's inner lines start at column 0 and were indexed, a list
 * item's are indented and were not, and a blockquote's carry `>` and were not.
 * Two of the three answers were right and all three were accidents, so the
 * blockquote rule held only for as long as quoted content kept its prefix
 * (carve-php#572). Walking the tree asks the question the rule actually asks.
 */
class HeadingReferenceCollector
{
    /**
     * @var array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}>
     */
    protected array $references = [];

    public function __construct(protected HeadingIdTracker $headingIdTracker)
    {
    }

    /**
     * Collect from `$root`, returning folded label => [label, reference].
     *
     * Both spellings are needed: the folded key serves the case-insensitive
     * collapsed `[text][]` lookup, and the label as authored serves the exact
     * `[text][Label]` one, which resolves against headings too.
     *
     * @return array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}>
     */
    public function collect(Node $root): array
    {
        $this->references = [];
        $this->walk($root, false);

        return $this->references;
    }

    protected function walk(Node $node, bool $inBlockQuote): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Heading) {
                // EVERY heading draws an id, declined or not. The renderer
                // numbers all of them in document order, so skipping the
                // quoted ones here would shift the counter and hand out a
                // reference to the wrong anchor: `> # H` then `- # H` would
                // resolve to `#H`, the quoted heading R1 exists to exclude,
                // instead of `#H-2`.
                $id = $this->headingIdTracker->getIdForHeading($child);
                if (!$inBlockQuote) {
                    $this->register($child, $id);
                }

                continue;
            }

            // A blockquote anywhere above a heading declines it, so the flag
            // is sticky on the way down rather than checked at the heading.
            $this->walk($child, $inBlockQuote || $child instanceof BlockQuote);
        }
    }

    protected function register(Heading $heading, string $id): void
    {
        $plainText = $this->headingIdTracker->getPlainText($heading);
        $label = preg_replace('/\s+/', ' ', trim($plainText)) ?? $plainText;
        if ($label === '') {
            return;
        }

        $folded = $this->foldLabel($label);
        if (isset($this->references[$folded])) {
            // FIRST occurrence wins, matching the id-dedup order.
            return;
        }

        $this->references[$folded] = [$label, new ReferenceDefinition('#' . $id, [], 0, null, true)];
    }

    /**
     * R1 matches the heading index case-insensitively, which is looser than
     * the exact, case-sensitive link-definition match in the same rule: a
     * definition label is an identifier the author wrote twice, while a
     * heading reference is prose quoted from elsewhere in the document.
     */
    protected function foldLabel(string $label): string
    {
        return (string)preg_replace_callback(
            '/./us',
            static fn (array $m): string => mb_strtolower($m[0], 'UTF-8'),
            $label,
        );
    }
}
