<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use RuntimeException;

/**
 * The canonical Carve writer cannot spell an AST node without changing it.
 *
 * NOT EVERY SUCH SHAPE THROWS. A paragraph whose whole content is one image is
 * a DECLARED writer ceiling rather than a refusal - it is written as a block
 * image and the caller that writes source declares the loss, because refusing
 * would break every import of a `<p><img></p>` (markup-carve/carve#1658). The
 * carve-outs are listed on {@see \MarkupCarve\Carve\Renderer\CarveRenderer}.
 */
class SourceUnspellableException extends RuntimeException
{
    public function __construct(public readonly string $nodeType, public readonly string $reason)
    {
        parent::__construct(sprintf('The Carve renderer cannot spell %s: %s.', $nodeType, $reason));
    }
}
