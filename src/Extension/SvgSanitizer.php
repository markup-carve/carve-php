<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Html\UrlPolicy;

/**
 * Provisional. A standalone SVG sanitizer, the port of carve-js `sanitizeSvg` (extensions/blocks/svg-sanitize.ts).
 *
 * A tokenizer, not a regex scrub: an element off the allowlist is dropped with its subtree, an attribute off the
 * allowlist and every `on*` handler is dropped, URL and style values are checked, and only the survivors are written
 * again. The output holds no `<script>`, `<foreignObject>`, `<style>`, event handler, dangerous or external URL, or
 * active CSS. Output bytes equal carve-js on the same input, except for the fixes of carve#6755,
 * carve#6760 and carve#6761.
 */
final class SvgSanitizer
{
    /**
     * @var string
     */
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    /**
     * JavaScript's `\s` and `String.prototype.trim()` set; `\s` in PCRE is ASCII only.
     *
     * @var string
     */
    private const WS = '[\t\n\x0B\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /**
     * Raw characters outside the XML 1.0 `Char` production (carve#6764); valid UTF-8 holds no surrogates.
     *
     * @var string
     */
    private const NON_XML_CHAR = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u';

    /**
     * Removed before a scheme is read; a probe class, so it is wider than what a target may emit.
     *
     * @var string
     */
    private const SCHEME_STRIP = '/[\x00-\x20\x{7F}-\x{9F}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}'
        . '\x{3000}\x{FEFF}]/u';

    /**
     * Stands in for a decoded lone surrogate, which UTF-8 cannot hold: like the surrogate, it is no letter, no
     * whitespace and no probe-removed character.
     *
     * @var string
     */
    private const SURROGATE_STANDIN = "\u{E000}";

    /**
     * @var array<string>
     */
    private const ALLOWED_TAGS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc',
        'switch', 'path', 'rect', 'circle', 'ellipse', 'line',
        'polyline', 'polygon', 'text', 'tspan', 'textPath', 'marker',
        'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask',
        'pattern', 'filter', 'feGaussianBlur', 'feOffset', 'feBlend',
        'feColorMatrix', 'feComponentTransfer', 'feFuncA', 'feFuncR',
        'feFuncG', 'feFuncB', 'feComposite', 'feFlood', 'feMerge',
        'feMergeNode', 'feMorphology', 'feTile', 'feTurbulence',
        'feDropShadow', 'feImage', 'feDisplacementMap',
    ];

    /**
     * Looked up in lower case like carve-js, so the two camel-case names never match there either.
     *
     * @var array<string>
     */
    private const ANIMATION_TAGS = ['animate', 'animatetransform', 'animatemotion', 'set', 'mpath'];

    /**
     * Matched in lower case.
     *
     * @var array<string>
     */
    private const ALLOWED_ATTRS = [
        'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx',
        'cy', 'r', 'rx', 'ry', 'width', 'height', 'viewbox',
        'points', 'transform', 'pathlength', 'fill', 'fill-opacity',
        'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-opacity', 'opacity', 'color', 'offset',
        'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
        'spreadmethod', 'patternunits', 'patterntransform', 'patterncontentunits',
        'clippathunits', 'maskunits', 'maskcontentunits', 'markerwidth',
        'markerheight', 'markerunits', 'orient', 'refx', 'refy',
        'preserveaspectratio', 'font-family', 'font-size', 'font-weight',
        'font-style', 'text-anchor', 'dominant-baseline', 'letter-spacing',
        'word-spacing', 'clip-path', 'clip-rule', 'mask', 'marker-start',
        'marker-mid', 'marker-end', 'stddeviation', 'in', 'in2',
        'result', 'mode', 'operator', 'values', 'type',
        'flood-color', 'flood-opacity', 'attributename', 'begin', 'dur',
        'from', 'to', 'repeatcount', 'keytimes', 'keysplines',
        'calcmode', 'additive', 'accumulate', 'class', 'id', 'role',
        'xmlns', 'xmlns:xlink', 'xml:space', 'version',
    ];

    /**
     * The same list as the core renderer's default deny list, kept separately as in carve-js.
     *
     * @var array<string>
     */
    private const DANGEROUS_URL_SCHEMES = [
        'javascript', 'vbscript', 'data', 'file', 'ms-msdt',
        'ms-office', 'ms-word', 'ms-excel', 'ms-powerpoint', 'ms-access',
        'ms-visio', 'ms-project', 'ms-publisher', 'ms-infopath', 'ms-spd',
        'ms-search', 'search-ms', 'ms-cxh', 'ms-cxh-full', 'shell',
        'vscode', 'vscode-insiders', 'jar',
    ];

    /**
     * Values that may only hold a local `#id` reference or a literal; SMIL lists are checked per `;` entry.
     *
     * @var array<string>
     */
    private const REF_VALUE_ATTRS = [
        'fill', 'stroke', 'filter', 'clip-path', 'mask',
        'marker-start', 'marker-mid', 'marker-end', 'color', 'stop-color',
        'flood-color', 'values', 'from', 'to', 'by',
    ];

    /**
     * @var array<string>
     */
    private const ANIMATION_VALUE_ATTRS = ['values', 'from', 'to', 'by'];

    /**
     * The named references that can spell a scheme; decoded for checks only, never for output.
     *
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
        'nbsp' => "\u{A0}",
    ];

    /**
     * @var array<string>
     */
    private array $out = [];

    /**
     * @var array<string>
     */
    private array $dropStack = [];

    /**
     * @var array<array{string, int}> Name and the output index where its content starts.
     */
    private array $kept = [];

    private bool $sawRoot = false;

    private bool $rootSelfClosed = false;

    private function __construct(private SvgSanitizeOptions $opts)
    {
    }

    /**
     * Sanitize an SVG source string.
     *
     * @param string $source
     * @param \MarkupCarve\Carve\Extension\SvgSanitizeOptions|array<string, mixed> $opts
     *
     * @return array{svg: string, ok: bool} `ok` is true when the input is one well-formed `<svg>` root; when it is
     *   false, `svg` is empty and a caller shows the source as text, never the input.
     */
    public static function sanitize(string $source, SvgSanitizeOptions|array $opts = []): array
    {
        $state = new self($opts instanceof SvgSanitizeOptions ? $opts : SvgSanitizeOptions::fromArray($opts));

        return $state->run($source);
    }

    /**
     * @param string $source
     *
     * @return array{svg: string, ok: bool}
     */
    private function run(string $source): array
    {
        $fail = ['svg' => '', 'ok' => false];
        // carve#6764: dropped before any check, so a check never sees a value the output does not hold.
        $src = self::trim((string)preg_replace(self::NON_XML_CHAR, '', self::validUtf8($source)));
        $length = strlen($src);
        $cursor = 0;
        while ($cursor < $length) {
            $start = strpos($src, '<', $cursor);
            if ($start === false) {
                break;
            }
            if (!$this->consumeText(substr($src, $cursor, $start - $cursor))) {
                return $fail;
            }
            $end = self::tokenEnd($src, $start);
            if ($end < 0 || !$this->consumeToken(substr($src, $start, $end - $start))) {
                return $fail;
            }
            $cursor = $end;
        }
        if (!$this->consumeText((string)substr($src, $cursor)) || !$this->sawRoot || $this->kept || $this->dropStack) {
            return $fail;
        }
        $svg = implode('', $this->out);
        $tail = $this->rootSelfClosed ? '~/>' . self::WS . '*\z~u' : '~</svg>' . self::WS . '*\z~iu';
        if (preg_match('~^<svg[\s/>]~i', $svg) !== 1 || preg_match($tail, $svg) !== 1) {
            return $fail;
        }

        return ['svg' => $svg, 'ok' => true];
    }

    private static function validUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $text = mb_scrub($text, 'UTF-8');
        mb_substitute_character($previous);

        return $text;
    }

    private static function trim(string $text): string
    {
        return (string)preg_replace('~^' . self::WS . '+|' . self::WS . '+\z~u', '', $text);
    }

    /**
     * @return int The offset after the token, or -1 when it does not close.
     */
    private static function tokenEnd(string $src, int $start): int
    {
        foreach (['<!--' => '-->', '<![CDATA[' => ']]>', '<?' => '?>'] as $open => $close) {
            if (substr_compare($src, $open, $start, strlen($open)) === 0) {
                $at = strpos($src, $close, $start + strlen($open));

                return $at === false ? -1 : $at + strlen($close);
            }
        }
        $length = strlen($src);
        $at = $start + 1;
        while ($at < $length) {
            $at += strcspn($src, '"\'<>', $at);
            if ($at >= $length) {
                return -1;
            }
            $char = $src[$at];
            if ($char === '>') {
                return $at + 1;
            }
            if ($char === '<') {
                return -1;
            }
            $close = strpos($src, $char, $at + 1);
            if ($close === false) {
                return -1;
            }
            $at = $close + 1;
        }

        return -1;
    }

    private function consumeText(string $between): bool
    {
        if ($between === '') {
            return true;
        }
        if (!$this->sawRoot) {
            return self::trim($between) === '';
        }
        if (!$this->dropStack) {
            $this->out[] = self::escapeText($between);
        }

        return true;
    }

    private function consumeToken(string $token): bool
    {
        if (preg_match('~^</([A-Za-z][A-Za-z0-9_:.-]*)' . self::WS . '*>\z~u', $token, $m) === 1) {
            return $this->closeTag($m[1]);
        }
        if (preg_match('~^<([A-Za-z][A-Za-z0-9_:.-]*)~', $token, $m) !== 1) {
            return true;
        }
        $name = $m[1];
        $selfClose = str_ends_with($token, '/>');
        $raw = (string)substr($token, strlen($name) + 1, strlen($token) - strlen($name) - 1 - ($selfClose ? 2 : 1));

        return $this->openTag($name, $raw, $selfClose);
    }

    private function closeTag(string $name): bool
    {
        if ($this->dropStack) {
            return array_pop($this->dropStack) === $name;
        }
        $frame = array_pop($this->kept);
        if ($frame === null || $frame[0] !== $name) {
            return false;
        }
        if (count($this->out) === $frame[1]) {
            $opener = (string)array_pop($this->out);
            $this->out[] = substr($opener, 0, -1) . '/>';
            if ($name === 'svg') {
                $this->rootSelfClosed = true;
            }
        } else {
            $this->out[] = '</' . $name . '>';
        }

        return true;
    }

    private function openTag(string $name, string $raw, bool $selfClose): bool
    {
        $outside = !$this->kept && !$this->dropStack;
        $isRoot = $outside && !$this->sawRoot;
        if (($isRoot && $name !== 'svg') || ($outside && $this->sawRoot)) {
            return false;
        }
        if ($this->dropStack || !$this->tagAllowed($name)) {
            if (!$selfClose) {
                $this->dropStack[] = $name;
            }

            return true;
        }
        if ($isRoot) {
            $this->sawRoot = true;
            $this->rootSelfClosed = $selfClose;
        }
        $attrs = $this->sanitizeAttrs($raw, $name, $isRoot);
        if ($isRoot) {
            $attrs = ' xmlns="' . self::SVG_NS . '"' . $attrs;
        }
        $this->out[] = '<' . $name . $attrs . ($selfClose ? '/>' : '>');
        if (!$selfClose) {
            $this->kept[] = [$name, count($this->out)];
        }

        return true;
    }

    private function tagAllowed(string $name): bool
    {
        $lower = strtolower($name);
        if (in_array($name, self::ALLOWED_TAGS, true) || in_array($lower, self::ALLOWED_TAGS, true)) {
            return true;
        }

        return ($this->opts->allowLinks && $lower === 'a')
            || ($this->opts->allowAnimation && in_array($lower, self::ANIMATION_TAGS, true))
            || ($this->opts->allowExternalImages && $lower === 'image');
    }

    private function sanitizeAttrs(string $raw, string $tag, bool $isRoot): string
    {
        $lowerTag = strtolower($tag);
        $externalHref = ($this->opts->allowLinks && $lowerTag === 'a') || ($this->opts->allowExternalImages && $lowerTag === 'image');
        $attrs = self::parseAttrs($raw);
        $animatesHref = self::animatesHref($attrs);
        $out = '';
        $seen = [];
        foreach ($attrs as [$name, $value]) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            // carve#6755: the root's own xmlns goes by name; a regex over the written attributes also matched values.
            if ($isRoot && strtolower($name) === 'xmlns') {
                continue;
            }
            $animationValue = in_array(strtolower($name), self::ANIMATION_VALUE_ATTRS, true);
            if ($animatesHref && $animationValue && $value !== null && !self::localRefs($value)) {
                continue;
            }
            $out .= $this->sanitizeAttribute($name, $value, $externalHref);
        }

        return $out;
    }

    /**
     * carve#6761: an animation of `href` may only hold `#id` entries, like the `href` it changes.
     *
     * @param array<array{string, string|null}> $attrs
     */
    private static function animatesHref(array $attrs): bool
    {
        foreach ($attrs as [$name, $value]) {
            if (strtolower($name) === 'attributename' && $value !== null) {
                $target = strtolower(self::trim(self::normalizeForCheck($value)));
                if ($target === 'href' || $target === 'xlink:href') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function localRefs(string $value): bool
    {
        foreach (explode(';', self::normalizeForCheck($value)) as $segment) {
            $segment = self::trim($segment);
            if ($segment !== '' && !str_starts_with($segment, '#')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<array{string, string|null}>
     */
    private static function parseAttrs(string $raw): array
    {
        $out = [];
        $length = strlen($raw);
        $at = 0;
        while ($at < $length) {
            $at = self::skipSpace($raw, $at);
            if ($at >= $length) {
                break;
            }
            if (preg_match('~\G[A-Za-z_:][A-Za-z0-9_:.-]*~', $raw, $m, 0, $at) !== 1) {
                $at += self::charLength($raw[$at]);

                continue;
            }
            $name = $m[0];
            $at = self::skipSpace($raw, $at + strlen($name));
            if (($raw[$at] ?? '') !== '=') {
                $out[] = [$name, null];

                continue;
            }
            $at = self::skipSpace($raw, $at + 1);
            $quote = $raw[$at] ?? '';
            if ($quote === '"' || $quote === "'") {
                $close = strpos($raw, $quote, $at + 1);
                $end = $close === false ? $length : $close;
                $out[] = [$name, substr($raw, $at + 1, $end - $at - 1)];
                $at = $end + 1;

                continue;
            }
            preg_match('~\G(?:(?!' . self::WS . ')[^>])*~u', $raw, $m, 0, $at);
            $out[] = [$name, $m[0] ?? ''];
            $at += strlen($m[0] ?? '');
        }

        return $out;
    }

    private static function skipSpace(string $raw, int $at): int
    {
        if (preg_match('~\G' . self::WS . '+~u', $raw, $m, 0, $at) === 1) {
            return $at + strlen($m[0]);
        }

        return $at;
    }

    private static function charLength(string $lead): int
    {
        $byte = ord($lead);

        return match (true) {
            $byte < 0xC0 => 1,
            $byte < 0xE0 => 2,
            $byte < 0xF0 => 3,
            default => 4,
        };
    }

    private function sanitizeAttribute(string $name, ?string $value, bool $externalHref): string
    {
        $lower = strtolower($name);
        if (str_starts_with($lower, 'on')) {
            return '';
        }
        if ($lower === 'href' || $lower === 'xlink:href') {
            return $this->sanitizeUrlAttribute($name, $value, $externalHref);
        }
        if ($lower === 'style') {
            return $this->opts->allowStyle && $value !== null && !self::styleIsDangerous($value)
                ? ' ' . $name . '="' . self::escapeAttr($value) . '"' : '';
        }
        if (!str_starts_with($lower, 'aria-') && !str_starts_with($lower, 'data-') && !in_array($lower, self::ALLOWED_ATTRS, true)) {
            return '';
        }
        // carve#6779: an empty prefixed namespace declaration undeclares the prefix, which XML forbids.
        if ($value === null || ($value === '' && str_starts_with($lower, 'xmlns:'))) {
            return '';
        }
        if (in_array($lower, self::REF_VALUE_ATTRS, true) ? self::refAttrUnsafe($value) : self::valueHasExternalRef($value)) {
            return '';
        }

        return ' ' . $name . '="' . self::escapeAttr($value) . '"';
    }

    private function sanitizeUrlAttribute(string $name, ?string $value, bool $externalHref): string
    {
        if ($value === null) {
            return '';
        }
        $decoded = self::normalizeForCheck($value);
        if (!str_starts_with($decoded, '#') && !$externalHref) {
            return '';
        }
        if (!self::schemeIsSafe($decoded) || !$this->urlAllowed($decoded)) {
            return '';
        }

        return ' ' . $name . '="' . self::escapeAttr($value) . '"';
    }

    /**
     * carve-js `isUrlAllowed`: the scheme of C2-H-159 against the `urlPolicy` lists.
     */
    private function urlAllowed(string $url): bool
    {
        $scheme = self::urlScheme($url);
        if ($scheme === '') {
            return true;
        }
        $policy = $this->opts->urlPolicy ?? [];
        if (isset($policy['allow'])) {
            return in_array($scheme, array_map(strtolower(...), $policy['allow']), true);
        }

        return !in_array($scheme, array_map(strtolower(...), $policy['deny'] ?? UrlPolicy::defaultDenyList()), true);
    }

    /**
     * carve-js `urlScheme`: probe-removed characters are skipped, the first kept one must be a letter.
     */
    private static function urlScheme(string $url): string
    {
        $scheme = '';
        foreach (mb_str_split($url) as $char) {
            $code = mb_ord($char);
            if (self::probeRemoved((int)$code)) {
                continue;
            }
            if ($char === ':') {
                return strtolower($scheme);
            }
            $ok = $scheme === '' ? ctype_alpha($char) : (ctype_alnum($char) || $char === '+' || $char === '-' || $char === '.');
            if (!$ok) {
                return '';
            }
            $scheme .= $char;
        }

        return '';
    }

    private static function probeRemoved(int $code): bool
    {
        if ($code > 32 && $code < 127) {
            return false;
        }
        foreach (Tables::URL_PROBE_REMOVES as [$from, $to]) {
            if ($code >= $from && $code <= $to) {
                return true;
            }
        }

        return false;
    }

    private static function schemeIsSafe(string $url): bool
    {
        $probe = (string)preg_replace(self::SCHEME_STRIP, '', $url);
        if (preg_match('~^([a-zA-Z][a-zA-Z0-9+.-]*):~', $probe, $m) !== 1) {
            return true;
        }

        return !in_array(strtolower($m[1]), self::DANGEROUS_URL_SCHEMES, true);
    }

    private static function codePoint(int $cp): ?string
    {
        if ($cp < 0 || $cp > 0x10FFFF) {
            return null;
        }

        return $cp >= 0xD800 && $cp <= 0xDFFF ? self::SURROGATE_STANDIN : (string)mb_chr($cp, 'UTF-8');
    }

    private static function decodeEntities(string $value): string
    {
        if (!str_contains($value, '&')) {
            return $value;
        }

        return (string)preg_replace_callback(
            '~&(#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);~',
            static function (array $m): string {
                $body = $m[1];
                if ($body[0] === '#') {
                    $digits = ltrim(substr($body, ($body[1] === 'x' || $body[1] === 'X') ? 2 : 1), '0');
                    $hex = $body[1] === 'x' || $body[1] === 'X';
                    if (strlen($digits) > ($hex ? 6 : 7)) {
                        return $m[0];
                    }

                    return self::codePoint($digits === '' ? 0 : ($hex ? (int)hexdec($digits) : (int)$digits)) ?? $m[0];
                }

                return self::NAMED_REFS[strtolower($body)] ?? $m[0];
            },
            $value,
        );
    }

    private static function decodeCssEscapes(string $value): string
    {
        if (!str_contains($value, '\\')) {
            return $value;
        }

        return (string)preg_replace_callback(
            '~\\\\([0-9a-fA-F]{1,6}' . self::WS . '?|.)~su',
            static function (array $m): string {
                $esc = $m[1];
                if (ctype_xdigit($esc[0])) {
                    return self::codePoint((int)hexdec(self::trim($esc))) ?? '';
                }

                return $esc;
            },
            $value,
        );
    }

    private static function normalizeForCheck(string $value): string
    {
        return self::decodeCssEscapes(self::decodeEntities($value));
    }

    private static function styleIsDangerous(string $value): bool
    {
        $compact = (string)preg_replace('~/\*.*?\*/~s', '', $value);
        $compact = (string)preg_replace('~' . self::WS . '+~u', '', mb_strtolower(self::normalizeForCheck($compact), 'UTF-8'));
        // carve#6760: the core list of C2-H-162 (CSS image functions fetch from a bare string) plus `javascript:`.
        foreach ([...Tables::URL_STYLE_PATTERNS, 'javascript:'] as $needle) {
            if (str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function nonLocalUrl(string $value): bool
    {
        // Atomic, so `(?!#)` cannot pass one character early on `url('#g')` (carve#6763). Only CSS whitespace is
        // skipped: a URL keeps a leading no-break space, so `url(\u{A0}#g)` names another resource.
        return preg_match('~url\((?>[\t\n\f\r ]*[\'"]?[\t\n\f\r ]*)(?!#)~i', $value) === 1;
    }

    private static function refAttrUnsafe(string $value): bool
    {
        foreach (explode(';', self::normalizeForCheck($value)) as $segment) {
            $segment = self::trim($segment);
            if (self::nonLocalUrl($segment)) {
                return true;
            }
            $probe = (string)preg_replace(self::SCHEME_STRIP, '', $segment);
            // A slash-led path (`//host/x`, `/abs`) fetches remotely like an absolute URL.
            if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~', $probe) === 1 || str_starts_with($probe, '/')) {
                return true;
            }
        }

        return false;
    }

    private static function valueHasExternalRef(string $value): bool
    {
        $decoded = self::normalizeForCheck($value);

        return self::nonLocalUrl($decoded) || !self::schemeIsSafe($decoded);
    }

    /**
     * Keeps the entities XML defines (the five names and numeric references to an XML `Char`) and escapes every other `&`.
     */
    private static function escapeAmp(string $text): string
    {
        if (!str_contains($text, '&')) {
            return $text;
        }

        return (string)preg_replace_callback(
            '~&(#[0-9]+;|#x[0-9a-fA-F]+;|(?:amp|lt|gt|quot|apos);)?~',
            static function (array $m): string {
                $ref = $m[1] ?? '';
                if ($ref === '') {
                    return '&amp;';
                }
                if ($ref[0] !== '#') {
                    return $m[0];
                }

                return self::isXmlCharRef($ref) ? $m[0] : '&amp;' . $ref;
            },
            $text,
        );
    }

    /**
     * @param string $ref A numeric reference body such as `#x1F;`.
     */
    private static function isXmlCharRef(string $ref): bool
    {
        $hex = $ref[1] === 'x';
        $digits = ltrim(substr($ref, $hex ? 2 : 1, -1), '0');
        if (strlen($digits) > ($hex ? 6 : 7)) {
            return false;
        }
        $cp = $digits === '' ? 0 : ($hex ? (int)hexdec($digits) : (int)$digits);

        return $cp === 0x9 || $cp === 0xA || $cp === 0xD || ($cp >= 0x20 && $cp <= 0xD7FF)
            || ($cp >= 0xE000 && $cp <= 0xFFFD) || ($cp >= 0x10000 && $cp <= 0x10FFFF);
    }

    private static function escapeText(string $text): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], self::escapeAmp($text));
    }

    private static function escapeAttr(string $text): string
    {
        return str_replace(['"', '<', '>'], ['&quot;', '&lt;', '&gt;'], self::escapeAmp($text));
    }
}
