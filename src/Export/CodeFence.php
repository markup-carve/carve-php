<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

use MarkupCarve\Carve\OptionError;

/**
 * A code fence that the fenced content cannot close.
 */
final class CodeFence
{
    /**
     * The shortest fence of `$marker`, three at least, that is longer than every run of it opening a line of
     * `$content` (after up to three spaces), so no content line closes the fence.
     *
     * @param string $content
     * @param string $marker A backtick or a tilde.
     *
     * @throws \MarkupCarve\Carve\OptionError For another marker.
     *
     * @return string
     */
    public static function safe(string $content, string $marker = '`'): string
    {
        if ($marker !== '`' && $marker !== '~') {
            throw new OptionError('safeCodeFence: the marker is a backtick or a tilde');
        }
        $longest = 0;
        if (preg_match_all('/^ {0,3}(' . preg_quote($marker, '/') . '+)/m', $content, $m) > 0) {
            foreach ($m[1] as $run) {
                $longest = max($longest, strlen($run));
            }
        }

        return str_repeat($marker, max(3, $longest + 1));
    }
}
