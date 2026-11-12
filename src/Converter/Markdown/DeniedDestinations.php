<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use Closure;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\SmallCaps;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Subscript;
use MarkupCarve\Carve\Node\Inline\Superscript;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Node;

/**
 * Markdown import does not write a destination the PART 9 §25 sink blanks
 * (carve#2254, carve#6010); only the content is kept.
 *
 * @internal
 */
final class DeniedDestinations
{
    /**
     * @var string
     */
    public const LINK = 'Dropped a link destination with a denied URL scheme';

    /**
     * @var string
     */
    public const IMAGE = 'Dropped an image source with a denied URL scheme';

    /**
     * @var string
     */
    public const REFERENCE = 'Dropped a reference definition with a denied URL scheme';

    /**
     * Inline formatting with no spelling once empty.
     *
     * @var list<class-string<\MarkupCarve\Carve\Node\Node>>
     */
    private const WRAPPERS = [Emphasis::class, Strong::class, Underline::class, Strike::class, Superscript::class, Subscript::class, Highlight::class, Span::class, Insert::class, Delete::class, SmallCaps::class];

    /**
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param \Closure(string): void $report Called once per dropped destination; a reference link is covered by its definition's row.
     */
    public static function drop(Document $document, Closure $report): bool
    {
        $changed = false;
        self::visit($document, $report, $changed);

        return $changed;
    }

    private static function denied(?string $url): bool
    {
        return $url !== null && HtmlAstBuilder::hasDeniedScheme($url);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \Closure(string): void $report
     * @param bool $changed
     */
    private static function visit(Node $node, Closure $report, bool &$changed): void
    {
        $children = $node->getChildren();
        if ($children === []) {
            return;
        }
        $inline = $node instanceof InlineNode || $node instanceof Paragraph || $node instanceof Heading || $node instanceof Caption
            || $node instanceof DefinitionTerm || ($node instanceof TableCell && !$node->hasBlockContent());
        $out = [];
        $dropped = false;
        foreach ($children as $child) {
            if ($child instanceof LinkReferenceDefinition && self::denied($child->getHref())) {
                $changed = true;
                $report(self::REFERENCE);
            } elseif ($child instanceof Link && self::denied($child->getDestination())) {
                $changed = $dropped = true;
                if ($child->getReferenceLabel() === null) {
                    $report(self::LINK);
                }
                self::visit($child, $report, $changed);
                array_push($out, ...self::standIn($child->getChildren(), $child));
            } elseif ($child instanceof Image && self::denied($child->getSource())) {
                $changed = $dropped = true;
                if ($child->getReferenceLabel() === null) {
                    $report(self::IMAGE);
                }
                $content = self::standIn($child->getAlt() === '' ? [] : [new Text($child->getAlt())], $child);
                if ($inline) {
                    array_push($out, ...$content);
                } elseif ($content !== []) {
                    $paragraph = new Paragraph();
                    $paragraph->setChildren($content);
                    $out[] = $paragraph;
                }
            } else {
                $held = self::isWrapper($child) && $child->getChildren() !== [];
                self::visit($child, $report, $changed);
                // A wrapper whose only content was a dropped empty link goes with it.
                if ($held && $child->getChildren() === [] && $child->getAttributes() === []) {
                    $dropped = true;
                } else {
                    $out[] = $child;
                }
            }
        }
        if ($out === $children) {
            return;
        }
        if ($dropped && ($node instanceof Paragraph || $node instanceof Heading || $node instanceof TableCell || $node instanceof Caption || $node instanceof DefinitionTerm)) {
            $out = self::trimEdges($out);
        }
        $node->setChildren($out);
    }

    private static function isWrapper(Node $node): bool
    {
        foreach (self::WRAPPERS as $class) {
            if ($node instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Content only, in a span where attributes survive: the HTML importer's shape.
     *
     * @param array<int, \MarkupCarve\Carve\Node\Node> $children
     * @param \MarkupCarve\Carve\Node\Node $from
     *
     * @return array<int, \MarkupCarve\Carve\Node\Node>
     */
    private static function standIn(array $children, Node $from): array
    {
        if ($from->getAttributes() === []) {
            return $children;
        }
        $span = new Span();
        $span->setAttributesWithOrder($from->getAttributes(), $from->getAttributeOrder());
        $span->inheritLastSpellings($from->getLastSpellings());
        $span->setChildren($children);

        return [$span];
    }

    /**
     * The whitespace a dropped construct leaves at a block edge or a line break.
     *
     * @param array<int, \MarkupCarve\Carve\Node\Node> $nodes
     *
     * @return array<int, \MarkupCarve\Carve\Node\Node>
     */
    private static function trimEdges(array $nodes): array
    {
        $nodes = array_values($nodes);
        $count = count($nodes);
        foreach ($nodes as $at => $node) {
            if (!$node instanceof Text || $node::class !== Text::class || $node->getAttributes() !== []) {
                continue;
            }
            $content = $node->getContent();
            if ($at === 0 || $nodes[$at - 1] instanceof SoftBreak) {
                $content = ltrim($content, " \t");
            }
            if ($at === $count - 1 || $nodes[$at + 1] instanceof SoftBreak) {
                $content = rtrim($content, " \t");
            }
            $node->setContent($content);
        }

        return array_values(array_filter($nodes, static fn (Node $node): bool => !($node instanceof Text && $node::class === Text::class && $node->getContent() === '' && $node->getAttributes() === [])));
    }
}
