<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * @internal
 */
final class HtmlLineState
{
    public static function endsInsideTag(string $line, bool $inTag): bool
    {
        $offset = 0;
        while (true) {
            if ($inTag) {
                $close = strpos($line, '>', $offset);
                if ($close === false) {
                    return true;
                }
                $inTag = false;
                $offset = $close + 1;
            }
            $open = strpos($line, '<', $offset);
            if ($open === false) {
                return false;
            }
            $next = $line[$open + 1] ?? '';
            $inTag = $next === '/' || ($next >= 'A' && $next <= 'Z') || ($next >= 'a' && $next <= 'z');
            $offset = $open + 1;
        }
    }
}
