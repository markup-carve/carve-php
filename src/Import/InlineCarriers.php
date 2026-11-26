<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Html\UrlPolicy;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Options;
use SplObjectStorage;

/**
 * The inline elements a render writes for a node of its own: links and images (C2-M-050, C2-M-051), footnote
 * references (C2-M-062), the conventions of C2-M-035 and the inline carriers of C2-H-186.
 */
final class InlineCarriers
{
    /**
     * @var array<string>
     */
    private const LINK_FORMS = ['data-carve-ref', 'data-carve-autolink', 'data-carve-xref'];

    /**
     * @var array<string>
     */
    private const NOTE_PREFIXES = ['user-content-fn-', 'fn-', 'fn'];

    /**
     * C2-M-044: the revision keys of an editorial comment span.
     *
     * @var array<string, string>
     */
    private const COMMENT_KEYS = ['data-author' => 'author', 'data-datetime' => 'datetime'];

    /**
     * C2-M-113: the keys of a ranged comment as Pandoc writes them.
     *
     * @var array<string, string>
     */
    private const RANGED_COMMENT_KEYS = ['data-author' => 'author', 'data-date' => 'datetime'];

    /**
     * The inline containers that an import drops when they hold nothing and have no attributes (C2-M-069).
     *
     * @var array<string, true>
     */
    private const EMPTY_DROPPED = [
        'span' => true,
        'a' => true,
        'em' => true,
        'i' => true,
        'strong' => true,
        'b' => true,
        's' => true,
        'strike' => true,
        'del' => true,
        'ins' => true,
        'mark' => true,
        'u' => true,
        'sub' => true,
        'sup' => true,
    ];

    private ImportContext $context;

    private ?Options $policy = null;

    /**
     * The `comment-end` spans that close a ranged comment read before them (C2-M-113).
     *
     * @var \SplObjectStorage<\MarkupCarve\Carve\Import\HtmlNode, true>
     */
    private SplObjectStorage $commentEnds;

    /**
     * @var \SplObjectStorage<\MarkupCarve\Carve\Import\HtmlNode, array{0: int, 1: int}>|null
     */
    private ?SplObjectStorage $order = null;

    /**
     * @var array<string, array<array{0: int, 1: \MarkupCarve\Carve\Import\HtmlNode}>>
     */
    private array $ends = [];

    /**
     * For each place in document order, the number of nodes before it that give an inline.
     *
     * @var array<int, int>
     */
    private array $holding = [0];

    public function __construct(private InlineReader $reader)
    {
        $this->context = $reader->context;
        $this->commentEnds = new SplObjectStorage();
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    public function read(HtmlNode $node): ?array
    {
        if ($node->foreign) {
            return null;
        }

        return match ($node->name) {
            'a' => $this->footnote($node) ?? $this->anchor($node),
            'span' => $this->spanCarrier($node) ?? $this->classedSpan($node),
            'sup' => $this->noteSup($node),
            default => null,
        };
    }

    public static function isNoteRef(HtmlNode $node): bool
    {
        if (!$node->isElement('a') || ($node->attr('role') !== 'doc-noteref' && !$node->has('data-footnote-ref'))) {
            return false;
        }
        $href = $node->attr('href') ?? '';

        return str_starts_with($href, '#') && self::footnoteKey($href) !== '';
    }

    /**
     * C2-M-063: the backlink of a note.
     */
    public static function isBacklink(HtmlNode $node): bool
    {
        return $node->isElement('a') && ($node->attr('role') === 'doc-backlink' || $node->has('data-footnote-backref')
            || in_array('footnote-backref', $node->classes(), true));
    }

    public static function footnoteKey(string $fragment): string
    {
        $hash = str_starts_with($fragment, '#') ? substr($fragment, 1) : $fragment;
        foreach (self::NOTE_PREFIXES as $prefix) {
            if (str_starts_with($hash, $prefix)) {
                return substr($hash, strlen($prefix));
            }
        }

        return $hash;
    }

    /**
     * C2-M-062: a `<sup>` that only holds a note reference, or whose first class is `footnote-ref`, is that reference.
     *
     * @return array<array<string, mixed>>|null
     */
    private function noteSup(HtmlNode $node): ?array
    {
        if (count($node->children) === 1 && self::isNoteRef($node->children[0])) {
            return $this->footnote($node->children[0]);
        }
        if (($node->classes()[0] ?? null) !== 'footnote-ref') {
            return null;
        }
        $anchor = $node->firstElement('a');
        if ($anchor === null) {
            return null;
        }

        return self::isNoteRef($anchor) ? $this->footnote($anchor)
            : [['type' => 'footnote_ref', 'key' => self::footnoteKey($anchor->attr('href') ?? ''), 'namespace' => 'footnote']];
    }

    /**
     * C2-M-062, C2-H-088: a note reference, or the inline footnote its note stands for (C2-H-186).
     *
     * @return array<array<string, mixed>>|null
     */
    private function footnote(HtmlNode $node): ?array
    {
        if (self::isBacklink($node)) {
            return [];
        }
        if (!self::isNoteRef($node)) {
            return null;
        }
        if (Snippets::droppedMark($node, $this->context)) {
            return [];
        }
        $href = $node->attr('href') ?? '';
        $note = $this->context->notes[str_starts_with($href, '#') ? substr($href, 1) : $href] ?? null;
        $label = $node->attr('data-carve-footnote-label');
        $omit = ['href', 'role', 'data-footnote-ref'];
        if (self::isGeneratedRefId($node->attr('id'), $href, $node->plain())) {
            $omit[] = 'id';
        }
        $mapped = Attrs::of($node, $omit);
        if ($note !== null && $note['inline'] && $label === null) {
            $first = $note['item']->firstElement('p');

            return [['type' => 'inline_footnote', 'children' => $first === null ? [] : $this->reader->children($first)] + $mapped];
        }

        return [['type' => 'footnote_ref', 'key' => $this->noteKey($node, $label, $note, $href), 'namespace' => 'footnote'] + $mapped];
    }

    /**
     * C2-H-088, C2-H-202: a generated mark id starts with the note number or the shown number and may be suffixed.
     */
    private static function isGeneratedRefId(?string $id, string $href, string $shown): bool
    {
        if ($id === null || !str_starts_with($href, '#fn')) {
            return true;
        }
        foreach (['fnref' . substr($href, 3), 'fnref' . $shown] as $stem) {
            if ($id === $stem || self::generatedSuffix($id, $stem)) {
                return true;
            }
        }

        return false;
    }

    private static function generatedSuffix(string $id, string $stem): bool
    {
        if (!str_starts_with($id, $stem . '-')) {
            return false;
        }
        foreach (explode('-', substr($id, strlen($stem) + 1)) as $part) {
            if ($part === '' || !ctype_digit($part) || $part[0] === '0' || (int)$part < 2) {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-H-186: the key a reference carries, where its note agrees with it.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param string|null $label
     * @param array{item: \MarkupCarve\Carve\Import\HtmlNode, label: string|null, inline: bool}|null $note
     * @param string $href
     *
     * @return string
     */
    private function noteKey(HtmlNode $node, ?string $label, ?array $note, string $href): string
    {
        if ($node->has('data-carve-xref')) {
            $this->context->ignoredCarrier($node, 'data-carve-xref', 'the element is a footnote reference');
        }
        $fault = null;
        if ($label !== null) {
            $fault = match (true) {
                $label === '' || Chars::fold($label) !== $label => 'the key is not folded',
                $node->has('data-carve-xref') => 'the reference is also a cross-reference',
                $note !== null && $note['label'] !== $label => 'it does not name the note it links to',
                default => null,
            };
            if ($fault === null) {
                return $label;
            }
            $this->context->ignoredCarrier($node, 'data-carve-footnote-label', $fault);
        }

        return $note['label'] ?? self::footnoteKey($href);
    }

    /**
     * C2-H-185: a reference, an autolink and a cross-reference exclude each other.
     *
     * @return array<string>
     */
    private static function linkForms(HtmlNode $node): array
    {
        return array_values(array_filter(self::LINK_FORMS, static fn (string $name): bool => $node->has($name)));
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    private function anchor(HtmlNode $node): ?array
    {
        $forms = self::linkForms($node);
        if (count($forms) > 1) {
            foreach ($forms as $name) {
                if ($name !== 'data-carve-ref') {
                    $this->context->ignoredCarrier($node, $name, 'the link has one form');
                }
            }

            return null;
        }

        return match ($forms[0] ?? null) {
            'data-carve-autolink' => $this->autolink($node),
            'data-carve-xref' => $this->xref($node),
            default => null,
        };
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    private function autolink(HtmlNode $node): ?array
    {
        $href = $node->attr('href');
        if ($href === null || $node->elements() !== []) {
            $this->context->ignoredCarrier($node, 'data-carve-autolink', 'an autolink is a destination and its text');

            return null;
        }
        $label = $node->plain();
        $out = ['type' => 'autolink', 'href' => $href];
        if ($label !== $href) {
            $out['text'] = $label;
        }

        return [$out + Attrs::of($node, ['href'])];
    }

    /**
     * C2-H-096, C2-I-096: the text of a cross-reference is generated, so only its target reads back.
     *
     * @return array<array<string, mixed>>|null
     */
    private function xref(HtmlNode $node): ?array
    {
        $href = $node->attr('href') ?? '';
        $key = substr($href, 1);
        if (!str_starts_with($href, '#') || $key === '' || strpbrk($key, "> \n") !== false) {
            $this->context->ignoredCarrier($node, 'data-carve-xref', 'the target is no cross-reference id');

            return null;
        }

        return [['type' => 'xref', 'key' => $key, 'namespace' => 'heading', 'href' => $href] + Attrs::of($node, ['href'])];
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    private function spanCarrier(HtmlNode $node): ?array
    {
        $raw = $node->attr('data-carve-raw');
        if ($raw !== null) {
            if (!Elements::isRawSpan($node)) {
                $this->context->ignoredCarrier($node, 'data-carve-raw', Elements::isRawFormat($raw)
                    ? 'the span holds an element or has attributes' : 'the value is no raw format name');

                return null;
            }

            return Elements::keepsRaw($node, $this->context) ? [['type' => 'raw_inline', 'format' => $raw, 'value' => $node->plain()]] : [];
        }
        if (!$node->has('data-carve-caption-number')) {
            return null;
        }
        if (self::inCaption($node) && count($node->attrs) === 1 && $node->elements() === []) {
            return [['type' => 'caption_number']];
        }
        $reason = 'the mark stands outside a caption, holds an element or has attributes';
        $this->context->ignoredCarrier($node, 'data-carve-caption-number', $reason);

        return null;
    }

    private static function inCaption(HtmlNode $node): bool
    {
        for ($parent = $node->parent; $parent !== null; $parent = $parent->parent) {
            if ($parent->isElement('figcaption') || $parent->isElement('caption')) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-M-035: the spans whose classes a render gives the node it writes.
     *
     * @return array<array<string, mixed>>|null
     */
    private function classedSpan(HtmlNode $node): ?array
    {
        $classes = $node->classes();
        $first = $classes[0] ?? '';
        if ($first === 'math' && in_array($classes[1] ?? '', ['inline', 'display'], true)) {
            return $this->mathSpan($node, $classes[1]);
        }
        if ($first === 'critic-comment') {
            $mapped = Attrs::withoutClasses(Attrs::of($node), [$first]);

            return [['type' => 'critic_comment', 'value' => $node->plain()] + Attrs::renamedKeys($mapped, self::COMMENT_KEYS)];
        }
        if ($first === 'comment-start') {
            return $this->rangedComment($node);
        }
        if ($first === 'comment-end' && isset($this->commentEnds[$node])) {
            return [];
        }
        if ($first === 'mention' || $first === 'tag') {
            return $this->social($node, $first);
        }
        if (preg_match('/\Aext-([A-Za-z_][A-Za-z0-9_-]*)\z/D', $first, $match) === 1) {
            return [
                ['type' => 'inline_extension', 'name' => $match[1], 'children' => $this->reader->children($node)]
                + Attrs::withoutClasses(Attrs::of($node), [$first]),
            ];
        }

        return null;
    }

    /**
     * C2-M-113: a ranged comment is a point comment at its start; its end is dropped, and a range with a node in
     * it is reported as lost. A start with no text gives no comment.
     *
     * @return array<array<string, mixed>>
     */
    private function rangedComment(HtmlNode $node): array
    {
        $order = $this->indexDocument($node);
        $last = $order[$node][1];
        $ends = $this->ends[$node->attr('data-comment-id') ?? "\0"] ?? [];
        [$low, $high] = [0, count($ends)];
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($ends[$mid][0] > $last) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }
        $end = $ends[$low][1] ?? null;
        if ($end !== null) {
            $this->commentEnds[$end] = true;
        }
        $value = $node->plain();
        if ($value === '') {
            $this->context->report($node, 'import-element-dropped', 'Ranged comment with no text dropped');

            return [];
        }
        if ($end !== null && $this->rangeHolds($last + 1, $order[$end][0])) {
            $this->context->report($node, 'import-structure-repaired', 'Ranged comment read as a point: its range is lost');
        }
        $mapped = Attrs::withoutKeys(Attrs::withoutClasses(Attrs::of($node), ['comment-start']), ['data-comment-id']);

        return [['type' => 'critic_comment', 'value' => $value] + Attrs::renamedKeys($mapped, self::RANGED_COMMENT_KEYS)];
    }

    /**
     * Whether a node in document order from `from` up to `to` gives an inline.
     */
    private function rangeHolds(int $from, int $to): bool
    {
        return $this->holding[$to] > $this->holding[$from];
    }

    /**
     * Whether the node gives an inline by itself: a text, a comment or raw element kept as raw HTML, a ranged comment
     * with text, or a block without children other than an empty inline container without attributes. Null for an
     * inline element, which `keeps()` decides once its content is walked.
     */
    private function holds(HtmlNode $node): ?bool
    {
        if ($node->isText()) {
            return $node->name !== '';
        }
        if ($node->isComment()) {
            return $this->context->rawHtml;
        }
        if (!$node->isElement()) {
            return false;
        }
        $kind = Elements::treatment($node);
        if ($kind === 'drop') {
            return false;
        }
        if ($kind === 'raw' || $node->name === 'input') {
            return $this->context->rawHtml;
        }
        if (self::isRangeStart($node)) {
            return $node->plain() !== '';
        }

        if (Elements::isBlock($node)) {
            return $node->children === [] && ($node->attrs !== [] || !isset(self::EMPTY_DROPPED[$node->name]));
        }

        return null;
    }

    /**
     * C2-M-069: whether the inline reader keeps a node for the element. When its content gives no inline, the element
     * is read whole, with the descendants already found empty skipped; otherwise it is read without its content, which
     * counts on its own. The read is a probe: its reports and the keys it records are taken back.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param bool $contentHolds
     * @param \SplObjectStorage<\MarkupCarve\Carve\Import\HtmlNode, true> $empty
     */
    private function keeps(HtmlNode $node, bool $contentHolds, SplObjectStorage $empty): bool
    {
        $read = $node;
        if ($contentHolds) {
            $read = clone $node;
            $read->children = [];
        }
        $context = $this->context;
        [$diagnostics, $anchorKeys, $definitions] = [$context->diagnostics, $context->anchorKeys, $context->definitions];
        $this->reader->skip = $empty;
        $kept = $this->reader->inline($read) !== [];
        $this->reader->skip = null;
        [$context->diagnostics, $context->anchorKeys, $context->definitions] = [$diagnostics, $anchorKeys, $definitions];

        return $kept;
    }

    /**
     * An element whose content gives no inline of its own: it is dropped, kept whole as raw, or a comment's text.
     */
    private static function isOpaque(HtmlNode $node): bool
    {
        if (!$node->isElement()) {
            return false;
        }
        $kind = Elements::treatment($node);

        return $kind === 'drop' || $kind === 'raw' || $node->name === 'input' || self::isRangeStart($node);
    }

    private static function isRangeStart(HtmlNode $node): bool
    {
        return $node->isElement('span') && ($node->classes()[0] ?? '') === 'comment-start';
    }

    /**
     * Records a `comment-end` span or a range start at its place; a claimed end gives nothing, other nodes as `holds()`.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param int $place
     * @param array<string, true> $pending The ids of the starts that no end has claimed yet.
     */
    private function enter(HtmlNode $node, int $place, array &$pending): ?bool
    {
        $id = $node->attr('data-comment-id') ?? "\0";
        if ($node->isElement('span') && ($node->classes()[0] ?? '') === 'comment-end') {
            $this->ends[$id][] = [$place, $node];
            if (isset($pending[$id])) {
                unset($pending[$id]);

                return false;
            }
        } elseif (self::isRangeStart($node)) {
            $pending[$id] = true;
        }

        return $this->holds($node);
    }

    /**
     * Decides an inline element once its content is walked, counting it in `held`; one that gives nothing and holds
     * nothing is left out of later probes.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param int $held The inlines found so far.
     * @param int $before The inlines found before the element's content.
     * @param \SplObjectStorage<\MarkupCarve\Carve\Import\HtmlNode, true> $empty
     */
    private function settle(HtmlNode $node, int &$held, int $before, SplObjectStorage $empty): bool
    {
        $kept = $this->keeps($node, $held > $before, $empty);
        if ($kept) {
            $held++;
        } elseif ($held === $before) {
            $empty[$node] = true;
        }

        return $kept;
    }

    /**
     * One walk of the document for every ranged comment: each node's place in document order and the last place
     * of its descendants, and the `comment-end` spans by `data-comment-id` in that order. Only what the import keeps
     * counts as an inline: the walk skips the content of opaque elements, and an end a start before it claims is dropped.
     *
     * @return \SplObjectStorage<\MarkupCarve\Carve\Import\HtmlNode, array{0: int, 1: int}>
     */
    private function indexDocument(HtmlNode $node): SplObjectStorage
    {
        if ($this->order !== null && isset($this->order[$node])) {
            return $this->order;
        }
        $root = $node;
        while ($root->parent !== null) {
            $root = $root->parent;
        }
        $this->order = new SplObjectStorage();
        $this->ends = [];
        $own = [];
        $held = 0;
        /** @var \SplObjectStorage<\MarkupCarve\Carve\Import\HtmlNode, true> $empty */
        $empty = new SplObjectStorage();
        $pending = [];
        $count = 0;
        $stack = [[$root, false, 0]];
        while ($stack !== []) {
            [$at, $closing, $before] = array_pop($stack);
            if ($closing) {
                $first = $this->order[$at][0];
                $this->order[$at] = [$first, $count - 1];
                if ($own[$first] === null) {
                    $own[$first] = $this->settle($at, $held, $before, $empty);
                }

                continue;
            }
            $this->order[$at] = [$count, $count];
            $own[$count] = $this->enter($at, $count, $pending);
            if ($own[$count] === true) {
                $held++;
            }
            $count++;
            $stack[] = [$at, true, $held];
            if (self::isOpaque($at)) {
                continue;
            }
            for ($i = count($at->children) - 1; $i >= 0; $i--) {
                $stack[] = [$at->children[$i], false, 0];
            }
        }
        $this->holding = [0];
        foreach ($own as $i => $gives) {
            $this->holding[$i + 1] = $this->holding[$i] + ($gives ? 1 : 0);
        }

        return $this->order;
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    private function mathSpan(HtmlNode $node, string $display): ?array
    {
        $value = $node->plain();
        [$open, $close] = $display === 'display' ? ['\\[', '\\]'] : ['\\(', '\\)'];
        if (strlen($value) < 4 || !str_starts_with($value, $open) || !str_ends_with($value, $close)) {
            return null;
        }
        $mapped = Attrs::withoutKeys(Attrs::withoutClasses(Attrs::of($node), ['math', 'inline', 'display']), ['role']);

        return [['type' => 'math', 'display' => $display === 'display', 'value' => substr($value, 2, -2)] + $mapped];
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    private function social(HtmlNode $node, string $kind): ?array
    {
        $strong = $node->elements();
        $name = $node->plain();
        $sigil = $kind === 'mention' ? '@' : '#';
        if (count($strong) !== 1 || $strong[0]->name !== 'strong' || !str_starts_with($name, $sigil) || strlen($name) === 1) {
            return null;
        }
        $mapped = Attrs::withoutClasses(Attrs::of($node), [$kind]);

        return [
            $kind === 'tag' ? ['type' => 'tag', 'name' => substr($name, 1)] + $mapped
            : ['type' => 'mention', 'target' => substr($name, 1), 'kind' => 'user'] + $mapped,
        ];
    }

    /**
     * C2-M-051: a destination is tested with the default deny list.
     */
    private function safe(HtmlNode $node, ?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $this->policy ??= Options::from([]);
        if (UrlPolicy::passes($url, $this->policy)) {
            return $url;
        }
        $this->context->report($node, 'import-destination-denied', 'Denied URL scheme in ' . $node->name);

        return null;
    }

    /**
     * C2-H-186: the key and form of a reference; a malformed or contradicted carrier is not read.
     *
     * @return array{key: string, refForm: string}|null
     */
    private function reference(HtmlNode $node): ?array
    {
        $key = $node->attr('data-carve-ref');
        $collapsed = $node->has('data-carve-ref-collapsed');
        $shortcut = $node->has('data-carve-ref-shortcut');
        if ($key === null && !$collapsed && !$shortcut) {
            return null;
        }
        $fault = match (true) {
            $key === null => [$collapsed ? 'data-carve-ref-collapsed' : 'data-carve-ref-shortcut', 'there is no reference key'],
            $key === '' || Chars::fold($key) !== $key => ['data-carve-ref', 'the key is not folded'],
            count(self::linkForms($node)) > 1 => ['data-carve-ref', 'the link has one form'],
            default => null,
        };
        if ($fault !== null) {
            $this->context->ignoredCarrier($node, $fault[0], $fault[1]);

            return null;
        }
        if ($collapsed && $shortcut) {
            $this->context->ignoredCarrier($node, 'data-carve-ref-collapsed', 'a reference has one form');
            $this->context->ignoredCarrier($node, 'data-carve-ref-shortcut', 'a reference has one form');
        }
        $form = $collapsed && !$shortcut ? 'collapsed' : ($shortcut && !$collapsed ? 'shortcut' : 'full');

        return ['key' => $key, 'refForm' => $form];
    }

    /**
     * C2-H-191, C2-H-192: the first reference of each key gives its definition back.
     */
    private function define(string $key, ?string $href, HtmlNode $node): void
    {
        if ($href === null || isset($this->context->definitions[$key])) {
            return;
        }
        if ($node->name === 'a' && str_starts_with($href, '#')) {
            $this->context->anchorKeys[$key] = true;
        }
        $definition = ['type' => 'link_reference_definition', 'key' => $key, 'namespace' => 'link', 'href' => $href];
        $tooltip = $node->attr('title');
        if ($tooltip !== null) {
            $definition['tooltip'] = $tooltip;
        }
        $this->context->definitions[$key] = $definition;
    }

    /**
     * C2-M-050: `<a>` with an `href` is a link; without one, or with a denied one, it is its content (C2-W-011).
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<array<string, mixed>> $children
     *
     * @return array<array<string, mixed>>
     */
    public function link(HtmlNode $node, array $children): array
    {
        $href = $this->safe($node, $node->attr('href'));
        $ref = $this->reference($node);
        $out = ['type' => 'link', 'children' => $children];
        if ($href !== null) {
            $out['href'] = $href;
        }
        if ($ref !== null) {
            $this->define($ref['key'], $href, $node);
            $out += ['key' => $ref['key'], 'namespace' => 'link', 'refForm' => $ref['refForm']];
        } elseif ($node->attr('title') !== null) {
            $out['tooltip'] = $node->attr('title');
        }
        $out += Attrs::of($node, ['href', 'title']);
        if ($href !== null || $ref !== null) {
            return [$out];
        }

        return isset($out['attrs']) ? [['type' => 'span', 'children' => $children, 'attrs' => $out['attrs']]] : $children;
    }

    /**
     * C2-M-050: `<img>` is an image; one whose `src` fails is its alternative text.
     *
     * @return array<array<string, mixed>>
     */
    public function image(HtmlNode $node): array
    {
        $src = $this->safe($node, $node->attr('src'));
        $long = $this->safe($node, $node->attr('longdesc'));
        $ref = $this->reference($node);
        $out = ['type' => 'image'];
        if ($src !== null) {
            $out['src'] = $src;
        }
        // C2-A-115: an empty alt is decorative, a missing one leaves the alt text unknown.
        $alt = $node->attr('alt');
        if ($alt === '') {
            $out['decorative'] = true;
        } elseif ($alt !== null) {
            $out['alt'] = $alt;
        }
        if ($ref !== null) {
            $this->define($ref['key'], $src, $node);
            $out += ['key' => $ref['key'], 'namespace' => 'link', 'refForm' => $ref['refForm']];
        } elseif ($node->attr('title') !== null) {
            $out['tooltip'] = $node->attr('title');
        }
        if ($long !== null) {
            $link = ['type' => 'link', 'href' => $long, 'children' => [['type' => 'text', 'value' => $long]]];
            $out['longDescription'] = [['type' => 'paragraph', 'children' => [$link]]];
        }
        $out += Attrs::of($node, ['src', 'alt', 'title', 'longdesc']);
        if ($src !== null || $ref !== null) {
            return [$out];
        }
        $alt = isset($out['alt']) ? [['type' => 'text', 'value' => $out['alt']]] : [];

        return isset($out['attrs']) ? [['type' => 'span', 'children' => $alt, 'attrs' => $out['attrs']]] : $alt;
    }
}
