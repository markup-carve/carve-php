<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

/**
 * The fields of a code fence or div opener line (C2-B-108, C2-B-119).
 */
final class Opener
{
    public bool $bare = false;

    public ?string $raw = null;

    public string $syntax = '';

    public string $sigil = '';

    public ?string $name = null;

    /**
     * Start and end offsets of the quoted title text.
     *
     * @var array{0: int, 1: int}|null
     */
    public ?array $title = null;

    public ?string $label = null;

    /**
     * Where further words start (C2-B-122), or -1.
     */
    public int $extra = -1;

    /**
     * The offset behind the language word, raw format or div name, or -1.
     */
    public int $wordEnd = -1;

    /**
     * @param int $run
     */
    public function __construct(public int $run)
    {
    }
}
