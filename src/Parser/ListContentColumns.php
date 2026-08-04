<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * The content column of the innermost list item a line-based prepass is inside.
 *
 * Both definition prepasses need it for the same reason: a definition on an
 * item's CONTINUATION line carries no marker, so stripping container markers
 * leaves the item's indentation in front of the `[` and the line stops looking
 * like a definition. Stripping exactly this many columns separates a definition
 * AT the content column (collected) from one below it (paragraph text that
 * registers nothing - PART 9 §24 C3, as carve#624 states it).
 *
 * A line-based approximation: tab-vs-space marker alignment is byte-counted,
 * the post-blank `baseIndent + 2` continuation rule is not modeled, and lists
 * inside blockquotes are not fully modeled. Those residual cases can produce a
 * spurious definition, not content loss.
 *
 * Extracted from ReferenceDefinitionExtractor when the footnote prepass turned
 * out to need the same bookkeeping and did not have it: a `[^f]: x` on an
 * item's continuation line was neither collected nor rendered, so the author's
 * line disappeared and a reference to it stayed literal (carve-php#761).
 */
class ListContentColumns
{
    /**
     * @var list<int>
     */
    protected array $columns = [];

    protected bool $prevBlank = true;

    protected bool $lastOpenedItem = false;

    /**
     * Feed the next raw line; returns the content column in effect for it.
     *
     * @param string $line Raw source line.
     * @param bool $opaque True inside a fence or line block, where `- verse` is
     *   content rather than a marker.
     */
    public function observe(string $line, bool $opaque = false): int
    {
        $wasPrevBlank = $this->prevBlank;
        $this->prevBlank = trim($line) === '';
        $this->lastOpenedItem = false;

        if (!$opaque) {
            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            $rawTrimmed = trim($line);
            $startsBlock = preg_match('/^#{1,6}([ \t]|$)/', $rawTrimmed) === 1
                || str_starts_with($rawTrimmed, '>')
                || preg_match('/^(`{3,}|~{3,})/', $rawTrimmed) === 1
                || preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $rawTrimmed) === 1;

            $marker = '/^([ \t]*)(?:[-*]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)])(?:\{[^}]*\})? +/';
            if (
                preg_match($marker, $line, $lm) === 1
                && preg_match('/\S/', substr($line, strlen($lm[0]))) === 1
            ) {
                $markerIndent = strlen($lm[1]);
                while ($this->columns !== [] && $this->columns[array_key_last($this->columns)] > $markerIndent) {
                    array_pop($this->columns);
                }
                // One line can open several items: `- - b` opens an outer item
                // whose content is another item, so BOTH content columns are
                // live for the lines under it. Recording only the outer one
                // made the inner item's column unknown, and a definition at the
                // outer column was collected here while the block parser still
                // rendered it as the inner item's text - the line appeared
                // twice.
                $offset = 0;
                $rest = $line;
                do {
                    $markerLength = strlen($lm[0]);
                    $offset += $markerLength;
                    $this->columns[] = $offset;
                    $rest = substr($rest, $markerLength);
                    $nested = preg_match($marker, $rest, $lm) === 1
                        && $lm[1] === ''
                        && preg_match('/\S/', substr($rest, strlen($lm[0]))) === 1;
                } while ($nested);
                $this->lastOpenedItem = true;
            } elseif ($rawTrimmed !== '' && ($wasPrevBlank || $startsBlock)) {
                while ($this->columns !== [] && $this->columns[array_key_last($this->columns)] > $indent) {
                    array_pop($this->columns);
                }
            }
        }

        return $this->columns === [] ? 0 : $this->columns[array_key_last($this->columns)];
    }

    /**
     * Whether the line last fed to `observe()` carried the item's marker.
     *
     * On that line the content starts exactly AT the returned column but the
     * columns before it are the marker, not indentation, so a caller reading
     * the item's content has to cut rather than ask for a continuation strip.
     */
    public function openedItem(): bool
    {
        return $this->lastOpenedItem;
    }

    /**
     * The line with the INNERMOST open item's content column removed, when its
     * indent is exactly that column. Returns null when it is not.
     *
     * An outer item's column would be defensible too - inside
     *
     * ```
     * - - b
     *   [^f]: note
     * ```
     *
     * the line sits at the outer item's content column, and carve-js reads it
     * as a definition there. This engine's block parser renders that line as
     * the INNER item's text, so collecting it would show the note twice: once
     * as item text and once as an endnote. A definition that renders where it
     * was written and also collects is worse than one that only renders, so
     * the prepass stays with the column the block parser is known to consume
     * (carve-php#764).
     */
    public function stripToContentColumn(string $line): ?string
    {
        if ($this->columns === []) {
            return null;
        }
        $indent = strlen($line) - strlen(ltrim($line, " \t"));
        if ($indent !== $this->columns[array_key_last($this->columns)]) {
            return null;
        }

        return substr($line, $indent);
    }

    /**
     * The line with the enclosing item's content column removed, when the line
     * is a continuation reaching that column. Returns null when it is not, so
     * the caller keeps its own view of the line.
     */
    public static function stripTo(string $line, int $contentCol): ?string
    {
        if ($contentCol <= 0) {
            return null;
        }
        $indent = strlen($line) - strlen(ltrim($line, " \t"));
        if ($indent < $contentCol) {
            return null;
        }

        return substr($line, $contentCol);
    }
}
