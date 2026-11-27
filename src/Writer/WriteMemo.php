<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Closure;
use Exception;

/**
 * The traced writes of one outer write, by their inputs: the C2-W-088 passes and their trials write the same tree with
 * the same escapes again. A write is a function of its arguments and of the `Text` write state, which the key holds;
 * a hit restores the state the write left.
 *
 * @phpstan-import-type WriteState from \MarkupCarve\Carve\Writer\Text
 */
final class WriteMemo
{
    /**
     * @var array<string, int> Entries kept per kind at most.
     */
    private const LIMITS = ['bare' => 64, 'marked' => 16, 'decoded' => 16, 'print' => 512, 'probe' => 2048];

    /**
     * @var array<string, int> The bytes of source each kind keeps at most, weighed by what an entry holds beside its
     *   source: a marked candidate maps every traced character, so a long document keeps few or none.
     */
    private const BYTES = [
        'bare' => 2_000_000,
        'marked' => 4_000_000,
        'decoded' => 2_000_000,
        'print' => 4_000_000,
        'probe' => 4_000_000,
    ];

    /**
     * @var int A longer source is not kept, as `ReadbackRound` keeps no long read-back it is not asked to (carve#6413).
     */
    private const MAX_SOURCE = 65536;

    /**
     * @var array<string, int>
     */
    private const WEIGHTS = ['bare' => 1, 'marked' => 40, 'decoded' => 2, 'print' => 0, 'probe' => 0];

    /**
     * @var array<string, int> The weighed bytes each kind holds.
     */
    private static array $held = [];

    /**
     * @var array<string, array<string, array{mixed, WriteState|null, bool}>>|null By kind, oldest first; null outside
     *   `scoped()`.
     */
    private static ?array $entries = null;

    /**
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     */
    public static function scoped(Closure $write): mixed
    {
        $outer = [self::$entries, self::$held];
        if (self::$entries === null) {
            self::$entries = [];
            self::$held = [];
        }
        try {
            return $write();
        } finally {
            [self::$entries, self::$held] = $outer;
        }
    }

    /**
     * @template T
     *
     * @param string $kind
     * @param array<mixed> $inputs Everything the write reads besides the `Text` state.
     * @param \Closure(): array{T, bool} $write The result and the flag the caller keeps beside it.
     *
     * @return array{T, bool}
     */
    public static function remember(string $kind, array $inputs, Closure $write): array
    {
        if (self::$entries === null) {
            return $write();
        }
        $key = self::key([$inputs, Text::writeState()]);
        $entry = self::$entries[$kind][$key] ?? null;
        if ($entry !== null) {
            unset(self::$entries[$kind][$key]);
            self::$entries[$kind][$key] = $entry;
            if ($entry[1] !== null) {
                Text::restoreWriteState($entry[1]);
            }

            return [$entry[0], $entry[2]];
        }
        [$result, $flag] = $write();
        self::keep($kind, $key, [$result, Text::writeState(), $flag]);

        return [$result, $flag];
    }

    /**
     * A content probe of the C2-W-088 rounds, by its inputs and the `Text` write state: its result, the characters it
     * charged and the state it left. A run is kept and reused only where it charged no more than the budget left, so it
     * never stopped on the budget of C2-W-133.
     *
     * @template T
     *
     * @param array<mixed> $inputs
     * @param int $budget The characters the probe may still charge.
     * @param \Closure(): array{T, int} $run The result and the characters it charged.
     *
     * @return array{T, int}
     */
    public static function probe(array $inputs, int $budget, Closure $run): array
    {
        if (self::$entries === null) {
            return $run();
        }
        $key = self::key([$inputs, Text::writeState()]);
        $kept = self::$entries['probe'][$key] ?? null;
        if ($kept !== null && is_array($kept[0]) && is_int($kept[0][1] ?? null) && $kept[0][1] <= $budget) {
            if ($kept[1] !== null) {
                Text::restoreWriteState($kept[1]);
            }

            return [$kept[0][0], $kept[0][1]];
        }
        $result = $run();
        if ($result[1] <= $budget) {
            self::keep('probe', $key, [$result, Text::writeState(), false]);
        }

        return $result;
    }

    /**
     * A value that depends only on its inputs, as the fingerprint of a parse.
     *
     * @template T
     *
     * @param string $kind
     * @param array<mixed> $inputs
     * @param \Closure(): T $read
     *
     * @return T
     */
    public static function read(string $kind, array $inputs, Closure $read): mixed
    {
        if (self::$entries === null) {
            return $read();
        }
        $key = self::key($inputs);
        if (isset(self::$entries[$kind][$key])) {
            return self::$entries[$kind][$key][0];
        }
        $value = $read();
        self::keep($kind, $key, [$value, null, false]);

        return $value;
    }

    /**
     * Adds an entry and drops the oldest while the kind holds more than its count or its bytes.
     *
     * @param string $kind
     * @param string $key
     * @param array{mixed, WriteState|null, bool} $entry
     */
    private static function keep(string $kind, string $key, array $entry): void
    {
        $bytes = self::sourceBytes($entry[0]);
        $size = self::WEIGHTS[$kind] * $bytes;
        if ($bytes > self::MAX_SOURCE || $size > self::BYTES[$kind] || self::$entries === null) {
            return;
        }
        $kept = self::$entries[$kind] ?? [];
        $held = (self::$held[$kind] ?? 0) + $size;
        $kept[$key] = $entry;
        $count = count($kept);
        while ($count-- > self::LIMITS[$kind] || $held > self::BYTES[$kind]) {
            $oldest = array_key_first($kept);
            $held -= self::WEIGHTS[$kind] * self::sourceBytes($kept[$oldest][0]);
            unset($kept[$oldest]);
        }
        self::$entries[$kind] = $kept;
        self::$held[$kind] = $held;
    }

    private static function sourceBytes(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }

        return is_array($value) && is_string($value['source'] ?? null) ? strlen($value['source']) : 0;
    }

    /**
     * An extension registered with closures is keyed by the identity of its objects, which live as long as the write.
     *
     * @param array<mixed> $inputs
     */
    private static function key(array $inputs): string
    {
        try {
            return hash('xxh128', serialize($inputs));
        } catch (Exception) {
            return hash('xxh128', serialize(self::objectIds($inputs)));
        }
    }

    private static function objectIds(mixed $value): mixed
    {
        if (is_object($value)) {
            return [$value::class, spl_object_id($value)];
        }
        if (!is_array($value)) {
            return $value;
        }

        return array_map(self::objectIds(...), $value);
    }
}
