<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use function explode;
use function implode;
use function str_ends_with;
use function substr;

/**
 * The encoding of a code fence's `content`: literal payload text.
 *
 * `code_block.content` keeps every payload character, including the final line
 * break when the last payload line has one (CARVE-P12-064). So no payload lines
 * is `""`, one blank line is `"\n"`, a line holding `a` is `"a\n"`, and that line
 * followed by a blank one is `"a\n\n"`. Only a fence that ends at EOF without a
 * closer can carry an unterminated last line, and an imported or programmatically
 * built block may too; `"a"` and `"a\n"` are different values and survive JSON as
 * such.
 *
 * The raw block is NOT this encoding and keeps {@see \MarkupCarve\Carve\VerbatimPayload}
 * (markup-carve/carve#2616 leaves `raw_block.content` alone).
 *
 * @internal
 */
final class CodePayload
{
    /**
     * The `content` for a code payload's collected `$lines`.
     *
     * `$terminated` is whether the last line owns a break: true for every closed
     * fence, and for an unclosed one whose last line is not the document's final
     * unterminated line.
     *
     * @param array<string> $lines
     * @param bool $terminated
     */
    public static function content(array $lines, bool $terminated): string
    {
        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . ($terminated ? "\n" : '');
    }

    /**
     * Whether `$content` ends its last payload line, so a writer owes no
     * separator before the closing delimiter.
     */
    public static function terminated(string $content): bool
    {
        return $content === '' || str_ends_with($content, "\n");
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

        return explode("\n", self::terminated($content) ? substr($content, 0, -1) : $content);
    }

    /**
     * The payload's lines joined with newlines and NO break after the last one -
     * what a host that embeds the payload inside its own element wants, since
     * that element supplies the boundary itself.
     */
    public static function joinedLines(string $content): string
    {
        return implode("\n", self::lines($content));
    }

    /**
     * The `content` a code element's verbatim TEXT stands for. The text IS the
     * payload, so an HTML import keeps it byte for byte, including whether its
     * final break is present.
     */
    public static function contentFromCodeText(string $text): string
    {
        return $text;
    }
}
