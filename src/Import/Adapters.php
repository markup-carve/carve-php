<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use Closure;

/**
 * The editor adapters of the importer (C2-M-102 to C2-M-110): `word` and `google-docs` read the notes a word processor
 * exports as mutually linked anchors as footnotes, on the parsed tree before the mapping.
 */
final class Adapters
{
    /**
     * @var array<string, true>
     */
    private const NOTE_PASS = ['word' => true, 'google-docs' => true];

    /**
     * @var array<string, true>
     */
    private const BLOCKS = [
        'li' => true,
        'div' => true,
        'section' => true,
        'aside' => true,
        'p' => true,
        'td' => true,
        'blockquote' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const WRAPPERS = ['div' => true, 'li' => true, 'section' => true, 'aside' => true];

    /**
     * @var array<\MarkupCarve\Carve\Import\HtmlNode>
     */
    private array $order = [];

    /**
     * Element id to its position in `$order`.
     *
     * @var array<int, int>
     */
    private array $index = [];

    /**
     * Element id to the position of the last element of its subtree.
     *
     * @var array<int, int>
     */
    private array $end = [];

    /**
     * @var array<\MarkupCarve\Carve\Import\HtmlNode>
     */
    private array $sections = [];

    /**
     * Positions of the `<a>` elements of each `href`, in document order.
     *
     * @var array<string, array<int>>
     */
    private array $byHref = [];

    private Edits $edits;

    private function __construct(private HtmlNode $root)
    {
        $this->edits = new Edits();
    }

    /**
     * C2-M-102: `word` and `google-docs` run the note pass; every other adapter leaves the tree as it is.
     */
    public static function apply(HtmlNode $root, string $adapter): void
    {
        if (isset(self::NOTE_PASS[$adapter])) {
            (new self($root))->notePass();
        }
    }

    private function notePass(): void
    {
        $this->walk();
        $targets = $this->fragmentTargets();
        $anchors = array_values(array_filter($this->order, fn (HtmlNode $node): bool => $this->isAnchor($node, $targets)));
        if ($anchors === []) {
            return;
        }
        $notes = $this->notesOf($this->references($anchors, $targets));
        if ($notes === []) {
            return;
        }
        $this->attachRemaining($notes, $anchors);
        $this->rewrite($notes);
    }

    private function walk(): void
    {
        $stack = [];
        foreach (array_reverse($this->root->children) as $child) {
            if ($child->isElement()) {
                $stack[] = [$child, false];
            }
        }
        while ($stack !== []) {
            [$node, $exit] = array_pop($stack);
            $id = spl_object_id($node);
            if ($exit) {
                $this->end[$id] = count($this->order) - 1;

                continue;
            }
            if (Notes::isSection($node)) {
                $this->sections[] = $node;
            }
            $at = count($this->order);
            $this->index[$id] = $at;
            $this->order[] = $node;
            $href = $node->name === 'a' ? $node->attr('href') : null;
            if ($href !== null) {
                $this->byHref[$href][] = $at;
            }
            $stack[] = [$node, true];
            foreach (array_reverse($node->children) as $child) {
                if ($child->isElement()) {
                    $stack[] = [$child, false];
                }
            }
        }
    }

    private function at(HtmlNode $node): int
    {
        return $this->index[spl_object_id($node)];
    }

    private function contains(HtmlNode $outer, HtmlNode $inner): bool
    {
        $at = $this->at($inner);

        return $at >= $this->at($outer) && $at <= $this->end[spl_object_id($outer)];
    }

    private static function fragment(HtmlNode $node): ?string
    {
        $href = $node->attr('href');

        return $href !== null && strlen($href) > 1 && $href[0] === '#' ? substr($href, 1) : null;
    }

    private static function identity(HtmlNode $node): string
    {
        $id = $node->attr('id');

        return $id !== null && $id !== '' ? $id : ($node->attr('name') ?? '');
    }

    private static function isMarkedReference(HtmlNode $node): bool
    {
        $classes = $node->classes();

        return in_array('footnote-ref', $classes, true) || in_array('footnoteRef', $classes, true);
    }

    private static function isTopLevel(HtmlNode $node): bool
    {
        return $node->name === 'body' || $node->name === 'html';
    }

    /**
     * C2-M-105, C2-M-108, C2-M-109: whitespace, comments, `<hr>` and `<br>` are the layout of the export.
     */
    private static function isLayout(HtmlNode $node): bool
    {
        return $node->isBlank() || $node->isComment() || $node->isElement('hr') || $node->isElement('br');
    }

    /**
     * C2-M-103: the element a fragment names, by `id` first, then by the `name` of an `<a>`.
     *
     * @return array<string, \MarkupCarve\Carve\Import\HtmlNode>
     */
    private function fragmentTargets(): array
    {
        $targets = [];
        foreach ($this->order as $node) {
            $id = $node->attr('id');
            if ($id !== null && $id !== '' && !isset($targets[$id])) {
                $targets[$id] = $node;
            }
        }
        foreach ($this->order as $node) {
            $name = $node->name === 'a' ? $node->attr('name') : null;
            if ($name !== null && $name !== '' && !isset($targets[$name])) {
                $targets[$name] = $node;
            }
        }

        return $targets;
    }

    /**
     * C2-M-103: an `<a>` that names an element and is no note reference or backlink of C2-M-062 and C2-M-063.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<string, \MarkupCarve\Carve\Import\HtmlNode> $targets
     */
    private function isAnchor(HtmlNode $node, array $targets): bool
    {
        if (
            $node->name !== 'a' || $node->attr('role') === 'doc-noteref' || $node->has('data-footnote-ref')
            || InlineCarriers::isBacklink($node)
        ) {
            return false;
        }
        $fragment = self::fragment($node);

        return $fragment !== null && isset($targets[$fragment]);
    }

    /**
     * How many named elements each element holds, itself included.
     *
     * @param array<int, true> $named
     *
     * @return array<int, int>
     */
    private function namedCounts(array $named): array
    {
        $held = [];
        for ($at = count($this->order) - 1; $at >= 0; $at--) {
            $node = $this->order[$at];
            $id = spl_object_id($node);
            $count = ($held[$id] ?? 0) + (isset($named[$id]) ? 1 : 0);
            $held[$id] = $count;
            $parent = $node->parent;
            if ($parent !== null && $parent->isElement()) {
                $pid = spl_object_id($parent);
                $held[$pid] = ($held[$pid] ?? 0) + $count;
            }
        }

        return $held;
    }

    /**
     * C2-M-103: the block of a named element, or its one-note wrapper.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $target
     * @param array<int, int> $held
     */
    private static function blockOf(HtmlNode $target, array $held): ?HtmlNode
    {
        $node = $target;
        while ($node !== null && !isset(self::BLOCKS[$node->name])) {
            $parent = $node->parent;
            $node = $parent !== null && $parent->isElement() && !self::isTopLevel($parent) ? $parent : null;
        }
        if ($node === null) {
            return null;
        }
        $parent = $node->parent;
        $wraps = $parent !== null && $parent->isElement() && isset(self::WRAPPERS[$parent->name])
            && ($parent->attr('id') ?? '') !== '' && ($held[spl_object_id($parent)] ?? 0) === 1;

        return $wraps ? $parent : $node;
    }

    /**
     * @param array<int> $list
     * @param int $value
     */
    private static function firstAtOrAfter(array $list, int $value): int
    {
        $low = 0;
        $high = count($list);
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($list[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * The positions of the `<a>` elements inside `$block` whose `href` is `$href`.
     *
     * @return array<int>
     */
    private function hrefsIn(HtmlNode $block, string $href): array
    {
        $list = $this->byHref[$href] ?? [];
        $from = self::firstAtOrAfter($list, $this->at($block));
        $to = self::firstAtOrAfter($list, $this->end[spl_object_id($block)] + 1);

        return array_slice($list, $from, $to - $from);
    }

    /**
     * C2-M-104: of two candidates inside each other's block, whether `$first` is the reference.
     *
     * @param array{ref: \MarkupCarve\Carve\Import\HtmlNode, block: \MarkupCarve\Carve\Import\HtmlNode, fragment: string} $first
     * @param array{ref: \MarkupCarve\Carve\Import\HtmlNode, block: \MarkupCarve\Carve\Import\HtmlNode, fragment: string} $second
     */
    private function wins(array $first, array $second): bool
    {
        $firstMarked = self::isMarkedReference($first['ref']);
        if ($firstMarked !== self::isMarkedReference($second['ref'])) {
            return $firstMarked;
        }
        $secondBack = in_array('footnote-back', $second['ref']->classes(), true);
        if (in_array('footnote-back', $first['ref']->classes(), true) !== $secondBack) {
            return $secondBack;
        }

        return $this->at($first['ref']) < $this->at($second['ref']);
    }

    /**
     * C2-M-104: the candidates that are references, each with its block.
     *
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $anchors
     * @param array<string, \MarkupCarve\Carve\Import\HtmlNode> $targets
     *
     * @return array<array{ref: \MarkupCarve\Carve\Import\HtmlNode, block: \MarkupCarve\Carve\Import\HtmlNode, fragment: string}>
     */
    private function references(array $anchors, array $targets): array
    {
        $named = [];
        foreach ($anchors as $anchor) {
            $named[spl_object_id($targets[(string)self::fragment($anchor)])] = true;
        }
        $held = $this->namedCounts($named);
        $candidates = [];
        foreach ($anchors as $anchor) {
            $fragment = (string)self::fragment($anchor);
            $block = self::blockOf($targets[$fragment], $held);
            if ($block === null || $this->contains($block, $anchor)) {
                continue;
            }
            $own = self::identity($anchor);
            $mutual = $own !== '' && $this->hrefsIn($block, '#' . $own) !== [];
            if ($mutual || self::isMarkedReference($anchor)) {
                $candidates[spl_object_id($anchor)] = ['ref' => $anchor, 'block' => $block, 'fragment' => $fragment];
            }
        }
        // The first candidate among the anchors of a block that name an identity, once per block and identity.
        $first = [];
        $kept = [];
        foreach ($candidates as $candidate) {
            $own = self::identity($candidate['ref']);
            $inverse = null;
            if ($own !== '') {
                $key = spl_object_id($candidate['block']) . "\0" . $own;
                if (!array_key_exists($key, $first)) {
                    $first[$key] = null;
                    foreach ($this->hrefsIn($candidate['block'], '#' . $own) as $position) {
                        $found = $candidates[spl_object_id($this->order[$position])] ?? null;
                        if ($found !== null) {
                            $first[$key] = $found;

                            break;
                        }
                    }
                }
                $inverse = $first[$key];
                if ($inverse !== null && !$this->contains($inverse['block'], $candidate['ref'])) {
                    $inverse = null;
                }
            }
            if ($inverse === null || !$this->wins($inverse, $candidate)) {
                $kept[] = $candidate;
            }
        }

        return $kept;
    }

    /**
     * C2-M-104: one note per block, in document order, without a block that contains another note.
     *
     * @param array<array{ref: \MarkupCarve\Carve\Import\HtmlNode, block: \MarkupCarve\Carve\Import\HtmlNode, fragment: string}> $kept
     *
     * @return array<\MarkupCarve\Carve\Import\AdapterNote>
     */
    private function notesOf(array $kept): array
    {
        $groups = [];
        foreach ($kept as $candidate) {
            $id = spl_object_id($candidate['block']);
            $note = $groups[$id] ??= new AdapterNote($candidate['block']);
            $note->refs[] = $candidate['ref'];
            $note->fragments[$candidate['fragment']] = true;
        }
        $sorted = array_values($groups);
        usort($sorted, fn (AdapterNote $a, AdapterNote $b): int => $this->at($a->block) <=> $this->at($b->block));
        // In document order a block that contains another note contains the next one.
        $notes = [];
        foreach ($sorted as $at => $note) {
            $next = $sorted[$at + 1] ?? null;
            if ($next === null || !$this->contains($note->block, $next->block)) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * C2-M-104: every other anchor outside the notes whose fragment is that of a reference binds to the same note.
     *
     * @param array<\MarkupCarve\Carve\Import\AdapterNote> $notes
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $anchors
     */
    private function attachRemaining(array $notes, array $anchors): void
    {
        $starts = array_map(fn (AdapterNote $note): int => $this->at($note->block), $notes);
        $byFragment = [];
        $bound = [];
        foreach ($notes as $note) {
            foreach (array_keys($note->fragments) as $fragment) {
                $byFragment[(string)$fragment] ??= $note;
            }
            foreach ($note->refs as $ref) {
                $bound[spl_object_id($ref)] = true;
            }
        }
        foreach ($anchors as $anchor) {
            $note = $byFragment[(string)self::fragment($anchor)] ?? null;
            if ($note === null || isset($bound[spl_object_id($anchor)])) {
                continue;
            }
            $slot = self::firstAtOrAfter($starts, $this->at($anchor) + 1) - 1;
            if ($slot >= 0 && $this->contains($notes[$slot]->block, $anchor)) {
                continue;
            }
            $note->refs[] = $anchor;
            $bound[spl_object_id($anchor)] = true;
        }
    }

    /**
     * The items of the first list of each footnote section: the notes C2-M-061 reads where they stand.
     *
     * @return array<int, true>
     */
    private function sectionItems(): array
    {
        $items = [];
        foreach ($this->sections as $section) {
            foreach ($section->firstElement('ol')?->elements() ?? [] as $item) {
                if ($item->name === 'li') {
                    $items[spl_object_id($item)] = true;
                }
            }
        }

        return $items;
    }

    /**
     * C2-M-107: the `href` of each note: its own `id` where it stays, else the next key no footnote section gives.
     *
     * @param array<\MarkupCarve\Carve\Import\AdapterNote> $notes
     * @param \Closure(\MarkupCarve\Carve\Import\AdapterNote): bool $stays
     *
     * @return array<string>
     */
    private function noteHrefs(array $notes, Closure $stays): array
    {
        /** @var array<int|string, true> $taken */
        $taken = [];
        foreach ($this->sections as $section) {
            foreach ($section->firstElement('ol')?->elements() ?? [] as $item) {
                $id = $item->name === 'li' ? $item->attr('id') : null;
                if ($id !== null && $id !== '') {
                    $taken[InlineCarriers::footnoteKey($id)] = true;
                }
            }
        }
        $next = 1;
        $hrefs = [];
        foreach ($notes as $note) {
            if ($stays($note)) {
                $hrefs[] = '#' . $note->block->attr('id');

                continue;
            }
            while (isset($taken[(string)$next])) {
                $next++;
            }
            $hrefs[] = '#fn' . $next++;
        }

        return $hrefs;
    }

    /**
     * C2-M-105: the reference, or the `<sup>` it is the only element of.
     */
    private static function referenceSite(HtmlNode $ref): HtmlNode
    {
        $parent = $ref->parent;
        if ($parent === null || !$parent->isElement('sup')) {
            return $ref;
        }
        foreach ($parent->children as $child) {
            if ($child !== $ref && !$child->isBlank()) {
                return $ref;
            }
        }

        return $parent;
    }

    /**
     * @param array<\MarkupCarve\Carve\Import\AdapterNote> $notes
     */
    private function rewrite(array $notes): void
    {
        $items = $this->sectionItems();
        // C2-M-107: a note that is an item of a footnote section keeps its place and its id.
        $stays = static fn (AdapterNote $note): bool => isset($items[spl_object_id($note->block)])
            && ($note->block->attr('id') ?? '') !== '';
        $hrefs = $this->noteHrefs($notes, $stays);
        // C2-M-105: every reference site is replaced before any note leaves, since a note can hold the reference to another.
        foreach ($notes as $at => $note) {
            foreach ($note->refs as $ref) {
                $text = new HtmlNode(HtmlNode::TEXT, (string)($at + 1));
                $reference = self::element('a', ['role' => 'doc-noteref', 'href' => $hrefs[$at]], [$text]);
                $this->edits->replace(self::referenceSite($ref), $reference);
            }
        }
        foreach ($notes as $note) {
            $this->dropBacklinks($note);
        }
        $moving = [];
        foreach ($notes as $at => $note) {
            if (!$stays($note)) {
                $moving[] = [$note, $hrefs[$at]];
            }
        }
        if ($moving === []) {
            $this->edits->finish();

            return;
        }
        $this->dropSeparator($moving[0][0]->block);
        $moved = [];
        $emptied = [];
        foreach ($moving as [$note, $href]) {
            $parent = $this->edits->parentOf($note->block);
            $this->edits->detach($note->block);
            if ($parent !== null) {
                $emptied[spl_object_id($parent)] = $parent;
            }
            $moved[] = self::element('li', ['id' => substr($href, 1)], $this->edits->children($note->block));
        }
        foreach ($emptied as $parent) {
            $this->pruneEmpty($parent);
        }
        $this->edits->finish();
        $this->body()->append(self::element('section', ['role' => 'doc-endnotes'], [self::element('ol', [], $moved)]));
    }

    /**
     * C2-M-106: a backlink names a reference of its note, is the element a reference's fragment names, or is marked.
     */
    private function dropBacklinks(AdapterNote $note): void
    {
        $identities = [];
        foreach ($note->refs as $ref) {
            $identity = self::identity($ref);
            if ($identity !== '') {
                $identities[$identity] = true;
            }
        }
        $backlinks = [];
        $last = $this->end[spl_object_id($note->block)];
        for ($at = $this->at($note->block); $at <= $last; $at++) {
            $node = $this->order[$at];
            if ($node->name !== 'a' || $node->parent === null) {
                continue;
            }
            $fragment = self::fragment($node);
            if (
                isset($identities[$fragment ?? '']) || ($fragment !== null && isset($note->fragments[self::identity($node)]))
                || in_array('footnote-back', $node->classes(), true) || InlineCarriers::isBacklink($node)
            ) {
                $backlinks[] = $node;
            }
        }
        foreach ($backlinks as $backlink) {
            $parent = $this->edits->parentOf($backlink);
            $this->edits->detach($backlink);
            if ($parent !== null && ($parent->isElement('sup') || $parent->isElement('span'))) {
                $blank = true;
                foreach ($this->edits->children($parent) as $child) {
                    if (!$child->isBlank()) {
                        $blank = false;

                        break;
                    }
                }
                if ($blank) {
                    $this->edits->detach($parent);
                }
            }
        }
    }

    /**
     * C2-M-108: the rule and line breaks before the first note, on its level and on each level above it.
     */
    private function dropSeparator(HtmlNode $first): void
    {
        $node = $first;
        while (true) {
            $sibling = $this->edits->before($node);
            while ($sibling !== null && self::isLayout($sibling)) {
                $previous = $this->edits->before($sibling);
                if ($sibling->isElement()) {
                    $this->edits->detach($sibling);
                }
                $sibling = $previous;
            }
            $parent = $this->edits->parentOf($node);
            if ($sibling !== null || $parent === null || !$parent->isElement() || self::isTopLevel($parent)) {
                return;
            }
            $node = $parent;
        }
    }

    /**
     * C2-M-109: an element the notes left with layout only gives nothing, nor its parent, up to the `<body>`.
     */
    private function pruneEmpty(HtmlNode $start): void
    {
        $node = $start;
        while ($node->isElement() && !self::isTopLevel($node) && !$this->edits->isGone($node) && $node->parent !== null) {
            foreach ($this->edits->children($node) as $child) {
                if (!self::isLayout($child)) {
                    return;
                }
            }
            $parent = $node->parent;
            $this->edits->detach($node);
            $node = $parent;
        }
    }

    private function body(): HtmlNode
    {
        foreach ($this->root->children as $node) {
            if ($node->isElement('html')) {
                $body = $node->firstElement('body');
                if ($body !== null) {
                    return $body;
                }
            }
        }

        return $this->root;
    }

    /**
     * @param string $name
     * @param array<string, string> $attrs
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $children
     */
    private static function element(string $name, array $attrs, array $children = []): HtmlNode
    {
        $node = new HtmlNode(HtmlNode::ELEMENT, $name, $attrs);
        foreach ($children as $child) {
            $node->append($child);
        }

        return $node;
    }
}
