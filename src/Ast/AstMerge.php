<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;
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
        $merged = self::mergeValue($base, $ours, $theirs, '', $conflicts, $resolve);
        if ($merged === self::missing() || $conflicts !== []) {
            return ['ok' => false, 'ast' => null, 'conflicts' => $conflicts];
        }

        /** @var array<string, mixed> $ast */
        $ast = self::clean($merged);
        if (($ast['type'] ?? null) !== 'document' || !isset($ast['children']) || !is_array($ast['children'])) {
            throw new InvalidArgumentException('Merge result is not a PART 12 document root.');
        }
        $ast['srcByteLength'] = 0;

        return ['ok' => true, 'ast' => $ast, 'conflicts' => []];
    }

    private static function pointer(string $path, string|int $key): string
    {
        return $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key);
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

        return $out;
    }

    private static function stripMetadata(string $path): bool
    {
        return !in_array('keyValues', explode('/', $path), true);
    }

    private static function equal(mixed $a, mixed $b, string $path = ''): bool
    {
        if ($a === self::missing() || $b === self::missing()) {
            return $a === $b;
        }

        $stripMetadata = self::stripMetadata($path);

        return json_encode(self::clean($a, $stripMetadata), JSON_THROW_ON_ERROR) === json_encode(self::clean($b, $stripMetadata), JSON_THROW_ON_ERROR);
    }

    private static function key(mixed $value, string $path): string
    {
        return json_encode(self::clean($value, self::stripMetadata($path)), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function conflictItem(string $reason, string $path, mixed $base, mixed $ours, mixed $theirs): array
    {
        $item = [
            'path' => $path !== '' ? $path : '/',
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
     * @param string $path
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
        string $path,
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
     * @param string $path
     *
     * @return array{baseToSide: array<int, int>, sideToBase: array<int, int>, additions: list<int>}
     */
    private static function matchSide(array $base, array $side, string $path): array
    {
        $baseToSide = [];
        $sideToBase = [];
        $exact = [];
        foreach ($side as $index => $value) {
            $exact[self::key($value, $path)][] = $index;
        }
        foreach ($base as $index => $value) {
            $key = self::key($value, $path);
            if (($exact[$key] ?? []) === []) {
                continue;
            }
            $sideIndex = array_shift($exact[$key]);
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

        $kinds = [];
        foreach ($remainingBase() as $index) {
            $kinds[self::kind($base[$index])] = true;
        }
        foreach (array_keys($kinds) as $kind) {
            $bs = array_values(array_filter($remainingBase(), static fn (int $i): bool => self::kind($base[$i]) === $kind));
            $ss = array_values(array_filter($remainingSide(), static fn (int $i): bool => self::kind($side[$i]) === $kind));
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
            for ($i = $baseCount - 1; $i >= 0; --$i) {
                for ($j = $sideCount - 1; $j >= 0; --$j) {
                    $table[$i][$j] = self::kind($base[$bs[$i]]) === self::kind($side[$ss[$j]])
                        ? $table[$i + 1][$j + 1] + 1
                        : max($table[$i + 1][$j], $table[$i][$j + 1]);
                }
            }
            for ($i = 0, $j = 0; $i < $baseCount && $j < $sideCount;) {
                if (self::kind($base[$bs[$i]]) === self::kind($side[$ss[$j]])) {
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
     * @param int $index
@param array{sideToBase: array<int, int>} $match
     * @param int $length
     */
    private static function anchor(int $index, array $match, int $length): string
    {
        $before = -1;
        $after = -1;
        for ($i = $index - 1; $i >= 0; --$i) {
            if (isset($match['sideToBase'][$i])) {
                $before = $match['sideToBase'][$i];

                break;
            }
        }
        for ($i = $index + 1; $i < $length; ++$i) {
            if (isset($match['sideToBase'][$i])) {
                $after = $match['sideToBase'][$i];

                break;
            }
        }

        return $before . ':' . $after;
    }

    /**
     * @param list<mixed> $base
     * @param list<mixed> $ours
     * @param list<mixed> $theirs
     * @param string $path
     * @param list<array<string, mixed>> $conflicts
     * @param callable|null $resolve
     */
    private static function mergeSequence(array $base, array $ours, array $theirs, string $path, array &$conflicts, ?callable $resolve): mixed
    {
        $om = self::matchSide($base, $ours, $path);
        $tm = self::matchSide($base, $theirs, $path);
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
                $present = $oi === null ? $theirs[$ti] : $ours[$oi];
                if (self::equal($value, $present, $path)) {
                    $omitted[$token] = true;

                    continue;
                }
                $resolved = self::conflict('delete-edit', self::pointer($path, $index), $value, $oi === null ? self::missing() : $ours[$oi], $ti === null ? self::missing() : $theirs[$ti], $conflicts, $resolve);
                if ($resolved === self::missing()) {
                    $omitted[$token] = true;
                } else {
                    $values[$token] = $resolved;
                }

                continue;
            }
            $merged = self::mergeValue($value, $ours[$oi], $theirs[$ti], self::pointer($path, $index), $conflicts, $resolve);
            if ($merged === self::missing()) {
                $omitted[$token] = true;
            } else {
                $values[$token] = $merged;
            }
        }

        $ot = [];
        $tt = [];
        $used = [];
        foreach ($om['additions'] as $oi) {
            $same = null;
            $oursHint = self::identityHint($ours[$oi]);
            foreach ($tm['additions'] as $ti) {
                if ($oursHint !== null && self::identityHint($theirs[$ti]) === $oursHint && self::anchor($oi, $om, count($ours)) === self::anchor($ti, $tm, count($theirs)) && !self::equal($ours[$oi], $theirs[$ti], $path)) {
                    return self::conflict('concurrent-sequence-edit', $path, $base, $ours, $theirs, $conflicts, $resolve);
                }
                if (!isset($used[$ti]) && self::anchor($oi, $om, count($ours)) === self::anchor($ti, $tm, count($theirs)) && self::equal($ours[$oi], $theirs[$ti], $path)) {
                    $same = $ti;

                    break;
                }
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
        $ready = array_keys(array_filter($incoming, static fn (int $count): bool => $count === 0));
        $order = [];
        while ($ready !== []) {
            sort($ready, SORT_STRING);
            $token = array_shift($ready);
            $order[] = $token;
            foreach (array_keys($edges[$token] ?? []) as $to) {
                --$incoming[$to];
                if ($incoming[$to] === 0) {
                    $ready[] = $to;
                }
            }
        }
        if (count($order) !== count($tokens)) {
            return self::conflict('concurrent-sequence-edit', $path, $base, $ours, $theirs, $conflicts, $resolve);
        }

        return array_map(static fn (string $token): mixed => $values[$token], $order);
    }

    /**
     * @param mixed $base
     * @param mixed $ours
     * @param mixed $theirs
     * @param string $path
@param list<array<string, mixed>> $conflicts
     * @param callable|null $resolve
     */
    private static function mergeValue(mixed $base, mixed $ours, mixed $theirs, string $path, array &$conflicts, ?callable $resolve): mixed
    {
        if (self::equal($ours, $theirs, $path)) {
            return $ours;
        }
        if (self::equal($ours, $base, $path)) {
            return $theirs;
        }
        if (self::equal($theirs, $base, $path)) {
            return $ours;
        }
        if ($ours === self::missing() || $theirs === self::missing()) {
            return self::conflict('delete-edit', $path, $base, $ours, $theirs, $conflicts, $resolve);
        }
        if (is_array($base) && array_is_list($base) && is_array($ours) && array_is_list($ours) && is_array($theirs) && array_is_list($theirs)) {
            return self::mergeSequence($base, $ours, $theirs, $path, $conflicts, $resolve);
        }
        if (is_array($base) && !array_is_list($base) && is_array($ours) && !array_is_list($ours) && is_array($theirs) && !array_is_list($theirs)) {
            $out = [];
            foreach (array_unique([...array_keys($base), ...array_keys($ours), ...array_keys($theirs)]) as $key) {
                if (self::stripMetadata($path) && ($key === 'pos' || $key === 'srcByteLength')) {
                    continue;
                }
                $value = self::mergeValue(
                    array_key_exists($key, $base) ? $base[$key] : self::missing(),
                    array_key_exists($key, $ours) ? $ours[$key] : self::missing(),
                    array_key_exists($key, $theirs) ? $theirs[$key] : self::missing(),
                    self::pointer($path, $key),
                    $conflicts,
                    $resolve,
                );
                if ($value !== self::missing()) {
                    $out[$key] = $value;
                }
            }

            return $out;
        }

        return self::conflict('both-changed', $path, $base, $ours, $theirs, $conflicts, $resolve);
    }
}
