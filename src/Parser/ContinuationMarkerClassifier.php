<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;

/**
 * @internal
 */
final class ContinuationMarkerClassifier
{
    /**
     * @var list<array{kind: string, column: int, depth: int, content: int, first: bool}>
     */
    private array $stack = [];

    /**
     * @var array<int, array{owner: array{kind: string, column: int, depth: int, content: int, first: bool}|null, reason: string|null, column: int, depth: int, stale: bool, open: bool}>
     */
    private array $decisions = [];

    /**
     * @var array<int, array{kind: string, column: int, depth: int, content: int, first: bool}>
     */
    private array $firstBlocks = [];

    /**
     * @var array<int, int|null>
     */
    private array $listColumnsBefore = [];

    /**
     * @var array<int, int|null>
     */
    private array $footnoteColumnBefore = [];

    /**
     * @var array<string, true>
     */
    private array $closedOwners = [];

    private int $scanned = 0;

    private bool $paragraphOpen = false;

    private bool $descriptionLazy = false;

    /**
     * @var array{kind: string, column: int, depth: int, content: int, first: bool}|null
     */
    private ?array $carriedOwner = null;

    /**
     * @param array<int, string> $lines
     * @param \MarkupCarve\Carve\Parser\Block\ListParser $lists
     */
    public function __construct(private array $lines, private ListParser $lists)
    {
    }

    /**
     * @return array{owner: array{kind: string, column: int, depth: int, content: int, first: bool}|null, reason: string|null, column: int, depth: int, stale: bool, open: bool}
     */
    public function decision(int $index): array
    {
        $total = count($this->lines);
        while ($this->scanned <= $index && $this->scanned < $total) {
            $this->step($this->scanned++);
        }

        return $this->decisions[$index] ?? ['owner' => null, 'reason' => null, 'column' => -1, 'depth' => 0, 'stale' => false, 'open' => false];
    }

    /**
     * @return array{kind: string, column: int, depth: int, content: int, first: bool}|null
     */
    public function firstBlockOwner(int $index): ?array
    {
        $this->decision($index);

        return $this->firstBlocks[$index] ?? null;
    }

    public function innermostListColumnBefore(int $index): ?int
    {
        $this->decision($index);

        return $this->listColumnsBefore[$index] ?? null;
    }

    public function footnoteColumnBefore(int $index): ?int
    {
        $this->decision($index);

        return $this->footnoteColumnBefore[$index] ?? null;
    }

    private function step(int $index): void
    {
        $this->listColumnsBefore[$index] = null;
        $this->footnoteColumnBefore[$index] = null;
        $listFound = false;
        for ($at = count($this->stack) - 1; $at >= 0; $at--) {
            if (LayoutWork::$on) {
                LayoutWork::$markerStackEntriesExamined++;
            }
            if ($this->stack[$at]['kind'] === 'list' && !$listFound) {
                $this->listColumnsBefore[$index] = $this->stack[$at]['column'];
                $listFound = true;
            }
            if ($this->stack[$at]['kind'] === 'footnote' && $this->footnoteColumnBefore[$index] === null) {
                $this->footnoteColumnBefore[$index] = $this->stack[$at]['column'];
            }
        }
        $line = $this->lines[$index];
        [$body, $depth, $prefix] = $this->quoteBody($line);
        $column = self::columnAt($line, $prefix + strspn($body, " \t"));
        if (self::isPlus(ltrim($body, " \t"))) {
            if ($depth > 0 && ($line[$prefix - 1] ?? '') === '>' && strspn($body, " \t") === 0) {
                foreach ($this->stack as $entry) {
                    if ($entry['kind'] === 'quote' && $entry['depth'] === $depth) {
                        $column = max($column, $entry['column']);
                    }
                }
            }
            if (LayoutWork::$on) {
                LayoutWork::$markerLinesClassified++;
            }
            $owner = null;
            $deepestQuote = 0;
            $deepestList = 0;
            $listInQuote = false;
            foreach ($this->stack as $entry) {
                if ($entry['kind'] === 'quote' && $entry['depth'] > $deepestQuote) {
                    $deepestQuote = $entry['depth'];
                }
                if ($entry['kind'] === 'list' && $entry['depth'] > $deepestList) {
                    $deepestList = $entry['depth'];
                }
                if ($entry['kind'] === 'list' && $entry['depth'] >= $depth) {
                    $listInQuote = true;
                }
            }
            for ($at = count($this->stack) - 1; $at >= 0; $at--) {
                if (LayoutWork::$on) {
                    LayoutWork::$markerStackEntriesExamined++;
                }
                $entry = $this->stack[$at];
                if (
                    $entry['column'] === $column && $entry['depth'] === $depth
                    && ($entry['kind'] !== 'quote' || $depth === 0 || $deepestQuote > $depth)
                    && ($depth === 0 || $depth >= $deepestQuote || $entry['kind'] === 'list'
                        || $entry['kind'] === 'quote' && ($deepestList <= $depth || $deepestQuote > $depth))
                ) {
                    $owner = $entry;
                    if ($entry['kind'] !== 'quote') {
                        break;
                    }
                }
            }
            if (
                $this->carriedOwner !== null && $this->carriedOwner['kind'] === 'quote'
                && $this->carriedOwner['column'] === $column && $this->carriedOwner['depth'] === $depth
            ) {
                $owner = $this->carriedOwner;
            }
            if (
                $depth === 0 && ($this->carriedOwner === null || $this->carriedOwner['kind'] === 'quote')
                && ContainerPrefix::quoteContent($this->lines[$index - 1] ?? '') !== null
                && ($owner === null || $owner['kind'] !== 'list' || !$owner['first'])
            ) {
                for ($at = count($this->stack) - 1; $at >= 0; $at--) {
                    $entry = $this->stack[$at];
                    if ($entry['kind'] === 'quote' && $entry['column'] === $column && $entry['depth'] === 0) {
                        $owner = $entry;

                        break;
                    }
                }
            }
            $nextLine = $this->lines[$index + 1] ?? '';
            $nextBody = $this->quoteBody($nextLine)[0];
            if (
                $depth > 0 && $depth >= $deepestQuote && $owner === null && !$listInQuote && self::isPlus($body)
                && ($this->lists->parseListItemMarker(ltrim($nextBody, " \t")) === null
                    || $this->lists->parseListItemMarker($nextLine) !== null)
            ) {
                $this->firstBlocks[$index] = ['kind' => 'quote', 'column' => $column, 'depth' => $depth, 'content' => $column, 'first' => true];
            }
            $follower = $this->lines[$index + 1] ?? null;
            if ($follower === '' && $index + 1 === array_key_last($this->lines)) {
                $follower = null;
            }
            $followerColumn = PHP_INT_MAX;
            $followerBody = '';
            $followerDepth = 0;
            if ($follower !== null) {
                if (LayoutWork::$on) {
                    LayoutWork::$markerFollowerProbes++;
                }
                [$followerBody, $followerDepth, $followerPrefix] = $this->quoteBodyAtDepth($follower, $depth);
                if ($followerDepth === $depth) {
                    $followerColumn = self::columnAt($follower, $followerPrefix + strspn($followerBody, " \t"));
                }
            }
            $reason = $owner === null ? 'wrong-column' : ($follower === null ? 'document' : null);
            $quoteBeyondOwner = false;
            if ($owner !== null && ($owner['kind'] === 'description' || $owner['kind'] === 'footnote')) {
                foreach ($this->stack as $entry) {
                    if ($entry['kind'] === 'quote' && $entry['column'] > $owner['column']) {
                        $quoteBeyondOwner = true;

                        break;
                    }
                }
            }
            if ($reason === null) {
                if (trim($followerBody) === '') {
                    $reason = 'blank';
                } elseif ($followerColumn < $column) {
                    $reason = 'left';
                } elseif ($followerColumn > $column) {
                    $reason = 'right';
                } elseif (
                    $this->lists->parseListItemMarker(ltrim($followerBody, " \t")) !== null
                    && ($owner['kind'] === 'list' || $owner['kind'] === 'quote' && $depth === 0 && $column > 0
                        || $owner['kind'] === 'description' && $this->descriptionLazy || $quoteBeyondOwner)
                ) {
                    $reason = 'sibling';
                }
            }
            $stale = $owner !== null && isset($this->closedOwners[$owner['kind'] . ':' . $owner['depth'] . ':' . $owner['column']]);
            $open = $this->stack !== [];
            $this->decisions[$index] = compact('owner', 'reason', 'column', 'depth', 'stale', 'open');
            if ($owner !== null && $reason === null) {
                $this->carriedOwner = $owner;
            }
            $this->paragraphOpen = $reason !== null;

            return;
        }
        if (trim($body) === '') {
            $this->carriedOwner = null;
            $this->paragraphOpen = false;
            $this->descriptionLazy = false;
            $this->stack = array_values(array_filter($this->stack, static fn (array $entry): bool => $entry['depth'] <= $depth));
            $quoteStart = $this->quoteStart($line);
            $rest = substr($line, $quoteStart);
            $quoteColumn = self::columnAt($line, $quoteStart);
            for ($level = 1; $level <= min($depth, BlockGrammar::MAX_NESTING_DEPTH + 1); $level++) {
                $next = ContainerPrefix::quoteContent($rest);
                if ($next === null) {
                    break;
                }
                $this->pushQuote($quoteColumn, $level - 1);
                $quoteColumn = self::columnAt($rest, strlen($rest) - strlen($next), $quoteColumn);
                $this->pushQuote($quoteColumn, $level);
                $rest = $next;
            }

            return;
        }
        $first = $this->firstBlocks[$index - 1] ?? null;
        if ($first !== null && $column === $first['column'] && $this->lists->parseListItemMarker(ltrim($body, " \t")) === null) {
            $this->carriedOwner = $first;
        }
        if (
            $this->carriedOwner !== null && ($column < $this->carriedOwner['column']
            || $column === $this->carriedOwner['column'] && $this->carriedOwner['kind'] !== 'quote'
                && $this->lists->parseListItemMarker(ltrim($body, " \t")) !== null)
        ) {
            $this->carriedOwner = null;
        }
        if ($this->carriedOwner === null) {
            $this->stack = array_values(array_filter($this->stack, static fn (array $entry): bool => $entry['kind'] !== 'quote'));
        }
        if (
            $this->paragraphOpen && $this->stack !== [] && $column < $this->stack[array_key_last($this->stack)]['column']
            && $this->lists->parseListItemMarker(ltrim($body, " \t")) === null
            && !str_starts_with(ltrim($body, " \t"), '#')
            && !str_starts_with(ltrim($body, " \t"), '>')
        ) {
            return;
        }
        $head = ltrim($body, " \t");
        if ($this->paragraphOpen && (str_starts_with($head, ':: ') || str_starts_with($head, ': '))) {
            for ($at = count($this->stack) - 1; $at >= 0; $at--) {
                $entry = $this->stack[$at];
                if (
                    $entry['kind'] === 'list' && $entry['column'] === 0
                    && $column > $entry['column'] && $column < $entry['content']
                ) {
                    return;
                }
            }
        }
        if (
            $this->paragraphOpen && ($this->lists->parseListItemMarker($head) !== null || str_starts_with($head, '>'))
        ) {
            for ($at = count($this->stack) - 1; $at >= 0; $at--) {
                $entry = $this->stack[$at];
                if (
                    $entry['kind'] === 'description' && $column >= $entry['column']
                    && ($this->lists->parseListItemMarker($head) !== null
                        || $entry['column'] === 0 && $column < $entry['content'])
                ) {
                    $this->descriptionLazy = $this->descriptionLazy || str_starts_with($head, '>');

                    return;
                }
            }
        }
        if (str_starts_with($head, ':: ') || str_starts_with($head, ': ') || str_starts_with($head, '[^')) {
            foreach ($this->stack as $entry) {
                if ($entry['kind'] === 'list' && $column > $entry['column'] && $column < $entry['content']) {
                    $this->closedOwners['list:' . $entry['depth'] . ':' . $entry['column']] = true;
                }
            }
        }
        while ($this->stack !== []) {
            $last = $this->stack[array_key_last($this->stack)];
            if ($this->carriedOwner !== null && $last === $this->carriedOwner) {
                break;
            }
            if ($last['depth'] < $depth || ($last['depth'] === $depth && $last['column'] < $column)) {
                break;
            }
            array_pop($this->stack);
        }
        $quoteStart = $this->quoteStart($line);
        $rest = substr($line, $quoteStart);
        $quoteColumn = self::columnAt($line, $quoteStart);
        for ($level = 1; $level <= min($depth, BlockGrammar::MAX_NESTING_DEPTH + 1); $level++) {
            $next = ContainerPrefix::quoteContent($rest);
            if ($next === null) {
                break;
            }
            $this->pushQuote($quoteColumn, $level - 1);
            $quoteColumn = self::columnAt($rest, strlen($rest) - strlen($next), $quoteColumn);
            $this->pushQuote($quoteColumn, $level);
            $rest = $next;
        }
        for ($at = count($this->stack) - 1; $at >= 0; $at--) {
            $entry = $this->stack[$at];
            if ($entry['kind'] !== 'list' || $entry['depth'] !== $depth) {
                continue;
            }
            if (
                $column > $entry['column'] && $column < $entry['content']
                && $this->lists->markerHeadAt(ltrim($body, " \t")) !== null
            ) {
                return;
            }

            break;
        }
        $inner = ltrim($body, " \t");
        $innerColumn = $column;
        $openedListHere = false;
        while ($inner !== '') {
            $head = $this->lists->markerHeadAt($inner);
            if ($head !== null) {
                $openedListHere = true;
                $width = self::columnAt($inner, $head['content'], $innerColumn) - $innerColumn;
                $content = substr($inner, $head['content']);
                $contentWidth = $head['name'] === 'task' ? 2 + $head['attrs'] : $width;
                $this->stack[] = ['kind' => 'list', 'column' => $innerColumn, 'depth' => $depth, 'content' => $innerColumn + $contentWidth, 'first' => self::isPlus($content)];
                unset($this->closedOwners['list:' . $depth . ':' . $innerColumn]);
                if (self::isPlus($content)) {
                    $this->firstBlocks[$index] = $this->stack[array_key_last($this->stack)];
                }
                $innerColumn += $width;
                $inner = ltrim($content, " \t");

                continue;
            }
            $quoted = ContainerPrefix::quoteContent($inner);
            if ($quoted !== null) {
                if ($openedListHere || $depth === 0) {
                    $this->stack[] = ['kind' => 'quote', 'column' => $innerColumn, 'depth' => $depth, 'content' => $innerColumn, 'first' => false];
                }
                $innerColumn = self::columnAt($inner, strlen($inner) - strlen($quoted), $innerColumn);
                $depth++;
                $this->stack[] = ['kind' => 'quote', 'column' => $innerColumn, 'depth' => $depth, 'content' => $innerColumn, 'first' => self::isPlus($quoted)];
                if (self::isPlus($quoted)) {
                    $this->firstBlocks[$index] = $this->stack[array_key_last($this->stack)];
                }
                $inner = ltrim($quoted, " \t");

                continue;
            }
            if (str_starts_with($inner, ': ')) {
                $this->descriptionLazy = false;
                $bodyOffset = 1 + strspn(substr($inner, 1), " \t");
                $content = ltrim(substr($inner, $bodyOffset), " \t");
                $first = self::isPlus($content);
                $bodyColumn = self::columnAt($inner, $bodyOffset, $innerColumn);
                $this->stack[] = ['kind' => 'description', 'column' => $innerColumn, 'depth' => $depth, 'content' => $bodyColumn, 'first' => $first];
                if ($first) {
                    $this->firstBlocks[$index] = $this->stack[array_key_last($this->stack)];
                }
                $innerColumn = $bodyColumn;
                $inner = $content;

                continue;
            }
            if (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $inner) === 1) {
                $markerEnd = strpos($inner, ']:');
                if ($markerEnd !== false) {
                    $bodyOffset = $markerEnd + 2 + strspn(substr($inner, $markerEnd + 2), " \t");
                    $content = ltrim(substr($inner, $bodyOffset), " \t");
                    $first = self::isPlus($content);
                    $bodyColumn = self::columnAt($inner, $bodyOffset, $innerColumn);
                    $this->stack[] = ['kind' => 'footnote', 'column' => $innerColumn, 'depth' => $depth, 'content' => $bodyColumn, 'first' => $first];
                    if ($first) {
                        $this->firstBlocks[$index] = $this->stack[array_key_last($this->stack)];
                    }
                    $innerColumn = $bodyColumn;
                    $inner = $content;

                    continue;
                }
            }

            break;
        }
        $this->paragraphOpen = $inner !== '' && !str_starts_with($inner, '```')
            && !str_starts_with($inner, '~~~') && !str_starts_with($inner, '#')
            && !str_starts_with($inner, '%%');
    }

    private function pushQuote(int $column, int $depth): void
    {
        foreach ($this->stack as $entry) {
            if ($entry['kind'] === 'quote' && $entry['column'] === $column && $entry['depth'] === $depth) {
                return;
            }
        }

        $this->stack[] = ['kind' => 'quote', 'column' => $column, 'depth' => $depth, 'content' => $column, 'first' => false];
    }

    /**
     * @return array{string, int, int}
     */
    private function quoteBody(string $line): array
    {
        $at = $this->quoteStart($line);
        $end = strlen($line);
        $depth = 0;
        while (($width = ContainerPrefix::quoteMarkerWidth($line, $at, $end)) !== null) {
            $at += $width;
            $depth++;
            $at = $this->hostedQuoteStart($line, $at, $depth);
        }

        return [substr($line, $at), $depth, $at];
    }

    /**
     * @return array{string, int, int}
     */
    private function quoteBodyAtDepth(string $line, int $target): array
    {
        $at = $this->quoteStart($line);
        $end = strlen($line);
        $depth = 0;
        while ($depth < $target && ($width = ContainerPrefix::quoteMarkerWidth($line, $at, $end)) !== null) {
            $at += $width;
            $depth++;
            if ($depth < $target) {
                $at = $this->hostedQuoteStart($line, $at, $depth);
            }
        }

        return [substr($line, $at), $depth, $at];
    }

    /**
     * Inside a quote, a `>` indented to an item's or a description's content column opens a quote in that host.
     */
    private function hostedQuoteStart(string $line, int $at, int $depth): int
    {
        $indent = strspn($line, " \t", $at);
        if ($indent === 0 || ($line[$at + $indent] ?? '') !== '>') {
            return $at;
        }
        $column = self::columnAt($line, $at + $indent);
        foreach ($this->stack as $entry) {
            if (
                ($entry['kind'] === 'list' || $entry['kind'] === 'description')
                && $entry['depth'] === $depth && $entry['content'] === $column
            ) {
                return $at + $indent;
            }
        }

        return $at;
    }

    private function quoteStart(string $line): int
    {
        $start = strspn($line, " \t");
        if ($start === 0 || ($line[$start] ?? '') !== '>') {
            return 0;
        }
        $column = self::columnAt($line, $start);
        foreach ($this->stack as $entry) {
            if (($entry['kind'] === 'list' || $entry['kind'] === 'description') && $entry['content'] === $column) {
                return $start;
            }
        }

        return 0;
    }

    private static function isPlus(string $line): bool
    {
        return str_starts_with($line, '+') && trim(substr($line, 1), " \t") === '';
    }

    public static function columnAt(string $line, int $offset, int $column = 0): int
    {
        for ($at = 0; $at < $offset; $at++) {
            $column += $line[$at] === "\t" ? 4 - $column % 4 : 1;
        }

        return $column;
    }
}
