<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\Attrs;

/**
 * A list marker as read from its line (C2-B-033 to C2-B-046).
 */
final class Marker
{
    public string $key = '';

    public string $char = '';

    public string $style = '';

    public string $delimiter = '';

    public int $value = 0;

    public string $letters = '';

    public int $roman = 0;

    public int $end = 0;

    public int $content = 0;

    public int $box = 0;

    public ?Attrs $attrs = null;

    public ?string $task = null;

    /**
     * @param bool $ordered
     */
    public function __construct(public bool $ordered)
    {
    }
}
