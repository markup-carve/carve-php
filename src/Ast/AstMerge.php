<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;
use SplPriorityQueue;
use stdClass;

final class AstMerge
{
    private static ?stdClass $missing = null;

    private static function missing(): stdClass
    {
        return self::$missing ??= new stdClass();
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     * @param callable(array<string, mixed>): ('base'|'ours'|'theirs'|array{value: mixed}|null)|null $resolve
     *
     * @throws \InvalidArgumentException
     *
     * @return array{ok: true, ast: array<string, mixed>, conflicts: array{}}|array{ok: false, ast: null, conflicts: list<array<string, mixed>>}
     */
    public static function merge(array $base, array $ours, array $theirs, ?callable $resolve = null): array
    {
        $conflicts = [];
        if (json_encode(self::clean($ours), JSON_THROW_ON_ERROR) === json_encode(self::clean($theirs), JSON_THROW_ON_ERROR)) {
            $merged = $ours;
        } else {
            $index = new AstStructuralIndex();
            $oursIndex = $index->build($ours);
            $theirsIndex = $index->build($theirs);
            $baseIndex = $index->build($base);
            $merged = self::mergeValue($baseIndex, $oursIndex, $theirsIndex, new AstMergePath(), $conflicts, $resolve);
            unset($index, $baseIndex, $oursIndex, $theirsIndex);
        }
        if ($merged === self::missing() || $conflicts !== []) {
            return ['ok' => false, 'ast' => null, 'conflicts' => $conflicts];
        }

        /** @var array<string, mixed> $ast */
        $ast = self::clean($merged);
        if (($ast['type'] ?? null) !== 'document' || !isset($ast['children']) || !is_array($ast['children'])) {
            throw new InvalidArgumentException('Merge result is not a PART 12 document root.');
        }
        $ast['srcByteLength'] = 0;
        (new AstCodec())->decode($ast);

        return ['ok' => true, 'ast' => $ast, 'conflicts' => []];
    }

    private static function clean(mixed $value, bool $stripMetadata = true): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $child) {
            if ($stripMetadata && ($key === 'pos' || $key === 'srcByteLength')) {
                continue;
            }
            $out[$key] = self::clean($child, $stripMetadata && $key !== 'keyValues');
        }

        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    private static function absent(): AstIndexedValue
    {
        return new AstIndexedValue(self::missing(), -1);
    }

    /**
     * @return array<string, mixed>
     */
    private static function conflictItem(string $reason, AstMergePath $path, mixed $base, mixed $ours, mixed $theirs): array
    {
        $item = [
            'path' => $path->pointer(),
            'reason' => $reason,
            'base' => $base === self::missing() ? null : $base,
            'ours' => $ours === self::missing() ? null : $ours,
            'theirs' => $theirs === self::missing() ? null : $theirs,
        ];
        if (in_array(self::missing(), [$base, $ours, $theirs], true)) {
            $item['deleted'] = [
                'base' => $base === self::missing(),
                'ours' => $ours === self::missing(),
                'theirs' => $theirs === self::missing(),
            ];
        }

        return $item;
    }

    /**
     * @param string $reason
     * @param \MarkupCarve\Carve\Ast\AstMergePath $path
     * @param mixed $base
     * @param mixed $ours
     * @param mixed $theirs
     * @param list<array<string, mixed>> $conflicts
     * @param callable|null $resolve
     *
     * @throws \InvalidArgumentException
     */
    private static function conflict(
        string $reason,
        AstMergePath $path,
        mixed $base,
        mixed $ours,
        mixed $theirs,
        array &$conflicts,
        ?callable $resolve,
    ): mixed {
        $item = self::conflictItem($reason, $path, $base, $ours, $theirs);
        $resolution = $resolve !== null ? $resolve($item) : null;
        if ($resolution === null) {
            $conflicts[] = $item;

            return self::missing();
        }
        if (is_array($resolution) && array_key_exists('value', $resolution)) {
            return $resolution['value'];
        }

        $resolved = match ($resolution) {
            'base' => $base,
            'ours' => $ours,
            'theirs' => $theirs,
            default => throw new InvalidArgumentException('Merge resolver must return base, ours, theirs, {value}, or null.'),
        };

        return $resolved;
    }

    private static function kind(mixed $value): string
    {
        if (is_array($value) && !array_is_list($value) && isset($value['type']) && is_string($value['type'])) {
            return 'node:' . $value['type'];
        }

        return is_array($value) ? (array_is_list($value) ? 'array' : 'object') : get_debug_type($value);
    }

    private static function identityHint(mixed $value): ?string
    {
        if (!is_array($value) || array_is_list($value) || !isset($value['type']) || !is_string($value['type'])) {
            return null;
        }
        foreach (['label', 'ref', 'name'] as $field) {
            if (isset($value[$field]) && is_string($value[$field])) {
                return $value['type'] . ':' . $field . ':' . $value[$field];
            }
        }
        if (isset($value['attrs']) && is_array($value['attrs']) && isset($value['attrs']['id']) && is_string($value['attrs']['id'])) {
            return $value['type'] . ':attrs.id:' . $value['attrs']['id'];
        }

        return null;
    }

    /**
     * @param list<mixed> $base
     * @param list<mixed> $side
     * @param array<int|string, \MarkupCarve\Carve\Ast\AstIndexedValue> $baseNodes
     * @param array<int|string, \MarkupCarve\Carve\Ast\AstIndexedValue> $sideNodes
     *
     * @return array{baseToSide: array<int, int>, sideToBase: array<int, int>, additions: list<int>}
     */
    private static function matchSide(array $base, array $side, array $baseNodes, array $sideNodes): array
    {
        $baseToSide = [];
        $sideToBase = [];
        $exact = [];
        foreach ($side as $index => $value) {
            $exact[$sideNodes[$index]->id][] = $index;
        }
        $exactCursors = [];
        foreach ($base as $index => $value) {
            $key = $baseNodes[$index]->id;
            $cursor = $exactCursors[$key] ?? 0;
            if (!isset($exact[$key][$cursor])) {
                continue;
            }
            $sideIndex = $exact[$key][$cursor];
            $exactCursors[$key] = $cursor + 1;
            $baseToSide[$index] = $sideIndex;
            $sideToBase[$sideIndex] = $index;
        }
        $remainingBase = static function () use ($base, &$baseToSide): array {
            return array_values(array_diff(array_keys($base), array_keys($baseToSide)));
        };
        $remainingSide = static function () use ($side, &$sideToBase): array {
            return array_values(array_diff(array_keys($side), array_keys($sideToBase)));
        };

        $hints = [];
        $baseHints = [];
        foreach ($remainingBase() as $index) {
            $hint = self::identityHint($base[$index]);
            if ($hint !== null) {
                $baseHints[$hint][] = $index;
            }
        }
        foreach ($remainingSide() as $index) {
            $hint = self::identityHint($side[$index]);
            if ($hint !== null) {
                $hints[$hint][] = $index;
            }
        }
        foreach ($remainingBase() as $index) {
            $hint = self::identityHint($base[$index]);
            if ($hint !== null && count($baseHints[$hint] ?? []) === 1 && count($hints[$hint] ?? []) === 1) {
                $sideIndex = $hints[$hint][0];
                if (!isset($sideToBase[$sideIndex])) {
                    $baseToSide[$index] = $sideIndex;
                    $sideToBase[$sideIndex] = $index;
                }
            }
        }

        $baseKinds = [];
        $sideKinds = [];
        foreach ($remainingBase() as $index) {
            $baseKinds[self::kind($base[$index])][] = $index;
        }
        foreach ($remainingSide() as $index) {
            $sideKinds[self::kind($side[$index])][] = $index;
        }
        foreach ($baseKinds as $kind => $bs) {
            $ss = $sideKinds[$kind] ?? [];
            if (count($bs) === 1 && count($ss) === 1) {
                $baseToSide[$bs[0]] = $ss[0];
                $sideToBase[$ss[0]] = $bs[0];
            }
        }

        $bs = $remainingBase();
        $ss = $remainingSide();
        $baseCount = count($bs);
        $sideCount = count($ss);
        if ($baseCount * $sideCount > 1_000_000) {
            $cursor = 0;
            foreach ($bs as $baseIndex) {
                while ($cursor < $sideCount && self::kind($base[$baseIndex]) !== self::kind($side[$ss[$cursor]])) {
                    ++$cursor;
                }
                if ($cursor >= $sideCount) {
                    break;
                }
                $baseToSide[$baseIndex] = $ss[$cursor];
                $sideToBase[$ss[$cursor]] = $baseIndex;
                ++$cursor;
            }
        } else {
            $table = array_fill(0, $baseCount + 1, array_fill(0, $sideCount + 1, 0));
            $baseKinds = array_map(static fn (int $index): string => self::kind($base[$index]), $bs);
            $sideKinds = array_map(static fn (int $index): string => self::kind($side[$index]), $ss);
            for ($i = $baseCount - 1; $i >= 0; --$i) {
                for ($j = $sideCount - 1; $j >= 0; --$j) {
                    $table[$i][$j] = $baseKinds[$i] === $sideKinds[$j]
                        ? $table[$i + 1][$j + 1] + 1
                        : max($table[$i + 1][$j], $table[$i][$j + 1]);
                }
            }
            for ($i = 0, $j = 0; $i < $baseCount && $j < $sideCount;) {
                if ($baseKinds[$i] === $sideKinds[$j]) {
                    $baseToSide[$bs[$i]] = $ss[$j];
                    $sideToBase[$ss[$j]] = $bs[$i];
                    ++$i;
                    ++$j;
                } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                    ++$i;
                } else {
                    ++$j;
                }
            }
        }

        return [
            'baseToSide' => $baseToSide,
            'sideToBase' => $sideToBase,
            'additions' => array_values(array_diff(array_keys($side), array_keys($sideToBase))),
        ];
    }

    /**
     * @param array{sideToBase: array<int, int>} $match
     * @param int $length
     *
     * @return array<int, string>
     */
    private static function anchors(array $match, int $length): array
    {
        $before = [];
        $at = -1;
        for ($i = 0; $i < $length; ++$i) {
            $before[$i] = $at;
            $at = $match['sideToBase'][$i] ?? $at;
        }
        $anchors = [];
        $at = -1;
        for ($i = $length - 1; $i >= 0; --$i) {
            $anchors[$i] = $before[$i] . ':' . $at;
            $at = $match['sideToBase'][$i] ?? $at;
        }

        return $anchors;
    }

    /**
     * @param \MarkupCarve\Carve\Ast\AstIndexedValue $baseIndex
     * @param \MarkupCarve\Carve\Ast\AstIndexedValue $oursIndex
     * @param \MarkupCarve\Carve\Ast\AstIndexedValue $theirsIndex
     * @param \MarkupCarve\Carve\Ast\AstMergePath $path
     * @param list<array<string, mixed>> $conflicts
     * @param callable|null $resolve
     *
     * @throws \InvalidArgumentException
     */
    private static function mergeSequence(
        AstIndexedValue $baseIndex,
        AstIndexedValue $oursIndex,
        AstIndexedValue $theirsIndex,
        AstMergePath $path,
        array &$conflicts,
        ?callable $resolve,
    ): mixed {
        $base = $baseIndex->value;
        $ours = $oursIndex->value;
        $theirs = $theirsIndex->value;
        if (!is_array($base) || !array_is_list($base) || !is_array($ours) || !array_is_list($ours) || !is_array($theirs) || !array_is_list($theirs)) {
            throw new InvalidArgumentException('Merge sequence requires list values.');
        }
        $om = self::matchSide($base, $ours, $baseIndex->children, $oursIndex->children);
        $tm = self::matchSide($base, $theirs, $baseIndex->children, $theirsIndex->children);
        $values = [];
        $omitted = [];
        foreach ($base as $index => $value) {
            $oi = $om['baseToSide'][$index] ?? null;
            $ti = $tm['baseToSide'][$index] ?? null;
            $token = 'b' . $index;
            if ($oi === null && $ti === null) {
                $omitted[$token] = true;

                continue;
            }
            if ($oi === null || $ti === null) {
                if ($baseIndex->children[$index]->id === ($oi === null ? $theirsIndex->children[$ti] : $oursIndex->children[$oi])->id) {
                    $omitted[$token] = true;

                    continue;
                }
                $previous = $path->push($index);
                $resolved = self::conflict('delete-edit', $path, $value, $oi === null ? self::missing() : $ours[$oi], $ti === null ? self::missing() : $theirs[$ti], $conflicts, $resolve);
                $path->pop($previous);
                if ($resolved === self::missing()) {
                    $omitted[$token] = true;
                } else {
                    $values[$token] = $resolved;
                }

                continue;
            }
            $previous = $path->push($index);
            $merged = self::mergeValue($baseIndex->children[$index], $oursIndex->children[$oi], $theirsIndex->children[$ti], $path, $conflicts, $resolve);
            $path->pop($previous);
            if ($merged === self::missing()) {
                $omitted[$token] = true;
            } else {
                $values[$token] = $merged;
            }
        }

        $ot = [];
        $tt = [];
        $used = [];
        $oursAnchors = self::anchors($om, count($ours));
        $theirsAnchors = self::anchors($tm, count($theirs));
        $buckets = [];
        $cursors = [];
        $identities = [];
        foreach ($tm['additions'] as $ti) {
            $key = $theirsIndex->children[$ti]->id;
            $anchor = $theirsAnchors[$ti];
            $bucketKey = $anchor . "\0" . $key;
            $buckets[$bucketKey][] = $ti;
            $hint = self::identityHint($theirs[$ti]);
            if ($hint !== null) {
                $hintKey = $anchor . "\0" . $hint;
                if (!isset($identities[$hintKey])) {
                    $identities[$hintKey] = [[$key, $ti]];
                } elseif ($identities[$hintKey][0][0] !== $key && count($identities[$hintKey]) === 1) {
                    $identities[$hintKey][] = [$key, $ti];
                }
            }
        }
        foreach ($om['additions'] as $oi) {
            $key = $oursIndex->children[$oi]->id;
            $anchor = $oursAnchors[$oi];
            $bucketKey = $anchor . "\0" . $key;
            $cursor = $cursors[$bucketKey] ?? 0;
            $same = $buckets[$bucketKey][$cursor] ?? null;
            $oursHint = self::identityHint($ours[$oi]);
            if ($oursHint !== null) {
                $hintKey = $anchor . "\0" . $oursHint;
                foreach ($identities[$hintKey] ?? [] as [$otherKey, $ti]) {
                    if ($otherKey !== $key && ($same === null || $ti < $same)) {
                        return self::conflict('concurrent-sequence-edit', $path, $base, $ours, $theirs, $conflicts, $resolve);
                    }
                }
            }
            if ($same !== null) {
                $cursors[$bucketKey] = $cursor + 1;
            }
            $token = 'o' . $oi;
            $ot[$oi] = $token;
            $values[$token] = $ours[$oi];
            if ($same !== null) {
                $tt[$same] = $token;
                $used[$same] = true;
            }
        }
        foreach ($tm['additions'] as $ti) {
            if (isset($used[$ti])) {
                continue;
            }
            $token = 't' . $ti;
            $tt[$ti] = $token;
            $values[$token] = $theirs[$ti];
        }
        $tokensFor = static function (array $side, array $match, array $additions) use ($omitted): array {
            $tokens = [];
            foreach ($side as $index => $_) {
                $token = isset($match['sideToBase'][$index]) ? 'b' . $match['sideToBase'][$index] : ($additions[$index] ?? null);
                if ($token !== null && !isset($omitted[$token])) {
                    $tokens[] = $token;
                }
            }

            return $tokens;
        };
        $oursTokens = $tokensFor($ours, $om, $ot);
        $theirsTokens = $tokensFor($theirs, $tm, $tt);
        $surviving = array_values(array_filter(array_map(static fn (int $i): string => 'b' . $i, array_keys($base)), static fn (string $token): bool => !isset($omitted[$token])));
        $basePart = static fn (array $tokens): array => array_values(array_filter($tokens, static fn (string $token): bool => str_starts_with($token, 'b')));
        $oursMoved = $basePart($oursTokens) !== $surviving;
        $theirsMoved = $basePart($theirsTokens) !== $surviving;
        $edges = [];
        $addEdges = static function (array $tokens, bool $includeBase) use (&$edges): void {
            $count = count($tokens);
            for ($i = 1; $i < $count; ++$i) {
                $from = $tokens[$i - 1];
                $to = $tokens[$i];
                if (!$includeBase && str_starts_with($from, 'b') && str_starts_with($to, 'b')) {
                    continue;
                }
                if ($from !== $to) {
                    $edges[$from][$to] = true;
                }
            }
        };
        if (!$oursMoved && !$theirsMoved) {
            $addEdges($surviving, true);
            $addEdges($oursTokens, false);
            $addEdges($theirsTokens, false);
        } else {
            $addEdges($oursTokens, $oursMoved);
            $addEdges($theirsTokens, $theirsMoved);
        }
        $tokens = array_fill_keys(array_unique([...$oursTokens, ...$theirsTokens]), true);
        $incoming = array_fill_keys(array_keys($tokens), 0);
        foreach ($edges as $tos) {
            foreach (array_keys($tos) as $to) {
                ++$incoming[$to];
            }
        }
        $ready = new SplPriorityQueue();
        $ready->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        foreach ($incoming as $token => $count) {
            if ($count === 0) {
                $ready->insert($token, [-ord($token[0]), -(int)substr($token, 1)]);
            }
        }
        $order = [];
        while (!$ready->isEmpty()) {
            $token = $ready->extract();
            if (!is_string($token)) {
                throw new InvalidArgumentException('A merge ordering token must be a string');
            }
            $order[] = $token;
            foreach (array_keys($edges[$token] ?? []) as $to) {
                --$incoming[$to];
                if ($incoming[$to] === 0) {
                    $ready->insert($to, [-ord($to[0]), -(int)substr($to, 1)]);
                }
            }
        }
        if (count($order) !== count($tokens)) {
            return self::conflict('concurrent-sequence-edit', $path, $base, $ours, $theirs, $conflicts, $resolve);
        }

        return array_map(static fn (string $token): mixed => $values[$token], $order);
    }

    /**
     * @param \MarkupCarve\Carve\Ast\AstIndexedValue $baseIndex
     * @param \MarkupCarve\Carve\Ast\AstIndexedValue $oursIndex
     * @param \MarkupCarve\Carve\Ast\AstIndexedValue $theirsIndex
     * @param \MarkupCarve\Carve\Ast\AstMergePath $path
     * @param list<array<string, mixed>> $conflicts
     * @param callable|null $resolve
     */
    private static function mergeValue(
        AstIndexedValue $baseIndex,
        AstIndexedValue $oursIndex,
        AstIndexedValue $theirsIndex,
        AstMergePath $path,
        array &$conflicts,
        ?callable $resolve,
    ): mixed {
        $base = $baseIndex->value;
        $ours = $oursIndex->value;
        $theirs = $theirsIndex->value;
        if ($oursIndex->id === $theirsIndex->id) {
            return $ours;
        }
        if ($oursIndex->id === $baseIndex->id) {
            return $theirs;
        }
        if ($theirsIndex->id === $baseIndex->id) {
            return $ours;
        }
        if ($ours === self::missing() || $theirs === self::missing()) {
            return self::conflict('delete-edit', $path, $base, $ours, $theirs, $conflicts, $resolve);
        }
        if (is_array($base) && array_is_list($base) && is_array($ours) && array_is_list($ours) && is_array($theirs) && array_is_list($theirs)) {
            return self::mergeSequence($baseIndex, $oursIndex, $theirsIndex, $path, $conflicts, $resolve);
        }
        if (is_array($base) && !array_is_list($base) && is_array($ours) && !array_is_list($ours) && is_array($theirs) && !array_is_list($theirs)) {
            $out = [];
            foreach (array_unique([...array_keys($base), ...array_keys($ours), ...array_keys($theirs)]) as $key) {
                if ($path->stripMetadata && ($key === 'pos' || $key === 'srcByteLength')) {
                    continue;
                }
                $previous = $path->push($key);
                $value = self::mergeValue(
                    $baseIndex->children[$key] ?? self::absent(),
                    $oursIndex->children[$key] ?? self::absent(),
                    $theirsIndex->children[$key] ?? self::absent(),
                    $path,
                    $conflicts,
                    $resolve,
                );
                $path->pop($previous);
                if ($value !== self::missing()) {
                    $out[$key] = $value;
                }
            }

            return $out;
        }

        return self::conflict('both-changed', $path, $base, $ours, $theirs, $conflicts, $resolve);
    }
}
