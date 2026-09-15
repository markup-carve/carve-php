<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;

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
    public const SCAN = '/\{\{[ \t]++(?:"(?:\\\\.|[^"\\\\\n])*+"|[^{}])*+(?<=[ \t])\}\}/s';

    /**
     * Loose directive shape: one whole token, valid options or not. Same body
     * alphabet as SCAN - a quoted run may hold the pair - anchored rather than
     * scanned.
     *
     * @var string
     */
    public const SHAPE = '/^\{\{(?:"(?:\\\\.|[^"\\\\\n])*+"|[^{}])*+\}\}$/s';

    /**
     * Split the option slot into tokens, keeping a quoted value whole.
     * Splitting on whitespace tore `@label:"a b"` into three, so a value's own
     * `#` reached the section slot and a diagnostic named a token that is
     * nowhere in the source.
     *
     * @var string
     */
    private const OPTION_TOKENS = '/(?:"(?:\\\\.|[^"\\\\\n])*+"|[^\s])++/';

    /**
     * @var string
     */
    public const ERROR_UNKNOWN_OPTION = 'unknown-option';

    /**
     * @var string
     */
    public const ERROR_MALFORMED = 'malformed';

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
     * @return array{path: string, section: string|null, lines: array{start: int, end: int}|null, shift: int|'auto', error: string|null, errorPart: string|null}|null
     */
    public static function parse(string $text): ?array
    {
        // The padding is a RUN of whitespace, not one space (grammar PART 6).
        // At least one character is required on each side - `{{c.crv}}` and
        // `{{ c.crv}}` are literal text - but beyond the first, more changes
        // nothing: a bare path stops at the first space anyway. Refusing the
        // extra turned an ALIGNED directive into prose with no warning, which
        // is the failure mode section 19's error rules exist to avoid, and it
        // is what carve-js and carve-rs already accepted.
        if (!preg_match('/^\{\{[ \t]+(.+?)[ \t]+\}\}$/s', $text, $match)) {
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
            $path = stripcslashes($pathMatch[1]);
            $rest = trim($pathMatch[2]);
        } else {
            if (!preg_match('/^([^#@} "]+)(.*)$/s', $body, $pathMatch)) {
                return null;
            }
            $path = $pathMatch[1];
            $rest = trim($pathMatch[2]);
        }

        $section = null;
        $lines = null;
        $shift = 0;
        $error = null;
        $errorPart = null;
        if ($rest !== '') {
            preg_match_all(self::OPTION_TOKENS, $rest, $tokens);
            foreach ($tokens[0] as $part) {
                if (preg_match('/^#([A-Za-z_][A-Za-z0-9_-]*)$/', $part, $sectionMatch)) {
                    $section = $sectionMatch[1];

                    continue;
                }

                if (str_starts_with($part, '@lines:')) {
                    // Line numbers are 1-based and the range must run forward. A
                    // leading-zero, zero, or inverted range is NOT a valid
                    // option: it falls through to the invalid-option handling
                    // below (Warning + literal), matching the reference engine.
                    if (
                        preg_match('/^@lines:([1-9]\d*)-([1-9]\d*)$/', $part, $lineMatch) === 1
                        && (int)$lineMatch[2] >= (int)$lineMatch[1]
                    ) {
                        $lines = ['start' => (int)$lineMatch[1], 'end' => (int)$lineMatch[2]];

                        continue;
                    }
                }

                if (preg_match('/^@shift:([+-]?\d+)$/', $part, $shiftMatch)) {
                    $shift = (int)$shiftMatch[1];

                    continue;
                }

                if ($part === '@shift:auto') {
                    $shift = 'auto';

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
            'shift' => $shift,
            'error' => $error,
            'errorPart' => $errorPart,
        ];
    }
}
