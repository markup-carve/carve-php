<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use MarkupCarve\Carve\Parser\Utility\LayoutWork;

final class ImportWork
{
    public static bool $on = false;

    private static int $depth = 0;

    public static int $inlineLines = 0;

    public static int $inlineBytes = 0;

    public static int $setextVisits = 0;

    public static int $paragraphVisits = 0;

    public static int $shiftLines = 0;

    public static int $shiftBytes = 0;

    public static int $assemblyLines = 0;

    public static int $assemblyBytes = 0;

    public static int $layoutWork = 0;

    public static int $quoteVisits = 0;

    public static int $quoteArraySlots = 0;

    public static function enter(): object
    {
        if (self::$depth++ === 0) {
            self::reset();
        }

        return new class {
            public function __destruct()
            {
                ImportWork::leave();
            }
        };
    }

    public static function leave(): void
    {
        self::$depth--;
    }

    public static function layoutTotal(): int
    {
        return LayoutWork::total() + LayoutWork::$prescan + LayoutWork::$fencePrescan
            + LayoutWork::$commentPrescan + LayoutWork::$referencePrescan
            + LayoutWork::$footnotePrescan;
    }

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
        self::$quoteVisits = 0;
        self::$quoteArraySlots = 0;
    }

    // phpcs:disable PhpCollective.Commenting.DocComment
    /** @return array<string, int> */
    // phpcs:enable PhpCollective.Commenting.DocComment
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
            'quoteVisits' => self::$quoteVisits,
            'quoteArraySlots' => self::$quoteArraySlots,
        ];
    }
}
