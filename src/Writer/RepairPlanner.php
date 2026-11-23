<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;

/**
 * The read-back rounds of C2-W-088, including the ordered C2-W-125 trials.
 */
final class RepairPlanner
{
    /**
     * @var array<string, array<int, true>>
     */
    private array $escapes = [];

    /**
     * @var array<string, int>
     */
    private array $attempts = [];

    /**
     * @var array<string, int>
     */
    private array $textOrder = [];

    /**
     * @var array<string, array<int, true>>
     */
    private array $trialEscapes = [];

    private int $read = 0;

    /**
     * C2-W-088: whether a round escapes every potential opener of a reported kind, or the reported openers only.
     */
    private bool $kindRounds = true;

    /**
     * @var array<string, array<string, true>> The kinds already escaped, by leaf path.
     */
    private array $kindsDone = [];

    /**
     * @var array<string>
     */
    private const INLINE_CONTAINERS = [
        'superscript', 'subscript', 'emphasis', 'strong', 'underline', 'strike', 'highlight', 'insert', 'delete',
        'link', 'image', 'span', 'inline_footnote', 'inline_extension', 'citation', 'abbreviation', 'quote',
    ];

    /**
     * @var array<string> The nodes whose content a `]` closes.
     */
    private const BRACKETED = ['link', 'span', 'inline_footnote', 'inline_extension'];

    private int $limit;

    /**
     * The fingerprint of the tree every round compares its read-back with.
     */
    private string $treePrint;

    /**
     * The planner a content probe runs for: its character budget is the one charged.
     */
    private ?self $parent = null;

    /**
     * In a content probe, the type of the probed node.
     */
    private ?string $probed = null;

    // The rounds of one content probe, as MAX_PROBE_ROUNDS in carve-js.
    /**
     * @var int
     */
    private const MAX_PROBE_ROUNDS = 32;

    /**
     * @param array<mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $seed The escapes of the local write, which the rounds go on from.
     */
    public function __construct(private array $tree, private ?array $layout, private array $options = [], array $seed = [])
    {
        foreach ($seed as $path => $offsets) {
            $value = self::leafValue($tree, $path);
            foreach ($offsets as $at => $_) {
                // A backslash before punctuation is doubled by the bare write itself.
                if ($value !== null && !($value[$at] === '\\' && Text::isPunct($value[$at + 1] ?? ''))) {
                    $this->escapes[$path][$at] = true;
                }
            }
        }
        $encoded = json_encode($tree, JSON_INVALID_UTF8_SUBSTITUTE);
        $allowance = $encoded === false ? 0 : strlen($encoded) * 4;
        $this->limit = max(24_000_000, 1000 * $allowance);
        self::textOrder($tree, '', $this->textOrder);
        $this->treePrint = TreeEquality::fingerprint($tree);
    }

    /**
     * C2-W-133: null means the repair could not settle inside the existing character budget.
     */
    public function repair(): ?string
    {
        $seen = [];
        for ($round = 0; $round < 8192; $round++) {
            $candidate = Writer::bareCandidate($this->tree, $this->layout, $this->options, $this->escapes);
            $source = $candidate['source'];
            if (!$this->charge($source)) {
                return null;
            }
            $key = hash('xxh3', $source . "\0" . serialize($this->attempts));
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $comparison = ReadbackRound::inspect($candidate, $this->tree, $this->options, $this->treePrint);
            if ($comparison['equal']) {
                return $source;
            }
            if (!$this->applyRound($candidate, $comparison)) {
                return null;
            }
            // The next round's write and parse do not need this round's candidate and read-back.
            unset($candidate, $comparison);
        }

        return null;
    }

    /**
     * The characters the rounds have read so far, the measure of C2-W-133.
     */
    public function charactersRead(): int
    {
        return $this->read;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * } $comparison
     */
    private function applyRound(array $candidate, array $comparison): bool
    {
        $asked = CompareRound::inspect($this->tree, $comparison['read'], $this->escapes, $candidate, $this->probed);
        $missing = self::missingSiblings($candidate, $comparison['read']);
        $trial = $this->nextTrial($candidate, $comparison['read'], $missing);
        if ($trial !== null && $this->keepTrial($trial)) {
            foreach ($trial as [$path, $offset]) {
                $this->addEscape($path, $offset);
                $this->trialEscapes[$path][$offset] = true;
            }
        }
        $this->addAsked($comparison['openers']);
        if ($this->kindRounds && $comparison['openers'] !== []) {
            // The kind escapes go first; a closer escape CompareRound asks for (C2-W-125) gives no kind and waits for a
            // later round, which asks for it again only if the kinds did not make it needless.
            $this->escapeKinds($comparison['openers']);
            $this->escapePartnerClosers();
        } else {
            $this->addAsked($asked);
        }
        $moved = $this->probeMoved($candidate, $comparison['moved']);
        $moved = $this->ownerMoved($comparison['moved']) || $moved;
        if ($comparison['openers'] !== [] || $missing !== [] || $asked !== []) {
            return $comparison['openers'] !== [] || $trial !== null || $asked !== [] || $moved;
        }

        $differences = [];
        self::textDifferences($this->tree, $comparison['read'], '', $differences);
        $this->addAsked($differences);

        return $differences !== [] || $moved;
    }

    /**
     * C2-W-088: each reported opener's kind escapes every potential opener of that kind in its leaf block.
     *
     * @param array<string, array<int, true>> $reported
     */
    private function escapeKinds(array $reported): void
    {
        foreach ($reported as $path => $offsets) {
            $value = self::nodeAt($this->tree, $path)['value'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $leaf = $this->leafOf($path);
            foreach ($offsets as $offset => $_) {
                $kind = self::kindAt($value, $offset);
                if ($kind === null || isset($this->kindsDone[$leaf][$kind])) {
                    continue;
                }
                $this->kindsDone[$leaf][$kind] = true;
                foreach ($this->leafTexts()[$leaf] ?? [] as $text) {
                    $other = self::nodeAt($this->tree, $text)['value'] ?? null;
                    if (is_string($other)) {
                        foreach (self::kindOffsets($kind, $other) as $at) {
                            $this->addEscape($text, $at);
                        }
                    }
                }
            }
        }
    }

    /**
     * C2-W-125, case five: a `[` escaped in the content of a bracketed node leaves the `]` that closes it in the text
     * bare, which would end that node early, so that `]` takes its backslash in the same round.
     */
    private function escapePartnerClosers(): void
    {
        $pending = [['', $this->tree]];
        while ($pending !== []) {
            [$path, $node] = array_pop($pending);
            if (!in_array($node['type'] ?? '', self::BRACKETED, true)) {
                foreach (self::childrenOf($node) as $index => $child) {
                    $pending[] = [$path . '/children/' . $index, $child];
                }

                continue;
            }
            $open = [];
            $content = [];
            foreach (array_reverse(self::childrenOf($node), true) as $index => $child) {
                $content[] = [$path . '/children/' . $index, $child];
            }
            while ($content !== []) {
                [$at, $child] = array_pop($content);
                $type = $child['type'] ?? '';
                if (in_array($type, self::BRACKETED, true)) {
                    $pending[] = [$at, $child];
                } elseif ($type === 'text') {
                    $this->pairBrackets($at, is_string($child['value'] ?? null) ? $child['value'] : '', $open);
                } else {
                    foreach (array_reverse(self::childrenOf($child), true) as $index => $inner) {
                        $content[] = [$at . '/children/' . $index, $inner];
                    }
                }
            }
        }
    }

    /**
     * @param string $path
     * @param string $value
     * @param array<array{string, int}> $open The `[` of the content not yet closed.
     */
    private function pairBrackets(string $path, string $value, array &$open): void
    {
        $n = strlen($value);
        for ($i = strcspn($value, '[]'); $i < $n; $i += 1 + strcspn($value, '[]', $i + 1)) {
            if ($value[$i] === '[') {
                $open[] = [$path, $i];

                continue;
            }
            $partner = array_pop($open);
            if ($partner !== null && isset($this->escapes[$partner[0]][$partner[1]])) {
                $this->addEscape($path, $i);
            }
        }
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<int, array<mixed>>
     */
    private static function childrenOf(array $node): array
    {
        return array_filter(is_array($node['children'] ?? null) ? $node['children'] : [], 'is_array');
    }

    /**
     * @var array<string, array<string>>|null The text paths of each leaf block, in source order.
     */
    private ?array $leafTexts = null;

    /**
     * @return array<string, array<string>>
     */
    private function leafTexts(): array
    {
        if ($this->leafTexts === null) {
            $this->leafTexts = [];
            foreach ($this->textOrder as $text => $_order) {
                $this->leafTexts[$this->leafOf($text)][] = $text;
            }
        }

        return $this->leafTexts;
    }

    private function leafOf(string $path): string
    {
        while (($cut = strrpos($path, '/children/')) !== false) {
            $path = substr($path, 0, $cut);
            $type = self::nodeAt($this->tree, $path)['type'] ?? null;
            if (!in_array($type, self::INLINE_CONTAINERS, true)) {
                return $path;
            }
        }

        return $path;
    }

    private static function kindAt(string $value, int $offset): ?string
    {
        $ch = $value[$offset] ?? '';
        if ($ch === '' || !ctype_punct($ch)) {
            return null;
        }
        if ($ch === '{' && ctype_punct($value[$offset + 1] ?? 'a')) {
            return '{' . $value[$offset + 1];
        }

        return $ch === '-' ? '--' : $ch;
    }

    /**
     * C2-W-088: the potential openers of a kind in a text: the first character of each run of its characters, the
     * brace of a braced opener, the second hyphen of a hyphen run, and a `/` or `*` its neighbors let open.
     *
     * @return array<int>
     */
    private static function kindOffsets(string $kind, string $value): array
    {
        $offsets = [];
        $n = strlen($value);
        $ch = $kind[0];
        $first = $ch === '{' ? '{' : ($kind === '--' ? '-' : $ch);
        for ($i = strpos($value, $first); $i !== false && $i < $n; $i = strpos($value, $value[$i], $i + 1)) {
            $prev = $value[$i - 1] ?? '';
            $open = match (true) {
                strlen($kind) === 2 && $ch === '{' => ($value[$i + 1] ?? '') === $kind[1] && $prev !== '{',
                $kind === '--' => $prev === '-' && ($value[$i - 2] ?? '') !== '-' && ($value[$i + 1] ?? '') !== '',
                $ch === '/' || $ch === '*' => $prev !== $ch && ($value[$i + 1] ?? '') !== $ch
                    && !ctype_alnum($prev) && !in_array($value[$i + 1] ?? ' ', [' ', "\t", "\n"], true),
                default => $prev !== $ch,
            };
            if ($open) {
                $offsets[] = $i;
            }
        }

        return $offsets;
    }

    private function addEscape(string $path, int $offset): bool
    {
        if (isset($this->escapes[$path][$offset])) {
            return false;
        }
        $this->escapes[$path][$offset] = true;

        return true;
    }

    /**
     * @param array<string, array<int, true>> $asked
     */
    private function addAsked(array $asked): bool
    {
        $added = false;
        foreach ($asked as $path => $offsets) {
            foreach ($offsets as $offset => $_) {
                $added = $this->addEscape($path, $offset) || $added;
            }
        }

        return $added;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<int, array{string, string, int, int, int}> $moved
     */
    private function probeMoved(array $candidate, array $moved): bool
    {
        $added = false;
        $spans = $moved === [] ? [] : self::spans($candidate);
        foreach ($moved as $position => [$path, , , $end, $readEnd]) {
            if ($position === 0) {
                continue;
            }
            if ($readEnd <= $end) {
                continue;
            }
            foreach ($this->probeAcrossCloser($candidate, $spans, $path, $end) as [$owner, $offset]) {
                $added = $this->addEscape($owner, $offset) || $added;
            }
        }

        return $added;
    }

    /**
     * @param array<int, array{string, string, int, int, int}> $moved
     */
    private function ownerMoved(array $moved): bool
    {
        [$path, , , $end, $readEnd] = $moved[0] ?? [null, '', 0, 0, 0];
        if ($path === null || $readEnd <= $end || !self::hasLaterOverrun($moved, $path)) {
            return false;
        }
        $parts = explode('/', $path);
        $index = (int)array_pop($parts);
        $nextPath = implode('/', $parts) . '/' . ($index + 1);
        $next = self::nodeAt($this->tree, $nextPath);
        $owner = self::nodeAt($this->tree, $path);
        if ($next === null || $owner === null) {
            return false;
        }
        $mark = self::firstMark($next, $nextPath, self::closer($owner), $this->escapes);

        return $mark !== null && $this->addEscape($mark[0], $mark[1]);
    }

    /**
     * @param array<int, array{string, string, int, int, int}> $moved
     * @param string $path
     */
    private static function hasLaterOverrun(array $moved, string $path): bool
    {
        $parent = substr($path, 0, (int)strrpos($path, '/'));
        foreach (array_slice($moved, 1) as [$otherPath, , , $otherEnd, $otherReadEnd]) {
            if ($otherReadEnd > $otherEnd && substr($otherPath, 0, (int)strrpos($otherPath, '/')) === $parent) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param array<array{string, string}> $missing
     *
     * @return array<array{string, int}>|null
     */
    private function nextTrial(array $candidate, array $actual, array $missing): ?array
    {
        foreach ($missing as [$path, $prior]) {
            $step = $this->attempts[$path] ?? 0;
            $trial = $this->trial($candidate, $actual, $path, $prior, $step);
            $this->attempts[$path] = $step;
            if ($trial !== null) {
                return $trial;
            }
        }

        return null;
    }

    /**
     * @param array<array{string, int}> $trial
     */
    private function keepTrial(array $trial): bool
    {
        if (count($trial) > 4) {
            return true;
        }
        foreach ($trial as [$path, $offset]) {
            if ($this->trialChangesTree($path, $offset)) {
                return true;
            }
        }

        return false;
    }

    private function charge(string $source): bool
    {
        if ($this->parent !== null) {
            return $this->parent->charge($source);
        }
        $this->read += strlen($source);

        return $this->read <= $this->limit;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     *
     * @return array<array{string, string}>
     */
    private static function missingSiblings(array $candidate, array $actual): array
    {
        $starts = [];
        self::actualSpans($actual, null, $starts);
        $nodes = [];
        foreach ($candidate['nodes'] as [$path, $type, $start, $end]) {
            $nodes[$path] = [$type, $start, $end];
        }
        $missing = [];
        foreach ($nodes as $path => [$type, $start]) {
            $parts = explode('/', $path);
            $index = (int)array_pop($parts);
            $parent = implode('/', $parts);
            $prior = $parent . '/' . ($index - 1);
            if ($index > 0 && isset($nodes[$prior])) {
                [$priorType, $priorStart, $priorEnd] = $nodes[$prior];
                if (($starts[$priorType . ':' . $priorStart][0] ?? $priorEnd) > $start) {
                    $missing[] = [$path, $prior];

                    continue;
                }
            }
            if (!isset($nodes[$parent], $starts[$type . ':' . $start])) {
                continue;
            }
            [$parentType, $parentStart] = $nodes[$parent];
            if ($starts[$type . ':' . $start][1] !== [$parentType, $parentStart]) {
                $missing[] = [$path, $prior];
            }
        }

        return $missing;
    }

    /**
     * @param array<mixed> $node
     * @param array{string, int}|null $parent
     * @param array<string, array{int, array{string, int}|null}> $starts
     */
    private static function actualSpans(array $node, ?array $parent, array &$starts): void
    {
        $pos = is_array($node['pos'] ?? null) ? $node['pos'] : null;
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        if ($pos !== null && isset($pos[0], $pos[1])) {
            $start = (int)$pos[0];
            $starts[$type . ':' . $start] = [(int)$pos[1], $parent];
            $parent = [$type, $start];
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                self::actualSpans($child, $parent, $starts);
            }
        }
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param string $prior
     * @param string $path
     * @param int $step
     *
     * @return array<array{string, int}>|null
     */
    private function trial(array $candidate, array $actual, string $path, string $prior, int &$step): ?array
    {
        $node = self::nodeAt($this->tree, $path);
        if ($node === null) {
            return null;
        }
        $parts = explode('/', $path);
        $index = (int)array_pop($parts);
        $parent = implode('/', $parts);
        while ($step < 14) {
            $case = $step++;
            if ($case === 3 || $case === 4) {
                $probed = $case === 3
                    ? $this->contentConstruct($path, $node)
                    : self::acrossCloser($candidate, $actual, $path, $this->escapes);
                if ($probed !== []) {
                    return $probed;
                }

                continue;
            }
            $chosen = match ($case) {
                0, 11 => self::endEscape(self::lastText(self::nodeAt($this->tree, $prior), $prior), $this->escapes),
                5, 12 => self::endEscape(self::lastText($node, $path), $this->escapes),
                7 => self::after($this->tree, $parent . '/' . ($index + 1), $this->escapes),
                13 => self::firstMark($node, $path, self::closer($node), $this->escapes),
                default => null,
            };
            if ($chosen !== null) {
                return [$chosen];
            }
        }

        return null;
    }

    /**
     * C2-W-088: the content of the node at `path`, written and parsed on its own, holds a construct that the tree does
     * not, as a closer of an inner node that reads early. The escapes its own rounds ask for are this trial. A content
     * that opens a block is read again behind a word, then with its line breaks as spaces (carve-js
     * `escapeContentConstruct`).
     *
     * @param string $path
     * @param array<mixed> $node
     *
     * @return array<array{string, int}>
     */
    private function contentConstruct(string $path, array $node): array
    {
        $children = is_array($node['children'] ?? null) ? array_values($node['children']) : [];
        if ($children === [] || !in_array($node['type'] ?? '', self::INLINE_CONTAINERS, true)) {
            return [];
        }
        $word = ['type' => 'text', 'value' => 'x'];
        $flat = [];
        $kept = [];
        foreach ($children as $index => $child) {
            $break = is_array($child) && ($child['type'] ?? '') === 'soft_break';
            $flat[] = $break ? ['type' => 'text', 'value' => ' '] : $child;
            if (!$break) {
                $kept[$index] = true;
            }
        }
        $all = array_fill_keys(array_keys($children), true);
        $first = is_array($children[0]) ? ($children[0]['type'] ?? '') : '';
        $variants = [[[$word, ...$children], 1, $all], [[$word, ...$flat], 1, $kept]];
        if ($first !== 'soft_break' && $first !== 'hard_break') {
            array_unshift($variants, [$children, 0, $all]);
        }
        foreach ($variants as [$inlines, $shift, $mapped]) {
            $found = $this->probeContent($path, $inlines, $shift, $mapped);
            if ($found !== null) {
                return $found;
            }
        }

        return [];
    }

    /**
     * One variant of a content probe: null where its first parse is no single paragraph, which tells nothing.
     *
     * @param string $path
     * @param array<mixed> $inlines
     * @param int $shift
     * @param array<int, true> $mapped The children of `path` that stand in the probe as themselves.
     *
     * @return array<array{string, int}>|null
     */
    private function probeContent(string $path, array $inlines, int $shift, array $mapped): ?array
    {
        $prefix = $path . '/children/';
        $inside = '/children/0/children/';
        $probe = new self(['type' => 'document', 'children' => [['type' => 'paragraph', 'children' => $inlines]]], null, $this->options);
        $probe->parent = $this;
        $probe->kindRounds = false;
        $type = self::nodeAt($this->tree, $path)['type'] ?? null;
        $probe->probed = is_string($type) ? $type : null;
        $probe->escapes = self::moveEscapes($this->escapes, $prefix, $inside, $shift, $mapped);
        if (!$probe->probeRounds()) {
            return null;
        }
        $found = [];
        foreach (self::moveEscapes($probe->escapes, $inside, $prefix, -$shift, $mapped, true) as $original => $offsets) {
            foreach ($offsets as $at => $_) {
                if (!isset($this->escapes[$original][$at])) {
                    $found[] = [$original, $at];
                }
            }
        }

        return $found;
    }

    /**
     * The rounds of a content probe; false where its first parse is no single paragraph.
     */
    private function probeRounds(): bool
    {
        for ($round = 0; $round < self::MAX_PROBE_ROUNDS; $round++) {
            $candidate = Writer::bareCandidate($this->tree, null, $this->options, $this->escapes);
            if (!$this->charge($candidate['source'])) {
                return true;
            }
            $comparison = ReadbackRound::inspect($candidate, $this->tree, $this->options, $this->treePrint);
            $blocks = is_array($comparison['read']['children'] ?? null) ? $comparison['read']['children'] : [];
            if ($round === 0 && (count($blocks) !== 1 || !is_array($blocks[0]) || ($blocks[0]['type'] ?? '') !== 'paragraph')) {
                return false;
            }
            if ($comparison['equal'] || !$this->applyRound($candidate, $comparison)) {
                return true;
            }
        }

        return true;
    }

    /**
     * The escapes below `from` moved below `to`, each child index shifted by `shift`. Only the children in `mapped`
     * (by their index below the content of the probed node) move.
     *
     * @param array<string, array<int, true>> $escapes
     * @param string $from
     * @param string $to
     * @param int $shift
     * @param array<int, true> $mapped
     * @param bool $back
     *
     * @return array<string, array<int, true>>
     */
    private static function moveEscapes(
        array $escapes,
        string $from,
        string $to,
        int $shift,
        array $mapped,
        bool $back = false,
    ): array {
        $moved = [];
        foreach ($escapes as $owner => $offsets) {
            if (!str_starts_with($owner, $from)) {
                continue;
            }
            $rest = substr($owner, strlen($from));
            $cut = strpos($rest, '/');
            $index = (int)($cut === false ? $rest : substr($rest, 0, $cut));
            if (isset($mapped[$back ? $index + $shift : $index])) {
                $moved[$to . ($index + $shift) . ($cut === false ? '' : substr($rest, $cut))] = $offsets;
            }
        }

        return $moved;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     */
    private static function leafValue(array $tree, string $path): ?string
    {
        $node = self::nodeAt($tree, $path);

        return is_string($node['value'] ?? null) ? $node['value'] : null;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     *
     * @return array<mixed>|null
     */
    private static function nodeAt(array $tree, string $path): ?array
    {
        $node = $tree;
        foreach (explode('/', trim($path, '/')) as $part) {
            if (!is_array($node[$part] ?? null)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * @param array<mixed>|null $node
     * @param string $path
     *
     * @return array{string, string}|null
     */
    private static function lastText(?array $node, string $path): ?array
    {
        if ($node === null) {
            return null;
        }
        if (($node['type'] ?? '') === 'text') {
            return [$path, is_string($node['value'] ?? null) ? $node['value'] : ''];
        }
        foreach (array_reverse(is_array($node['children'] ?? null) ? $node['children'] : [], true) as $index => $child) {
            if (is_array($child)) {
                $last = self::lastText($child, $path . '/children/' . $index);
                if ($last !== null) {
                    return $last;
                }
            }
        }

        return null;
    }

    /**
     * @param array{string, string}|null $last
     * @param array<string, array<int, true>> $escapes
     *
     * @return array{string, int}|null
     */
    private static function endEscape(?array $last, array $escapes): ?array
    {
        if ($last === null) {
            return null;
        }
        [$path, $value] = $last;
        $length = strlen($value);
        if ($length > 0 && str_contains('\\!^$`', $value[$length - 1]) && !isset($escapes[$path][$length - 1])) {
            return [$path, $length - 1];
        }
        $brace = strrpos($value, '{');

        return $brace !== false && !isset($escapes[$path][$brace]) ? [$path, $brace] : null;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array{string, int}|null
     */
    private static function after(array $tree, string $path, array $escapes): ?array
    {
        $node = self::nodeAt($tree, $path);
        $value = $node['value'] ?? null;

        return ($node['type'] ?? '') === 'text' && is_string($value) && $value !== ''
            && Text::isPunct($value[0]) && !isset($escapes[$path][0]) ? [$path, 0] : null;
    }

    /**
     * @param array<mixed> $node
     */
    private static function closer(array $node): string
    {
        return match ($node['type'] ?? '') {
            'superscript' => '^', 'subscript' => ',', 'emphasis' => '/', 'strong' => '*',
            'underline' => '_', 'strike' => '~', 'highlight' => '=', 'insert' => '+', 'delete' => '-',
            'link', 'span', 'inline_footnote', 'inline_extension' => ']',
            default => '',
        };
    }

    /**
     * @param array<mixed> $node
     * @param string $mark
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array{string, int}|null
     */
    private static function firstMark(array $node, string $path, string $mark, array $escapes): ?array
    {
        if ($mark === '') {
            return null;
        }
        if (($node['type'] ?? '') === 'text') {
            $value = is_string($node['value'] ?? null) ? $node['value'] : '';
            for ($at = 0, $length = strlen($value); $at < $length; $at++) {
                if ($value[$at] === $mark && !isset($escapes[$path][$at])) {
                    return [$path, $at];
                }
            }

            return null;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $found = self::firstMark($child, $path . '/children/' . $index, $mark, $escapes);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array<array{string, int}>
     */
    private static function acrossCloser(array $candidate, array $actual, string $path, array $escapes): array
    {
        $span = null;
        foreach ($candidate['nodes'] as [$expectedPath, , $start, $end]) {
            if ($expectedPath === $path) {
                $span = [$start, $end];

                break;
            }
        }
        if ($span === null) {
            return [];
        }
        $starts = [];
        self::actualSpans($actual, null, $starts);
        $endsByStart = [];
        foreach ($starts as $key => [$readEnd]) {
            if (str_starts_with($key, 'text:')) {
                continue;
            }
            $separator = strrpos($key, ':');
            if ($separator !== false) {
                $start = (int)substr($key, $separator + 1);
                $endsByStart[$start] = max($endsByStart[$start] ?? 0, $readEnd);
            }
        }
        $found = [];
        foreach ($candidate['text'] as $position => $code) {
            [$owner, $offset] = [$candidate['paths'][$code >> 32], $code & 0xFFFFFFFF];
            if (
                !str_starts_with($owner, $path . '/') || $position < $span[0] || $position >= $span[1]
                || isset($escapes[$owner][$offset])
            ) {
                continue;
            }
            $mark = $candidate['source'][$position] ?? '';
            if (!Text::isPunct($mark)) {
                continue;
            }
            if (($endsByStart[$position] ?? 0) > $span[1]) {
                $found[] = [$owner, $offset];
            }
        }

        return $found;
    }

    /**
     * A construct inside a paired node may read across its closer only when the content and
     * following inlines are parsed together. Probe that suffix independently so an outer parse
     * does not hide its nested openers. As in C2-W-125, a probe takes at most 32 rounds.
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<string, array{int, int}> $spans
     * @param int $end
     * @param string $path
     *
     * @return array<array{string, int}>
     */
    private function probeAcrossCloser(array $candidate, array $spans, string $path, int $end): array
    {
        $node = self::nodeAt($this->tree, $path);
        if ($node === null || self::closer($node) === '') {
            return [];
        }
        [$probe, $end] = $this->probeCandidate($candidate, $path, $end);
        if ($probe !== $candidate) {
            $candidate = $probe;
            $spans = self::spans($candidate);
        }
        $first = null;
        for ($position = $spans[$path][0] ?? 0, $stop = $spans[$path][1] ?? -1; $position < $stop; $position++) {
            if (str_starts_with(RepairTrace::owner($candidate, $position)[0] ?? '', $path . '/')) {
                $first = $position;

                break;
            }
        }
        if ($first === null) {
            return [];
        }
        $source = 'x ' . substr($candidate['source'], $first, self::acrossEnd($candidate['source'], $spans, $path) - $first);
        if (!$this->charge($source)) {
            return [];
        }
        $read = TreeEncoder::decodedWithOffsets(Carve::parse($source, $this->parseOptions()), $source);

        return $this->probeOpeners($candidate, $read, $path, $end, $first, self::closer($node));
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $read
     * @param string $path
     * @param int $end
     * @param int $first
     * @param string $closer
     *
     * @return array<array{string, int}>
     */
    private function probeOpeners(array $candidate, array $read, string $path, int $end, int $first, string $closer): array
    {
        $found = [];
        $pending = [$read];
        while ($pending !== []) {
            $item = array_pop($pending);
            if (!is_array($item)) {
                continue;
            }
            foreach (is_array($item['children'] ?? null) ? $item['children'] : [] as $child) {
                $pending[] = $child;
            }
            $opener = $this->probeOpener($candidate, $item, $path, $end, $first, $closer);
            if ($opener !== null) {
                [$start, $owned] = $opener;
                $found[$start] = $owned;
            }
        }
        ksort($found);

        return array_slice(array_values($found), 0, 32);
    }

    // The inlines behind a node that a probe across its closer reads, at most (as MAX_ACROSS in carve-js).
    /**
     * @var int
     */
    private const MAX_ACROSS = 16;

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges?: array<string, array{int, int}>
     * } $candidate
     *
     * @return array<string, array{int, int}>
     */
    private static function spans(array $candidate): array
    {
        $spans = $candidate['ranges'] ?? [];
        foreach ($candidate['nodes'] as [$nodePath, , $start, $end]) {
            $spans[$nodePath] = [$start, $end];
        }

        return $spans;
    }

    /**
     * The end of a probe across the closer of `path`: the node, its closer and at most MAX_ACROSS inlines behind it.
     *
     * @param string $source
     * @param array<string, array{int, int}> $spans
     * @param string $path
     */
    private static function acrossEnd(string $source, array $spans, string $path): int
    {
        $cut = (int)strrpos($path, '/');
        $parent = substr($path, 0, $cut);
        $index = (int)substr($path, $cut + 1);
        for ($k = $index + self::MAX_ACROSS; $k >= $index; $k--) {
            $end = $spans[$parent . '/' . $k][1] ?? null;
            if ($end !== null) {
                return $end;
            }
        }

        return strlen($source);
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param int $end
     * @param string $path
     *
     * @return array{
     *     array{
     *         source: string,
     *         text: array<int, int>,
     *         paths: array<int, string>,
     *         nodes: array<int, array{string, string, int, int}>
     *     },
     *     int
     * }
     */
    private function probeCandidate(array $candidate, string $path, int $end): array
    {
        $probeEscapes = $this->escapes;
        $changed = false;
        foreach ($this->trialEscapes as $owner => $offsets) {
            if (!str_starts_with($owner, $path . '/')) {
                continue;
            }
            foreach ($offsets as $at => $_) {
                unset($probeEscapes[$owner][$at]);
                $changed = true;
            }
        }
        if ($changed) {
            $candidate = Writer::bareCandidate($this->tree, $this->layout, $this->options, $probeEscapes);
            foreach ($candidate['nodes'] as [$nodePath, , , $nodeEnd]) {
                if ($nodePath === $path) {
                    $end = $nodeEnd;

                    break;
                }
            }
        }

        return [$candidate, $end];
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $item
     * @param string $closer
     * @param int $first
     * @param int $end
     * @param string $path
     *
     * @return array{int, array{string, int}}|null
     */
    private function probeOpener(array $candidate, array $item, string $path, int $end, int $first, string $closer): ?array
    {
        $pos = is_array($item['pos'] ?? null) ? $item['pos'] : [];
        $startOffset = $pos[0] ?? null;
        $endOffset = $pos[1] ?? null;
        if (!is_int($startOffset) || !is_int($endOffset)) {
            return null;
        }
        $start = $startOffset + $first - 2;
        $finish = $endOffset + $first - 2;
        $owned = RepairTrace::owner($candidate, $start);
        if (
            $owned === null || !str_starts_with($owned[0], $path . '/') || $start >= $end || $finish <= $end
            || !str_contains('[`<{$:', $candidate['source'][$start])
            || $candidate['source'][$start] === $closer || isset($this->escapes[$owned[0]][$owned[1]])
        ) {
            return null;
        }

        return [$start, $owned];
    }

    private function trialChangesTree(string $path, int $offset): bool
    {
        $rank = $this->textOrder[$path] ?? -1;
        $left = [];
        foreach ($this->escapes as $owner => $offsets) {
            $order = $this->textOrder[$owner] ?? -1;
            foreach ($offsets as $at => $_) {
                if ($order < $rank || ($owner === $path && $at < $offset)) {
                    $left[$owner][$at] = true;
                }
            }
        }
        $without = $this->sourceTree($left);
        if ($without === null) {
            return false;
        }
        $left[$path][$offset] = true;
        $with = $this->sourceTree($left);

        return $with !== null && $without !== $with;
    }

    /**
     * The fingerprint of the tree the source written with these escapes reads back as.
     *
     * @param array<string, array<int, true>> $escapes
     *
     * @return string|null
     */
    private function sourceTree(array $escapes): ?string
    {
        $source = Writer::bareCandidate($this->tree, $this->layout, $this->options, $escapes, false)['source'];
        if (!$this->charge($source)) {
            return null;
        }

        return TreeEquality::fingerprint(TreeEncoder::decodedForWriter(Carve::parse($source, $this->parseOptions())));
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOptions(): array
    {
        return isset($this->options['extensions']) ? ['extensions' => $this->options['extensions']] : [];
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param array<string, int> $order
     */
    private static function textOrder(array $node, string $path, array &$order): void
    {
        if (($node['type'] ?? '') === 'text') {
            $order[$path] = count($order);
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                self::textOrder($child, $path . '/children/' . $index, $order);
            }
        }
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed>|null $actual
     * @param string $path
     * @param array<string, array<int, true>> $differences
     */
    private static function textDifferences(array $expected, ?array $actual, string $path, array &$differences): void
    {
        if (($expected['type'] ?? '') === 'text') {
            $at = self::firstTextDifference($expected, $actual);
            if ($at !== null) {
                $differences[$path][$at] = true;
            }

            return;
        }
        if ($actual === null || ($expected['type'] ?? '') !== ($actual['type'] ?? '')) {
            return;
        }
        $actualChildren = is_array($actual['children'] ?? null) ? $actual['children'] : [];
        foreach (is_array($expected['children'] ?? null) ? $expected['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $other = $actualChildren[$index] ?? null;
                self::textDifferences($child, is_array($other) ? $other : null, $path . '/children/' . $index, $differences);
            }
        }
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed>|null $actual
     */
    private static function firstTextDifference(array $expected, ?array $actual): ?int
    {
        $value = is_string($expected['value'] ?? null) ? $expected['value'] : '';
        $other = ($actual['type'] ?? '') === 'text' && is_string($actual['value'] ?? null) ? $actual['value'] : '';
        $at = 0;
        $end = min(strlen($value), strlen($other));
        while ($at < $end && $value[$at] === $other[$at]) {
            $at++;
        }

        return $at < strlen($value) && Text::isPunct($value[$at]) ? $at : null;
    }
}
