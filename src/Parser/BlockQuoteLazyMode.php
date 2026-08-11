<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Mutually exclusive structural mode of the block-quote lazy-line tracker.
 */
enum BlockQuoteLazyMode
{
    case Content;
    case CodeFence;
    case CommentFence;
    case Div;
}
