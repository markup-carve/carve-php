<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Inline\Chars;

/**
 * The footnote section (C2-M-061 to C2-M-064, C2-H-089, C2-H-194): its notes are recorded before the references to
 * them are read.
 */
final class Notes
{
    public static function isSection(HtmlNode $node): bool
    {
        return $node->isElement('section') && ($node->attr('role') === 'doc-endnotes' || in_array('footnotes', $node->classes(), true));
    }

    /**
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $nodes
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     */
    public static function collect(array $nodes, ImportContext $context): void
    {
        $pending = $nodes;
        $sections = [];
        $referenced = [];
        while ($pending !== []) {
            $node = array_pop($pending);
            if (!$node->isElement() || Elements::isRawSpan($node)) {
                continue;
            }
            if (self::isSection($node)) {
                $sections[] = $node;
            } else {
                self::recordReference($node, $referenced);
            }
            // A note can hold the reference to another note.
            array_push($pending, ...$node->children);
        }
        foreach (array_reverse($sections) as $section) {
            self::collectSection($section, $referenced, $context);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<string, array<string|null>> $referenced
     */
    private static function recordReference(HtmlNode $node, array &$referenced): void
    {
        $href = $node->attr('href');
        if (!$node->isElement('a') || $href === null || !str_starts_with($href, '#') || $node->attr('role') !== 'doc-noteref') {
            return;
        }
        $referenced[substr($href, 1)][] = $node->attr('data-carve-footnote-label');
    }

    /**
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<string, array<string|null>> $referenced
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     */
    private static function collectSection(HtmlNode $node, array $referenced, ImportContext $context): void
    {
        $list = $node->firstElement('ol');
        foreach ($list === null ? [] : $list->elements() as $item) {
            if ($item->name !== 'li') {
                continue;
            }
            self::dropBacklinks($item);
            $id = $item->attr('id');
            if ($id !== null && $id !== '') {
                $context->notes[$id] = self::entry($item, $referenced[$id] ?? null, $context);
            }
        }
    }

    /**
     * C2-M-063, C2-H-091: the backlinks a render adds to the last paragraph of a note, with the spaces before them.
     */
    private static function dropBacklinks(HtmlNode $item): void
    {
        $elements = $item->elements();
        $last = $elements[count($elements) - 1] ?? null;
        if ($last === null || $last->name !== 'p') {
            return;
        }
        $nodes = $last->children;
        $end = count($nodes);
        $found = false;
        while ($end > 0 && (InlineCarriers::isBacklink($nodes[$end - 1]) || $nodes[$end - 1]->isBlank())) {
            $found = $found || InlineCarriers::isBacklink($nodes[$end - 1]);
            $end--;
        }
        if (!$found) {
            return;
        }
        $kept = array_slice($nodes, 0, $end);
        $blank = array_filter($kept, static fn (HtmlNode $n): bool => !$n->isBlank()) === [];
        if ($blank) {
            $item->children = array_values(array_filter($item->children, static fn (HtmlNode $n): bool => $n !== $last));

            return;
        }
        $tail = $kept[count($kept) - 1];
        if ($tail->isText()) {
            $tail->name = rtrim($tail->name, " \t");
        }
        $last->children = $kept;
    }

    /**
     * @param \MarkupCarve\Carve\Import\HtmlNode $item
     * @param array<string|null>|null $references
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array{item: \MarkupCarve\Carve\Import\HtmlNode, label: string|null, inline: bool}
     */
    private static function entry(HtmlNode $item, ?array $references, ImportContext $context): array
    {
        $label = $item->attr('data-carve-footnote-label');
        $inline = $item->has('data-carve-inline-footnote');
        $fault = $inline ? self::inlineFault($item, $references !== null) : null;
        if ($fault !== null) {
            $context->ignoredCarrier($item, 'data-carve-inline-footnote', $fault);
        }
        if ($label !== null && ($label === '' || Chars::fold($label) !== $label || $inline)) {
            $reason = $inline ? 'the note is also an inline footnote' : 'the key is not folded';
            $context->ignoredCarrier($item, 'data-carve-footnote-label', $reason);

            return ['item' => $item, 'label' => null, 'inline' => false];
        }
        // C2-H-186: a reference that carries another key makes the carriers of both disagree, and neither is read.
        foreach ($references ?? [] as $key) {
            if ($label !== null && $key !== null && $key !== $label) {
                $context->ignoredCarrier($item, 'data-carve-footnote-label', 'a reference to the note carries another key');

                return ['item' => $item, 'label' => null, 'inline' => false];
            }
        }

        return ['item' => $item, 'label' => $label, 'inline' => $inline && $fault === null];
    }

    private static function inlineFault(HtmlNode $item, bool $referenced): ?string
    {
        if ($item->has('data-carve-footnote-label')) {
            return 'the note also carries a footnote key';
        }
        if (!$referenced) {
            return 'no reference points to the note';
        }
        $blocks = array_values(array_filter($item->children, static fn (HtmlNode $n): bool => !$n->isBlank()));
        $single = count($blocks) <= 1 && ($blocks === [] || $blocks[0]->isElement('p'));

        return $single ? null : 'the note holds more than one paragraph';
    }
}
