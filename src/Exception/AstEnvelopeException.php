<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use RuntimeException;

/**
 * A Carve AST interchange envelope this reader cannot accept (PART 12 §34).
 *
 * Deliberately NOT an {@see AstDecodeException}. That type means "the tree is
 * not one this decoder can read"; these mean "the envelope around it says
 * something this build cannot honour", and §34 exists precisely so a caller can
 * tell the two apart. A tree is only walked once the reader has agreed it can
 * read this contract, this vocabulary and these extensions at all, so the two
 * can never both be true of one payload.
 */
abstract class AstEnvelopeException extends RuntimeException
{
}
