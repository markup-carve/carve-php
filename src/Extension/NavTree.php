<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Internal: the tree edits the navigation extensions share (the carve-js `navigation/common.ts`).
 */
final class NavTree
{
    /**
     * @var array<string, true>
     */
    private const NOT_CHILDREN = [
        'pos' => true,
        'segments' => true,
        'attrs' => true,
        'data' => true,
        'metadata' => true,
        'source' => true,
        'payload' => true,
        'props' => true,
    ];

    /**
     * @var string
     */
    private const HEADING_REFERENCE = 'heading-reference';

    /**
     * The white space of JavaScript `String.prototype.trim`, as carve-js trims a copied title.
     *
     * @var string
     */
    private const JS_SPACE = '[\t\n\v\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /**
     * Visits every node below `$root` in document order, slots included, and splices in what `$rewrite` returns: null
     * keeps the node and visits its children, a list replaces it. Fallbacks are visited only with `$fallbacks`.
     *
     * @param \MarkupCarve\Carve\Node $root
     * @param \Closure(\MarkupCarve\Carve\Node, array<\MarkupCarve\Carve\Node>): (array<\MarkupCarve\Carve\Node>|null) $rewrite
     * @param bool $fallbacks
     * @param array<\MarkupCarve\Carve\Node> $ancestors
     * @param bool $visitReplacements
     *
     * @return void
     */
    public static function rewrite(
        Node $root,
        Closure $rewrite,
        bool $fallbacks,
        array $ancestors = [],
        bool $visitReplacements = true,
    ): void {
        $ancestors[] = $root;
        if ($root->children !== null) {
            $root->children = self::rewriteList($root->children, $rewrite, $fallbacks, $ancestors, $visitReplacements);
        }
        foreach ($root->f as $key => $value) {
            if (isset(self::NOT_CHILDREN[$key]) || ($key === 'fallback' && !$fallbacks)) {
                continue;
            }
            if ($value instanceof Node) {
                self::rewrite($value, $rewrite, $fallbacks, $ancestors, $visitReplacements);
            } elseif (is_array($value) && self::isList($value)) {
                $root->f[$key] = self::rewriteList(PlainText::nodes($value), $rewrite, $fallbacks, $ancestors, $visitReplacements);
            } elseif (is_array($value)) {
                foreach ($value as $slot => $list) {
                    if (is_array($list) && self::isList($list)) {
                        $value[$slot] = self::rewriteList(PlainText::nodes($list), $rewrite, $fallbacks, $ancestors, $visitReplacements);
                    }
                }
                $root->f[$key] = $value;
            }
        }
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param \Closure(\MarkupCarve\Carve\Node, array<\MarkupCarve\Carve\Node>): (array<\MarkupCarve\Carve\Node>|null) $rewrite
     * @param bool $fallbacks
     * @param array<\MarkupCarve\Carve\Node> $ancestors
     * @param bool $visitReplacements
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function rewriteList(array $list, Closure $rewrite, bool $fallbacks, array $ancestors, bool $visitReplacements): array
    {
        $out = [];
        foreach ($list as $item) {
            $replaced = $rewrite($item, $ancestors);
            if ($replaced === null) {
                self::rewrite($item, $rewrite, $fallbacks, $ancestors, $visitReplacements);
                $out[] = $item;

                continue;
            }
            foreach ($replaced as $node) {
                if ($visitReplacements) {
                    self::rewrite($node, $rewrite, $fallbacks, $ancestors, true);
                }
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        if ($value === [] || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!$item instanceof Node) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \MarkupCarve\Carve\Node $root
     * @param \Closure(\MarkupCarve\Carve\Node, array<\MarkupCarve\Carve\Node>): void $visit
     * @param bool $fallbacks
     *
     * @return void
     */
    public static function visit(Node $root, Closure $visit, bool $fallbacks): void
    {
        self::rewrite($root, static function (Node $node, array $ancestors) use ($visit): ?array {
            $visit($node, $ancestors);

            return null;
        }, $fallbacks);
    }

    /**
     * C2-X-054: a node the transform inserts or marks is declared on the view.
     *
     * @param \MarkupCarve\Carve\Node $view
     * @param string $name
     * @param array<string> $slots
     *
     * @return void
     */
    public static function declare(Node $view, string $name, array $slots = []): void
    {
        $declared = is_array($view->f['extensions'] ?? null) ? $view->f['extensions'] : [];
        foreach ($declared as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                return;
            }
        }
        $declared[] = ['name' => $name, 'version' => '1', 'required' => false, 'slots' => $slots];
        $view->f['extensions'] = $declared;
    }

    public static function attrs(Node $node): ?Attrs
    {
        $attrs = $node->f['attrs'] ?? null;

        return $attrs instanceof Attrs ? $attrs : null;
    }

    public static function headingId(Node $heading): ?string
    {
        $generated = $heading->f['generatedId'] ?? null;

        return self::attrs($heading)->id ?? (is_string($generated) ? $generated : null);
    }

    /**
     * C2-X-055 allocates generated ids again over the final view, from the edited text; a transform that edits the
     * text of a heading keeps its id by writing it as the heading's id.
     */
    public static function pinId(Node $heading): void
    {
        $generated = $heading->f['generatedId'] ?? null;
        if (self::attrs($heading)?->id !== null || !is_string($generated)) {
            return;
        }
        $attrs = self::attrs($heading);
        $attrs = $attrs === null ? new Attrs() : clone $attrs;
        $attrs->id = $generated;
        $heading->f['attrs'] = $attrs;
    }

    /**
     * Marks render chrome a transform inserted, such as a section number, under the name of its extension.
     */
    public static function chrome(Node $node, string $owner): Node
    {
        $node->f['data'] = [$owner => 'chrome'];

        return $node;
    }

    private static function isChrome(Node $node): bool
    {
        $data = $node->f['data'] ?? null;

        return is_array($data) && in_array('chrome', $data, true);
    }

    public static function text(string $value): Node
    {
        $node = new Node('text');
        $node->f['value'] = $value;

        return $node;
    }

    /**
     * The display text a transform derives from a heading: a copy of its inlines without inserted chrome, notes and ids.
     *
     * @param array<\MarkupCarve\Carve\Node> $children
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function derivedInlines(array $children): array
    {
        $copy = array_map(Extensions::copy(...), array_values(array_filter($children, static fn (Node $n): bool => !self::isChrome($n))));
        $holder = new Node('span', $copy);
        self::rewrite($holder, static function (Node $node): ?array {
            if (self::isChrome($node) || $node->type === 'footnote_ref' || $node->type === 'inline_footnote') {
                return [];
            }
            $attrs = self::attrs($node);
            if ($attrs !== null && $attrs->id !== null) {
                $rest = clone $attrs;
                $rest->id = null;
                $rest->idRange = null;
                if ($rest->isEmpty()) {
                    unset($node->f['attrs']);
                } else {
                    $node->f['attrs'] = $rest;
                }
            }

            return null;
        }, true);
        $copy = $holder->children ?? [];
        $first = $copy[0] ?? null;
        if ($first !== null && $first->type === 'text') {
            $first->f['value'] = ltrim(PlainText::str($first->f['value'] ?? ''));
        }
        $last = $copy[count($copy) - 1] ?? null;
        if ($last !== null && $last->type === 'text') {
            $last->f['value'] = rtrim(PlainText::str($last->f['value'] ?? ''));
        }

        return array_values(array_filter($copy, static fn (Node $n): bool => $n->type !== 'text' || ($n->f['value'] ?? '') !== ''));
    }

    /**
     * Sets a pair of the attributes, replacing an authored key of any case (HTML attribute names fold case).
     */
    public static function setPair(Node $node, string $name, string $value): void
    {
        $attrs = self::attrs($node);
        $attrs = $attrs === null ? new Attrs() : clone $attrs;
        $attrs->pairs = array_values(array_filter($attrs->pairs, static fn (array $p): bool => strtolower($p[0]) !== $name));
        $attrs->pairs[] = [$name, $value];
        $node->f['attrs'] = $attrs;
    }

    public static function pairOf(Node $node, string $name): ?string
    {
        foreach (self::attrs($node)->pairs ?? [] as [$key, $value]) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }

        return null;
    }

    public static function removePair(Node $node, string $name): void
    {
        $attrs = self::attrs($node);
        if ($attrs === null) {
            return;
        }
        $kept = array_values(array_filter($attrs->pairs, static fn (array $p): bool => strtolower($p[0]) !== $name));
        if (count($kept) === count($attrs->pairs)) {
            return;
        }
        $copy = clone $attrs;
        $copy->pairs = $kept;
        if ($copy->id === null && $copy->classes === [] && $copy->pairs === []) {
            unset($node->f['attrs']);
        } else {
            $node->f['attrs'] = $copy;
        }
    }

    private static function referenceKey(string $key): string
    {
        return strtolower((string)preg_replace('/\s+/u', ' ', trim($key)));
    }

    /**
     * The links that are references a heading resolved (C2-H-083), before or after `freezeReferences()`.
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function headingReferences(Node $view): array
    {
        $definitions = [];
        $links = [];
        self::visit($view, static function (Node $node) use (&$definitions, &$links): void {
            if ($node->type === 'link_reference_definition') {
                $definitions[self::referenceKey(PlainText::str($node->f['key'] ?? ''))] = true;
            }
            $href = $node->f['href'] ?? null;
            $data = $node->f['data'] ?? null;
            if (
                $node->type === 'link' && is_string($href) && str_starts_with($href, '#')
                && (isset($node->f['key']) || is_array($data) && in_array(self::HEADING_REFERENCE, $data, true))
            ) {
                $links[] = $node;
            }
        }, true);

        return array_values(array_filter(
            $links,
            static fn (Node $link): bool => !isset($link->f['key'])
                || !isset($definitions[self::referenceKey(PlainText::str($link->f['key']))]),
        ));
    }

    /**
     * The render resolves a reference against the heading text again, so a transform that edits that text first turns
     * each reference a heading resolved into a direct link to the same id.
     */
    public static function freezeReferences(Node $view, string $owner): void
    {
        foreach (self::headingReferences($view) as $link) {
            if (!isset($link->f['key'])) {
                continue;
            }
            unset($link->f['key'], $link->f['namespace'], $link->f['refForm']);
            $data = is_array($link->f['data'] ?? null) ? $link->f['data'] : [];
            $data[$owner] = self::HEADING_REFERENCE;
            $link->f['data'] = $data;
        }
    }

    /**
     * Indents each line of `$html` by `$depth` levels of two spaces, except the content of a `<pre>`.
     */
    public static function indent(string $html, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        $pre = false;
        $out = [];
        foreach (explode("\n", $html) as $line) {
            $out[] = $pre || $line === '' ? $line : $pad . $line;
            if (preg_match('/<pre[\s>]/u', $line) === 1) {
                $pre = true;
            }
            if (str_contains($line, '</pre>')) {
                $pre = false;
            }
        }

        return implode("\n", $out);
    }

    /**
     * The display text of each heading id, read before a transform edits the headings.
     *
     * @return array<string, array<\MarkupCarve\Carve\Node>>
     */
    public static function headingTexts(Node $view): array
    {
        $texts = [];
        self::visit($view, static function (Node $node) use (&$texts): void {
            if ($node->type !== 'heading') {
                return;
            }
            $id = self::headingId($node);
            if ($id !== null && !isset($texts[$id])) {
                $texts[$id] = self::derivedInlines($node->children ?? []);
            }
        }, false);

        return $texts;
    }

    /**
     * A cross-reference writes the text of its heading (C2-I-096), which the render reads from the edited heading;
     * each one to a heading in `$texts` becomes a direct link with the text from before the edit.
     *
     * @param \MarkupCarve\Carve\Node $view
     * @param array<string, array<\MarkupCarve\Carve\Node>> $texts
     *
     * @return void
     */
    public static function freezeCrossReferences(Node $view, array $texts): void
    {
        self::rewrite($view, static function (Node $node) use ($texts): ?array {
            if (!self::isTitleXref($node)) {
                return null;
            }
            $key = PlainText::str($node->f['key'] ?? '');
            if (!isset($texts[$key])) {
                return null;
            }

            return [self::link('#' . $key, self::copiedTitle($texts[$key], $texts), self::attrs($node))];
        }, true, visitReplacements: false);
    }

    /**
     * carve#6540: a copy of a heading title, in which a cross-reference stays a link to its target with the target's
     * plain title. It copies no title again, so the copies stay linear in the input and a title that cites itself ends.
     *
     * @param array<\MarkupCarve\Carve\Node> $title
     * @param array<string, array<\MarkupCarve\Carve\Node>> $texts
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function copiedTitle(array $title, array $texts): array
    {
        $holder = new Node('span', array_map(Extensions::copy(...), $title));
        self::rewrite($holder, static function (Node $node) use ($texts): ?array {
            if (!self::isTitleXref($node)) {
                return null;
            }
            $key = PlainText::str($node->f['key'] ?? '');
            if (!isset($texts[$key])) {
                return null;
            }
            $text = preg_replace('/^' . self::JS_SPACE . '+|' . self::JS_SPACE . '+$/u', '', PlainText::of($texts[$key])) ?? '';

            return [self::link('#' . $key, [self::text($text)], self::attrs($node))];
        }, true, visitReplacements: false);

        return $holder->children ?? [];
    }

    /**
     * A heading cross-reference that writes the title of its target (no other display mode).
     */
    public static function isTitleXref(Node $node): bool
    {
        $display = $node->f['display'] ?? null;

        return $node->type === 'xref' && ($node->f['namespace'] ?? null) === 'heading' && ($display === null || $display === 'title');
    }

    /**
     * @param string $href
     * @param array<\MarkupCarve\Carve\Node> $children
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function link(string $href, array $children, ?Attrs $attrs = null): Node
    {
        $link = new Node('link', $children);
        $link->f['href'] = $href;
        if ($attrs !== null) {
            $link->f['attrs'] = $attrs;
        }

        return $link;
    }
}
