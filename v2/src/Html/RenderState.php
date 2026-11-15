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
        $this->scan($doc);
        ksort($this->notes);
        foreach ($doc->children ?? [] as $child) {
            if ($child->type !== 'footnote') {
                $this->refs($child);
            }
        }
        foreach ($this->notes as $note) {
            foreach (Walk::kids($note) as $kid) {
                $this->refs($kid);
            }
        }
        foreach ($doc->children ?? [] as $child) {
            if ($child->type !== 'footnote') {
                $this->admonitionIds($child);
            }
        }
        foreach ($this->notes as $note) {
            if ($note->type === 'footnote') {
                $this->admonitionIds($note);
            }
        }
    }

    /**
     * The notes by number, the taken ids and the cross-reference texts, in document order.
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function scan(Node $node): void
    {
        $number = $node->f['number'] ?? null;
        if (is_int($number) && ($this->profile === null || $this->profile->allowsInline($node))) {
            if ($node->type === 'inline_footnote') {
                $this->notes[$number] ??= $node;
            } elseif ($node->type === 'footnote_ref') {
                $def = $this->footnoteDefs[PlainText::str($node->f['key'] ?? '')] ?? null;
                if ($def !== null) {
                    $this->notes[$number] ??= $def;
                }
            }
        }
        $attrs = $node->f['attrs'] ?? null;
        if ($attrs instanceof Attrs && $attrs->id !== null) {
            $this->taken[$attrs->id] = true;
        }
        if ($node->type === 'heading') {
            $id = Resolver::idOf($node);
            if ($id !== null) {
                $this->taken[$id] = true;
                $this->xrefText[$id] ??= PlainText::of($node->children ?? []);
            }
        }
        $caption = $node->f['caption'] ?? null;
        if ($caption instanceof Node && $attrs instanceof Attrs && $attrs->id !== null) {
            $mark = Resolver::captionMark($caption);
            if ($mark !== null && is_int($mark[0]->f['n'] ?? null)) {
                $label = Resolver::labelKey($mark[1], $this->o->symbols);
                $this->xrefText[$attrs->id] ??= ltrim($label . ' ' . $mark[0]->f['n']);
            }
        }
        foreach (Walk::kids($node) as $kid) {
            $this->scan($kid);
        }
    }

    /**
     * C2-H-088: the second and later references to a note take `-2`, `-3`; an authored id replaces it.
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function refs(Node $node): void
    {
        $number = $node->f['number'] ?? null;
        if (is_int($number) && isset($this->notes[$number]) && ($node->type === 'footnote_ref' || $node->type === 'inline_footnote') && ($this->profile === null || $this->profile->allowsInline($node))) {
            $k = count($this->backlinks[$number] ?? []) + 1;
            $attrs = $node->f['attrs'] ?? null;
            $id = $attrs instanceof Attrs && $attrs->id !== null ? $attrs->id : 'fnref' . $number . ($k > 1 ? '-' . $k : '');
            $this->refIds[spl_object_id($node)] = $id;
            $this->backlinks[$number][] = $id;
            if ($node->type === 'inline_footnote') {
                return;
            }
        }
        foreach (Walk::kids($node) as $kid) {
            $this->refs($kid);
        }
    }

    /**
     * C2-H-155, C2-H-156.
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function admonitionIds(Node $node): void
    {
        $titled = isset($node->f['title']) && is_array($node->f['title']);
        if ($titled && ($node->type === 'admonition' && !self::namedByAuthor($node) || $node === $this->placingDirective)) {
            $this->admonitions++;
            $base = 'adm-' . $this->admonitions;
            $id = $base;
            for ($n = 2; isset($this->taken[$id]); $n++) {
                $id = $base . '-' . $n;
            }
            $this->taken[$id] = true;
            $this->admonitionIds[spl_object_id($node)] = $id;
        }
        foreach (Walk::kids($node) as $kid) {
            $this->admonitionIds($kid);
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
