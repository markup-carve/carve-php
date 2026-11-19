<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Diagnostics\Findings;
use MarkupCarve\Carve\Diagnostics\Message;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Html\RenderState;
use MarkupCarve\Carve\Html\UrlPolicy;
use MarkupCarve\Carve\Include\Expander;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Facts;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * One run of an export (carve-js `src/export/common.ts`): the resolved copy of the tree, the losses, the derived-text
 * budget and the text of the nodes whose output does not depend on the target.
 *
 * @internal
 */
final class Exporter
{
    /**
     * The loss codes only the exports report, beside the render-loss codes of the catalog they share with HTML.
     *
     * @var array<string>
     */
    public const LOSS_CODES = [
        'markdown-container-flattened', 'markdown-caption-flattened', 'markdown-attributes-dropped', 'fence-title-dropped',
        'raw-html-escaped', 'table-header-row-demoted', 'table-header-cell-demoted', 'list-style-dropped', 'table-span-flattened',
        'ruby-flattened', 'editorial-comment-flattened',
    ];

    /**
     * The bound of a recursive walk, above the parser's own nesting limit, for supplied trees.
     *
     * @var int
     */
    public const MAX_DEPTH = 1000;

    /**
     * @var int
     */
    public const DERIVED_TEXT_BYTES = 1000000;

    /**
     * The plain text of the inline types whose text is one field behind a prefix.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PLAIN_FIELD = [
        'text' => ['value', ''],
        'code' => ['value', ''],
        'literal_inline' => ['value', ''],
        'math' => ['value', ''],
        'critic_comment' => ['value', ''],
        'image' => ['alt', ''],
        'abbreviation' => ['key', ''],
        'tag' => ['name', '#'],
        'citation_group' => ['raw', ''],
    ];

    /**
     * @var array<string, string>
     */
    private const PLAIN_FIXED = [
        'soft_break' => ' ',
        'hard_break' => ' ',
        'non_breaking_space' => ' ',
        'comment' => '',
        'raw_inline' => '',
    ];

    public bool $smartTypography;

    /**
     * @var array<array{0: string, 1: string, 2: string, 3: \MarkupCarve\Carve\Node|null}>
     */
    public array $losses = [];

    public int $derivedLeft = self::DERIVED_TEXT_BYTES;

    public bool $derivedReported = false;

    public int $depth = 0;

    public int $linkDepth = 0;

    private RenderState $state;

    /**
     * @param string $target `markdown`, `plain` or `ansi`.
     * @param \MarkupCarve\Carve\Node $document The resolved copy.
     * @param \MarkupCarve\Carve\Options $options
     * @param \MarkupCarve\Carve\Resolve\Facts|null $facts
     */
    private function __construct(
        public readonly string $target,
        public readonly Node $document,
        private Options $options,
        ?Facts $facts,
    ) {
        $this->smartTypography = $options->smartTypography;
        $this->state = new RenderState($document, $options, null, $facts);
    }

    /**
     * Checks the options, validates the tree and resolves a copy of it, so the caller's tree is not changed.
     *
     * @param string $target
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return self
     */
    public static function start(string $target, Node $tree, array $options): self
    {
        foreach ($options as $name => $value) {
            if (!in_array($name, ['smartTypography', 'strictLosses', 'urlPolicy'], true)) {
                throw new OptionError('unknown option ' . $name);
            }
        }
        $opts = Options::from($options);
        $resolve = Closure::bind(static fn (Node $t, Options $o): array => Carve::resolvedTree($t, $o), null, Carve::class);
        [$document, , $facts] = $resolve($tree, $opts);

        return new self($target, $document, $opts, $facts);
    }

    /**
     * @param string $output
     * @param bool $strict
     *
     * @throws \MarkupCarve\Carve\Export\CarveExportError Under `strictLosses` when the export lost something.
     *
     * @return \MarkupCarve\Carve\Export\ExportResult
     */
    public function finish(string $output, bool $strict): ExportResult
    {
        $diagnostics = [];
        if ($this->losses !== []) {
            $paths = Findings::paths($this->document);
            foreach ($this->losses as [$code, $severity, $message, $node]) {
                $path = $node === null ? '' : ($paths[$node] ?? null);
                $start = -1;
                $end = -1;
                if ($node !== null && $node->start >= 0) {
                    $start = $node->start;
                    $end = $node->end;
                }
                $diagnostics[] = new Diagnostic($code, $severity, 'render-loss', $message, $path, $start, $end, $node?->file);
            }
        }
        if ($strict && $diagnostics !== []) {
            throw new CarveExportError($diagnostics);
        }

        return new ExportResult($output, $diagnostics);
    }

    public function loss(?Node $node, string $code, string $message, string $severity = 'warning'): void
    {
        $this->losses[] = [$code, $severity, $message, $node];
    }

    /**
     * Descends one level of the tree, refusing a supplied tree nested past `MAX_DEPTH`.
     *
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return T
     */
    public function nested(Closure $run): mixed
    {
        if ($this->depth >= self::MAX_DEPTH) {
            throw new OptionError('option document: nested deeper than ' . self::MAX_DEPTH . ' levels');
        }
        $this->depth++;
        try {
            return $run();
        } finally {
            $this->depth--;
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     */
    public function insideLink(Closure $run): mixed
    {
        $this->linkDepth++;
        try {
            return $run();
        } finally {
            $this->linkDepth--;
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     */
    public function outsideLinks(Closure $run): mixed
    {
        $depth = $this->linkDepth;
        $this->linkDepth = 0;
        try {
            return $run();
        } finally {
            $this->linkDepth = $depth;
        }
    }

    /**
     * C2-H-201: takes `$text` from the budget of derived text. Past the budget the caller writes the plain form, and the
     * first such occurrence is reported.
     *
     * @param \MarkupCarve\Carve\Node|null $node
     * @param string $text
     *
     * @return bool
     */
    public function drawDerived(?Node $node, string $text): bool
    {
        $cost = strlen($text);
        if ($cost <= $this->derivedLeft) {
            $this->derivedLeft -= $cost;

            return true;
        }
        $this->derivedLeft = 0;
        if (!$this->derivedReported) {
            $this->derivedReported = true;
            $this->loss($node, 'derived-text-budget', 'Wrote an abbreviation or a cross-reference in its plain form: '
                . 'the derived text of this render reached its budget of ' . self::DERIVED_TEXT_BYTES . ' bytes');
        }

        return false;
    }

    /**
     * The destination of a link or an image, empty where the URL policy denies its scheme (reported on `$node`).
     *
     * @param string $url
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    public function allowedDestination(string $url, Node $node): string
    {
        if (UrlPolicy::passes($url, $this->options)) {
            return $url;
        }
        $message = $node->type === 'image' ? 'Blanked a denied image source' : 'Blanked a denied destination scheme';
        $this->loss($node, 'destination-denied', $message, 'error');

        return '';
    }

    public function rawDropped(Node $node, bool $block): void
    {
        $this->loss($node, 'raw-format-dropped', 'Dropped ' . ($block ? 'block' : 'inline') . ' raw format "'
            . self::str($node->f['format'] ?? '') . '" while rendering ' . $this->target);
    }

    public function smartText(Node $node): string
    {
        $source = self::str($node->f['source'] ?? '');
        if (!$this->smartTypography) {
            return $source;
        }
        $glyph = $node->f['glyph'] ?? null;
        if (is_string($glyph)) {
            return $glyph;
        }

        return Tables::SMART_GLYPHS[self::str($node->f['kind'] ?? '')] ?? $source;
    }

    /**
     * C2-H-086: the label part of an unresolved reference, as typed.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param string $key
     *
     * @return string
     */
    public static function referenceLabel(Node $node, string $key): string
    {
        return match ($node->f['refForm'] ?? null) {
            'collapsed' => '[]',
            'shortcut' => '',
            default => '[' . $key . ']',
        };
    }

    /**
     * Whether a link or an image is a reference that found no definition.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    public static function isUnresolved(Node $node): bool
    {
        return isset($node->f['key']) && !isset($node->f[$node->type === 'link' ? 'href' : 'src']);
    }

    /**
     * The tooltip of a link or an image: its own, or that of the definition its key found.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string|null
     */
    public function tooltip(Node $node): ?string
    {
        $own = $node->f['tooltip'] ?? null;
        if (is_string($own)) {
            return $own;
        }
        $key = $node->f['key'] ?? null;
        $tooltip = is_string($key) ? ($this->state->linkDefs[$key] ?? null)?->f['tooltip'] ?? null : null;

        return is_string($tooltip) ? $tooltip : null;
    }

    /**
     * The text a cross-reference stands for, or its source where it found no target.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array{0: string, 1: bool} The text and whether the reference resolved.
     */
    public function xrefText(Node $node): array
    {
        $key = self::str($node->f['key'] ?? '');
        $text = isset($node->f['href']) ? $this->state->xrefText($key) : null;
        if ($text === null) {
            return ['</#' . $key . '>', false];
        }

        return [$this->drawDerived($node, $text) ? $text : $key, true];
    }

    /**
     * C2-H-133: an include that was not expanded is written as its source line, and reported.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    public function unexpandedInclude(Node $node): string
    {
        $this->loss($node, 'include-unresolved', Message::fill('include-unresolved', ['path' => Expander::shownPath($node)]));

        return HtmlRenderer::includeText($node);
    }

    public static function noteNumber(Node $node): ?int
    {
        $number = $node->f['number'] ?? null;

        return is_int($number) ? $number : null;
    }

    /**
     * C2-H-069: a comment-only line takes one line break with it, the one behind it, or the one before it where the
     * content ends with the comment. `$nested` is true for the children of an inline node.
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     * @param int $at
     * @param bool $nested
     *
     * @return bool
     */
    public static function isSkippedBreak(array $nodes, int $at, bool $nested): bool
    {
        return self::isCommentOnlyLine($nodes, $at - 1, -1, false, $nested) || self::isCommentOnlyLine($nodes, $at + 1, 1, true, $nested);
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     * @param int $from
     * @param int $step
     * @param bool $atEnd
     * @param bool $nested
     *
     * @return bool
     */
    private static function isCommentOnlyLine(array $nodes, int $from, int $step, bool $atEnd, bool $nested): bool
    {
        $comment = false;
        $count = count($nodes);
        for ($at = $from; $at >= 0 && $at < $count; $at += $step) {
            $node = $nodes[$at];
            if ($node->type === 'comment') {
                $comment = true;
            } elseif ($node->type === 'soft_break' || $node->type === 'hard_break') {
                return $comment && !$atEnd;
            } elseif ($node->type !== 'text' || preg_match('/^[ \t]*$/', self::str($node->f['value'] ?? '')) !== 1) {
                return false;
            }
        }

        return $comment && !$nested;
    }

    /**
     * The footnote definitions of the document in source order, the first of each key only.
     *
     * @param \MarkupCarve\Carve\Node $document
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function footnoteDefinitions(Node $document): array
    {
        $found = [];
        $keys = [];
        $visit = static function (array $children) use (&$visit, &$found, &$keys): void {
            foreach ($children as $child) {
                if ($child->type === 'include') {
                    $visit($child->children ?? []);
                }
                $key = self::str($child->f['key'] ?? '');
                if ($child->type !== 'footnote' || isset($keys[$key])) {
                    continue;
                }
                $keys[$key] = true;
                $found[] = $child;
            }
        };
        $visit($document->children ?? []);

        return $found;
    }

    /**
     * Every node of the tree below `$root`, the nodes in fields included.
     *
     * @param \MarkupCarve\Carve\Node $root
     * @param \Closure(\MarkupCarve\Carve\Node): void $visit
     *
     * @return void
     */
    public static function eachNode(Node $root, Closure $visit): void
    {
        $pending = [$root];
        while ($pending !== []) {
            $node = array_pop($pending);
            $visit($node);
            foreach ($node->children ?? [] as $child) {
                $pending[] = $child;
            }
            foreach ($node->f as $value) {
                if ($value instanceof Node) {
                    $pending[] = $value;
                } elseif (is_array($value)) {
                    foreach ($value as $item) {
                        if ($item instanceof Node) {
                            $pending[] = $item;
                        }
                    }
                }
            }
        }
    }

    /**
     * Bidirectional controls the exports never write (U+202A to U+202E, U+2066 to U+2069).
     *
     * @param string $text
     *
     * @return string
     */
    public static function stripBidi(string $text): string
    {
        return (string)preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
    }

    /**
     * The text of inline content with no styling, for a measure or a slug.
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return string
     */
    public function plainInlineText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $f = $node->f;
            $field = self::PLAIN_FIELD[$node->type] ?? null;
            if ($field !== null) {
                $out .= $field[1] . self::str($f[$field[0]] ?? '');

                continue;
            }
            $out .= self::PLAIN_FIXED[$node->type] ?? $this->plainNode($node);
        }

        return $out;
    }

    private function plainNode(Node $node): string
    {
        $f = $node->f;

        return match ($node->type) {
            'smart_punctuation' => $this->smartText($node),
            'autolink' => self::str($f['text'] ?? $f['href'] ?? ''),
            'symbol' => ':' . self::str($f['name'] ?? '') . ':',
            'mention' => self::mentionText($node),
            'xref' => $this->plainXref($node),
            'caption_number' => is_int($f['n'] ?? null) ? (string)$f['n'] : '#',
            'ruby' => $this->plainRuby($node),
            default => $this->plainInlineText($node->children ?? []),
        };
    }

    public static function mentionText(Node $node): string
    {
        $label = $node->f['label'] ?? null;

        return is_string($label) ? $label : '@' . self::str($node->f['target'] ?? '');
    }

    private function plainRuby(Node $node): string
    {
        $out = '';
        foreach (PlainText::nodes($node->f['pairs'] ?? []) as $pair) {
            $out .= $this->plainInlineText(PlainText::nodes($pair->f['base'] ?? []));
        }

        return $out;
    }

    private function plainXref(Node $node): string
    {
        $key = self::str($node->f['key'] ?? '');
        $text = isset($node->f['href']) ? $this->state->xrefText($key) : null;

        return $text ?? '</#' . $key . '>';
    }

    /**
     * The fallback of a qualified extension node, or its children.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param bool $block
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function extensionContent(Node $node, bool $block): array
    {
        $fallback = PlainText::nodes($node->f['fallback'] ?? null);
        if ($block) {
            return $fallback !== [] ? $fallback : $node->children ?? [];
        }

        return str_contains(self::str($node->f['name'] ?? ''), '.') && isset($node->f['fallback']) ? $fallback : $node->children ?? [];
    }

    /**
     * The value of an authored key of `$attrs`, matched as written.
     *
     * @param mixed $attrs
     * @param string $key
     *
     * @return string|null
     */
    public static function attrValue(mixed $attrs, string $key): ?string
    {
        if (!$attrs instanceof Attrs) {
            return null;
        }
        foreach ($attrs->pairs as [$k, $v]) {
            if ($k === $key) {
                return $v;
            }
        }

        return null;
    }

    public static function str(mixed $value): string
    {
        return PlainText::str($value);
    }
}
