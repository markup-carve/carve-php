<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Html\AttrWriter;
use MarkupCarve\Carve\Html\UrlPolicy;

/**
 * Internal: the authored attributes a standard extension writes on its own element, filtered like the carve-js
 * `authorAttrs` (no event handlers, no unsafe names, URL values through the policy, no active CSS).
 */
final class AuthorAttrs
{
    /**
     * @var array<string, true>
     */
    private const URL_NAMES = [
        'href' => true,
        'src' => true,
        'poster' => true,
        'action' => true,
        'formaction' => true,
        'xlink:href' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const BLOCKED = [
        'srcdoc' => true,
        'srcset' => true,
        'nonce' => true,
        'integrity' => true,
        'formaction' => true,
        'action' => true,
    ];

    /**
     * @var array<string, string>
     */
    private const NAMED_REFS = [
        'colon' => ':',
        'semi' => ';',
        'sol' => '/',
        'tab' => "\t",
        'newline' => "\n",
        'lpar' => '(',
        'rpar' => ')',
        'amp' => '&',
        'quot' => '"',
        'apos' => "'",
        'nbsp' => "\u{a0}",
    ];

    /**
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param \MarkupCarve\Carve\Extension\RenderHost $host
     * @param array<string> $consumed Lowercase keys the renderer reads and does not write.
     *
     * @return string
     */
    public static function write(?Attrs $attrs, RenderHost $host, array $consumed = []): string
    {
        if ($attrs === null) {
            return '';
        }
        $out = '';
        if ($attrs->id !== null) {
            $out .= ' id="' . RenderHost::escapeAttribute($attrs->id) . '"';
        }
        if ($attrs->classes !== []) {
            $out .= ' class="' . RenderHost::escapeAttribute(implode(' ', $attrs->classes)) . '"';
        }
        foreach ($attrs->pairs as [$name, $value]) {
            $out .= self::pair($name, $value, $host, $consumed);
        }

        return $out;
    }

    /**
     * @param string $name
     * @param string $value
     * @param \MarkupCarve\Carve\Extension\RenderHost $host
     * @param array<string> $consumed
     */
    private static function pair(string $name, string $value, RenderHost $host, array $consumed): string
    {
        $lower = strtolower($name);
        if (
            preg_match('/^[a-z_:][\w:.-]*$/i', $name) !== 1 || str_starts_with($lower, 'on') || isset(self::BLOCKED[$lower])
            || in_array($lower, $consumed, true)
        ) {
            return '';
        }
        if (isset(self::URL_NAMES[$lower]) && !$host->isUrlAllowed($value) || $lower === 'style' && self::styleIsDangerous($value)) {
            return ' ' . $name . '=""';
        }

        return ' ' . $name . '="' . RenderHost::escapeAttribute(self::authoredValue($lower, $value)) . '"';
    }

    /**
     * C2-H-161: an authored value passes the tests of the core renderer, whatever the key: a denied scheme before the
     * first colon, of the whole value or of one entry of a URL list, and active CSS (C2-H-162).
     */
    private static function authoredValue(string $lower, string $value): string
    {
        if ($lower !== 'style' && !str_contains($value, ':')) {
            return $value;
        }
        if (UrlPolicy::authoredValueFails($value)) {
            return '';
        }
        if (isset(Tables::URL_LIST_ATTRIBUTES[$lower])) {
            $sep = Tables::URL_LIST_ATTRIBUTES[$lower] ? '/[\s,]+/' : '/\s+/';
            foreach (preg_split($sep, $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (UrlPolicy::authoredValueFails($token)) {
                    return '';
                }
            }
        }

        return $lower === 'style' && AttrWriter::styleFails($value) ? '' : $value;
    }

    public static function value(?Attrs $attrs, string $name): ?string
    {
        foreach ($attrs->pairs ?? [] as [$key, $value]) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }

        return null;
    }

    public static function withClass(?Attrs $attrs, string $base): Attrs
    {
        $out = $attrs === null ? new Attrs() : clone $attrs;
        array_unshift($out->classes, $base);

        return $out;
    }

    /**
     * The attributes with a key added unless one of that name is authored.
     */
    public static function withDefault(Attrs $attrs, string $name, string $value): Attrs
    {
        if (self::value($attrs, $name) !== null) {
            return $attrs;
        }
        $out = clone $attrs;
        $out->pairs[] = [$name, $value];

        return $out;
    }

    /**
     * A style value that can fetch or execute, after entity and CSS escapes are decoded.
     */
    public static function styleIsDangerous(string $value): bool
    {
        $value = (string)preg_replace('~/\*.*?\*/~s', '', $value);
        $value = (string)preg_replace_callback('/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', static function (array $m): string {
            $body = $m[1];
            if ($body[0] === '#') {
                $cp = strtolower($body[1] ?? '') === 'x' ? (int)hexdec(substr($body, 2)) : (int)substr($body, 1);

                return $cp <= 0x10FFFF ? mb_chr($cp, 'UTF-8') : $m[0];
            }

            return self::NAMED_REFS[strtolower($body)] ?? $m[0];
        }, $value);
        $value = (string)preg_replace_callback('/\\\\([0-9a-f]{1,6}\s?|.)/is', static function (array $m): string {
            if (ctype_xdigit($m[1][0])) {
                $cp = (int)hexdec(trim($m[1]));

                return $cp <= 0x10FFFF ? (string)mb_chr($cp, 'UTF-8') : '';
            }

            return $m[1];
        }, $value);
        $compact = (string)preg_replace('/\s+/u', '', strtolower($value));
        foreach (['expression(', 'url(', '@import', 'behavior:', '-moz-binding', 'javascript:'] as $needle) {
            if (str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }
}
