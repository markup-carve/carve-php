<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Resolve;

use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;

/**
 * The resolve stage: references, heading ids, numbering, the result list.
 */
final class Resolver
{
    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param string $s
     * @param \MarkupCarve\Carve2\Options $opts
     *
     * @return void
     */
    public static function resolve(Node $doc, string $s, Options $opts): void
    {
        $doc->f['astVersion'] = '2.0';
    }
}
