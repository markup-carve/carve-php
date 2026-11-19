<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-097: spell image alternative text without letting its brackets or raw openers close the image early.
 */
final class AltSource
{
    public static function spell(string $alt): string
    {
        $lone = [];
        $raw = self::rawAtoms($alt, $lone);
        self::unpartnered($alt, $raw, $lone);
        $out = '';
        $length = strlen($alt);
        for ($at = 0; $at < $length; $at++) {
            $char = $alt[$at];
            if ($char === '\\' && ($at + 1 === $length || Text::isPunct($alt[$at + 1]))) {
                $out .= '\\';
            } elseif (isset($lone[$at])) {
                $out .= '\\';
            }
            $out .= $char;
        }

        return $out;
    }

    /**
     * @param string $alt
     * @param array<int, true> $lone
     *
     * @return array<int, true>
     */
    private static function rawAtoms(string $alt, array &$lone): array
    {
        $raw = [];
        $length = strlen($alt);
        for ($at = 0; $at < $length; $at++) {
            $end = self::atomEnd($alt, $at);
            if ($end > 0) {
                for ($part = $at; $part < $end; $part++) {
                    $raw[$part] = true;
                }
                $at = $end - 1;
            } elseif ($end < 0) {
                $lone[$at] = true;
                while ($alt[$at] === '`' && ($alt[$at + 1] ?? '') === '`') {
                    $lone[++$at] = true;
                }
            }
        }

        return $raw;
    }

    private static function atomEnd(string $alt, int $at): int
    {
        $char = $alt[$at];
        $next = $alt[$at + 1] ?? '';
        if ($char === '{' && ($next === '#' || $next === '%')) {
            $close = strpos($alt, $next . '}', $at + 2);

            return $close === false ? -1 : $close + 2;
        }
        if ($char !== '`' || ($alt[$at - 1] ?? '') === '`') {
            return 0;
        }
        $size = strspn($alt, '`', $at);
        $search = $at + $size;
        while (($found = strpos($alt, '`', $search)) !== false) {
            $run = strspn($alt, '`', $found);
            if ($run === $size) {
                return $found + $size;
            }
            $search = $found + $run;
        }

        return -1;
    }

    /**
     * @param string $alt
     * @param array<int, true> $raw
     * @param array<int, true> $lone
     */
    private static function unpartnered(string $alt, array $raw, array &$lone): void
    {
        $open = [];
        $notes = [];
        $length = strlen($alt);
        for ($at = 0; $at < $length; $at++) {
            if (isset($raw[$at])) {
                continue;
            }
            if ($alt[$at] === '[') {
                $open[] = $at;
            } elseif ($alt[$at] === ']') {
                if ($at >= 2 && $alt[$at - 1] === '^' && end($open) === $at - 2) {
                    $lone[$at] = true;
                } elseif ($open !== []) {
                    $opener = array_pop($open);
                    if (($alt[$opener + 1] ?? '') === '^') {
                        $notes[] = $at;
                    }
                } else {
                    $lone[$notes === [] ? $at : array_pop($notes)] = true;
                }
            }
        }
        foreach ($open as $at) {
            $lone[$at] = true;
        }
    }
}
