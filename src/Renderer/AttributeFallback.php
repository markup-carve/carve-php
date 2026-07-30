<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * Controls what the Markdown target does with attributes Markdown cannot spell.
 *
 * Markdown has no syntax for a block container or for attributes on an image, so
 * `{#id .class data-*}` has nowhere to go. `Drop` discards them, which is right
 * for human-facing export. `Html` keeps them as raw HTML - a `<div>` wrapper and
 * an `<img>` tag - the same degradation an inline mark already gets, which is
 * what a consumer using Markdown as an interchange format needs.
 */
enum AttributeFallback: string
{
    case Drop = 'drop';
    case Html = 'html';
}
