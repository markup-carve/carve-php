<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Snippet;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Diagnostics\Message;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\Walk;

/**
 * C2-N-090 to C2-N-096: the snippet registry and the one expansion pass over the render's own tree, after includes and
 * before resolution and document transforms. What the pass leaves for the renderer goes in the `layout` of the nodes:
 * `snippetCarrier` on the element that carries the roundtrip carriers, `snippetMark` on copied note references and
 * links, and on the document `snippetLosses` and `snippetBudget`.
 */
final class Expander
{
    /**
     * C2-N-093: `snippet_depth` and `snippet_nodes` of data/limits.json.
     *
     * @var int
     */
    public const DEPTH = 16;

    /**
     * @var int
     */
    public const NODES = 100000;

    /**
     * @var int
     */
    private const NODE_COST = 32;

    /**
     * The inline nodes that render as exactly one element whose attributes the renderer writes.
     *
     * @var array<string, true>
     */
    private const ONE_ELEMENT = [
        'emphasis' => true,
        'strong' => true,
        'underline' => true,
        'strike' => true,
        'highlight' => true,
        'superscript' => true,
        'subscript' => true,
        'insert' => true,
        'delete' => true,
        'span' => true,
        'code' => true,
    ];

    /**
     * The nodes no include stands inside, which the registry does not enter.
     *
     * @var array<string, true>
     */
    private const HOLDS_NO_INCLUDE = [
        'paragraph' => true,
        'heading' => true,
        'code_block' => true,
        'comment' => true,
        'raw_block' => true,
        'thematic_break' => true,
        'line_block' => true,
        'snippet_use' => true,
        'cell_paragraph' => true,
        'link_reference_definition' => true,
        'abbreviation_def' => true,
        'citation_definition' => true,
        'frontmatter' => true,
    ];

    /**
     * The fields whose keys count as strings (C2-N-094).
     *
     * @var array<string, true>
     */
    private const OPAQUE = ['data' => true, 'metadata' => true, 'props' => true, 'payload' => true];

    /**
     * Test-only lowered limits of the conformance harness (`host.limits`); never a Carve option (C2-H-201).
     *
     * @var array{snippetDepth?: int, snippetNodes?: int, derivedTextBytes?: int}|null
     */
    public static ?array $testLimits = null;

    /**
     * @var array<string, array{0: \MarkupCarve\Carve\Node, 1: \MarkupCarve\Carve\Node|null}>
     */
    private array $registry;

    /**
     * @var array<int, true> The definitions being expanded, by object id.
     */
    private array $stack = [];

    private int $depth;

    private int $limit;

    private int $total = 0;

    private int $budget;

    private int $left;

    private bool $reported = false;

    private int $admitted = 0;

    /**
     * @var array<array{0: string, 1: \MarkupCarve\Carve\Node, 2: string}>
     */
    private array $losses = [];

    private int $current = 0;

    private int $copyCount = 0;

    private int $copyBytes = 0;

    private function __construct(private Node $doc, private bool $roundtrip)
    {
        $this->registry = self::registry($doc);
        $this->depth = self::$testLimits['snippetDepth'] ?? self::DEPTH;
        $this->limit = self::$testLimits['snippetNodes'] ?? self::NODES;
        $this->budget = self::budgetOf($doc);
        $this->left = $this->budget;
    }

    /**
     * C2-H-201: the derived-text budget of one render of `$doc`.
     *
     * @param \MarkupCarve\Carve\Node $doc
     *
     * @return int
     */
    public static function budgetOf(Node $doc): int
    {
        if (isset(self::$testLimits['derivedTextBytes'])) {
            return self::$testLimits['derivedTextBytes'];
        }
        $length = $doc->f['srcByteLength'] ?? 0;
        $length = is_int($length) ? max(0, $length) : 0;
        $jsonLength = $doc->layout['jsonByteLength'] ?? null;
        if (is_int($jsonLength)) {
            $length = min($length, $jsonLength);
        }

        return max(1000000, min($length, intdiv(PHP_INT_MAX, 8)) * 8);
    }

    /**
     * C2-N-091: expands the snippet uses of `$doc` in place, which must be the render's own tree, once.
     *
     * @param \MarkupCarve\Carve\Node $doc
     * @param bool $roundtrip
     * @param string|null $source The source of `$doc`, which spares the walk when it spells no use.
     *
     * @return void
     */
    public static function expand(Node $doc, bool $roundtrip, ?string $source = null): void
    {
        if (isset($doc->layout['snippetsExpanded'])) {
            return;
        }
        $doc->layout['snippetsExpanded'] = true;
        if ($source !== null && $doc->includeSources === [] && !str_contains($source, '{{')) {
            return;
        }
        if (!self::holds($doc, false)) {
            return;
        }
        $pass = new self($doc, $roundtrip);
        $pass->walkNode($doc);
        $doc->layout['snippetBudget'] = [$pass->budget, $pass->left, $pass->reported];
        $doc->layout['snippetLosses'] = [...self::losses($doc), ...$pass->losses];
    }

    /**
     * The findings the pass left on `$doc`: code, node and message.
     *
     * @param \MarkupCarve\Carve\Node $doc
     *
     * @return array<array{0: string, 1: \MarkupCarve\Carve\Node, 2: string}>
     */
    public static function losses(Node $doc): array
    {
        $entries = $doc->layout['snippetLosses'] ?? null;
        $out = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && is_string($entry[0] ?? null) && ($entry[1] ?? null) instanceof Node && is_string($entry[2] ?? null)) {
                $out[] = [$entry[0], $entry[1], $entry[2]];
            }
        }

        return $out;
    }

    /**
     * C2-N-094: the derived-text budget the pass left on `$doc`, its remainder and whether it was reported, or null.
     *
     * @param \MarkupCarve\Carve\Node $doc
     *
     * @return array{0: int, 1: int, 2: bool}|null
     */
    public static function budgetLeft(Node $doc): ?array
    {
        $state = $doc->layout['snippetBudget'] ?? null;
        if (!is_array($state) || !is_int($state[0] ?? null) || !is_int($state[1] ?? null) || !is_bool($state[2] ?? null)) {
            return null;
        }

        return [$state[0], $state[1], $state[2]];
    }

    /**
     * C2-H-215: the carriers of the expanded use whose element `$node` is, or ''.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    public static function carrierText(Node $node): string
    {
        $carrier = $node->layout['snippetCarrier'] ?? null;
        if (!is_array($carrier) || !is_int($carrier[0] ?? null) || !is_string($carrier[1] ?? null) || !is_string($carrier[2] ?? null)) {
            return '';
        }

        return ' data-carve-snippet="' . $carrier[0] . ':' . $carrier[1] . ':$' . $carrier[2] . '"'
            . (($carrier[3] ?? false) === true ? ' data-carve-snippet-scope="include"' : '');
    }

    /**
     * C2-H-215: the `N` of the innermost expanded use around a copied note reference or link, or null.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return int|null
     */
    public static function mark(Node $node): ?int
    {
        $mark = $node->layout['snippetMark'] ?? null;

        return is_int($mark) ? $mark : null;
    }

    /**
     * C2-N-090: the registry of the expanded tree in document order: each name with its first definition and the
     * expanded include whose file holds it. Each later definition of a name goes to `$duplicate`.
     *
     * @param \MarkupCarve\Carve\Node $doc
     * @param callable(\MarkupCarve\Carve\Node): void|null $duplicate
     *
     * @return array<string, array{0: \MarkupCarve\Carve\Node, 1: \MarkupCarve\Carve\Node|null}>
     */
    public static function registry(Node $doc, ?callable $duplicate = null): array
    {
        $registry = [];
        $stack = [[$doc->children ?? [], null, 0]];
        while ($stack !== []) {
            [$list, $include, $at] = array_pop($stack);
            for ($count = count($list); $at < $count; $at++) {
                $child = $list[$at];
                if ($child->type === 'snippet_definition') {
                    if (isset($child->layout['snippetTransform'])) {
                        continue;
                    }
                    $name = $child->f['name'] ?? '';
                    if (isset($registry[$name])) {
                        if ($duplicate !== null) {
                            $duplicate($child);
                        }
                    } else {
                        $registry[$name] = [$child, $include];
                    }

                    continue;
                }
                $inner = $child->type === 'include' ? $child : $include;
                if ($child->type === 'include' ? $child->children === null : isset(self::HOLDS_NO_INCLUDE[$child->type])) {
                    continue;
                }
                // An include may stand in a container, as `- {{ a.crv }}`; its definitions count where it stands.
                $stack[] = [$list, $include, $at + 1];
                $lists = [];
                self::eachList($child, static function (array &$nodes) use (&$lists): void {
                    $lists[] = $nodes;
                });
                foreach (array_reverse($lists) as $nodes) {
                    $stack[] = [$nodes, $inner, 0];
                }

                continue 2;
            }
        }

        return $registry;
    }

    /**
     * Whether `$doc` holds a snippet use, or with `$definitions` a snippet definition, anywhere.
     *
     * @param \MarkupCarve\Carve\Node $doc
     * @param bool $definitions
     *
     * @return bool
     */
    public static function holds(Node $doc, bool $definitions): bool
    {
        $pending = [$doc];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node->type === 'snippet_use' || ($definitions && $node->type === 'snippet_definition')) {
                return true;
            }
            self::eachList($node, static function (array &$nodes) use (&$pending): void {
                array_push($pending, ...$nodes);
            });
        }

        return false;
    }

    /**
     * Calls `$visit` with each list of child nodes of `$node`, by reference, so a list can change in place.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param callable $visit
     *
     * @return void
     */
    public static function eachList(Node $node, callable $visit): void
    {
        if ($node->children !== null && $node->children !== []) {
            $visit($node->children);
        }
        foreach ($node->f as $key => $value) {
            if (isset(Walk::OPAQUE[$key]) || !is_array($value) && !$value instanceof Node) {
                continue;
            }
            if ($value instanceof Node) {
                $list = [$value];
                $visit($list);
                $node->f[$key] = $list[0];

                continue;
            }
            if ($value === []) {
                continue;
            }
            if (array_is_list($value) && $value[0] instanceof Node) {
                $visit($value);
                $node->f[$key] = $value;

                continue;
            }
            if ($key === 'slots' || $key === 'metadata') {
                foreach ($value as $name => $item) {
                    if (is_array($item) && $item !== [] && array_is_list($item) && $item[0] instanceof Node) {
                        $visit($item);
                        $value[$name] = $item;
                    }
                }
                $node->f[$key] = $value;
            }
        }
    }

    /**
     * C2-N-095: in a view a document transform returned, a snippet use is written as its source text and a snippet
     * definition the transform added enters no registry; each reports `snippet-unexpanded-transform`.
     *
     * @param \MarkupCarve\Carve\Node $view
     *
     * @return void
     */
    public static function refuseTransformed(Node $view): void
    {
        $view->layout['snippetsExpanded'] = true;
        if (!self::holds($view, true)) {
            return;
        }
        $losses = self::losses($view);
        $visit = static function (array &$list, bool $top) use (&$visit, &$losses): void {
            foreach ($list as $at => $node) {
                if ($node->type === 'snippet_use') {
                    $out = self::spelled($node);
                    $list[$at] = $out;
                    $losses[] = ['snippet-unexpanded-transform', $out, Message::fill('snippet-unexpanded-transform')];

                    continue;
                }
                if ($node->type === 'snippet_definition') {
                    if (!$top || !isset($node->layout['snippetAuthored'])) {
                        $node->layout['snippetTransform'] = true;
                        $losses[] = ['snippet-unexpanded-transform', $node, Message::fill('snippet-unexpanded-transform')];
                    }

                    continue;
                }
                self::eachList($node, static function (array &$nodes) use (&$visit, $node): void {
                    $visit($nodes, $node->type === 'include');
                });
            }
        };
        self::eachList($view, static function (array &$nodes) use (&$visit): void {
            $visit($nodes, true);
        });
        $view->layout['snippetLosses'] = $losses;
    }

    /**
     * C2-N-095: marks the definitions of the view the transforms receive, so a definition a transform adds is told
     * apart. A copy of the view keeps the marks.
     *
     * @param \MarkupCarve\Carve\Node $doc
     *
     * @return void
     */
    public static function markDefinitions(Node $doc): void
    {
        $mark = static function (Node $node): void {
            $node->layout['snippetAuthored'] = true;
        };
        foreach (self::registry($doc, $mark) as [$definition]) {
            $mark($definition);
        }
    }

    /**
     * C2-N-093: the use written as its source spelling; an authored use keeps its position for its finding.
     *
     * @param \MarkupCarve\Carve\Node $use
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function spelled(Node $use): Node
    {
        $text = Node::at('text', $use->start, $use->end);
        $text->f['value'] = Syntax::spelling(is_string($use->f['name'] ?? null) ? $use->f['name'] : '');
        $text->file = $use->file;
        $text->includeChain = $use->includeChain;
        if (($use->f['mode'] ?? null) !== 'block') {
            return $text;
        }
        $paragraph = Node::at('paragraph', $use->start, $use->end);
        $paragraph->children = [$text];
        $paragraph->file = $use->file;
        $paragraph->includeChain = $use->includeChain;

        return $paragraph;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param int $at
     * @param \MarkupCarve\Carve\Node $use
     * @param \MarkupCarve\Carve\Node $authored
     * @param string $code
     * @param array<string, string|int> $slots
     * @param string|null $message
     *
     * @return void
     */
    private function refuse(array &$list, int $at, Node $use, Node $authored, string $code, array $slots, ?string $message = null): void
    {
        $out = self::spelled($use);
        $list[$at] = $out;
        $this->losses[] = [$code, $authored->start >= 0 ? $authored : $out, $message ?? Message::fill($code, $slots)];
    }

    /**
     * Walks `$count` nodes of `$list` from `$from` and returns how many stand there after their uses expanded.
     *
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param int $from
     * @param int $count
     *
     * @return int
     */
    private function walkRange(array &$list, int $from, int $count): int
    {
        $end = $from + $count;
        for ($at = $from; $at < $end;) {
            $node = $list[$at];
            if ($node->type === 'snippet_use') {
                $length = $this->expandUse($list, $at, $node);
                $end += $length - 1;
                $at += $length;

                continue;
            }
            if ($node->type !== 'snippet_definition') {
                $this->walkNode($node);
            }
            $at++;
        }

        return $end - $from;
    }

    private function walkNode(Node $node): void
    {
        self::eachList($node, function (array &$nodes): void {
            $this->walkRange($nodes, 0, count($nodes));
        });
    }

    /**
     * Expands the use at `$list[$at]` and returns how many nodes now stand in its place, the copy expanded too.
     *
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param int $at
     * @param \MarkupCarve\Carve\Node $use
     *
     * @return int
     */
    private function expandUse(array &$list, int $at, Node $use): int
    {
        $name = is_string($use->f['name'] ?? null) ? $use->f['name'] : '';
        $authored = $use->layout['snippetAuthoredUse'] ?? null;
        $authored = $authored instanceof Node ? $authored : $use;
        $entry = $this->registry[$name] ?? null;
        $code = $this->refusal($entry);
        if ($code !== null || $entry === null) {
            $this->refuse($list, $at, $use, $authored, $code ?? 'snippet-unresolved', ['name' => $name, 'limit' => $this->depth]);

            return 1;
        }
        [$definition, $include] = $entry;
        $inline = ($use->f['mode'] ?? null) !== 'block';
        [$copy, $attrs, $single, $wraps, $count, $bytes] = $this->shape($definition, $inline);
        if (!$this->admits($list, $at, $use, $authored, $name, $count, $bytes)) {
            return 1;
        }
        $n = ++$this->admitted;
        $wrapper = $wraps ? self::wrapper($copy, $attrs, $definition, $inline) : null;
        $holder = $wrapper ?? $single;
        if ($this->roundtrip && $holder !== null) {
            $holder->layout['snippetCarrier'] = [$n, $inline ? 'inline' : 'block', $name, $include !== null];
        }
        array_splice($list, $at, 1, $wrapper === null ? $copy : [$wrapper]);

        return $this->walkCopy($list, $at, $copy, $wrapper, $definition, $n);
    }

    /**
     * C2-H-214: the span of an inline copy or the paragraph of a block copy, with the definition's attributes.
     *
     * @param array<\MarkupCarve\Carve\Node> $copy
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param \MarkupCarve\Carve\Node $definition
     * @param bool $inline
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function wrapper(array $copy, ?Attrs $attrs, Node $definition, bool $inline): Node
    {
        $wrapper = new Node($inline ? 'span' : 'paragraph', $copy);
        if ($attrs !== null) {
            $wrapper->f['attrs'] = $attrs;
        }
        $wrapper->includeChain = $definition->includeChain;
        $wrapper->file = $definition->file;

        return $wrapper;
    }

    /**
     * C2-N-093: the code of a use that is not expanded, or null where its definition exists, closes no cycle and stays
     * within the depth limit.
     *
     * @param array{0: \MarkupCarve\Carve\Node, 1: \MarkupCarve\Carve\Node|null}|null $entry
     *
     * @return string|null
     */
    private function refusal(?array $entry): ?string
    {
        return match (true) {
            $entry === null => 'snippet-unresolved',
            isset($this->stack[spl_object_id($entry[0])]) => 'snippet-cycle',
            count($this->stack) + 1 > $this->depth => 'snippet-depth',
            default => null,
        };
    }

    /**
     * How the copy of `$definition` stands at a use: the copy, the attributes of its wrapper, the one copy node that
     * carries the carriers itself, whether a wrapper holds the copy, and its node count and bytes (C2-N-094).
     *
     * @param \MarkupCarve\Carve\Node $definition
     * @param bool $inline
     *
     * @return array{0: array<\MarkupCarve\Carve\Node>, 1: \MarkupCarve\Carve\Attrs|null, 2: \MarkupCarve\Carve\Node|null,
     *     3: bool, 4: int, 5: int}
     */
    private function shape(Node $definition, bool $inline): array
    {
        $this->copyCount = 0;
        $this->copyBytes = 0;
        $copy = [];
        foreach ($definition->children ?? [] as $child) {
            $copy[] = $this->copy($child);
        }
        $attrs = $definition->f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? clone $attrs : null;
        // C2-H-215: under roundtrip a copy that renders as one element carries the carriers itself; any other gets a span.
        $single = $inline && $attrs === null && $this->roundtrip && count($copy) === 1 && $this->rendersAsOne($copy[0])
            ? $copy[0] : null;
        $wraps = !$inline || $attrs !== null || ($this->roundtrip && $single === null);
        $bytes = $this->copyBytes;
        if ($wraps) {
            $bytes += strlen($inline ? 'span' : 'paragraph') + ($attrs !== null ? self::attrsBytes($attrs) : 0);
        }

        return [$copy, $attrs, $single, $wraps, $this->copyCount + ($wraps ? 1 : 0), $bytes];
    }

    /**
     * Whether the node and text budgets admit the copy; where not, the use is refused in place (C2-N-094, C2-H-201).
     *
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param int $at
     * @param \MarkupCarve\Carve\Node $use
     * @param \MarkupCarve\Carve\Node $authored
     * @param string $name
     * @param int $count
     * @param int $bytes
     *
     * @return bool
     */
    private function admits(array &$list, int $at, Node $use, Node $authored, string $name, int $count, int $bytes): bool
    {
        if ($this->total + $count > $this->limit) {
            $this->refuse($list, $at, $use, $authored, 'snippet-node-budget', ['name' => $name, 'limit' => $this->limit]);

            return false;
        }
        $cost = self::NODE_COST * $count + $bytes;
        if ($cost <= $this->left) {
            $this->total += $count;
            $this->left -= $cost;

            return true;
        }
        if ($this->reported) {
            $list[$at] = self::spelled($use);
        } else {
            $this->reported = true;
            $this->refuse($list, $at, $use, $authored, 'derived-text-budget', [], 'Wrote a snippet use as its source text: '
                . 'the derived text of this render reached its budget of ' . $this->budget . ' bytes');
        }

        return false;
    }

    /**
     * Expands the uses of a placed copy and returns how many nodes stand in place of the use.
     *
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param int $at
     * @param array<\MarkupCarve\Carve\Node> $copy
     * @param \MarkupCarve\Carve\Node|null $wrapper
     * @param \MarkupCarve\Carve\Node $definition
     * @param int $n
     *
     * @return int
     */
    private function walkCopy(array &$list, int $at, array $copy, ?Node $wrapper, Node $definition, int $n): int
    {
        $id = spl_object_id($definition);
        $this->stack[$id] = true;
        $outer = $this->current;
        $this->current = $n;
        foreach ($copy as $node) {
            $this->markNotes($node);
        }
        $length = 1;
        if ($wrapper !== null) {
            $this->walkRange($copy, 0, count($copy));
            $wrapper->children = $copy;
        } else {
            $length = $this->walkRange($list, $at, count($copy));
        }
        $this->current = $outer;
        unset($this->stack[$id]);

        return $length;
    }

    private function markNotes(Node $node): void
    {
        // A link is marked too: its mark moves out of a use only where the link stands outside that copy (C2-H-215).
        if ($node->type === 'footnote_ref' || $node->type === 'inline_footnote' || $node->type === 'link') {
            $node->layout['snippetMark'] = $this->current;
        }
        self::eachList($node, function (array &$nodes): void {
            foreach ($nodes as $child) {
                $this->markNotes($child);
            }
        });
    }

    /**
     * Whether `$node` renders as one element that can take the carriers: a link only where no note mark moves out
     * behind it.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return bool
     */
    private function rendersAsOne(Node $node): bool
    {
        if (isset(self::ONE_ELEMENT[$node->type])) {
            return true;
        }
        if ($node->type !== 'link') {
            return false;
        }
        $pending = [$node];
        while ($pending !== []) {
            $next = array_pop($pending);
            // A use inside the link may expand to a note that moves out behind it.
            if ($next->type === 'footnote_ref' || $next->type === 'inline_footnote' || $next->type === 'snippet_use') {
                return false;
            }
            self::eachList($next, static function (array &$nodes) use (&$pending): void {
                array_push($pending, ...$nodes);
            });
        }

        return true;
    }

    /**
     * C2-N-092: a copy of `$node` with no position, no authored id, no annotation ids and no resolution fields; its
     * node count and the bytes of its strings go to the running copy (C2-N-094).
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return \MarkupCarve\Carve\Node
     */
    private function copy(Node $node): Node
    {
        $this->copyCount++;
        $this->copyBytes += strlen($node->type);
        $out = new Node($node->type);
        $out->file = $node->file;
        $out->includeChain = $node->includeChain;
        $out->sourceName = $node->sourceName;
        $out->slotView = $node->slotView;
        if ($node->type === 'snippet_use') {
            $out->layout['snippetAuthoredUse'] = $node->layout['snippetAuthoredUse'] ?? $node;
        }
        if ($node->children !== null) {
            $out->children = [];
            foreach ($node->children as $child) {
                $out->children[] = $this->copy($child);
            }
        }
        $results = Tables::AST_RESULTS[$node->type] ?? [];
        foreach ($node->f as $field => $value) {
            if ($value === null || isset($results[$field]) && self::dropsResult($node, $results[$field])) {
                continue;
            }
            if ($field === 'attrs' && $value instanceof Attrs) {
                $attrs = clone $value;
                $attrs->id = null;
                $attrs->idRange = null;
                if ($attrs->classes === [] && $attrs->pairs === []) {
                    continue;
                }
                $this->copyBytes += self::attrsBytes($attrs);
                $out->f['attrs'] = $attrs;

                continue;
            }
            if ($field === 'data' && is_array($value)) {
                $data = self::withoutAnnotationIds($value);
                if ($data === []) {
                    continue;
                }
                $this->copyBytes += self::bytes($data, true);
                $out->f['data'] = $data;

                continue;
            }
            $out->f[$field] = $this->copyValue($value, isset(self::OPAQUE[$field]), $field === 'slots');
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @param bool $keys Whether the keys of the value count (an opaque value).
     * @param bool $slots Whether the value is the slots of an extension node, whose names are no strings of the tree.
     *
     * @return mixed
     */
    private function copyValue(mixed $value, bool $keys, bool $slots = false): mixed
    {
        if ($value instanceof Node) {
            return $this->copy($value);
        }
        if (is_string($value)) {
            $this->copyBytes += strlen($value);

            return $value;
        }
        if (is_object($value)) {
            return clone $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if ($keys && !$slots && is_string($key)) {
                $this->copyBytes += strlen($key);
            }
            $out[$key] = $this->copyValue($item, $keys && !$slots);
        }

        return $out;
    }

    private static function dropsResult(Node $node, string $when): bool
    {
        return match ($when) {
            'reference use' => isset($node->f['key']),
            'derived from authored span mark' => isset($node->f['span']),
            default => true,
        };
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function withoutAnnotationIds(array $data): array
    {
        $annotation = $data['carve.annotation'] ?? null;
        if (is_array($annotation)) {
            unset($annotation['ids']);
            if ($annotation === []) {
                unset($data['carve.annotation']);
            } else {
                $data['carve.annotation'] = $annotation;
            }
        }

        return $data;
    }

    private static function attrsBytes(Attrs $attrs): int
    {
        $bytes = 0;
        foreach ($attrs->classes as $class) {
            $bytes += strlen($class);
        }
        foreach ($attrs->pairs as [$key, $value]) {
            $bytes += strlen($key) + strlen($value);
        }

        return $bytes;
    }

    /**
     * The bytes of every string and, with `$keys`, every string key (C2-N-094).
     *
     * @param mixed $value
     * @param bool $keys
     *
     * @return int
     */
    private static function bytes(mixed $value, bool $keys): int
    {
        if (is_string($value)) {
            return strlen($value);
        }
        if (!is_array($value)) {
            return 0;
        }
        $sum = 0;
        foreach ($value as $key => $item) {
            $sum += ($keys && is_string($key) ? strlen($key) : 0) + self::bytes($item, $keys);
        }

        return $sum;
    }
}
