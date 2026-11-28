<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Cli;

final class StdinReader
{
    /**
     * @param resource $in
     */
    public static function read($in): string|false
    {
        $readFailed = false;
        set_error_handler(static function () use (&$readFailed): bool {
            $readFailed = true;

            return true;
        });
        try {
            $source = stream_get_contents($in);
        } finally {
            restore_error_handler();
        }

        return $readFailed ? false : $source;
    }
}
