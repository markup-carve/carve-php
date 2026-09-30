<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;

/**
 * Resolves forward references and publishes authored definitions.
 *
 * @internal
 */
final class BlockReferenceResolver
{
    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \Closure(): \MarkupCarve\Carve\Parser\InlineParser $getInlineParser
     * @param \Closure(string): ?\MarkupCarve\Carve\Parser\ReferenceDefinition $getReferenceCallback
     * @param \Closure(string): bool $hasFootnoteCallback
     * @param \Closure(string, int): void $markReferenceUsedCallback
     * @param \Closure(string, int, int): void $trackAnchorLinkCallback
     * @param \Closure(int): ?\MarkupCarve\Carve\Ast\SourceSpan $wholeLineSpanCallback
     */
    public function __construct(
        private BlockParserState $state,
        private Closure $getInlineParser,
        private Closure $getReferenceCallback,
        private Closure $hasFootnoteCallback,
        private Closure $markReferenceUsedCallback,
        private Closure $trackAnchorLinkCallback,
        private Closure $wholeLineSpanCallback,
    ) {
    }

    public function resolveForwardReferences(Node $node, int $depth = 0): void
    {
        if ($depth >= BlockGrammar::MAX_HEADING_WALK_DEPTH) {
            return;
        }
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Link && UnresolvedReference::sourceOf($child) !== null) {
                $label = $child->getReferenceLabel();
                $definition = $label !== null ? $this->getReference($label) : null;
                if ($definition !== null) {
                    $child->resolveReference($definition->url, $definition->title);
                    $this->applyDeferredReferenceAttributes($child, $definition->attributes);
                    $this->markReferenceUsed($label, 0);
                    $this->trackAnchorOfResolvedLink($child, $definition->url);
                }
            } elseif ($child instanceof Link && $child->isFromHeadingReference()) {
                // AN EXPLICIT DEFINITION BEATS THE IMPLICIT HEADING IT ALREADY
                // RESOLVED TO (corpus 173, corpus 275). getCollapsedReference()
                // states that precedence - definitions first, heading index
                // second - but it is asked during inline parsing, and on this
                // path a definition written BELOW its use is not collected yet.
                // So `[Defined][]` took `#Defined` from the heading index while
                // `[Defined]: /wins` was still to come.
                //
                // The AUTHORED label is what a definition is keyed by, and it
                // is not the label the heading index answered with: the index
                // matches a heading's RENDERED text, so `[*bold* heading][]`
                // came back as `bold heading` while its definition is written
                // `[*bold* heading]: /x`. Looking the definition up by the
                // resolved label finds nothing, so the raw label is used and
                // both labels are restored when it wins.
                $rawLabel = self::collapsedLabelOf($child->getRawReferenceLabel());
                $definition = $rawLabel !== null ? $this->getReference($rawLabel) : null;
                if ($definition !== null) {
                    $child->setReferenceLabel($rawLabel);
                    $child->setFromHeadingReference(false);
                    $child->resolveReference($definition->url, $definition->title);
                    $this->applyDeferredReferenceAttributes($child, $definition->attributes);
                    $this->markReferenceUsed($rawLabel, 0);
                    $this->trackAnchorOfResolvedLink($child, $definition->url);
                }
            } elseif ($child instanceof Image && UnresolvedReference::sourceOf($child) !== null) {
                $label = $child->getReferenceLabel();
                $definition = $label !== null ? $this->getReference($label) : null;
                if ($definition !== null) {
                    $child->resolveReference($definition->url, $definition->title);
                    $this->applyDeferredReferenceAttributes($child, $definition->attributes);
                    $this->markReferenceUsed($label, 0);
                }
            } elseif ($child instanceof FootnoteRef && $child->isUnresolved() && $this->hasFootnote($child->getLabel())) {
                $child->setUnresolved(false);
            }
            if ($child->hasChildren()) {
                $this->resolveForwardReferences($child, $depth + 1);
            }
        }
        if ($depth === 0 && $this->state->session->abbreviations !== []) {
            ($this->getInlineParser)()->expandLateAbbreviations($node, $this->state->session->abbreviations);
        }
    }

    /**
     * The AUTHORED label inside a reference's raw source.
     *
     * `[label][]` collapsed, `[text][label]` full. Returns null for anything
     * else, so a caller falls back rather than guessing.
     *
     * @param string|null $raw
     */
    public static function collapsedLabelOf(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (preg_match('/^\\[(.*)\\]\\[\\]$/s', $raw, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/^\\[.*\\]\\[(.+)\\]$/s', $raw, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * A reference resolving HERE owes what one resolving inline already did.
     *
     * `InlineParser` tracks a `#fragment` destination for anchor validation at
     * the moment it resolves a reference. A reference whose definition sits
     * BELOW its use does not resolve there - it resolves in this pass - and the
     * anchor went untracked, so a broken `[ref]: #nowhere` warned when the
     * definition was written above the link and said nothing when it was
     * written below (carve-php#1853).
     *
     * @param \MarkupCarve\Carve\Node\Inline\Link $link
     * @param string $url
     */
    public function trackAnchorOfResolvedLink(Link $link, string $url): void
    {
        if (preg_match('/^#(.+)$/', $url, $anchorMatch) !== 1) {
            return;
        }

        $pos = $link->getPos();
        $this->trackAnchorLink($anchorMatch[1], $pos->startLine ?? 0, $pos->startColumn ?? 0);
    }

    /**
     * Definition attributes precede authored trailing attributes; authored
     * values win when both write the same key.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, string|list<string>> $definitionAttributes
     */
    public function applyDeferredReferenceAttributes(Node $node, array $definitionAttributes): void
    {
        if ($definitionAttributes === []) {
            return;
        }
        $authored = $node->getAttributeEntries();
        $authoredOrder = $node->getAttributeOrder();
        $definition = $definitionAttributes;
        if (isset($definition['class'], $authored['class'])) {
            $authored['class'] = [...(array)$definition['class'], ...(array)$authored['class']];
            unset($definition['class']);
        }
        $order = array_map(
            static fn (string $name): string => match ($name) {
                'id' => '#id',
                'class' => '.class',
                default => $name,
            },
            array_keys($definition),
        );
        $node->setAttributesWithOrder(array_merge($definition, $authored), [...$order, ...$authoredOrder]);
    }

    public function appendLinkReferenceDefinitions(Document $document): void
    {
        // EVERY entry is authored now. This used to skip `$definition->fromHeading`,
        // because a heading was seeded into `$this->state->session->references` beside the folded
        // index; markup-carve/carve#742 stops that seeding, so the check can no
        // longer fail and is removed rather than left reading as a guard. The
        // observable it protected - no `link_reference_definition` node, and so no
        // invented `[H]: #H` line from the canonical writer, for a document that
        // only ever had a heading - is pinned in ImplicitHeadingReferenceTest.
        $authored = [];
        foreach ($this->state->session->references as $key => $definition) {
            // strval, because PHP turns an all-digit array key into an INT.
            // A reference label is any inline text, so `[5]: /u` keys the map
            // with 5 rather than "5", and the definition node's constructor
            // types its label `string` - a fatal TypeError on an ordinary
            // document (carve-php#881). Same coercion that broke a digit-only
            // abbreviation term in #880, and the same guard the attribute names
            // below already carry.
            $authored[] = [$definition->rawLabel ?? (string)$key, $definition];
        }
        usort($authored, static fn (array $a, array $b): int => $a[1]->line <=> $b[1]->line);

        foreach ($authored as [$label, $definition]) {
            $node = new LinkReferenceDefinition($label, $definition->url, $definition->title);
            if ($definition->attributes !== []) {
                // SLOT spellings, not raw keys. The writer's `#id` slot is what
                // emits an id, and its raw-`id` branch returns early on purpose
                // so a key cannot be written twice - so an order list holding
                // `id` dropped the id from the definition line entirely
                // (carve-php#831). Same mapping the block-attribute path uses.
                $node->setAttributesWithOrder(
                    $definition->attributes,
                    array_map(
                        static fn (string $name): string => match ($name) {
                            'id' => '#id',
                            'class' => '.class',
                            default => $name,
                        },
                        array_map('strval', array_keys($definition->attributes)),
                    ),
                );
            }
            // PART 12 §4 requires `pos` on every node but the root, and §10 says
            // a hoisted definition's span still points at the line the author
            // wrote it on - which is the whole point of hoisting a NODE rather
            // than a root map. The definition is single-line by production.
            $node->setPos($this->wholeLineSpan($definition->line));
            $document->appendChild($node);
        }
    }

    private function getReference(string $label): ?ReferenceDefinition
    {
        return ($this->getReferenceCallback)($label);
    }

    private function hasFootnote(string $label): bool
    {
        return ($this->hasFootnoteCallback)($label);
    }

    private function markReferenceUsed(string $label, int $line): void
    {
        ($this->markReferenceUsedCallback)($label, $line);
    }

    private function trackAnchorLink(string $fragment, int $line, int $column): void
    {
        ($this->trackAnchorLinkCallback)($fragment, $line, $column);
    }

    private function wholeLineSpan(int $index): ?SourceSpan
    {
        return ($this->wholeLineSpanCallback)($index);
    }
}
