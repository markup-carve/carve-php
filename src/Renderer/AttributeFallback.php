<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * Selects how the Markdown target handles attributes it cannot spell.
 *
 * `Drop` explicitly discards them. `Html` preserves them on containers,
 * images and inline marks through HTML tags. Without an explicit selection,
 * inline marks preserve their attributes while containers and images keep
 * their ordinary Markdown spelling.
 */
enum AttributeFallback: string
{
    case Drop = 'drop';
    case Html = 'html';
}
