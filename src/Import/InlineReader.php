<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use SplObjectStorage;

/**
 * Inline content: the inline elements (C2-M-044 to C2-M-049), the elements without a counterpart read as spans
 * (C2-M-056) and raw content in a run (C2-M-065).
 */
final class InlineReader
{
    /**
     * The elements whose inline content is the inline content of a block (C2-M-018).
     *
     * @var array<string, true>
     */
    private const EDGE = [
        'p' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'td' => true,
        'th' => true,
        'dt' => true,
        'caption' => true,
        'figcaption' => true,
    ];

    /**
     * @var array<string, string>
     */
    private const FORMATS = [
        'em' => 'emphasis',
        'i' => 'emphasis',
        'strong' => 'strong',
        'b' => 'strong',
        's' => 'strike',
        'strike' => 'strike',
        'del' => 'delete',
        'ins' => 'insert',
        'mark' => 'highlight',
        'u' => 'underline',
        'sub' => 'subscript',
        'sup' => 'superscript',
    ];

    /**
     * C2-M-069, C2-W-116: the inline nodes that need content.
     *
     * @var array<string, true>
     */
    private const NEEDS_CONTENT = [
        'emphasis' => true,
        'strong' => true,
        'strike' => true,
        'delete' => true,
        'insert' => true,
        'highlight' => true,
        'underline' => true,
        'subscript' => true,
        'superscript' => true,
        'span' => true,
        'link' => true,
    ];

    /**
     * C2-M-069, C2-W-116: the inline nodes whose value needs content.
     *
     * @var array<string, true>
     */
    private const VALUE_NEEDS_CONTENT = [
        'code' => true,
        'literal_inline' => true,
        'math' => true,
        'raw_inline' => true,
    ];

    /**
     * @var array<string>
     */
    private const TEX = ['application/x-tex', 'text/x-tex', 'LaTeX'];

    public readonly InlineCarriers $carriers;

    /**
     * During a probe of a ranged comment's range, the nodes already known to give nothing (C2-M-113).
     *
     * @var \SplObjectStorage<\MarkupCarve\Carve\Import\HtmlNode, true>|null
     */
    public ?SplObjectStorage $skip = null;

    /**
     * @var array<string, array<string, string>>
     */
    private array $texts = [];

    public function __construct(public readonly ImportContext $context)
    {
        $this->carriers = new InlineCarriers($this);
    }

    /**
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<\MarkupCarve\Carve\Import\HtmlNode>|null $nodes
     *
     * @return array<array<string, mixed>>
     */
    public function children(HtmlNode $node, ?array $nodes = null): array
    {
        $out = [];
        foreach ($nodes ?? $node->children as $child) {
            array_push($out, ...$this->inline($child));
        }
        $out = Whitespace::breaks(Whitespace::coalesce($out), $node, $this->context);

        return isset(self::EDGE[$node->name]) && !$node->foreign ? Whitespace::edges($out, $node, $this->context) : $out;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function inline(HtmlNode $node): array
    {
        if ($this->skip !== null && isset($this->skip[$node])) {
            return [];
        }
        if ($node->isText()) {
            if (strlen($node->name) <= 32 && strpbrk($node->name, "\r\n\0") === false) {
                // One shared array per short text, as HtmlImporter::shareTexts() leaves the tree (carve-php#5722).
                return $node->name === '' ? [] : [$this->texts[$node->name] ??= ['type' => 'text', 'value' => $node->name]];
            }

            return Whitespace::soft($node->name);
        }
        if ($node->isComment()) {
            return $this->raw($node);
        }
        if ($node->isElement()) {
            return $this->element($node);
        }
        $this->context->report($node, 'import-element-dropped', 'Doctype dropped');

        return [];
    }

    /**
     * C2-M-065, C2-M-066: a comment or raw element in inline content.
     *
     * @return array<array<string, mixed>>
     */
    public function raw(HtmlNode $node): array
    {
        if (!Elements::keepsRaw($node, $this->context)) {
            return [];
        }

        return [['type' => 'raw_inline', 'format' => 'html', 'value' => Elements::outer($node)]];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function element(HtmlNode $node): array
    {
        $use = Snippets::inline($node, $this->context);
        if ($use !== null) {
            return $use;
        }
        $own = $this->carriers->read($node);
        if ($own !== null) {
            return $this->nonEmpty($own, $node, true);
        }
        $kept = $this->treated($node) ?? $this->leaf($node);
        if ($kept !== null) {
            return $this->nonEmpty($kept, $node, true);
        }
        $children = $this->children($node);
        $type = self::FORMATS[$node->name] ?? null;
        if ($type !== null) {
            $mapped = Attrs::of($node);
            if ($type === 'insert' || $type === 'delete') {
                $mapped = Attrs::renamedKeys($mapped, ['data-author' => 'author']);
            }

            return $this->nonEmpty([['type' => $type, 'children' => $children] + $mapped], $node);
        }

        return match ($node->name) {
            'a' => $this->nonEmpty($this->carriers->link($node, $children), $node),
            'abbr' => $this->abbreviation($node, $children),
            'time', 'kbd' => [$this->semanticSpan($node, $children)],
            default => $this->nonEmpty([['type' => 'span', 'children' => $children] + Attrs::of($node)], $node),
        };
    }

    /**
     * C2-M-056, C2-M-058, C2-M-014: the treatment of an element in inline content, before its row reads it.
     *
     * @return array<array<string, mixed>>|null
     */
    private function treated(HtmlNode $node): ?array
    {
        $kind = Elements::treatment($node);
        if ($kind === 'drop') {
            Elements::drop($node, $this->context);

            return [];
        }
        // C2-M-066: an `<input>` that is no task box, which its item reads, is raw.
        if ($kind === 'raw' || $node->name === 'input') {
            return $this->raw($node);
        }
        if ($kind === 'unwrap' || Elements::isOrphan($node)) {
            if ($node->name !== 'html' && $node->name !== 'body') {
                $this->context->report($node, 'import-element-mapped', 'HTML ' . $node->name . ' unwrapped');
            }

            return $this->children($node);
        }
        if ($kind === 'map' && !Elements::isBlock($node)) {
            return null;
        }
        $this->context->report($node, 'import-element-mapped', 'HTML ' . $node->name . ' read as a span');

        return $this->nonEmpty([['type' => 'span', 'children' => $this->children($node)] + Attrs::of($node)], $node);
    }

    /**
     * The elements whose children are not read as inline content (C2-M-045 to C2-M-049).
     *
     * @return array<array<string, mixed>>|null
     */
    private function leaf(HtmlNode $node): ?array
    {
        return match ($node->name) {
            'br' => [['type' => 'hard_break']],
            'img' => $this->carriers->image($node),
            'math' => $this->math($node),
            'ruby' => $this->ruby($node),
            'code' => [['type' => 'code', 'value' => $node->plain()] + Attrs::of($node)],
            default => null,
        };
    }

    /**
     * C2-M-069: an inline node that needs content and has none is dropped, except a span with attributes.
     * With `$valuesOnly`, only the value inlines are checked.
     *
     * @param array<array<string, mixed>> $nodes
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param bool $valuesOnly
     *
     * @return array<array<string, mixed>>
     */
    public function nonEmpty(array $nodes, HtmlNode $node, bool $valuesOnly = false): array
    {
        $out = [];
        foreach ($nodes as $item) {
            $type = J::str($item, 'type');
            $empty = isset(self::VALUE_NEEDS_CONTENT[$type])
                ? ($item['value'] ?? null) === ''
                : !$valuesOnly && isset(self::NEEDS_CONTENT[$type]) && ($item['children'] ?? null) === [];
            if (!$empty) {
                $out[] = $item;

                continue;
            }
            $kept = isset($item['attrs']);
            if ($item['type'] === 'span' && ($kept || isset($item['lang']) || isset($item['dir']))) {
                $out[] = $item;

                continue;
            }
            if ($kept) {
                $this->context->report($node, 'import-structure-repaired', 'Empty HTML ' . $node->name . ' with attributes dropped');
            }
        }

        return $out;
    }

    /**
     * C2-M-047: an abbreviation that a template defines, or a span with the key `abbr`.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<array<string, mixed>> $children
     *
     * @return array<array<string, mixed>>
     */
    private function abbreviation(HtmlNode $node, array $children): array
    {
        $expansion = $node->attr('title');
        $key = $node->plain();
        $definition = $this->context->abbreviations[$key] ?? null;
        if ($definition !== null && ($definition['expansion'] ?? null) === $expansion && $node->elements() === []) {
            $abbreviation = ['type' => 'abbreviation', 'key' => $key, 'namespace' => 'abbr', 'expansion' => $expansion];

            return [$abbreviation + Attrs::of($node, ['title'])];
        }

        return [$this->semanticSpan($node, $children)];
    }

    /**
     * C2-M-047, C2-H-075: the span whose key names the element, with the value of the attribute it derives from.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private function semanticSpan(HtmlNode $node, array $children): array
    {
        $target = ['abbr' => 'title', 'time' => 'datetime'][$node->name] ?? null;
        $value = $target === null ? '' : ($node->attr($target) ?? '');
        $mapped = Attrs::of($node, $target === null ? [] : [$target]);
        $attrs = J::attrs($mapped);
        // C2-M-047: an authored attribute named like the built key keeps its place in upper case.
        $pairs = array_map(
            static fn (array $pair): array => $pair[0] === $node->name ? [strtoupper($pair[0]), $pair[1]] : $pair,
            J::pairs($attrs),
        );
        $attrs['keyValues'] = array_merge([[$node->name, $value]], $pairs);
        $mapped['attrs'] = $attrs;

        return ['type' => 'span', 'children' => $children] + $mapped;
    }

    /**
     * C2-M-049: MathML with a TeX source is math; without one it is its text.
     *
     * @return array<array<string, mixed>>
     */
    private function math(HtmlNode $node): array
    {
        $source = null;
        foreach ($node->elements() as $child) {
            foreach ($child->elements() as $grand) {
                if ($source === null && $grand->name === 'annotation' && in_array($grand->attr('encoding'), self::TEX, true)) {
                    $source = $grand->plain();
                }
            }
        }
        $source ??= $node->attr('alttext');
        if ($source !== null) {
            return [['type' => 'math', 'display' => $node->attr('display') === 'block', 'value' => $source]];
        }
        $this->context->report($node, 'import-element-mapped', 'MathML without a TeX source read as its text');

        return Whitespace::soft($node->plain());
    }

    /**
     * C2-M-048: each `<rt>` closes a pair; `<rp>` is dropped without a report; a base with no `<rt>` is text.
     *
     * @return array<array<string, mixed>>
     */
    private function ruby(HtmlNode $node): array
    {
        $pairs = [];
        $base = [];
        foreach ($node->children as $child) {
            if ($child->isElement('rt')) {
                $pairs[] = ['type' => 'ruby_pair', 'base' => Whitespace::coalesce($base), 'annotation' => $this->children($child)];
                $base = [];
            } elseif ($child->isElement('rb')) {
                array_push($base, ...$this->children($child));
            } elseif (!$child->isElement('rp')) {
                array_push($base, ...$this->inline($child));
            }
        }
        if ($base !== []) {
            $this->context->report($node, 'import-structure-repaired', 'Ruby base without annotation read as text');
        }

        return $pairs === [] ? $base : array_merge([['type' => 'ruby', 'pairs' => $pairs] + Attrs::of($node)], $base);
    }
}
