<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;

/**
 * The canonical source carried by an abbreviation template (C2-H-179, C2-W-047 to C2-W-055).
 */
final class AbbreviationSource
{
    /**
     * @param array<\MarkupCarve\Carve\Node> $run
     * @param \MarkupCarve\Carve\Html\RenderLog $log
     *
     * @return string
     */
    public static function run(array $run, RenderLog $log): string
    {
        $source = [];
        foreach ($run as $node) {
            $key = $node->f['key'] ?? '';
            $expansion = $node->f['expansion'] ?? '';
            $key = is_string($key) ? $key : '';
            $expansion = is_string($expansion) ? $expansion : '';
            if (
                preg_match('/^[A-Za-z0-9]+$/D', $key) !== 1 || $expansion === ''
                || strpbrk($expansion, "\r\n") !== false || str_ends_with($expansion, ' ') || str_ends_with($expansion, "\t")
            ) {
                $log->loss('field-unspellable', 'warning', ['what' => 'this abbreviation definition exactly']);
            }
            // C2-W-055: each line break becomes one space in the nearest spelling.
            $expansion = rtrim((string)preg_replace('/\r\n|\r|\n/', ' ', $expansion), " \t");
            $line = self::attributes($node, $log);
            $source[] = ($line === '' ? '' : $line . "\n") . '*[' . $key . ']: ' . $expansion;
        }

        return implode("\n\n", $source);
    }

    /**
     * C2-W-048 to C2-W-050: one canonical attribute block for a node.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Html\RenderLog $log
     *
     * @return string
     */
    public static function attributes(Node $node, RenderLog $log): string
    {
        $parts = [];
        $attrs = $node->f['attrs'] ?? null;
        if ($attrs instanceof Attrs) {
            if ($attrs->id !== null && strpbrk($attrs->id, "\r\n") === false) {
                $parts[] = self::name($attrs->id) ? '#' . $attrs->id : 'id=' . self::quoted($attrs->id);
            } elseif ($attrs->id !== null) {
                self::lostAttribute($log);
            }
            foreach ($attrs->classes as $class) {
                $part = self::classPart($class, $log);
                if ($part !== null) {
                    $parts[] = $part;
                }
            }
        }
        if (is_string($node->f['lang'] ?? null) && preg_match('/^(?:[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*)?$/D', $node->f['lang']) === 1) {
            $parts[] = ':' . $node->f['lang'];
        } elseif (isset($node->f['lang'])) {
            self::lostAttribute($log);
        }
        if ($attrs instanceof Attrs) {
            foreach ($attrs->pairs as [$key, $value]) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $key) !== 1 || strpbrk($value, "\r\n") !== false) {
                    self::lostAttribute($log);

                    continue;
                }
                $parts[] = self::keyPart($key, $value);
            }
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    /**
     * @param string $class
     * @param \MarkupCarve\Carve\Html\RenderLog $log
     *
     * @return string|null
     */
    private static function classPart(string $class, RenderLog $log): ?string
    {
        if ($class === '' || strpbrk($class, " \t\r\n") !== false) {
            self::lostAttribute($log);
        }
        if (strpbrk($class, "\r\n") !== false) {
            return null;
        }

        return self::name($class) ? '.' . $class : 'class=' . self::quoted($class);
    }

    /**
     * C2-W-117: a supplied field with no exact source spelling reports a loss.
     *
     * @param \MarkupCarve\Carve\Html\RenderLog $log
     *
     * @return void
     */
    private static function lostAttribute(RenderLog $log): void
    {
        $log->loss('field-unspellable', 'warning', ['what' => 'this abbreviation attribute exactly']);
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return string
     */
    private static function keyPart(string $key, string $value): string
    {
        if ($value === '') {
            return str_starts_with($key, '_') ? $key . '=""' : $key;
        }

        return $key . '=' . (strcspn($value, " \t\"'\\|}") === strlen($value) ? $value : self::quoted($value));
    }

    /**
     * @param string $value
     *
     * @return bool
     */
    private static function name(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function quoted(string $value): string
    {
        $out = '"';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $next = $value[$i + 1] ?? '"';
            if ($char === '"' || ($char === '\\' && ctype_punct($next))) {
                $out .= '\\';
            }
            $out .= $char;
        }

        return $out . '"';
    }
}
