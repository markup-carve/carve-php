<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use MarkupCarve\Carve\Ast\AstEnvelope;

/**
 * The payload announces a higher MAJOR contract version.
 *
 * §34(a) asks for a typed error naming both versions, and says why it is not a
 * schema failure: the payload may be perfectly well-formed under a contract
 * this build predates. Reporting it as invalid sends the caller hunting a
 * corrupt tree.
 */
final class AstEnvelopeVersionException extends AstEnvelopeException
{
    public function __construct(
        public readonly string $found,
        public readonly string $implemented = AstEnvelope::CONTRACT_VERSION,
    ) {
        parent::__construct(sprintf(
            'AST envelope announces contract version %s; this build implements %s. A higher '
                . 'major removes, renames or reinterprets, so the payload is refused rather '
                . 'than half-read (PART 12 §34(a)).',
            $found,
            $implemented,
        ));
    }
}
