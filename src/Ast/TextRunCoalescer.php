<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;

/**
 * PART 12 §1a: a node's children hold no two adjacent `text` nodes.
 *
 * This parser emits a run per stretch left over where a delimiter did not open
 * emphasis, so `foo_bar_baz and snake_case stay literal` is four nodes here and
 * one in the reference. Same characters, same rendering, different tree - and
 * both valid against the schema, which is why nothing caught it.
 *
 * IT RUNS ON THE PARSED TREE, NOT DURING SERIALIZATION. §6 requires `parse(x)`
 * serialized and deserialized to equal `parse(x)`; joining runs in the encoder
 * satisfies §1a and breaks §6 on the same document, because what comes back
 * holds one node where the tree held four. That is not hypothetical: it is how
 * this was first implemented (#617) and what #623 reported.
 *
 * `escaped_text` is NOT `text` and does not merge with it: PART 12 §5 keeps the
 * two distinct because an escape is authored form.
 */
class TextRunCoalescer
{
    /**
     * Joins every run of adjacent text nodes in the tree, in place.
     */
    public static function apply(Node $node): void
    {
        foreach ($node->getChildren() as $child) {
            self::apply($child);
        }

        // Inline content does not only live in `children`. A citation item
        // carries three separate inline arrays, and a walk that follows only
        // `children` holds for the corpus while leaving the vocabulary open.
        if ($node instanceof CitationGroup) {
            self::coalesceCitationItems($node);
        }

        self::coalesceChildren($node);
    }

    protected static function coalesceChildren(Node $node): void
    {
        $children = $node->getChildren();
        if (count($children) < 2) {
            return;
        }

        // Each run collapses into the node it STARTS with, so a run of four
        // ends as one node holding all four values. Appending into the
        // immediate predecessor instead would chain the merges and drop
        // everything but the first pair.
        //
        // The survivors are collected and assigned ONCE. Removing merged nodes
        // one at a time is quadratic - each removal shifts every later element
        // - and an adversarial document is one long run: 50,000 `[x]{` openers
        // parse to 50,000 adjacent text nodes.
        $survivors = [];
        $runStart = null;
        $merged = false;
        foreach ($children as $child) {
            if (!$child instanceof Text) {
                $runStart = null;
                $survivors[] = $child;

                continue;
            }
            if ($runStart === null) {
                $runStart = $child;
                $survivors[] = $child;

                continue;
            }
            $runStart->setPos(self::mergedPos($runStart->getPos(), $child->getPos()));
            $runStart->appendContent($child->getContent());
            $merged = true;
        }

        if ($merged) {
            $node->setChildren($survivors);
        }
    }

    protected static function coalesceCitationItems(CitationGroup $group): void
    {
        $items = $group->getItems();
        foreach ($items as $index => $item) {
            foreach (['prefix', 'locator', 'suffix'] as $field) {
                if (!isset($item[$field])) {
                    continue;
                }
                $items[$index][$field] = self::coalesceList($item[$field]);
            }
        }
        $group->setItems($items);
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Inline\InlineNode> $nodes
     *
     * @return list<\MarkupCarve\Carve\Node\Inline\InlineNode>
     */
    protected static function coalesceList(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            self::apply($node);
            $previous = $out === [] ? null : $out[count($out) - 1];
            if ($previous instanceof Text && $node instanceof Text) {
                // $previous is the node the run started with, so a long run
                // still collapses into one node rather than chaining.
                $previous->setPos(self::mergedPos($previous->getPos(), $node->getPos()));
                $previous->appendContent($node->getContent());

                continue;
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * The span of two joined runs, or null when it would not be truthful.
     *
     * Only CONTIGUOUS pieces keep a span. Where they are not adjacent in the
     * source - two halves of a wrapped table cell, an autolink unwrapped inside
     * a link label - the joined value is not a slice of the source at any
     * offset, and PART 12 §4 rates a span that selects the wrong text worse
     * than no span at all.
     */
    protected static function mergedPos(?SourceSpan $left, ?SourceSpan $right): ?SourceSpan
    {
        if ($left === null || $right === null) {
            return null;
        }
        if ($left->endOffset !== $right->startOffset) {
            return null;
        }

        return new SourceSpan(
            $left->startLine,
            $right->endLine,
            $left->startColumn,
            $right->endColumn,
            $left->startOffset,
            $right->endOffset,
        );
    }
}
