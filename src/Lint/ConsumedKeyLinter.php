<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Node;

/**
 * Reports a bare key one edit away from the consumed key its block reads (`{tabel}`, `{lose}`).
 */
final class ConsumedKeyLinter
{
    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $map = SourceOffsets::map($source);
        $length = strlen($source);
        $warnings = [];
        $pending = [$document];
        while ($pending !== []) {
            $node = array_shift($pending);
            $key = $this->consumedKey($node);
            $pos = $node->getPos();
            if ($key !== null && $pos !== null) {
                foreach ($node->getAttributes() as $word => $value) {
                    $word = (string)$word;
                    if ($value !== '' || !self::isNearMiss($word, $key)) {
                        continue;
                    }
                    $warnings[] = new LintWarning(
                        $pos->startLine,
                        $pos->startColumn,
                        'consumed-key-near-miss',
                        sprintf('"%s" is not a consumed key here; did you mean {%s}?', $word, $key),
                        SourceOffsets::toByte($pos->startOffset, $map, $length),
                        SourceOffsets::toByte($pos->endOffset, $map, $length),
                    );
                }
            }
            array_push($pending, ...$node->getChildren());
        }

        return $warnings;
    }

    private function consumedKey(Node $node): ?string
    {
        if ($node instanceof ListBlock || $node instanceof DefinitionList) {
            return 'loose';
        }
        $code = $node instanceof CodeBlock ? $node : null;
        if ($node instanceof Figure) {
            $target = $node->getTargets()[0] ?? null;
            $code = $target instanceof CodeBlock ? $target : null;
        }
        $language = $code?->getLanguage();

        return $language === 'csv' || $language === 'tsv' ? 'table' : null;
    }

    /**
     * A case-only match, or one insertion, deletion, substitution or adjacent swap.
     */
    public static function isNearMiss(string $word, string $key): bool
    {
        if ($word === $key) {
            return false;
        }
        if (strtolower($word) === $key) {
            return true;
        }
        $n = strlen($word);
        $m = strlen($key);
        if (abs($n - $m) > 1) {
            return false;
        }
        $d = [];
        for ($i = 0; $i <= $n; $i++) {
            $d[$i] = [$i];
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $word[$i - 1] === $key[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $word[$i - 1] === $key[$j - 2] && $word[$i - 2] === $key[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$n][$m] <= 1;
    }
}
