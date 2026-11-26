<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Closure;
use MarkupCarve\Carve\Block\BlockParser;
use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Block\Markers;
use MarkupCarve\Carve\Block\TreeSink;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\Standard;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Snippet\Syntax;

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
     * A definition key or text of these characters reads back as written, so its line needs no read-back.
     *
     * @var string
     */
    private const PLAIN_KEY = '~^[A-Za-z0-9_-]+(?: [A-Za-z0-9_-]+)*$~';

    /**
     * @var string
     */
    private const PLAIN_TEXT = '~^[A-Za-z0-9 ._,:;!?-]*$~';

    /**
     * @var array<string>
     */
    private const LAZY = ['list', 'list_item', 'block_quote', 'definition_list', 'definition_description', 'footnote'];

    /**
     * @var array<string>
     */
    private const OPEN = ['list', 'list_item', 'definition_list', 'definition_description', 'footnote'];

    /**
     * C2-W-154: the containers whose last block decides how a column-0 line directly below them reads.
     *
     * @var array<string>
     */
    private const LIST_LIKE = ['list', 'list_item', 'block_quote', 'footnote', 'definition_list', 'definition_description'];

    /**
     * @var array<string, string>|null The lead decisions of one write call, which writes its tree many times.
     */
    private static ?array $leadMemo = null;

    private Inline $inline;

    /**
     * @var array<mixed>|null The block written above the current top-level block.
     */
    private ?array $above = null;

    /**
     * @var array<mixed>|null The block above the current sibling that its lines would still belong to, past comment-only
     *   lines (C2-B-137).
     */
    private ?array $reaching = null;

    /**
     * @var array<mixed>|null The sibling written directly above the current top-level block.
     */
    private ?array $belowSibling = null;

    /**
     * C2-W-155: the top-level blocks written one space in, by path; true where the attribute line stays at column 0.
     *
     * @var array<string, bool>
     */
    private array $indentPlan = [];

    /**
     * C2-W-155: the paragraphs that keep their indented first line below a list written one space in, by path.
     *
     * @var array<string, true>
     */
    private array $spelledLeads = [];

    /**
     * C2-W-080: inside a hard-break block or a line block a hard break is the bare line ending.
     */
    public bool $bareBreaks = false;

    // Made at the first table: a document without one does not load the table writer.
    private ?TableWriter $tables = null;

    private string $bodyOwner = 'body';

    private int $blockDepth = 0;

    private int $fencedAncestors = 0;

    private int $fenceRun = 0;

    /**
     * @var array<string, int>
     */
    private array $longestRunByPath = [];

    public function __construct(private State $w)
    {
        $this->inline = new Inline($w);
    }

    private function tables(): TableWriter
    {
        return $this->tables ??= new TableWriter($this->w, $this->inline);
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
        $this->blockDepth++;
        if ($path === '/children') {
            $this->planIndents($nodes, $path);
        }
        try {
            $this->siblings($nodes, $path, $prefix, $tight ? self::TIGHT_ITEM : self::BLOCKS);
        } finally {
            $this->blockDepth--;
        }
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
        $followingAt = 0;
        $reach = [null, 0];
        for ($at = 0; $at < $count; $at++) {
            $node = $nodes[$at];
            if (!is_array($node)) {
                continue;
            }
            $rootGroup = $path === '/children';
            $first = $at;
            $undecided = false;
            if ($rootGroup) {
                $this->w->beginRepairGroup($node);
                $undecided = Text::enterGroup();
            }
            $start = count($this->w->lines);
            $lead = $this->w->lead;
            $leadSkip = $this->w->leadSkip;
            $bareDotLead = $this->w->bareDotLead;
            $afterDescriptions = $this->w->afterDescriptions;
            $prewritten = $this->w->prewritten;
            $openFences = $this->w->openFences;
            $starts = count($this->w->starts);
            $losses = count($this->w->losses);
            try {
                $followingAt = self::followingAfter($nodes, $at, $followingAt);
                $reach = $this->reachTo($nodes, $at, $reach);
                $this->reaching = self::nodeAt($nodes, $reach[0]);
                $this->beforeSibling($nodes, $at, $followingAt, $before, $list, $path, $prefix, $layout);
                $next = $nodes[$at + 1] ?? null;
                $this->markFenceBeforeComment($node, $path . '/' . $at, is_array($next) ? $next : null);
                $written = count($this->w->lines);
                $this->w->afterDescriptions = is_array($before) && ($before['type'] ?? '') === 'definition_list';
                if (($node['type'] ?? '') === 'code_block' && isset($node['caption'])) {
                    $at = $this->captionedCode($nodes, $at, $path, $prefix);
                } else {
                    $this->sibling($node, $path, $at, $prefix);
                }
            } catch (RepairRefused $e) {
                if (!$rootGroup) {
                    throw $e;
                }
                array_splice($this->w->lines, $start);
                array_splice($this->w->starts, $starts);
                array_splice($this->w->losses, $losses);
                $this->w->lead = $lead;
                $this->w->leadSkip = $leadSkip;
                $this->w->bareDotLead = $bareDotLead;
                $this->w->afterDescriptions = $afterDescriptions;
                $this->w->prewritten = $prewritten;
                $this->w->openFences = $openFences;
                $this->w->refuseRepair($path . '/' . $at);
                $this->w->endRepairGroup();
                Text::leaveGroup($undecided);

                continue;
            }
            if ($rootGroup) {
                $this->endRootGroup($first, $at, $written, $undecided);
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
     * Records a top-level block group that wrote lines, for the group check of `Writer`.
     *
     * @param int $first
     * @param int $last
     * @param int $written The first line of the group.
     * @param bool $outer The undecided record of the groups before it.
     *
     * @return void
     */
    private function endRootGroup(int $first, int $last, int $written, bool $outer): void
    {
        $this->w->endRepairGroup();
        $undecided = Text::leaveGroup($outer);
        if ($this->w->recordGroups && count($this->w->lines) !== $written) {
            $this->w->rootGroups[] = [$first, $last, $written, count($this->w->lines), $undecided];
        }
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param int $at
     * @param string $prefix
     *
     * @return void
     */
    private function sibling(array $node, string $path, int $at, string $prefix): void
    {
        $this->block($node, $path . '/' . $at, isset($this->indentPlan[$path . '/' . $at]) ? $prefix . ' ' : $prefix);
    }

    /**
     * C2-W-155: plans which top-level blocks are written one space in. Below a list, description list or footnote
     * the indented block would belong to it after the blank line (C2-B-025, C2-B-026), so the writer takes, in this
     * order: the block's attribute line at column 0 (C2-B-097, C2-B-091), else the list or description list above
     * indented too with the same test repeated upward, else no spelling (C2-W-117, reported at the paragraph).
     *
     * @param array<mixed> $nodes
     * @param string $path
     *
     * @return void
     */
    private function planIndents(array $nodes, string $path): void
    {
        $this->indentPlan = [];
        $this->spelledLeads = [];
        $count = count($nodes);
        $above = $this->blocksAbove($nodes);
        for ($next = 1; $next < $count; $next++) {
            $at = $above[$next];
            $node = $at === null ? null : $nodes[$at];
            $paragraph = $nodes[$next];
            if (
                $at === null || !is_array($node) || !is_array($paragraph)
                || !self::indentedAbove($node, $paragraph, $path . '/' . $next)
            ) {
                continue;
            }
            $marks = $this->indentChain($nodes, $above, $at, $path);
            if ($marks === []) {
                continue;
            }
            foreach ($marks as $index => $outdented) {
                $this->indentPlan[$path . '/' . $index] = $outdented;
            }
            $this->spelledLeads[$path . '/' . $next] = true;
        }
    }

    /**
     * C2-W-155: walks upward from the list or description list at `at` and returns the blocks to write one space in,
     * true where the attribute line stays at column 0, or none where no spelling keeps the tree. The order of the
     * repairs lives here (carve#6418).
     *
     * @param array<mixed> $nodes
     * @param array<int, int|null> $above
     * @param int $at
     * @param string $path
     *
     * @return array<int, bool>
     */
    private function indentChain(array $nodes, array $above, int $at, string $path): array
    {
        $marks = [];
        $current = $at;
        while (true) {
            $aboveAt = $above[$current];
            $container = $aboveAt === null ? null : $nodes[$aboveAt];
            $node = $nodes[$current];
            if (!is_array($container) || !$this->isOpenContainer($container)) {
                return $marks + [$current => false];
            }
            if (is_array($node) && $this->ownAttributes($node, $path . '/' . $current) !== '') {
                return $marks + [$current => true];
            }
            if (!in_array($container['type'] ?? '', ['list', 'definition_list'], true)) {
                return [];
            }
            $marks[$current] = false;
            $current = $aboveAt;
        }
    }

    /**
     * For each block, the index of the last block above it that is no comment-only line, or null where no container
     * reaches it. Comment-only lines are not seen by block structure (C2-B-137); a comment block, a region marker line
     * and the comment line of C2-W-123 close the containers above (C2-B-053, C2-W-155).
     *
     * @param array<mixed> $nodes
     *
     * @return array<int, int|null>
     */
    private function blocksAbove(array $nodes): array
    {
        $above = [];
        $reach = [null, 0];
        $count = count($nodes);
        for ($index = 0; $index < $count; $index++) {
            $reach = $this->reachTo($nodes, $index, $reach);
            $above[$index] = $reach[0];
        }

        return $above;
    }

    /**
     * Moves the walk of `blocksAbove` on to the block at `at`: the block reaching it and the next index to read.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param array{0: int|null, 1: int} $reach
     *
     * @return array{0: int|null, 1: int}
     */
    private function reachTo(array $nodes, int $at, array $reach): array
    {
        [$last, $index] = $reach;
        for (; $index < $at; $index++) {
            $node = $nodes[$index] ?? null;
            if (!is_array($node)) {
                continue;
            }
            if (!self::isCommentLine($node)) {
                $last = $index;

                continue;
            }
            $previous = $nodes[$index - 1] ?? null;
            $fenceEnds = $last === $index - 1 && is_array($previous) && $this->isOpenContainer($previous)
                && $this->lastFence($previous) !== null;
            if ($fenceEnds || self::isRegionMarker($node)) {
                $last = null;
            }
        }

        return [$last, $index];
    }

    /**
     * @param array<mixed> $nodes
     * @param int|null $at
     *
     * @return array<mixed>|null
     */
    private static function nodeAt(array $nodes, ?int $at): ?array
    {
        return $at !== null && is_array($nodes[$at] ?? null) ? $nodes[$at] : null;
    }

    /**
     * @param array<mixed> $node
     *
     * @return bool
     */
    private static function isRegionMarker(array $node): bool
    {
        $marker = '/^#(?:region[ \t]+[A-Za-z_][A-Za-z0-9_-]*|endregion(?:[ \t]+[A-Za-z_][A-Za-z0-9_-]*)?)$/';

        return preg_match($marker, Inline::str($node, 'value')) === 1;
    }

    /**
     * The attribute line the writer puts above the list or description list `node`, or ''.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function ownAttributes(array $node, string $path): string
    {
        return ($node['type'] ?? '') === 'list'
            ? $this->listAttributes($node, Lists::markers($node, $this->w, $path)[1])
            : BraceBlock::of($node);
    }

    /**
     * The prefix of the attribute line of the block at `path`: column 0 where C2-W-155 keeps it there.
     *
     * @param string $path
     * @param string $prefix
     *
     * @return string
     */
    private function attributePrefix(string $path, string $prefix): string
    {
        return ($this->indentPlan[$path] ?? false) ? substr($prefix, 0, -1) : $prefix;
    }

    /**
     * @param array<mixed> $nodes
     * @param int $at
     * @param int $followingAt
     * @param array<mixed>|null $before
     * @param array<mixed>|null $list
     * @param string $path
     * @param string $prefix
     * @param int $layout
     *
     * @return void
     */
    private function beforeSibling(
        array $nodes,
        int $at,
        int $followingAt,
        ?array $before,
        ?array $list,
        string $path,
        string $prefix,
        int $layout,
    ): void {
        $this->belowSibling = $path === '/children' ? $before : null;
        $this->above = $path !== '/children' ? null : (self::isCommentLine($before) ? $this->reaching : $before);
        $node = is_array($nodes[$at]) ? $nodes[$at] : [];
        $following = is_array($nodes[$followingAt] ?? null) ? $nodes[$followingAt] : null;
        $followingPath = $path . '/' . $followingAt;
        $attributes = '';
        if (
            self::isCommentLine($node) && $before !== null && $this->isOpenContainer($before) && $following !== null
            && $this->w->prewritten !== $followingPath
        ) {
            $attributes = $this->followingAttributes($following, $followingPath);
        }
        if (self::isCommentLine($node) && $this->w->prewritten === $followingPath) {
            // The attribute line written above the comments closes the container (C2-W-122).
            $this->reaching = null;
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
     * @param int $previous
     *
     * @return int
     */
    private static function followingAfter(array $nodes, int $at, int $previous): int
    {
        return $previous > $at ? $previous : self::afterComments($nodes, $at);
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
        if ($list !== null && ($node['type'] ?? null) === 'list' && self::adjacentOrdered($list, $node)) {
            // The next list's attribute line would be a lazy line of the open paragraph above it (C2-B-024).
            return $this->takesLineBelow($list)
                && $this->listAttributes($node, Lists::markers($node, $this->w, $path)[1]) !== '' ? 1 : 0;
        }

        if (self::directBelowContainer($before, $node, $path)) {
            return 0;
        }

        return $nested ? 0 : 1;
    }

    /**
     * C2-W-154: a top-level paragraph that would open with a typed opener, below a list, footnote or description
     * whose last block takes no lazy line, is written on the line directly after it; the opener closes it and stays
     * text (C2-B-026).
     *
     * @param array<mixed> $before
     * @param array<mixed> $node
     * @param string $path
     *
     * @return bool
     */
    private static function directBelowContainer(array $before, array $node, string $path): bool
    {
        return in_array($before['type'] ?? '', ['list', 'footnote', 'definition_list'], true)
            && ($node['type'] ?? '') === 'paragraph'
            && preg_match('#^/children/\d+$#', $path) === 1 && BraceBlock::of($node) === ''
            && self::listEnd($before) === 'closed' && self::opensWithTypedLead($node);
    }

    /**
     * C2-W-155: a top-level paragraph that would open with a typed opener, below a list or description list whose last
     * block takes a lazy line, follows that list written one space in, so its indented first line stays outside it.
     *
     * @param array<mixed> $before
     * @param array<mixed> $node
     * @param string $path
     *
     * @return bool
     */
    private static function indentedAbove(array $before, array $node, string $path): bool
    {
        return in_array($before['type'] ?? '', ['list', 'definition_list'], true)
            && ($node['type'] ?? '') === 'paragraph'
            && preg_match('#^/children/\d+$#', $path) === 1 && BraceBlock::of($node) === ''
            && self::listEnd($before) === 'lazy' && self::opensWithTypedLead($node);
    }

    /**
     * How the block a list, footnote or description ends on treats a column-0 line directly below it: `lazy` where
     * the line would continue it (C2-B-024), `closed` where the line closes it (C2-B-026), or `unknown`.
     *
     * @param array<mixed> $node
     *
     * @return string
     */
    private static function listEnd(array $node): string
    {
        $type = $node['type'] ?? '';
        if (in_array($type, self::LIST_LIKE, true)) {
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $last = end($children);

            return is_array($last) ? self::listEnd($last) : 'unknown';
        }
        if ($type === 'paragraph') {
            return 'lazy';
        }

        return in_array($type, ['code_block', 'raw_block', 'div', 'table', 'heading'], true) && !isset($node['caption'])
            ? 'closed' : 'unknown';
    }

    /**
     * Whether the paragraph `node` starts with an em dash node of source `---` and a format name (C2-B-232).
     *
     * @param array<mixed> $node
     *
     * @return bool
     */
    private static function opensWithTypedLead(array $node): bool
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $first = $children[0] ?? null;
        $next = $children[1] ?? null;
        if (
            !is_array($first) || ($first['type'] ?? null) !== 'smart_punctuation' || ($first['source'] ?? null) !== '---'
            || !is_array($next) || ($next['type'] ?? null) !== 'text'
        ) {
            return false;
        }
        $line = '---' . Inline::str($next, 'value');
        $line = substr($line, 0, strcspn($line, "\n"));

        return LineShapes::typedFrontmatter($line, 0, strlen($line)) !== null;
    }

    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     */
    private static function adjacentOrdered(array $before, array $after): bool
    {
        $left = is_array($before['marker'] ?? null) ? $before['marker'] : [];
        $right = is_array($after['marker'] ?? null) ? $after['marker'] : [];

        return ($left['kind'] ?? null) === 'ordered' && ($right['kind'] ?? null) === 'ordered'
            && ($left['delimiter'] ?? null) === ($right['delimiter'] ?? null);
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
            if (self::isRegionMarker($node)) {
                // A region marker closes the container, so it follows a closed block directly, as at top level; a fence
                // the container ends with keeps its closer and one blank line (C2-W-034, C2-W-123).
                return $this->takesLineBelow($before) || $this->lastFence($before) !== null ? 1 : 0;
            }
            // C2-W-123 writes the final fence open when a comment follows it.
            if ($this->lastFence($before) !== null) {
                return 0;
            }
            // C2-B-138: an ordinary comment line stays in the open container.
            $this->w->lose($path, 'comment', null);

            return 1;
        }
        if (
            self::isCommentLine($node) && self::isCommentLine($before) && $this->reaching !== null
            && $this->isOpenContainer($this->reaching) && !self::isRegionMarker($node)
        ) {
            // C2-B-138: so does every later comment line of the run.
            $this->w->lose($path, 'comment', null);
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
        if (
            $next === null || !self::isCommentLine($next) || self::isRegionMarker($next) || !$this->isOpenContainer($node)
            || $this->lastFence($node) === null
        ) {
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
            $name = $this->divName($node);
            $block = BraceBlock::of($node, $name === null ? [] : ['skipClasses' => [0]]);

            return $block === '' && $name !== null ? BraceBlock::of($node) : $block;
        }
        if ($type === 'table') {
            $line = $this->tables()->attributeLine($node, $path);

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
            'snippet_definition' => $this->snippetDefinition($node, $path, $prefix),
            'snippet_use' => $this->w->emit($prefix, Syntax::spelling(Inline::str($node, 'name'))),
            'definition_term' => $this->textLeaf($node, $path, $prefix, ':: ', '   '),
            'table' => $this->table($node, $path, $prefix),
            default => null,
        };

        return in_array($type, [
            'paragraph', 'heading', 'thematic_break', 'code_block', 'raw_block', 'comment', 'frontmatter', 'include',
            'link_reference_definition', 'abbreviation_def', 'snippet_definition', 'snippet_use', 'definition_term', 'table',
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
     * @return \MarkupCarve\Carve\Writer\Context
     */
    private function context(array $nodes, bool $lineStart, string $prefix = ''): Context
    {
        $c = new Context();
        $c->bareBreaks = $this->bareBreaks;
        $c->counts = Inline::counts($nodes);
        $c->lineStart = $lineStart;
        $c->blockDepth = $this->blockDepth - 1;
        $c->hasFencedAncestor = $this->fencedAncestors > 0;
        $c->fenceRun = $this->fenceRun;
        $c->inQuote = str_contains($prefix, '>');
        $c->afterDescriptions = $this->w->afterDescriptions;
        $c->boxLead = $this->w->lead !== null && $this->w->lead === $this->w->boxLead;
        $c->owner = $prefix === '' && $this->blockDepth === 1 ? 'top'
            : (str_ends_with($prefix, '> ') || str_ends_with($prefix, '>') ? 'quote' : $this->bodyOwner);
        $c->writerState = $this->w;

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
        // C2-W-159: a paragraph whose only child is an inline use reads as a block use, so it is written as one.
        $children = $node['children'] ?? null;
        if (
            is_array($children) && count($children) === 1 && is_array($children[0]) && ($children[0]['type'] ?? null) === 'snippet_use'
            && !isset($node['attrs']) && !isset($node['caption'])
        ) {
            $this->w->lose($path, 'paragraph', null);
            $this->w->emit($prefix, Syntax::spelling(Inline::str($children[0], 'name')));

            return;
        }
        $text = $this->textLeaf($node, $path, $prefix, '', '');
        $role = is_string($node['role'] ?? null) ? $node['role'] : null;
        if ($text !== null && self::readRole($text, $node) !== $role) {
            $this->w->lose($path, 'paragraph', 'role', $role !== null);
        }
        $this->caption($node['caption'] ?? null, $path . '/caption', $prefix);
    }

    /**
     * C2-W-154: the first line indented by one space; directly below a list, footnote or description that ends in a
     * closed block it stays at column 0. Below a footnote that ends in a block taking lazy lines it has no spelling.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $text
     * @param bool $attributes
     *
     * @return string
     */
    private function metadataLeadText(array $node, string $path, string $text, bool $attributes): string
    {
        $above = $this->above;
        if ($attributes || $above === null || !$this->isOpenContainer($above)) {
            return ' ' . $text;
        }
        // C2-W-154 needs the line directly below the container; across a comment line a blank line stands between.
        if ($above === $this->belowSibling && self::directBelowContainer($above, $node, $path)) {
            return $text;
        }
        if (isset($this->spelledLeads[$path])) {
            return ' ' . $text;
        }
        // Below a blank line the indented line would be a block of the open container above (C2-B-026).
        $this->w->lose($path, 'paragraph', null);

        return ' ' . $text;
    }

    /**
     * C2-W-154: a top-level paragraph whose first line would read as a typed opener (C2-B-232) and starts with an em
     * dash node, which no backslash keeps, is written with that line indented by one space.
     *
     * @param array<mixed> $children
     * @param string $path
     * @param string $text
     *
     * @return bool
     */
    private static function metadataLead(array $children, string $path, string $text): bool
    {
        $first = $children[0] ?? null;
        if (
            !is_array($first) || ($first['type'] ?? null) !== 'smart_punctuation' || ($first['source'] ?? null) !== '---'
            || preg_match('#^/children/\d+$#', $path) !== 1
        ) {
            return false;
        }

        return LineShapes::typedFrontmatter($text, 0, strcspn($text, "\n")) !== null;
    }

    /**
     * C2-B-157, C2-B-158, C2-B-198: the role a paragraph written as `$text` reads back with. The reader gives it to a
     * paragraph of one line that is a block image or display math, and keeps it when that is its one child besides
     * the comments of comment-only lines.
     *
     * @param string $text
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function readRole(string $text, array $node): ?string
    {
        $lines = explode("\n", rtrim($text, "\n"));
        $line = ltrim(array_shift($lines), " \t");
        $n = strlen($line);
        if ($n === 0) {
            return null;
        }
        foreach ($lines as $below) {
            // C2-B-157: comment-only lines below the line leave the paragraph one line.
            if (preg_match('/^[ \t]*%%(?!%)/', $below) !== 1) {
                return null;
            }
        }
        $role = match (true) {
            LineShapes::isBlockImage($line, 0, $n) => 'image',
            LineShapes::isDisplayMath($line, 0, $n) => 'math',
            default => null,
        };
        // A break, or text of only spaces and tabs, is no child of a one-line paragraph on read-back.
        $children = array_values(array_filter(
            is_array($node['children'] ?? null) ? $node['children'] : [],
            static fn (mixed $child): bool => !is_array($child) || !self::vanishes($child),
        ));
        $only = array_shift($children);
        foreach ($children as $child) {
            if (!is_array($child) || ($child['type'] ?? '') !== 'comment') {
                return null;
            }
        }

        return is_array($only) && ($only['type'] ?? '') === $role ? $role : null;
    }

    /**
     * @param array<mixed> $child
     *
     * @return bool
     */
    private static function vanishes(array $child): bool
    {
        $type = $child['type'] ?? '';
        $value = $child['value'] ?? null;

        return $type === 'soft_break' || $type === 'hard_break'
            || ($type === 'text' && is_string($value) && trim($value, " \t") === '');
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param string $mark
     * @param string $indent
     *
     * @return string|null The written content, null when the leaf is lost.
     */
    private function textLeaf(array $node, string $path, string $prefix, string $mark, string $indent): ?string
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        // C2-W-151: content of only spaces and tabs that end its line is dropped, which leaves the leaf empty.
        if (self::onlyBlankText($children)) {
            $this->w->lose($path, Inline::str($node, 'type'), null);

            return null;
        }
        if ($mark !== '') {
            self::loseLeadingBlank($children, $path, $this->w);
        }
        $lines = count($this->w->lines);
        $this->attributeLine($node, $path, $prefix);
        if (($node['type'] ?? null) === 'heading') {
            // C2-B-154: behind a lead wider than the body column, a continuation still stands past the marker.
            $indent = str_repeat(' ', $this->leadColumns($prefix)) . $indent;
        }
        $this->w->lead = ($this->w->lead ?? $prefix) . $mark;
        $c = $this->context($children, $mark === '', $prefix);
        $outer = $this->w->takeSmartRun();
        $text = $this->inline->content($children, $path . '/children', $c, -1);
        if (Inline::endsInLoneEmptyText($children)) {
            $this->w->lose($path, Inline::str($node, 'type'), null);
        }
        if ($mark === '') {
            $protected = FirstLine::lead($text, $children, $this->w->lead, $path, $this->w->repairTrace === null);
            $kind = $this->w->repairTrace === null && $protected !== $text ? FirstLine::kind($text, $protected) : null;
            if ($kind !== null) {
                $c = $this->context($children, true, $prefix);
                $c->kinds = [$kind => true];
                $text = $this->inline->content($children, $path . '/children', $c, -1);
                $protected = FirstLine::lead($text, $children, $this->w->lead, $path, true);
            }
            $text = $protected;
        }
        $text = self::endContent($text, $children, $c);
        if ($mark === '' && ($node['type'] ?? null) === 'paragraph' && self::metadataLead($children, $path, $text)) {
            $text = $this->metadataLeadText($node, $path, $text, count($this->w->lines) > $lines);
        }
        if ($this->w->takeSmartRun()) {
            SmartReadBack::leafReadBack($this->w, $children, $text, $path, Inline::str($node, 'type'), $mark, $indent);
        }
        $this->w->smartRun = $outer;
        $this->w->emitLines($prefix, $indent, $text);

        return $text;
    }

    /**
     * A block opener can form across inline nodes, after their individual escape decisions. Try the earliest text
     * punctuation that keeps the whole emitted line a paragraph with the same children.
     *
     * @param string $text
     * @param array<mixed> $children
     * @param string|null $head
     * @param string $path
     *
     * @return string
     */
    public static function protectParagraphLead(string $text, array $children, ?string $head, string $path): string
    {
        if (!self::mayOpenBlock($text)) {
            return $text;
        }
        // C2-W-092: a caret opens a caption line only with a following gap.
        // Its other continuations cannot change the paragraph's block kind.
        if ($text[0] === '^' && !in_array($text[1] ?? '', [' ', "\t", ''], true)) {
            return $text;
        }
        // Definition lines open only at document column zero, never inside a container.
        if ($text[0] === '[' && preg_match('#^/children/[0-9]+$#', $path) !== 1) {
            return $text;
        }
        $head ??= '';
        $key = null;
        if (self::$leadMemo !== null) {
            $key = self::leadKey($head, $text, $children);
            if (isset(self::$leadMemo[$key])) {
                return self::$leadMemo[$key];
            }
        }
        $first = is_array($children[0] ?? null) ? $children[0] : [];
        $referenceLead = in_array($first['type'] ?? '', ['footnote_ref', 'link'], true)
            && str_starts_with($text, '[') && str_contains($text, ']');
        $afterReference = $referenceLead ? (int)strpos($text, ']') + 1 : 0;
        $nested = $head === '' ? [] : self::containers($head . 'x');
        $lead = self::readsAsParagraph($head . $text, $children, true, $nested)
            ? $text
            : self::escapedLead($text, $children, $head, $referenceLead, $afterReference, $nested);
        if ($key !== null) {
            self::$leadMemo[$key] = $lead;
        }

        return $lead;
    }

    /**
     * The memo key of a lead decision. The children are hashed one at a time: the serialized list of a long paragraph
     * is a string of several megabytes (carve-php#5722).
     *
     * @param string $head
     * @param string $text
     * @param array<mixed> $children
     *
     * @return string
     */
    private static function leadKey(string $head, string $text, array $children): string
    {
        $hash = hash_init('xxh128');
        hash_update($hash, $head . "\0" . $text . "\0" . count($children) . "\0");
        foreach ($children as $at => $child) {
            hash_update($hash, serialize($at) . serialize($child));
        }

        return hash_final($hash);
    }

    /**
     * Runs one write call with the lead decisions shared by every write of its tree.
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     */
    public static function withLeadMemo(Closure $write): mixed
    {
        $outer = self::$leadMemo;
        self::$leadMemo ??= [];
        try {
            return $write();
        } finally {
            self::$leadMemo = $outer;
        }
    }

    /**
     * The earliest escape of a text punctuation that keeps the line a paragraph with the same children.
     *
     * @param string $text
     * @param array<mixed> $children
     * @param string $head
     * @param bool $referenceLead
     * @param int $afterReference
     * @param array<string> $nested
     *
     * @return string
     */
    private static function escapedLead(
        string $text,
        array $children,
        string $head,
        bool $referenceLead,
        int $afterReference,
        array $nested,
    ): string {
        // C2-W-078: an ordered marker beside a smart punctuation run that reads back as other nodes keeps no escape
        // with the same children. The first one that keeps the paragraph is written, and the read-back reports the run.
        $paragraph = null;
        $marker = ctype_alnum($text[0]);
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            if ($i < $afterReference || !Text::isPunct($text[$i]) || ($i > 0 && $text[$i - 1] === '\\')) {
                continue;
            }
            $candidate = substr($text, 0, $i) . '\\' . substr($text, $i);
            $isParagraph = false;
            if (self::readsAsParagraph($head . $candidate, $children, $referenceLead, $nested, $isParagraph)) {
                return $candidate;
            }
            if ($isParagraph && $marker) {
                $paragraph ??= $candidate;
            }
        }

        return $paragraph ?? $text;
    }

    public static function mayOpenBlock(string $text): bool
    {
        if ($text === '' || str_contains($text, "\n")) {
            return false;
        }

        // C2-B-036: an ordered marker opens with a letter or a digit.
        $n = strlen($text);

        return Text::isPunct($text[0])
            || (ctype_alnum($text[0]) && !Markers::quickReject($text, 0, $n) && Markers::read($text, 0, $n) !== null);
    }

    /**
     * @param string $line
     * @param array<mixed> $children
     * @param bool $allowUnresolvedReference
     * @param array<string> $containers The containers the line's head opens, which the paragraph stands in.
     * @param bool $isParagraph Set to whether the line reads as a paragraph, whatever its children.
     */
    private static function readsAsParagraph(
        string $line,
        array $children,
        bool $allowUnresolvedReference = false,
        array $containers = [],
        bool &$isParagraph = false,
    ): bool {
        if ($allowUnresolvedReference) {
            $isParagraph = self::firstBlockIsParagraph(self::blocksOf($line . "\n"), $containers);

            return $isParagraph;
        }
        $parsed = Carve::parse($line . "\n");
        $tree = TreeEncoder::decoded($parsed);
        unset($parsed);
        $rootChildren = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        $node = $rootChildren[0] ?? null;
        foreach ($containers as $type) {
            if (!is_array($node) || ($node['type'] ?? '') !== $type) {
                return false;
            }
            $nested = is_array($node['children'] ?? null) ? $node['children'] : [];
            $node = $nested[0] ?? null;
        }

        $isParagraph = is_array($node) && ($node['type'] ?? '') === 'paragraph';

        return $isParagraph && TreeEquality::equal($node['children'] ?? [], $children);
    }

    /**
     * The block tree of a source without its inline pass: no later stage changes a block's kind, and the inline nodes
     * of a long paragraph cost far more than its blocks (carve-php#5664).
     */
    public static function blocksOf(string $source): Node
    {
        $sink = new TreeSink($source);
        (new BlockParser($sink))->parse($source);
        assert($sink->root !== null);

        return $sink->root;
    }

    /**
     * Whether the first block of a parsed line is a paragraph inside the given containers, outermost first.
     *
     * @param \MarkupCarve\Carve\Node $root
     * @param array<string> $containers
     */
    public static function firstBlockIsParagraph(Node $root, array $containers): bool
    {
        $node = $root->children[0] ?? null;
        foreach ($containers as $type) {
            if ($node === null || $node->type !== $type) {
                return false;
            }
            $node = $node->children[0] ?? null;
        }

        return $node !== null && $node->type === 'paragraph';
    }

    /**
     * The list, item and quote containers the first block of a line opens, outermost first.
     *
     * @param string $line
     *
     * @return array<string>
     */
    public static function containers(string $line): array
    {
        static $known = [];
        if (isset($known[$line])) {
            return $known[$line];
        }
        if (count($known) >= 256) {
            $known = [];
        }
        $tree = TreeEncoder::decoded(Carve::parse($line . "\n"));
        $node = is_array($tree['children'] ?? null) ? ($tree['children'][0] ?? null) : null;
        $types = [];
        while (is_array($node) && in_array($node['type'] ?? '', ['list', 'list_item', 'block_quote'], true)) {
            $types[] = $node['type'];
            $nested = is_array($node['children'] ?? null) ? $node['children'] : [];
            $node = $nested[0] ?? null;
        }

        return $known[$line] = $types;
    }

    /**
     * C2-W-022: the line ending of a hard break at the end of the content is the line ending of the leaf.
     *
     * @param string $text
     * @param array<mixed> $children
     * @param \MarkupCarve\Carve\Writer\Context $c
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
     * How many columns (C2-B-003) the pending lead reaches past `prefix`, or 0 without a lead.
     *
     * @param string $prefix
     *
     * @return int
     */
    private function leadColumns(string $prefix): int
    {
        $lead = $this->w->lead;
        if ($lead === null) {
            return 0;
        }

        return max(0, mb_strlen($lead, 'UTF-8') - mb_strlen(substr($lead, 0, $this->w->leadSkip), 'UTF-8') - mb_strlen($prefix, 'UTF-8'));
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
            $outer = $this->w->takeSmartRun();
            $outerTexts = Text::$captionTexts;
            Text::$captionTexts = self::captionTexts($inlines, $path . '/children/' . $at . '/children');
            try {
                $part = $this->inline->content($inlines, $path . '/children/' . $at . '/children', $c, -1);
            } finally {
                Text::$captionTexts = $outerTexts;
            }
            if ($this->w->takeSmartRun()) {
                SmartReadBack::leafReadBack($this->w, $inlines, $part, $path . '/children/' . $at, 'caption');
            }
            $this->w->smartRun = $outer;
            $parts[] = $part;
        }
        $this->w->lead = ($this->w->lead ?? $prefix) . '^ ';
        $this->w->emitLines($prefix, '  ', implode(' ', $parts));
    }

    /**
     * C2-B-207: the top-level texts of a caption before its number mark that hold a `#`.
     *
     * @param array<mixed> $inlines
     * @param string $path
     *
     * @return array<string, true>
     */
    private static function captionTexts(array $inlines, string $path): array
    {
        $texts = [];
        foreach ($inlines as $at => $inline) {
            $type = is_array($inline) ? $inline['type'] ?? null : null;
            if ($type === 'caption_number') {
                break;
            }
            if ($type === 'text' && str_contains(Inline::str($inline, 'value'), '#')) {
                $texts[$path . '/' . $at] = true;
            }
        }

        return $texts;
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
        $inner = $this->bodyPrefix($prefix, $lines);
        $this->w->emit($prefix, $run . $info);
        $this->verbatim($lines, $inner);
        if (!isset($this->w->openFences[$path])) {
            $this->w->emit($inner, $run);
        } elseif (self::endsInBlankLine($lines)) {
            $this->w->lose($path, Inline::str($node, 'type'), 'value');
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
        // C2-B-122: the parser reads only a run of language-word characters as the syntax.
        if (strspn($syntax, Tables::CHARS_LANGUAGE_WORD) !== strlen($syntax)) {
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
     * How far the lead of the current line reaches past `prefix`: a task box, a label or attributes on a marker line.
     *
     * @param string $prefix
     *
     * @return int
     */
    private function leadIndent(string $prefix): int
    {
        return $this->w->lead === null ? 0 : max(0, strlen($this->w->lead) - $this->w->leadSkip - strlen($prefix));
    }

    /**
     * C2-B-029: an opener behind a lead strips up to its indent from each line. Where a line starts with whitespace, the
     * lines and the closer stand at the opener's column (C2-W-033); otherwise at the content column (C2-W-024).
     *
     * @param string $prefix
     * @param array<string> $lines
     *
     * @return string
     */
    private function bodyPrefix(string $prefix, array $lines): string
    {
        $indent = $this->leadIndent($prefix);
        if ($indent === 0) {
            return $prefix;
        }
        foreach ($lines as $line) {
            if (str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
                return $prefix . str_repeat(' ', $indent);
            }
        }

        return $prefix;
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
        if ($title !== null && str_contains($title, "\n")) {
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
        $inner = $this->bodyPrefix($prefix, $lines);
        $this->w->emit($prefix, $run);
        $this->verbatim($lines, $inner);
        if (!isset($this->w->openFences[$path])) {
            $this->w->emit($inner, $run);
        } elseif (self::endsInBlankLine($lines)) {
            $this->w->lose($path, 'comment', 'value');
        }
    }

    /**
     * C2-B-113: an open fence drops the blank lines at the end of its body, so its value cannot end in one (C2-W-123).
     *
     * @param array<string> $lines
     *
     * @return bool
     */
    private static function endsInBlankLine(array $lines): bool
    {
        return $lines !== [] && trim((string)end($lines), " \t") === '';
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
        if ($format !== 'yaml' && preg_match('/^[A-Za-z0-9]+$/', $format) !== 1) {
            // C2-W-124: the node would read back as another type.
            $this->w->lose($path, 'frontmatter', null);
        }
        $lines = $this->valueLines($node, $path);
        if (in_array('---', $lines, true)) {
            $this->w->lose($path, 'frontmatter', 'value');
        }
        // C2-B-232: only the first child of the document may use the bare opener; a later block names its format.
        $leading = $path === '/children/0' && !is_array($node['attrs'] ?? null);
        $this->attributeLine($node, $path, $prefix);
        $this->w->emit($prefix, $format === 'yaml' && $leading ? '---' : '---' . $format);
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
        $spelling = $this->w->fact($path, 'optionSpelling');
        $this->w->emit($prefix, HtmlRenderer::includeText($include, is_array($spelling) ? $spelling : null));
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
        $lost = $this->blankKey($node, $path);
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
            $lost = true;
            $this->w->lose($path, 'link_reference_definition', 'href');
        }
        $title = is_string($node['tooltip'] ?? null) ? ' ' . BraceBlock::quoted($node['tooltip']) : '';
        $sep = $written === '' && $title === '' ? '' : ' ';
        $line = '[' . Inline::str($node, 'key') . ']:' . $sep . $written . $title;
        $tooltip = $node['tooltip'] ?? null;
        $plain = preg_match(self::PLAIN_KEY, Inline::str($node, 'key')) === 1
            && preg_match('~^[A-Za-z0-9/._:?#@!$&+,;=-]+$~', $href) === 1
            && ($tooltip === null || (is_string($tooltip) && preg_match(self::PLAIN_TEXT, $tooltip) === 1));
        if (!$lost && !$plain) {
            $this->readDefinitionBack($node, $path, $line);
        }
        $this->w->emit($prefix, $line);
    }

    /**
     * C2-W-011, C2-W-117: a definition line that reads back as another tree is reported as the read-back of carve-js
     * reports it: at the first differing field, or at the node where the line is no such definition.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $line The definition line; a footnote's has a stand-in body.
     *
     * @return void
     */
    private function readDefinitionBack(array $node, string $path, string $line): void
    {
        $type = Inline::str($node, 'type');
        $read = ReadbackRound::read($line . "\n")['children'] ?? [];
        $block = is_array($read) && count($read) === 1 && is_array($read[0] ?? null) ? $read[0] : null;
        if ($block === null || ($block['type'] ?? null) !== $type) {
            $this->w->lose($path, $type, null);

            return;
        }
        $skip = ['type' => true, 'attrs' => true, 'pos' => true, 'children' => true];
        foreach ($node as $name => $value) {
            if (!isset($skip[$name]) && ($block[$name] ?? null) !== $value) {
                $this->w->lose($path, $type, (string)$name);

                return;
            }
        }
        foreach (array_keys($block) as $name) {
            if (!isset($skip[$name]) && !array_key_exists($name, $node)) {
                $this->w->lose($path, $type, (string)$name);

                return;
            }
        }
    }

    /**
     * C2-I-079: a key of only spaces, tabs and line feeds folds to nothing.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return bool Whether the key is lost.
     */
    private function blankKey(array $node, string $path): bool
    {
        if (trim(Inline::str($node, 'key'), " \t\n") !== '') {
            return false;
        }
        $this->w->lose($path, Inline::str($node, 'type'), 'key');

        return true;
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
        $line = '*[' . $key . ']: ' . $expansion;
        if ($expansion !== $raw || $expansion === '') {
            $this->w->lose($path, 'abbreviation_def', 'expansion');
        } elseif (preg_match('/^[A-Za-z0-9]+$/', $key) !== 1 || preg_match(self::PLAIN_TEXT, $expansion) !== 1) {
            $this->readDefinitionBack($node, $path, $line);
        }
        $this->w->emit($prefix, $line);
    }

    /**
     * C2-W-159: `{$`, the name, the attributes as the parts of one block, `}: ` and the value on one line. A line break
     * of a supplied value is written as one space and reported.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function snippetDefinition(array $node, string $path, string $prefix): void
    {
        $block = BraceBlock::of($node);
        $marker = '{$' . Inline::str($node, 'name') . ($block === '' ? '' : ' ' . substr($block, 1, -1)) . '}: ';
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $broken = false;
        $node['children'] = self::spacedBreaks($children, $broken);
        if ($broken) {
            $this->w->lose($path, 'snippet_definition', null);
        }
        unset($node['attrs'], $node['lang'], $node['dir']);
        $this->textLeaf($node, $path, $prefix, $marker, '');
    }

    /**
     * The inline nodes with every line break, in any slot, as one space.
     *
     * @param array<mixed> $nodes
     * @param bool $broken Set when a break was replaced.
     *
     * @return array<mixed>
     */
    private static function spacedBreaks(array $nodes, bool &$broken): array
    {
        foreach ($nodes as $at => $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? null;
            if ($type === 'soft_break' || $type === 'hard_break') {
                $nodes[$at] = ['type' => 'text', 'value' => ' '];
                $broken = true;

                continue;
            }
            foreach ($node as $key => $value) {
                if ($key !== 'attrs' && $key !== 'data' && is_array($value) && array_is_list($value) && is_array($value[0] ?? null)) {
                    $node[$key] = self::spacedBreaks($value, $broken);
                }
            }
            $nodes[$at] = $node;
        }

        return $nodes;
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
        $behind = $this->leadIndent($prefix);
        $this->w->lead = ($this->w->lead ?? $prefix) . $mark;
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        // C2-B-067: a description list opened on the marker line takes its descriptions at its own column.
        if (is_array($children[0] ?? null) && ($children[0]['type'] ?? null) === 'definition_list') {
            $width = mb_strlen($mark, 'UTF-8');
        }
        $priorOwner = $this->bodyOwner;
        $this->bodyOwner = ($node['type'] ?? '') === 'definition_description' ? 'description' : 'body';
        try {
            $this->blocks($children, $path . '/children', $prefix . str_repeat(' ', $behind + $width), $tight);
        } finally {
            $this->bodyOwner = $priorOwner;
        }
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
        $key = Inline::str($node, 'key');
        if (!$this->blankKey($node, $path) && preg_match(self::PLAIN_KEY, $key) !== 1) {
            $this->readDefinitionBack($node, $path, '[^' . $key . ']: x');
        }
        $this->body($node, $path, $prefix, '[^' . $key . ']: ', 2, false);
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
                $this->w->emit($this->attributePrefix($path, $prefix), $block);
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
        $this->w->boxLead = $task === null ? ($this->w->lead ?? $prefix) . $head . $gap : null;
        // C2-B-043: a bare dot right behind a bare dot opens no list, so a list first in this body takes numbers.
        $this->w->bareDotLead = $marker === '.' && $task === null ? ($this->w->lead ?? $prefix) . $head . $gap : null;
        $this->body($node, $path, $prefix, $head . $gap . $box, strlen($marker) + strlen($gap), $tight);
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
        $this->attributeLine($node, $path, $this->attributePrefix($path, $prefix));
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
        if (isset($this->longestRunByPath[$path])) {
            return $this->longestRunByPath[$path];
        }
        $longest = 0;
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $at => $child) {
            if (!is_array($child)) {
                continue;
            }
            $below = $this->runsBelow($child, $path . '/children/' . $at);
            $longest = max($longest, $this->hasColonRun($child, $path . '/children/' . $at) ? max(3, $below + 1) : $below);
        }

        return $this->longestRunByPath[$path] = $longest;
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
        $this->fencedAncestors++;
        $priorRun = $this->fenceRun;
        $this->fenceRun = strlen($run);
        // C2-B-015: a list marker interrupts a paragraph only where the paragraph's container is an open list's item.
        $priorOwner = $this->bodyOwner;
        $this->bodyOwner = 'fenced';
        try {
            $write();
        } finally {
            $this->fencedAncestors--;
            $this->fenceRun = $priorRun;
            $this->bodyOwner = $priorOwner;
        }
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
            $this->w->leadSkip = strlen($this->w->lead) - strlen($prefix) - 2;
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
            $outer = $this->w->takeSmartRun();
            $title = $this->inline->content($node['title'], $path . '/title', $c, 0x22);
            if ($this->w->takeSmartRun()) {
                SmartReadBack::leafReadBack($this->w, $node['title'], $title, $path, 'title');
            }
            $this->w->smartRun = $outer;
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
    private function divName(array $node): ?string
    {
        $first = self::classes($node)[0] ?? null;
        if ($first === null || !BraceBlock::isName($first)) {
            return null;
        }

        if (isset(Tables::DIV_NAME_KINDS[$first])) {
            return null;
        }
        foreach ($this->w->extensions as $extension) {
            $definitions = is_string($extension) ? Standard::definitions($extension) ?? [] : [$extension];
            foreach ($definitions as $definition) {
                if ($definition instanceof ExtensionDefinition && $definition->matcher === ['form' => 'div', 'name' => $first]) {
                    return null;
                }
            }
        }

        return $first;
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
        $named = $this->divName($node);
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
                if (is_array($line) && ($line['type'] ?? '') === 'comment') {
                    $this->comment($line, $path . '/children/' . $at, $prefix);

                    continue;
                }
                $inlines = is_array($line) && is_array($line['children'] ?? null) ? $line['children'] : [];
                $lazy = $this->takeLazyComments($inlines, $path . '/children/' . $at . '/children/');
                // C2-W-151: text of only spaces and tabs at the end of the line is dropped, so a hard break before it ends the line.
                $blanks = [];
                while ($inlines !== [] && self::blankText(end($inlines))) {
                    array_pop($inlines);
                    $blanks[] = $path . '/children/' . $at . '/children/' . count($inlines);
                }
                $c = $this->context($inlines, true);
                $c->inLineBlock = true;
                $outer = $this->w->takeSmartRun();
                $text = $this->inline->content($inlines, $path . '/children/' . $at . '/children', $c, -1);
                // C2-B-123: bare, a line of three or more colons closes the line block. C2-W-088 escapes the line kind,
                // its first character; where that is a space, which C2-B-130 keeps as content, the line has no spelling.
                if (preg_match('/^[ \t]*:{3,}[ \t]*$/', $text) === 1) {
                    if ($text[0] === ':') {
                        $text = '\\' . $text;
                    } else {
                        $this->w->lose($path . '/children/' . $at . '/children/0', 'text', null);
                        $this->w->lose('', 'document', null);
                    }
                }
                $text = self::endContent($text, $inlines, $c);
                if ($this->w->takeSmartRun()) {
                    SmartReadBack::leafReadBack($this->w, $inlines, $text, $path . '/children/' . $at, 'line');
                }
                $this->w->smartRun = $outer;
                $this->w->emit($prefix, $text);
                if ($lazy !== []) {
                    foreach ($lazy as $lazyPath => $comment) {
                        $this->lazyComment($comment, $lazyPath, $prefix);
                    }
                    // The spaces before the comment do not end the line in the tree.
                    foreach ($blanks as $blank) {
                        $this->w->lose($blank, 'text', 'value');
                    }
                }
            }
            $this->bareBreaks = false;
        });
    }

    /**
     * Takes the run of line comments that ends a line of a line block off its inlines, by path in source order: each is
     * a lazy line below the line (carve#6472). CC-327, C2-B-135: any other line comment of the line reads back as text.
     *
     * @param array<mixed> $inlines
     * @param string $path
     *
     * @return array<string, array<mixed>>
     */
    private function takeLazyComments(array &$inlines, string $path): array
    {
        $isLine = static fn (mixed $node): bool => is_array($node) && ($node['type'] ?? '') === 'comment'
            && ($node['form'] ?? '') === 'line';
        $lazy = [];
        for ($last = end($inlines); is_array($last) && $isLine($last); $last = end($inlines)) {
            $lazy[$path . (count($inlines) - 1)] = $last;
            array_pop($inlines);
        }
        foreach ($inlines as $at => $inline) {
            if ($isLine($inline)) {
                $this->w->lose($path . $at, 'comment', null);
            }
        }

        return array_reverse($lazy, true);
    }

    /**
     * C2-B-024: a line comment at the end of a line of a stanza is a lazy comment-only line, which its containers do not
     * own. At the top level every line is owned, so it has no spelling there.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     *
     * @return void
     */
    private function lazyComment(array $node, string $path, string $prefix): void
    {
        if ($prefix === '') {
            $this->w->lose($path, 'comment', null);

            return;
        }
        $value = Inline::str($node, 'value');
        if (str_contains($value, "\n")) {
            $this->w->lose($path, 'comment', 'value');
            $value = str_replace("\n", ' ', $value);
        }
        $kept = rtrim($value, " \t");
        if ($kept !== $value) {
            $this->w->lose($path, 'comment', 'value');
        }
        $this->w->emit('', $kept === '' ? '%%' : '%% ' . $kept);
    }

    /**
     * @param array<mixed> $children
     *
     * @return bool
     */
    private static function onlyBlankText(array $children): bool
    {
        foreach ($children as $child) {
            if (!self::blankText($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-I-002: the spaces and tabs behind a heading or term marker are no part of the content, so text that starts
     * with them loses them, unless it holds nothing else and ends the line (C2-W-151).
     *
     * @param array<mixed> $children
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return void
     */
    private static function loseLeadingBlank(array $children, string $path, State $w): void
    {
        $first = $children[0] ?? null;
        $value = is_array($first) && ($first['type'] ?? '') === 'text' && is_string($first['value'] ?? null) ? $first['value'] : '';
        if (strspn($value, " \t") === 0) {
            return;
        }
        $after = $children[1] ?? null;
        $endsLine = $after === null || (is_array($after) && ($after['type'] ?? '') === 'soft_break');
        if (trim($value, " \t") !== '' || !$endsLine) {
            $w->lose($path . '/children/0', 'text', 'value');
        }
    }

    private static function blankText(mixed $node): bool
    {
        return is_array($node) && ($node['type'] ?? '') === 'text' && array_diff(array_keys($node), ['type', 'value', 'pos']) === []
            && is_string($node['value'] ?? null) && trim($node['value'], " \t") === '';
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
            $this->tables()->write($node, $path, $prefix, true);
        } else {
            $this->tables()->write($node, $path, $prefix, false);
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
        if (!ExtensionSource::readsBack($node, $text, $this->w->extensions, $this->w, $path)) {
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
