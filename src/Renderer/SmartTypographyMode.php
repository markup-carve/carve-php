<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * Controls whether smart typography renders as its glyph or as the source run
 * the author typed.
 *
 * Smart typography is an AST node carrying both halves, so a renderer can emit
 * either. Presentation output wants the glyph; output written for a machine to
 * read - a corpus fed to a language model, a diff-stable artifact - is usually
 * better off with the characters that were actually typed, because the glyph is
 * a presentation choice the consumer did not ask for and cannot undo.
 */
enum SmartTypographyMode: string
{
    /** Render the resolved glyph: `...` becomes an ellipsis. The default. */
    case Glyph = 'glyph';

    /** Render the author's source run: `...` stays three periods. */
    case Source = 'source';
}
