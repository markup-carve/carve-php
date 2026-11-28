<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * The cursor of a comparison of two inline lists: the next node of each, and the offset in a text of each.
 */
final class Cursor
{
    public int $i = 0;

    public int $vi = 0;

    public int $j = 0;

    public int $aj = 0;

    /**
     * The last node of the expected list that started where a node of the parse starts, or -1.
     */
    public int $anchor = -1;

    /**
     * The byte offset of that start.
     */
    public int $anchorByte = 0;
}
