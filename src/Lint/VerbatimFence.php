<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * Where a verbatim block opens and closes, for the passes that read the SOURCE.
 *
 * Every source-scanning rule has to skip fenced code and raw blocks, because a
 * markup example inside one is the construct being written ABOUT, not a
 * construct in the document. This is where the delimiter is recognized, once,
 * so a second scanner cannot disagree with the first about what opens a fence.
 *
 * The AST-walking passes do not need it: a fence's content is one node's text
 * there, and they never look inside it.
 */
class VerbatimFence
{
    /**
     * The fence delimiter opening or closing a verbatim block, if this line is
     * one; null otherwise.
     */
    public static function delimiter(string $line): ?string
    {
        return preg_match('/^\s*(`{3,}|~{3,})/', $line, $matches) === 1 ? $matches[1] : null;
    }
}
