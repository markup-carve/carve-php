<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Walk;
use Throwable;

/**
 * Inline nodes to HTML (C2-H-072 to C2-H-101).
 */
final class InlineRenderer
{
    /**
     * Inline nodes with a fixed output.
     *
     * @var array<string, string>
     */
    private const FIXED = [

        'soft_break' => "\n",
        'hard_break' => "<br>\n",
        'non_breaking_space' => '&nbsp;',
        'comment' => '',
        'citation' => '',
    ];

    /**
     * The writer of each inline type, called with the node, its attributes, its language and the depth.
     *
     * @var array<string, string>
     */
    private const METHODS = [
        'smart_punctuation' => 'smart',
        'code' => 'code',
        'literal_inline' => 'literal',
        'math' => 'math',
        'raw_inline' => 'rawInline',
        'span' => 'span',
        'critic_comment' => 'criticComment',
        'link' => 'link',
        'autolink' => 'autolink',
        'image' => 'image',
        'footnote_ref' => 'noteMarkOf',
        'inline_footnote' => 'noteMarkOf',
        'xref' => 'xref',
        'abbreviation' => 'abbreviation',
        'mention' => 'social',
        'tag' => 'social',
        'symbol' => 'symbol',
        'caption_number' => 'captionNumber',
        'inline_extension' => 'extension',
        'citation_group' => 'citationGroup',
        'small_caps' => 'smallCaps',
        'ruby' => 'ruby',
    ];

    private int $linkDepth = 0;

    private int $abbrSpan = 0;

    private string $moved = '';

    public ?Profile $profile = null;

    /**
     * @param \MarkupCarve\Carve\Options $o
     * @param \MarkupCarve\Carve\Html\RenderState $state
     * @param \MarkupCarve\Carve\Html\AttrWriter $aw
     * @param \MarkupCarve\Carve\Html\RenderLog $log
     * @param \Closure(): \MarkupCarve\Carve\Extension\RenderHost $host
     */
    public function __construct(
        private Options $o,
        private RenderState $state,
        private AttrWriter $aw,
        private RenderLog $log,
        private Closure $host,
    ) {
    }

    /**
     * The content of a leaf: C2-H-069 drops one line break beside each comment-only line,
     * judged in each child list on its own (the oracle's reading; G-render-16).
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     * @param int $depth Nesting level for the profile (C2-H-166).
     *
     * @return string
     */
    public function leaf(array $nodes, int $depth = 0): string
    {
        return $this->inlines($nodes, $depth);
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     * @param int $depth
     * @param bool $nested
     *
     * @return string
     */
    public function inlines(array $nodes, int $depth = 0, bool $nested = false): string
    {
        $out = '';
        if ($this->profile !== null) {
            foreach (self::dropCommentLineBreaks($nodes, $nested, $this->profile) as $node) {
                $out .= $this->profile->inline($node, $depth, $this);
            }

            return $out;
        }
        // With no comment in the tree, or a single node, C2-H-069 drops nothing.
        $list = $this->state->comments && count($nodes) > 1 ? self::dropCommentLineBreaks($nodes, $nested) : $nodes;
        if ($this->o->quotes !== null) {
            $list = array_values($list);
        }
        foreach ($list as $i => $node) {
            $tag = $node->f === [] ? Tables::DELIMITER_HTML[$node->type] ?? null : null;
            if ($tag !== null) {
                // A delimiter span with no attributes, the common case of node(), without its lookups.
                $out .= '<' . $tag . '>' . $this->inlines($node->children ?? [], $depth + 1, true) . '</' . $tag . '>';
            } elseif ($node->type === 'text') {
                $value = $node->f['value'] ?? '';
                // Plain text needs no escaping: the check of Escape::text, done here to save two calls.
                $out .= is_string($value) && strcspn($value, Escape::TEXT_SPECIAL) === strlen($value) ? $value
                    : Escape::text(PlainText::str($value));
            } else {
                $out .= $this->other($node, $list, $i, $depth);
            }
        }

        return $out;
    }

    /**
     * A node that is no plain text and no bare delimiter span.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param int $i
     * @param int $depth
     *
     * @return string
     */
    private function other(Node $node, array $list, int $i, int $depth): string
    {
        if ($node->type === 'smart_punctuation' && $this->o->quotes !== null) {
            return Escape::text($this->quote($node, $this->o->quotes, $list[$i - 1] ?? null, $list[$i + 1] ?? null));
        }

        return $this->node($node, $depth);
    }

    /**
     * C2-H-078: a quote that carries the glyph of its kind takes the glyph of a registered extension; a closing single
     * quote between two letters is an apostrophe and keeps its glyph.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param array{0: string, 1: string, 2: string, 3: string} $quotes
     * @param \MarkupCarve\Carve\Node|null $before
     * @param \MarkupCarve\Carve\Node|null $after
     *
     * @return string
     */
    private function quote(Node $node, array $quotes, ?Node $before, ?Node $after): string
    {
        $f = $node->f;
        if (!$this->o->smartTypography) {
            return PlainText::str($f['source'] ?? '');
        }
        $kind = PlainText::str($f['kind'] ?? '');
        $own = Tables::SMART_GLYPHS[$kind] ?? null;
        $glyph = PlainText::str($f['glyph'] ?? $own ?? $f['source'] ?? '');
        $at = array_search($kind, ['left_double_quote', 'right_double_quote', 'left_single_quote', 'right_single_quote'], true);
        if ($at === false || $glyph !== $own) {
            return $glyph;
        }
        if (
            $kind === 'right_single_quote' && $before?->type === 'text' && $after?->type === 'text'
            && preg_match('/\p{L}$/u', PlainText::str($before->f['value'] ?? '')) === 1
            && preg_match('/^\p{L}/u', PlainText::str($after->f['value'] ?? '')) === 1
        ) {
            return $glyph;
        }

        return $quotes[$at];
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     * @param bool $nested
     * @param \MarkupCarve\Carve\Html\Profile|null $profile
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function dropCommentLineBreaks(array $nodes, bool $nested = false, ?Profile $profile = null): array
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
                if ($i < $count && ($lineStart > 0 || !$nested) && ($profile === null || $profile->allowsInline($nodes[$i]))) {
                    $drop[$i] = true;
                } elseif (
                    $i === $count && $lineStart > 0 && !$nested
                    && ($profile === null || $profile->allowsInline($nodes[$lineStart - 1]))
                ) {
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
     * @param array<\MarkupCarve\Carve\Node> $nodes
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
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    public function node(Node $node, int $depth = 0): string
    {
        $previous = $this->log->currentNode;
        $this->log->currentNode = $node;
        try {
            return $this->nodeContent($node, $depth);
        } finally {
            $this->log->currentNode = $previous;
        }
    }

    private function nodeContent(Node $node, int $depth): string
    {
        $type = $node->type;
        if ($type === 'text') {
            return Escape::text(PlainText::str($node->f['value'] ?? ''));
        }
        if (isset(self::FIXED[$type])) {
            return self::FIXED[$type];
        }
        $attrs = $node->f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? $attrs : null;
        $lang = $node->f['lang'] ?? null;
        $lang = is_string($lang) ? $lang : null;
        $method = self::METHODS[$type] ?? null;
        if ($method !== null) {
            return $this->$method($node, $attrs, $lang, $depth);
        }
        $kids = $node->children ?? [];
        $tag = Tables::DELIMITER_HTML[$type] ?? null;
        if ($tag !== null) {
            return '<' . $tag . $this->aw->build([], null, $attrs, $lang, dir: AttrWriter::dirOf($node)) . '>'
                . $this->inlines($kids, $depth + 1, true) . '</' . $tag . '>';
        }

        return $this->inlines($kids, $depth + 1, true);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs Unused; the uniform signature of METHODS.
     * @param string|null $lang Unused.
     * @param int $depth Unused.
     *
     * @return string
     */
    private function smart(Node $node, ?Attrs $attrs = null, ?string $lang = null, int $depth = 0): string
    {
        $f = $node->f;

        return Escape::text(PlainText::str($this->o->smartTypography ? ($f['glyph'] ?? $f['source'] ?? '') : ($f['source'] ?? '')));
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     *
     * @return string
     */
    private function code(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0): string
    {
        return '<code' . $this->aw->build([], null, $attrs, $lang, dir: AttrWriter::dirOf($node)) . '>'
            . Escape::text(PlainText::str($node->f['value'] ?? '')) . '</code>';
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     *
     * @return string
     */
    private function literal(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0): string
    {
        $text = Escape::text(PlainText::str($node->f['value'] ?? ''));

        $dir = AttrWriter::dirOf($node);

        return $attrs === null && $lang === null && $dir === null
            ? $text : '<span' . $this->aw->build([], null, $attrs, $lang, dir: $dir) . '>' . $text . '</span>';
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     *
     * @return string
     */
    private function criticComment(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0): string
    {
        return '<span' . $this->aw->build([], null, $attrs, $lang, ['critic-comment'], dir: AttrWriter::dirOf($node)) . '>'
            . Escape::text(PlainText::str($node->f['value'] ?? '')) . '</span>';
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs Unused; the uniform signature of METHODS.
     * @param string|null $lang Unused.
     * @param int $depth Unused.
     *
     * @return string
     */
    private function citationGroup(Node $node, ?Attrs $attrs = null, ?string $lang = null, int $depth = 0): string
    {
        return Escape::text(PlainText::str($node->f['raw'] ?? ''));
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth
     *
     * @return string
     */
    private function smallCaps(Node $node, ?Attrs $attrs, ?string $lang, int $depth): string
    {
        return '<span' . $this->aw->build([], null, $attrs, $lang, ['smallcaps'], dir: AttrWriter::dirOf($node)) . '>'
            . $this->inlines($node->children ?? [], $depth + 1, true) . '</span>';
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth
     *
     * @return string
     */
    private function noteMarkOf(Node $node, ?Attrs $attrs, ?string $lang, int $depth): string
    {
        return $this->noteMark($node, $depth);
    }

    /**
     * C2-H-072, C2-H-168.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     *
     * @return string
     */
    private function math(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0): string
    {
        $value = PlainText::str($node->f['value'] ?? '');
        $display = ($node->f['display'] ?? false) === true;
        $classes = ['math', $display ? 'display' : 'inline'];
        $out = '<span' . $this->aw->build([], null, $attrs, $lang, $classes, [], [['role', 'math']], dir: AttrWriter::dirOf($node)) . '>'
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
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs Unused; the uniform signature of METHODS.
     * @param string|null $lang Unused.
     * @param int $depth Unused.
     *
     * @return string
     */
    private function rawInline(Node $node, ?Attrs $attrs = null, ?string $lang = null, int $depth = 0): string
    {
        $value = PlainText::str($node->f['value'] ?? '');
        $format = PlainText::str($node->f['format'] ?? '');
        if ($format !== 'html') {
            $this->log->loss('raw-format-dropped', 'warning', ['kind' => 'inline', 'format' => $format, 'target' => 'HTML']);

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
     * C2-H-075, C2-H-099, C2-H-175: `abbr`, `time` and `kbd` keys, and those a registered extension adds, nest from inside
     * out.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
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
            foreach ($this->o->semanticKeys as [$key, $target]) {
                $value = null;
                foreach ($rest->pairs as [$name, $candidate]) {
                    if ($name === $key) {
                        $value = $candidate;

                        break;
                    }
                }
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
        $inner = $this->inlines($node->children ?? [], $depth + 1, true);
        $this->abbrSpan -= $isAbbr ? 1 : 0;
        if ($layers === []) {
            return '<span' . $this->aw->build([], null, $attrs, $lang, dir: AttrWriter::dirOf($node)) . '>' . $inner . '</span>';
        }
        $last = count($layers) - 1;
        foreach ($layers as $i => [$key, $target, $value]) {
            $structural = [];
            if ($target !== null && $value !== '') {
                $derived = $this->aw->filterValue($target, $value);
                $structural = $i === $last && $rest->get($target) !== null ? [] : [[$target, $derived]];
            }
            $attr = $i === $last
                ? $this->aw->build($structural, null, $rest, $lang, dir: AttrWriter::dirOf($node)) : AttrWriter::join($structural);
            $inner = '<' . $key . $attr . '>' . $inner . '</' . $key . '>';
        }

        return $inner;
    }

    /**
     * C2-H-079, C2-H-081, C2-H-085, C2-H-088, C2-H-141.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
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
        // C2-H-177: a reference to a degraded heading writes as unresolved, without changing resolution.
        if (
            is_string($key) && $this->profile !== null && !isset($this->state->linkDefs[$key])
            && !$this->profile->allowsBlockType('heading')
        ) {
            return $this->unresolved($node, $attrs, $lang, $depth);
        }
        if ($this->linkDepth > 0) {
            return $this->inlines($node->children ?? [], $depth + 1, true);
        }
        $def = is_string($key) ? $this->state->linkDefs[$key] ?? null : null;
        if ($this->o->profile === 'comment' && !UrlPolicy::passes(PlainText::str($href), $this->o)) {
            $this->destination(PlainText::str($href));

            return $this->profile?->plainInline($node) ?? Escape::text(PlainText::of($node->children ?? []));
        }
        $merged = self::mergeDefinition($attrs, $def);
        $structural = $this->linkStructural($node, is_string($key) ? $key : null, PlainText::str($href), $def, $merged);
        $this->linkDepth++;
        $saved = $this->moved;
        $this->moved = '';
        $inner = $this->inlines($node->children ?? [], $depth + 1, true);
        $moved = $this->moved;
        $this->moved = $saved;
        $this->linkDepth--;

        return '<a' . $this->aw->build($structural, null, $merged, $lang, dir: AttrWriter::dirOf($node)) . '>' . $inner . '</a>' . $moved;
    }

    /**
     * C2-H-010: `href`, `title` and `rel` of a link, then the roundtrip carriers of a reference.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param string|null $key
     * @param string $href
     * @param \MarkupCarve\Carve\Node|null $def
     * @param \MarkupCarve\Carve\Attrs|null $merged Loses an authored `rel`, which becomes structural.
     *
     * @return array<array{0: string, 1: string|null}>
     */
    private function linkStructural(Node $node, ?string $key, string $href, ?Node $def, ?Attrs &$merged): array
    {
        $structural = [['href', $this->destination($href)]];
        $tooltip = $def === null ? $node->f['tooltip'] ?? null : $def->f['tooltip'] ?? null;
        if (is_string($tooltip)) {
            $structural[] = ['title', $tooltip];
        }
        $rel = $this->rel($merged);
        if ($rel !== null) {
            $structural[] = ['rel', $rel];
        }
        if ($this->o->roundtrip && $key !== null) {
            $structural = array_merge($structural, self::refCarriers($node, $key));
        }

        return $structural;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
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
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param \MarkupCarve\Carve\Node|null $def
     *
     * @return \MarkupCarve\Carve\Attrs|null
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
     * @param \MarkupCarve\Carve\Attrs|null $attrs
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
            // C2-H-167: one character class run, linear; a rel value is a few words.
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
        $this->log->loss('destination-denied', 'error', field: $this->log->currentNode?->type === 'image' ? 'src' : 'href');

        return '';
    }

    /**
     * C2-H-085, C2-H-139: brackets and label as text, the attribute block behind it.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
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
            $out = '[' . $this->inlines($node->children ?? [], $depth + 1, true) . ']';
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
     * @param \MarkupCarve\Carve\Attrs|null $attrs
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
            // C2-H-139: one anchored negated class, linear; roundtrip text only, not per node.
            $parts[] = $v === '' ? $k : $k . '=' . (preg_match('/^[^\s"\'\\\\|}]+$/', $v) === 1 ? $v : '"' . addcslashes($v, '"\\') . '"');
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     *
     * @return string
     */
    private function autolink(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0): string
    {
        $href = PlainText::str($node->f['href'] ?? '');
        $text = Escape::text(PlainText::str($node->f['text'] ?? $href));
        if ($this->linkDepth > 0) {
            return $text;
        }
        if ($this->o->profile === 'comment' && !UrlPolicy::passes($href, $this->o)) {
            $this->destination($href);

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

        return '<a' . $this->aw->build($structural, null, $attrs, $lang, dir: AttrWriter::dirOf($node)) . '>' . $text . '</a>';
    }

    /**
     * C2-H-080, C2-H-160: an authored `src` is never written.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     * @param int|null $sourceLine C2-H-016: a block image is the paragraph's element.
     *
     * @return string
     */
    public function image(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0, ?int $sourceLine = null): string
    {
        $key = $node->f['key'] ?? null;
        $src = $node->f['src'] ?? null;
        if (is_string($key) && !is_string($src)) {
            return $this->unresolved($node, $attrs, $lang, 0);
        }
        $def = is_string($key) ? $this->state->linkDefs[$key] ?? null : null;
        $merged = self::mergeDefinition($attrs, $def);
        $tooltip = $def === null ? $node->f['tooltip'] ?? null : $def->f['tooltip'] ?? null;
        $structural = [['src', $this->destination(PlainText::str($src))], ['alt', PlainText::str($node->f['alt'] ?? '')]];
        if (is_string($tooltip)) {
            $structural[] = ['title', $tooltip];
        }
        if ($this->o->roundtrip && is_string($key)) {
            $structural = array_merge($structural, self::refCarriers($node, $key));
        }

        return '<img' . $this->aw->build($structural, null, $merged, $lang, sourceLine: $sourceLine, dir: AttrWriter::dirOf($node)) . '>';
    }

    /**
     * C2-H-086, C2-H-088.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param int $depth
     *
     * @return string
     */
    private function noteMark(Node $node, int $depth): string
    {
        $number = $node->f['number'] ?? null;
        $id = $this->state->refIds[spl_object_id($node)] ?? null;
        if (!is_int($number) || $id === null) {
            return $this->unresolvedNoteMark($node, $depth);
        }
        $structural = [['id', $id], ['href', '#' . $this->state->noteIds[$number]], ['role', 'doc-noteref']];
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

    private function unresolvedNoteMark(Node $node, int $depth): string
    {
        if ($node->type === 'inline_footnote') {
            return $this->inlines($node->children ?? [], $depth + 1, true);
        }
        $attrs = $node->f['attrs'] ?? null;
        $lang = $node->f['lang'] ?? null;
        $text = '[^' . PlainText::str($node->f['key'] ?? '') . ']';

        return Escape::text($text . self::attributeText(
            $attrs instanceof Attrs ? $attrs : null,
            is_string($lang) ? $lang : null,
        ));
    }

    /**
     * C2-H-096, C2-H-097.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     *
     * @return string
     */
    private function xref(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0): string
    {
        $key = PlainText::str($node->f['key'] ?? '');
        $href = $node->f['href'] ?? null;
        if (!is_string($href)) {
            return Escape::text('</#' . $key . '>');
        }
        $text = $this->state->xrefHtmlText($key) ?? Escape::text($key);
        if (!$this->derivedFits(strlen($text))) {
            $text = Escape::text($key);
        }
        if ($this->linkDepth > 0) {
            return $text;
        }
        $structural = [['href', $this->destination($href)]];
        if ($this->o->roundtrip) {
            $structural[] = ['data-carve-xref', null];
        }

        return '<a' . $this->aw->build($structural, null, $attrs, $lang, dir: AttrWriter::dirOf($node)) . '>' . $text . '</a>';
    }

    /**
     * C2-H-098, C2-H-099.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     *
     * @return string
     */
    private function abbreviation(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0): string
    {
        $term = Escape::text(PlainText::str($node->f['key'] ?? ''));
        $expansion = $node->f['expansion'] ?? null;
        if ($this->abbrSpan > 0 || !is_string($expansion) || !$this->derivedFits(strlen($expansion))) {
            return $term;
        }

        return '<abbr' . $this->aw->build([['title', $expansion]], null, $attrs, $lang, dir: AttrWriter::dirOf($node)) . '>'
            . $term . '</abbr>';
    }

    private function derivedFits(int $cost): bool
    {
        if ($cost <= $this->state->derivedRemaining) {
            $this->state->derivedRemaining -= $cost;

            return true;
        }
        if (!$this->state->budgetReported) {
            $this->state->budgetReported = true;
            $this->log->loss('derived-text-budget', 'warning');
        }

        return false;
    }

    /**
     * C2-H-100, C2-H-126: inert spans, or anchors when `social` gives a URL.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     *
     * @return string
     */
    private function social(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0): string
    {
        $mention = $node->type === 'mention';
        $name = PlainText::str($mention ? $node->f['target'] ?? '' : $node->f['name'] ?? '');
        $label = $mention && is_string($node->f['label'] ?? null) ? $node->f['label'] : ($mention ? '@' : '#') . $name;
        $inner = '<strong>' . Escape::text($label) . '</strong>';
        $url = $this->linkDepth > 0 || ($this->profile !== null && !$this->profile->allowsType('link'))
            ? null : $this->socialUrl($mention, $name);
        $class = $mention ? 'mention' : 'tag';
        if ($url === null) {
            return '<span' . $this->aw->build([], null, $attrs, $lang, [$class], dir: AttrWriter::dirOf($node)) . '>' . $inner . '</span>';
        }

        $rel = $this->rel($attrs);
        $structural = [['href', $this->destination($url)]];
        if ($rel !== null) {
            $structural[] = ['rel', $rel];
        }
        $built = $this->aw->build($structural, null, $attrs, $lang, [$class], dir: AttrWriter::dirOf($node));
        // C2-H-011: on a mention or a tag the class stands first.
        // C2-H-011: one negated class between fixed text, linear; once per linked mention or tag.
        if (preg_match('/ class="[^"]*"/', $built, $m) === 1) {
            $built = $m[0] . str_replace($m[0], '', $built);
        }

        return '<a' . $built . '>' . Escape::text($label) . '</a>';
    }

    /**
     * @param bool $mention
     * @param string $name
     *
     * @return string|null
     */
    private function socialUrl(bool $mention, string $name): ?string
    {
        $resolver = $mention ? $this->o->resolveMention : $this->o->resolveTag;
        if ($resolver !== null) {
            $request = ['kind' => $mention ? 'mention' : 'tag', 'name' => $name];
            if ($this->o->socialHasContext) {
                $request['context'] = $this->o->socialContext;
            }
            try {
                $url = $resolver($request);
            } catch (Throwable) {
                return null;
            }

            return is_string($url) && $url !== '' ? $url : null;
        }
        $template = $mention ? $this->o->mentionUrl : $this->o->tagUrl;

        $encoded = str_replace(['%21', '%27', '%28', '%29', '%2A'], ['!', "'", '(', ')', '*'], rawurlencode(Escape::clean($name)));
        $url = $template === null ? '' : str_replace('{name}', $encoded, $template);

        return $url === '' ? null : $url;
    }

    /**
     * C2-H-101.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth Unused; the uniform signature of METHODS.
     *
     * @return string
     */
    private function symbol(Node $node, ?Attrs $attrs, ?string $lang, int $depth = 0): string
    {
        $name = PlainText::str($node->f['name'] ?? '');
        $out = $this->o->symbols[$name] ?? Escape::text(':' . $name . ':');
        if ($attrs === null && $lang === null) {
            return $out;
        }

        return '<span' . $this->aw->build([], null, $attrs, $lang, dir: AttrWriter::dirOf($node)) . '>' . $out . '</span>';
    }

    /**
     * C2-H-066, C2-H-067.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs Unused; the uniform signature of METHODS.
     * @param string|null $lang Unused.
     * @param int $depth Unused.
     *
     * @return string
     */
    private function captionNumber(Node $node, ?Attrs $attrs = null, ?string $lang = null, int $depth = 0): string
    {
        $n = $node->f['n'] ?? null;
        $text = is_int($n) ? (string)$n : '#';
        if ($this->o->roundtrip && is_int($n)) {
            return '<span data-carve-caption-number>' . $text . '</span>';
        }

        return $text;
    }

    /**
     * C2-H-076, C2-X-034: a qualified inline extension uses its registered renderer or its fallback.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth
     *
     * @return string
     */
    private function extension(Node $node, ?Attrs $attrs, ?string $lang, int $depth): string
    {
        $name = PlainText::str($node->f['name'] ?? '');
        if (str_contains($name, '.')) {
            $renderer = $this->o->rendered[Walk::key($node)] ?? null;
            if ($renderer !== null) {
                return $renderer($node, ($this->host)());
            }
            $this->log->loss('extension-fallback-rendered', 'warning', ['name' => $name]);
            $fallback = $node->f['fallback'] ?? null;
            $content = $this->inlines(PlainText::nodes(is_array($fallback) ? $fallback : $node->children), $depth + 1, true);
            if ($attrs === null || $attrs->isEmpty() && $lang === null && !isset($node->f['dir'])) {
                return $content;
            }

            return '<span' . $this->aw->build([], null, $attrs, $lang, dir: AttrWriter::dirOf($node)) . '>'
                . $content . '</span>';
        }

        return '<span' . $this->aw->build([], null, $attrs, $lang, ['ext-' . $name], dir: AttrWriter::dirOf($node)) . '>'
            . $this->inlines($node->children ?? [], $depth + 1, true) . '</span>';
    }

    /**
     * C2-H-142.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param string|null $lang
     * @param int $depth
     *
     * @return string
     */
    private function ruby(Node $node, ?Attrs $attrs, ?string $lang, int $depth): string
    {
        $out = '';
        foreach (PlainText::nodes($node->f['pairs'] ?? []) as $pair) {
            $out .= $this->inlines(PlainText::nodes($pair->f['base'] ?? []), $depth + 1, true)
                . '<rp>(</rp><rt>' . $this->inlines(PlainText::nodes($pair->f['annotation'] ?? []), $depth + 1, true) . '</rt><rp>)</rp>';
        }

        return '<ruby' . $this->aw->build([], null, $attrs, $lang, dir: AttrWriter::dirOf($node)) . '>' . $out . '</ruby>';
    }
}
