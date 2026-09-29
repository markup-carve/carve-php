<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

/**
 * The encoding of a verbatim payload - a code fence's or raw block's `content`.
 *
 * Joining the payload lines with newlines collapses a payload of NO LINES and a
 * payload of ONE BLANK LINE onto the same empty string, and the two are different
 * documents: the first contributes nothing, while every blank line between the
 * delimiters is payload. PART 9 §28 forbids the two encoding alike, and the AST
 * schema closes `code_block` to new properties, so the count lives in `content`
 * itself - an all-blank payload is one newline per line. The raw block has read it
 * this way since markup-carve/carve#2574; this is the same distinction for the
 * code fence (carve-php#2726, ported from carve-js `src/verbatim-payload.ts` at
 * carve-js#2353).
 *
 * @internal
 */
final class VerbatimPayload
{
    /**
     * Whether `$content` encodes a payload of nothing but blank lines.
     */
    private static function allBlank(string $content): bool
    {
        return $content !== '' && trim($content, "\n") === '';
    }

    /**
     * The `content` for a verbatim payload's collected `$lines`.
     *
     * @param array<string> $lines
     */
    public static function content(array $lines): string
    {
        foreach ($lines as $line) {
            if ($line !== '') {
                return implode("\n", $lines);
            }
        }

        return $lines === [] ? '' : str_repeat("\n", count($lines));
    }

    /**
     * Whether `$content` already ends its last payload line, so a writer owes no
     * separator before the closing delimiter. True for a payload of no lines and
     * for an all-blank one, whose newlines ARE its lines.
     */
    public static function terminated(string $content): bool
    {
        return $content === '' || self::allBlank($content);
    }

    /**
     * The payload lines `$content` stands for. Inverse of `content()`.
     *
     * @return array<string>
     */
    public static function lines(string $content): array
    {
        if ($content === '') {
            return [];
        }
        if (self::allBlank($content)) {
            return array_fill(0, strlen($content), '');
        }

        return explode("\n", $content);
    }

    /**
     * A code payload as verbatim TEXT, every line newline-terminated.
     *
     * This is what `<pre><code>` holds and what every plain-text target writes: a
     * payload of no lines is no characters, and one of N blank lines is N newlines.
     */
    public static function codeText(string $content): string
    {
        return self::terminated($content) ? $content : $content . "\n";
    }

    /**
     * The `content` a code element's verbatim TEXT stands for. Inverse of
     * `codeText()`, and what an HTML import needs: the newline before `</code>`
     * terminates the last payload line rather than adding one, so a `<pre><code>`
     * holding a single newline is one blank line and not none.
     */
    public static function contentFromCodeText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return self::content(explode("\n", str_ends_with($text, "\n") ? substr($text, 0, -1) : $text));
    }
}
