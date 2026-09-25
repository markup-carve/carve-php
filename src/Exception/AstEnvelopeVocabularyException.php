<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

/**
 * The payload names a node vocabulary this build does not know.
 *
 * §34 names "a vocabulary this build does not know" as one of the three
 * failures the envelope exists to tell apart. A foreign vocabulary is refused
 * rather than walked: the tree would otherwise reach the codec and come back as
 * an unknown node type, which is the undifferentiated failure §34 opens by
 * naming.
 */
final class AstEnvelopeVocabularyException extends AstEnvelopeException
{
    public function __construct(public readonly string $vocabulary)
    {
        parent::__construct(sprintf(
            'AST envelope is written in vocabulary "%s", which this reader does not know '
                . '(PART 12 §34).',
            $vocabulary,
        ));
    }
}
