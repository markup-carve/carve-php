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

    private int $limit;

    /**
     * @param array<mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     */
    public function __construct(private array $tree, private ?array $layout, private array $options = [])
    {
        $encoded = json_encode($tree, JSON_INVALID_UTF8_SUBSTITUTE);
        $allowance = $encoded === false ? 0 : strlen($encoded) * 4;
        $this->limit = max(24_000_000, 1000 * $allowance);
        self::textOrder($tree, '', $this->textOrder);
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
            $comparison = ReadbackRound::inspect($candidate, $this->tree);
            if ($comparison['equal']) {
                return $source;
            }
            if (!$this->applyRound($candidate, $comparison)) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
     * @param array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * } $comparison
     */
    private function applyRound(array $candidate, array $comparison): bool
    {
        $asked = CompareRound::inspect($this->tree, $comparison['read'], $this->escapes);
        $missing = self::missingSiblings($candidate, $comparison['read']);
        $trial = $this->nextTrial($candidate, $comparison['read'], $missing);
        if ($trial !== null && $this->keepTrial($trial)) {
            foreach ($trial as [$path, $offset]) {
                $this->addEscape($path, $offset);
                $this->trialEscapes[$path][$offset] = true;
            }
        }
        $this->addAsked($comparison['openers']);
        $this->addAsked($asked);
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
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
     * @param array<int, array{string, string, int, int, int}> $moved
     */
    private function probeMoved(array $candidate, array $moved): bool
    {
        $added = false;
        foreach ($moved as $position => [$path, , , $end, $readEnd]) {
            if ($position === 0) {
                continue;
            }
            if ($readEnd <= $end) {
                continue;
            }
            foreach ($this->probeAcrossCloser($candidate, $path, $end) as [$owner, $offset]) {
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
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
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
        $this->read += strlen($source);

        return $this->read <= $this->limit;
    }

    /**
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
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
        if ($pos !== null && isset($pos['startOffset'], $pos['endOffset'])) {
            $start = (int)$pos['startOffset'];
            $starts[$type . ':' . $start] = [(int)$pos['endOffset'], $parent];
            $parent = [$type, $start];
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                self::actualSpans($child, $parent, $starts);
            }
        }
    }

    /**
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
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
            if ($case === 4) {
                $across = self::acrossCloser($candidate, $actual, $path, $this->escapes);
                if ($across !== []) {
                    return $across;
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
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
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
        foreach ($candidate['text'] as $position => [$owner, $offset]) {
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
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
     * @param int $end
     * @param string $path
     *
     * @return array<array{string, int}>
     */
    private function probeAcrossCloser(array $candidate, string $path, int $end): array
    {
        $node = self::nodeAt($this->tree, $path);
        if ($node === null || self::closer($node) === '') {
            return [];
        }
        [$candidate, $end] = $this->probeCandidate($candidate, $path, $end);
        $first = null;
        foreach ($candidate['text'] as $position => [$owner]) {
            if (str_starts_with($owner, $path . '/')) {
                $first = $position;

                break;
            }
        }
        if ($first === null) {
            return [];
        }
        $source = 'x ' . substr($candidate['source'], $first);
        if (!$this->charge($source)) {
            return [];
        }
        $read = TreeEncoder::decodedWithPositions(Carve::parse($source), $source);
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
            $opener = $this->probeOpener($candidate, $item, $path, $end, $first, self::closer($node));
            if ($opener !== null) {
                [$start, $owned] = $opener;
                $found[$start] = $owned;
            }
        }
        ksort($found);

        return array_slice(array_values($found), 0, 32);
    }

    /**
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
     * @param int $end
     * @param string $path
     *
     * @return array{array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>}, int}
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
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
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
        $startOffset = $pos['startOffset'] ?? null;
        $endOffset = $pos['endOffset'] ?? null;
        if (!is_int($startOffset) || !is_int($endOffset)) {
            return null;
        }
        $start = $startOffset + $first - 2;
        $finish = $endOffset + $first - 2;
        $owned = $candidate['text'][$start] ?? null;
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
        $left[$path][$offset] = true;
        $with = $this->sourceTree($left);

        return $without !== null && $with !== null && !TreeEquality::equal($without, $with);
    }

    /**
     * @param array<string, array<int, true>> $escapes
     *
     * @return array<mixed>|null
     */
    private function sourceTree(array $escapes): ?array
    {
        $source = Writer::bareCandidate($this->tree, $this->layout, $this->options, $escapes, false)['source'];
        if (!$this->charge($source)) {
            return null;
        }

        return TreeEncoder::decodedForWriter(Carve::parse($source));
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
