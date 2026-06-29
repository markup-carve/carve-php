<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * Controls how soft line breaks render.
 *
 * This only affects newline nodes that remain inside a paragraph after block
 * parsing. Use a trailing backslash or the local `::: \` block for visible hard
 * breaks in source.
 */
enum SoftBreakMode: string
{
    case Newline = 'newline';
    case Space = 'space';
    case Break = 'br';
}
