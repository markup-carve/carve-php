<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Closure;
use MarkupCarve\Carve\CarveConverter;

/**
 * The position-tracking core parse the linters read. Inside share() every
 * linter gets the same converter and tree, so callers must not mutate either.
 *
 * @internal
 */
final class LintParse
{
    /**
     * @var array{0: string, 1: array{0: \MarkupCarve\Carve\CarveConverter, 1: \MarkupCarve\Carve\Node\Document}|null}|null
     */
    private static ?array $shared = null;

    /**
     * @template T
     *
     * @param string $source
     * @param \Closure(): T $lint
     *
     * @return T
     */
    public static function share(string $source, Closure $lint): mixed
    {
        $outer = self::$shared;
        self::$shared = [$source, null];
        try {
            return $lint();
        } finally {
            self::$shared = $outer;
        }
    }

    /**
     * @param string $source
     *
     * @return array{0: \MarkupCarve\Carve\CarveConverter, 1: \MarkupCarve\Carve\Node\Document}
     */
    public static function parse(string $source): array
    {
        if (self::$shared === null || self::$shared[0] !== $source) {
            return self::fresh($source);
        }

        return self::$shared[1] ??= self::fresh($source);
    }

    /**
     * @param string $source
     *
     * @return array{0: \MarkupCarve\Carve\CarveConverter, 1: \MarkupCarve\Carve\Node\Document}
     */
    private static function fresh(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();

        return [$converter, $converter->parse($source)];
    }
}
