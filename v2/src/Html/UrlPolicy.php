<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

use MarkupCarve\Carve2\Generated\Tables;
use MarkupCarve\Carve2\Options;

/**
 * The scheme test of C2-H-159 and C2-H-160, public for every consumer that writes a URL sink (C2-H-173).
 */
final class UrlPolicy
{
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
        if (self::$probe === null) {
            $class = '';
            foreach (Tables::URL_PROBE_REMOVES as [$from, $to]) {
                $class .= sprintf('\x{%X}', $from) . ($to > $from ? sprintf('-\x{%X}', $to) : '');
            }
            self::$probe = '/[' . $class . ']+/u';
        }
        $value = Escape::clean($value);
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
     * @param \MarkupCarve\Carve2\Options $opts
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
