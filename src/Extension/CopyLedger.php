<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Node;
use ReflectionFunction;

/**
 * Internal: the copies a built-in document transform makes of one node, so that the view check reads each template
 * once per place instead of each copy (carve-php#5978).
 *
 * Only a transform closure written in `Navigation` records copies, and only while `run()` runs it: such a transform
 * takes no callback, runs no other code and changes no copy after making it, so each copy still holds the values of its
 * template when the check reads the view. A transform that calls one of them is not trusted itself, so its copies are
 * checked one by one.
 */
final class CopyLedger
{
    /**
     * The copy and its template, by object id of the copy; null outside a trusted run.
     *
     * @var array<int, array{\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Node}>|null
     */
    private static ?array $copies = null;

    public static function record(Node $copy, Node $template): void
    {
        if (self::$copies !== null) {
            self::$copies[spl_object_id($copy)] = [$copy, $template];
        }
    }

    /**
     * Runs a document transform and returns its view with each copy it recorded and its template, by object id of
     * the copy. The caller holds the pairs while it reads the ids, so no other object takes the id of a copy.
     *
     * @param \Closure(\MarkupCarve\Carve\Node): \MarkupCarve\Carve\Node $run
     * @param \MarkupCarve\Carve\Node $view
     *
     * @return array{\MarkupCarve\Carve\Node, array<int, array{\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Node}>}
     */
    public static function run(Closure $run, Node $view): array
    {
        if (!self::trusted($run)) {
            $outer = self::$copies;
            self::$copies = null;
            try {
                return [$run($view), []];
            } finally {
                self::$copies = $outer;
            }
        }
        $outer = self::$copies;
        self::$copies = [];
        try {
            $changed = $run($view);

            return [$changed, self::$copies ?? []];
        } finally {
            self::$copies = $outer;
        }
    }

    private static function trusted(Closure $run): bool
    {
        return (new ReflectionFunction($run))->getClosureScopeClass()?->getName() === Navigation::class;
    }
}
