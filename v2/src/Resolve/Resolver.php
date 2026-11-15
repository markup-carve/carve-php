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

    private function __construct(private Node $doc, private string $s, private Options $o)
    {
    }

    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param string|null $s The source, or null for a supplied tree.
     * @param \MarkupCarve\Carve2\Options $opts
     *
     * @return self
     */
    public static function resolve(Node $doc, ?string $s, Options $opts): self
    {
        $r = new self($doc, $s ?? '', $opts);
        $doc->f['astVersion'] = '2.0';
        if ($s !== null) {
            $doc->f['srcByteLength'] = strlen($s);
        }
        $r->collectDefinitions();
        $r->numberFootnotes();
        $r->collectAuthoredIds($doc);
        $r->headingIds();
        $r->captions();
        $r->references($doc);
        $doc->f['stages'] = self::STAGES;

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
        $this->clearNumbers($this->doc);
        foreach ($this->doc->children ?? [] as $child) {
            if ($child->type !== 'footnote') {
                $this->noteRefs($child);
            }
        }
        // The queue grows while it is read: a note can reference a later note.
        $i = 0;
        while (isset($this->notes[$i])) {
            foreach (Walk::kids($this->notes[$i]) as $kid) {
                $this->noteRefs($kid);
            }
            $i++;
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function clearNumbers(Node $node): void
    {
        if ($node->type === 'footnote_ref' || $node->type === 'inline_footnote') {
            unset($node->f['number']);
        }
        foreach (Walk::kids($node) as $kid) {
            $this->clearNumbers($kid);
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function noteRefs(Node $node): void
    {
        if ($node->type === 'footnote_ref') {
            $key = $node->f['key'] ?? null;
            if (is_string($key) && isset($this->footnoteDefs[$key])) {
                if (!isset($this->footnoteNumber[$key])) {
                    $this->notes[] = $this->footnoteDefs[$key];
                    $this->footnoteNumber[$key] = count($this->notes);
                }
                $node->f['number'] = $this->footnoteNumber[$key];
            }

            return;
        }
        if ($node->type === 'inline_footnote') {
            $this->notes[] = $node;
            $node->f['number'] = count($this->notes);

            return;
        }
        foreach (Walk::kids($node) as $kid) {
            $this->noteRefs($kid);
        }
    }

    /**
     * C2-H-024: authored ids are reserved first, in document order.
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function collectAuthoredIds(Node $node): void
    {
        $attrs = $node->f['attrs'] ?? null;
        if ($attrs instanceof Attrs && $attrs->id !== null) {
            $this->taken[$attrs->id] = true;
        }
        foreach (Walk::kids($node) as $kid) {
            $this->collectAuthoredIds($kid);
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
        foreach ($this->doc->children ?? [] as $child) {
            if ($child->type !== 'footnote') {
                $this->headingIdsIn($child);
            } else {
                $this->clearHeadingIds($child);
            }
        }
        foreach ($this->notes as $note) {
            if ($note->type === 'footnote') {
                $this->headingIdsIn($note);
            }
        }
        $this->collectHeadings($this->doc);
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function clearHeadingIds(Node $node): void
    {
        if ($node->type === 'heading') {
            unset($node->f['generatedId']);
        }
        foreach (Walk::kids($node) as $kid) {
            $this->clearHeadingIds($kid);
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function headingIdsIn(Node $node): void
    {
        if ($node->type === 'heading') {
            unset($node->f['generatedId']);
            $attrs = $node->f['attrs'] ?? null;
            if ($this->o->headingIds !== 'off' && !($attrs instanceof Attrs && $attrs->id !== null)) {
                $node->f['generatedId'] = $this->unique(Slug::of(PlainText::of($node->children ?? []), $this->o->headingIds));
            }
        }
        foreach (Walk::kids($node) as $kid) {
            $this->headingIdsIn($kid);
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
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function collectHeadings(Node $node): void
    {
        if ($node->type === 'heading') {
            $id = self::idOf($node);
            if ($id !== null) {
                foreach ($this->headingTexts($node) as $text) {
                    $this->headingKeys[$text] ??= $id;
                }
                $this->xrefIds[$id] = true;
            }
        }
        foreach (Walk::kids($node) as $kid) {
            $this->collectHeadings($kid);
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
        $this->clearCaptionNumbers($this->doc);
        foreach ($this->doc->children ?? [] as $child) {
            if ($child->type !== 'footnote') {
                $this->captionsIn($child, false, false);
            }
        }
        foreach ($this->notes as $note) {
            if ($note->type === 'footnote') {
                $this->captionsIn($note, false, false);
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function clearCaptionNumbers(Node $node): void
    {
        if ($node->type === 'caption_number') {
            unset($node->f['n']);
        } elseif ($node->type === 'math' && isset($node->f['numberPrefix'])) {
            unset($node->f['number']);
        }
        foreach (Walk::kids($node) as $kid) {
            $this->clearCaptionNumbers($kid);
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     * @param bool $panel The node is a panel of a figure group (C2-H-066).
     * @param bool $owned A caption with a mark around it owns the number of an equation (C2-H-151).
     *
     * @return void
     */
    private function captionsIn(Node $node, bool $panel, bool $owned): void
    {
        $caption = $node->f['caption'] ?? null;
        if ($caption instanceof Node) {
            $mark = self::captionMark($caption);
            if ($mark !== null) {
                if (!$panel) {
                    $mark[0]->f['n'] = $this->next(self::labelKey($mark[1], $this->o->symbols));
                    $id = self::idOf($node);
                    if ($id !== null) {
                        $this->xrefIds[$id] = true;
                    }
                }
                if ($node->type === 'figure' || $node->type === 'figure_group') {
                    $owned = true;
                }
            }
        }
        if ($node->type === 'math' && !$owned && ($node->f['display'] ?? false) === true && is_string($node->f['numberPrefix'] ?? null)) {
            $node->f['number'] = $this->next($node->f['numberPrefix']);
        }
        $panels = [];
        if ($node->type === 'figure_group') {
            foreach ($node->children ?? [] as $child) {
                $panels[spl_object_id($child)] = $child->type === 'figure' || $child->type === 'table';
            }
        }
        foreach (Walk::kids($node) as $kid) {
            $this->captionsIn($kid, $panels[spl_object_id($kid)] ?? false, $owned);
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
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function references(Node $node): void
    {
        $f = $node->f;
        $key = $f['key'] ?? null;
        if (is_string($key)) {
            switch ($node->type) {
                case 'link':
                case 'image':
                    if (($f['namespace'] ?? null) === 'link') {
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
        if ($node->type === 'table') {
            $this->table($node);
        }
        foreach (Walk::kids($node) as $kid) {
            $this->references($kid);
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
