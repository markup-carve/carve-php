<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use stdClass;

/** The source and tree records named by C2-W-139 to C2-W-142. All offsets are source bytes. */
final class DataModel
{
    private const LEAVES = [
        'paragraph' => true, 'caption' => true, 'definition_term' => true, 'heading' => true,
        'line' => true, 'table_cell' => true, 'table_row' => true, 'code_block' => true,
        'raw_block' => true, 'frontmatter' => true, 'comment_block' => true,
    ];

    private const VERBATIM = ['code_block' => true, 'raw_block' => true, 'frontmatter' => true, 'comment_block' => true];

    /** @var list<stdClass> */
    public array $lines = [];

    /** @var list<stdClass> */
    public array $blocks = [];

    /** @var list<stdClass> */
    public array $inline = [];

    public function __construct(private Context $context)
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

    private static function quoteStart(Line $line, int $marks = PHP_INT_MAX): int
    {
        $text = $line->text;
        $at = strspn($text, " \t");
        while ($marks-- > 0 && ($text[$at] ?? '') === '>' && (($text[$at + 1] ?? '') === ' ' || $at + 1 === strlen($text))) {
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

    private function build(): void
    {
        $c = $this->context;
        $count = count($c->lines);
        if ($count > 1 && $c->lines[$count - 1]->text === '' && str_ends_with($c->source, "\n")) {
            $count--;
        }
        $holders = array_fill(0, $count, null);
        foreach ($c->walked as $item) {
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
            $marks = isset(self::VERBATIM[$leaf]) ? $c->layout->quoteDepth($path) : PHP_INT_MAX;
            $start = $sourceLine->content !== null && $sourceLine->content >= $sourceLine->start && $sourceLine->content <= $sourceLine->end
                ? $sourceLine->content
                : ($pos !== null && $pos['startLine'] === $i + 1 && $pos['startOffset'] >= $sourceLine->start && $pos['startOffset'] <= $sourceLine->end
                    ? $pos['startOffset'] : self::quoteStart($sourceLine, $marks));
            $body = self::trimEnd(substr($sourceLine->text, $start - $sourceLine->start));
            $record = (object)[
                'number' => $i + 1, 'start' => $sourceLine->start, 'end' => $sourceLine->end,
                'text' => $sourceLine->text, 'body' => $body, 'body_start' => $start,
                'body_end' => $start + strlen($body), 'blank' => $leaf === 'none' && $body === '',
                'leaf' => $leaf, 'leaf_id' => $path, 'leaf_first' => $pos !== null && $pos['startLine'] === $i + 1,
                'leaf_start' => $pos['startOffset'] ?? -1, 'leaf_head' => null, 'leaf_next' => null,
                'attached_here' => isset($c->attachedLines[$i + 1]),
                'closes_container' => false, 'ancestors' => $this->ancestors($path),
                'quote_depth' => $c->layout->quoteDepth($path), 'quote_marks' => 0,
                'quote_prefix' => str_contains(substr($sourceLine->text, 0, $start - $sourceLine->start), '>'),
                'raw_body' => $body, 'lazy' => false, 'open_colon' => [], 'shape' => self::shape($body),
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

    /** @return list<string> */
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
        if ($line->leaf_first || !in_array($line->leaf, ['paragraph', 'caption', 'definition_term', 'heading', 'line', 'table_cell'], true)) {
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

    private function container(string $path, int $quotes): stdClass
    {
        $type = $this->context->nodeTypes[$path] ?? 'document';
        $content = in_array($type, ['document', 'block_quote'], true) ? 0
            : ($this->context->layout->contentColumn($path)
                ?? $this->context->layout->columnOf($this->context->layout->startOffset($path) ?? 0, $quotes));

        return (object)[
            'type' => $type, 'content_column' => $content,
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

    private function buildNodes(): void
    {
        $c = $this->context;
        foreach ($c->walked as $item) {
            $node = $item['node'];
            $pos = Support::pos($node);
            if ($pos === null || $item['path'] === '') {
                continue;
            }
            $type = self::type($node);
            $parentType = $c->nodeTypes[$item['parent']] ?? 'document';
            if (in_array($parentType, ['paragraph', 'caption', 'definition_term', 'heading', 'line', 'cell_paragraph'], true)) {
                $this->inline[] = (object)[
                    'type' => $type, 'path' => $item['path'], 'parent' => $parentType,
                    'leaf' => $parentType, 'start' => $pos['startOffset'], 'end' => $pos['endOffset'],
                    'text' => substr($c->source, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']),
                ];
            }
            $start = $pos['startOffset'];
            $line = $this->lines[$c->lineOf($start) - 1] ?? null;
            if ($line === null) {
                continue;
            }
            $quotes = $c->layout->quoteDepth($item['path']);
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
            $this->blocks[] = (object)[
                'type' => $type, 'path' => $item['path'], 'start' => $start, 'end' => $pos['endOffset'],
                'text' => substr($c->source, $start, $pos['endOffset'] - $start),
                'line' => $line, 'last_line' => $this->lines[$pos['endLine'] - 1] ?? $line,
                'column' => $column, 'opener_column' => $c->layout->markerColumn($item['path']) ?? $column,
                'mark_column' => $markColumn, 'opener_start' => $opener, 'opener_text' => $openerText,
                'owner' => $this->container($ownerPath, $quotes),
                'item_owner' => $this->container($itemPath, $quotes),
                'parent' => $this->container($item['parent'], $quotes),
                'index' => $item['index'], 'attributed' => isset($node['attrs']),
                'image_line' => $type === 'paragraph' && count($node['children'] ?? []) === 1 && ($node['children'][0]['type'] ?? '') === 'image',
                'after_quote_mark' => str_ends_with(substr($c->source, $line->start, $start - $line->start), '> '),
                'prev_type' => null,
            ];
        }
    }

    private function boundary(int $at): string
    {
        $found = '';
        foreach ($this->blocks as $block) {
            if (!in_array($block->type, ['block_quote', 'list_item', 'footnote', 'definition_description'], true)
                || $block->start > $at || $block->end <= $at) {
                continue;
            }
            if (strlen($block->path) > strlen($found)) {
                $found = $block->path;
            }
        }

        return $found;
    }

    private function markColonContainers(): void
    {
        $colon = [];
        foreach ($this->blocks as $block) {
            if (!in_array($block->type, ['div', 'directive', 'admonition', 'figure_group', 'line_block'], true)
                || preg_match('/^(:{3,})/', $block->opener_text, $match) !== 1) {
                continue;
            }
            $start = $block->opener_start;
            $kind = match ($block->type) {
                'admonition' => 'admonition', 'figure_group' => 'figure group', 'line_block' => 'line block',
                default => preg_match('/^:{3,}[ \t]+\\\\(?:[ \t]|$)/', $block->opener_text) ? 'hard-break block' : 'div',
            };
            $colon[] = (object)[
                'width' => strlen($match[1]), 'line' => $this->context->lineOf($start),
                'column' => $start - ($this->lines[$this->context->lineOf($start) - 1]->start ?? 0) + 1,
                'kind' => $kind, 'start' => $start, 'end' => $block->end,
                'owner' => $this->boundary($start),
                'closed' => $block->last_line->number > $block->line->number
                    && preg_match('/^:{3,}$/', $block->last_line->body) === 1
                    && strlen($block->last_line->body) >= strlen($match[1]),
            ];
        }
        foreach ($this->lines as $line) {
            if ($line->path === '') {
                foreach ($this->blocks as $block) {
                    if ($block->start <= $line->body_start && $block->end >= $line->start && strlen($block->path) > strlen($line->path)) {
                        $line->path = $block->path;
                    }
                }
            }
            $boundary = $this->boundary($line->body_start);
            foreach ($colon as $entry) {
                $gap = $entry->end < $line->start - 1
                    ? substr($this->context->source, $entry->end, $line->start - $entry->end) : '';
                if ($entry->start < $line->body_start && ($entry->end >= $line->start - 1 || (!$entry->closed && trim($gap, " \t\r\n>") === ''))
                    && $entry->owner === $boundary) {
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
