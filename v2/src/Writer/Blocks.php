<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Writer;

use Closure;
use MarkupCarve\Carve2\Generated\Tables;
use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Node;

/**
 * The block nodes as Carve source: the layout of C2-W-017 to C2-W-034 and the canonical forms of C2-W-035 to C2-W-060.
 * A block writes its lines behind `prefix`, the prefix of its containers.
 */
final class Blocks
{
    /**
     * @var int
     */
    private const BLOCKS = 0;

    /**
     * @var int
     */
    private const TIGHT_ITEM = 1;

    /**
     * @var int
     */
    private const ENTRIES = 2;

    /**
     * @var array<string>
     */
    private const LAZY = ['list', 'list_item', 'block_quote', 'definition_list', 'definition_description', 'footnote'];

    /**
     * @var array<string>
     */
    private const OPEN = ['list', 'list_item', 'definition_list', 'definition_description', 'footnote'];

    private Inline $inline;

    /**
     * C2-W-080: inside a hard-break block or a line block a hard break is the bare line ending.
     */
    public bool $bareBreaks = false;

    private TableWriter $tables;

    public function __construct(private State $w)
    {
        $this->inline = new Inline($w);
        $this->tables = new TableWriter($w, $this->inline);
    }

    /**
     * @param array<mixed> $nodes
     * @param string $path Path of the list.
     * @param string $prefix
     * @param bool $tight
     *
     * @return void
     */
    public function blocks(array $nodes, string $path, string $prefix, bool $tight = false): void
    {
        $this->siblings($nodes, $path, $prefix, $tight ? self::TIGHT_ITEM : self::BLOCKS);
    }

    /**
     * @param array<mixed> $nodes
     * @param string $path
     * @param string $prefix
     * @param int $layout
     *
     * @return void
     */
    private function siblings(array $nodes, string $path, string $prefix, int $layout): void
    {
        $before = null;
        // The last block that is no comment: comments do not keep two lists apart (C2-B-053).
        $list = null;
        $count = count($nodes);
        for ($at = 0; $at < $count; $at++) {
            $node = $nodes[$at];
            if (!is_array($node)) {
                continue;
            }
            $start = count($this->w->lines);
            $this->beforeSibling($nodes, $at, $before, $list, $path, $prefix, $layout);
            $next = $nodes[$at + 1] ?? null;
            $this->markFenceBeforeComment($node, $path . '/' . $at, is_array($next) ? $next : null);
            $written = count($this->w->lines);
            $this->w->afterDescriptions = is_array($before) && ($before['type'] ?? '') === 'definition_list';
            if (($node['type'] ?? '') === 'code_block' && isset($node['caption'])) {
                $at = $this->captionedCode($nodes, $at, $path, $prefix);
            } else {
                $this->block($node, $path . '/' . $at, $prefix);
            }
            if (count($this->w->lines) === $written) {
                // C2-W-029: a block that writes nothing leaves no blank line behind.
                array_splice($this->w->lines, $start);

                continue;
            }
            $before = $node;
            if (($node['type'] ?? '') !== 'comment') {
                $list = $node;
            }
        }
    }

    /**
     * @param array<mixed> $nodes
     * @param int $at
     * @param array<mixed>|null $before
     * @param array<mixed>|null $list
     * @param string $path
     * @param string $prefix
     * @param int $layout
     *
     * @return void
     */
    private function beforeSibling(array $nodes, int $at, ?array $before, ?array $list, string $path, string $prefix, int $layout): void
    {
        $node = is_array($nodes[$at]) ? $nodes[$at] : [];
        $followingAt = self::afterComments($nodes, $at);
        $following = is_array($nodes[$followingAt] ?? null) ? $nodes[$followingAt] : null;
        $followingPath = $path . '/' . $followingAt;
        $attributes = '';
        if (
            self::isCommentLine($node) && $before !== null && $this->isOpenContainer($before) && $following !== null
            && $this->w->prewritten !== $followingPath
        ) {
            $attributes = $this->followingAttributes($following, $followingPath);
        }
        if ($before !== null) {
            $nested = $layout === self::ENTRIES || ($layout === self::TIGHT_ITEM && $this->isNestedList($before, $node, $at, $path));
            $blanks = $attributes !== '' ? 1 : ($this->w->prewritten === $path . '/' . $at ? 0
                : $this->blankLinesBetween($before, $list, $node, $nested, $path . '/' . $at));
            for (; $blanks > 0; $blanks--) {
                $this->w->emit($prefix, '');
            }
        }
        if ($attributes !== '') {
            $this->w->emit($prefix, $attributes);
            $this->w->prewritten = $followingPath;
        }
    }

    /**
     * @param array<mixed> $nodes
     * @param int $at
     *
     * @return int
     */
    private static function afterComments(array $nodes, int $at): int
    {
        $next = $at + 1;
        while (is_array($nodes[$next] ?? null) && self::isCommentLine($nodes[$next])) {
            $next++;
        }

        return $next;
    }

    /**
     * @param array<mixed>|null $node
     *
     * @return bool
     */
    private static function isCommentLine(?array $node): bool
    {
        return $node !== null && ($node['type'] ?? '') === 'comment' && ($node['form'] ?? '') !== 'block';
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed>|null $list
     * @param array<mixed> $node
     * @param bool $nested
     * @param string $path
     *
     * @return int
     */
    private function blankLinesBetween(array $before, ?array $list, array $node, bool $nested, string $path): int
    {
        if (($node['type'] ?? '') === 'comment') {
            return $this->blankLinesBeforeComment($before, $node, $path);
        }
        if ($list !== null && Lists::sameKind($list, $node)) {
            return 3;
        }

        return $nested ? 0 : 1;
    }

    /**
     * C2-W-059: the blank lines before the comment `node`, which follows `before`.
     *
     * @param array<mixed> $before
     * @param array<mixed> $node
     * @param string $path
     *
     * @return int
     */
    private function blankLinesBeforeComment(array $before, array $node, string $path): int
    {
        if (($before['type'] ?? '') === 'code_block' && isset($before['caption']) && ($node['form'] ?? '') === 'line') {
            return 0;
        }
        if (self::isCommentLine($node) && $this->isOpenContainer($before)) {
            // C2-B-138: the line stays in the container, unless it ends a fence that the container ends with.
            if ($this->lastFence($before) !== null) {
                return 0;
            }
            $this->w->lose($path, 'comment', null);

            return 1;
        }

        return $this->takesLineBelow($before) || isset($this->w->openFences[$path]) ? 1 : 0;
    }

    /**
     * Whether a comment-only line directly below `node` would be read into it (C2-B-024, C2-A-086).
     *
     * @param array<mixed> $node
     *
     * @return bool
     */
    private function takesLineBelow(array $node): bool
    {
        $type = $node['type'] ?? '';
        if (in_array($type, ['paragraph', 'heading', 'definition_term', 'table'], true) || isset($node['caption'])) {
            return true;
        }
        if (!in_array($type, self::LAZY, true)) {
            return false;
        }
        if ($type === 'block_quote') {
            return true;
        }
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $last = end($children);

        return is_array($last) && $this->takesLineBelow($last);
    }

    /**
     * @param array<mixed> $node
     *
     * @return bool
     */
    private function isOpenContainer(array $node): bool
    {
        return in_array($node['type'] ?? '', self::OPEN, true);
    }

    /**
     * The fence that ends the open container `node`, or null.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>|null
     */
    private function lastFence(array $node): ?array
    {
        $at = $node;
        while ($this->isOpenContainer($at)) {
            $children = is_array($at['children'] ?? null) ? $at['children'] : [];
            $last = end($children);
            if (!is_array($last)) {
                return null;
            }
            $at = $last;
        }
        $type = $at['type'] ?? '';

        return $type === 'code_block' || $type === 'raw_block' || ($type === 'comment' && ($at['form'] ?? '') === 'block') ? $at : null;
    }

    /**
     * C2-W-123: the fence an open container ends with is written open where a comment line follows the container.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param array<mixed>|null $next
     *
     * @return void
     */
    private function markFenceBeforeComment(array $node, string $path, ?array $next): void
    {
        if (!self::isCommentLine($next) || !$this->isOpenContainer($node) || $this->lastFence($node) === null) {
            return;
        }
        $at = $node;
        while ($this->isOpenContainer($at)) {
            $children = is_array($at['children'] ?? null) ? $at['children'] : [];
            $path .= '/children/' . (count($children) - 1);
            $last = end($children);
            if (!is_array($last)) {
                return;
            }
            $at = $last;
        }
        $this->w->openFences[$path] = true;
    }

    /**
     * The attribute line of `node` written above a comment (C2-W-122, C2-W-129, C2-W-130).
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function followingAttributes(array $node, string $path): string
    {
        $type = $node['type'] ?? '';
        if ($type === 'list') {
            return $this->listAttributes($node, Lists::markers($node, $this->w, $path)[1]);
        }
        if ($type === 'div') {
            $classes = self::classes($node);
            if ($this->usesHardBreakOpener($node, $path)) {
                return BraceBlock::of($node, ['skipClasses' => [count($classes) - 1]]);
            }
            $name = self::divName($node);
            $block = BraceBlock::of($node, $name === null ? [] : ['skipClasses' => [0]]);

            return $block === '' && $name !== null ? BraceBlock::of($node) : $block;
        }
        if ($type === 'table') {
            $line = $this->tables->attributeLine($node, $path);

            return $line !== '' ? $line : '{footer-rows=0}';
        }

        return BraceBlock::of($node);
    }

    /**
     * C2-W-018: whether `node`, the second block of a tight item, is a nested list directly below the first paragraph.
     *
     * @param array<mixed> $before
     * @param array<mixed> $node
     * @param int $at
     * @param string $path
     *
     * @return bool
     */
    private function isNestedList(array $before, array $node, int $at, string $path): bool
    {
        if ($at !== 1 || ($node['type'] ?? '') !== 'list' || ($before['type'] ?? '') !== 'paragraph') {
            return false;
        }
        $marker = is_array($node['marker'] ?? null) ? $node['marker'] : [];
        // C2-W-126, C2-B-015: only a bullet or a first marker with the value 1 opens a list below a paragraph.
        if (($marker['kind'] ?? '') === 'ordered' && ($marker['start'] ?? 1) !== 1) {
            return false;
        }

        return $this->listAttributes($node, Lists::markers($node, $this->w, $path . '/' . $at)[1]) === '';
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<string>
     */
    private static function classes(array $node): array
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        return is_array($attrs['classes'] ?? null) ? array_values(array_filter($attrs['classes'], 'is_string')) : [];
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    public function block(array $node, string $path, string $prefix): void
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $this->w->starts[] = [count($this->w->lines), $path, $type];
        if (!$this->leafBlock($type, $node, $path, $prefix)) {
            $this->containerBlock($type, $node, $path, $prefix);
        }
    }

    /**
     * @param string $type
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return bool
     */
    private function leafBlock(string $type, array $node, string $path, string $prefix): bool
    {
        match ($type) {
            'paragraph' => $this->paragraph($node, $path, $prefix),
            'heading' => $this->heading($node, $path, $prefix),
            'thematic_break' => $this->leafLine($node, $prefix, '***'),
            'code_block', 'raw_block' => $this->codeBlock($node, $path, $prefix, true),
            'comment' => $this->comment($node, $path, $prefix),
            'frontmatter' => $this->frontmatter($node, $path, $prefix),
            'include' => $this->includeBlock($node, $path, $prefix),
            'link_reference_definition' => $this->linkDefinition($node, $path, $prefix),
            'abbreviation_def' => $this->abbreviationDefinition($node, $path, $prefix),
            'definition_term' => $this->textLeaf($node, $path, $prefix, ':: ', '   '),
            'table' => $this->table($node, $path, $prefix),
            default => null,
        };

        return in_array($type, [
            'paragraph', 'heading', 'thematic_break', 'code_block', 'raw_block', 'comment', 'frontmatter', 'include',
            'link_reference_definition', 'abbreviation_def', 'definition_term', 'table',
        ], true);
    }

    /**
     * @param string $type
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function containerBlock(string $type, array $node, string $path, string $prefix): void
    {
        match ($type) {
            'footnote' => $this->footnote($node, $path, $prefix),
            'list' => $this->listBlock($node, $path, $prefix),
            'definition_list' => $this->definitionList($node, $path, $prefix),
            'definition_description' => $this->body($node, $path, $prefix, ': ', 2, false),
            'block_quote' => $this->blockQuote($node, $path, $prefix),
            'div' => $this->div($node, $path, $prefix),
            'admonition', 'directive' => $this->namedDiv($node, $path, $prefix),
            'figure_group' => $this->figureGroup($node, $path, $prefix),
            'line_block' => $this->lineBlock($node, $path, $prefix),
            'block_extension' => $this->blockExtension($node, $path, $prefix),
            default => $this->childrenOnly($node, $path, $prefix),
        };
    }

    /**
     * C2-W-047: the attributes of a block, as one brace block on the line above it.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param array{keys?: array<array{0: string, 1: string}>, skipClasses?: array<int>, skipKeys?: array<string>} $extra
     *
     * @return void
     */
    private function attributeLine(array $node, string $path, string $prefix, array $extra = []): void
    {
        if ($this->w->prewritten === $path) {
            $this->w->prewritten = null;

            return;
        }
        $this->checkAttrs($node, $path);
        $block = BraceBlock::of($node, $extra);
        if ($block !== '') {
            $this->w->emit($prefix, $block);
        }
    }

    /**
     * C2-W-117: a class that holds a space, a tab or a line break has no spelling; neither has `dir`.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return void
     */
    public function checkAttrs(array $node, string $path): void
    {
        foreach (self::classes($node) as $class) {
            if (strpbrk($class, " \t\n") !== false || $class === '') {
                $this->w->lose($path, Inline::str($node, 'type'), 'attrs');
            }
        }
        if (isset($node['dir'])) {
            $this->w->lose($path, Inline::str($node, 'type'), 'dir');
        }
    }

    /**
     * @param array<mixed> $node
     * @param string $prefix
     * @param string $line
     *
     * @return void
     */
    private function leafLine(array $node, string $prefix, string $line): void
    {
        $block = BraceBlock::of($node);
        if ($block !== '') {
            $this->w->emit($prefix, $block);
        }
        $this->w->emit($prefix, $line);
    }

    /**
     * A context for the content of a text leaf.
     *
     * @param array<mixed> $nodes
     * @param bool $lineStart
     * @param string $prefix
     *
     * @return \MarkupCarve\Carve2\Writer\Context
     */
    private function context(array $nodes, bool $lineStart, string $prefix = ''): Context
    {
        $c = new Context();
        $c->bareBreaks = $this->bareBreaks;
        $c->counts = Inline::counts($nodes);
        $c->lineStart = $lineStart;
        $c->afterDescriptions = $this->w->afterDescriptions;
        $c->owner = $prefix === '' ? 'top' : (str_ends_with($prefix, '> ') || str_ends_with($prefix, '>') ? 'quote' : 'body');

        return $c;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function paragraph(array $node, string $path, string $prefix): void
    {
        $this->textLeaf($node, $path, $prefix, '', '');
        $this->caption($node['caption'] ?? null, $path . '/caption', $prefix);
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param string $mark
     * @param string $indent
     *
     * @return void
     */
    private function textLeaf(array $node, string $path, string $prefix, string $mark, string $indent): void
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children === []) {
            $this->w->lose($path, Inline::str($node, 'type'), null);

            return;
        }
        $this->attributeLine($node, $path, $prefix);
        $this->w->lead = ($this->w->lead ?? $prefix) . $mark;
        $c = $this->context($children, $mark === '', $prefix);
        $text = $this->inline->content($children, $path . '/children', $c, -1);
        $this->w->emitLines($prefix, $indent, self::endContent($text, $children, $c));
    }

    /**
     * C2-W-022: the line ending of a hard break at the end of the content is the line ending of the leaf.
     *
     * @param string $text
     * @param array<mixed> $children
     * @param \MarkupCarve\Carve2\Writer\Context $c
     *
     * @return string
     */
    private static function endContent(string $text, array $children, Context $c): string
    {
        $last = end($children);
        if (!is_array($last) || ($last['type'] ?? '') !== 'hard_break' || !str_ends_with($text, "\n")) {
            return $text;
        }
        // C2-I-102: in a hard-break block a line ending alone is no break at the end of the content.
        $spelled = str_ends_with($text, "\\\n") && (strlen($text) === 2 || $text[strlen($text) - 3] === "\n");

        return $c->bareBreaks && !$spelled ? substr($text, 0, -1) . '\\' : substr($text, 0, -1);
    }

    /**
     * C2-W-035: `=`, one per level, and one space; a continuation line stands at the content column.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function heading(array $node, string $path, string $prefix): void
    {
        $level = is_int($node['level'] ?? null) ? $node['level'] : 1;
        if ($level > 6) {
            $this->w->lose($path, 'heading', 'level');
            $level = 6;
        }
        $this->textLeaf($node, $path, $prefix, str_repeat('=', max(1, $level)) . ' ', str_repeat(' ', max(1, $level) + 1));
    }

    /**
     * C2-W-053: `^`, one space and the content, directly below the block.
     *
     * @param mixed $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function caption(mixed $node, string $path, string $prefix): void
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['short'])) {
            $this->w->lose($path, 'caption', 'short');
        }
        $blocks = is_array($node['children'] ?? null) ? $node['children'] : [];
        // C2-W-012: the content of two former blocks is kept apart by a space.
        if (count($blocks) !== 1) {
            $this->w->lose($path, 'caption', null);
        }
        $parts = [];
        foreach ($blocks as $at => $block) {
            $inlines = is_array($block) && is_array($block['children'] ?? null) ? $block['children'] : [];
            $c = $this->context($inlines, false);
            $parts[] = $this->inline->content($inlines, $path . '/children/' . $at . '/children', $c, -1);
        }
        $this->w->lead = ($this->w->lead ?? $prefix) . '^ ';
        $this->w->emitLines($prefix, '  ', implode(' ', $parts));
    }

    /**
     * The lines of a verbatim value. C2-W-033: a value with no final line break is written with one.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return array<string>
     */
    private function valueLines(array $node, string $path): array
    {
        $value = Inline::str($node, 'value');
        if ($value === '') {
            return [];
        }
        $lines = explode("\n", $value);
        if (end($lines) === '') {
            array_pop($lines);
        } else {
            $this->w->lose($path, Inline::str($node, 'type'), 'value');
        }

        return $lines;
    }

    /**
     * The longest run of `$char` that stands alone on a line, at any indent (C2-W-038, C2-B-110).
     *
     * @param array<string> $lines
     * @param string $char
     *
     * @return int
     */
    private static function longestBareRun(array $lines, string $char): int
    {
        $longest = 0;
        foreach ($lines as $line) {
            $body = ltrim($line, " \t");
            $run = strspn($body, $char);
            if ($run > 0 && trim(substr($body, $run), " \t") === '') {
                $longest = max($longest, $run);
            }
        }

        return $longest;
    }

    /**
     * C2-W-037, C2-W-038, C2-W-040: a code block, and a raw block as the fence with `=` and its format.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param bool $withCaption
     *
     * @return void
     */
    private function codeBlock(array $node, string $path, string $prefix, bool $withCaption): void
    {
        $this->attributeLine($node, $path, $prefix);
        $lines = $this->valueLines($node, $path);
        $run = str_repeat('`', max(3, self::longestBareRun($lines, '`') + 1));
        $info = $this->fenceInfo($node, $path);
        $this->w->emit($prefix, $run . $info);
        $this->verbatim($lines, $prefix);
        if (!isset($this->w->openFences[$path])) {
            $this->w->emit($prefix, $run);
        }
        if ($withCaption && ($node['type'] ?? '') === 'code_block') {
            $this->caption($node['caption'] ?? null, $path . '/caption', $prefix);
        }
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function fenceInfo(array $node, string $path): string
    {
        if (($node['type'] ?? '') === 'raw_block') {
            return '=' . Inline::str($node, 'format');
        }
        $syntax = Inline::str($node, 'syntax');
        if (str_contains($syntax, '`') || strpbrk($syntax, " \t\"[") !== false) {
            $this->w->lose($path, 'code_block', 'syntax');
            $syntax = '';
        }
        $title = null;
        if (is_array($node['title'] ?? null)) {
            $title = '';
            foreach ($node['title'] as $part) {
                if (!is_array($part) || ($part['type'] ?? '') !== 'text') {
                    $this->w->lose($path, 'code_block', 'title');
                }
                $title .= is_array($part) ? Inline::str($part, 'value') : '';
            }
            if (str_contains($title, '`')) {
                $this->w->lose($path, 'code_block', 'title');
                $title = null;
            }
        }

        return $syntax . $this->openerTail($node, $path, $title, $title === null ? '' : substr(BraceBlock::quoted($title), 1, -1));
    }

    /**
     * C2-W-040: the quoted title and the label of an opener; a field the opener cannot hold is left out.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string|null $title
     * @param string $spelled
     *
     * @return string
     */
    private function openerTail(array $node, string $path, ?string $title, string $spelled): string
    {
        $out = '';
        $type = Inline::str($node, 'type');
        if ($title !== null && (str_contains($title, '"') || str_contains($title, "\n"))) {
            $this->w->lose($path, $type, 'title');
        } elseif ($title !== null) {
            $out .= ' "' . $spelled . '"';
        }
        if (!is_string($node['groupLabel'] ?? null)) {
            return $out;
        }
        $label = $node['groupLabel'];
        if (str_contains($label, ']') || str_contains($label, "\n")) {
            $this->w->lose($path, $type, 'groupLabel');

            return $out;
        }

        return $out . ' [' . $label . ']';
    }

    /**
     * C2-W-059: a line comment, and a comment block with the shortest percent run its body allows.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function comment(array $node, string $path, string $prefix): void
    {
        $value = Inline::str($node, 'value');
        $this->attributeLine($node, $path, $prefix);
        $form = $node['form'] ?? 'line';
        if ($form !== 'block') {
            if (str_contains($value, "\n")) {
                $this->w->lose($path, 'comment', 'value');
                $value = str_replace("\n", ' ', $value);
            }
            if ($form === 'braced') {
                $this->w->emit($prefix, $value === '' ? '{%%}' : '{% ' . $value . ' %}');

                return;
            }
            $kept = rtrim($value, " \t");
            if ($kept !== $value) {
                $this->w->lose($path, 'comment', 'value');
            }
            $this->w->emit($prefix, $kept === '' ? '%%' : '%% ' . $kept);

            return;
        }
        $lines = $value === '' ? [] : explode("\n", $value);
        $run = str_repeat('%', max(3, self::longestBareRun($lines, '%') + 1));
        $this->w->emit($prefix, $run);
        $this->verbatim($lines, $prefix);
        if (!isset($this->w->openFences[$path])) {
            $this->w->emit($prefix, $run);
        }
    }

    /**
     * C2-W-052: the bare opener for YAML, and the format name glued to it for every other format.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function frontmatter(array $node, string $path, string $prefix): void
    {
        $format = Inline::str($node, 'format');
        if ($format !== 'yaml' && preg_match('/^[A-Za-z][A-Za-z0-9_+-]*$/', $format) !== 1) {
            // C2-W-124: the node would read back as another type.
            $this->w->lose($path, 'frontmatter', null);
        }
        $lines = $this->valueLines($node, $path);
        if (in_array('---', $lines, true)) {
            $this->w->lose($path, 'frontmatter', 'value');
        }
        $this->w->emit($prefix, $format === 'yaml' ? '---' : '---' . $format);
        foreach ($lines as $line) {
            $this->w->lines[] = $prefix . $line;
        }
        $this->w->emit($prefix, '---');
    }

    /**
     * C2-W-058: an include directive in the spelling the renderer shows too (C2-H-171).
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function includeBlock(array $node, string $path, string $prefix): void
    {
        $this->attributeLine($node, $path, $prefix);
        $include = new Node('include');
        $include->f = $node;
        if (Inline::str($node, 'path') === '' || str_contains(Inline::str($node, 'path'), "\n")) {
            $this->w->lose($path, 'include', 'path');
        }
        $this->w->emit($prefix, HtmlRenderer::includeText($include));
        $this->caption($node['caption'] ?? null, $path . '/caption', $prefix);
    }

    /**
     * C2-W-056: a link definition.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function linkDefinition(array $node, string $path, string $prefix): void
    {
        $this->attributeLine($node, $path, $prefix);
        $this->blankKey($node, $path);
        $href = Inline::str($node, 'href');
        $written = '';
        $n = strlen($href);
        for ($i = 0; $i < $n; $i++) {
            if ($href[$i] === '\\' && $i + 1 < $n && str_contains('()\\', $href[$i + 1])) {
                $written .= '\\';
            }
            $written .= $href[$i];
        }
        if ($href === '') {
            $this->w->lose($path, 'link_reference_definition', 'href');
        }
        $title = is_string($node['tooltip'] ?? null) ? ' ' . BraceBlock::quoted($node['tooltip']) : '';
        $sep = $written === '' && $title === '' ? '' : ' ';
        $this->w->emit($prefix, '[' . Inline::str($node, 'key') . ']:' . $sep . $written . $title);
    }

    /**
     * C2-I-079: a key of only spaces, tabs and line feeds folds to nothing.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return void
     */
    private function blankKey(array $node, string $path): void
    {
        $key = Inline::str($node, 'key');
        if (trim($key, " \t\n") === '' || preg_match('/(?<!\\\\)\]/', $key) === 1) {
            $this->w->lose($path, Inline::str($node, 'type'), 'key');
        }
    }

    /**
     * C2-W-055: `*[key]:`, one space and the expansion.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function abbreviationDefinition(array $node, string $path, string $prefix): void
    {
        $this->attributeLine($node, $path, $prefix);
        $raw = Inline::str($node, 'expansion');
        $expansion = rtrim(str_replace("\n", ' ', $raw), " \t");
        $key = Inline::str($node, 'key');
        if ($expansion !== $raw || $expansion === '' || preg_match('/^[A-Za-z0-9]+$/', $key) !== 1) {
            $this->w->lose($path, 'abbreviation_def', $expansion !== $raw || $expansion === '' ? 'expansion' : 'key');
        }
        $this->w->emit($prefix, '*[' . $key . ']: ' . $expansion);
    }

    /**
     * C2-W-024, C2-W-027: the blocks of a body behind its marker, the first on the marker line.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param string $mark
     * @param int $width
     * @param bool $tight
     *
     * @return void
     */
    private function body(array $node, string $path, string $prefix, string $mark, int $width, bool $tight): void
    {
        // C2-B-229: a body opened on the marker line of another body has its content column behind its own marker.
        $behind = $this->w->lead === null ? 0 : max(0, strlen($this->w->lead) - strlen($prefix));
        $this->w->lead = ($this->w->lead ?? $prefix) . $mark;
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $this->blocks($children, $path . '/children', $prefix . str_repeat(' ', $behind + $width), $tight);
        if ($this->w->lead === null) {
            return;
        }
        // C2-W-057: an empty body has no spelling.
        $this->w->lose($path, Inline::str($node, 'type'), null);
        $this->w->emit($prefix, '');
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function footnote(array $node, string $path, string $prefix): void
    {
        $this->attributeLine($node, $path, $prefix);
        $this->blankKey($node, $path);
        $this->body($node, $path, $prefix, '[^' . Inline::str($node, 'key') . ']: ', 2, false);
    }

    /**
     * The brace block above a list: its attributes, and the start value no marker carries (C2-W-044).
     *
     * @param array<mixed> $node
     * @param string|null $start
     *
     * @return string
     */
    private function listAttributes(array $node, ?string $start): string
    {
        return BraceBlock::of($node, $start === null ? [] : ['keys' => [['start', $start]]]);
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function listBlock(array $node, string $path, string $prefix): void
    {
        $items = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($items === []) {
            $this->w->lose($path, 'list', null);

            return;
        }
        [$markers, $start] = Lists::markers($node, $this->w, $path);
        if ($this->w->prewritten === $path) {
            $this->w->prewritten = null;
        } else {
            $this->checkAttrs($node, $path);
            $block = $this->listAttributes($node, $start);
            if ($block !== '') {
                $this->w->emit($prefix, $block);
            }
        }
        $tight = ($node['spacing'] ?? 'tight') !== 'loose';
        if (!$tight && count($items) === 1) {
            $this->w->lose($path, 'list', 'spacing');
        }
        foreach ($items as $at => $item) {
            if ($at > 0 && !$tight) {
                $this->w->emit($prefix, '');
            }
            if (is_array($item)) {
                $this->listItem($item, $path . '/children/' . $at, $prefix, $markers[$at] ?? '-', $tight);
            }
        }
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param string $marker
     * @param bool $tight
     *
     * @return void
     */
    private function listItem(array $node, string $path, string $prefix, string $marker, bool $tight): void
    {
        $this->checkAttrs($node, $path);
        // C2-W-051: the attributes of an item are glued to its marker.
        $head = $marker . BraceBlock::of($node);
        // C2-W-025, C2-B-040: an uppercase marker with a dot takes two spaces.
        $gap = strlen($marker) >= 2 && str_ends_with($marker, '.') && ctype_upper($marker[0]) ? '  ' : ' ';
        $task = is_array($node['task'] ?? null) ? $node['task'] : null;
        $box = $task === null ? '' : '[' . (is_string($task['mark'] ?? null) ? $task['mark'] : ' ') . '] ';
        $this->body($node, $path, $prefix, $head . $gap . $box, strlen($marker) + strlen($gap), $tight);
        $this->w->bareDotLead = $marker === '.' && $this->w->lead !== null;
    }

    /**
     * C2-W-060, C2-W-114: terms and descriptions with no blank line between them.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function definitionList(array $node, string $path, string $prefix): void
    {
        $entries = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($entries === []) {
            $this->w->lose($path, 'definition_list', null);

            return;
        }
        $first = array_values(array_filter($entries, static fn ($e): bool => is_array($e) && ($e['type'] ?? '') !== 'comment'))[0] ?? null;
        if (($first['type'] ?? '') !== 'definition_term') {
            // C2-W-117: a list that opens with a description reads back as a paragraph.
            $this->w->lose($path, 'definition_list', null);
        }
        $this->attributeLine($node, $path, $prefix);
        $this->siblings($entries, $path . '/children', $prefix, self::ENTRIES);
    }

    /**
     * The longest colon run among the fenced containers below `node`, or 0 (C2-W-039).
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return int
     */
    private function runsBelow(array $node, string $path): int
    {
        $longest = 0;
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $at => $child) {
            if (!is_array($child)) {
                continue;
            }
            $below = $this->runsBelow($child, $path . '/children/' . $at);
            $longest = max($longest, $this->hasColonRun($child, $path . '/children/' . $at) ? max(3, $below + 1) : $below);
        }

        return $longest;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return bool
     */
    private function hasColonRun(array $node, string $path): bool
    {
        $type = $node['type'] ?? '';
        if ($type === 'block_quote') {
            return $this->w->fact($path, 'fenced') === true;
        }

        return in_array($type, ['div', 'admonition', 'directive', 'figure_group', 'line_block'], true);
    }

    /**
     * A block between an opener and a closer of one colon run. C2-W-032: an empty body is one blank line.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param string $opener
     * @param bool $blankWhenEmpty
     * @param \Closure(): void $write
     *
     * @return void
     */
    private function fenced(array $node, string $path, string $prefix, string $opener, bool $blankWhenEmpty, Closure $write): void
    {
        $run = str_repeat(':', max(3, $this->runsBelow($node, $path) + 1));
        $this->w->emit($prefix, $run . $opener);
        $mark = count($this->w->lines);
        $write();
        if (count($this->w->lines) === $mark && $blankWhenEmpty) {
            $this->w->emit($prefix, '');
        }
        $this->w->emit($prefix, $run);
    }

    /**
     * C2-W-031, C2-W-082: a quote with `>` lines, or as a quote fence where the sidecar records that form.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function blockQuote(array $node, string $path, string $prefix): void
    {
        $this->attributeLine($node, $path, $prefix);
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($this->w->fact($path, 'fenced') === true) {
            $this->fenced($node, $path, $prefix, ' >', false, fn () => $this->blocks($children, $path . '/children', $prefix));
        } elseif ($children === []) {
            $this->w->emit($prefix, '>');
        } else {
            $this->w->lead = ($this->w->lead ?? $prefix) . '> ';
            $this->blocks($children, $path . '/children', $prefix . '> ');
            if ($this->w->lead !== null) {
                $this->w->emit($prefix, '');
            }
        }
        $this->caption($node['caption'] ?? null, $path . '/caption', $prefix);
    }

    /**
     * C2-B-119: the quoted title of an opener is one line of inline content.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function titleTail(array $node, string $path): string
    {
        $title = null;
        if (is_array($node['title'] ?? null)) {
            $c = $this->context($node['title'], false);
            $c->blockLines = false;
            $title = $this->inline->content($node['title'], $path . '/title', $c, 0x22);
        }

        return $this->openerTail($node, $path, $title, $title ?? '');
    }

    /**
     * C2-W-039, C2-W-120: the name of a plain div is its first class, where that class can be a name.
     *
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function divName(array $node): ?string
    {
        $first = self::classes($node)[0] ?? null;
        if ($first === null || !BraceBlock::isName($first)) {
            return null;
        }

        return isset(Tables::DIV_NAME_KINDS[$first]) ? null : $first;
    }

    /**
     * C2-W-081: the opener of a hard-break block stands for the class, which it adds last (C2-A-068).
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return bool
     */
    private function usesHardBreakOpener(array $node, string $path): bool
    {
        $classes = self::classes($node);
        if (end($classes) !== 'hardbreaks' || isset($node['title']) || isset($node['groupLabel'])) {
            return false;
        }

        return $this->w->fact($path, 'hardBreakOpener') !== false && !self::softBreakBelow($node);
    }

    /**
     * C2-L-034: with no fact the opener is used only when every line break in the div is a hard break.
     *
     * @param array<mixed> $node
     *
     * @return bool
     */
    private static function softBreakBelow(array $node): bool
    {
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child) && (($child['type'] ?? '') === 'soft_break' || self::softBreakBelow($child))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function div(array $node, string $path, string $prefix): void
    {
        $classes = self::classes($node);
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($this->usesHardBreakOpener($node, $path)) {
            $this->attributeLine($node, $path, $prefix, ['skipClasses' => [count($classes) - 1]]);
            $this->fenced($node, $path, $prefix, ' \\', true, function () use ($children, $path, $prefix): void {
                $this->bareBreaks = true;
                $this->blocks($children, $path . '/children', $prefix);
                $this->bareBreaks = false;
            });

            return;
        }
        $named = self::divName($node);
        $name = $this->w->prewritten === $path && $named !== null && BraceBlock::of($node, ['skipClasses' => [0]]) === ''
            ? null : $named;
        $this->attributeLine($node, $path, $prefix, $name === null ? [] : ['skipClasses' => [0]]);
        $opener = ($name === null ? '' : ' ' . $name) . $this->titleTail($node, $path);
        $this->fenced($node, $path, $prefix, $opener, true, fn () => $this->blocks($children, $path . '/children', $prefix));
    }

    /**
     * C2-W-039: an admonition and a directive are named by their kind.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function namedDiv(array $node, string $path, string $prefix): void
    {
        $this->attributeLine($node, $path, $prefix);
        if (isset($node['tocTarget'])) {
            $this->w->lose($path, 'directive', 'tocTarget');
        }
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $opener = ' ' . Inline::str($node, 'kind') . $this->titleTail($node, $path);
        $this->fenced($node, $path, $prefix, $opener, true, fn () => $this->blocks($children, $path . '/children', $prefix));
    }

    /**
     * C2-W-059: the comments between a figure group and its caption stand between the closer and the caption line.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function figureGroup(array $node, string $path, string $prefix): void
    {
        $this->attributeLine($node, $path, $prefix);
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $count = count($children);
        $after = 0;
        while (
            isset($node['caption']) && $after < $count && is_array($children[$count - 1 - $after])
            && ($children[$count - 1 - $after]['type'] ?? '') === 'comment'
        ) {
            $after++;
        }
        $inside = array_slice($children, 0, $count - $after);
        $this->fenced($node, $path, $prefix, ' figure', true, fn () => $this->blocks($inside, $path . '/children', $prefix));
        for ($at = $count - $after; $at < $count; $at++) {
            if (is_array($children[$at])) {
                $this->comment($children[$at], $path . '/children/' . $at, $prefix);
            }
        }
        $this->caption($node['caption'] ?? null, $path . '/caption', $prefix);
    }

    /**
     * C2-W-059, C2-A-082: a code block with a caption, the comments that follow it, then the caption.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $path
     * @param string $prefix
     *
     * @return int
     */
    private function captionedCode(array $nodes, int $at, string $path, string $prefix): int
    {
        $node = is_array($nodes[$at]) ? $nodes[$at] : [];
        $this->codeBlock($node, $path . '/' . $at, $prefix, false);
        $last = $at;
        for (
            $next = $nodes[$last + 1] ?? null; is_array($next) && ($next['type'] ?? '') === 'comment'
            && ($next['form'] ?? '') === 'line'; $next = $nodes[$last + 1] ?? null
        ) {
            $last++;
            $this->comment($next, $path . '/' . $last, $prefix);
        }
        $this->caption($node['caption'] ?? null, $path . '/' . $at . '/caption', $prefix);

        return $last;
    }

    /**
     * C2-W-032, C2-W-080: one line of source per `line`; an empty line block has its closer below its opener.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function lineBlock(array $node, string $path, string $prefix): void
    {
        $this->attributeLine($node, $path, $prefix);
        $lines = is_array($node['children'] ?? null) ? $node['children'] : [];
        $this->fenced($node, $path, $prefix, ' |', false, function () use ($lines, $path, $prefix): void {
            $this->bareBreaks = true;
            foreach ($lines as $at => $line) {
                $inlines = is_array($line) && is_array($line['children'] ?? null) ? $line['children'] : [];
                $c = $this->context($inlines, true);
                $text = $this->inline->content($inlines, $path . '/children/' . $at . '/children', $c, -1);
                $this->w->emit($prefix, self::endContent($text, $inlines, $c));
            }
            $this->bareBreaks = false;
        });
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function table(array $node, string $path, string $prefix): void
    {
        $this->checkAttrs($node, $path);
        if ($this->w->prewritten === $path) {
            $this->w->prewritten = null;
            $this->tables->write($node, $path, $prefix, true);
        } else {
            $this->tables->write($node, $path, $prefix, false);
        }
        $this->caption($node['caption'] ?? null, $path . '/caption', $prefix);
    }

    /**
     * C2-X-023: an extension node is written in its source form; without it, its fallback is written and reported.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function blockExtension(array $node, string $path, string $prefix): void
    {
        $source = is_array($node['source'] ?? null) ? $node['source'] : [];
        $name = Inline::str($source, 'name');
        $form = Inline::str($source, 'form');
        // C2-X-023, C2-X-025: a source record named like a raw format is no spelling of the extension.
        if ($form === '' || $form === 'inline' || str_starts_with($name, '=') || !is_string($source['body'] ?? null)) {
            $this->childrenOnly($node, $path, $prefix);

            return;
        }
        $lines = explode("\n", $source['body']);
        if (end($lines) === '') {
            array_pop($lines);
        }
        $mark = $form === 'div' ? ':' : '`';
        $run = str_repeat($mark, max(3, self::longestBareRun($lines, $mark) + 1));
        $title = is_string($source['title'] ?? null) ? ' ' . BraceBlock::quoted($source['title']) : '';
        $label = is_string($source['groupLabel'] ?? null) ? ' [' . $source['groupLabel'] . ']' : '';
        $opener = $run . ($mark === ':' ? ' ' : '') . $name . $title . $label;
        $text = implode("\n", array_merge([$opener], $lines, [$run])) . "\n";
        // C2-X-023, C2-X-049
        if (!ExtensionSource::readsBack($node, $text, $this->w->extensions)) {
            $this->childrenOnly($node, $path, $prefix);

            return;
        }
        $this->attributeLine($node, $path, $prefix);
        $this->w->emit($prefix, $opener);
        $this->verbatim($lines, $prefix);
        $this->w->emit($prefix, $run);
    }

    /**
     * Lines of a verbatim value behind the prefix; an empty line keeps no trailing space of it (C2-W-021).
     *
     * @param array<string> $lines
     * @param string $prefix
     *
     * @return void
     */
    private function verbatim(array $lines, string $prefix): void
    {
        foreach ($lines as $line) {
            $this->w->lines[] = $line === '' ? rtrim($prefix, ' ') : $prefix . $line;
        }
    }

    /**
     * C2-A-175: a node with no spelling of its own is its children, or its fallback.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function childrenOnly(array $node, string $path, string $prefix): void
    {
        $this->w->lose($path, Inline::str($node, 'type'), null);
        if (is_array($node['fallback'] ?? null)) {
            $this->blocks($node['fallback'], $path . '/fallback', $prefix);

            return;
        }
        $this->blocks(is_array($node['children'] ?? null) ? $node['children'] : [], $path . '/children', $prefix);
    }
}
