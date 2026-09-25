<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

/**
 * The envelope itself is not the shape `ast-envelope-schema.json` names.
 *
 * `additionalProperties: false` holds on the envelope and on each extension
 * entry, for the reason §11 closed the tree.
 */
final class AstEnvelopeShapeException extends AstEnvelopeException
{
    public function __construct(public readonly string $detail)
    {
        parent::__construct(sprintf('AST envelope is not the shape PART 12 §34 names: %s', $detail));
    }
}
