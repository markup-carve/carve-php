<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final class MarkdownReferenceDefinition
{
    /**
     * @return array{label: string, target: string, lines: int, complex: bool}|null
     */
    public static function read(string $source, int $offset = 0): ?array
    {
        if (preg_match('/\G {0,3}\[/', $source, $opener, offset: $offset) !== 1) {
            return null;
        }
        $at = $offset + strlen($opener[0]);
        $labelStart = $at;
        $length = strlen($source);
        for (; $at < $length; $at++) {
            if ($source[$at] === '\\' && self::escaped($source[$at + 1] ?? '')) {
                $at++;
            } elseif ($source[$at] === '[') {
                return null;
            } elseif ($source[$at] === ']') {
                break;
            }
        }
        $label = substr($source, $labelStart, $at - $labelStart);
        if (trim($label) === '' || preg_match('/\n[ \t]*\n/', $label) === 1 || strlen($label) > 999 || ($source[$at + 1] ?? '') !== ':') {
            return null;
        }
        $at += 2;
        self::spaces($source, $at);
        if (($source[$at] ?? '') === "\n") {
            $at++;
            self::spaces($source, $at);
        }
        $destinationStart = $at;
        if (($source[$at] ?? '') === '<') {
            for ($at++; $at < $length; $at++) {
                if ($source[$at] === '\\' && self::escaped($source[$at + 1] ?? '')) {
                    $at++;
                } elseif ($source[$at] === '<' || $source[$at] === "\n") {
                    return null;
                } elseif ($source[$at] === '>') {
                    break;
                }
            }
            if (($source[$at] ?? '') !== '>') {
                return null;
            }
            $at++;
        } else {
            $depth = 0;
            for (; $at < $length && ord($source[$at]) > 32 && ord($source[$at]) !== 127; $at++) {
                if ($source[$at] === '\\' && self::escaped($source[$at + 1] ?? '')) {
                    $at++;
                } elseif ($source[$at] === '(') {
                    $depth++;
                    if ($depth > 32) {
                        return null;
                    }
                } elseif ($source[$at] === ')') {
                    $depth--;
                    if ($depth < 0) {
                        return null;
                    }
                }
            }
            if ($depth !== 0 || $at === $destinationStart) {
                return null;
            }
        }
        $destination = substr($source, $destinationStart, $at - $destinationStart);
        $destinationEnd = $at;
        $finish = static function (int $end, string $title = '') use ($source, $label, $destination, $offset): array {
            return [
                'label' => $label,
                'target' => $destination . $title,
                'lines' => substr_count(substr($source, $offset, $end - $offset), "\n") + 1,
                'complex' => str_contains($label, "\n") || str_contains($label, '\\]') || str_contains($title, "\n"),
            ];
        };
        $withoutTitle = static function () use ($source, $destinationEnd, $finish): ?array {
            $end = $destinationEnd;
            self::spaces($source, $end);

            return !isset($source[$end]) || $source[$end] === "\n" ? $finish($end) : null;
        };
        self::spaces($source, $at);
        if (($source[$at] ?? '') === "\n") {
            $at++;
            self::spaces($source, $at);
        }
        $quote = $source[$at] ?? '';
        if ($at === $destinationEnd || !in_array($quote, ['"', "'", '('], true)) {
            return $withoutTitle();
        }
        $close = $quote === '(' ? ')' : $quote;
        $titleStart = ++$at;
        for (; $at < $length; $at++) {
            if ($source[$at] === '\\' && self::escaped($source[$at + 1] ?? '')) {
                $at++;
            } elseif (($quote === '(' && $source[$at] === '(') || ($source[$at] === "\n" && preg_match('/\G\n[ \t]*\n/', $source, offset: $at) === 1)) {
                return $withoutTitle();
            } elseif ($source[$at] === $close) {
                break;
            }
        }
        if (($source[$at] ?? '') !== $close) {
            return $withoutTitle();
        }
        $title = substr($source, $titleStart - 1, ++$at - $titleStart + 1);
        self::spaces($source, $at);
        if (isset($source[$at]) && $source[$at] !== "\n") {
            return $withoutTitle();
        }

        return $finish($at, ' ' . $title);
    }

    private static function escaped(string $character): bool
    {
        return $character !== '' && preg_match('/[!-\/:-@\[-`{-~]/', $character) === 1;
    }

    private static function spaces(string $source, int &$at): void
    {
        while (isset($source[$at]) && ($source[$at] === ' ' || $source[$at] === "\t")) {
            $at++;
        }
    }
}
