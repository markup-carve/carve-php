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
    private const WS = '[' . self::WS_CHARS . ']';

    /**
     * @var string
     */
    private const WS_CHARS = '\t\n\x0B\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * Raw characters outside the XML 1.0 `Char` production (carve#6764); valid UTF-8 holds no surrogates.
     *
     * @var string
     */
    private const NON_XML_CHAR = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u';

    /**
     * The single-byte part of `NON_XML_CHAR`.
     *
     * @var string
     */
    private const NON_XML_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";

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
     * The text before a token, then the token: a comment, CDATA section or processing instruction up to its first
     * closer, a close tag (group 3), or any other tag (group 4 names an open tag) up to a `>` outside quotes. It stops
     * at a `<` none of these closes.
     *
     * @var string
     */
    private const TOKEN = '~\G([^<]*+)(<(?:!--(?:[^-]++|-(?!->))*+-->|!\[CDATA\[(?:[^\]]++|\](?!\]>))*+\]\]>'
        . '|\?(?:[^?]++|\?(?!>))*+\?>|/([A-Za-z][A-Za-z0-9_:.-]*+)' . self::WS . '*+>'
        . '|(?!!--|!\[CDATA\[|\?)([A-Za-z][A-Za-z0-9_:.-]*+)?(?:[^"\'<>]++|"[^"]*+"|\'[^\']*+\')*+>))~u';

    /**
     * Small enough that `preg_match_all` over it stays under the PCRE match limit.
     *
     * @var int
     */
    private const BATCH_BYTES = 8192;

    /**
     * @var string
     */
    private const CLOSE_TAG = '~^</([A-Za-z][A-Za-z0-9_:.-]*)' . self::WS . '*>\z~u';

    /**
     * @var string
     */
    private const NAME_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_:.-';

    /**
     * One attribute (name, then a double-quoted, single-quoted or bare value) or a run of skipped characters. An
     * unclosed quote runs to the end. Every repeat is over one class, so a long run stays under the PCRE match limit.
     *
     * @var string
     */
    private const ATTR = '~\G' . self::WS . '*+(?:([A-Za-z_:][A-Za-z0-9_:.-]*+)(?:' . self::WS . '*+=' . self::WS
        . '*+(?:"([^"]*+)"?|\'([^\']*+)\'?|([^>' . self::WS_CHARS . ']*+)))?|[^A-Za-z_:' . self::WS_CHARS . ']++)~u';

    /**
     * @var string
     */
    private const TRIM = '~^' . self::WS . '+|' . self::WS . '+\z~u';

    /**
     * @var string
     */
    private const WS_RUN = '~' . self::WS . '+~u';

    /**
     * @var string
     */
    private const SELF_CLOSED_TAIL = '~/>' . self::WS . '*\z~u';

    /**
     * @var string
     */
    private const CLOSED_TAIL = '~</svg>' . self::WS . '*\z~iu';

    /**
     * @var string
     */
    private const CSS_ESCAPE = '~\\\\([0-9a-fA-F]{1,6}' . self::WS . '?|.)~su';

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

    /**
     * The lists above keyed by entry, built once: `in_array()` over the attribute list cost more than the rest of
     * an attribute's checks.
     *
     * @var array<string, array<string, true>>
     */
    private static array $sets = [];

    private function __construct(private SvgSanitizeOptions $opts)
    {
        self::$sets = self::$sets ?: [
            'tags' => array_fill_keys(self::ALLOWED_TAGS, true),
            'animationTags' => array_fill_keys(self::ANIMATION_TAGS, true),
            'attrs' => array_fill_keys(self::ALLOWED_ATTRS, true),
            'refValueAttrs' => array_fill_keys(self::REF_VALUE_ATTRS, true),
            'animationValueAttrs' => array_fill_keys(self::ANIMATION_VALUE_ATTRS, true),
            'dangerousSchemes' => array_fill_keys(self::DANGEROUS_URL_SCHEMES, true),
        ];
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
        $src = self::clean($source);
        $cursor = 0;
        // A short input is read in one pass; a longer one a token at a time, so memory does not grow with its
        // token count.
        $batched = strlen($src) <= self::BATCH_BYTES;
        $tokens = [];
        if ($batched) {
            preg_match_all(self::TOKEN, $src, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        }
        $i = 0;
        while (true) {
            if ($batched) {
                if (!isset($tokens[$i])) {
                    break;
                }
                $m = $tokens[$i++];
            } else {
                $found = preg_match(self::TOKEN, $src, $m, PREG_UNMATCHED_AS_NULL, $cursor);
                if ($found !== 1) {
                    $m = $found === false ? self::scanToken($src, $cursor) : null;
                    if ($m === null) {
                        break;
                    }
                }
            }
            $cursor += strlen($m[0]);
            if (!$this->consumeToken($m[1], $m[2], $m[3], $m[4])) {
                return $fail;
            }
        }
        // The tokens stop early only at a `<` that does not close.
        $rest = (string)substr($src, $cursor);
        if (str_contains($rest, '<') || !$this->consumeText($rest) || !$this->sawRoot || $this->kept || $this->dropStack) {
            return $fail;
        }
        $svg = implode('', $this->out);
        $tail = $this->rootSelfClosed ? self::SELF_CLOSED_TAIL : self::CLOSED_TAIL;
        if (preg_match('~^<svg[\s/>]~i', $svg) !== 1 || preg_match($tail, $svg) !== 1) {
            return $fail;
        }

        return ['svg' => $svg, 'ok' => true];
    }

    /**
     * carve#6764: characters outside XML are dropped before any check, so a check never sees a value the output does
     * not hold.
     */
    private static function clean(string $source): string
    {
        $src = self::validUtf8($source);
        if (strcspn($src, self::NON_XML_BYTES) !== strlen($src) || str_contains($src, "\u{FFFE}") || str_contains($src, "\u{FFFF}")) {
            $src = (string)preg_replace(self::NON_XML_CHAR, '', $src);
        }

        return self::trim($src);
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
        if ($text === '') {
            return $text;
        }
        // No `WS` character starts or ends at an ASCII byte outside the set.
        $first = ord($text[0]);
        $last = ord($text[-1]);
        if (
            $first < 0x80 && $first !== 0x20 && ($first < 0x09 || $first > 0x0D)
            && $last < 0x80 && $last !== 0x20 && ($last < 0x09 || $last > 0x0D)
        ) {
            return $text;
        }

        return (string)preg_replace(self::TRIM, '', $text);
    }

    /**
     * `TOKEN` without a regex over the token, for a token that exceeds the PCRE match limit (a long comment with PCRE
     * JIT off).
     *
     * @return array{string, string, string, string|null, string|null}|null
     */
    private static function scanToken(string $src, int $cursor): ?array
    {
        $start = strpos($src, '<', $cursor);
        $end = $start === false ? -1 : self::tokenEnd($src, $start);
        if ($start === false || $end < 0) {
            return null;
        }
        $token = substr($src, $start, $end - $start);
        $closeName = preg_match(self::CLOSE_TAG, $token, $close) === 1 ? $close[1] : null;
        $openName = ctype_alpha($token[1]) ? substr($token, 1, strspn($token, self::NAME_CHARS, 1)) : null;

        return [substr($src, $cursor, $end - $cursor), substr($src, $cursor, $start - $cursor), $token, $closeName, $openName];
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

    /**
     * @param string $text The text before the token.
     * @param string $token
     * @param string|null $closeName
     * @param string|null $openName Null for a comment, CDATA section, processing instruction or other skipped tag.
     */
    private function consumeToken(string $text, string $token, ?string $closeName, ?string $openName): bool
    {
        if ($text !== '' && !$this->consumeText($text)) {
            return false;
        }
        if ($closeName !== null) {
            return $this->closeTag($closeName);
        }
        if ($openName === null) {
            return true;
        }
        $selfClose = str_ends_with($token, '/>');

        return $this->openTag($openName, (string)substr($token, strlen($openName) + 1, -($selfClose ? 2 : 1)), $selfClose);
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
        if (isset(self::$sets['tags'][$name]) || isset(self::$sets['tags'][$lower])) {
            return true;
        }

        return ($this->opts->allowLinks && $lower === 'a')
            || ($this->opts->allowAnimation && isset(self::$sets['animationTags'][$lower]))
            || ($this->opts->allowExternalImages && $lower === 'image');
    }

    private function sanitizeAttrs(string $raw, string $tag, bool $isRoot): string
    {
        if ($raw === '') {
            return '';
        }
        $lowerTag = strtolower($tag);
        $externalHref = ($this->opts->allowLinks && $lowerTag === 'a') || ($this->opts->allowExternalImages && $lowerTag === 'image');
        // Group 1 is the name (null for skipped characters); groups 2 to 4 are the value.
        if (strlen($raw) <= self::BATCH_BYTES) {
            preg_match_all(self::ATTR, $raw, $attrs, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        } else {
            $attrs = self::namedAttrs($raw);
        }
        $animatesHref = stripos($raw, 'attributename') !== false && self::animatesHref($attrs);
        $out = '';
        $seen = [];
        foreach ($attrs as $m) {
            $name = $m[1];
            if ($name === null || isset($seen[$name])) {
                continue;
            }
            $value = $m[2] ?? $m[3] ?? $m[4];
            $seen[$name] = true;
            $lower = strtolower($name);
            // carve#6755: the root's own xmlns goes by name; a regex over the written attributes also matched values.
            if ($isRoot && $lower === 'xmlns') {
                continue;
            }
            if ($animatesHref && $value !== null && isset(self::$sets['animationValueAttrs'][$lower]) && !self::localRefs($value)) {
                continue;
            }
            $out .= $this->sanitizeAttribute($name, $lower, $value, $externalHref);
        }

        return $out;
    }

    /**
     * A long attribute run, matched one attribute at a time; only named matches are kept, so memory follows the
     * attribute count.
     *
     * @return array<array<string|null>> `ATTR` matches.
     */
    private static function namedAttrs(string $raw): array
    {
        $attrs = [];
        $at = 0;
        while (preg_match(self::ATTR, $raw, $m, PREG_UNMATCHED_AS_NULL, $at) === 1) {
            $at += strlen($m[0]);
            if ($m[1] !== null) {
                $attrs[] = $m;
            }
        }

        return $attrs;
    }

    /**
     * carve#6761: an animation of `href` may only hold `#id` entries, like the `href` it changes.
     *
     * @param array<array<string|null>> $attrs `ATTR` matches.
     */
    private static function animatesHref(array $attrs): bool
    {
        foreach ($attrs as $m) {
            $value = $m[2] ?? $m[3] ?? $m[4];
            if ($m[1] !== null && $value !== null && strtolower($m[1]) === 'attributename') {
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

    private function sanitizeAttribute(string $name, string $lower, ?string $value, bool $externalHref): string
    {
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
        if (!str_starts_with($lower, 'aria-') && !str_starts_with($lower, 'data-') && !isset(self::$sets['attrs'][$lower])) {
            return '';
        }
        // carve#6779: an empty prefixed namespace declaration undeclares the prefix, which XML forbids.
        if ($value === null || ($value === '' && str_starts_with($lower, 'xmlns:'))) {
            return '';
        }
        if (isset(self::$sets['refValueAttrs'][$lower]) ? self::refAttrUnsafe($value) : self::valueHasExternalRef($value)) {
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
        if (!str_contains($url, ':')) {
            return true;
        }
        $probe = (string)preg_replace(self::SCHEME_STRIP, '', $url);
        if (preg_match('~^([a-zA-Z][a-zA-Z0-9+.-]*):~', $probe, $m) !== 1) {
            return true;
        }

        return !isset(self::$sets['dangerousSchemes'][strtolower($m[1])]);
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
            self::CSS_ESCAPE,
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
        $compact = (string)preg_replace(self::WS_RUN, '', mb_strtolower(self::normalizeForCheck($compact), 'UTF-8'));
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
        if (stripos($value, 'url(') === false) {
            return false;
        }

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
            if (!str_contains($segment, ':') && !str_contains($segment, '/')) {
                continue;
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
