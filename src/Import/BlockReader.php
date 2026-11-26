<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Generated\Tables;

/**
 * Blocks: the flow of C2-M-011 to C2-M-013, the block elements (C2-M-023 to C2-M-030), the conventions of C2-M-035 on
 * blocks, and the elements without a counterpart read as divs (C2-M-056 to C2-M-060).
 */
final class BlockReader
{
    public readonly InlineReader $inline;

    public readonly ListReader $lists;

    public readonly TableReader $tables;

    private ImportContext $context;

    public function __construct(InlineReader $inline)
    {
        $this->inline = $inline;
        $this->context = $inline->context;
        $this->lists = new ListReader($this);
        $this->tables = new TableReader($this);
    }

    /**
     * C2-M-012, C2-M-013: the blocks of a flow; a run of text and inline elements is one paragraph.
     *
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $nodes
     * @param \MarkupCarve\Carve\Import\HtmlNode $host
     * @param bool $top Read the children of the document, where templates stand (C2-H-187).
     *
     * @return array<array<string, mixed>>
     */
    public function flow(array $nodes, HtmlNode $host, bool $top = false): array
    {
        $out = [];
        $run = [];
        foreach ($nodes as $node) {
            if (self::isPhrasing($node)) {
                $run[] = $node;

                continue;
            }
            array_push($out, ...$this->run($run, $host, $top));
            $run = [];
            array_push($out, ...($top ? $this->documentChild($node) : $this->block($node)));
        }

        return array_merge($out, $this->run($run, $host, $top));
    }

    private static function isPhrasing(HtmlNode $node): bool
    {
        return $node->isText() || $node->isComment() || ($node->isElement() && !Elements::isBlock($node));
    }

    /**
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $run
     * @param \MarkupCarve\Carve\Import\HtmlNode $host
     * @param bool $top
     *
     * @return array<array<string, mixed>>
     */
    private function run(array $run, HtmlNode $host, bool $top): array
    {
        $solid = array_values(array_filter($run, static fn (HtmlNode $n): bool => !$n->isBlank()));
        if ($solid === []) {
            return [];
        }
        $raw = true;
        $images = false;
        $imageRun = true;
        foreach ($solid as $node) {
            $raw = $raw && Elements::isRaw($node);
            $images = $images || $node->isElement('img');
            $imageRun = $imageRun && ($node->isElement('img') || $node->isComment());
        }
        if ($raw || ($images && $imageRun)) {
            $out = [];
            foreach ($solid as $node) {
                array_push($out, ...($top ? $this->documentChild($node) : $this->block($node)));
            }

            return $out;
        }
        $inline = [];
        foreach ($run as $node) {
            array_push($inline, ...$this->inline->inline($node));
        }
        $children = Whitespace::edges(Whitespace::breaks(Whitespace::coalesce($inline), $host, $this->context), $host, $this->context);

        return $children === [] ? [] : [self::paragraph($children)];
    }

    /**
     * C2-M-023, C2-A-184: a paragraph of one display formula has the role `math`.
     *
     * @param array<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    public static function paragraph(array $children): array
    {
        $only = count($children) === 1 ? $children[0] : null;
        if ($only !== null && $only['type'] === 'math' && $only['display']) {
            return ['type' => 'paragraph', 'role' => 'math', 'children' => $children];
        }

        return ['type' => 'paragraph', 'children' => Whitespace::coalesce($children)];
    }

    /**
     * The children of the document, and of a section a render opened in it (C2-H-026), where templates stand.
     *
     * @return array<array<string, mixed>>
     */
    public function documentChild(HtmlNode $node): array
    {
        if (!$node->isElement()) {
            return $this->block($node);
        }
        if ($node->name === 'template' && $this->context->templates->offsetExists($node)) {
            return $this->context->templates[$node];
        }
        if ($node->name === 'section' && !$node->foreign) {
            return $this->section($node, true);
        }
        if ($node->name === 'html' || $node->name === 'body') {
            $this->wrapperAttributes($node);

            return $this->flow($node->children, $node, true);
        }

        return $this->block($node);
    }

    /**
     * C2-M-004: of `<html>` and `<body>` only `lang` and `dir` reach the document node.
     */
    private function wrapperAttributes(HtmlNode $node): void
    {
        foreach ($node->attrs as $name => $value) {
            $name = (string)$name;
            if ($name !== 'lang' && $name !== 'dir' && Attrs::dropped($name, $value) === null) {
                $this->context->report($node, 'import-attribute-dropped', 'Attribute ' . $name . ' of ' . $node->name . ' dropped');
            }
        }
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function blocks(HtmlNode $node): array
    {
        return $this->flow($node->children, $node);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function block(HtmlNode $node): array
    {
        if ($node->isText()) {
            if ($node->isBlank()) {
                return [];
            }
            $children = Whitespace::edges(Whitespace::breaks(Whitespace::soft($node->name), $node, $this->context), $node, $this->context);

            return [self::paragraph($children)];
        }
        if ($node->isComment()) {
            return Elements::keepsRaw($node, $this->context) ? [self::rawBlock($node)] : [];
        }
        if ($node->isElement()) {
            return $this->element($node);
        }
        $this->context->report($node, 'import-element-dropped', 'Doctype dropped');

        return [];
    }

    /**
     * C2-M-065: a raw block value ends with a line feed (C2-A-045).
     *
     * @return array<string, mixed>
     */
    private static function rawBlock(HtmlNode $node): array
    {
        $value = Elements::outer($node);

        return ['type' => 'raw_block', 'format' => 'html', 'value' => str_ends_with($value, "\n") ? $value : $value . "\n"];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function element(HtmlNode $node): array
    {
        $use = Snippets::block($node, $this->context);
        if ($use !== null) {
            return $use;
        }
        $treated = $this->treated($node);
        if ($treated !== null) {
            return $treated;
        }
        $simple = $this->simple($node);
        if ($simple !== null) {
            return $simple;
        }

        return match ($node->name) {
            'ul', 'ol', 'menu' => $this->lists->list($node),
            'dl' => $this->lists->definitionList($node),
            'pre' => [$this->pre($node)],
            'table' => [$this->tables->table($node)],
            'figure' => [$this->figure($node)],
            'div' => [$this->container($node)],
            default => $this->fallback($node),
        };
    }

    /**
     * C2-M-056, C2-M-058: the treatment of an element among blocks, before its row reads it.
     *
     * @return array<array<string, mixed>>|null
     */
    private function treated(HtmlNode $node): ?array
    {
        $kind = Elements::treatment($node);
        if ($kind === 'drop') {
            // C2-M-005: the head is reported once; an empty one, which the parser implies, is no fact.
            if ($node->name !== 'head' || $node->children !== [] || $node->attrs !== []) {
                Elements::drop($node, $this->context);
            }

            return [];
        }
        if ($kind === 'raw' || Elements::isRawSpan($node)) {
            $carrier = Templates::carrierOf($node);
            if ($carrier !== null) {
                $this->context->ignoredCarrier($node, $carrier, 'a template is read only at the top level of the flow');
            }

            return Elements::keepsRaw($node, $this->context) ? [self::rawBlock($node)] : [];
        }
        if ($kind === 'unwrap' || Elements::isOrphan($node)) {
            if ($node->name !== 'html' && $node->name !== 'body') {
                $this->context->report($node, 'import-element-mapped', 'HTML ' . $node->name . ' unwrapped');
            }

            return $this->blocks($node);
        }
        if ($kind !== 'div') {
            return null;
        }
        $this->context->report($node, 'import-element-mapped', 'HTML ' . $node->name . ' read as a div');

        return [['type' => 'div', 'children' => $this->divChildren($node)] + Attrs::of($node)];
    }

    /**
     * C2-M-059: the first `<summary>` of a `<details>` and the `<legend>` of a `<fieldset>` are its first paragraph.
     *
     * @return array<array<string, mixed>>
     */
    private function divChildren(HtmlNode $node): array
    {
        $lead = ['details' => 'summary', 'fieldset' => 'legend'][$node->name] ?? null;
        $first = $lead === null ? null : $node->firstElement($lead);
        if ($first === null) {
            return $this->blocks($node);
        }
        $rest = array_values(array_filter($node->children, static fn (HtmlNode $n): bool => $n !== $first));
        $title = Whitespace::edges($this->inline->children($first), $first, $this->context);

        return array_merge($title === [] ? [] : [self::paragraph($title)], $this->flow($rest, $node));
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    private function simple(HtmlNode $node): ?array
    {
        return match ($node->name) {
            'p' => $this->withContent($this->paragraphElement($node), $node),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->withContent($this->heading($node, (int)$node->name[1]), $node),
            'hr' => [['type' => 'thematic_break'] + Attrs::of($node)],
            'blockquote' => [['type' => 'block_quote', 'children' => $this->blocks($node)] + Attrs::of($node)],
            'section' => $this->section($node, false),
            'aside' => $this->admonition($node),
            default => null,
        };
    }

    /**
     * C2-M-069: a paragraph, heading or term with no content is dropped; a drop that loses an attribute is reported.
     *
     * @param array<string, mixed> $block
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     *
     * @return array<array<string, mixed>>
     */
    public function withContent(array $block, HtmlNode $node): array
    {
        if ($block['children'] !== []) {
            return [$block];
        }
        if (isset($block['attrs']) || isset($block['lang']) || isset($block['dir'])) {
            $this->context->report($node, 'import-structure-repaired', 'Empty HTML ' . $node->name . ' with attributes dropped');
        }

        return [];
    }

    /**
     * C2-M-023, C2-M-075: a `<p>`; one whose only content is one image is an image paragraph (C2-H-020).
     *
     * @return array<string, mixed>
     */
    private function paragraphElement(HtmlNode $node): array
    {
        $children = $this->inline->children($node);
        $only = count($children) === 1 ? $children[0] : null;
        $base = $only !== null && $only['type'] === 'image' && ($only['refForm'] ?? null) !== 'shortcut'
            ? ['type' => 'paragraph', 'role' => 'image', 'children' => $children] : self::paragraph($children);

        return $base + Attrs::of($node, [], true, $this->context);
    }

    /**
     * C2-M-024, C2-M-025: a heading, its level above six, and its id.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param int $level
     * @param array{0: string|null}|null $id The id the section decided, or null to read the element's own.
     *
     * @return array<string, mixed>
     */
    private function heading(HtmlNode $node, int $level, ?array $id = null): array
    {
        [$authored] = $id ?? $this->headingId($node);
        $omit = $level > 6 ? ['id', 'role', 'aria-level'] : ['id'];
        $omit[] = 'data-carve-source-level';
        $level = $this->sourceLevel($node, $level);
        $mapped = Attrs::of($node, $omit, true, $this->context);
        if ($authored !== null) {
            $mapped['attrs'] = ['id' => $authored] + J::attrs($mapped);
        }

        return ['type' => 'heading', 'level' => $level, 'children' => $this->inline->children($node)] + $mapped;
    }

    /**
     * C2-H-186, C2-H-208: `data-carve-source-level`, the level of a heading before `headingShift`.
     */
    private function sourceLevel(HtmlNode $node, int $level): int
    {
        $value = $node->attr('data-carve-source-level');
        if ($value === null) {
            return $level;
        }
        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            $this->context->ignoredCarrier($node, 'data-carve-source-level', 'the value is no decimal level');

            return $level;
        }
        if (strlen($value) > strlen((string)$level) || (int)$value >= $level) {
            $this->context->ignoredCarrier($node, 'data-carve-source-level', 'the value is not below the level of the element');

            return $level;
        }

        return (int)$value;
    }

    /**
     * C2-H-186: `data-carve-explicit-id` and `data-carve-generated-id`.
     *
     * @return array{0: string|null}
     */
    private function headingId(HtmlNode $node): array
    {
        $id = $node->attr('id');
        $explicit = $node->has('data-carve-explicit-id');
        $generated = $node->has('data-carve-generated-id');
        if ($explicit && $generated) {
            $this->context->ignoredCarrier($node, 'data-carve-explicit-id', 'an id is authored or generated, not both');
            $this->context->ignoredCarrier($node, 'data-carve-generated-id', 'an id is authored or generated, not both');

            return [$id];
        }
        if (($explicit || $generated) && ($id === null || $id === '')) {
            $name = $explicit ? 'data-carve-explicit-id' : 'data-carve-generated-id';
            $this->context->ignoredCarrier($node, $name, 'the element has no id');

            return [null];
        }

        return [$generated ? null : $id];
    }

    private static function headingLevel(HtmlNode $node): ?int
    {
        if (!$node->foreign && preg_match('/\Ah[1-6]\z/', $node->name) === 1) {
            return (int)$node->name[1];
        }
        if (!$node->isElement('div') || $node->attr('role') !== 'heading') {
            return null;
        }
        $level = TableReader::htmlInteger($node->attr('aria-level'));

        return $level !== null && $level > 6 ? $level : null;
    }

    /**
     * C2-M-026 to C2-M-028, C2-M-061: a section a render opens at a heading, a footnote section, or a div.
     *
     * @return array<array<string, mixed>>
     */
    private function section(HtmlNode $node, bool $top): array
    {
        if (Notes::isSection($node)) {
            return $this->footnoteSection($node);
        }
        $elements = $node->elements();
        $first = $elements[0] ?? null;
        $level = $first === null ? null : self::headingLevel($first);
        $at = $first === null ? 0 : (int)array_search($first, $node->children, true);
        $before = array_slice($node->children, 0, $at);
        if ($first === null || $level === null || array_filter($before, static fn (HtmlNode $n): bool => !$n->isBlank()) !== []) {
            foreach (['data-carve-explicit-id', 'data-carve-generated-id'] as $name) {
                if ($node->has($name)) {
                    $this->context->ignoredCarrier($node, $name, 'the section opens with no heading');
                }
            }
            $this->context->report($node, 'import-element-mapped', 'HTML section without a heading read as a div');

            return [['type' => 'div', 'children' => $this->blocks($node)] + Attrs::of($node)];
        }
        $this->sectionAttributes($node);
        $heading = $this->heading($first, $level, $this->sectionId($node, $first));

        return array_merge([$heading], $this->flow(array_slice($node->children, $at + 1), $node, $top));
    }

    /**
     * C2-M-027: a render writes no attribute but the id on the section it opens at a heading.
     */
    private function sectionAttributes(HtmlNode $node): void
    {
        foreach ($node->attrs as $name => $value) {
            $name = (string)$name;
            if ($name === 'id' || Attrs::isCarrier($name) || Attrs::dropped($name, $value) !== null) {
                continue;
            }
            $this->context->report($node, 'import-attribute-dropped', 'Attribute ' . $name . ' of a section dropped');
        }
    }

    /**
     * C2-M-026: the id of the section is the heading's, unless the heading carries an id of its own that differs.
     *
     * @return array{0: string|null}|null
     */
    private function sectionId(HtmlNode $node, HtmlNode $first): ?array
    {
        $own = $first->attr('id');
        $outer = $node->attr('id');
        if ($own === null || $own === '') {
            return $this->headingId($node);
        }
        if ($outer !== null && $outer !== $own) {
            $message = 'Attribute id of a section dropped: its heading carries the id ' . $own;
            $this->context->report($node, 'import-attribute-dropped', $message);
        }

        return null;
    }

    /**
     * C2-M-061, C2-M-064: the notes of the footnote section; a section with a title, a label or blocks after it gives a
     * `footnotes` directive at its place.
     *
     * @return array<array<string, mixed>>
     */
    private function footnoteSection(HtmlNode $node): array
    {
        $ordered = $node->firstElement('ol');
        if ($ordered === null) {
            $this->context->report($node, 'import-structure-repaired', 'Footnote section has no ordered list');

            return [];
        }
        foreach ($ordered->elements() as $item) {
            if ($item->name !== 'li') {
                continue;
            }
            $id = $item->attr('id');
            if ($id === null || $id === '') {
                $this->context->report($item, 'import-structure-repaired', 'Footnote has no id');

                continue;
            }
            $note = $this->context->notes[$id] ?? null;
            if ($note !== null && $note['inline']) {
                continue;
            }
            $key = $note['label'] ?? InlineCarriers::footnoteKey($id);
            $note = ['type' => 'footnote', 'key' => $key, 'namespace' => 'footnote'];
            $this->context->footnotes[] = $note + ['children' => $this->blocks($item)];
        }

        return $this->notesDirective($node);
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function notesDirective(HtmlNode $node): array
    {
        $parts = $this->titled($node);
        $siblings = $node->parent === null ? [] : $node->parent->children;
        $behind = false;
        foreach (array_slice($siblings, (int)array_search($node, $siblings, true) + 1) as $sibling) {
            if ($sibling->isBlank()) {
                continue;
            }
            // A later untitled note section contributes definitions, not a block after this placement point.
            if (Notes::isSection($sibling)) {
                $later = $this->titled($sibling);
                if (!isset($later['title']) && !isset($later['groupLabel'])) {
                    continue;
                }
            }
            $behind = true;

            break;
        }
        if (!isset($parts['title']) && !isset($parts['groupLabel']) && !$behind) {
            return [];
        }
        unset($parts['rest']);

        return [['type' => 'directive', 'kind' => 'footnotes', 'children' => []] + $parts];
    }

    /**
     * C2-H-154: `<p class="admonition-title">` and `<p class="div-label">` lead the body of a div or admonition.
     *
     * @return array{title?: array<array<string, mixed>>, groupLabel?: string, rest: array<\MarkupCarve\Carve\Import\HtmlNode>}
     */
    private function titled(HtmlNode $node): array
    {
        $nodes = $node->children;
        $count = count($nodes);
        $index = 0;
        $out = [];
        foreach (['admonition-title' => 'title', 'div-label' => 'groupLabel'] as $class => $field) {
            while ($index < $count && $nodes[$index]->isBlank()) {
                $index++;
            }
            $candidate = $nodes[$index] ?? null;
            if ($candidate === null || !$candidate->isElement('p') || $candidate->attr('class') !== $class) {
                continue;
            }
            $index++;
            $out[$field] = $field === 'title' ? $this->inline->children($candidate) : $candidate->plain();
        }
        $out['rest'] = array_slice($nodes, $index);

        return $out;
    }

    /**
     * C2-M-035, C2-M-060: an admonition, or a div.
     *
     * @return array<array<string, mixed>>
     */
    private function admonition(HtmlNode $node): array
    {
        $classes = $node->classes();
        $kind = $classes[1] ?? '';
        if (($classes[0] ?? null) !== 'admonition' || (Tables::DIV_NAME_KINDS[$kind] ?? null) !== 'admonition') {
            return [$this->container($node)];
        }
        $parts = $this->titled($node);
        $rest = $parts['rest'];
        unset($parts['rest']);
        $generated = $this->context->labels['admonition' . ucfirst($kind)] ?? null;
        $omit = $node->attr('aria-label') === $generated ? ['aria-label', 'aria-labelledby'] : ['aria-labelledby'];
        $mapped = Attrs::withoutKeys(Attrs::withoutClasses(Attrs::of($node), ['admonition', $kind]), $omit);

        return [['type' => 'admonition', 'kind' => $kind] + $parts + ['children' => $this->flow($rest, $node)] + $mapped];
    }

    /**
     * C2-M-024, C2-M-029, C2-H-186: a `<div>`: a heading, a line block, a directive or a div.
     *
     * @return array<string, mixed>
     */
    private function container(HtmlNode $node): array
    {
        $level = self::headingLevel($node);
        if ($level !== null) {
            return $this->heading($node, $level);
        }
        if ($node->has('data-carve-line-block')) {
            $fault = self::lineBlockFault($node);
            if ($fault === null) {
                return $this->lineBlock($node);
            }
            $this->context->ignoredCarrier($node, 'data-carve-line-block', $fault);
        }
        if ($node->has('data-carve-div') && count($node->attrs) > 1) {
            $this->context->ignoredCarrier($node, 'data-carve-div', 'the div carries other attributes');
        }
        $parts = $this->titled($node);
        $rest = $parts['rest'];
        unset($parts['rest']);
        $mapped = Attrs::of($node, [], true, $this->context);
        $kind = J::classes(J::attrs($mapped))[0] ?? '';
        if ((Tables::DIV_NAME_KINDS[$kind] ?? null) === 'directive') {
            return ['type' => 'directive', 'kind' => $kind] + $parts + ['children' => $this->flow($rest, $node)]
                + Attrs::withoutClasses($mapped, [$kind]);
        }

        return ['type' => 'div'] + $parts + ['children' => $this->flow($rest, $node)] + $mapped;
    }

    private static function lineBlockFault(HtmlNode $node): ?string
    {
        if ($node->has('data-carve-div')) {
            return 'the element is also a div';
        }
        if (!in_array('line-block', $node->classes(), true)) {
            return 'the element lacks the class line-block';
        }
        foreach ($node->children as $child) {
            if (!$child->isBlank() && !$child->isElement('p')) {
                return 'the element holds more than paragraphs';
            }
        }

        return null;
    }

    /**
     * C2-H-043: one paragraph per stanza, a line per `<br>`, and the no-break spaces of the verse whitespace as spaces.
     * A `<br>` with only whitespace after it is the hard break of the stanza's last line; a line that is empty or ends
     * in a space or tab keeps the hard break its `<br>` stands for, as it cannot be written without it (C2-H-195 row 18).
     *
     * @return array<string, mixed>
     */
    private function lineBlock(HtmlNode $node): array
    {
        $lines = [];
        foreach ($node->elements() as $stanza) {
            if ($lines !== []) {
                $lines[] = ['type' => 'line', 'children' => []];
            }
            $pieces = [[]];
            foreach ($stanza->children as $child) {
                if ($child->isElement('br')) {
                    $pieces[] = [];
                } else {
                    $pieces[count($pieces) - 1][] = $child;
                }
            }
            $closing = count($pieces) > 1 && self::blank($pieces[count($pieces) - 1]);
            if ($closing) {
                array_pop($pieces);
            }
            $last = count($pieces) - 1;
            foreach ($pieces as $i => $piece) {
                $children = $this->lineInline($piece, $stanza);
                if ($i === $last ? $closing : self::needsHardBreak($children)) {
                    $children[] = ['type' => 'hard_break'];
                }
                $lines[] = ['type' => 'line', 'children' => $children];
            }
        }

        return ['type' => 'line_block', 'children' => $lines] + Attrs::withoutClasses(Attrs::of($node), ['line-block']);
    }

    /**
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $nodes
     *
     * @return bool
     */
    private static function blank(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!$node->isBlank()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array<string, mixed>> $children
     *
     * @return bool
     */
    private static function needsHardBreak(array $children): bool
    {
        if ($children === []) {
            return true;
        }
        $end = $children[count($children) - 1];

        return $end['type'] === 'text' && in_array(substr(J::str($end, 'value'), -1), [' ', "\t"], true);
    }

    /**
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $nodes
     * @param \MarkupCarve\Carve\Import\HtmlNode $host
     *
     * @return array<array<string, mixed>>
     */
    private function lineInline(array $nodes, HtmlNode $host): array
    {
        $children = [];
        foreach ($nodes as $node) {
            array_push($children, ...$this->inline->inline($node));
        }
        $children = Whitespace::coalesce($children);
        if (($children[0]['type'] ?? null) === 'soft_break') {
            array_shift($children);
        }
        $lead = true;
        $children = self::verseSpaces($children, $lead);

        return Whitespace::breaks(Whitespace::coalesce($children), $host, $this->context);
    }

    /**
     * C2-H-043 read back: a no-break space run the renderer wrote for spaces is spaces again. The renderer writes a
     * run as `&nbsp;` only when it leads the line or covers two or more columns, and always takes the whole space and
     * tab run, so a lone interior no-break space or one next to a space or tab was authored and stays.
     *
     * @param array<array<string, mixed>> $nodes
     * @param bool $lead
     *
     * @return array<array<string, mixed>>
     */
    private static function verseSpaces(array $nodes, bool &$lead): array
    {
        foreach ($nodes as $k => $node) {
            if ($node['type'] === 'text') {
                $value = J::str($node, 'value');
                if (str_contains($value, "\u{00A0}")) {
                    $first = $lead;
                    $nodes[$k]['value'] = preg_replace_callback(
                        '/(?<![ \t\x{00A0}])\x{00A0}+(?![ \t\x{00A0}])/u',
                        static fn (array $m): string => $m[0][1] === 0 && $first || strlen($m[0][0]) > 2
                            ? str_repeat(' ', strlen($m[0][0]) >> 1) : $m[0][0],
                        $value,
                        flags: PREG_OFFSET_CAPTURE,
                    ) ?? $value;
                }
            } elseif (isset($node['children'])) {
                $nodes[$k]['children'] = self::verseSpaces(J::nodes($node), $lead);
            }
            $lead = false;
        }

        return $nodes;
    }

    /**
     * C2-M-030: `<pre>` is a code block; `language-` gives the syntax; `data-carve-label` the label (C2-H-186).
     *
     * @return array<string, mixed>
     */
    private function pre(HtmlNode $node): array
    {
        $code = $node->firstElement('code');
        $classes = $code?->classes() ?? [];
        $language = null;
        foreach ($classes as $class) {
            if ($language === null && str_starts_with($class, 'language-')) {
                $language = substr($class, 9);
            }
        }
        $others = $code === null ? 0 : count(array_diff(array_keys($code->attrs), ['class']));
        if ($code !== null && ($others > 0 || count($classes) > ($language === null ? 0 : 1))) {
            $this->context->report($code, 'import-attribute-dropped', 'Code element attributes other than language dropped');
        }
        $value = $code === null ? $node->plain() : $code->plain();
        $out = ['type' => 'code_block', 'value' => $value !== '' && !str_ends_with($value, "\n") ? $value . "\n" : $value];
        if ($language !== null && $language !== '') {
            $out['syntax'] = $language;
        }
        $title = $node->attr('title');
        if ($title !== null) {
            $out['title'] = [['type' => 'text', 'value' => $title]];
        }
        $label = $this->codeLabel($node, $code);
        if ($label !== null) {
            $out['groupLabel'] = $label;
        }

        return $out + Attrs::of($node, $title === null ? [] : ['title']);
    }

    private function codeLabel(HtmlNode $node, ?HtmlNode $code): ?string
    {
        $label = $node->attr('data-carve-label');
        if ($label === null) {
            return null;
        }
        $only = $code !== null && array_filter($node->children, static fn (HtmlNode $n): bool => $n !== $code) === [];
        if ($only && strpbrk($label, "]\n\r") === false) {
            return $label;
        }
        $reason = $only ? 'the value is no fence label' : 'the element holds more than its code';
        $this->context->ignoredCarrier($node, 'data-carve-label', $reason);

        return null;
    }

    /**
     * C2-M-042, C2-M-043, C2-H-199: a figure, a figure group and its panels.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<string> $drop
     *
     * @return array<string, mixed>
     */
    public function figure(HtmlNode $node, array $drop = []): array
    {
        $group = ($node->classes()[0] ?? null) === 'carve-figure-group';
        $caption = $node->firstElement('figcaption');
        $nodes = array_values(array_filter($node->children, static fn (HtmlNode $n): bool => $n !== $caption));
        if ($group) {
            $children = [];
            foreach ($nodes as $child) {
                array_push($children, ...($child->isElement('figure') ? [$this->panel($child)] : $this->block($child)));
            }
        } else {
            $children = $this->flow($nodes, $node);
        }
        $captionPart = $caption === null ? [] : ['caption' => $this->caption($caption)];
        if ($group) {
            return ['type' => 'figure_group', 'children' => $children] + $captionPart
                + Attrs::withoutClasses(Attrs::of($node), ['carve-figure-group']);
        }
        $own = Attrs::withoutClasses(Attrs::of($node), $drop);
        $only = count($children) === 1 ? $children[0] : null;
        if ($only !== null && self::takesCaption($only, $caption !== null)) {
            return $this->mergeFigureAttributes($node, array_merge($only, $captionPart), $own);
        }

        return ['type' => 'figure_group', 'children' => $children] + $captionPart + $own;
    }

    /**
     * C2-M-043: figure attributes precede the captionable block's attributes; later scalar values win.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<string, mixed> $block
     * @param array<string, mixed> $figure
     *
     * @return array<string, mixed>
     */
    private function mergeFigureAttributes(HtmlNode $node, array $block, array $figure): array
    {
        $outer = J::attrs($figure);
        $inner = J::attrs($block);
        $attrs = $outer;
        if (J::classes($inner) !== []) {
            $attrs['classes'] = array_merge(J::classes($outer), J::classes($inner));
        }
        if (isset($inner['id'])) {
            if (isset($outer['id'])) {
                $this->context->report($node, 'import-attribute-dropped', 'Figure id replaced by the child id');
            }
            $attrs['id'] = $inner['id'];
        }
        $pairs = J::pairs($outer);
        foreach (J::pairs($inner) as [$key, $value]) {
            $found = false;
            foreach ($pairs as $index => [$oldKey]) {
                if ($oldKey === $key) {
                    $pairs[$index][1] = $value;
                    $this->context->report($node, 'import-attribute-dropped', 'Figure attribute ' . $key . ' replaced by the child');
                    $found = true;

                    break;
                }
            }
            if (!$found) {
                $pairs[] = [$key, $value];
            }
        }
        if ($pairs !== []) {
            $attrs['keyValues'] = $pairs;
        }
        if ($attrs !== []) {
            $block['attrs'] = $attrs;
        }
        foreach (['lang', 'dir'] as $field) {
            if (isset($figure[$field]) && !isset($block[$field])) {
                $block[$field] = $figure[$field];
            } elseif (isset($figure[$field], $block[$field])) {
                $this->context->report($node, 'import-attribute-dropped', 'Figure ' . $field . ' replaced by the child');
            }
        }

        return $block;
    }

    /**
     * C2-A-082: the blocks with a caption slot, which the figure's caption may fill.
     *
     * @param array<string, mixed> $block
     * @param bool $captioned
     *
     * @return bool
     */
    private static function takesCaption(array $block, bool $captioned): bool
    {
        $slot = in_array($block['type'], ['code_block', 'block_quote', 'table'], true)
            || ($block['type'] === 'paragraph' && isset($block['role']));

        return $slot && (!$captioned || !isset($block['caption']));
    }

    /**
     * @return array<string, mixed>
     */
    private function panel(HtmlNode $node): array
    {
        $inner = $node->elements();
        $panel = ($node->classes()[0] ?? null) === 'carve-figure-panel';
        if ($panel && count($inner) === 1 && $inner[0]->name === 'table' && count($node->attrs) === 1) {
            return $this->tables->table($inner[0]);
        }

        return $this->figure($node, $panel ? ['carve-figure-panel'] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function caption(HtmlNode $node): array
    {
        return ['type' => 'caption', 'children' => $this->blocks($node)] + Attrs::of($node);
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function fallback(HtmlNode $node): array
    {
        if ($node->name === 'img') {
            $image = $this->inline->carriers->image($node);
            if (count($image) === 1 && $image[0]['type'] === 'image') {
                return [['type' => 'paragraph', 'role' => 'image', 'children' => $image]];
            }

            return $image === [] ? [] : [self::paragraph($image)];
        }
        $children = Whitespace::edges($this->inline->inline($node), $node, $this->context);

        return $children === [] ? [] : [self::paragraph($children)];
    }
}
