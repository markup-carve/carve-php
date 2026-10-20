<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final class MarkdownImportWork
{
    public static bool $on = false;

    public static int $inlineLines = 0;

    public static int $inlineBytes = 0;

    public static int $setextVisits = 0;

    public static int $paragraphVisits = 0;

    public static int $shiftLines = 0;

    public static int $shiftBytes = 0;

    public static int $assemblyLines = 0;

    public static int $assemblyBytes = 0;

    public static int $layoutWork = 0;

    public static function reset(): void
    {
        self::$inlineLines = 0;
        self::$inlineBytes = 0;
        self::$setextVisits = 0;
        self::$paragraphVisits = 0;
        self::$shiftLines = 0;
        self::$shiftBytes = 0;
        self::$assemblyLines = 0;
        self::$assemblyBytes = 0;
        self::$layoutWork = 0;
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        return [
            'inlineLines' => self::$inlineLines,
            'inlineBytes' => self::$inlineBytes,
            'setextVisits' => self::$setextVisits,
            'paragraphVisits' => self::$paragraphVisits,
            'shiftLines' => self::$shiftLines,
            'shiftBytes' => self::$shiftBytes,
            'assemblyLines' => self::$assemblyLines,
            'assemblyBytes' => self::$assemblyBytes,
            'layoutWork' => self::$layoutWork,
        ];
    }
}
