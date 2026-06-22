<?php

declare(strict_types=1);

namespace Carve\Util;

/**
 * String utility functions
 */
final class StringUtil
{
    /**
     * Find a safe code fence marker that doesn't conflict with content
     *
     * Returns backticks (` or ```) that don't appear in the content,
     * extending the marker length as needed.
     *
     * @param string $content The content that will be fenced
     * @param int $minTicks Minimum number of backticks (1 for inline, 3 for blocks)
     *
     * @return string Safe fence marker
     */
    public static function findSafeCodeFence(string $content, int $minTicks = 1): string
    {
        $maxRun = 0;
        $currentRun = 0;
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            if ($content[$i] === '`') {
                $currentRun++;
                if ($currentRun > $maxRun) {
                    $maxRun = $currentRun;
                }

                continue;
            }

            $currentRun = 0;
        }

        return str_repeat('`', max($minTicks, $maxRun + 1));
    }

    /**
     * Escape string for safe HTML output (attributes and text content)
     *
     * Escapes <, >, &, and quotes. Also converts the internal nbsp placeholder
     * (U+E000) to &nbsp; entity.
     */
    public static function escapeHtml(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }
}
