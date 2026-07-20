<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Mention;
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
        return $node instanceof Text || $node instanceof EscapedText || $node instanceof Mention;
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
            if ($node instanceof Text || $node instanceof EscapedText) {
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
     * Returns null when the text is not directive-shaped at all. A run that IS
     * directive-shaped but carries a bad option returns an error entry instead,
     * so a caller can tell "not a directive" from "a directive the author got
     * wrong" and warn only for the latter.
     *
     * @return array{path: string, section: string|null, lines: array{start: int, end: int}|null, shift: int|'auto', error: string|null, errorPart: string|null}|null
     */
    public static function parse(string $text): ?array
    {
        if (!preg_match('/^\{\{ (.+) \}\}$/s', $text, $match)) {
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
        if ($rest !== '') {
            foreach (preg_split('/\s+/', $rest) ?: [] as $part) {
                if (preg_match('/^#([A-Za-z_][A-Za-z0-9_-]*)$/', $part, $sectionMatch)) {
                    $section = $sectionMatch[1];

                    continue;
                }

                if (preg_match('/^@lines:(\d+)-(\d+)$/', $part, $lineMatch)) {
                    $lines = ['start' => (int)$lineMatch[1], 'end' => (int)$lineMatch[2]];

                    continue;
                }

                if (preg_match('/^@shift:([+-]?\d+)$/', $part, $shiftMatch)) {
                    $shift = (int)$shiftMatch[1];

                    continue;
                }

                if ($part === '@shift:auto') {
                    $shift = 'auto';

                    continue;
                }

                return static::error(
                    str_starts_with($part, '@') ? static::ERROR_UNKNOWN_OPTION : static::ERROR_MALFORMED,
                    $part,
                );
            }
        }

        return [
            'path' => $path,
            'section' => $section,
            'lines' => $lines,
            'shift' => $shift,
            'error' => null,
            'errorPart' => null,
        ];
    }

    /**
     * Serialize directive parts back to source.
     *
     * Rebuilt from the parsed parts rather than echoed from the input, because
     * the input has already been through the core's smart-quotes pass: a quoted
     * path arrives with typographic quotes, which are not what an author should
     * find in a formatted document. Emitting a canonical form makes the result
     * portable and keeps formatting idempotent.
     *
     * @param array{path: string, section: string|null, lines: array{start: int, end: int}|null, shift: int|'auto', error?: string|null, errorPart?: string|null} $parts
     */
    public static function toSource(array $parts): string
    {
        $path = $parts['path'];
        // Only a path that cannot be read bare needs quoting; the bare form
        // stops at a space, '#', '@' or '}'.
        $needsQuotes = $path === '' || preg_match('/[\s#@}"]/', $path) === 1;
        $out = '{{ ' . ($needsQuotes ? '"' . addcslashes($path, '"\\') . '"' : $path);

        if ($parts['section'] !== null) {
            $out .= ' #' . $parts['section'];
        }

        if ($parts['lines'] !== null) {
            $out .= ' @lines:' . $parts['lines']['start'] . '-' . $parts['lines']['end'];
        }

        if ($parts['shift'] === 'auto') {
            $out .= ' @shift:auto';
        } elseif ($parts['shift'] !== 0) {
            // No explicit '+' on a positive shift: it re-parses identically and
            // matches how authors write it, so formatting stays a no-op.
            $out .= ' @shift:' . $parts['shift'];
        }

        return $out . ' }}';
    }

    /**
     * @return array{path: string, section: string|null, lines: array{start: int, end: int}|null, shift: int|'auto', error: string|null, errorPart: string|null}
     */
    protected static function error(string $error, string $part): array
    {
        return [
            'path' => '',
            'section' => null,
            'lines' => null,
            'shift' => 0,
            'error' => $error,
            'errorPart' => $part,
        ];
    }
}
