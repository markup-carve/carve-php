<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

final class PlanCharacterClasses
{
    /**
     * @var int
     */
    public const SCHEME_FIRST = 1;

    /**
     * @var int
     */
    public const SCHEME_REST = 2;

    /**
     * @var int
     */
    public const URL_BODY = 4;

    /**
     * @var int
     */
    public const EMAIL_LOCAL = 8;

    /**
     * @var int
     */
    public const EMAIL_DOMAIN = 16;

    /**
     * @var int
     */
    public const EMAIL_LAST = 32;

    /**
     * @var int
     */
    public const EXTENSION_FIRST = 256;

    /**
     * @var int
     */
    public const EXTENSION_REST = 512;

    /**
     * @var int
     */
    public const SYMBOL_FIRST = 1024;

    /**
     * @var int
     */
    public const SYMBOL_REST = 2048;

    /**
     * @var int
     */
    public const MENTION = 4096;

    /**
     * @var int
     */
    public const KEY_REST = 32768;

    /**
     * @var int
     */
    public const QUOTE_OPENS_AFTER = 1048576;

    /**
     * @var array<int, int>
     */
    public const CLASSES = [
        0, 0, 0, 0, 0, 0, 0, 0,
        0, 262144, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0,
        262144, 2097156, 2359296, 4, 4, 4, 4, 2359300,
        3145732, 4, 524292, 2624542, 524292, 2670238, 2097182, 524292,
        179870, 179870, 179870, 179870, 179870, 179870, 179870, 179870,
        179870, 179870, 1048580, 4, 2097152, 3670020, 2097152, 4,
        4, 262143, 262143, 262143, 262143, 262143, 262143, 262143,
        262143, 262143, 262143, 262143, 262143, 262143, 262143, 262143,
        262143, 262143, 262143, 262143, 262143, 262143, 262143, 262143,
        262143, 262143, 262143, 1048580, 262144, 4, 524288, 588700,
        0, 262143, 262143, 262143, 262143, 262143, 262143, 262143,
        262143, 262143, 262143, 262143, 262143, 262143, 262143, 262143,
        262143, 262143, 262143, 262143, 262143, 262143, 262143, 262143,
        262143, 262143, 262143, 1048576, 262144, 262144, 524292, 0,
    ];

    public static function contains(int $code, int $mask): bool
    {
        return $code >= 0 && $code < 128 && (self::CLASSES[$code] & $mask) !== 0;
    }
}
