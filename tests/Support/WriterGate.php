<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\Writer;
use Throwable;

/**
 * The two gates of the writer on one document (C2-W-002, C2-W-003): write, read back, equal tree, and writing the
 * result again gives the same bytes.
 */
final class WriterGate
{
    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return string|null Why the gate failed, or null.
     */
    public static function check(string $source, array $options = []): ?string
    {
        $tree = self::tree($source);
        $first = Writer::writeWithDiagnostics($tree, null, $options);
        if (self::refused($first->codes())) {
            return null;
        }
        $back = self::tree($first->source);
        if (!TreeEquality::equal($tree, $back)) {
            return 'tree';
        }
        $second = Writer::writeWithDiagnostics($back, null, $options);
        if ($second->source !== $first->source) {
            return 'stable';
        }

        return null;
    }

    /**
     * Both gates on one parse, as the carve-js gates count them: `failure` is a gate that failed, `loss` whether the
     * writer reported a loss (C2-W-011) on a source it was given, `refused` a C2-W-133 refusal.
     *
     * @param string $source
     *
     * @return array{failure: string|null, loss: bool, refused: bool}
     */
    public static function gates(string $source): array
    {
        try {
            $tree = self::tree($source);
            $formatted = Writer::formatWithDiagnostics($source);
            $written = Writer::writeWithDiagnostics($tree);
            $codes = array_merge($formatted->codes(), $written->codes());
            if (self::refused($codes)) {
                return ['failure' => null, 'loss' => true, 'refused' => true];
            }
            $failure = self::againstTree($tree, $formatted->source, true) ?? self::againstTree($tree, $written->source, false);
        } catch (Throwable $e) {
            return ['failure' => 'threw ' . $e->getMessage(), 'loss' => false, 'refused' => false];
        }

        return ['failure' => $failure, 'loss' => $codes !== [], 'refused' => false];
    }

    /**
     * @param array<string, mixed> $tree
     * @param string $written
     * @param bool $fmt
     *
     * @return string|null
     */
    private static function againstTree(array $tree, string $written, bool $fmt): ?string
    {
        $back = self::tree($written);
        if (!TreeEquality::equal($tree, $back)) {
            return ($fmt ? 'fmt' : 'write') . ' tree';
        }
        $again = $fmt ? Writer::formatWithDiagnostics($written)->source : Writer::writeWithDiagnostics($back)->source;

        return $again === $written ? null : ($fmt ? 'fmt' : 'write') . ' stable';
    }

    /**
     * The gates through `fmt`, which writes with the sidecar of its parse (C2-W-002, C2-L-042).
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return string|null
     */
    public static function checkFormat(string $source, array $options = []): ?string
    {
        $first = Writer::formatWithDiagnostics($source, $options);
        if (self::refused($first->codes())) {
            return null;
        }
        if (!TreeEquality::equal(self::tree($source), self::tree($first->source))) {
            return 'tree';
        }
        if (Writer::formatWithDiagnostics($first->source, $options)->source !== $first->source) {
            return 'stable';
        }

        return null;
    }

    /**
     * @param array<string> $codes
     *
     * @return bool
     */
    private static function refused(array $codes): bool
    {
        return in_array('writer-refused', $codes, true);
    }

    /**
     * @param string $source
     *
     * @return array<string, mixed>
     */
    public static function tree(string $source): array
    {
        $tree = json_decode(Carve::toJson($source), true, 100000, JSON_THROW_ON_ERROR);

        return is_array($tree) ? $tree : [];
    }

    /**
     * The inputs of the conformance corpus: every Carve input of an example and of a clause case.
     *
     * @param string $dir
     *
     * @return array<string, string>
     */
    public static function corpusInputs(string $dir): array
    {
        $inputs = [];
        $examples = json_decode((string)file_get_contents($dir . '/examples.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (is_array($examples) ? $examples['examples'] : [] as $example) {
            if (is_string($example['input'] ?? null)) {
                $inputs[(string)$example['id']] = $example['input'];
            }
        }
        $cases = json_decode((string)file_get_contents($dir . '/clause-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (is_array($cases) ? $cases['cases'] : [] as $case) {
            if (is_string($case['input'] ?? null)) {
                $inputs[(string)$case['id']] = $case['input'];
            }
        }

        return $inputs;
    }
}
