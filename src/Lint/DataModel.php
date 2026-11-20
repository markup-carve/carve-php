<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Closure;
use stdClass;

final class DataModel
{
    /**
     * @var array
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

    /**
     * @var array
     */
    private const VERBATIM = ['code_block' => true, 'raw_block' => true, 'frontmatter' => true, 'comment_block' => true];

    /**
     * @var array
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
     * @var array
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
     * @var list<\stdClass>
     */
    public array $lines = [];

    /**
     * @var list<\stdClass>
     */
    public array $blocks = [];

    /**
     * @var array<string, true>
     */
    private array $taskPaths = [];

    /**
     * @var list<array{node: array<string, mixed>, path: string, parent: string, index: int}>
     */
    private array $blockWalked = [];

    /**
     * @var array<string, array{type: string, inline: bool}>
     */
    private array $holderByPath = [];

    /**
     * @var array<string, \stdClass>
     */
    private array $containerCache = [];

    /**
     * @var array<string, int>
     */
    private array $quoteDepths = ['' => 0];

    /**
     * @param \MarkupCarve\Carve\Lint\Context $context
     * @param \Closure(\stdClass): void $onInline
     * @param \Closure(\stdClass): void $onBlock
     */
    public function __construct(private Context $context, private Closure $onInline, private Closure $onBlock)
    {
        $this->build();
    }

    private static function type(array $node): string
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';

        return $type === 'comment' && ($node['form'] ?? null) === 'block' ? 'comment_block' : $type;
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

    private static function trimEnd(string $text): string
    {
        return rtrim($text, " \t");
    }

    private function masked(Line $line): string
    {
        if ($line->opaque) {
            return $line->text;
        }
        $text = $line->text;
        $length = strlen($text);
        foreach ($this->context->leafMask as [$from, $to]) {
            if ($to <= $line->start || $from >= $line->end) {
                continue;
            }
            $a = max(0, $from - $line->start);
            $b = min($length, $to - $line->start);
            for ($i = $a; $i < $b; $i++) {
                $text[$i] = "\0";
            }
        }
        for ($i = 0; $i < $length; $i++) {
            $code = ord($line->text[$i]);
            $punctuation = ($code >= 33 && $code <= 47) || ($code >= 58 && $code <= 64)
                || ($code >= 91 && $code <= 96) || ($code >= 123 && $code <= 126);
            if (!$punctuation) {
                continue;
            }
            $slashes = 0;
            for ($j = $i - 1; $j >= 0 && $line->text[$j] === '\\'; $j--) {
                $slashes++;
            }
            if ($slashes % 2 === 1) {
                $text[$i] = "\0";
            }
        }

        return $text;
    }

    // This assembles the line, block, and inline projections in a single source walk.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    private function build(): void
    {
        $c = $this->context;
        foreach ($c->walked as $item) {
            $parentHolder = $this->holderByPath[$item['parent']] ?? null;
            $type = self::type($item['node']);
            if ($type === 'list_item' && isset($item['node']['task'])) {
                $this->taskPaths[$item['path']] = true;
            }
            if ($parentHolder !== null && $parentHolder['inline']) {
                $pos = Support::pos($item['node']);
                if ($pos !== null && $item['path'] !== '') {
                    ($this->onInline)((object)[
                        'type' => $type,
                        'path' => $item['path'],
                        'parent' => $c->nodeTypes[$item['parent']] ?? 'document',
                        'leaf' => $parentHolder['type'],
                        'start' => $pos['startOffset'],
                        'end' => $pos['endOffset'],
                        'text' => substr($c->source, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']),
                    ]);
                }
            } elseif ($parentHolder === null) {
                $this->blockWalked[] = $item;
            }
            $this->holderByPath[$item['path']] = isset(self::INLINE_HOLDERS[$type])
                ? ['type' => $type, 'inline' => isset(self::INLINE_LEAVES[$type])]
                : $parentHolder;
        }
        $c->walked = [];
        $this->holderByPath = [];
        $count = count($c->lines);
        if ($count > 1 && $c->lines[$count - 1]->text === '' && str_ends_with($c->source, "\n")) {
            $count--;
        }
        $holders = array_fill(0, $count, null);
        foreach ($this->blockWalked as $item) {
            $node = $item['node'];
            $pos = Support::pos($node);
            $type = self::type($node);
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
            $rawBody = self::trimEnd(substr($sourceLine->text, $start - $sourceLine->start));
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
                'attached_here' => isset($c->attachedLines[$i + 1]),
                'closes_container' => false,
                'ancestors' => $this->ancestors($path),
                'quote_depth' => $this->quoteDepth($path),
                'quote_marks' => 0,
                'quote_prefix' => str_contains(substr($sourceLine->text, 0, $start - $sourceLine->start), '>'),
                'raw_body' => $rawBody,
                'lazy' => false,
                'open_colon' => [],
                'shape' => self::shape($body),
                'path' => $path !== '' ? $path : $sourceLine->path,
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
            $record->quote_marks = $this->quoteMarks($record->text);
            $record->lazy = $this->lazy($record);
        }
        $this->buildNodes();
        $this->markColonContainers();
    }

    private static function shape(string $body): string
    {
        if ($body === '') {
            return 'blank';
        }
        if (preg_match('/^:{3,}[ \t]*$/', $body)) {
            return 'div_bare_run';
        }
        if (preg_match('/^\{[ \t]*(?:[.#_A-Za-z])[^{}]*\}$/', $body)) {
            return 'attribute_line';
        }
        if (preg_match('/^[#=]{1,6} +\S/', $body)) {
            return 'heading';
        }
        if (preg_match('/^>(?: |$)/', $body)) {
            return 'quote';
        }
        if (preg_match('/^::(?: |$)/', $body)) {
            return 'term';
        }
        if (preg_match('/^:[^:]/', $body)) {
            return 'description';
        }

        return 'text';
    }

    /**
     * @return list<string>
     */
    private function ancestors(string $path): array
    {
        $types = [];
        while ($path !== '') {
            $path = self::pathParent($path);
            if (isset($this->context->nodeTypes[$path])) {
                $types[] = $this->context->nodeTypes[$path];
            }
        }

        return $types;
    }

    private function quoteMarks(string $text): int
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

    private function lazy(stdClass $line): bool
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
        if ($line->quote_marks < $line->quote_depth) {
            return true;
        }
        $path = $line->leaf_id;
        while ($path !== '') {
            $path = self::pathParent($path);
            $content = $this->context->layout->contentColumn($path);
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
        $key = $path . ':' . $quotes;
        if (isset($this->containerCache[$key])) {
            return $this->containerCache[$key];
        }
        $type = $this->context->nodeTypes[$path] ?? 'document';
        $content = in_array($type, ['document', 'block_quote'], true) ? 0
            : ($this->context->layout->contentColumn($path)
                ?? $this->context->layout->columnOf($this->context->layout->startOffset($path) ?? 0, $quotes));
        if (in_array($type, ['list_item', 'footnote', 'definition_description'], true)) {
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
        if ($type === 'list_item' && isset($this->taskPaths[$path])) {
            $start = $this->context->layout->startOffset($path) ?? 0;
            $lineEnd = strpos($this->context->source, "\n", $start);
            $written = substr($this->context->source, $start, ($lineEnd === false ? strlen($this->context->source) : $lineEnd) - $start);
            if (preg_match('/^(?:[-*]|[0-9]+[.)]|[A-Za-z][.)])[ \t]+\[[ xX]\][ \t]+/', $written, $match)) {
                $content = max($content, $this->context->layout->columnOf($start + strlen($match[0]), $quotes));
            }
        }

        return $this->containerCache[$key] = (object)[
            'type' => $type,
            'content_column' => $content,
            'marker_column' => $this->context->layout->markerColumn($path),
        ];
    }

    private function openerStart(int $start, int $quotes): int
    {
        $s = $this->context->source;
        $at = $start;
        while (($s[$at] ?? '') === '{') {
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

    private function buildNodes(): void
    {
        $c = $this->context;
        foreach ($this->blockWalked as $item) {
            $node = $item['node'];
            $pos = Support::pos($node);
            if ($pos === null || $item['path'] === '') {
                continue;
            }
            $type = self::type($node);
            $start = $pos['startOffset'];
            $line = $this->lines[$c->lineOf($start) - 1] ?? null;
            if ($line === null) {
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
            $endLine = strpos($c->source, "\n", $opener);
            $openerText = self::trimEnd(substr($c->source, $opener, ($endLine === false ? strlen($c->source) : $endLine) - $opener));
            $column = $c->layout->columnOf($start, $quotes);
            $markColumn = $c->layout->columnOf($opener, $quotes);
            $prevPath = $item['index'] > 0 && preg_match('~/[0-9]+$~', $item['path'])
                ? preg_replace('~/[0-9]+$~', '/' . ($item['index'] - 1), $item['path']) : null;
            $record = (object)[
                'type' => $type,
                'path' => $item['path'],
                'start' => $start,
                'end' => $pos['endOffset'],
                'text' => in_array($type, ['list', 'line', 'frontmatter'], true)
                    ? substr($c->source, $start, $pos['endOffset'] - $start) : '',
                'line' => $line,
                'last_line' => $this->lines[$pos['endLine'] - 1] ?? $line,
                'column' => $column,
                'opener_column' => $c->layout->markerColumn($item['path']) ?? $column,
                'mark_column' => $markColumn,
                'opener_start' => $opener,
                'opener_text' => $openerText,
                'owner' => $this->container($ownerPath, $quotes),
                'item_owner' => $this->container($itemPath, $quotes),
                'parent' => $this->container($item['parent'], $quotes),
                'index' => $item['index'],
                'attributed' => isset($node['attrs']),
                'image_line' => $type === 'paragraph' && ($node['imageLine'] ?? false),
                'after_quote_mark' => str_ends_with(substr($c->source, $line->start, $start - $line->start), '> '),
                'prev_type' => $prevPath === null ? null : ($c->nodeTypes[$prevPath] ?? null),
            ];
            ($this->onBlock)($record);
            $this->blocks[] = (object)[
                'type' => $type,
                'path' => $item['path'],
                'start' => $start,
                'end' => $pos['endOffset'],
                'opener_start' => $opener,
                'opener_text' => $openerText,
                'line' => $line,
                'last_line' => $record->last_line,
            ];
        }
        $this->blockWalked = [];
    }

    /**
     * @param int $at
     * @param list<\stdClass> $bounds
     * @param int $next
     * @param list<\stdClass> $open
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

    // Track nested colon runs and container boundaries in source order.
    // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded
    private function markColonContainers(): void
    {
        $bounds = array_values(array_filter($this->blocks, static fn (stdClass $block): bool => in_array(
            $block->type,
            ['block_quote', 'list_item', 'footnote', 'definition_description'],
            true,
        )));
        usort($bounds, static fn (stdClass $a, stdClass $b): int => ($a->start <=> $b->start) ?: (strlen($a->path) <=> strlen($b->path)));
        $nextBound = 0;
        $openBounds = [];
        $colon = [];
        $colonBlocks = $this->blocks;
        usort($colonBlocks, static fn (stdClass $a, stdClass $b): int => $a->opener_start <=> $b->opener_start);
        foreach ($colonBlocks as $block) {
            if (
                !in_array($block->type, ['div', 'directive', 'admonition', 'figure_group', 'line_block'], true)
                || preg_match('/^(:{3,})/', $block->opener_text, $match) !== 1
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
                'closed' => $block->last_line->number > $block->line->number
                    && preg_match('/^:{3,}$/', $block->last_line->body) === 1
                    && strlen($block->last_line->body) >= strlen($match[1]),
            ];
        }
        usort($colon, static fn (stdClass $a, stdClass $b): int => $a->start <=> $b->start);
        $nextBound = 0;
        $openBounds = [];
        $nextColon = 0;
        $openColon = [];
        $pathBlocks = $this->blocks;
        usort($pathBlocks, static fn (stdClass $a, stdClass $b): int => ($a->start <=> $b->start)
            ?: (strlen($a->path) <=> strlen($b->path)));
        $nextPath = 0;
        $openPaths = [];
        foreach ($this->lines as $line) {
            while (isset($pathBlocks[$nextPath]) && $pathBlocks[$nextPath]->start <= $line->body_start) {
                $openPaths[] = $pathBlocks[$nextPath++];
            }
            if ($line->path === '') {
                foreach ($openPaths as $i => $block) {
                    if ($block->end < $line->start) {
                        unset($openPaths[$i]);
                    } elseif (strlen($block->path) > strlen($line->path)) {
                        $line->path = $block->path;
                    }
                }
            } else {
                foreach ($openPaths as $i => $block) {
                    if ($block->end < $line->start) {
                        unset($openPaths[$i]);
                    }
                }
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
            if ($line->shape === 'div_bare_run') {
                foreach ($line->open_colon as $entry) {
                    if ($entry->width <= strlen($line->body)) {
                        $line->closes_container = true;

                        break;
                    }
                }
            }
        }
    }
}
