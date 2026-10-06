<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * A comment-span scan through appended entries and newline-extended tail text.
 *
 * @internal
 */
final class CollectedCommentSpan
{
    public int $entry = 0;

    public int $tailLength = 0;

    public bool $tailSeen = false;

    public ?int $openComment = null;

    /**
     * @var array{char: string, length: int}|null
     */
    public ?array $openCode = null;

    public bool $atBlockStart = true;
}
