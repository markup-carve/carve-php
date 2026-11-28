<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

final class PlanReason
{
    /**
     * @param \MarkupCarve\Carve\Writer\Plan $plan
@param array<mixed> $group
     * @param \MarkupCarve\Carve\Writer\WriteResult $written
     * @param string $path
     */
    public static function forGroup(Plan $plan, array $group, string $path, WriteResult $written): ?string
    {
        if ($plan->undecided !== null) {
            return $plan->undecided;
        }
        if ($written->diagnostics !== []) {
            return 'loss';
        }
        if (self::countCrEnds($written->source) > count($plan->lost)) {
            return 'control';
        }

        return self::uncovered($group, $path, $plan) ? 'coverage' : null;
    }

    private static function countCrEnds(string $source): int
    {
        $found = 0;
        foreach (explode("\n", $source) as $line) {
            if (str_ends_with(rtrim($line, " \t"), "\r")) {
                $found++;
            }
        }

        return $found;
    }

    /**
     * @param array<mixed> $nodes
     * @param \MarkupCarve\Carve\Writer\Plan $plan
     * @param string $path
     */
    private static function uncovered(array $nodes, string $path, Plan $plan): bool
    {
        $pending = [[$nodes, $path]];
        while ($pending !== []) {
            [$list, $listPath] = array_pop($pending);
            foreach ($list as $at => $node) {
                if (!is_array($node)) {
                    continue;
                }
                $nodePath = $listPath . '/' . $at;
                if (isset($plan->opaqueBlocks[$nodePath])) {
                    continue;
                }
                if (($node['type'] ?? null) === 'text' && !isset($plan->covered[$nodePath])) {
                    return true;
                }
                foreach ($node as $slot => $value) {
                    if (
                        !is_string($slot) || !is_array($value) || !array_is_list($value)
                        || ($value !== [] && !is_array($value[0]))
                    ) {
                        continue;
                    }
                    $pending[] = [$value, $nodePath . '/' . $slot];
                }
            }
        }

        return false;
    }
}
