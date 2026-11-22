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
        $missing = self::missingSiblings($candidate, $comparison['read']);
        $trial = $this->nextTrial($candidate, $comparison['read'], $missing);
        if ($trial !== null && $this->keepTrial($trial)) {
            foreach ($trial as [$path, $offset]) {
                $this->escapes[$path][$offset] = true;
            }
        }
        foreach ($comparison['openers'] as $path => $offsets) {
            foreach ($offsets as $offset => $_) {
                $this->escapes[$path][$offset] = true;
            }
        }
        if ($comparison['openers'] !== [] || $missing !== []) {
            return $comparison['openers'] !== [] || $trial !== null;
        }

        $differences = [];
        self::textDifferences($this->tree, $comparison['read'], '', $differences);
        foreach ($differences as $path => $offsets) {
            foreach ($offsets as $offset => $_) {
                $this->escapes[$path][$offset] = true;
            }
        }

        return $differences !== [];
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
