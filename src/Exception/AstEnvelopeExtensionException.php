<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

/**
 * The payload marks an extension required that this reader does not implement.
 *
 * §34(c). Rendering the parts it understands and reporting success is what
 * §9(b) forbids one level down, for the same reason.
 */
final class AstEnvelopeExtensionException extends AstEnvelopeException
{
    public function __construct(public readonly string $extension)
    {
        parent::__construct(sprintf(
            'AST envelope requires extension "%s", which this reader does not implement '
                . '(PART 12 §34(c)).',
            $extension,
        ));
    }
}
