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
     * @var array<string, true>
     */
    private array $taken = [];

    private int $admonitions = 0;

    /**
     * The tree in pre-order (Walk::order) and the indexes of note marks and titled nodes in it.
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
        foreach ($doc->children ?? [] as $child) {
            $key = $child->f['key'] ?? null;
            if ($child->type === 'link_reference_definition' && is_string($key)) {
                $this->linkDefs[$key] ??= $child;
            } elseif ($child->type === 'footnote' && is_string($key)) {
                $this->footnoteDefs[$key] ??= $child;
            } elseif ($child->type === 'directive' && ($child->f['kind'] ?? null) === 'footnotes') {
                $this->placingDirective ??= $child;
            }
        }
        [$this->nodes, $this->end, $this->pos] = Walk::order($doc);
        $this->scan();
        ksort($this->notes);
        if ($this->notes === []) {
            // C2-H-158: with no section to place, the directive is a plain `<div class="footnotes">`.
            $this->placingDirective = null;
        }
        foreach ($doc->children ?? [] as $child) {
            if ($child->type !== 'footnote') {
                $at = $this->pos[spl_object_id($child)];
                $this->refs($at, $this->end[$at]);
            }
        }
        foreach ($this->notes as $note) {
            $at = $this->pos[spl_object_id($note)] ?? null;
            if ($at !== null) {
                $this->refs($at + 1, $this->end[$at]);
            }
        }
        foreach ($doc->children ?? [] as $child) {
            if ($child->type !== 'footnote') {
                $this->admonitionIds($this->pos[spl_object_id($child)]);
            }
        }
        foreach ($this->notes as $note) {
            if ($note->type === 'footnote' && isset($this->pos[spl_object_id($note)])) {
                $this->admonitionIds($this->pos[spl_object_id($note)]);
            }
        }
        $this->nodes = [];
        $this->end = [];
        $this->pos = [];
        $this->marks = [];
        $this->titled = [];
    }

    /**
     * The notes by number, the taken ids and the cross-reference texts, in document order.
     *
     * @return void
     */
    private function scan(): void
    {
        foreach ($this->nodes as $i => $node) {
            $f = $node->f;
            $type = $node->type;
            if ($type === 'footnote_ref' || $type === 'inline_footnote') {
                $this->marks[] = $i;
                $number = $f['number'] ?? null;
                if (is_int($number) && ($this->profile === null || $this->profile->allowsInline($node))) {
                    if ($type === 'inline_footnote') {
                        $this->notes[$number] ??= $node;
                    } else {
                        $def = $this->footnoteDefs[PlainText::str($f['key'] ?? '')] ?? null;
                        if ($def !== null) {
                            $this->notes[$number] ??= $def;
                        }
                    }
                }
            }
            if (isset($f['title']) && is_array($f['title'])) {
                $this->titled[] = $i;
            }
            $attrs = $f['attrs'] ?? null;
            $id = $attrs instanceof Attrs ? $attrs->id : null;
            if ($id !== null) {
                $this->taken[$id] = true;
            }
            if ($type === 'heading') {
                $hid = Resolver::idOf($node);
                if ($hid !== null) {
                    $this->taken[$hid] = true;
                    $this->xrefText[$hid] ??= PlainText::of($node->children ?? []);
                }
            }
            $caption = $f['caption'] ?? null;
            if ($id !== null && $caption instanceof Node) {
                $mark = Resolver::captionMark($caption);
                if ($mark !== null && is_int($mark[0]->f['n'] ?? null)) {
                    $label = Resolver::labelKey($mark[1], $this->o->symbols);
                    $this->xrefText[$id] ??= ltrim($label . ' ' . $mark[0]->f['n']);
                }
            }
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
     *
     * @return void
     */
    private function admonitionIds(int $at): void
    {
        $list = $this->titled;
        $count = count($list);
        $to = $this->end[$at];
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
