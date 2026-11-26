<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveException;

/**
 * An editor source or change an `EditorSession` refuses: invalid UTF-8, or a change that is malformed, out of order,
 * overlapping or splits a UTF-8 scalar. carve-js throws `EditorChangeError` for the same input.
 */
final class EditorChangeError extends InvalidArgumentException implements CarveException
{
}
