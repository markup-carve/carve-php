<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\ContentNodeInterface;

/**
 * Inline literal (grammar PART 9 §27).
 *
 * A `!` prefix on a verbatim code span, mirroring the `$`-math prefix:
 * `` !`content` ``. The content is captured verbatim by the backtick run
 * (like a code span, so no inline construct and no smart typography apply
 * inside), HTML-escaped on output, and emitted by EVERY renderer -- never
 * dropped or target-routed the way raw inline (`{=format}`, §20) is. The
 * `<code>` wrapper is dropped because it is prose, not code: with no further
 * attribute block the node emits bare escaped text (no element); with a
 * trailing attribute block it emits a `<span>` carrying them.
 */
class LiteralInline extends InlineNode implements ContentNodeInterface
{
    public function __construct(protected string $content = '')
    {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getType(): string
    {
        return 'literal_inline';
    }
}
