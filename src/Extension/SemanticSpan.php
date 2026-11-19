<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

/**
 * Provisional. The span keys `samp`, `var`, `cite` and `dfn` for render and lint (carve-js#5060), in the 0.1 order from
 * inside out beside the core three (C2-H-075); a `dfn` value becomes `title`.
 */
final class SemanticSpan
{
    /**
     * @var array<array{0: string, 1: string|null}>
     */
    public const KEYS = [
        ['abbr', 'title'], ['time', 'datetime'], ['samp', null], ['var', null], ['kbd', null], ['cite', null], ['dfn', 'title'],
    ];

    public static function bundle(): Bundle
    {
        return new Bundle([new ExtensionDefinition('carve.semanticSpan', '1', semanticKeys: self::KEYS)]);
    }
}
