<?php

declare(strict_types=1);

namespace Carve\Renderer;

/**
 * Defines how soft breaks (single newlines in source) are rendered.
 *
 * This applies only to newlines that remain INSIDE a paragraph after block
 * parsing. Paragraph interruption runs first: a line that begins with a block
 * marker (`- `/`* ` bullet, `>`, `#`, a valid table row, a fence) is parsed as
 * its own block, so it never becomes a soft break. Break mode therefore cannot
 * turn such a line into a <br>; backslash-escape the marker (`\- ...`) to keep
 * it inline. For an explicit, mode-independent line break use a trailing `\`
 * (a hard break), which always renders as <br>.
 */
enum SoftBreakMode: string
{
    /**
     * Render as newline character in HTML source (default for HtmlRenderer)
     * Not visible in browser since HTML collapses whitespace
     */
    case Newline = 'newline';

    /**
     * Render as a space character
     * Not visible in browser (same as newline due to whitespace collapsing)
     */
    case Space = 'space';

    /**
     * Render as <br> tag (visible line break in browser)
     * Useful for poetry, addresses, or when preserving line breaks matters.
     * Reliable only for lines that do NOT begin with a block marker; see the
     * enum-level note on interaction with paragraph interruption.
     */
    case Break = 'br';
}
