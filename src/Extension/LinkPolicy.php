<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;
use Normalizer;

/**
 * Internal: the 0.1 `LinkPolicy` decision, with a host read as a WHATWG URL parser reads it, and the autolink scan.
 */
final class LinkPolicy
{
    /**
     * @var string
     */
    private const EMAIL = '[a-zA-Z0-9._%+-]{1,64}@(?:[a-zA-Z0-9-]{1,63}\.)+[a-zA-Z]{2,63}';

    /**
     * @var array<string>
     */
    private array $denied;

    /**
     * @var array<string>|null
     */
    private ?array $allowed;

    /**
     * @var array<string>|null
     */
    private ?array $allowedDomains;

    /**
     * @var array<string>
     */
    private array $deniedDomains;

    private ?string $baseHost;

    /**
     * @param array<string>|null $allowedSchemes
     * @param array<string> $deniedSchemes
     * @param array<string>|null $allowedDomains
     * @param array<string> $deniedDomains
     * @param bool $allowExternal
     * @param bool $allowInternal
     * @param string|null $baseHost
     */
    public function __construct(
        ?array $allowedSchemes,
        array $deniedSchemes,
        ?array $allowedDomains,
        array $deniedDomains,
        private readonly bool $allowExternal,
        private readonly bool $allowInternal,
        ?string $baseHost,
    ) {
        $this->denied = array_map('strtolower', $deniedSchemes);
        $this->allowed = $allowedSchemes === null ? null : array_map('strtolower', $allowedSchemes);
        $this->allowedDomains = $allowedDomains === null ? null : array_map(self::canonicalHost(...), $allowedDomains);
        $this->deniedDomains = array_map(self::canonicalHost(...), $deniedDomains);
        $this->baseHost = $baseHost === null ? null : self::canonicalHost($baseHost);
    }

    /**
     * @param \MarkupCarve\Carve\Node $view
     * @param array<string> $rel
     *
     * @return \MarkupCarve\Carve\Node
     */
    public function apply(Node $view, array $rel): Node
    {
        NavTree::rewrite($view, fn (Node $node): ?array => $this->rewrite($node, $rel), true);

        return $view;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string> $rel
     *
     * @return array<\MarkupCarve\Carve\Node>|null
     */
    private function rewrite(Node $node, array $rel): ?array
    {
        if ($node->type === 'image') {
            return $this->image($node);
        }
        if ($node->type === 'autolink') {
            return $this->autolink($node, $rel);
        }
        $href = $node->f['href'] ?? null;
        if ($node->type !== 'link' || !is_string($href)) {
            return null;
        }
        if (!$this->allows($href)) {
            return $node->children ?? [];
        }
        if ($rel !== []) {
            self::addRel($node, $rel);
        }

        return null;
    }

    /**
     * @return array<\MarkupCarve\Carve\Node>|null
     */
    private function image(Node $node): ?array
    {
        $src = $node->f['src'] ?? null;
        if (!is_string($src) || $this->allows($src)) {
            return null;
        }
        $alt = PlainText::str($node->f['alt'] ?? '');

        return $alt === '' ? [] : [NavTree::text($alt)];
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string> $rel
     *
     * @return array<\MarkupCarve\Carve\Node>|null
     */
    private function autolink(Node $node, array $rel): ?array
    {
        $href = PlainText::str($node->f['href'] ?? '');
        if (!$this->allows($href)) {
            return [NavTree::text(PlainText::str($node->f['text'] ?? $href))];
        }
        if ($rel === []) {
            return null;
        }
        $link = self::asLink($node);
        self::addRel($link, $rel);

        return [$link];
    }

    /**
     * The renderer writes no attributes on an autolink, so a transform that sets one turns it into the link it renders
     * as.
     */
    public static function asLink(Node $node): Node
    {
        $href = PlainText::str($node->f['href'] ?? '');
        $link = Node::at('link', $node->start, $node->end);
        $link->children = [NavTree::text(PlainText::str($node->f['text'] ?? $href))];
        $link->f['href'] = $href;
        if (isset($node->f['attrs'])) {
            $link->f['attrs'] = $node->f['attrs'];
        }

        return $link;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string> $values
     */
    private static function addRel(Node $node, array $values): void
    {
        $split = preg_split('/\s+/u', NavTree::pairOf($node, 'rel') ?? '') ?: [];
        $parts = array_values(array_filter($split, static fn (string $p): bool => $p !== ''));
        foreach ($values as $value) {
            if (!in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }
        NavTree::setPair($node, 'rel', implode(' ', $parts));
    }

    public function allows(string $input): bool
    {
        $url = str_replace(["\t", "\n", "\r"], '', self::trimControls($input));
        if ($url === '') {
            return true;
        }
        $prefix = str_replace('\\', '/', $url);
        if (str_starts_with($url, '#')) {
            return $this->allowInternal;
        }
        if (str_starts_with($prefix, '//')) {
            if ($this->allowed !== null && !in_array('http', $this->allowed, true) && !in_array('https', $this->allowed, true)) {
                return false;
            }
            $host = self::specialUrlHost($prefix);

            return $host !== null && $this->hostAllowed($host);
        }
        if (str_starts_with($prefix, '/') || str_starts_with($prefix, './') || str_starts_with($prefix, '../')) {
            return $this->allowInternal;
        }
        $colon = strpos($url, ':');
        // A path without a scheme, such as `doc/page` or `a/b:c`, is relative and so internal.
        if ($colon === false || strpbrk(substr($url, 0, $colon), '/?#') !== false) {
            return $this->allowInternal;
        }

        return $this->schemeAllowed($url, $colon);
    }

    private function schemeAllowed(string $url, int $colon): bool
    {
        $raw = strtolower(substr($url, 0, $colon));
        $scheme = (string)preg_replace('/[\x{0}-\x{8}\x{e}-\x{1f}\x{7f}-\x{9f}\s]+/u', '', $raw);
        if (in_array($scheme, $this->denied, true) || ($this->allowed !== null && !in_array($raw, $this->allowed, true))) {
            return false;
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            return true;
        }
        $host = self::specialUrlHost(substr($url, $colon + 1));
        if ($host !== null) {
            return $this->hostAllowed($host);
        }

        return $this->deniedDomains === [] && $this->allowedDomains === null && $this->allowExternal;
    }

    private function hostAllowed(string $host): bool
    {
        if (self::inDomains($host, $this->deniedDomains)) {
            return false;
        }
        if ($this->allowedDomains !== null && !self::inDomains($host, $this->allowedDomains)) {
            return false;
        }

        return $this->allowExternal || $host === $this->baseHost;
    }

    /**
     * @param string $host
     * @param array<string> $domains
     */
    private static function inDomains(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private static function trimControls(string $url): string
    {
        $start = 0;
        $end = strlen($url);
        while ($start < $end && ord($url[$start]) <= 32) {
            $start++;
        }
        while ($end > $start && ord($url[$end - 1]) <= 32) {
            $end--;
        }

        return substr($url, $start, $end - $start);
    }

    private static function normalizeHost(string $host): string
    {
        return rtrim(mb_strtolower(str_replace(["\u{3002}", "\u{FF0E}", "\u{FF61}"], '.', $host), 'UTF-8'), '.');
    }

    /**
     * The host of a special URL; `$afterScheme` follows the colon.
     */
    private static function specialUrlHost(string $afterScheme): ?string
    {
        $rest = ltrim($afterScheme, '/\\');
        $end = strcspn($rest, '/\\?#');
        $authority = substr($rest, 0, $end);
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            $host = $close === false ? $authority : substr($authority, 0, $close + 1);
        } else {
            $colon = strpos($authority, ':');
            $host = $colon === false ? $authority : substr($authority, 0, $colon);
        }
        $host = self::normalizeHost(rawurldecode($host));

        return $host === '' ? null : self::whatwgHost($host) ?? $host;
    }

    /**
     * A configured domain, read as the host a URL with it would have.
     */
    private static function canonicalHost(string $domain): string
    {
        $host = self::normalizeHost($domain);

        return $host === '' ? $host : self::whatwgHost($host) ?? $host;
    }

    /**
     * The WHATWG host parser where it decides which host a browser opens: IDNA to ASCII, and the IPv4 number forms
     * (`2130706433` and `0x7f.1` are `127.0.0.1`). Null when the parser refuses the host.
     */
    private static function whatwgHost(string $host): ?string
    {
        if (str_starts_with($host, '[')) {
            return $host;
        }
        if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
            $ascii = self::toAscii($host);
            if ($ascii === null) {
                return null;
            }
            $host = $ascii;
        }
        $parts = explode('.', $host);
        if (end($parts) === '' && count($parts) > 1) {
            array_pop($parts);
        }
        $last = (string)end($parts);
        if (preg_match('/^(?:\d+|0[xX][0-9a-fA-F]*)$/', $last) !== 1) {
            return $host;
        }

        return self::ipv4($parts);
    }

    /**
     * IDNA to ASCII: `idn_to_ascii()` with ext-intl, else NFC, lower case and Punycode per label (RFC 3492).
     */
    private static function toAscii(string $host): ?string
    {
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            return is_string($ascii) && $ascii !== '' ? strtolower($ascii) : null;
        }
        $normalized = Normalizer::normalize(mb_strtolower($host, 'UTF-8'), Normalizer::FORM_C);
        if (!is_string($normalized)) {
            return null;
        }
        $labels = [];
        foreach (explode('.', $normalized) as $label) {
            $labels[] = preg_match('/[^\x00-\x7f]/', $label) === 1 ? 'xn--' . self::punycode($label) : $label;
        }

        return implode('.', $labels);
    }

    /**
     * RFC 3492 Punycode of one label.
     */
    private static function punycode(string $label): string
    {
        $codes = array_map(static fn (string $c): int => (int)mb_ord($c, 'UTF-8'), mb_str_split($label, 1, 'UTF-8'));
        $output = '';
        foreach ($codes as $code) {
            if ($code < 0x80) {
                $output .= mb_chr($code, 'UTF-8');
            }
        }
        $basic = strlen($output);
        $handled = $basic;
        if ($basic > 0) {
            $output .= '-';
        }
        $n = 0x80;
        $delta = 0;
        $bias = 72;
        $total = count($codes);
        while ($handled < $total) {
            $next = PHP_INT_MAX;
            foreach ($codes as $code) {
                if ($code >= $n && $code < $next) {
                    $next = $code;
                }
            }
            $delta += ($next - $n) * ($handled + 1);
            $n = $next;
            foreach ($codes as $code) {
                if ($code < $n) {
                    $delta++;
                }
                if ($code !== $n) {
                    continue;
                }
                $q = $delta;
                for ($k = 36;; $k += 36) {
                    $t = $k <= $bias ? 1 : ($k >= $bias + 26 ? 26 : $k - $bias);
                    if ($q < $t) {
                        break;
                    }
                    $output .= self::digit($t + ($q - $t) % (36 - $t));
                    $q = intdiv($q - $t, 36 - $t);
                }
                $output .= self::digit($q);
                $bias = self::adapt($delta, $handled + 1, $handled === $basic);
                $delta = 0;
                $handled++;
            }
            $delta++;
            $n++;
        }

        return $output;
    }

    private static function digit(int $d): string
    {
        return (string)mb_chr($d < 26 ? $d + 97 : $d + 22, 'UTF-8');
    }

    private static function adapt(int $delta, int $points, bool $first): int
    {
        $delta = $first ? intdiv($delta, 700) : intdiv($delta, 2);
        $delta += intdiv($delta, $points);
        $k = 0;
        while ($delta > 455) {
            $delta = intdiv($delta, 35);
            $k += 36;
        }

        return $k + intdiv(36 * $delta, $delta + 38);
    }

    /**
     * @param array<string> $parts
     */
    private static function ipv4(array $parts): ?string
    {
        if (count($parts) > 4) {
            return null;
        }
        $numbers = [];
        foreach ($parts as $part) {
            $number = self::ipv4Number($part);
            if ($number === null) {
                return null;
            }
            $numbers[] = $number;
        }
        $last = array_pop($numbers);
        foreach ($numbers as $number) {
            if ($number > 255) {
                return null;
            }
        }
        if ($last >= 256 ** (5 - count($parts))) {
            return null;
        }
        $address = $last;
        foreach ($numbers as $at => $number) {
            $address += $number * 256 ** (3 - $at);
        }

        return implode('.', [($address >> 24) & 255, ($address >> 16) & 255, ($address >> 8) & 255, $address & 255]);
    }

    private static function ipv4Number(string $part): ?int
    {
        if ($part === '') {
            return null;
        }
        if (preg_match('/^0[xX]([0-9a-fA-F]*)$/', $part, $m) === 1) {
            return $m[1] === '' ? 0 : (strlen(ltrim($m[1], '0')) > 8 ? null : (int)hexdec($m[1]));
        }
        if (preg_match('/^0[0-7]+$/', $part) === 1) {
            return strlen(ltrim($part, '0')) > 11 ? null : (int)octdec($part);
        }
        if (preg_match('/^\d+$/', $part) === 1) {
            return strlen(ltrim($part, '0')) > 10 ? null : (int)$part;
        }

        return null;
    }

    /**
     * @param array<string> $schemes
     */
    public static function autolinkPattern(array $schemes): ?string
    {
        $urlSchemes = array_values(array_filter($schemes, static fn (string $s): bool => $s !== 'mailto'));
        $urlSchemes = array_map(static fn (string $s): string => preg_quote($s, '/'), $urlSchemes);
        $parts = [];
        if ($urlSchemes !== []) {
            $parts[] = '(?<![\p{L}\p{N}])(?:' . implode('|', $urlSchemes) . '):\/\/[^\s<>\[\](){}]*[^\s<>\[\](){}.,;:!?\'"]';
        }
        if (in_array('mailto', $schemes, true)) {
            $parts[] = '(?<![\p{L}\p{N}._%+-])(?:mailto:)?' . self::EMAIL;
        }

        return $parts === [] ? null : '/' . implode('|', $parts) . '/iu';
    }

    /**
     * @return array<\MarkupCarve\Carve\Node>|null
     */
    public static function linked(string $text, string $pattern): ?array
    {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }
        $out = [];
        $last = 0;
        foreach ($matches[0] as [$found, $at]) {
            if ($at > $last) {
                $out[] = NavTree::text(substr($text, $last, $at - $last));
            }
            $node = new Node('autolink');
            $bare = !str_contains($found, '://') && !str_starts_with($found, 'mailto:');
            $node->f['href'] = $bare ? 'mailto:' . $found : $found;
            if ($bare) {
                $node->f['text'] = $found;
            }
            $out[] = $node;
            $last = $at + strlen($found);
        }
        if ($last < strlen($text)) {
            $out[] = NavTree::text(substr($text, $last));
        }

        return $out;
    }
}
