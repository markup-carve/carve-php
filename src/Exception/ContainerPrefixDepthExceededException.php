<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use MarkupCarve\Carve\Parser\BlockParser;
use RuntimeException;

/**
 * A single line's container prefix reached the parser's walk ceiling (PART 9 §25).
 *
 * "AT THE RENDER CEILING, A RENDERER REFUSES -- NORMATIVE. Reaching it MUST
 * produce a typed, documented failure naming the depth bound. NOT silent
 * truncation, not a partial document, and not whatever the host language
 * raises when the stack runs out."
 *
 * The clause is written for the render ceiling, and the same sentence decides
 * this one: what it forbids is the host language's answer, and on this engine
 * that answer is a SIGSEGV rather than a catchable `Error` - `parse()` returned
 * no document, no exception and no interpretable exit code
 * (markup-carve/carve-php#1456).
 *
 * The bound is on ONE LINE'S container prefix, which is a different axis from
 * `BlockParser::MAX_NESTING_DEPTH`: that cap counts containers open in the
 * DOCUMENT and degrades past them, while this counts the quote markers and
 * bullets a single line spells before its content. A line can spell far more of
 * them than the document cap will ever build, so the cap never bounded this
 * walk.
 *
 * {@see \MarkupCarve\Carve\Parser\BlockParser::MAX_LINE_PREFIX_DEPTH} carries
 * the derivation of the number.
 */
class ContainerPrefixDepthExceededException extends RuntimeException
{
    /**
     * @param int $limit The depth bound that was reached.
     */
    public function __construct(public readonly int $limit)
    {
        parent::__construct(sprintf(
            'A single line spells more than %d container prefix elements, past the depth '
                . 'this parser reads them at. No document nests that far - the parser stops '
                . 'building containers at %d - so shorten the line\'s prefix rather than '
                . 'raising the bound.',
            $limit,
            BlockParser::MAX_NESTING_DEPTH,
        ));
    }
}
