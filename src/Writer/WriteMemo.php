<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Closure;

/**
 * The traced writes of one outer write, by their inputs: the C2-W-088 passes and their trials write the same tree with
 * the same escapes again. A write is a function of its arguments and of the `Text` write state, which the key holds;
 * a hit restores the state the write left.
 */
final class WriteMemo
{
    /**
     * @var array<string, int> Entries kept per kind at most.
     */
    private const LIMITS = ['bare' => 64, 'marked' => 16, 'decoded' => 16, 'print' => 512];

    /**
     * @var array<string, int> The bytes of source each kind keeps at most, weighed by what an entry holds beside its
     *   source: a marked candidate maps every traced character, so a long document keeps few or none.
     */
    private const BYTES = ['bare' => 2_000_000, 'marked' => 4_000_000, 'decoded' => 2_000_000, 'print' => 4_000_000];

    /**
     * @var int A longer source is not kept, as `ReadbackRound` keeps no long read-back it is not asked to (carve#6413).
     */
    private const MAX_SOURCE = 65536;

    /**
     * @var array<string, int>
     */
    private const WEIGHTS = ['bare' => 1, 'marked' => 40, 'decoded' => 2, 'print' => 0];

    /**
     * @var array<string, int> The weighed bytes each kind holds.
     */
    private static array $held = [];

    /**
     * @var array<string, array<string, array{mixed, array<mixed>, bool}>>|null By kind, oldest first; null outside
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
     * @param string $kind
     * @param array<mixed> $inputs Everything the write reads besides the `Text` state.
     * @param \Closure(): array{mixed, bool} $write The result and the flag the caller keeps beside it.
     *
     * @return array{mixed, bool}
     */
    public static function remember(string $kind, array $inputs, Closure $write): array
    {
        if (self::$entries === null) {
            return $write();
        }
        $key = hash('xxh128', serialize([$inputs, Text::writeState()]));
        $entry = self::$entries[$kind][$key] ?? null;
        if ($entry !== null) {
            unset(self::$entries[$kind][$key]);
            self::$entries[$kind][$key] = $entry;
            Text::restoreWriteState($entry[1]);

            return [$entry[0], $entry[2]];
        }
        [$result, $flag] = $write();
        self::keep($kind, $key, [$result, Text::writeState(), $flag]);

        return [$result, $flag];
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
        $key = hash('xxh128', serialize($inputs));
        if (isset(self::$entries[$kind][$key])) {
            return self::$entries[$kind][$key][0];
        }
        $value = $read();
        self::keep($kind, $key, [$value, [], false]);

        return $value;
    }

    /**
     * Adds an entry and drops the oldest while the kind holds more than its count or its bytes.
     *
     * @param string $kind
     * @param string $key
     * @param array{mixed, array<mixed>, bool} $entry
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
        while (count($kept) > self::LIMITS[$kind] || $held > self::BYTES[$kind]) {
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
}
