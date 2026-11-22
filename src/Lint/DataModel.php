<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Closure;
use MarkupCarve\Carve\Carve;
use stdClass;
use Throwable;

final class DataModel
{
    /**
     * @var array<string, true>
     */
    private const LEAVES = [
        'paragraph' => true,
        'caption' => true,
        'definition_term' => true,
        'heading' => true,
        'line' => true,
        'table_cell' => true,
        'table_row' => true,
        'code_block' => true,
        'raw_block' => true,
        'frontmatter' => true,
        'comment_block' => true,
    ];

    public static function isLineLeaf(string $type): bool
    {
        return isset(self::LEAVES[$type]);
    }

    /**
     * @var array<string, true>
     */
    private const BLOCK_OPAQUE = [
        'code_block' => true,
        'raw_block' => true,
        'frontmatter' => true,
        'link_reference_definition' => true,
        'abbreviation_def' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const VERBATIM = ['code_block' => true, 'raw_block' => true, 'frontmatter' => true, 'comment_block' => true];

    /**
     * Container types whose content starts at column zero.
     *
     * @var array<string, true>
     */
    private const ZERO_CONTENT = ['document' => true, 'block_quote' => true];

    /**
     * Container types whose content may require marker parsing.
     *
     * @var array<string, true>
     */
    private const MARKER_CONTENT = ['list_item' => true, 'footnote' => true, 'definition_description' => true];

    /**
     * @var array<string, true>
     */
    private const INLINE_HOLDERS = [
        'paragraph' => true,
        'caption' => true,
        'definition_term' => true,
        'heading' => true,
        'line' => true,
        'cell_paragraph' => true,
        'code_block' => true,
        'raw_block' => true,
        'frontmatter' => true,
        'comment' => true,
        'include' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const INLINE_LEAVES = [
        'paragraph' => true,
        'caption' => true,
        'definition_term' => true,
        'heading' => true,
        'line' => true,
        'cell_paragraph' => true,
    ];

    /**
     * @var array<string, true>
     */
    public const INLINE_PARENTS = [
        'paragraph' => true,
        'heading' => true,
        'definition_term' => true,
        'cell_paragraph' => true,
        'line' => true,
        'citation_definition' => true,
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
        'link' => true,
        'inline_footnote' => true,
        'inline_extension' => true,
        'small_caps' => true,
    ];

    /**
     * @var list<\stdClass>
     */
    public array $lines = [];

    /**
     * @var list<\stdClass>
     */
    public array $blocks = [];

    /**
     * @var list<\stdClass> One record per distinct id of the tree, in order of first occurrence (C2-W-150).
     */
    public array $ids = [];

    /**
     * @var list<array{node: array<string, mixed>, path: string, parent: string, index: int, pos: array<string, int>|null, type: string}>
     */
    private array $blockWalked = [];

    /**
     * @var array<string, array{type: string, inline: bool}>
     */
    private array $holderByPath = [];

    /**
     * @var array<int, array<string, \stdClass>> By quote depth, then path.
     */
    private array $containerCache = [];

    /**
     * @var array<string, int>
     */
    private array $quoteDepths = ['' => 0];

    private int $maskCursor = 0;

    /**
     * @param \MarkupCarve\Carve\Lint\Context $context
     * @param \Closure(\stdClass): void $onInline
     * @param \Closure(\stdClass): void $onBlock
     * @param \Closure(string, string, string, array<string, mixed>, int, int, int): bool $acceptBlock
     * @param \Closure(string, string): bool $mightUseBlock
     * @param \Closure(string, array<string, mixed>|null, int, int): bool $mightUseInline
     * @param bool $textRuns
     * @param string $textRunNeedles Necessary characters of every active text-run rule; empty disables the lexical gate.
     * @param bool $plainTableCellGate Whether all active table-cell rules have the known lexical preconditions.
     */
    public function __construct(
        private Context $context,
        private Closure $onInline,
        private Closure $onBlock,
        private Closure $acceptBlock,
        private Closure $mightUseBlock,
        private Closure $mightUseInline,
        private bool $textRuns,
        private string $textRunNeedles,
        private bool $plainTableCellGate,
    ) {
        $this->build();
    }

    /**
     * @param array<string, mixed> $node
     */
    public static function imageLine(array $node): bool
    {
        $children = $node['children'] ?? null;

        return is_array($children) && count($children) === 1
            && is_array($children[0] ?? null) && ($children[0]['type'] ?? '') === 'image';
    }

    private static function pathParent(string $path): string
    {
        $at = strrpos($path, '/children/');

        return $at === false ? '' : substr($path, 0, $at);
    }

    private function quoteDepth(string $path): int
    {
        if (isset($this->quoteDepths[$path])) {
            return $this->quoteDepths[$path];
        }
        $parent = self::pathParent($path);

        return $this->quoteDepths[$path] = $this->quoteDepth($parent)
            + (($this->context->nodeTypes[$parent] ?? '') === 'block_quote' ? 1 : 0);
    }

    private static function quoteStart(Line $line, int $marks = PHP_INT_MAX): int
    {
        $text = $line->text;
        $at = strspn($text, " \t");
        $length = strlen($text);
        while ($marks-- > 0 && ($text[$at] ?? '') === '>' && (($text[$at + 1] ?? '') === ' ' || $at + 1 === $length)) {
            $at++;
            if (($text[$at] ?? '') === ' ') {
                $at++;
            }
            $at += strspn($text, " \t", $at);
        }

        return $line->start + $at;
    }

    /**
     * The end of up to `$quotes` quote marks, each with its leading and one following space or tab.
     */
    private static function quotePrefixEnd(string $text, int $quotes): int
    {
        $at = 0;
        for ($mark = 0; $mark < $quotes; $mark++) {
            $at += strspn($text, " \t", $at);
            if (($text[$at] ?? '') !== '>') {
                break;
            }
            $at++;
            if (($text[$at] ?? '') === ' ' || ($text[$at] ?? '') === "\t") {
                $at++;
            }
        }

        return $at;
    }

    private static function trimEnd(string $text): string
    {
        return rtrim($text, " \t");
    }

    private static function punctuation(int $code): bool
    {
        return ($code >= 33 && $code <= 47) || ($code >= 58 && $code <= 64)
            || ($code >= 91 && $code <= 96) || ($code >= 123 && $code <= 126);
    }

    private function masked(Line $line): string
    {
        if ($line->opaque) {
            return $line->text;
        }
        $text = $line->text;
        $length = strlen($text);
        $masks = $this->context->leafMask;
        while (isset($masks[$this->maskCursor]) && $masks[$this->maskCursor][1] <= $line->start) {
            $this->maskCursor++;
        }
        for ($j = $this->maskCursor; isset($masks[$j]) && $masks[$j][0] < $line->end; $j++) {
            [$from, $to, $path, $type] = $masks[$j];
            $a = max(0, $from - $line->start);
            $b = min($length, $to - $line->start);
            // C2-B-027: the quote marks that prefix a later line of an inline span in a quote are no characters of the span.
            if ($from < $line->start && !isset(self::BLOCK_OPAQUE[$type])) {
                $quotes = $this->quoteDepth($path);
                if ($quotes > 0) {
                    $a = max($a, min($b, self::quotePrefixEnd($line->text, $quotes)));
                }
            }
            if ($a < $b) {
                $text = substr_replace($text, str_repeat("\0", $b - $a), $a, $b - $a);
            }
        }
        // ASCII punctuation behind an odd run of backslashes is escaped; a backslash is punctuation itself.
        $raw = $line->text;
        for ($at = strpos($raw, '\\'); $at !== false; $at = strpos($raw, '\\', $at + $run)) {
            $run = strspn($raw, '\\', $at);
            for ($k = 1; $k < $run; $k += 2) {
                $text[$at + $k] = "\0";
            }
            if ($run % 2 === 1 && $at + $run < $length && self::punctuation(ord($raw[$at + $run]))) {
                $text[$at + $run] = "\0";
            }
        }

        return $text;
    }

    // This assembles the line, block, and inline projections in a single source walk.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    private function build(): void
    {
        $c = $this->context;
        $runs = [];
        $openRuns = [];
        $pendingRuns = [];
        $seenIds = [];
        $numberedPanels = [];
        $fragments = false;
        $pipeTables = [];
        $rows = [];
        foreach ($c->walked as $item) {
            $parentHolder = $this->holderByPath[$item['parent']] ?? null;
            $type = $item['type'];
            if ($type === 'table_row' && $item['pos'] !== null) {
                $rows[] = [$item['pos']['startLine'] - 1, $item['pos']['endLine'] - 1, $item['path']];
            } elseif ($type === 'table' && $c->layout->sourceFormat($item['path']) === 'pipe' && $item['pos'] !== null) {
                $pipeTables[] = [$item['pos']['startLine'] - 1, $item['pos']['endLine'] - 1, $item['path']];
            }
            // An authored id, then the id generated for a heading.
            $attrs = $item['node']['attrs'] ?? null;
            $authored = is_array($attrs) ? ($attrs['id'] ?? null) : null;
            $generated = $type === 'heading' ? ($item['node']['generatedId'] ?? null) : null;
            if (is_string($authored) || is_string($generated)) {
                $xref = $type === 'heading' || self::numberedCaption($item['node']) || isset($numberedPanels[$item['path']]);
                foreach ([$authored, $generated] as $id) {
                    if (!is_string($id)) {
                        continue;
                    }
                    if (isset($seenIds[$id])) {
                        // One record per id; a later occurrence in the HTML still makes it rendered.
                        $record = $this->ids[$seenIds[$id]];
                        $record->rendered = $record->rendered || $type !== 'frontmatter';
                        $record->xref = $record->xref || $xref;

                        continue;
                    }
                    $seenIds[$id] = count($this->ids);
                    $record = (object)[
                        'type' => 'id',
                        'id' => $id,
                        'owner' => $type,
                        'index' => count($this->ids),
                        'start' => $item['pos']['startOffset'] ?? 0,
                        // C2-A-218: a metadata key renders no element.
                        'rendered' => $type !== 'frontmatter',
                        'xref' => $xref,
                    ];
                    if ($type === 'heading') {
                        $record->heading_text = self::plain($item['node']['children'] ?? []);
                    }
                    $this->ids[] = $record;
                }
            }
            if ($type === 'link' && str_starts_with(self::str($item['node']['href'] ?? null), '#')) {
                $fragments = true;
            } elseif ($type === 'figure_group' && self::numberedCaption($item['node'])) {
                foreach (self::panelPaths($item['node'], $item['path']) as $panel) {
                    $numberedPanels[$panel] = true;
                }
            }
            if ($this->textRuns) {
                $parentType = $c->nodeTypes[$item['parent']] ?? 'document';
                if (isset(self::INLINE_PARENTS[$parentType])) {
                    $key = $item['parent'];
                    if ($type === 'text' || $type === 'soft_break') {
                        $open = $openRuns[$key] ?? null;
                        $pending = $pendingRuns[$key] ?? null;
                        $pos = $item['pos'];
                        if ($pos !== null) {
                            if ($open !== null && $runs[$open]['next'] === $item['index']) {
                                $runs[$open]['end'] = $pos['endOffset'];
                                $runs[$open]['next'] = $item['index'] + 1;
                            } else {
                                $candidate = [
                                    'type' => 'text_run',
                                    'path' => $key,
                                    'parent' => $parentType,
                                    'leaf' => $parentHolder['type'] ?? $parentType,
                                    'start' => $pending !== null && $pending['next'] === $item['index']
                                        ? $pending['start'] : $pos['startOffset'],
                                    'end' => $pos['endOffset'],
                                    'next' => $item['index'] + 1,
                                ];
                                if (
                                    $this->textRunNeedles !== ''
                                    && strcspn(
                                        $c->source,
                                        $this->textRunNeedles,
                                        $candidate['start'],
                                        $candidate['end'] - $candidate['start'],
                                    )
                                    === $candidate['end'] - $candidate['start']
                                ) {
                                    $pendingRuns[$key] = $candidate;
                                } else {
                                    $runs[] = $candidate;
                                    $openRuns[$key] = array_key_last($runs);
                                    unset($pendingRuns[$key]);
                                }
                            }
                        } elseif ($open !== null && $runs[$open]['next'] === $item['index']) {
                            $runs[$open]['next'] = $item['index'] + 1;
                        } elseif ($pending !== null && $pending['next'] === $item['index']) {
                            $pendingRuns[$key]['next'] = $item['index'] + 1;
                        }
                    } else {
                        unset($openRuns[$key], $pendingRuns[$key]);
                    }
                }
            }
            // The caption of an image paragraph is a block (C2-W-140).
            if ($type === 'caption') {
                $parentHolder = null;
            }
            if ($parentHolder !== null && $parentHolder['inline']) {
                $pos = $item['pos'];
                if (
                    $pos !== null && $item['path'] !== ''
                    && ($this->mightUseInline)($type, $item['node'], $pos['startOffset'], $pos['endOffset'])
                ) {
                    $inline = [
                        'type' => $type,
                        'path' => $item['path'],
                        'parent' => $c->nodeTypes[$item['parent']] ?? 'document',
                        'leaf' => $parentHolder['type'],
                        'start' => $pos['startOffset'],
                        'end' => $pos['endOffset'],
                        'text' => substr($c->source, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']),
                    ];
                    if (isset($c->dataNodeTypes[$type]) || isset($c->dataNodeTypes['*'])) {
                        // The rules that read `node` build it from `nodeSource` on first read (DataRules::field).
                        $inline['node'] = null;
                        $inline['nodeSource'] = $item['node'];
                    }
                    ($this->onInline)((object)$inline);
                }
            } elseif ($parentHolder === null) {
                $this->blockWalked[] = $item;
            }
            if (!empty($item['node']['children'])) {
                if (isset(self::INLINE_HOLDERS[$type])) {
                    $this->holderByPath[$item['path']] = ['type' => $type, 'inline' => isset(self::INLINE_LEAVES[$type])];
                } elseif ($parentHolder !== null) {
                    $this->holderByPath[$item['path']] = $parentHolder;
                }
            }
        }
        foreach ($runs as $run) {
            if (($this->mightUseInline)('text_run', null, $run['start'], $run['end'])) {
                $run['text'] = substr($c->source, $run['start'], $run['end'] - $run['start']);
                ($this->onInline)((object)$run);
            }
        }
        unset($runs, $openRuns);
        $c->walked = [];
        $this->holderByPath = [];
        // The line records reuse memory freed by the walk. Large allocator caches can otherwise force a new 2 MB page.
        if (memory_get_usage(true) - memory_get_usage(false) > 16 * 1024 * 1024) {
            gc_mem_caches();
        }
        $count = count($c->lines);
        if ($count > 1 && $c->lines[$count - 1]->text === '' && str_ends_with($c->source, "\n")) {
            $count--;
        }
        $holders = array_fill(0, $count, null);
        foreach ($this->blockWalked as $item) {
            $node = $item['node'];
            $pos = $item['pos'];
            $type = $item['type'];
            if ($pos === null || !isset(self::LEAVES[$type])) {
                continue;
            }
            for ($n = $pos['startLine']; $n <= min($pos['endLine'], $count); $n++) {
                $holders[$n - 1] = [$item['path'], $type, $pos];
            }
        }
        for ($i = 0; $i < $count; $i++) {
            $sourceLine = $c->lines[$i];
            $held = $holders[$i];
            [$path, $leaf, $pos] = $held ?? ['', 'none', null];
            $linePath = $sourceLine->path !== '' ? $sourceLine->path : $path;
            $marks = isset(self::VERBATIM[$leaf]) ? $this->quoteDepth($path) : PHP_INT_MAX;
            $start = $sourceLine->content !== null
                && $sourceLine->content >= $sourceLine->start
                && $sourceLine->content <= $sourceLine->end
                ? $sourceLine->content
                : ($pos !== null && $pos['startLine'] === $i + 1
                    && $pos['startOffset'] >= $sourceLine->start && $pos['startOffset'] <= $sourceLine->end
                    ? $pos['startOffset'] : self::quoteStart($sourceLine, $marks));
            $masked = $this->masked($sourceLine);
            $body = self::trimEnd(substr($masked, $start - $sourceLine->start));
            $rawBody = $masked === $sourceLine->text
                ? $body : self::trimEnd(substr($sourceLine->text, $start - $sourceLine->start));
            $quoteAt = strpos($sourceLine->text, '>');
            $record = (object)[
                'number' => $i + 1,
                'start' => $sourceLine->start,
                'end' => $sourceLine->end,
                'text' => $masked,
                'body' => $body,
                'body_start' => $start,
                'body_end' => $start + strlen($body),
                'blank' => $leaf === 'none' && $body === '',
                'leaf' => $leaf,
                'leaf_id' => $path,
                'leaf_first' => $pos !== null && $pos['startLine'] === $i + 1,
                'leaf_start' => $pos['startOffset'] ?? -1,
                'leaf_head' => null,
                'leaf_next' => null,
                'table_above' => null,
                'table_below' => null,
                'table_path' => null,
                'row_path' => null,
                'attached_here' => isset($c->attachedLines[$i + 1]),
                'closes_container' => false,
                'ancestor_path' => $linePath,
                'quote_depth' => $this->quoteDepth($path),
                'quote_marks' => null,
                'quote_prefix' => $quoteAt !== false && $quoteAt < $start - $sourceLine->start,
                'raw_body' => $rawBody,
                'lazy' => null,
                'open_colon' => [],
                'shape' => self::shape($body),
                'path' => $linePath,
            ];
            $this->lines[] = $record;
        }
        foreach ($this->lines as $i => $record) {
            $record->prev = $this->lines[$i - 1] ?? null;
            $record->prev2 = $this->lines[$i - 2] ?? null;
            $record->next = $this->lines[$i + 1] ?? null;
            $record->next2 = $this->lines[$i + 2] ?? null;
            $held = $holders[$i];
            if ($held !== null) {
                $record->leaf_head = $this->lines[$held[2]['startLine'] - 1] ?? null;
                $record->leaf_next = $this->lines[$held[2]['endLine']] ?? null;
            } else {
                $record->leaf_next = $record->next;
            }
        }
        // A table row's lines name the row, as carve-js marks them (C2-W-138).
        foreach ($rows as [$first, $last, $rowPath]) {
            for ($i = $first; $i <= $last && isset($this->lines[$i]); $i++) {
                $this->lines[$i]->row_path = $rowPath;
            }
        }
        foreach ($pipeTables as [$first, $last, $tablePath]) {
            $seen = null;
            for ($i = $first; $i <= $last && isset($this->lines[$i]); $i++) {
                $line = $this->lines[$i];
                $line->table_above = $seen;
                $line->table_path ??= $tablePath;
                if (!str_starts_with($line->raw_body, '%%')) {
                    $seen = $line;
                }
            }
            $seen = null;
            for ($i = min($last, count($this->lines) - 1); $i >= $first; $i--) {
                $line = $this->lines[$i];
                $line->table_below = $seen;
                if (!str_starts_with($line->raw_body, '%%')) {
                    $seen = $line;
                }
            }
        }
        unset($holders, $held, $record, $sourceLine, $node, $pos, $item);
        $needBounds = str_contains($c->source, ':::');
        if (!$needBounds) {
            foreach ($this->lines as $line) {
                if ($line->path === '') {
                    $needBounds = true;

                    break;
                }
            }
        }
        $this->buildNodes($needBounds);
        if ($needBounds) {
            $this->markColonContainers();
        }
        if ($fragments) {
            $this->renderedIds($seenIds);
        }
    }

    /**
     * C2-W-150: the ids only the rendered HTML carries, footnote and note marks and raw HTML anchors. Only a fragment
     * link reads them, so only a document with one pays for the render.
     *
     * @param array<string, int|true> $seen The ids with a record.
     *
     * @return void
     */
    private function renderedIds(array $seen): void
    {
        $options = array_intersect_key($this->context->options, ['includes' => true, 'headingIds' => true]);
        try {
            $html = Carve::render($this->context->source, $options);
        } catch (Throwable) {
            return;
        }
        preg_match_all('/<[a-zA-Z][^>]*?\sid="([^"]*)"|<a\b[^>]*?\sname="([^"]*)"/', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $id = html_entity_decode(($match[2] ?? '') !== '' ? $match[2] : ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $this->ids[] = (object)[
                'type' => 'id',
                'id' => $id,
                'owner' => 'html',
                'index' => count($this->ids),
                'start' => 0,
                'rendered' => true,
                'xref' => false,
            ];
        }
    }

    /**
     * C2-B-207: the paths of the panels of a figure group, as the resolver finds them.
     *
     * @param array<mixed> $group
     * @param string $path
     *
     * @return array<string>
     */
    public static function panelPaths(array $group, string $path): array
    {
        $paths = [];
        foreach (is_array($group['children'] ?? null) ? $group['children'] : [] as $k => $child) {
            $type = is_array($child) ? ($child['type'] ?? null) : null;
            $captioned = in_array($type, ['paragraph', 'code_block', 'block_quote', 'math'], true) && is_array($child['caption'] ?? null);
            if ($type === 'table' || $captioned) {
                $paths[] = $path . '/children/' . $k;
            }
        }

        return $paths;
    }

    /**
     * C2-B-207: a table, figure, figure group or panel whose caption holds a number mark.
     *
     * @param mixed $node
     *
     * @return bool
     */
    public static function numberedCaption(mixed $node): bool
    {
        $caption = is_array($node) ? ($node['caption'] ?? null) : null;
        $stack = is_array($caption) ? [$caption] : [];
        while ($stack !== []) {
            $next = array_pop($stack);
            if (!is_array($next)) {
                continue;
            }
            if (($next['type'] ?? null) === 'caption_number') {
                return true;
            }
            foreach (is_array($next['children'] ?? null) ? $next['children'] : [] as $child) {
                $stack[] = $child;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     */
    private static function ruby(array $node): string
    {
        $out = '';
        foreach (is_array($node['pairs'] ?? null) ? $node['pairs'] : [] as $pair) {
            if (is_array($pair)) {
                $out .= self::plain($pair['base'] ?? []) . '(' . self::plain($pair['annotation'] ?? []) . ')';
            }
        }

        return $out;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * C2-H-143: the plain text of decoded inline nodes.
     *
     * @param mixed $nodes
     *
     * @return string
     */
    private static function plain(mixed $nodes): string
    {
        $out = '';
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? null;
            $out .= match ($type) {
                'text', 'code', 'literal_inline', 'math' => self::str($node['value'] ?? null),
                'soft_break', 'hard_break' => ' ',
                'non_breaking_space' => "\u{00A0}",
                'image' => self::str($node['alt'] ?? null),
                'mention' => '@' . self::str($node['target'] ?? null),
                'tag' => '#' . self::str($node['name'] ?? null),
                'smart_punctuation' => self::str($node['glyph'] ?? $node['source'] ?? null),
                'autolink' => self::str($node['text'] ?? $node['href'] ?? null),
                'caption_number' => is_int($node['n'] ?? null) ? (string)$node['n'] : '',
                'abbreviation' => self::str($node['key'] ?? null),
                'symbol' => '',
                'ruby' => self::ruby($node),
                'footnote_ref', 'inline_footnote', 'xref', 'raw_inline', 'comment', 'citation_group' => '',
                default => self::plain($node['children'] ?? []),
            };
        }

        return $out;
    }

    private static function shape(string $body): string
    {
        if ($body === '') {
            return 'blank';
        }
        switch ($body[0]) {
            case ':':
                if (preg_match('/^:{3,}[ \t]*$/', $body)) {
                    return 'div_bare_run';
                }
                if (preg_match('/^::(?: |$)/', $body)) {
                    return 'term';
                }
                if (preg_match('/^:[^:]/', $body)) {
                    return 'description';
                }

                break;
            case '{':
                if (preg_match('/^\{[ \t]*(?:[.#_A-Za-z])[^{}]*\}$/', $body)) {
                    return 'attribute_line';
                }

                break;
            case '#':
            case '=':
                if (preg_match('/^[#=]{1,6} +\S/', $body)) {
                    return 'heading';
                }

                break;
            case '>':
                if (preg_match('/^>(?: |$)/', $body)) {
                    return 'quote';
                }
        }

        return 'text';
    }

    /**
     * @return list<string>
     */
    public static function ancestors(Context $context, string $path): array
    {
        $types = [];
        while ($path !== '') {
            $path = self::pathParent($path);
            if (isset($context->nodeTypes[$path])) {
                $types[] = $context->nodeTypes[$path];
            }
        }

        return $types;
    }

    public static function quoteMarks(string $text): int
    {
        $at = 0;
        $marks = 0;
        while (true) {
            $at += strspn($text, " \t", $at);
            if (($text[$at] ?? '') !== '>' || !in_array($text[$at + 1] ?? '', ['', ' ', "\t"], true)) {
                return $marks;
            }
            $marks++;
            $at++;
        }
    }

    public static function lazy(Context $context, stdClass $line): bool
    {
        if (
            $line->leaf_first || !in_array(
                $line->leaf,
                ['paragraph', 'caption', 'definition_term', 'heading', 'line', 'table_cell'],
                true,
            )
        ) {
            return false;
        }
        $line->quote_marks ??= self::quoteMarks($line->text);
        if ($line->quote_marks < $line->quote_depth) {
            return true;
        }
        $path = $line->leaf_id;
        while ($path !== '') {
            $path = self::pathParent($path);
            $content = $context->layout->contentColumn($path);
            if ($content !== null) {
                return $line->body_start - $line->start < $content;
            }
        }

        return false;
    }

    // Container metadata combines marker, content, and quote positions.
    // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

    private function container(string $path, int $quotes): stdClass
    {
        if (isset($this->containerCache[$quotes][$path])) {
            return $this->containerCache[$quotes][$path];
        }
        $type = $this->context->nodeTypes[$path] ?? 'document';
        $isQuoteOrDocument = isset(self::ZERO_CONTENT[$type]);
        $layoutContent = $isQuoteOrDocument ? null : $this->context->layout->contentColumn($path);
        $content = $isQuoteOrDocument ? 0
            : ($layoutContent
                ?? $this->context->layout->columnOf($this->context->layout->startOffset($path) ?? 0, $quotes));
        if ($layoutContent === null && isset(self::MARKER_CONTENT[$type])) {
            $from = $this->openerStart($this->context->layout->startOffset($path) ?? 0, $quotes);
            $lineEnd = strpos($this->context->source, "\n", $from);
            $written = substr($this->context->source, $from, ($lineEnd === false ? strlen($this->context->source) : $lineEnd) - $from);
            $marker = match ($type) {
                'list_item' => '/^(?:[-*]|[0-9]+[.)]|[A-Za-z][.)])[ \t]+/',
                'footnote' => '/^\[\^[^\]]+\]:[ \t]+/',
                default => '/^:[ \t]+/',
            };
            if (preg_match($marker, $written, $match)) {
                $content = max($content, $this->context->layout->columnOf($from + strlen($match[0]), $quotes));
            }
        }

        return $this->containerCache[$quotes][$path] = (object)[
            'type' => $type,
            'content_column' => $content,
            'marker_column' => $this->context->layout->markerColumn($path),
        ];
    }

    private function openerStart(int $start, int $quotes): int
    {
        $s = $this->context->source;
        $at = $start;
        $sawAttribute = false;
        while (($s[$at] ?? '') === '{' || ($sawAttribute && substr($s, $at, 2) === '%%')) {
            $sawAttribute = true;
            $end = strpos($s, "\n", $at);
            if ($end === false) {
                return $start;
            }
            $at = $end + 1;
            $at += strspn($s, " \t", $at);
            for ($left = $quotes; $left > 0 && ($s[$at] ?? '') === '>'; $left--) {
                $at++;
                $at += strspn($s, " \t", $at);
            }
        }

        return $at;
    }

    // Node records retain the shape-specific fields required by the shared data grammar.
    // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

    private function buildNodes(bool $needBounds): void
    {
        $c = $this->context;
        foreach ($this->blockWalked as $item) {
            $lastLine = null;
            $openerText = null;
            $node = $item['node'];
            if ($item['path'] === '') {
                continue;
            }
            $nodePos = $node['pos'] ?? null;
            if (
                $this->plainTableCellGate && !$needBounds && ($node['type'] ?? null) === 'table_cell' && !isset($node['span'])
                && is_array($nodePos) && is_int($nodePos['startOffset'] ?? null) && is_int($nodePos['endOffset'] ?? null)
                && $nodePos['endOffset'] >= $nodePos['startOffset']
                && strcspn($c->source, '<>~?{^', $nodePos['startOffset'], $nodePos['endOffset'] - $nodePos['startOffset'])
                    === $nodePos['endOffset'] - $nodePos['startOffset']
            ) {
                continue;
            }
            $pos = $item['pos'];
            // C2-W-140: every block has a record; a row a continuation joined has no `pos` (C2-A-158), so its layout gives the span.
            $entry = $pos === null ? ($c->layoutEntries[$item['path']] ?? null) : null;
            /** @var array{0: int, 1: int}|null $span */
            $span = is_int($entry['startByte'] ?? null) && is_int($entry['endByte'] ?? null)
                ? [$entry['startByte'], $entry['endByte']] : null;
            if ($pos === null && $span === null) {
                continue;
            }
            $type = $item['type'];
            [$start, $end] = $pos === null ? $span : [$pos['startOffset'], $pos['endOffset']];
            // The encoder counts lines from the same offsets, so a node's own start line is the line of its start.
            $included = $span !== null || (is_array($node['pos'] ?? null) && isset($node['pos']['file']));
            $line = $this->lines[($included ? $c->lineOf($start) : $pos['startLine']) - 1] ?? null;
            if ($line === null) {
                continue;
            }
            $parentType = $c->nodeTypes[$item['parent']] ?? 'document';
            if (
                !(($this->mightUseBlock)($type, $parentType))
                && !in_array($type, ['div', 'directive', 'admonition', 'figure_group', 'line_block'], true)
            ) {
                // All blocks still contribute their source spans to line ownership, even when no rule can read a record.
                if ($needBounds) {
                    $this->blocks[] = (object)[
                        'type' => $type,
                        'path' => $item['path'],
                        'start' => $start,
                        'end' => $end,
                        'opener_start' => $start,
                    ];
                }

                continue;
            }
            $quotes = $this->quoteDepth($item['path']);
            $ownerPath = $item['parent'];
            while (($c->nodeTypes[$ownerPath] ?? '') === 'definition_list' && $ownerPath !== '') {
                $ownerPath = self::pathParent($ownerPath);
            }
            $itemPath = $ownerPath;
            while (in_array($c->nodeTypes[$itemPath] ?? '', ['admonition', 'directive', 'div', 'figure_group'], true) && $itemPath !== '') {
                $itemPath = self::pathParent($itemPath);
            }
            if (($c->nodeTypes[$itemPath] ?? '') !== 'list_item') {
                $itemPath = '';
            }
            $opener = $node['attrs'] ?? null ? $this->openerStart($start, $quotes) : $start;
            $bounds = [
                'type' => $type,
                'path' => $item['path'],
                'start' => $start,
                'end' => $end,
                'opener_start' => $opener,
            ];
            if (in_array($type, ['div', 'directive', 'admonition', 'figure_group', 'line_block'], true)) {
                $endLine = strpos($c->source, "\n", $opener);
                $openerText = self::trimEnd(substr($c->source, $opener, ($endLine === false ? strlen($c->source) : $endLine) - $opener));
                $lastLine = $this->lines[($span === null ? $pos['endLine'] : $c->lineOf(max($start, $end - 1))) - 1] ?? $line;
                $bounds['opener_text'] = $openerText;
                $bounds['line'] = $line;
                $bounds['last_line'] = $lastLine;
            }
            if ($needBounds) {
                $this->blocks[] = (object)$bounds;
            }
            $itemOwnerType = $c->nodeTypes[$itemPath] ?? 'document';
            if (!(($this->acceptBlock)($type, $parentType, $itemOwnerType, $node, $start, $end, $item['index']))) {
                continue;
            }
            if ($lastLine === null) {
                $lastLine = $this->lines[($span === null ? $pos['endLine'] : $c->lineOf(max($start, $end - 1))) - 1] ?? $line;
            }
            $owner = $this->container($ownerPath, $quotes);
            $itemOwner = $itemPath === $ownerPath ? $owner : $this->container($itemPath, $quotes);
            $parent = $item['parent'] === $ownerPath ? $owner
                : ($item['parent'] === $itemPath ? $itemOwner : $this->container($item['parent'], $quotes));
            $record = (object)[
                'type' => $type,
                'path' => $item['path'],
                'identity' => $item['path'],
                'start' => $start,
                'end' => $end,
                'text' => null,
                'line' => $line,
                'last_line' => $lastLine,
                'column' => null,
                'opener_column' => null,
                'mark_column' => null,
                'quote_depth_internal' => $quotes,
                'opener_start' => $opener,
                'opener_text' => $openerText,
                'owner' => $owner,
                'item_owner' => $itemOwner,
                'parent' => $parent,
                'index' => $item['index'],
                'attributed' => isset($node['attrs']),
                'image_line' => $type === 'paragraph' && self::imageLine($node),
                'after_quote_mark' => null,
                'prev_type' => null,
            ];
            if (in_array($type, ['table', 'table_row', 'table_cell'], true)) {
                $tablePath = $item['path'];
                while ($tablePath !== '' && ($c->nodeTypes[$tablePath] ?? '') !== 'table') {
                    $tablePath = self::pathParent($tablePath);
                }
                $record->source_format = $tablePath !== '' ? $c->layout->sourceFormat($tablePath) : null;
            }
            if ($type === 'table_row' || $type === 'table_cell') {
                $rowPath = $type === 'table_row' ? $item['path'] : self::pathParent($item['path']);
                $groupType = $c->nodeTypes[self::pathParent($rowPath)] ?? null;
                $record->group = in_array($groupType, ['table_head', 'table_body', 'table_foot'], true) ? $groupType : null;
                $record->row_index = (int)substr($rowPath, strrpos($rowPath, '/') + 1);
            }
            if (isset($c->dataNodeTypes[$type]) || isset($c->dataNodeTypes['*'])) {
                $record->node = null;
                $record->nodeSource = $node;
            }
            ($this->onBlock)($record);
        }
        $this->blockWalked = [];
    }

    /**
     * @param int $at
     * @param list<\stdClass> $bounds
     * @param int $next
     * @param array<int, \stdClass> $open
     */
    private static function boundaryAt(int $at, array $bounds, int &$next, array &$open): string
    {
        while (isset($bounds[$next]) && $bounds[$next]->start <= $at) {
            $open[] = $bounds[$next++];
        }
        $found = '';
        foreach ($open as $i => $block) {
            if ($block->end <= $at) {
                unset($open[$i]);
            } elseif (strlen($block->path) > strlen($found)) {
                $found = $block->path;
            }
        }

        return $found;
    }

    /**
     * @param list<\stdClass> $blocks
     * @param bool $pathTie
     * @param string $field
     */
    private static function orderBlocks(array &$blocks, string $field = 'start', bool $pathTie = true): void
    {
        $previous = null;
        foreach ($blocks as $block) {
            if (
                $previous !== null
                && ($previous->$field > $block->$field
                    || ($pathTie && $previous->$field === $block->$field
                        && strlen($previous->path) > strlen($block->path)))
            ) {
                usort($blocks, static fn (stdClass $a, stdClass $b): int => ($a->$field <=> $b->$field)
                    ?: ($pathTie ? strlen($a->path) <=> strlen($b->path) : 0));

                return;
            }
            $previous = $block;
        }
    }

    private function fillPathsOnly(): void
    {
        $previous = null;
        foreach ($this->blocks as $block) {
            if (
                $previous !== null
                && ($previous->start > $block->start
                    || ($previous->start === $block->start && strlen($previous->path) > strlen($block->path)))
            ) {
                usort($this->blocks, static fn (stdClass $a, stdClass $b): int => ($a->start <=> $b->start)
                    ?: (strlen($a->path) <=> strlen($b->path)));

                break;
            }
            $previous = $block;
        }
        $next = 0;
        $open = [];
        foreach ($this->lines as $line) {
            while (isset($this->blocks[$next]) && $this->blocks[$next]->start <= $line->body_start) {
                $open[] = $this->blocks[$next++];
            }
            foreach ($open as $i => $block) {
                if ($block->end < $line->start) {
                    unset($open[$i]);
                } elseif ($line->path === '' && strlen($block->path) > strlen($line->path)) {
                    $line->path = $block->path;
                }
            }
        }
    }

    /**
     * A colon container is closed by a bare run of at least its width on a later line of its own
     * that no block inside it covers: a run that closed a child is the child's closer.
     *
     * @param list<\stdClass> $colon
     * @param list<\stdClass> $blocks Source-ordered block bounds.
     *
     * @return array<string, true> The paths of the closed containers.
     */
    private function closedColons(array $colon, array $blocks): array
    {
        $byPath = [];
        foreach ($colon as $entry) {
            $byPath[$entry->path] = $entry;
        }
        if ($byPath === []) {
            return [];
        }
        $runs = [];
        foreach ($this->context->lines as $sourceLine) {
            if (!str_contains($sourceLine->text, ':')) {
                continue;
            }
            $from = self::quoteStart($sourceLine);
            $text = rtrim(substr($sourceLine->text, $from - $sourceLine->start));
            if (strlen($text) >= 3 && strspn($text, ':') === strlen($text)) {
                $runs[] = [$from, $from + strlen($text), $sourceLine->number];
            }
        }
        $closed = [];
        if ($runs === []) {
            return $closed;
        }
        $next = 0;
        $stack = [];
        foreach ($runs as [$start, $end, $number]) {
            while (isset($blocks[$next]) && $blocks[$next]->start <= $start) {
                $block = $blocks[$next++];
                while ($stack !== [] && $stack[array_key_last($stack)]->end < $block->start) {
                    array_pop($stack);
                }
                $stack[] = $block;
            }
            while ($stack !== [] && $stack[array_key_last($stack)]->end < $end) {
                array_pop($stack);
            }
            $entry = $stack === [] ? null : ($byPath[$stack[array_key_last($stack)]->path] ?? null);
            if (
                $entry !== null && $number > $entry->first_line && $number <= $entry->last_line
                && $end - $start >= $entry->width
            ) {
                $closed[$entry->path] = true;
            }
        }

        return $closed;
    }

    // Track nested colon runs and container boundaries in source order.
    // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded
    private function markColonContainers(): void
    {
        if (!str_contains($this->context->source, ':::')) {
            $this->fillPathsOnly();

            return;
        }
        $pathBlocks = $this->blocks;
        self::orderBlocks($pathBlocks);
        $bounds = array_values(array_filter($pathBlocks, static fn (stdClass $block): bool => in_array(
            $block->type,
            ['block_quote', 'list_item', 'footnote', 'definition_description'],
            true,
        )));
        $nextBound = 0;
        $openBounds = [];
        $colon = [];
        $colonBlocks = array_values(array_filter($pathBlocks, static fn (stdClass $block): bool => in_array(
            $block->type,
            ['div', 'directive', 'admonition', 'figure_group', 'line_block'],
            true,
        )));
        self::orderBlocks($colonBlocks, 'opener_start', false);
        foreach ($colonBlocks as $block) {
            if (
                preg_match('/^(:{3,})/', $block->opener_text, $match) !== 1
            ) {
                continue;
            }
            $start = $block->opener_start;
            $kind = match ($block->type) {
                'admonition' => 'admonition', 'figure_group' => 'figure group', 'line_block' => 'line block',
                default => preg_match('/^:{3,}[ \t]+\\\\(?:[ \t]|$)/', $block->opener_text) ? 'hard-break block' : 'div',
            };
            $colon[] = (object)[
                'width' => strlen($match[1]),
                'line' => $this->context->lineOf($start),
                'column' => $start - ($this->lines[$this->context->lineOf($start) - 1]->start ?? 0) + 1,
                'kind' => $kind,
                'start' => $start,
                'end' => $block->end,
                'owner' => self::boundaryAt($start, $bounds, $nextBound, $openBounds),
                'last_line' => $block->last_line->number,
                'first_line' => $block->line->number,
                'path' => $block->path,
            ];
        }
        $closed = $this->closedColons($colon, $pathBlocks);
        foreach ($colon as $entry) {
            $entry->closed = isset($closed[$entry->path]);
        }
        usort($colon, static fn (stdClass $a, stdClass $b): int => $a->start <=> $b->start);
        $nextBound = 0;
        $openBounds = [];
        $nextColon = 0;
        $openColon = [];
        $nextPath = 0;
        $openPaths = [];
        foreach ($this->lines as $line) {
            while (isset($pathBlocks[$nextPath]) && $pathBlocks[$nextPath]->start <= $line->body_start) {
                $openPaths[] = $pathBlocks[$nextPath++];
            }
            while ($openPaths !== [] && $openPaths[array_key_last($openPaths)]->end < $line->start) {
                array_pop($openPaths);
            }
            if ($line->path === '') {
                $line->path = $openPaths === [] ? '' : $openPaths[array_key_last($openPaths)]->path;
            }
            // Only a bare colon run reads open-colon metadata or can close a container.
            if ($line->shape !== 'div_bare_run') {
                continue;
            }
            $boundary = self::boundaryAt($line->body_start, $bounds, $nextBound, $openBounds);
            while (isset($colon[$nextColon]) && $colon[$nextColon]->start < $line->body_start) {
                $openColon[] = $colon[$nextColon++];
            }
            foreach ($openColon as $i => $entry) {
                if ($entry->closed && $line->number > $entry->last_line) {
                    unset($openColon[$i]);

                    continue;
                }
                $gap = $entry->end < $line->start - 1
                    ? substr($this->context->source, $entry->end, $line->start - $entry->end) : '';
                if ($entry->end < $line->start - 1 && trim($gap, " \t\r\n>") !== '') {
                    unset($openColon[$i]);

                    continue;
                }
                if (
                    $entry->start < $line->body_start
                    && ($entry->end >= $line->start - 1 || (!$entry->closed && trim($gap, " \t\r\n>") === ''))
                    && $entry->owner === $boundary
                ) {
                    $line->open_colon[] = $entry;
                }
            }
            usort($line->open_colon, static fn (stdClass $a, stdClass $b): int => $b->start <=> $a->start);
            foreach ($line->open_colon as $entry) {
                if ($entry->width <= strlen($line->body)) {
                    $line->closes_container = true;

                    break;
                }
            }
        }
    }
}
