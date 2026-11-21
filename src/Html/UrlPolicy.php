<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Options;

/**
 * The scheme test of C2-H-159 and C2-H-160, public for every consumer that writes a URL sink (C2-H-173).
 */
final class UrlPolicy
{
    /**
     * The bytes of the removed ranges in ASCII (U+0000 to U+0020, U+007F) and every byte outside ASCII:
     * a value without them is its own probe, and Escape::clean() leaves it as it is.
     *
     * @var string
     */
    private const PROBE_SPECIAL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x1"
            . "2\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20\x7f\x80\x81\x82\x"
            . "83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f\x90\x91\x92\x93\x94\x95"
            . "\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa"
            . "8\xa9\xaa\xab\xac\xad\xae\xaf\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\x"
            . "bb\xbc\xbd\xbe\xbf\xc0\xc1\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd"
            . "\xce\xcf\xd0\xd1\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9\xda\xdb\xdc\xdd\xde\xdf\xe"
            . "0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xeb\xec\xed\xee\xef\xf0\xf1\xf2\x"
            . "f3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xfb\xfc\xfd\xfe\xff";

    private static ?string $probe = null;

    /**
     * @var array<string, true>|null
     */
    private static ?array $deny = null;

    /**
     * @return array<string> The default deny list of `urlPolicy` (data/options.json).
     */
    public static function defaultDenyList(): array
    {
        return Tables::OPTIONS['urlPolicy']['default']['deny'];
    }

    /**
     * C2-H-159: the copy a destination is tested on, with every `probe_removes` character removed.
     *
     * @param string $value
     *
     * @return string
     */
    public static function probe(string $value): string
    {
        if (strcspn($value, self::PROBE_SPECIAL) === strlen($value)) {
            return $value;
        }
        if (self::$probe === null) {
            $class = '';
            foreach (Tables::URL_PROBE_REMOVES as [$from, $to]) {
                $class .= sprintf('\x{%X}', $from) . ($to > $from ? sprintf('-\x{%X}', $to) : '');
            }
            self::$probe = '/[' . $class . ']+/u';
        }
        $value = Escape::clean($value);
        // C2-H-159: one character class run, linear; only for a URL the strcspn above flags.
        $out = preg_replace(self::$probe, '', $value);

        return is_string($out) ? $out : $value;
    }

    /**
     * @param string $url
     *
     * @return string|null The lowercased scheme, or null for a destination without one.
     */
    public static function schemeOf(string $url): ?string
    {
        $probe = self::probe($url);
        $len = strspn($probe, Tables::AUTOLINK_SCHEME_REST);
        if ($len === 0 || strspn($probe, Tables::AUTOLINK_SCHEME_FIRST, 0, 1) !== 1 || ($probe[$len] ?? '') !== ':') {
            return null;
        }

        return strtolower(substr($probe, 0, $len));
    }

    /**
     * C2-H-160: whether a destination passes the policy of the options.
     *
     * @param string $url
     * @param \MarkupCarve\Carve\Options $opts
     *
     * @return bool
     */
    public static function passes(string $url, Options $opts): bool
    {
        $scheme = self::schemeOf($url);
        if ($scheme === null) {
            return true;
        }

        return $opts->allowList ? isset($opts->schemes[$scheme]) : !isset($opts->schemes[$scheme]);
    }

    /**
     * C2-H-161: an authored value whose text before the first colon is a scheme of the default deny list.
     *
     * @param string $value
     *
     * @return bool
     */
    public static function authoredValueFails(string $value): bool
    {
        $colon = strpos($value, ':');
        if ($colon === false) {
            return false;
        }
        if (self::$deny === null) {
            self::$deny = array_fill_keys(self::defaultDenyList(), true);
        }

        return isset(self::$deny[strtolower(self::probe(substr($value, 0, $colon)))]);
    }
}
