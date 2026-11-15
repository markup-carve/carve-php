<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;
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
     * Generated text of a cross-reference target by id (C2-H-096).
     *
     * @var array<string, string>
     */
    public array $xrefText = [];

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
     * @var array<string, true>
     */
    private array $taken = [];

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
     */
    public function __construct(private Node $doc, private Options $o, private ?Profile $profile = null)
    {
        $this->definitions();
        $this->scan();
        $this->pos += Walk::kidPositions($doc, $this->end);
        ksort($this->notes);
        if ($this->notes === []) {
            // C2-H-158: with no section to place, the directive is a plain `<div class="footnotes">`.
            $this->placingDirective = null;
        }
        $body = Walk::bodyRanges($doc, $this->pos, $this->end);
        $this->allRefs($body);
        $this->allAdmonitionIds($body);
        $this->nodes = [];
        $this->end = [];
        $this->pos = [];
        $this->marks = [];
        $this->titled = [];
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
        // The walk of Walk::order, inlined so the tree is touched once.
        $stack = [$this->doc];
        $top = 1;
        $n = 0;
        while ($top > 0) {
            $node = $stack[--$top];
            if (is_int($node)) {
                $this->end[$node] = $n;

                continue;
            }
            $this->nodes[$n] = $node;
            $this->end[$n] = $n + 1;
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
            if ($kids !== null && $kids !== []) {
                $stack[$top++] = $i;
                for ($k = count($kids); $k > 0;) {
                    $kid = $kids[--$k];
                    // Plain text holds no slot and no result and is never listed; most nodes are text.
                    if ($kid->type !== 'text' || isset($kid->f['attrs'])) {
                        $stack[$top++] = $kid;
                    }
                }
            }
            if ($type === 'footnote_ref' || $type === 'inline_footnote') {
                $this->noteMark($node, $i);
            } elseif ($type === 'heading') {
                $this->headingTarget($node);
            } elseif ($type === 'image' && is_array($f['longDescription'] ?? null)) {
                $this->longDescriptions = true;
            }
            if (isset($f['title']) && is_array($f['title'])) {
                $this->titled[] = $i;
            }
            if (isset($f['attrs'])) {
                $this->authoredTarget($f);
            }
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
        $inline = $node->type === 'inline_footnote';
        if ($inline) {
            $this->pos[spl_object_id($node)] = $i;
        }
        $number = $node->f['number'] ?? null;
        if (!is_int($number) || ($this->profile !== null && !$this->profile->allowsInline($node))) {
            return;
        }
        $note = $inline ? $node : $this->footnoteDefs[PlainText::str($node->f['key'] ?? '')] ?? null;
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
            $this->xrefText[$id] ??= PlainText::of($heading->children ?? []);
        }
    }

    /**
     * An authored id is taken; with a numbered caption it is a cross-reference target (C2-H-096).
     *
     * @param array<string, mixed> $f
     *
     * @return void
     */
    private function authoredTarget(array $f): void
    {
        $attrs = $f['attrs'];
        $id = $attrs instanceof Attrs ? $attrs->id : null;
        if ($id === null) {
            return;
        }
        $this->taken[$id] = true;
        $caption = $f['caption'] ?? null;
        $mark = $caption instanceof Node ? Resolver::captionMark($caption) : null;
        if ($mark !== null && is_int($mark[0]->f['n'] ?? null)) {
            $label = Resolver::labelKey($mark[1], $this->o->symbols);
            $this->xrefText[$id] ??= ltrim($label . ' ' . $mark[0]->f['n']);
        }
    }

    /**
     * C2-H-088: the second and later references to a note take `-2`, `-3`; an authored id replaces it.
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
            $k = count($this->backlinks[$number] ?? []) + 1;
            $attrs = $node->f['attrs'] ?? null;
            $id = $attrs instanceof Attrs && $attrs->id !== null ? $attrs->id : 'fnref' . $number . ($k > 1 ? '-' . $k : '');
            $this->refIds[spl_object_id($node)] = $id;
            $this->backlinks[$number][] = $id;
            if ($node->type === 'inline_footnote') {
                $from = $this->end[$i];
            }
        }
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
