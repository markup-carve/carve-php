<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;
use MarkupCarve\Carve2\Resolve\Facts;
use MarkupCarve\Carve2\Resolve\PlainText;
use MarkupCarve\Carve2\Resolve\Resolver;
use MarkupCarve\Carve2\Resolve\Walk;

/**
 * What the renderer needs to know before it writes a byte (C2-H-102 to C2-H-110), read from a
 * resolved tree: the notes in number order, the ids of note marks, the admonition ids, the texts of
 * cross-reference targets and the link definitions.
 */
final class RenderState
{
    public int $derivedRemaining;

    public bool $budgetReported = false;

    /**
     * Node types whose subtree end a later pass reads.
     *
     * @var array<string, true>
     */
    private const NEEDS_END = ['inline_footnote' => true];

    /**
     * @var array<int, \MarkupCarve\Carve2\Node>
     */
    public array $notes = [];

    /**
     * Note mark id per reference node (spl_object_id).
     *
     * @var array<int, string>
     */
    public array $refIds = [];

    /**
     * @var array<int, string>
     */
    public array $noteIds = [];

    /**
     * @var array<int, array<string>>
     */
    public array $backlinks = [];

    /**
     * @var array<string, \MarkupCarve\Carve2\Node>
     */
    public array $linkDefs = [];

    /**
     * @var array<string, \MarkupCarve\Carve2\Node>
     */
    public array $footnoteDefs = [];

    /**
     * Generated text of a cross-reference target by id (C2-H-096), a heading until xrefText() asks for it.
     *
     * @var array<string, string|\MarkupCarve\Carve2\Node>
     */
    private array $xrefText = [];

    /**
     * @var array<string, string|null>
     */
    private array $xrefHtmlText = [];

    /**
     * Generated title ids of admonitions and of the placing footnotes directive (C2-H-156).
     *
     * @var array<int, string>
     */
    public array $admonitionIds = [];

    public ?Node $placingDirective = null;

    /**
     * Whether any image carries a long description, so paragraphs need a search for them.
     */
    public bool $longDescriptions = false;

    /**
     * Whether the tree holds a comment, so inline lists need the C2-H-069 line check.
     */
    public bool $comments = false;

    /**
     * @var array<int, \MarkupCarve\Carve2\Resolve\TableGrid>
     */
    public array $tableGrids = [];

    /**
     * @var array<string, true>
     */
    private array $taken = [];

    public function isIdTaken(string $id): bool
    {
        return isset($this->taken[$id]);
    }

    /**
     * @var array<string, int>
     */
    private array $suffix = [];

    private int $admonitions = 0;

    /**
     * The tree in pre-order without plain text, and the indexes of note marks and titled nodes in it.
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
     * @var array<int, int>
     */
    private array $marks = [];

    /**
     * @var array<int, int>
     */
    private array $titled = [];

    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param \MarkupCarve\Carve2\Options $o
     * @param \MarkupCarve\Carve2\Html\Profile|null $profile
     * @param \MarkupCarve\Carve2\Resolve\Facts|null $facts The lists of the resolve of this tree, in place of a walk.
     */
    public function __construct(private Node $doc, private Options $o, private ?Profile $profile = null, ?Facts $facts = null)
    {
        $length = $doc->f['srcByteLength'] ?? 0;
        $length = is_int($length) ? max(0, $length) : 0;
        $jsonLength = $doc->layout['jsonByteLength'] ?? null;
        if (is_int($jsonLength)) {
            $length = min($length, $jsonLength);
        }
        $this->derivedRemaining = max(1000000, min($length, intdiv(PHP_INT_MAX, 8)) * 8);
        $this->definitions();
        if ($facts !== null && $facts->doc === $doc) {
            $this->fromFacts($facts);
        } else {
            $this->scan();
        }
        ksort($this->notes);
        if ($this->notes === []) {
            // C2-H-158: with no section to place, the directive is a plain `<div class="footnotes">`.
            $this->placingDirective = null;
        }
        $body = Walk::bodyRanges($doc, $this->pos, $this->end);
        $this->allAdmonitionIds($body);
        $this->allRefs($body);
        $this->nodes = [];
        $this->end = [];
        $this->pos = [];
        $this->marks = [];
        $this->titled = [];
    }

    /**
     * What scan() collects, from the lists of the resolve: the notes, then the cross-reference texts of
     * headings and numbered captions in document order.
     *
     * @param \MarkupCarve\Carve2\Resolve\Facts $facts
     *
     * @return void
     */
    private function fromFacts(Facts $facts): void
    {
        $this->nodes = $facts->nodes;
        $this->end = $facts->end;
        $this->pos = $facts->pos;
        $this->marks = $facts->noteMarks;
        $this->titled = $facts->titled;
        $this->taken = $facts->taken;
        $this->longDescriptions = $facts->longDescriptions;
        $this->comments = $facts->comments;
        $this->tableGrids = $facts->tableGrids;
        foreach ($this->marks as $i) {
            $this->noteOf($this->nodes[$i]);
        }
        $headings = $facts->headings;
        $numbered = $facts->numbered;
        $h = 0;
        $c = 0;
        $hn = count($headings);
        $cn = count($numbered);
        while ($h < $hn || $c < $cn) {
            if ($c >= $cn || ($h < $hn && $headings[$h] < $numbered[$c])) {
                $this->headingTarget($this->nodes[$headings[$h++]]);
            } else {
                $node = $this->nodes[$numbered[$c++]];
                if (isset($node->f['attrs']) || $node->type === 'figure_group') {
                    $this->authoredTarget($node);
                }
            }
        }
    }

    /**
     * The first link definition, footnote definition and placing directive of each key (C2-B-085).
     *
     * @return void
     */
    private function definitions(): void
    {
        foreach ($this->doc->children ?? [] as $child) {
            $key = $child->f['key'] ?? null;
            if ($child->type === 'link_reference_definition' && is_string($key)) {
                $this->linkDefs[$key] ??= $child;
            } elseif ($child->type === 'footnote' && is_string($key)) {
                $this->footnoteDefs[$key] ??= $child;
            } elseif ($child->type === 'directive' && ($child->f['kind'] ?? null) === 'footnotes') {
                $this->placingDirective ??= $child;
            }
        }
    }

    /**
     * The body first, then the notes in number order (C2-H-088).
     *
     * @param array<array{0: int, 1: int}> $body
     *
     * @return void
     */
    private function allRefs(array $body): void
    {
        foreach ($body as [$from, $to]) {
            $this->refs($from, $to);
        }
        foreach ($this->notes as $note) {
            $at = $this->pos[spl_object_id($note)] ?? null;
            if ($at !== null) {
                $this->refs($at + 1, $this->end[$at]);
            }
        }
    }

    /**
     * The body first, then the footnote bodies in number order (C2-H-156).
     *
     * @param array<array{0: int, 1: int}> $body
     *
     * @return void
     */
    private function allAdmonitionIds(array $body): void
    {
        foreach ($body as [$from, $to]) {
            $this->admonitionIds($from, $to);
        }
        foreach ($this->notes as $note) {
            $at = $note->type === 'footnote' ? $this->pos[spl_object_id($note)] ?? null : null;
            if ($at !== null) {
                $this->admonitionIds($at, $this->end[$at]);
            }
        }
    }

    /**
     * The notes by number, the taken ids and the cross-reference texts, in document order.
     *
     * @return void
     */
    private function scan(): void
    {
        $this->nodes[0] = $this->doc;
        $n = 1;
        foreach (Walk::kids($this->doc) as $kid) {
            $at = $n;
            $this->pos[spl_object_id($kid)] ??= $at;
            $n = $this->scanFrom($kid, $n);
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
    private function scanFrom(Node $start, int $n): int
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
                if (($value instanceof Node || is_array($value) && Walk::nests($value)) && !isset(Walk::OPAQUE[$key])) {
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
            // A node with no fields (a paragraph, a delimiter span) has nothing to record.
            if ($f !== []) {
                $this->visit($node, $i, $f);
            }
        }

        return $n;
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     * @param int $i
     * @param array<string, mixed> $f
     *
     * @return void
     */
    private function visit(Node $node, int $i, array $f): void
    {
        $type = $node->type;
        if ($type === 'footnote_ref' || $type === 'inline_footnote') {
            $this->noteMark($node, $i);
        } elseif ($type === 'heading') {
            $this->headingTarget($node);
        } elseif ($type === 'image' && is_array($f['longDescription'] ?? null)) {
            $this->longDescriptions = true;
        } elseif ($type === 'comment') {
            $this->comments = true;
        }
        if (isset($f['title']) && is_array($f['title'])) {
            $this->titled[] = $i;
        }
        if (isset($f['attrs'])) {
            $this->authoredTarget($node);
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node A footnote reference or an inline footnote.
     * @param int $i
     *
     * @return void
     */
    private function noteMark(Node $node, int $i): void
    {
        $this->marks[] = $i;
        if ($node->type === 'inline_footnote') {
            $this->pos[spl_object_id($node)] = $i;
        }
        $this->noteOf($node);
    }

    /**
     * The note a footnote reference or an inline footnote numbers, unless the profile drops it.
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function noteOf(Node $node): void
    {
        $inline = $node->type === 'inline_footnote';
        $number = $node->f['number'] ?? null;
        if (!is_int($number) || ($this->profile !== null && !$this->profile->allowsInline($node))) {
            return;
        }
        $note = $inline ? $node : ($node->noteDefinition ?? $this->footnoteDefs[PlainText::str($node->f['key'] ?? '')] ?? null);
        if ($note !== null) {
            $this->notes[$number] ??= $note;
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $heading
     *
     * @return void
     */
    private function headingTarget(Node $heading): void
    {
        $id = Resolver::idOf($heading);
        if ($id !== null) {
            $this->taken[$id] = true;
            $this->xrefText[Resolver::nfc($id)] ??= $heading;
        }
    }

    /**
     * @param string $id
     *
     * @return string|null
     */
    public function xrefText(string $id): ?string
    {
        $id = Resolver::nfc($id);
        $text = $this->xrefText[$id] ?? null;
        if ($text instanceof Node) {
            $text = $this->xrefText[$id] = PlainText::of($text->children ?? []);
        }

        return $text;
    }

    public function xrefHtmlText(string $id): ?string
    {
        $id = Resolver::nfc($id);
        if (!array_key_exists($id, $this->xrefHtmlText)) {
            $text = $this->xrefText($id);
            $this->xrefHtmlText[$id] = $text === null ? null : Escape::text($text);
        }

        return $this->xrefHtmlText[$id];
    }

    /**
     * An authored id is taken; with a numbered caption it is a cross-reference target (C2-H-096).
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function authoredTarget(Node $node): void
    {
        $f = $node->f;
        $attrs = $f['attrs'] ?? null;
        $id = $attrs instanceof Attrs ? $attrs->id : null;
        if ($id !== null) {
            $this->taken[$id] = true;
        }
        $caption = $f['caption'] ?? null;
        $mark = $caption instanceof Node ? Resolver::captionMark($caption) : null;
        if ($mark !== null && is_int($mark[0]->f['n'] ?? null)) {
            $label = Resolver::labelKey($mark[1], $this->o->symbols);
            $text = ltrim($label . ' ' . $mark[0]->f['n']);
            if ($id !== null) {
                $this->xrefText[Resolver::nfc($id)] ??= $text;
            }
            if ($node->type === 'figure_group') {
                $this->panelTargets($node, $text);
            }
        }
    }

    private function panelTargets(Node $group, string $text): void
    {
        $index = 0;
        foreach ($group->children ?? [] as $child) {
            if (!Resolver::isPanel($child)) {
                continue;
            }
            $index++;
            $id = Resolver::idOf($child);
            if ($id !== null) {
                $this->xrefText[Resolver::nfc($id)] ??= $text . self::panelLetter($index);
            }
        }
    }

    private static function panelLetter(int $index): string
    {
        $out = '';
        while ($index > 0) {
            $index--;
            $out = chr(97 + $index % 26) . $out;
            $index = intdiv($index, 26);
        }

        return $out;
    }

    /**
     * C2-H-202: each new note takes its id before its mark; every generated mark shares the document's taken ids.
     *
     * @param int $from
     * @param int $to
     *
     * @return void
     */
    private function refs(int $from, int $to): void
    {
        $marks = $this->marks;
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
            $number = $node->f['number'] ?? null;
            if (!is_int($number) || !isset($this->notes[$number]) || ($this->profile !== null && !$this->profile->allowsInline($node))) {
                continue;
            }
            $this->noteIds[$number] ??= $this->unique('fn' . $number);
            $k = count($this->backlinks[$number] ?? []) + 1;
            $attrs = $node->f['attrs'] ?? null;
            $id = $attrs instanceof Attrs && $attrs->id !== null
                ? $attrs->id : $this->unique('fnref' . $number . ($k > 1 ? '-' . $k : ''));
            $this->refIds[spl_object_id($node)] = $id;
            $this->backlinks[$number][] = $id;
            if ($node->type === 'inline_footnote') {
                $from = $this->end[$i];
            }
        }
    }

    private function unique(string $base): string
    {
        $id = $base;
        $next = $this->suffix[$base] ?? 2;
        while (isset($this->taken[$id])) {
            $id = $base . '-' . $next++;
        }
        $this->suffix[$base] = $next;
        $this->taken[$id] = true;

        return $id;
    }

    /**
     * C2-H-155, C2-H-156.
     *
     * @param int $at
     * @param int $to
     *
     * @return void
     */
    private function admonitionIds(int $at, int $to): void
    {
        $list = $this->titled;
        $count = count($list);
        for ($t = Walk::firstAtOrAfter($list, $at); $t < $count && $list[$t] < $to; $t++) {
            $node = $this->nodes[$list[$t]];
            if (!($node->type === 'admonition' && !self::namedByAuthor($node) || $node === $this->placingDirective)) {
                continue;
            }
            $this->admonitions++;
            $base = 'adm-' . $this->admonitions;
            $id = $base;
            for ($n = 2; isset($this->taken[$id]); $n++) {
                $id = $base . '-' . $n;
            }
            $this->taken[$id] = true;
            $this->admonitionIds[spl_object_id($node)] = $id;
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return bool
     */
    public static function namedByAuthor(Node $node): bool
    {
        $attrs = $node->f['attrs'] ?? null;

        return $attrs instanceof Attrs && ($attrs->get('aria-label') !== null || $attrs->get('aria-labelledby') !== null);
    }
}
