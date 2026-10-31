<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;

/**
 * Recognition and serialization of the include directive shape (spec section 19
 * I1, I9a).
 *
 * The core never parses a directive as a node of its own - it is unreachable
 * from block/inline and arrives as ordinary text. Both the expander, which
 * resolves directives, and the Carve renderer, which must emit them back
 * unescaped so they survive formatting, therefore need the same grammar. It
 * lives here once so the two cannot drift: a shape the renderer preserves but
 * the expander does not recognize would silently produce a document whose
 * includes stop working.
 *
 * Recognition is over a RUN, not a node: a directive's own syntax overlaps
 * constructs the core already parses, so `{{ x #intro @shift:1 }}` arrives as
 * several adjacent nodes (I9a).
 */
class IncludeDirectiveSyntax
{
    private static ?string $closerText = null;

    private static int $closerFrom = 0;

    private static int $closerAt = -1;

    private static int $closerStop = -1;

    private static ?string $pairText = null;

    private static int $pairFrom = 0;

    private static int $pairAt = 0;

    private static int $pairNewline = -1;

    /**
     * Scan for directive spans inside a reassembled run.
     *
     * THE CLOSER IS THE FIRST `}}` OUTSIDE ANY QUOTED RUN. A quoted path or a
     * quoted option value may carry the pair, so the body is WALKED - quoted
     * run by quoted run - instead of searched for a closer: one alternative
     * consumes a complete quoted run in a single step, the other one non-brace
     * character, which leaves exactly one position where both fail. That
     * position is the closer, so it is matched rather than looked for.
     *
     * Every quantifier is possessive, so no character can be handed back and
     * the walk cannot re-enter. The one alternation - a `"` either opens a run
     * or is ordinary text - is decided in place: the quoted form is tried
     * first, and with no closing quote on the line it fails and the `"` is
     * taken as ordinary text. That IS the spec's "an unterminated quote does
     * not open a run", which is why the fallback keeps the first-`}}` reading
     * for a malformed directive.
     *
     * The whitespace required before the closer is a fixed-width LOOKBEHIND,
     * not a trailing `[ \t]+`: a trailing quantifier would have to take that
     * whitespace back off the body walk, and that give-back is the re-entry
     * this formulation exists to avoid.
     *
     * @var string
     */
    public const SCAN = '/\{\{ ++(?:"(?:\\\\.|[^"\\\\\n])*+"|[^{}])*+(?<= )\}\}/s';

    /**
     * Loose directive shape: one whole token, valid options or not. Same body
     * alphabet as SCAN - a quoted run may hold the pair - anchored rather than
     * scanned.
     *
     * @var string
     */
    public const SHAPE = '/^\{\{(?:"(?:\\\\.|[^"\\\\\n])*+"|[^{}])*+\}\}$/s';

    /**
     * One slot of the tail: its first character, then everything up to the
     * next whitespace or the next unquoted `#` or `@`.
     *
     * The separating whitespace is OPTIONAL in every position (carve#2773), so
     * a slot cannot be found by splitting on whitespace: that read
     * `#Alpha@shift:1` as one unknown token and left a well-formed directive
     * literal. A quoted value stays whole, because a value's own `#` is not a
     * boundary and naming it in a diagnostic names a token that is nowhere in
     * the source. An option's value runs to the next `@` only, so `@code:c#`
     * is one slot (`include_unquoted_value`).
     *
     * @var string
     */
    private const SLOT = '/\G(?:@(?:"(?:\\\\.|[^"\\\\\n])*+"|[^ @\n])++|#?(?:"(?:\\\\.|[^"\\\\\n])*+"|[^ #@\n])++)/';

    /**
     * @var string
     */
    public const ERROR_UNKNOWN_OPTION = 'unknown-option';

    /**
     * @var string
     */
    public const ERROR_LINES_INVALID = 'lines-invalid';

    /**
     * @var string
     */
    public const ERROR_ROWS_INVALID = 'rows-invalid';

    /**
     * @var string
     */
    public const ERROR_COLS_INVALID = 'cols-invalid';

    /**
     * @var string
     */
    public const ERROR_TABLE_OPTION_REPEATED = 'table-option-repeated';

    /**
     * @var string
     */
    public const ERROR_MALFORMED = 'malformed';

    /**
     * @var string
     */
    public const ERROR_DUPLICATE_SECTION = 'duplicate-section';

    /**
     * @var string
     */
    public const ERROR_DUPLICATE_OPTION = 'duplicate-option';

    /**
     * Nodes whose source form is recoverable verbatim, and which may therefore
     * take part in a directive run.
     */
    public static function isTextLike(Node $node): bool
    {
        return $node instanceof Text
            || $node instanceof EscapedText
            || $node instanceof Mention
            || $node instanceof SmartPunctuation;
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    public static function allTextLike(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!static::isTextLike($node)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    public static function textLikeContent(array $nodes): string
    {
        $content = '';
        foreach ($nodes as $node) {
            if ($node instanceof Text) {
                $content .= $node->getContent();
            } elseif ($node instanceof EscapedText) {
                // The BACKSLASH is part of the source run. A quoted path spells
                // an inner quote as an escape, and the grammar matches the
                // escape, not the bare character - dropping the backslash here
                // turned `{{ "a\"b.crv" }}` into an unbalanced `{{ "a"b.crv" }}`.
                $content .= '\\' . $node->getContent();
            } elseif ($node instanceof SmartPunctuation) {
                // The AUTHOR'S run, not the glyph the parser resolved it to: a
                // quoted path is spelled with straight quotes and the grammar
                // matches that spelling. Reading the curled glyph instead would
                // make recognition depend on smart typography having run.
                $content .= $node->getContent();
            } elseif ($node instanceof Mention) {
                /** @var list<\MarkupCarve\Carve\Node\Node> $children */
                $children = array_values($node->getChildren());
                $content .= static::textLikeContent($children);
            }
        }

        return $content;
    }

    /**
     * Parse a reassembled run into directive parts.
     *
     * Returns null only when the run is not SHAPE-well-formed: it must open
     * `{{`, close `}}` and carry a non-empty path token. Section and option
     * VALIDITY are deliberately not part of that test. A run whose shape is
     * right but whose options are wrong still parses, recording its first
     * complaint in 'error', so a caller can tell "not a directive" from "a
     * directive the author got wrong" and treat the latter as a fixable typo
     * rather than as prose.
     *
     * This is a recognizer only. The serializer no longer rebuilds a directive
     * from these parts - it emits the source span verbatim (see
     * CarveRenderer::emitDirective) - so option order, spacing and the author's
     * exact spelling are preserved without this having to carry them.
     *
     * @return array{path: string, section: string|null, lines: array{start: int, end: int}|null, invalidLines: bool, rows: array{start: int, end: int}|null, cols: list<string>|array{start: int, end: int}|null, invalidRows: bool, invalidCols: bool, region: string|null, code: array{lang: string|null}|null, shift: int|'auto', error: string|null, errorPart: string|null}|null
     */
    public static function parse(string $text): ?array
    {
        if (!preg_match('/^\{\{ +(.+?) +\}\}$/s', $text, $match)) {
            return null;
        }

        $body = $match[1];
        // The core's smart-quotes pass rewrites "..." before either caller sees
        // the text, so a quoted path arrives with typographic quotes.
        if (str_starts_with($body, '"') || str_starts_with($body, "\u{201c}")) {
            $pattern = str_starts_with($body, '"')
                ? '/^"((?:\\\\.|[^"\\\\])*)"(.*)$/s'
                : '/^\x{201c}([^\x{201d}]*)\x{201d}(.*)$/su';
            if (!preg_match($pattern, $body, $pathMatch)) {
                return null;
            }
            // The escape set of a quoted attribute value (carve#2778).
            $path = AttributeParser::processEscapes($pathMatch[1]);
            $rest = trim($pathMatch[2], ' ');
        } else {
            if (!preg_match('/^([^#@} \n"]+)(.*)$/s', $body, $pathMatch)) {
                return null;
            }
            $path = $pathMatch[1];
            $rest = trim($pathMatch[2], ' ');
        }

        $section = null;
        $lines = null;
        $invalidLines = false;
        $rows = null;
        $cols = null;
        $invalidRows = false;
        $invalidCols = false;
        $region = null;
        $code = null;
        $shift = 0;
        $error = null;
        $errorPart = null;
        $seen = [];
        // Spec I1: a key given twice is an error, never "last one wins".
        $repeats = static function (string $key, string $part) use (&$seen, &$error, &$errorPart): bool {
            if (!isset($seen[$key])) {
                $seen[$key] = true;

                return false;
            }
            if ($error === null) {
                $error = static::ERROR_DUPLICATE_OPTION;
                $errorPart = $part;
            }

            return true;
        };
        if ($rest !== '') {
            foreach (static::slots($rest) as $part) {
                if ($part === '@code') {
                    if (!$repeats('code', $part)) {
                        $code = ['lang' => null];
                    }

                    continue;
                }
                if (preg_match('/^@code:([A-Za-z0-9_+#.\/-]+)$/', $part, $codeMatch)) {
                    if (!$repeats('code', $part)) {
                        $code = ['lang' => $codeMatch[1]];
                    }

                    continue;
                }
                // `explicit_identifier`, so a digit-leading id is nameable (I1a).
                if (preg_match('/^#([A-Za-z0-9_][A-Za-z0-9_-]*)$/', $part, $sectionMatch)) {
                    // `include_section` is ONE optional slot. A second name is
                    // not a directive the author can mean, and taking the last
                    // one returned the wrong fragment with nothing on the page
                    // to say a selector had been dropped.
                    if ($section !== null) {
                        if ($error === null) {
                            $error = static::ERROR_DUPLICATE_SECTION;
                            $errorPart = $part;
                        }

                        continue;
                    }
                    $section = $sectionMatch[1];

                    continue;
                }

                if (str_starts_with($part, '@lines:')) {
                    // Line numbers are 1-based and the range must run forward.
                    if (
                        preg_match('/^@lines:([1-9]\d*)-([1-9]\d*)$/', $part, $lineMatch) === 1
                        && (int)$lineMatch[2] >= (int)$lineMatch[1]
                    ) {
                        if (!$repeats('lines', $part)) {
                            $lines = ['start' => (int)$lineMatch[1], 'end' => (int)$lineMatch[2]];
                        }

                        continue;
                    }
                    $invalidLines = true;
                    if ($error === null) {
                        $error = static::ERROR_LINES_INVALID;
                        $errorPart = $part;
                    }

                    continue;
                }

                if (preg_match('/^@rows(?::(.*))?$/s', $part, $rowOption)) {
                    if ($repeats('rows', $part)) {
                        $error = self::ERROR_TABLE_OPTION_REPEATED;

                        continue;
                    }
                    $rows = self::positiveRange($rowOption[1] ?? '');
                    if ($rows === null) {
                        $invalidRows = true;
                        $error ??= self::ERROR_ROWS_INVALID;
                    }

                    continue;
                }

                if (preg_match('/^@cols(?::(.*))?$/s', $part, $colOption)) {
                    if ($repeats('cols', $part)) {
                        $error = self::ERROR_TABLE_OPTION_REPEATED;

                        continue;
                    }
                    $raw = $colOption[1] ?? '';
                    $cols = self::positiveRange($raw) ?? self::columnNames($raw);
                    if ($cols === null) {
                        $invalidCols = true;
                        $error ??= self::ERROR_COLS_INVALID;
                    }

                    continue;
                }

                if (preg_match('/^@region:([A-Za-z_][A-Za-z0-9_-]*)$/', $part, $regionMatch)) {
                    if (!$repeats('region', $part)) {
                        $region = $regionMatch[1];
                    }

                    continue;
                }

                if (preg_match('/^@shift:([+-]?\d+)$/', $part, $shiftMatch)) {
                    if (!$repeats('shift', $part)) {
                        $shift = (int)$shiftMatch[1];
                    }

                    continue;
                }

                if ($part === '@shift:auto') {
                    if (!$repeats('shift', $part)) {
                        $shift = 'auto';
                    }

                    continue;
                }

                // Scanning continues past the first bad token so the error is
                // reported at the right severity, not thrown away.
                if ($error === null) {
                    $error = str_starts_with($part, '@') ? static::ERROR_UNKNOWN_OPTION : static::ERROR_MALFORMED;
                    $errorPart = $part;
                }
            }
        }

        return [
            'path' => $path,
            'section' => $section,
            'lines' => $lines,
            'invalidLines' => $invalidLines,
            'rows' => $rows,
            'cols' => $cols,
            'invalidRows' => $invalidRows,
            'invalidCols' => $invalidCols,
            'region' => $region,
            'code' => $code,
            'shift' => $shift,
            'error' => $error,
            'errorPart' => $errorPart,
        ];
    }

    /**
     * @return array{start: int, end: int}|null
     */
    private static function positiveRange(string $raw): ?array
    {
        if (preg_match('/^([1-9]\d*)-([1-9]\d*)$/', $raw, $match) !== 1) {
            return null;
        }
        $start = filter_var($match[1], FILTER_VALIDATE_INT);
        $end = filter_var($match[2], FILTER_VALIDATE_INT);

        return $start !== false && $end !== false && $start <= $end
            ? ['start' => $start, 'end' => $end] : null;
    }

    /**
     * @return list<string>|null
     */
    private static function columnNames(string $raw): ?array
    {
        if (strlen($raw) < 2 || $raw[0] !== '"' || substr($raw, -1) !== '"') {
            return null;
        }
        $names = [];
        $name = '';
        for ($i = 1, $end = strlen($raw) - 1; $i < $end; $i++) {
            if ($raw[$i] === '\\') {
                if (++$i >= $end) {
                    return null;
                }
                $name .= $raw[$i];
            } elseif ($raw[$i] === '"') {
                return null;
            } elseif ($raw[$i] === ',') {
                $names[] = $name;
                $name = '';
            } else {
                $name .= $raw[$i];
            }
        }
        $names[] = $name;

        return in_array('', $names, true) ? null : $names;
    }

    /**
     * The tail after the path, cut into slots.
     *
     * @return list<string>
     */
    protected static function slots(string $tail): array
    {
        $slots = [];
        $at = 0;
        $length = strlen($tail);
        while ($at < $length) {
            if ($tail[$at] === ' ') {
                $at++;

                continue;
            }
            if (preg_match(self::SLOT, $tail, $match, 0, $at) !== 1) {
                // A `#` or `@` with nothing spellable behind it. Take the one
                // character so the scan advances and the slot is reported.
                $slots[] = substr($tail, $at, 1);
                $at++;

                continue;
            }
            $slots[] = $match[0];
            $at += strlen($match[0]);
        }

        return $slots;
    }

    /**
     * Read the `include_directive` opening at `$open` by its SHAPE only (PART 6):
     * any identifier key, value or not. PART 10 I2 decides a code include line
     * from this spelling, so an unknown or malformed option must not unmake it.
     *
     * @return array{end: int, quoted: bool, options: list<array{0: string, 1: string|null}>}|null
     */
    public static function shape(string $text, int $open = 0): ?array
    {
        if (substr($text, $open, 2) !== '{{') {
            return null;
        }
        $closer = self::closerIndex($text, $open + 2);
        if ($closer === -1) {
            return null;
        }
        $inner = substr($text, $open + 2, $closer - $open - 2);
        $length = strlen($inner);
        $i = strspn($inner, ' ');
        if ($i === 0 || $i >= $length) {
            return null;
        }
        $quoted = $inner[$i] === '"';
        if ($quoted) {
            $end = self::quotedRunEnd($inner, $i);
            if ($end === -1) {
                return null;
            }
            $i = $end + 1;
        } else {
            $run = strcspn($inner, '#@} ', $i);
            if ($run === 0) {
                return null;
            }
            $i += $run;
        }
        $j = $i + strspn($inner, ' ', $i);
        if ($j < $length && $inner[$j] === '#') {
            if (preg_match('/\G[A-Za-z0-9_][A-Za-z0-9_-]*/', $inner, $name, 0, $j + 1) !== 1) {
                return null;
            }
            $i = $j + 1 + strlen($name[0]);
        }
        $options = [];
        while (true) {
            $j = $i + strspn($inner, ' ', $i);
            if ($j >= $length || $inner[$j] !== '@') {
                break;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_-]*/', $inner, $key, 0, $j + 1) !== 1) {
                return null;
            }
            $i = $j + 1 + strlen($key[0]);
            if ($i >= $length || $inner[$i] !== ':') {
                $options[] = [$key[0], null];

                continue;
            }
            if ($i + 1 < $length && ($inner[$i + 1] === '"' || $inner[$i + 1] === "'")) {
                $end = self::quotedRunEnd($inner, $i + 1);
                if ($end === -1) {
                    return null;
                }
                $options[] = [$key[0], substr($inner, $i + 2, $end - $i - 2)];
                $i = $end + 1;

                continue;
            }
            $run = strcspn($inner, "}|\"'\\ \t\r\n@", $i + 1);
            if ($run === 0) {
                return null;
            }
            $options[] = [$key[0], substr($inner, $i + 1, $run)];
            $i += 1 + $run;
        }
        $tail = substr($inner, $i);
        if ($tail === '' || strspn($tail, ' ') !== strlen($tail)) {
            return null;
        }

        return ['end' => $closer + 2, 'quoted' => $quoted, 'options' => $options];
    }

    /**
     * A directive that stays literal text in core inline content: an invalid
     * `@lines` range, a `@code` language that is no `language_info` token (PART 10 I1c),
     * or a bare `@code` on a bare path.
     *
     * @param array{end: int, quoted: bool, options: list<array{0: string, 1: string|null}>} $shape
     */
    public static function isLiteralInline(array $shape): bool
    {
        foreach ($shape['options'] as [$key, $value]) {
            if ($key === 'lines' && !self::isLineRange($value ?? '')) {
                return true;
            }
            if ($key === 'code' && $value === null && !$shape['quoted']) {
                return true;
            }
            if ($key === 'code' && $value !== null && preg_match('~^[A-Za-z0-9_+#./-]+$~D', $value) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{end: int, quoted: bool, options: list<array{0: string, 1: string|null}>} $shape
     */
    public static function hasCodeOption(array $shape): bool
    {
        foreach ($shape['options'] as [$key]) {
            if ($key === 'code') {
                return true;
            }
        }

        return false;
    }

    /**
     * The shape of a line holding only a directive with a `code` option, or null.
     *
     * @return array{end: int, quoted: bool, options: list<array{0: string, 1: string|null}>}|null
     */
    public static function codeIncludeLine(string $line): ?array
    {
        $subject = trim($line, " \t");
        $shape = str_starts_with($subject, '{{') ? self::shape($subject) : null;

        return $shape !== null && $shape['end'] === strlen($subject) && self::hasCodeOption($shape) ? $shape : null;
    }

    private static function isLineRange(string $raw): bool
    {
        if (preg_match('/^([1-9]\d*)-([1-9]\d*)$/', $raw, $match) !== 1) {
            return false;
        }

        return strlen($match[1]) < strlen($match[2])
            || (strlen($match[1]) === strlen($match[2]) && strcmp($match[1], $match[2]) <= 0);
    }

    private static function quotedRunEnd(string $text, int $from): int
    {
        $quote = $text[$from];
        $length = strlen($text);
        for ($i = $from + 1; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === "\n") {
                return -1;
            }
            if ($char === '\\') {
                $i++;
            } elseif ($char === $quote) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Whether a `}}` follows `$from` on its line, quotes aside: none means no closer.
     */
    private static function pairBeforeLineEnd(string $text, int $from): bool
    {
        if (self::$pairText !== $text || $from < self::$pairFrom || $from > self::$pairAt) {
            $pair = strpos($text, '}}', $from);
            self::$pairText = $text;
            self::$pairFrom = $from;
            self::$pairAt = $pair === false ? PHP_INT_MAX : $pair;
            $newline = $pair === false ? false : strrpos(substr($text, $from, $pair - $from), "\n");
            self::$pairNewline = $newline === false ? -1 : $from + $newline;
        }

        return self::$pairAt !== PHP_INT_MAX && self::$pairNewline < $from;
    }

    private static function closerIndex(string $text, int $from): int
    {
        // Openers are scanned left to right, so a scan that met no quote also
        // answers every later opener before its closer: keeps a run of openers linear.
        if (
            self::$closerText === $text && $from >= self::$closerFrom
            && ($from <= self::$closerAt || self::$closerAt === -1 && $from <= self::$closerStop)
        ) {
            return self::$closerAt;
        }
        if (!self::pairBeforeLineEnd($text, $from)) {
            return -1;
        }
        $length = strlen($text);
        $quoted = false;
        $found = -1;
        for ($i = $from; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === "\n") {
                break;
            }
            if ($char === '"' || $char === "'") {
                $quoted = true;
                $end = self::quotedRunEnd($text, $i);
                if ($end !== -1) {
                    $i = $end;
                }
            } elseif ($char === '}' && ($text[$i + 1] ?? '') === '}') {
                $found = $i;

                break;
            }
        }
        if (!$quoted) {
            self::$closerText = $text;
            self::$closerFrom = $from;
            self::$closerAt = $found;
            self::$closerStop = $i;
        }

        return $found;
    }
}
