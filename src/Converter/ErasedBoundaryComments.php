<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;

/**
 * The importers write a `%%` line as a block boundary. PART 0 S1 erases such a line before layout
 * (carve#5012), and in a quote it leaves a bare `>` that reads as a blank. A `%%%` comment fence is
 * still a block there, so it keeps the boundary the importer meant.
 *
 * @internal
 */
final class ErasedBoundaryComments
{
    /**
     * @var int
     */
    private const MAX_SINGLE_TRIES = 32;

    /**
     * Respell each erased quoted `%% $body` line as a `%%%` fence holding `$body`.
     */
    public static function fence(string $carve, string $body): string
    {
        $lines = explode("\n", $carve);
        $found = self::quotedLines($carve, $lines, $body);
        foreach (array_reverse($found, true) as $index => $lead) {
            array_splice($lines, $index, 1, [$lead . '%%%', $lead . $body, $lead . '%%%']);
        }

        return implode("\n", $lines);
    }

    /**
     * Respell each `%% $body` line that stands at its container's own column as a code block holding `$body`.
     * Such a line ends a Markdown list, but no comment, fenced or not, ends a Carve list (CARVE-P9-014), so the
     * writer pass needs a visible block there to see two lists.
     */
    public static function fenceListEnders(string $carve, string $body): string
    {
        if (!str_contains($carve, '%%')) {
            return $carve;
        }
        $lines = explode("\n", $carve);
        $pattern = '/^((?:[ \t]*>[ ]?)*)%%[ \t]+' . preg_quote($body, '/') . '[ \t]*$/D';
        foreach (array_reverse((new BlockParser())->erasedCommentLines($carve)) as $index) {
            if (preg_match($pattern, $lines[$index] ?? '', $m) === 1) {
                array_splice($lines, $index, 1, [$m[1] . '```', $m[1] . $body, $m[1] . '```']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Drop each erased quoted bare `%%` line where the document reads as with a fence there, and write the fence
     * where it does not.
     */
    public static function drop(string $carve): string
    {
        $lines = explode("\n", $carve);
        $found = self::quotedLines($carve, $lines, '');
        if ($found === []) {
            return $carve;
        }
        $fenced = self::assemble($lines, $found, []);
        $target = self::render($fenced);
        $dropped = array_fill_keys(array_keys($found), true);
        $all = self::assemble($lines, $found, $dropped);
        if (self::render($all) === $target) {
            return $all;
        }
        $dropped = [];
        foreach (array_slice(array_keys($found), 0, self::MAX_SINGLE_TRIES) as $index) {
            $candidate = $dropped + [$index => true];
            $written = self::assemble($lines, $found, $candidate);
            if (self::render($written) === $target) {
                $dropped = $candidate;
            }
        }

        return self::assemble($lines, $found, $dropped);
    }

    /**
     * @param string $carve
     * @param array<int, string> $lines
     * @param string $body
     *
     * @return array<int, string> Line index to the line's lead (quote prefix and indentation).
     */
    private static function quotedLines(string $carve, array $lines, string $body): array
    {
        if (!str_contains($carve, '%%') || !str_contains($carve, '>')) {
            return [];
        }
        $pattern = '/^((?:[ \t]*>[ ]?)+[ \t]*)%%' . ($body === '' ? '' : '[ \t]+' . preg_quote($body, '/')) . '[ \t]*$/D';
        $found = [];
        foreach ((new BlockParser())->erasedCommentLines($carve) as $index) {
            if (preg_match($pattern, $lines[$index] ?? '', $m) === 1) {
                $found[$index] = $m[1];
            }
        }

        return $found;
    }

    /**
     * A dropped line between two blanks takes one of them along: two blanks would end the list it sat in.
     *
     * @param array<int, string> $lines
     * @param array<int, string> $found
     * @param array<int, true> $dropped
     */
    private static function assemble(array $lines, array $found, array $dropped): string
    {
        foreach (array_reverse($found, true) as $index => $lead) {
            if (!isset($dropped[$index])) {
                array_splice($lines, $index, 1, [$lead . '%%%', $lead . '%%%']);

                continue;
            }
            $blankAround = $index > 0 && isset($lines[$index + 1]) && self::isBlank($lines[$index - 1]) && self::isBlank($lines[$index + 1]);
            array_splice($lines, $index, $blankAround ? 2 : 1);
        }

        return implode("\n", $lines);
    }

    private static function isBlank(string $line): bool
    {
        return trim($line, " \t>") === '';
    }

    private static function render(string $carve): string
    {
        return (new CarveConverter())->convert($carve);
    }
}
