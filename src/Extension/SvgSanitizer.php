<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

/**
 * Hand-rolled SVG sanitizer (Tier-3, zero-dependency). Powers the `img` fence
 * (see {@see ImgFenceExtension}); usable standalone.
 *
 * A real tokenizer, NOT a regex scrub — regex "sanitizers" for SVG are
 * routinely bypassed. It walks the source tag by tag, drops any element not on
 * a presentational allowlist **together with its subtree**, drops any attribute
 * not on the allowlist (and every `on*` handler), scrubs URL/style values, and
 * re-serializes only the survivors. Text nodes pass through with `&<>`
 * re-escaped. Anything unrecognized is dropped, never echoed.
 *
 * The output is guaranteed to contain no `<script>`, no event handlers, no
 * `<foreignObject>`, no `javascript:`/external URLs, and no active CSS — so it
 * is safe to inline into the DOM or to encode into a `data:image/svg+xml` URI.
 *
 * Faithful port of carve-js `src/svg-sanitize.ts`.
 */
final class SvgSanitizer
{
    /**
     * @var string
     */
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    /**
     * Presentational SVG element allowlist. Deliberately excludes script,
     * foreignObject, style, a, image, metadata, and SMIL — those are gated by
     * an option or dropped outright.
     *
     * @var array<string>
     */
    private const ALLOWED_TAGS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'switch',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'marker', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern',
        'filter', 'feGaussianBlur', 'feOffset', 'feBlend', 'feColorMatrix',
        'feComponentTransfer', 'feFuncA', 'feFuncR', 'feFuncG', 'feFuncB',
        'feComposite', 'feFlood', 'feMerge', 'feMergeNode', 'feMorphology',
        'feTile', 'feTurbulence', 'feDropShadow', 'feImage', 'feDisplacementMap',
    ];

    /**
     * @var array<string>
     */
    private const LINK_TAGS = ['a'];

    /**
     * @var array<string>
     */
    private const ANIMATION_TAGS = ['animate', 'animateTransform', 'animateMotion', 'set', 'mpath'];

    /**
     * @var array<string>
     */
    private const EXTERNAL_IMAGE_TAGS = ['image'];

    /**
     * Attribute-name allowlist (case-insensitive). Geometry + presentation only.
     *
     * @var array<string>
     */
    private const ALLOWED_ATTRS = [
        'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r',
        'rx', 'ry',
        'width', 'height', 'viewbox', 'points', 'transform', 'pathlength',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-opacity', 'opacity', 'color', 'offset', 'stop-color', 'stop-opacity',
        'gradientunits', 'gradienttransform', 'spreadmethod', 'patternunits',
        'patterntransform', 'patterncontentunits', 'clippathunits', 'maskunits',
        'maskcontentunits', 'markerwidth', 'markerheight', 'markerunits', 'orient',
        'refx', 'refy', 'preserveaspectratio', 'font-family', 'font-size', 'font-weight',
        'font-style', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'word-spacing',
        'clip-path', 'clip-rule', 'mask', 'marker-start', 'marker-mid', 'marker-end',
        'stddeviation', 'in', 'in2', 'result', 'mode', 'operator', 'values', 'type',
        'flood-color', 'flood-opacity', 'attributename', 'begin', 'dur', 'from', 'to',
        'repeatcount', 'keytimes', 'keysplines', 'calcmode', 'additive', 'accumulate',
        'class', 'id', 'role', 'xmlns', 'xmlns:xlink', 'xml:space', 'version',
    ];

    /**
     * Reference-carrying attrs get URL scrubbing rather than a value passthrough.
     *
     * @var array<string>
     */
    private const URL_ATTRS = ['href', 'xlink:href'];

    /**
     * Kept byte-identical to the core renderer's DANGEROUS_URL_SCHEMES
     * (HtmlRenderer::DANGEROUS_VALUE_SCHEMES): script / inline-content /
     * local-file vectors plus the OS protocol-handler / command-execution
     * schemes (CVE-2026-20841 class). Must not drift narrower.
     *
     * @var array<string>
     */
    private const DANGEROUS_URL_SCHEMES = [
        'javascript', 'vbscript', 'data', 'file',
        'ms-msdt', 'ms-office', 'ms-word', 'ms-excel', 'ms-powerpoint', 'ms-access',
        'ms-visio', 'ms-project', 'ms-publisher', 'ms-infopath', 'ms-spd', 'ms-search',
        'search-ms', 'ms-cxh', 'ms-cxh-full', 'shell', 'vscode', 'vscode-insiders', 'jar',
    ];

    /**
     * Attributes whose value is a paint/filter/animation REFERENCE. These may
     * only carry local `#id` refs or literals — never a non-local `url()` or any
     * absolute URL. SMIL value lists (`values`, `from`, `to`, `by`) are validated
     * per `;`-separated segment so a later entry cannot smuggle a remote target.
     *
     * @var array<string>
     */
    private const REF_VALUE_ATTRS = [
        'fill', 'stroke', 'filter', 'clip-path', 'mask',
        'marker-start', 'marker-mid', 'marker-end',
        'color', 'stop-color', 'flood-color',
        'values', 'from', 'to', 'by',
    ];

    /**
     * Named character references that can obfuscate a URL scheme (form a `:`,
     * `/`, whitespace, `(`, or `&`). Numeric refs are handled generically.
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
        'nbsp' => ' ',
    ];

    /**
     * Unicode whitespace/separator class stripped before a scheme probe, so a
     * leading NBSP / line separator cannot hide a dangerous scheme. Mirrors the
     * carve-js SCHEME_STRIP_RE character class.
     *
     * @var string
     */
    private const SCHEME_STRIP_RE = '/[\x{00}-\x{20}\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]/u';

    /**
     * `url(...)` whose content does not begin with `#` — a NON-LOCAL reference.
     *
     * @var string
     */
    private const NONLOCAL_URL_RE = '/url\(\s*[\'"]?\s*(?!#)/i';

    /**
     * Any absolute-URL scheme (`https:`, `ms-msdt:`, …).
     *
     * @var string
     */
    private const ABSOLUTE_SCHEME_RE = '/^[a-zA-Z][a-zA-Z0-9+.-]*:/';

    /**
     * One tag / comment / CDATA / DOCTYPE / PI / text token at a time.
     *
     * @var string
     */
    private const TOKEN_RE = '~<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<!(?:DOCTYPE|doctype)[^>]*>|<\?[\s\S]*?\?>|<\/([A-Za-z][\w:.-]*)\s*>|<([A-Za-z][\w:.-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(\/?)>~';

    /**
     * One attribute (name plus optional quoted/unquoted value) at a time.
     *
     * @var string
     */
    private const ATTR_RE = '/([A-Za-z_:][\w:.-]*)(?:\s*=\s*("([^"]*)"|\'([^\']*)\'|[^\s"\'>]+))?/';

    /**
     * Sanitize an SVG source string.
     *
     * @param string $source The raw SVG source.
     * @param \MarkupCarve\Carve\Extension\SvgSanitizeOptions|array<string, bool> $opts
     *
     * @return array{svg: string, ok: bool} `svg` is meaningful only when `ok` is
     *   true. When false, callers should fall back to showing the source, never
     *   the raw input.
     */
    public static function sanitize(string $source, SvgSanitizeOptions|array $opts = []): array
    {
        $options = $opts instanceof SvgSanitizeOptions ? $opts : SvgSanitizeOptions::fromArray($opts);

        $src = trim($source);
        $out = '';
        $lastIndex = 0;
        /** @var array<string> $dropStack names of DROPPED open elements (subtree being discarded) */
        $dropStack = [];
        /** @var array<string> $kept names of KEPT open elements — matched on close */
        $kept = [];
        $sawSvgRoot = false;
        $rootSelfClosed = false;

        $count = preg_match_all(
            self::TOKEN_RE,
            $src,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );
        if ($count === false) {
            return ['svg' => '', 'ok' => false];
        }

        foreach ($matches as $m) {
            $full = (string)$m[0][0];
            $index = (int)$m[0][1];

            // Text between the previous token and this one.
            if ($index > $lastIndex) {
                $between = substr($src, $lastIndex, $index - $lastIndex);
                if (!$sawSvgRoot) {
                    if (trim($between) !== '') {
                        return ['svg' => '', 'ok' => false];
                    }
                } elseif ($dropStack === []) {
                    $out .= self::escapeText($between);
                }
            }
            $lastIndex = $index + strlen($full);

            $endName = $m[1][0] ?? null;
            $startName = $m[2][0] ?? null;

            if ($endName !== null) {
                // Closing tag. Must match the most recent open element — kept or
                // dropped. A mismatch means malformed SVG → reject. Tag names are
                // matched CASE-SENSITIVELY (a data-URI SVG parses as XML).
                if ($dropStack !== []) {
                    $d = array_pop($dropStack);
                    if ($d !== $endName) {
                        return ['svg' => '', 'ok' => false];
                    }
                } else {
                    $open = array_pop($kept);
                    if ($open === null || $open !== $endName) {
                        return ['svg' => '', 'ok' => false];
                    }
                    $out .= '</' . $endName . '>';
                }

                continue;
            }
            if ($startName === null) {
                // Comment / CDATA / DOCTYPE / PI — dropped entirely.
                continue;
            }

            $selfClose = ($m[4][0] ?? null) === '/';
            $allowed = self::tagAllowed($startName, $options);
            $isRoot = $kept === [] && $dropStack === [] && !$sawSvgRoot;

            if ($isRoot && $startName !== 'svg') {
                // First element is not a lowercase `<svg>` root (XML is
                // case-sensitive, so only the exact `svg` element is the root).
                return ['svg' => '', 'ok' => false];
            }
            if (!$isRoot && $kept === [] && $dropStack === [] && $sawSvgRoot) {
                // A second element at the top level: not a single <svg> root.
                return ['svg' => '', 'ok' => false];
            }

            if ($dropStack !== []) {
                // Already discarding a subtree; track nesting by name.
                if (!$selfClose) {
                    $dropStack[] = $startName;
                }

                continue;
            }
            if (!$allowed) {
                if (!$selfClose) {
                    $dropStack[] = $startName;
                }

                continue;
            }

            if ($isRoot) {
                $sawSvgRoot = true;
                $rootSelfClosed = $selfClose;
            }
            $attrs = self::sanitizeAttrs((string)($m[3][0] ?? ''), $options, $startName);
            if ($isRoot) {
                // Force the canonical SVG namespace on the root: drop any author
                // `xmlns` and inject ours. `xmlns:xlink` is left intact (the
                // regex only matches the bare `xmlns=`).
                $stripped = preg_replace('/\s+xmlns\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attrs) ?? $attrs;
                $attrs = ' xmlns="' . self::SVG_NS . '"' . $stripped;
            }
            $out .= '<' . $startName . $attrs . ($selfClose ? '/>' : '>');
            if (!$selfClose) {
                $kept[] = $startName;
            }
        }

        // Trailing text.
        if ($lastIndex < strlen($src) && $sawSvgRoot && $dropStack === []) {
            $out .= self::escapeText(substr($src, $lastIndex));
        }

        // Well-formedness: a single closed <svg> root, balanced, nothing open.
        if (!$sawSvgRoot || $kept !== [] || $dropStack !== []) {
            return ['svg' => '', 'ok' => false];
        }
        $tailOk = $rootSelfClosed
            ? preg_match('~/>\s*$~', $out) === 1
            : preg_match('~</svg>\s*$~i', $out) === 1;
        if (preg_match('~^<svg[\s/>]~i', $out) !== 1 || !$tailOk) {
            return ['svg' => '', 'ok' => false];
        }

        return ['svg' => $out, 'ok' => true];
    }

    private static function tagAllowed(string $name, SvgSanitizeOptions $opts): bool
    {
        $n = strtolower($name);
        if (in_array($name, self::ALLOWED_TAGS, true) || in_array($n, self::ALLOWED_TAGS, true)) {
            return true;
        }
        if ($opts->allowLinks && in_array($n, self::LINK_TAGS, true)) {
            return true;
        }
        // Note: the `<style>` *element* is never allowed — its text can carry
        // `@import`/`url()` that no attribute scrub would catch. `allowStyle`
        // governs only the `style` attribute (see sanitizeAttrs()).
        if ($opts->allowAnimation && in_array($n, self::ANIMATION_TAGS, true)) {
            return true;
        }
        if ($opts->allowExternalImages && in_array($n, self::EXTERNAL_IMAGE_TAGS, true)) {
            return true;
        }

        return false;
    }

    private static function sanitizeAttrs(string $raw, SvgSanitizeOptions $opts, string $tag): string
    {
        // An external (non-`#fragment`) href passes only on the specific element
        // its gate covers: `allowLinks` for `<a>`, `allowExternalImages` for
        // `<image>`. Every other element (incl. fetch-capable `<use>` /
        // `<feImage>`) keeps only local `#id` refs. Scheme is still checked so
        // `javascript:`/`data:`/`file:` never survive.
        $t = strtolower($tag);
        $allowExternalHref = ($opts->allowLinks && $t === 'a') || ($opts->allowExternalImages && $t === 'image');
        $out = '';
        // Duplicate attributes are not well-formed XML (breaks the data-URI
        // parse), so keep only the first occurrence of each name.
        $seen = [];
        foreach (self::parseAttrs($raw) as [$name, $value]) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $n = strtolower($name);
            if (str_starts_with($n, 'on')) {
                continue; // every event handler, always
            }
            if (in_array($n, self::URL_ATTRS, true)) {
                if ($value === null) {
                    continue;
                }
                // Normalize entity/CSS-escape obfuscation before deciding.
                $decoded = self::normalizeForCheck($value);
                $local = str_starts_with($decoded, '#');
                if (!$local && !$allowExternalHref) {
                    continue;
                }
                if (!self::schemeIsSafe($decoded)) {
                    continue;
                }
                $out .= ' ' . $name . '="' . self::escapeAttr($value) . '"';

                continue;
            }
            if ($n === 'style') {
                if (!$opts->allowStyle || $value === null) {
                    continue;
                }
                if (self::styleIsDangerous($value)) {
                    continue;
                }
                $out .= ' ' . $name . '="' . self::escapeAttr($value) . '"';

                continue;
            }
            if (str_starts_with($n, 'aria-') || str_starts_with($n, 'data-') || in_array($n, self::ALLOWED_ATTRS, true)) {
                // A value may carry an external `url(...)` paint/filter ref or an
                // absolute URL (esp. in a SMIL `values` list); drop the attribute
                // rather than let the inlined SVG fetch/retarget a remote
                // resource. Local `url(#id)` / `#id` refs are kept.
                if (
                    $value !== null
                    && (in_array($n, self::REF_VALUE_ATTRS, true) ? self::refAttrUnsafe($value) : self::valueHasExternalRef($value))
                ) {
                    continue;
                }
                $out .= $value === null ? ' ' . $name : ' ' . $name . '="' . self::escapeAttr($value) . '"';
            }
        }

        return $out;
    }

    /**
     * @return array<array{0: string, 1: string|null}> name/value pairs
     */
    private static function parseAttrs(string $raw): array
    {
        $out = [];
        if (preg_match_all(self::ATTR_RE, $raw, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            return $out;
        }
        foreach ($matches as $m) {
            $name = (string)$m[1];
            // Mirror carve-js: value = m[3] (dq body) ?? m[4] (sq body) ??
            // (m[2] is set ? m[2] (unquoted) : null).
            $value = $m[3] ?? $m[4] ?? ($m[2] ?? null);
            $out[] = [$name, $value];
        }

        return $out;
    }

    private static function schemeIsSafe(string $url): bool
    {
        $probe = self::stripSchemeWhitespace($url);
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $probe, $m) !== 1) {
            return true; // relative / fragment — safe
        }

        return !in_array(strtolower($m[1]), self::DANGEROUS_URL_SCHEMES, true);
    }

    private static function stripSchemeWhitespace(string $value): string
    {
        $stripped = preg_replace(self::SCHEME_STRIP_RE, '', $value);

        return $stripped ?? $value;
    }

    /**
     * Decode CSS escapes (`\72` → `r`, `\/` → `/`) so an escaped `url(` /
     * `expression(` cannot slip past the needle checks.
     */
    private static function decodeCssEscapes(string $value): string
    {
        $result = preg_replace_callback(
            '/\\\\([0-9a-f]{1,6}\s?|[\s\S])/i',
            static function (array $m): string {
                $esc = $m[1];
                if (preg_match('/^[0-9a-f]/i', $esc) === 1) {
                    $cp = (int)hexdec(trim($esc));

                    return $cp <= 0x10FFFF ? self::codePoint($cp) : '';
                }

                return $esc;
            },
            $value,
        );

        return $result ?? $value;
    }

    /**
     * Decode XML/HTML character references (numeric `&#x61;`/`&#97;` + the named
     * set) so an entity-encoded scheme is normalized before a URL/scheme check.
     * Used ONLY for validation, never for output.
     */
    private static function decodeEntities(string $value): string
    {
        $result = preg_replace_callback(
            '/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i',
            static function (array $m): string {
                $body = $m[1];
                if ($body[0] === '#') {
                    $cp = strtolower($body[1] ?? '') === 'x'
                        ? (int)hexdec(substr($body, 2))
                        : (int)substr($body, 1);

                    return $cp >= 0 && $cp <= 0x10FFFF ? self::codePoint($cp) : $m[0];
                }
                $named = self::NAMED_REFS[strtolower($body)] ?? null;

                return $named ?? $m[0];
            },
            $value,
        );

        return $result ?? $value;
    }

    private static function codePoint(int $cp): string
    {
        // Callers guarantee 0 <= $cp <= 0x10FFFF, so mb_chr() always encodes a
        // string here (matching HtmlRenderer's guarded mb_chr usage).
        return mb_chr($cp, 'UTF-8');
    }

    /**
     * Full normalization for any URL/reference/style check: undo both entity and
     * CSS-escape obfuscation.
     */
    private static function normalizeForCheck(string $value): string
    {
        return self::decodeCssEscapes(self::decodeEntities($value));
    }

    /**
     * Blank a style value that can fetch or execute. Whole-value rejection, not
     * CSS surgery. CSS escapes are decoded first so `u\72l(` folds to `url(`.
     */
    private static function styleIsDangerous(string $value): bool
    {
        $withoutComments = preg_replace('#/\*[\s\S]*?\*/#', '', $value) ?? $value;
        $compact = self::normalizeForCheck($withoutComments);
        $compact = strtolower($compact);
        $compact = preg_replace('/\s+/', '', $compact) ?? $compact;

        return str_contains($compact, 'expression(')
            || str_contains($compact, 'url(')
            || str_contains($compact, '@import')
            || str_contains($compact, 'behavior:')
            || str_contains($compact, '-moz-binding')
            || str_contains($compact, 'javascript:');
    }

    private static function refAttrUnsafe(string $value): bool
    {
        $decoded = self::normalizeForCheck($value);
        foreach (explode(';', $decoded) as $seg) {
            $s = trim($seg);
            if (preg_match(self::NONLOCAL_URL_RE, $s) === 1) {
                return true;
            }
            $probe = self::stripSchemeWhitespace($s);
            if (preg_match(self::ABSOLUTE_SCHEME_RE, $probe) === 1) {
                return true;
            }
            // A leading `/` is a path reference: `//host/x` (protocol-relative)
            // or `/abs/path` both fetch remotely.
            if (str_starts_with($probe, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * For any other allowlisted attribute: reject a non-local `url()` or a
     * denylisted dangerous scheme.
     */
    private static function valueHasExternalRef(string $value): bool
    {
        $decoded = self::normalizeForCheck($value);
        if (preg_match(self::NONLOCAL_URL_RE, $decoded) === 1) {
            return true;
        }

        return !self::schemeIsSafe($decoded);
    }

    /**
     * Escape a bare `&` but leave intact the entities valid in an XML document:
     * the five predefined names (`amp lt gt quot apos`) and numeric refs. Other
     * HTML named entities (`&nbsp;`, `&copy;`) are NOT defined in XML, so a
     * `data:image/svg+xml` parse would fail on them — escape their `&`.
     */
    private static function escapeAmp(string $s): string
    {
        $result = preg_replace('/&(?!#\d+;|#x[0-9a-fA-F]+;|(?:amp|lt|gt|quot|apos);)/', '&amp;', $s);

        return $result ?? $s;
    }

    private static function escapeText(string $s): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], self::escapeAmp($s));
    }

    private static function escapeAttr(string $s): string
    {
        return str_replace(['"', '<', '>'], ['&quot;', '&lt;', '&gt;'], self::escapeAmp($s));
    }
}
