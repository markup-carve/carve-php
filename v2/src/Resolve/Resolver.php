<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Resolve;

use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Diagnostics\Findings;
use MarkupCarve\Carve2\Diagnostics\Message;
use MarkupCarve\Carve2\Inline\InlineContext;
use MarkupCarve\Carve2\Inline\InlineRunner;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;
use Normalizer;

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
    private const NEEDS_END = ['inline_footnote' => true, 'figure_group' => true, 'footnote' => true];

    /**
     * @var array<string>
     */
    public const STAGES = ['referenceResolution', 'tableAlignment'];

    /**
     * @var array<string, \MarkupCarve\Carve2\Node>
     */
    public array $linkDefs = [];

    /**
     * @var array<string, array<string, \MarkupCarve\Carve2\Node>>
     */
    private array $includeLinkDefs = [];

    /**
     * @var array<string, \MarkupCarve\Carve2\Node>
     */
    public array $footnoteDefs = [];

    /**
     * @var array<string, array<string, \MarkupCarve\Carve2\Node>>
     */
    private array $includeFootnoteDefs = [];

    /**
     * @var array<string, \MarkupCarve\Carve2\Node>
     */
    private array $abbrDefs = [];

    /**
     * @var array<string, array<string, \MarkupCarve\Carve2\Node>>
     */
    private array $includeAbbrDefs = [];

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
     * C2-I-084: the id of the first heading whose folded plain text is the key.
     *
     * @var array<string, string>
     */
    private array $headingKeys = [];

    /**
     * @var array<string, string>
     */
    private array $plainKeys = [];

    /**
     * The next suffix to try per id base, so a run of equal slugs costs linear time.
     *
     * @var array<string, int>
     */
    private array $suffix = [];

    /**
     * Ids a cross-reference resolves against (C2-H-095).
     *
     * @var array<string, string>
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

    private ?string $source = null;

    /**
     * Set by resolve() when asked to keep what it found for the renderer.
     */
    public ?Facts $facts = null;

    /**
     * Types whose earlier result prepare() clears, whose nodes a later pass reads or that set a flag.
     *
     * @var array<string, true>
     */
    private const LISTED = [

        'footnote_ref' => true,
        'inline_footnote' => true,
        'heading' => true,
        'caption_number' => true,
        'math' => true,
        'figure_group' => true,
        'table' => true,
        'comment' => true,
        'image' => true,
    ];

    /**
     * C2-B-085
     *
     * @var array<string, string>
     */
    private const DUPLICATE = [
        'link_reference_definition' => 'duplicate-link-reference-definition',
        'footnote' => 'duplicate-footnote-definition',
        'abbreviation_def' => 'duplicate-abbreviation-definition',
    ];

    private function __construct(private Node $doc, private Options $o, private ?Findings $findings)
    {
    }

    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param string|null $s The source, or null for a supplied tree.
     * @param \MarkupCarve\Carve2\Options $opts
     * @param bool $keep Keep the walk's lists as Facts for HtmlRenderer::render().
     * @param \MarkupCarve\Carve2\Diagnostics\Findings|null $findings Where duplicates are reported (C2-B-085, C2-A-039).
     *
     * @return self
     */
    public static function resolve(Node $doc, ?string $s, Options $opts, bool $keep = false, ?Findings $findings = null): self
    {
        $gc = gc_enabled();
        // The tree holds no cycles; with the collector on, each walk fills its root buffer and starts full scans.
        gc_disable();
        try {
            Walk::$rendered = $opts->rendered;
            $r = new self($doc, $opts, $findings);
            $r->source = $s;
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
            $doc->f['stages'] = ($doc->f['stages'] ?? null) === ['includeExpansion']
                ? array_merge(['includeExpansion'], self::STAGES) : self::STAGES;
            if ($keep) {
                $r->facts = new Facts(
                    $doc,
                    $r->nodes,
                    $r->end,
                    $r->pos,
                    $r->noteMarks,
                    $r->headings,
                    $r->numbered,
                    $r->titled,
                    $r->taken,
                    $r->longDescriptions,
                    $r->comments,
                );
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
                $first = $this->linkDefs[$key] ??= $child;
            } elseif ($child->type === 'footnote') {
                $first = $this->footnoteDefs[$key] ??= $child;
            } elseif ($child->type === 'abbreviation_def') {
                $first = $this->abbrDefs[$key] ??= $child;
            } else {
                continue;
            }
            if ($first !== $child) {
                [$start, $end] = $this->duplicateRange($child, null);
                $this->findings?->add(
                    self::DUPLICATE[$child->type],
                    $child,
                    start: $start,
                    end: $end,
                    message: self::duplicateMessage($child, $key),
                );
            }
        }
        if ($this->doc->includeSources !== []) {
            $this->collectIncludedDefinitions();
        }
    }

    /**
     * C2-N-053, C2-N-055: the definitions of each included file, first one per key and file.
     *
     * @return void
     */
    private function collectIncludedDefinitions(): void
    {
        $stack = array_reverse($this->doc->children ?? []);
        while ($stack !== []) {
            $node = array_pop($stack);
            $file = $node->f['file'] ?? null;
            if ($node->type === 'include' && $node->children !== null && is_string($file)) {
                $this->collectIncludedDefinition($node->children, $file);
            }
            foreach (array_reverse($node->children ?? []) as $child) {
                $stack[] = $child;
            }
        }
    }

    /**
     * C2-B-085 within one expansion: each include is a parse of its own, so a file included twice repeats no definition.
     *
     * @param array<\MarkupCarve\Carve2\Node> $children
     * @param string $file
     *
     * @return void
     */
    private function collectIncludedDefinition(array $children, string $file): void
    {
        $seen = [];
        foreach ($children as $child) {
            $key = $child->f['key'] ?? null;
            if (!is_string($key) || !isset(self::DUPLICATE[$child->type])) {
                continue;
            }
            if (isset($seen[$child->type][$key])) {
                [$start, $end] = $this->duplicateRange($child, $file);
                $this->findings?->add(self::DUPLICATE[$child->type], $child, $file, $start, $end, self::duplicateMessage($child, $key));

                continue;
            }
            $seen[$child->type][$key] = true;
            match ($child->type) {
                'link_reference_definition' => $this->includeLinkDefs[$file][$key] ??= $child,
                'footnote' => $this->includeFootnoteDefs[$file][$key] ??= $child,
                default => $this->includeAbbrDefs[$file][$key] ??= $child,
            };
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
            $definition = is_string($key) ? $this->definition($node, $key, 'footnote') : null;
            if ($definition !== null) {
                $node->noteDefinition = $definition;
                $identity = 'n' . spl_object_id($definition);
                if (!isset($this->footnoteNumber[$identity])) {
                    $this->notes[] = $definition;
                    $this->footnoteNumber[$identity] = count($this->notes);
                }
                $node->f['number'] = $this->footnoteNumber[$identity];
            }
        }
    }

    /**
     * One pass over the tree, which also builds the pre-order list: every earlier result is cleared
     * (a tree may be resolved again), authored
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
            if ($type === 'footnote') {
                $this->pos[spl_object_id($node)] = $i;
            }
            $kids = $node->children;
            foreach ($f as $key => $value) {
                if (($value instanceof Node || is_array($value) && Walk::nests($value)) && !isset(Walk::OPAQUE[$key])) {
                    $kids = Walk::kids($node);

                    break;
                }
            }
            $needsEnd = isset(self::NEEDS_END[$type]) || isset($f['caption']);
            if ($needsEnd) {
                $this->end[$i] = $n;
            }
            if ($kids !== null && $kids !== []) {
                if ($needsEnd) {
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
            if ($type !== 'include' && $attrs instanceof Attrs && $attrs->id !== null) {
                $this->reserve($attrs->id, $node, $attrs->idRange);
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
     * C2-A-039: the first node in document order holds an id; each later one is reported.
     *
     * @param string $id
     * @param \MarkupCarve\Carve2\Node $node
     * @param array{0: int, 1: int}|null $range
     *
     * @return void
     */
    private function reserve(string $id, Node $node, ?array $range): void
    {
        if (isset($this->taken[$id])) {
            $this->findings?->add(
                'duplicate-id',
                $node,
                $node->file,
                $range[0] ?? -1,
                $range[1] ?? -1,
                Message::fill('duplicate-id', ['explicit' => $id]),
            );
        }
        $this->taken[$id] = true;
    }

    private static function duplicateMessage(Node $child, string $key): string
    {
        $label = is_string($child->f['label'] ?? null) ? $child->f['label'] : $key;

        return Message::fill(self::DUPLICATE[$child->type], ['label' => $label]);
    }

    /**
     * C2-B-085: a duplicate footnote ranges its marker, not the continued definition body.
     *
     * @return array{0: int, 1: int}
     */
    private function duplicateRange(Node $node, ?string $file): array
    {
        if ($node->type !== 'footnote' || $node->start < 0) {
            return [-1, -1];
        }
        $source = $file === null ? $this->source : ($this->doc->includeSources[$file] ?? null);
        if (!is_string($source)) {
            return [-1, -1];
        }
        $lineEnd = strpos($source, "\n", $node->start);
        $limit = $lineEnd === false ? strlen($source) : $lineEnd;
        for ($at = $node->start + 2; $at + 1 < $limit; $at++) {
            if ($source[$at] === '\\') {
                $at++;

                continue;
            }
            if ($source[$at] === ']' && $source[$at + 1] === ':') {
                return [$node->start, $at + 2];
            }
        }

        return [-1, -1];
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
        $numbered = $type === 'figure_group' || ($node->f['caption'] ?? null) instanceof Node
            && ($type !== 'include' || $node->children === null);
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
                $plain = self::nfc(self::fold(PlainText::of($node->children ?? [])));
                if ($plain !== '') {
                    $this->headingKeys[$plain] ??= $id;
                }
                $this->xrefIds[self::nfc($id)] ??= $id;
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
     * C2-H-151: inside a captioned block or group whose caption has a mark, an equation's number
     * belongs to that caption.
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
                        $id = $node->type === 'include' ? null : self::idOf($node);
                        if ($id !== null) {
                            $this->xrefIds[self::nfc($id)] ??= $id;
                        }
                    }
                    if ($this->end[$i] > $ownedUntil) {
                        $ownedUntil = $this->end[$i];
                    }
                }
            }
            if (
                $type === 'math' && $i >= $ownedUntil && ($node->f['display'] ?? false) === true
                && is_string($node->f['numberPrefix'] ?? null)
            ) {
                $node->f['number'] = $this->next($node->f['numberPrefix']);
            }
            if ($type === 'figure_group') {
                $this->markPanels($node, $panels);
            }
        }
    }

    /**
     * C2-H-066: a panel of a figure group takes no number.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param array<int, bool> $panels
     */
    private function markPanels(Node $node, array &$panels): void
    {
        $caption = $node->f['caption'] ?? null;
        $numbered = $caption instanceof Node && self::captionMark($caption) !== null;
        foreach ($node->children ?? [] as $child) {
            if (self::isPanel($child)) {
                $panels[spl_object_id($child)] = true;
                if ($numbered) {
                    $id = self::idOf($child);
                    if ($id !== null) {
                        $this->xrefIds[self::nfc($id)] ??= $id;
                    }
                }
            }
        }
    }

    public static function isPanel(Node $node): bool
    {
        return $node->type === 'table' || in_array($node->type, ['paragraph', 'code_block', 'block_quote', 'math'], true)
            && ($node->f['caption'] ?? null) instanceof Node;
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
                    $target = $this->xrefIds[self::nfc($key)] ?? null;
                    if ($target !== null) {
                        $node->f['href'] = '#' . $target;
                    }

                    break;
                case 'abbreviation':
                    unset($node->f['expansion']);
                    $definition = $this->definition($node, $key, 'abbr');
                    if ($definition !== null) {
                        $node->f['expansion'] = $definition->f['expansion'] ?? '';
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
        // C2-A-115, C2-A-135: the tooltip of a reference use is a result field; the renderer takes it from the definition.
        unset($node->f[$field], $node->f['tooltip']);
        $definition = $this->definition($node, $key, 'link');
        if ($definition !== null) {
            $node->f[$field] = $definition->f['href'] ?? '';

            return;
        }
        if ($node->type === 'image' || ($node->f['refForm'] ?? null) === 'shortcut') {
            return;
        }
        $plain = $this->plainKeys[$key] ??= self::nfc(self::fold(PlainText::of(
            InlineRunner::parse($key, [0, strlen($key)], new InlineContext()),
        )));
        if ($plain !== '' && isset($this->headingKeys[$plain])) {
            $node->f['href'] = '#' . $this->headingKeys[$plain];
        }
    }

    private function definition(Node $node, string $key, string $kind): ?Node
    {
        $map = match ($kind) {
            'link' => $this->includeLinkDefs,
            'abbr' => $this->includeAbbrDefs,
            default => $this->includeFootnoteDefs,
        };
        foreach ($node->includeChain as $file) {
            if (isset($map[$file][$key])) {
                return $map[$file][$key];
            }
        }

        return match ($kind) {
            'link' => $this->linkDefs[$key] ?? null,
            'abbr' => $this->abbrDefs[$key] ?? null,
            default => $this->footnoteDefs[$key] ?? null,
        };
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
        // C2-I-079: one character class run, linear; labels are short.
        return trim((string)preg_replace('/[ \t\r\n]+/', ' ', $text), ' ');
    }

    public static function nfc(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $text;
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
        [$marks, $verticalMarks] = self::headMarks($grid);
        $columns = $table->f['columns'] ?? [];
        foreach (is_array($columns) ? $columns : [] as $c => $column) {
            if ($column instanceof Node) {
                self::columnAlignment($column, $marks[$c] ?? null, $verticalMarks[$c] ?? null);
            }
        }
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function headMarks(TableGrid $grid): array
    {
        $marks = [];
        $verticalMarks = [];
        foreach ($grid->rows as [$row, $group]) {
            if ($group->type !== 'table_head') {
                continue;
            }
            foreach ($row->children ?? [] as $cell) {
                $align = $cell->f['align'] ?? null;
                if (is_string($align) && !isset($cell->f['span'])) {
                    $marks[$grid->column[spl_object_id($cell)] ?? 0] = $align;
                }
                $valign = $cell->f['valign'] ?? null;
                if (is_string($valign) && !isset($cell->f['span'])) {
                    $verticalMarks[$grid->column[spl_object_id($cell)] ?? 0] = $valign;
                }
            }
        }

        return [$marks, $verticalMarks];
    }

    private static function columnAlignment(Node $column, ?string $mark, ?string $verticalMark): void
    {
        unset($column->f['resolvedAlign'], $column->f['resolvedValign']);
        $align = $mark ?? $column->f['align'] ?? null;
        if (is_string($align) && $align !== 'none') {
            $column->f['resolvedAlign'] = $align;
        }
        $valign = $verticalMark ?? $column->f['valign'] ?? null;
        if (is_string($valign)) {
            $column->f['resolvedValign'] = $valign;
        }
    }
}
