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
     * The content column of the body a description or note lead opened the span in.
     */
    public ?int $openOwner = null;

    /**
     * @var array{char: string, length: int}|null
     */
    public ?array $openCode = null;

    public bool $atBlockStart = true;

    /**
     * The column of the last scanned list marker line, whose item a deeper marker line nests in.
     */
    public ?int $markerColumn = null;

    /**
     * The scanned nonblank lines as `[column, opens an entry]`, with increasing columns.
     *
     * @var array<int, array{0: int, 1: bool}>
     */
    public array $entryColumns = [];

    /**
     * The scanned list items still open as `[marker column, content column]`, innermost last.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    public array $itemColumns = [];
}
