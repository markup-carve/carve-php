<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Resolve;

use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;

/**
 * The resolve stage (C2-A-133): footnote numbers, heading ids, caption numbers, reference
 * destinations, abbreviation expansions and table results. Every result is recomputed, so a tree
 * may be resolved again.
 */
final class Resolver
{
    /**
     * Node types whose subtree end a later pass reads.
     *
     * @var array<string, true>
     */
    private const NEEDS_END = ['inline_footnote' => true, 'figure' => true, 'figure_group' => true];

    /**
     * @var array<string>
     */
    public const STAGES = ['referenceResolution', 'tableAlignment'];

    /**
     * @var array<string, \MarkupCarve\Carve2\Node>
     */
    public array $linkDefs = [];

    /**
     * @var array<string, \MarkupCarve\Carve2\Node>
     */
    public array $footnoteDefs = [];

    /**
     * @var array<string, \MarkupCarve\Carve2\Node>
     */
    private array $abbrDefs = [];

    /**
     * Notes in number order: a footnote definition or an inline footnote.
     *
     * @var array<\MarkupCarve\Carve2\Node>
     */
    public array $notes = [];

    /**
     * @var array<string, int>
     */
    private array $footnoteNumber = [];

    /**
     * Every id that is taken: authored ids, then generated heading ids.
     *
     * @var array<string, true>
     */
    public array $taken = [];

    /**
     * C2-I-084: the id of the first heading whose folded source or folded plain text is the key.
     *
     * @var array<string, string>
     */
    private array $headingKeys = [];

    /**
     * The next suffix to try per id base, so a run of equal slugs costs linear time.
     *
     * @var array<string, int>
     */
    private array $suffix = [];

    /**
     * Ids a cross-reference resolves against (C2-H-095).
     *
     * @var array<string, true>
     */
    private array $xrefIds = [];

    /**
     * @var array<string, int>
     */
    private array $sequence = [];

    /**
     * The tree in pre-order without plain text, walked by index ranges in place of recursion.
     *
     * @var array<int, \MarkupCarve\Carve2\Node>
     */
    private array $nodes = [];

    /**
     * @var array<int, int>
     */
    private array $end = [];

    /**
     * @var array<int, int>
     */
    private array $pos = [];

    /**
     * Pre-order indexes of the nodes each pass reads, filled by prepare().
     *
     * @var array<int, int>
     */
    private array $noteMarks = [];

    /**
     * @var array<int, int>
     */
    private array $headings = [];

    /**
     * @var array<int, int>
     */
    private array $numbered = [];

    /**
     * @var array<int, int>
     */
    private array $keyed = [];

    /**
     * @var array<array{0: int, 1: int}>
     */
    private array $body = [];

    /**
     * @var array<int, int>
     */
    private array $titled = [];

    private bool $longDescriptions = false;

    private bool $comments = false;

    /**
     * Set by resolve() when asked to keep what it found for the renderer.
     */
    public ?Facts $facts = null;

    /**
     * Types whose earlier result prepare() clears, whose nodes a later pass reads or that set a flag.
     *
     * @var array<string, true>
     */
    private const LISTED = ['footnote_ref' => true, 'inline_footnote' => true, 'heading' => true, 'caption_number' => true, 'math' => true, 'figure_group' => true, 'table' => true, 'comment' => true, 'image' => true];

    private function __construct(private Node $doc, private string $s, private Options $o)
    {
    }

    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param string|null $s The source, or null for a supplied tree.
     * @param \MarkupCarve\Carve2\Options $opts
     * @param bool $keep Keep the walk's lists as Facts for HtmlRenderer::render().
     *
     * @return self
     */
    public static function resolve(Node $doc, ?string $s, Options $opts, bool $keep = false): self
    {
        $gc = gc_enabled();
        // The tree holds no cycles; with the collector on, each walk fills its root buffer and starts full scans.
        gc_disable();
        try {
            $r = new self($doc, $s ?? '', $opts);
            $doc->f['astVersion'] = '2.0';
            if ($s !== null) {
                $doc->f['srcByteLength'] = strlen($s);
            }
            $r->collectDefinitions();
            $r->prepare();
            $r->body = Walk::bodyRanges($doc, $r->pos, $r->end);
            $r->numberFootnotes();
            $r->headingIds();
            $r->captions();
            $r->references();
            $doc->f['stages'] = self::STAGES;
            if ($keep) {
                $r->facts = new Facts($doc, $r->nodes, $r->end, $r->pos, $r->noteMarks, $r->headings, $r->numbered, $r->titled, $r->taken, $r->longDescriptions, $r->comments);
            }
            $r->nodes = [];
            $r->end = [];
            $r->pos = [];
            $r->noteMarks = [];
            $r->headings = [];
            $r->numbered = [];
            $r->keyed = [];
            $r->titled = [];
        } finally {
            if ($gc) {
                gc_enable();
            }
        }

        return $r;
    }

    /**
     * C2-B-085: the first definition of a key wins.
     *
     * @return void
     */
    private function collectDefinitions(): void
    {
        foreach ($this->doc->children ?? [] as $child) {
            $key = $child->f['key'] ?? null;
            if (!is_string($key)) {
                continue;
            }
            if ($child->type === 'link_reference_definition') {
                $this->linkDefs[$key] ??= $child;
            } elseif ($child->type === 'footnote') {
                $this->footnoteDefs[$key] ??= $child;
            } elseif ($child->type === 'abbreviation_def') {
                $this->abbrDefs[$key] ??= $child;
            }
        }
    }

    /**
     * C2-H-087: references of the body first, in document order, then those inside the notes, note by
     * note in number order. An unused definition is never visited.
     *
     * @return void
     */
    private function numberFootnotes(): void
    {
        foreach ($this->body as [$from, $to]) {
            $this->noteRefs($from, $to);
        }
        // The queue grows while it is read: a note can reference a later note.
        $i = 0;
        while (isset($this->notes[$i])) {
            $at = $this->pos[spl_object_id($this->notes[$i])] ?? null;
            if ($at !== null) {
                $this->noteRefs($at + 1, $this->end[$at]);
            }
            $i++;
        }
    }

    /**
     * The references in a range; an inline footnote's content waits for its turn as a note.
     *
     * @param int $from
     * @param int $to
     *
     * @return void
     */
    private function noteRefs(int $from, int $to): void
    {
        $marks = $this->noteMarks;
        $count = count($marks);
        for ($m = Walk::firstAtOrAfter($marks, $from); $m < $count; $m++) {
            $i = $marks[$m];
            if ($i >= $to) {
                break;
            }
            if ($i < $from) {
                continue;
            }
            $node = $this->nodes[$i];
            if ($node->type === 'inline_footnote') {
                $this->notes[] = $node;
                $node->f['number'] = count($this->notes);
                $from = $this->end[$i];

                continue;
            }
            $key = $node->f['key'] ?? null;
            if (is_string($key) && isset($this->footnoteDefs[$key])) {
                if (!isset($this->footnoteNumber[$key])) {
                    $this->notes[] = $this->footnoteDefs[$key];
                    $this->footnoteNumber[$key] = count($this->notes);
                }
                $node->f['number'] = $this->footnoteNumber[$key];
            }
        }
    }

    /**
     * One pass over the tree, which also builds the pre-order list: every earlier result is cleared (a tree may be resolved again), authored
     * ids are reserved first in document order (C2-H-024), and the nodes each later pass reads are listed.
     *
     * @return void
     */
    private function prepare(): void
    {
        $this->nodes[0] = $this->doc;
        $n = 1;
        foreach (Walk::kids($this->doc) as $kid) {
            $at = $n;
            $this->pos[spl_object_id($kid)] ??= $at;
            $n = $this->prepareFrom($kid, $n);
            $this->end[$at] = $n;
        }
        $this->end[0] = $n;
    }

    /**
     * The walk of Walk::order from one block of the document, inlined so the tree is touched once. Only
     * the nodes in NEEDS_END record where their subtree ends.
     *
     * @param \MarkupCarve\Carve2\Node $start
     * @param int $n The index of $start.
     *
     * @return int The index after the subtree.
     */
    private function prepareFrom(Node $start, int $n): int
    {
        $stack = [$start];
        $top = 1;
        while ($top > 0) {
            $node = $stack[--$top];
            if (is_int($node)) {
                $this->end[$node] = $n;

                continue;
            }
            $this->nodes[$n] = $node;
            $i = $n++;
            $f = $node->f;
            $type = $node->type;
            $kids = $node->children;
            foreach ($f as $key => $value) {
                if ((is_array($value) || $value instanceof Node) && !isset(Walk::OPAQUE[$key])) {
                    $kids = Walk::kids($node);

                    break;
                }
            }
            if (isset(self::NEEDS_END[$type])) {
                $this->end[$i] = $n;
            }
            if ($kids !== null && $kids !== []) {
                if (isset(self::NEEDS_END[$type])) {
                    $stack[$top++] = $i;
                }
                for ($k = count($kids); $k > 0;) {
                    $kid = $kids[--$k];
                    // Plain text holds no slot and no result and is never listed; most nodes are text.
                    if ($kid->type !== 'text' || isset($kid->f['attrs'])) {
                        $stack[$top++] = $kid;
                    }
                }
            }
            $attrs = $f['attrs'] ?? null;
            if ($attrs instanceof Attrs && $attrs->id !== null) {
                $this->taken[$attrs->id] = true;
            }
            if (isset(self::LISTED[$type]) || isset($f['caption']) || isset($f['key'])) {
                $this->listNode($node, $i);
            }
            if (isset($f['title']) && is_array($f['title'])) {
                $this->titled[] = $i;
            }
        }

        return $n;
    }

    /**
     * Clears the earlier result of a node and lists it for the pass that computes it.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param int $i
     *
     * @return void
     */
    private function listNode(Node $node, int $i): void
    {
        $type = $node->type;
        $numbered = $type === 'figure_group' || ($node->f['caption'] ?? null) instanceof Node;
        switch ($type) {
            case 'footnote_ref':
            case 'inline_footnote':
                unset($node->f['number']);
                $this->noteMarks[] = $i;
                if ($type === 'inline_footnote') {
                    $this->pos[spl_object_id($node)] = $i;
                }

                break;
            case 'heading':
                unset($node->f['generatedId']);
                $this->headings[] = $i;

                break;
            case 'caption_number':
                unset($node->f['n']);

                break;
            case 'comment':
                $this->comments = true;

                break;
            case 'image':
                $this->longDescriptions = $this->longDescriptions || is_array($node->f['longDescription'] ?? null);

                break;
            case 'math':
                if (isset($node->f['numberPrefix'])) {
                    unset($node->f['number']);
                    $numbered = true;
                }

                break;
        }
        if ($numbered) {
            $this->numbered[] = $i;
        }
        if ($type === 'table' || is_string($node->f['key'] ?? null)) {
            $this->keyed[] = $i;
        }
    }

    /**
     * C2-H-022 to C2-H-025: generated ids in document order, those of footnote bodies after the body in
     * note order; a heading in an unused definition takes none.
     *
     * @return void
     */
    private function headingIds(): void
    {
        foreach ($this->body as [$from, $to]) {
            $this->headingIdsIn($from, $to);
        }
        foreach ($this->notes as $note) {
            if ($note->type === 'footnote') {
                $at = $this->pos[spl_object_id($note)];
                $this->headingIdsIn($at, $this->end[$at]);
            }
        }
        $this->collectHeadings();
    }

    /**
     * @param int $at
     * @param int $to
     *
     * @return void
     */
    private function headingIdsIn(int $at, int $to): void
    {
        if ($this->o->headingIds === 'off') {
            return;
        }
        $list = $this->headings;
        $count = count($list);
        for ($h = Walk::firstAtOrAfter($list, $at); $h < $count && $list[$h] < $to; $h++) {
            $node = $this->nodes[$list[$h]];
            $attrs = $node->f['attrs'] ?? null;
            if (!($attrs instanceof Attrs && $attrs->id !== null)) {
                $node->f['generatedId'] = $this->unique(Slug::of(PlainText::of($node->children ?? []), $this->o->headingIds));
            }
        }
    }

    /**
     * C2-H-024: a taken id gets `-2`, then `-3`, until it is free.
     *
     * @param string $id
     *
     * @return string
     */
    public function unique(string $id): string
    {
        $out = $id;
        $n = $this->suffix[$id] ?? 2;
        while (isset($this->taken[$out])) {
            $out = $id . '-' . $n;
            $n++;
        }
        $this->suffix[$id] = $n;
        $this->taken[$out] = true;

        return $out;
    }

    /**
     * @return void
     */
    private function collectHeadings(): void
    {
        foreach ($this->headings as $i) {
            $node = $this->nodes[$i];
            $id = self::idOf($node);
            if ($id !== null) {
                foreach ($this->headingTexts($node) as $text) {
                    $this->headingKeys[$text] ??= $id;
                }
                $this->xrefIds[$id] = true;
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return string|null The authored id, else the generated id of a heading.
     */
    public static function idOf(Node $node): ?string
    {
        $attrs = $node->f['attrs'] ?? null;
        if ($attrs instanceof Attrs && $attrs->id !== null) {
            return $attrs->id;
        }
        $gen = $node->f['generatedId'] ?? null;

        return is_string($gen) ? $gen : null;
    }

    /**
     * C2-H-149, C2-H-150, C2-H-170: caption numbers per sequence key, in document order, a node before
     * the nodes inside it; footnote bodies after the body in note order.
     *
     * @return void
     */
    private function captions(): void
    {
        foreach ($this->body as [$from, $to]) {
            $this->captionsIn($from, $to);
        }
        foreach ($this->notes as $note) {
            if ($note->type === 'footnote') {
                $at = $this->pos[spl_object_id($note)];
                $this->captionsIn($at, $this->end[$at]);
            }
        }
    }

    /**
     * C2-H-066: a panel of a figure group takes no number. C2-H-151: inside a figure or figure group
     * whose caption has a mark, an equation's number belongs to that caption.
     *
     * @param int $at
     * @param int $to
     *
     * @return void
     */
    private function captionsIn(int $at, int $to): void
    {
        $panels = [];
        $ownedUntil = $at;
        $list = $this->numbered;
        $count = count($list);
        for ($c = Walk::firstAtOrAfter($list, $at); $c < $count && $list[$c] < $to; $c++) {
            $i = $list[$c];
            $node = $this->nodes[$i];
            $type = $node->type;
            $caption = $node->f['caption'] ?? null;
            if ($caption instanceof Node) {
                $mark = self::captionMark($caption);
                if ($mark !== null) {
                    if (!isset($panels[spl_object_id($node)])) {
                        $mark[0]->f['n'] = $this->next(self::labelKey($mark[1], $this->o->symbols));
                        $id = self::idOf($node);
                        if ($id !== null) {
                            $this->xrefIds[$id] = true;
                        }
                    }
                    if (($type === 'figure' || $type === 'figure_group') && $this->end[$i] > $ownedUntil) {
                        $ownedUntil = $this->end[$i];
                    }
                }
            }
            if ($type === 'math' && $i >= $ownedUntil && ($node->f['display'] ?? false) === true && is_string($node->f['numberPrefix'] ?? null)) {
                $node->f['number'] = $this->next($node->f['numberPrefix']);
            }
            if ($type === 'figure_group') {
                foreach ($node->children ?? [] as $child) {
                    if ($child->type === 'figure' || $child->type === 'table') {
                        $panels[spl_object_id($child)] = true;
                    }
                }
            }
        }
    }

    /**
     * @param string $key
     *
     * @return int
     */
    private function next(string $key): int
    {
        $this->sequence[$key] = ($this->sequence[$key] ?? 0) + 1;

        return $this->sequence[$key];
    }

    /**
     * The caption number mark and the label before it (C2-B-207): the first `caption_number` in the
     * top-level inline content of the caption.
     *
     * @param \MarkupCarve\Carve2\Node $caption
     *
     * @return array{0: \MarkupCarve\Carve2\Node, 1: array<\MarkupCarve\Carve2\Node>}|null
     */
    public static function captionMark(Node $caption): ?array
    {
        foreach ($caption->children ?? [] as $block) {
            $before = [];
            foreach ($block->children ?? [] as $inline) {
                if ($inline->type === 'caption_number') {
                    return [$inline, $before];
                }
                $before[] = $inline;
            }
        }

        return null;
    }

    /**
     * C2-B-208: the plain text of the label without trailing whitespace.
     *
     * @param array<\MarkupCarve\Carve2\Node> $label
     * @param array<string, string> $symbols
     *
     * @return string
     */
    public static function labelKey(array $label, array $symbols): string
    {
        return rtrim(PlainText::of($label, $symbols), " \t\n\r\u{00A0}");
    }

    /**
     * Link, image and cross-reference destinations, abbreviation expansions, table results.
     *
     * @return void
     */
    private function references(): void
    {
        foreach ($this->keyed as $i) {
            $node = $this->nodes[$i];
            $type = $node->type;
            if ($type === 'table') {
                $this->table($node);

                continue;
            }
            $key = $node->f['key'] ?? null;
            if (!is_string($key)) {
                continue;
            }
            switch ($type) {
                case 'link':
                case 'image':
                    if (($node->f['namespace'] ?? null) === 'link') {
                        $this->linkReference($node, $key);
                    }

                    break;
                case 'xref':
                    unset($node->f['href']);
                    if (isset($this->xrefIds[$key])) {
                        $node->f['href'] = '#' . $key;
                    }

                    break;
                case 'abbreviation':
                    unset($node->f['expansion']);
                    if (isset($this->abbrDefs[$key])) {
                        $node->f['expansion'] = $this->abbrDefs[$key]->f['expansion'] ?? '';
                    }

                    break;
            }
        }
    }

    /**
     * C2-I-081 to C2-I-084: link definitions first, then the headings for a link.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param string $key
     *
     * @return void
     */
    private function linkReference(Node $node, string $key): void
    {
        $field = $node->type === 'image' ? 'src' : 'href';
        unset($node->f[$field]);
        if (isset($this->linkDefs[$key])) {
            $node->f[$field] = $this->linkDefs[$key]->f['href'] ?? '';

            return;
        }
        if ($node->type === 'image' || ($node->f['refForm'] ?? null) === 'shortcut') {
            return;
        }
        if (isset($this->headingKeys[$key])) {
            $node->f['href'] = '#' . $this->headingKeys[$key];
        }
    }

    /**
     * C2-I-084: the folded plain text of a heading and the folded source of its text.
     *
     * @param \MarkupCarve\Carve2\Node $heading
     *
     * @return array<string>
     */
    private function headingTexts(Node $heading): array
    {
        $kids = $heading->children ?? [];
        $out = [self::fold(PlainText::of($kids))];
        if ($kids === [] || $this->s === '') {
            return $out;
        }
        $start = $kids[0]->start;
        $end = $kids[count($kids) - 1]->end;
        if ($start >= 0 && $end >= $start && $end <= strlen($this->s)) {
            $out[] = self::fold(substr($this->s, $start, $end - $start));
        }

        return $out;
    }

    /**
     * C2-I-079: runs of spaces, tabs and line breaks become one space; the ends are trimmed.
     *
     * @param string $text
     *
     * @return string
     */
    public static function fold(string $text): string
    {
        return trim((string)preg_replace('/[ \t\r\n]+/', ' ', $text), ' ');
    }

    /**
     * C2-A-089, C2-B-213: `resolvedAlign` of each column and the span counts of the marks.
     *
     * @param \MarkupCarve\Carve2\Node $table
     *
     * @return void
     */
    private function table(Node $table): void
    {
        $grid = TableGrid::of($table);
        $grid->deriveSpans();
        $marks = [];
        foreach ($grid->rows as [$row, $group]) {
            if ($group->type !== 'table_head') {
                continue;
            }
            foreach ($row->children ?? [] as $cell) {
                $align = $cell->f['align'] ?? null;
                if (is_string($align) && !isset($cell->f['span'])) {
                    $marks[$grid->column[spl_object_id($cell)] ?? 0] = $align;
                }
            }
        }
        $columns = $table->f['columns'] ?? [];
        foreach (is_array($columns) ? $columns : [] as $c => $column) {
            if (!$column instanceof Node) {
                continue;
            }
            unset($column->f['resolvedAlign']);
            $align = $marks[$c] ?? $column->f['align'] ?? null;
            if (is_string($align)) {
                $column->f['resolvedAlign'] = $align;
            }
        }
    }
}
