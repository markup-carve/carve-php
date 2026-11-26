<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Diagnostics;

use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Options;

/**
 * The caps of a record: include failures (C2-T-024), then render losses (C2-H-204, C2-H-205).
 */
final class Cap
{
    /**
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     * @param \MarkupCarve\Carve\Options $o
     * @param bool $losses Also apply `allowLosses` and `maxLosses`.
     *
     * @return array{0: array<\MarkupCarve\Carve\Diagnostics\Diagnostic>, 1: int} The kept entries and the count of the others.
     */
    public static function apply(array $diagnostics, Options $o, bool $losses): array
    {
        $kept = [];
        $suppressed = 0;
        $causes = 0;
        $lossCount = 0;
        foreach ($diagnostics as $d) {
            if (isset(Tables::INCLUDE_CAUSE_CODES[$d->code]) && $causes++ >= $o->includeDiagnostics) {
                $suppressed++;

                continue;
            }
            if ($losses && $d->channel === 'render-loss') {
                if (isset($o->allowLosses[$d->code])) {
                    continue;
                }
                if ($lossCount++ >= $o->maxLosses) {
                    $suppressed++;

                    continue;
                }
            }
            $kept[] = $d;
        }

        return [$kept, $suppressed];
    }
}
