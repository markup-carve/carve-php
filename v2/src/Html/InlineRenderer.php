<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Generated\Tables;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;
use MarkupCarve\Carve2\Resolve\PlainText;

/**
 * Inline nodes to HTML (C2-H-072 to C2-H-101).
 */
final class InlineRenderer
{
    private int $linkDepth = 0;

    private int $abbrSpan = 0;

    private string $moved = '';

    public ?Profile $profile = null;

    public function __construct(private Options $o, private RenderState $state, private AttrWriter $aw, private RenderLog $log)
    {
    }

    /**
     * The content of a leaf: C2-H-069 drops one line break beside each comment-only line,
     * judged in each child list on its own (the oracle's reading; G-render-16).
     *
     * @param array<\MarkupCarve\Carve2\Node> $nodes
     * @param int $depth Nesting level for the profile (C2-H-166).
     *
     * @return string
     */
    public function leaf(array $nodes, int $depth = 0): string
    {
        return $this->inlines($nodes, $depth);
    }

    /**
     * @param array<\MarkupCarve\Carve2\Node> $nodes
     * @param int $depth
     *
     * @return string
     */
    public function inlines(array $nodes, int $depth = 0): string
    {
        $out = '';
        if ($this->profile !== null) {
            foreach (self::dropCommentLineBreaks($nodes) as $node) {
                $out .= $this->profile->inline($node, $depth, $this);
            }

            return $out;
        }
        foreach (self::dropCommentLineBreaks($nodes) as $node) {
            if ($node->type === 'text') {
                $value = $node->f['value'] ?? '';
                // Plain text needs no escaping: the check of Escape::text, done here to save two calls.
                $out .= is_string($value) && strcspn($value, Escape::TEXT_SPECIAL) === strlen($value) ? $value : Escape::text(PlainText::str($value));
            } else {
                $out .= $this->node($node, $depth);
            }
        }

        return $out;
    }

    /**
     * @param array<\MarkupCarve\Carve2\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    public static function dropCommentLineBreaks(array $nodes): array
    {
        $hasComment = false;
        foreach ($nodes as $n) {
            if ($n->type === 'comment') {
                $hasComment = true;

                break;
            }
        }
        if (!$hasComment) {
            return $nodes;
        }
        $drop = [];
        $count = count($nodes);
        $lineStart = 0;
        for ($i = 0; $i <= $count; $i++) {
            $isBreak = $i < $count && ($nodes[$i]->type === 'soft_break' || $nodes[$i]->type === 'hard_break');
            if ($i < $count && !$isBreak) {
                continue;
            }
            if (self::commentOnly($nodes, $lineStart, $i)) {
                if ($i < $count) {
                    $drop[$i] = true;
                } elseif ($lineStart > 0) {
                    $drop[$lineStart - 1] = true;
                }
            }
            $lineStart = $i + 1;
        }
        $out = [];
        foreach ($nodes as $i => $n) {
            if (!isset($drop[$i])) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @param array<\MarkupCarve\Carve2\Node> $nodes
     * @param int $from
     * @param int $to
     *
     * @return bool
     */
    private static function commentOnly(array $nodes, int $from, int $to): bool
    {
        $comment = false;
        for ($i = $from; $i < $to; $i++) {
            $n = $nodes[$i];
            if ($n->type === 'comment') {
                $comment = true;
            } elseif ($n->type !== 'text' || trim(PlainText::str($n->f['value'] ?? ''), " \t") !== '') {
                return false;
            }
        }

        return $comment;
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     * @param int $depth
     *
     * @return string
     */
    public function node(Node $node, int $depth = 0): string
    {
        $f = $node->f;
        if ($node->type === 'text') {
            return Escape::text(PlainText::str($f['value'] ?? ''));
        }
        $attrs = $f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? $attrs : null;
        $lang = is_string($f['lang'] ?? null) ? $f['lang'] : null;
        $kids = $node->children ?? [];
        switch ($node->type) {
            case 'text':
                return Escape::text(PlainText::str($f['value'] ?? ''));
            case 'soft_break':
                return "\n";
            case 'hard_break':
                return "<br>\n";
            case 'non_breaking_space':
                return '&nbsp;';
            case 'comment':
            case 'citation':
                return '';
            case 'smart_punctuation':
                return Escape::text(PlainText::str($this->o->smartTypography ? ($f['glyph'] ?? $f['source'] ?? '') : ($f['source'] ?? '')));
            case 'code':
                return '<code' . $this->aw->build([], null, $attrs, $lang) . '>' . Escape::text(PlainText::str($f['value'] ?? '')) . '</code>';
            case 'literal_inline':
                $text = Escape::text(PlainText::str($f['value'] ?? ''));

                return $attrs === null && $lang === null ? $text : '<span' . $this->aw->build([], null, $attrs, $lang) . '>' . $text . '</span>';
            case 'math':
                return $this->math($node, $attrs, $lang);
            case 'raw_inline':
                return $this->rawInline($node);
            case 'span':
                return $this->span($node, $attrs, $lang, $depth);
            case 'critic_comment':
                return '<span' . $this->aw->build([], null, $attrs, $lang, ['critic-comment']) . '>' . Escape::text(PlainText::str($f['value'] ?? '')) . '</span>';
            case 'link':
                return $this->link($node, $attrs, $lang, $depth);
            case 'autolink':
                return $this->autolink($node, $attrs, $lang);
            case 'image':
                return $this->image($node, $attrs, $lang);
            case 'footnote_ref':
            case 'inline_footnote':
                return $this->noteMark($node, $depth);
            case 'xref':
                return $this->xref($node, $attrs, $lang);
            case 'abbreviation':
                return $this->abbreviation($node, $attrs, $lang);
            case 'mention':
            case 'tag':
                return $this->social($node, $attrs, $lang);
            case 'symbol':
                return $this->symbol($node, $attrs, $lang);
            case 'caption_number':
                return $this->captionNumber($node);
            case 'inline_extension':
                return $this->extension($node, $attrs, $lang, $depth);
            case 'citation_group':
                return Escape::text(PlainText::str($f['raw'] ?? ''));
            case 'small_caps':
                return '<span' . $this->aw->build([], null, $attrs, $lang, ['smallcaps']) . '>' . $this->inlines($kids, $depth + 1) . '</span>';
            case 'ruby':
                return $this->ruby($node, $attrs, $lang, $depth);
        }
        $tag = Tables::DELIMITER_HTML[$node->type] ?? null;
        if ($tag !== null) {
            return '<' . $tag . $this->aw->build([], null, $attrs, $lang) . '>' . $this->inlines($kids, $depth + 1) . '</' . $tag . '>';
        }

        return $this->inlines($kids, $depth + 1);
    }

    /**
     * C2-H-072, C2-H-168.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     *
     * @return string
     */
    private function math(Node $node, ?Attrs $attrs, ?string $lang): string
    {
        $value = PlainText::str($node->f['value'] ?? '');
        $display = ($node->f['display'] ?? false) === true;
        if ($this->o->mode === 'static') {
            return '<code' . $this->aw->build([], null, $attrs, $lang, ['language-math']) . '>' . Escape::text($value) . '</code>';
        }
        $out = '<span' . $this->aw->build([], null, $attrs, $lang, ['math', $display ? 'display' : 'inline'], [], [['role', 'math']]) . '>'
            . ($display ? '\\[' : '\\(') . Escape::text($value) . ($display ? '\\]' : '\\)') . '</span>';
        // C2-H-151: a numbered equation of a supplied tree.
        $number = $node->f['number'] ?? null;
        $prefix = $node->f['numberPrefix'] ?? null;
        if ($display && is_int($number) && is_string($prefix)) {
            $out .= ' <span class="equation-number">' . Escape::text($prefix . ' ' . $number) . '</span>';
        }

        return $out;
    }

    /**
     * C2-H-051, C2-H-053, C2-H-054.
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return string
     */
    private function rawInline(Node $node): string
    {
        $value = PlainText::str($node->f['value'] ?? '');
        $format = PlainText::str($node->f['format'] ?? '');
        if ($format !== 'html') {
            $this->log->loss('raw-format-dropped', 'warning', 'raw ' . $format . ' dropped');

            return '';
        }
        if ($this->o->rawHtml) {
            return $value;
        }
        $this->log->escapedRaw = true;
        if ($this->o->roundtrip) {
            return '<span data-carve-raw="' . Escape::attr($format) . '">' . Escape::text($value) . '</span>';
        }

        return Escape::text($value);
    }

    /**
     * C2-H-075, C2-H-099, C2-H-175: `abbr`, `time` and `kbd` keys nest from inside out.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth
     *
     * @return string
     */
    private function span(Node $node, ?Attrs $attrs, ?string $lang, int $depth): string
    {
        $layers = [];
        $rest = $attrs;
        if ($attrs !== null) {
            $rest = clone $attrs;
            foreach (['abbr' => 'title', 'time' => 'datetime', 'kbd' => null] as $key => $target) {
                $value = $rest->get($key);
                if ($value !== null) {
                    $rest->remove($key);
                    $layers[] = [$key, $target, $value];
                }
            }
        }
        $isAbbr = false;
        foreach ($layers as [$key]) {
            $isAbbr = $isAbbr || $key === 'abbr';
        }
        $this->abbrSpan += $isAbbr ? 1 : 0;
        $inner = $this->inlines($node->children ?? [], $depth + 1);
        $this->abbrSpan -= $isAbbr ? 1 : 0;
        if ($layers === []) {
            return '<span' . $this->aw->build([], null, $attrs, $lang) . '>' . $inner . '</span>';
        }
        $last = count($layers) - 1;
        foreach ($layers as $i => [$key, $target, $value]) {
            $structural = [];
            if ($target !== null && $value !== '') {
                $derived = $this->aw->filterValue($target, $value);
                $structural = $i === $last && $rest->get($target) !== null ? [] : [[$target, $derived]];
            }
            $attr = $i === $last ? $this->aw->build($structural, null, $rest, $lang) : AttrWriter::join($structural);
            $inner = '<' . $key . $attr . '>' . $inner . '</' . $key . '>';
        }

        return $inner;
    }

    /**
     * C2-H-079, C2-H-081, C2-H-085, C2-H-088, C2-H-141.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth
     *
     * @return string
     */
    private function link(Node $node, ?Attrs $attrs, ?string $lang, int $depth): string
    {
        $key = $node->f['key'] ?? null;
        $href = $node->f['href'] ?? null;
        if (is_string($key) && !is_string($href)) {
            return $this->unresolved($node, $attrs, $lang, $depth);
        }
        // G-render-4: a heading outside the profile is no target, as the oracle does.
        if (is_string($key) && $this->profile !== null && !isset($this->state->linkDefs[$key]) && !$this->profile->allowsBlockType('heading')) {
            return $this->unresolved($node, $attrs, $lang, $depth);
        }
        if ($this->linkDepth > 0) {
            return $this->inlines($node->children ?? [], $depth + 1);
        }
        $def = is_string($key) ? $this->state->linkDefs[$key] ?? null : null;
        $tooltip = $node->f['tooltip'] ?? $def?->f['tooltip'] ?? null;
        $merged = self::mergeDefinition($attrs, $def);
        $structural = [['href', $this->destination(PlainText::str($href))]];
        if (is_string($tooltip)) {
            $structural[] = ['title', $tooltip];
        }
        $rel = $this->rel($merged);
        if ($rel !== null) {
            $structural[] = ['rel', $rel];
        }
        if ($this->o->roundtrip && is_string($key)) {
            $structural = array_merge($structural, self::refCarriers($node, $key));
        }
        $this->linkDepth++;
        $saved = $this->moved;
        $this->moved = '';
        $inner = $this->inlines($node->children ?? [], $depth + 1);
        $moved = $this->moved;
        $this->moved = $saved;
        $this->linkDepth--;

        return '<a' . $this->aw->build($structural, null, $merged, $lang) . '>' . $inner . '</a>' . $moved;
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     * @param string $key
     *
     * @return array<array{0: string, 1: string|null}>
     */
    private static function refCarriers(Node $node, string $key): array
    {
        $out = [['data-carve-ref', $key]];
        $form = $node->f['refForm'] ?? null;
        if ($form === 'collapsed') {
            $out[] = ['data-carve-ref-collapsed', null];
        } elseif ($form === 'shortcut') {
            $out[] = ['data-carve-ref-shortcut', null];
        }

        return $out;
    }

    /**
     * C2-H-141: the classes of the definition first, the reference's id and keys win.
     *
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param \MarkupCarve\Carve2\Node|null $def
     *
     * @return \MarkupCarve\Carve2\Attrs|null
     */
    private static function mergeDefinition(?Attrs $attrs, ?Node $def): ?Attrs
    {
        $defAttrs = $def?->f['attrs'] ?? null;
        if (!$defAttrs instanceof Attrs) {
            return $attrs;
        }
        $merged = clone $defAttrs;
        if ($attrs !== null) {
            // A key of the reference replaces the definition's and takes the reference's place (oracle order).
            foreach ($attrs->pairs as [$key]) {
                $merged->remove($key);
            }
            $merged->merge($attrs);
        }

        return $merged;
    }

    /**
     * C2-H-167: the authored words, then the profile's words not among them.
     *
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     *
     * @return string|null
     */
    private function rel(?Attrs &$attrs): ?string
    {
        $words = [];
        $authored = $attrs?->get('rel');
        if ($authored !== null) {
            $attrs = clone $attrs;
            $attrs->remove('rel');
            $authored = $this->aw->filterValue('rel', $authored);
            $words = preg_split('/[ \t\n\f\r]+/', $authored, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        foreach ($this->profile?->linkRel() ?? [] as $word) {
            if (!in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return $words === [] ? null : implode(' ', $words);
    }

    /**
     * C2-H-082, C2-H-160: a denied destination is written empty and reported.
     *
     * @param string $url
     *
     * @return string
     */
    public function destination(string $url): string
    {
        if (UrlPolicy::passes($url, $this->o)) {
            return $url;
        }
        $this->log->loss('destination-denied', 'error', 'destination denied');

        return '';
    }

    /**
     * C2-H-085, C2-H-139: brackets and label as text, the attribute block behind it.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth
     *
     * @return string
     */
    private function unresolved(Node $node, ?Attrs $attrs, ?string $lang, int $depth): string
    {
        $key = PlainText::str($node->f['key'] ?? '');
        if ($node->type === 'image') {
            $out = '![' . Escape::text(PlainText::str($node->f['alt'] ?? '')) . ']';
        } else {
            $out = '[' . $this->inlines($node->children ?? [], $depth + 1) . ']';
        }
        $form = $node->f['refForm'] ?? null;
        if ($form === 'full') {
            $out .= '[' . Escape::text($key) . ']';
        } elseif ($form === 'collapsed') {
            $out .= '[]';
        }

        return $out . Escape::text(self::attributeText($attrs, $lang));
    }

    /**
     * C2-H-139: id, classes, language, keys.
     *
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     *
     * @return string
     */
    public static function attributeText(?Attrs $attrs, ?string $lang): string
    {
        $parts = [];
        if ($attrs?->id !== null) {
            $parts[] = '#' . $attrs->id;
        }
        foreach ($attrs !== null ? $attrs->classes : [] as $class) {
            $parts[] = '.' . $class;
        }
        if ($lang !== null) {
            $parts[] = ':' . $lang;
        }
        foreach ($attrs !== null ? $attrs->pairs : [] as [$k, $v]) {
            $parts[] = $v === '' ? $k : $k . '=' . (preg_match('/^[^\s"\'\\\\|}]+$/', $v) === 1 ? $v : '"' . addcslashes($v, '"\\') . '"');
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     *
     * @return string
     */
    private function autolink(Node $node, ?Attrs $attrs, ?string $lang): string
    {
        $href = PlainText::str($node->f['href'] ?? '');
        $text = Escape::text(PlainText::str($node->f['text'] ?? $href));
        if ($this->linkDepth > 0) {
            return $text;
        }
        $structural = [['href', $this->destination($href)]];
        $rel = $this->rel($attrs);
        if ($rel !== null) {
            $structural[] = ['rel', $rel];
        }
        if ($this->o->roundtrip) {
            $structural[] = ['data-carve-autolink', null];
        }

        return '<a' . $this->aw->build($structural, null, $attrs, $lang) . '>' . $text . '</a>';
    }

    /**
     * C2-H-080, C2-H-160: an authored `src` is never written.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     *
     * @return string
     */
    public function image(Node $node, ?Attrs $attrs, ?string $lang): string
    {
        $key = $node->f['key'] ?? null;
        $src = $node->f['src'] ?? null;
        if (is_string($key) && !is_string($src)) {
            return $this->unresolved($node, $attrs, $lang, 0);
        }
        $def = is_string($key) ? $this->state->linkDefs[$key] ?? null : null;
        $merged = self::mergeDefinition($attrs, $def);
        $tooltip = $node->f['tooltip'] ?? $def?->f['tooltip'] ?? null;
        $structural = [['src', $this->destination(PlainText::str($src))], ['alt', PlainText::str($node->f['alt'] ?? '')]];
        if (is_string($tooltip)) {
            $structural[] = ['title', $tooltip];
        }
        if ($this->o->roundtrip && is_string($key)) {
            $structural = array_merge($structural, self::refCarriers($node, $key));
        }

        return '<img' . $this->aw->build($structural, null, $merged, $lang) . '>';
    }

    /**
     * C2-H-086, C2-H-088.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function noteMark(Node $node, int $depth): string
    {
        $number = $node->f['number'] ?? null;
        $id = $this->state->refIds[spl_object_id($node)] ?? null;
        if (!is_int($number) || $id === null) {
            if ($node->type === 'inline_footnote') {
                return $this->inlines($node->children ?? [], $depth + 1);
            }

            return Escape::text('[^' . PlainText::str($node->f['key'] ?? '') . ']');
        }
        $structural = [['id', $id], ['href', '#fn' . $number], ['role', 'doc-noteref']];
        if ($this->o->roundtrip && $node->type === 'footnote_ref') {
            $structural[] = ['data-carve-footnote-label', PlainText::str($node->f['key'] ?? '')];
        }
        $attrs = $node->f['attrs'] ?? null;
        $mark = '<a' . $this->aw->build($structural, '', $attrs instanceof Attrs ? $attrs : null) . '><sup>' . $number . '</sup></a>';
        if ($this->linkDepth > 0) {
            $this->moved .= $mark;

            return '';
        }

        return $mark;
    }

    /**
     * C2-H-096, C2-H-097.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     *
     * @return string
     */
    private function xref(Node $node, ?Attrs $attrs, ?string $lang): string
    {
        $key = PlainText::str($node->f['key'] ?? '');
        $href = $node->f['href'] ?? null;
        if (!is_string($href)) {
            return Escape::text('</#' . $key . '>');
        }
        $text = Escape::text($this->state->xrefText[$key] ?? $key);
        if ($this->linkDepth > 0) {
            return $text;
        }
        $structural = [['href', $this->destination($href)]];
        if ($this->o->roundtrip) {
            $structural[] = ['data-carve-xref', null];
        }

        return '<a' . $this->aw->build($structural, null, $attrs, $lang) . '>' . $text . '</a>';
    }

    /**
     * C2-H-098, C2-H-099.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     *
     * @return string
     */
    private function abbreviation(Node $node, ?Attrs $attrs, ?string $lang): string
    {
        $term = Escape::text(PlainText::str($node->f['key'] ?? ''));
        $expansion = $node->f['expansion'] ?? null;
        if ($this->abbrSpan > 0 || !is_string($expansion)) {
            return $term;
        }

        return '<abbr' . $this->aw->build([['title', $expansion]], null, $attrs, $lang) . '>' . $term . '</abbr>';
    }

    /**
     * C2-H-100, C2-H-126: inert spans, or anchors when `social` gives a URL.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     *
     * @return string
     */
    private function social(Node $node, ?Attrs $attrs, ?string $lang): string
    {
        $mention = $node->type === 'mention';
        $name = PlainText::str($mention ? $node->f['target'] ?? '' : $node->f['name'] ?? '');
        $label = $mention && is_string($node->f['label'] ?? null) ? $node->f['label'] : ($mention ? '@' : '#') . $name;
        $inner = '<strong>' . Escape::text($label) . '</strong>';
        $url = $this->linkDepth > 0 ? null : $this->socialUrl($mention, $name, $node);
        $class = $mention ? 'mention' : 'tag';
        if ($url === null) {
            return '<span' . $this->aw->build([], null, $attrs, $lang, [$class]) . '>' . $inner . '</span>';
        }

        $built = $this->aw->build([['href', $this->destination($url)]], null, $attrs, $lang, [$class]);
        // C2-H-011: on a mention or a tag the class stands first.
        if (preg_match('/ class="[^"]*"/', $built, $m) === 1) {
            $built = $m[0] . str_replace($m[0], '', $built);
        }

        return '<a' . $built . '>' . $inner . '</a>';
    }

    /**
     * @param bool $mention
     * @param string $name
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return string|null
     */
    private function socialUrl(bool $mention, string $name, Node $node): ?string
    {
        $resolver = $mention ? $this->o->resolveMention : $this->o->resolveTag;
        if ($resolver !== null) {
            $url = $resolver($name, $node);
            if (is_string($url)) {
                return $url;
            }
        }
        $template = $mention ? $this->o->mentionUrl : $this->o->tagUrl;

        return $template === null ? null : str_replace('{name}', $name, $template);
    }

    /**
     * C2-H-101.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     *
     * @return string
     */
    private function symbol(Node $node, ?Attrs $attrs, ?string $lang): string
    {
        $name = PlainText::str($node->f['name'] ?? '');
        $out = $this->o->symbols[$name] ?? Escape::text(':' . $name . ':');
        if ($attrs === null && $lang === null) {
            return $out;
        }

        return '<span' . $this->aw->build([], null, $attrs, $lang) . '>' . $out . '</span>';
    }

    /**
     * C2-H-066, C2-H-067.
     *
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return string
     */
    private function captionNumber(Node $node): string
    {
        $n = $node->f['n'] ?? null;
        $text = is_int($n) ? (string)$n : '#';
        if ($this->o->roundtrip && is_int($n)) {
            return '<span data-carve-caption-number>' . $text . '</span>';
        }

        return $text;
    }

    /**
     * C2-H-076: an unknown inline extension is a span around its content; a qualified one renders its fallback.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth
     *
     * @return string
     */
    private function extension(Node $node, ?Attrs $attrs, ?string $lang, int $depth): string
    {
        $name = PlainText::str($node->f['name'] ?? '');
        if (str_contains($name, '.')) {
            $this->log->loss('extension-fallback-rendered', 'warning', 'extension ' . $name . ' rendered through its fallback');
            $fallback = $node->f['fallback'] ?? null;

            return $this->inlines(PlainText::nodes(is_array($fallback) ? $fallback : $node->children), $depth + 1);
        }

        return '<span' . $this->aw->build([], null, $attrs, $lang, ['ext-' . $name]) . '>' . $this->inlines($node->children ?? [], $depth + 1) . '</span>';
    }

    /**
     * C2-H-142.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth
     *
     * @return string
     */
    private function ruby(Node $node, ?Attrs $attrs, ?string $lang, int $depth): string
    {
        $out = '';
        foreach (PlainText::nodes($node->f['pairs'] ?? []) as $pair) {
            $out .= $this->inlines(PlainText::nodes($pair->f['base'] ?? []), $depth + 1)
                . '<rp>(</rp><rt>' . $this->inlines(PlainText::nodes($pair->f['annotation'] ?? []), $depth + 1) . '</rt><rp>)</rp>';
        }

        return '<ruby' . $this->aw->build([], null, $attrs, $lang) . '>' . $out . '</ruby>';
    }
}
