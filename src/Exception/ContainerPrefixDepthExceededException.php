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
 * DOCUMENT and degrades past them, while this counts how deep a single line's
 * prefix is read before its content. A line can spell far more prefix than the
 * document cap will ever build, so the cap never bounded this walk.
 *
 * IT COUNTS LEVELS, NOT MARKERS, and the difference is not a rounding error: a
 * RUN of list markers is peeled by a loop and costs ONE level, because that is
 * what carve-php#1426 and carve-php#1442 settled for that walk. So `- ` a
 * hundred thousand times reads one level deep and parses, while `> - ` repeated
 * alternates and reads two levels per pair. Counting markers instead would
 * refuse the first, which is safe today and has its own bound already.
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
            'One line\'s container prefix nests more than %d levels deep, past the depth '
                . 'this parser reads it at. No document nests that far - the parser stops '
                . 'building containers at %d - so shorten the line\'s prefix rather than '
                . 'raising the bound.',
            $limit,
            BlockParser::MAX_NESTING_DEPTH,
        ));
    }
}
