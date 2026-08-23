<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\Converter\HeadingId\PreservesHeadingIds;

/**
 * Converts Djot markup to Carve markup.
 *
 * Several inline delimiters mean different things in Djot and Carve, so a Djot
 * document fed to a Carve processor renders wrong with no error. This converter
 * rewrites exactly those constructs to their Carve equivalents:
 *
 *   _x_ -> /x/ (Djot emphasis is underline in Carve)
 *   ~x~ -> {,x,} (Djot subscript is strikethrough in Carve; forced brace form)
 *   {~x~} -> {,x,} (Djot spells subscript braced too, and means the same by it)
 *   {=x=} -> {=x=} (highlight is the same braced form in Carve)
 *   **x** -> *x* (Markdown bold; Carve bold is a single *)
 *   ~~x~~ -> ~x~ (Markdown strikethrough; Carve strike is a single ~)
 *
 * Constructs that mean the same in both languages ($math$, {+ins+},
 * {-del-}, {^x^}, reference links) are left untouched. Delimiters inside code (fenced
 * or inline) and link/image destinations are never rewritten. Only the
 * delimiters are replaced, never the inner text, so nested constructs of
 * different families compose correctly.
 *
 * An intraword `_x_` converts too, to the braced `{/x/}`. Djot's spec puts no
 * word boundary on emphasis, so `snake_case_name` IS emphasis in the source
 * language and an author who wanted the literal characters had to escape them;
 * an unescaped run is therefore what the author saw and kept. The braced form
 * is required because a bare `/` is literal intraword in Carve.
 *
 * Ported from the carve-js djot-migrate linter (the canonical list of
 * Djot/Carve delimiter collisions). Operates byte-wise so offsets from the
 * masked scan splice into the original UTF-8 string unchanged.
 */
class DjotToCarve
{
    use EscapesCarveConstructs;
    use PreservesHeadingIds;

    /**
     * @var array<array{id: string, family: string, pattern: string, open: string, close: string}>
     */
    protected array $rules = [
        [
            'id' => 'markdown-strong-double-star',
            'family' => '*',
            'pattern' => '/\*\*(?!\s)((?:(?!\n[ \t]*\n)[^*])+?)(?<!\s)\*\*/',
            'open' => '*',
            'close' => '*',
        ],
        [
            'id' => 'markdown-strikethrough-double-tilde',
            'family' => '~',
            'pattern' => '/~~(?!\s)((?:(?!\n[ \t]*\n)[^~])+?)(?<!\s)~~/',
            'open' => '~',
            'close' => '~',
        ],
        [
            // Djot spells subscript both bare and braced, and means the same
            // thing by each. The braced spelling has to be matched here, ahead
            // of the bare rule and in the same family, so it claims the range
            // first: the bare rule's match sits inside this one, and the
            // overlap check then rejects it. Without this the braced form
            // reached the escaper instead and was written out as literal text,
            // losing the subscript.
            'id' => 'djot-subscript-tilde-braced',
            'family' => '~',
            'pattern' => '/\{~(?!\s)((?:(?!\n[ \t]*\n)[^~])+?)(?<!\s)~\}/',
            'open' => '{,',
            'close' => ',}',
        ],
        [
            'id' => 'djot-subscript-tilde',
            'family' => '~',
            'pattern' => '/~(?!\s)((?:(?!\n[ \t]*\n)[^~])+?)(?<!\s)~/',
            'open' => '{,',
            'close' => ',}',
        ],
        [
            // Braced superscript is spelled identically in both languages, so
            // the conversion is the identity. It still needs a rule: claiming
            // the range is what stops the bare rule below from matching the
            // `^x^` inside the braces and wrapping it a second time, into
            // `{{^x^}}`.
            'id' => 'djot-superscript-caret-braced',
            'family' => '^',
            'pattern' => '/\{\^(?!\s)((?:(?!\n[ \t]*\n)[^^])+?)(?<!\s)\^\}/',
            'open' => '{^',
            'close' => '^}',
        ],
        [
            // Carve has no bare superscript at all (a `^` outside the braced
            // form is literal), so every Djot `^x^` needs the braced form.
            'id' => 'djot-superscript-caret',
            'family' => '^',
            'pattern' => '/\^(?!\s)((?:(?!\n[ \t]*\n)[^^])+?)(?<!\s)\^/',
            'open' => '{^',
            'close' => '^}',
        ],
        [
            // The `[A-Za-z0-9_]` lookarounds are a DELIBERATE DIVERGENCE from
            // Djot, not a transcription of its rule, and they are the one place
            // this converter knowingly changes what a document means.
            //
            // Djot puts no word boundary on emphasis at all. Its spec says only
            // that a `_` opens "if it is not directly followed by whitespace"
            // and closes "if it is not directly preceded by whitespace", so a
            // strict reader emphasizes an intraword pair - pandoc's Djot reader
            // turns `snake_case_name` into `snake<em>case</em>name`.
            //
            // This converter does not, because the documents it exists for -
            // notes, READMEs, generated docs - are full of `snake_case`
            // identifiers no author meant as emphasis, and Carve itself leaves
            // an intraword `_` literal for that reason (carve-php#417). The
            // migration is therefore faithful to INTENT rather than to a strict
            // reading, and the cost is real and silent: a Djot document that
            // did mean emphasis inside a word loses it with no warning.
            'id' => 'djot-emphasis-underscore',
            'family' => '_',
            'pattern' => '/(?<![A-Za-z0-9_])_(?!\s)((?:(?!\n[ \t]*\n)[^_])+?)(?<!\s)_(?![A-Za-z0-9_])/',
            'open' => '/',
            'close' => '/',
        ],
        [
            // The complement of the rule above, and it CONVERTS rather than
            // leaving the run literal. The input is a DJOT document: Djot
            // emphasizes an intraword `_`, and an author who wanted the literal
            // characters had to escape them. `snake\_case\_name` renders as
            // `snake_case_name` in Djot and arrives here already escaped, so an
            // UNESCAPED `snake_case_name` is emphasis the author saw in their
            // own renderer and kept.
            //
            // The braced form is required, not stylistic: a bare `/` is literal
            // intraword in Carve, so only `snake{/case/}name` gives back
            // `snake<em>case</em>name`.
            //
            // This does not transfer to `MarkdownToCarve`, whose flanking rules
            // leave an intraword `_` literal - there the identifier reading is
            // the correct one.
            'id' => 'djot-intraword-underscore',
            'family' => '_',
            'pattern' => '/(?<=[A-Za-z0-9])_(?!\s)((?:(?!\n[ \t]*\n)[^_])+?)(?<!\s)_(?=[A-Za-z0-9])/',
            'open' => '{/',
            'close' => '/}',
        ],
        [
            'id' => 'djot-highlight-braces',
            'family' => '{',
            'pattern' => '/\{=(?!\s)((?:(?!\n[ \t]*\n)[\s\S])+?)(?<!\s)=\}/',
            'open' => '{=',
            'close' => '=}',
        ],
    ];

    /**
     * Convert Djot markup to Carve markup.
     */
    public function convert(string $djot): string
    {
        $source = str_replace(["\r\n", "\r"], "\n", $djot);
        $masked = $this->maskCodeAndDestinations($source);
        $source = $this->escapePlainDjotText($source, $masked);
        $masked = $this->maskCode($source);

        // Accepted [start, end] delimiter ranges per family, kept sorted by start
        // and disjoint, so the overlap check is a binary search instead of a
        // linear scan of every prior match (which was O(n^2) in match count).
        /** @var array<string, array<array{0: int, 1: int}>> $takenByFamily */
        $takenByFamily = [];
        /** @var array<array{0: int, 1: int, 2: string}> $edits */
        $edits = [];

        foreach ($this->rules as $rule) {
            if (!preg_match_all($rule['pattern'], $masked, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }
            /** @var array<array{0: string, 1: int}> $match */
            foreach ($found as $match) {
                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);

                $backslashes = 0;
                for ($k = $start - 1; $k >= 0 && $masked[$k] === '\\'; $k--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 1) {
                    continue;
                }

                if (!isset($takenByFamily[$rule['family']])) {
                    $takenByFamily[$rule['family']] = [];
                }
                // Bind the per-family bucket by reference so the overlap check
                // and the in-place insert mutate the stored array directly. A
                // by-value copy here would trigger copy-on-write duplication of
                // the growing bucket on every match, reintroducing O(n^2) cost.
                $familyTaken = &$takenByFamily[$rule['family']];
                if ($this->familyOverlaps($familyTaken, $start, $end)) {
                    continue;
                }

                $contentStart = $match[1][1];
                $contentEnd = $contentStart + strlen($match[1][0]);

                $this->insertInterval($familyTaken, $start, $end);
                // Replace only the delimiters; leave inner bytes untouched.
                $edits[] = [$start, $contentStart, $rule['open']];
                $edits[] = [$contentEnd, $end, $rule['close']];
            }
            // Break the reference into the bucket so a later iteration cannot
            // accidentally clobber it via the still-bound $familyTaken alias.
            unset($familyTaken);
        }

        usort($edits, fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$editStart, $editEnd, $replacement]) {
            $source = substr($source, 0, $editStart) . $replacement . substr($source, $editEnd);
        }

        $carve = $this->collapseFalseListBoundaries($this->normalizePlusBullets($source, $masked));

        return $this->applyHeadingIdPreservation($carve, $djot);
    }

    protected function escapePlainDjotText(string $source, string $masked): string
    {
        $result = '';
        $plain = '';
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            if ($masked[$i] === ' ' && $source[$i] !== "\n") {
                if ($plain !== '') {
                    $result .= $this->escapePlainCarveInlineSyntax($plain, self::HANDLED_DJOT);
                    $plain = '';
                }
                $result .= $source[$i];

                continue;
            }

            $plain .= $source[$i];
        }

        if ($plain !== '') {
            $result .= $this->escapePlainCarveInlineSyntax($plain, self::HANDLED_DJOT);
        }

        return $result;
    }

    /**
     * Rewrite Djot `+` bullet markers to `-`.
     *
     * Djot allows `-`, `*` and `+` as bullets; Carve does not have a `+` bullet
     * (it is the list-continuation marker), so a Djot `+` list would otherwise
     * convert to a plain paragraph. The code mask is used to skip lines inside
     * fenced blocks. Inline delimiter edits never cross newlines, so the masked
     * string stays line-aligned with the edited source.
     *
     * @param string $source The delimiter-converted source
     * @param string $masked The code-masked original (line-aligned)
     *
     * @return string
     */
    protected function normalizePlusBullets(string $source, string $masked): string
    {
        $lines = explode("\n", $source);
        $maskedLines = explode("\n", $masked);
        foreach ($lines as $i => $line) {
            if (!isset($maskedLines[$i]) || !preg_match('/^(\s*)\+(\s)/', $maskedLines[$i])) {
                continue;
            }
            $lines[$i] = preg_replace('/^(\s*)\+(\s)/', '$1-$2', $line) ?? $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Does [start, end) overlap any interval in a sorted, disjoint list?
     *
     * Binary-searches for the last interval starting at or before `start` and
     * checks it plus its successor (the only two that can overlap a disjoint
     * sorted set), so the check is O(log n) rather than O(n).
     *
     * @param array<array{0: int, 1: int}> $sorted intervals sorted by start, disjoint
     * @param int $end
     * @param int $start
     */
    protected function familyOverlaps(array $sorted, int $start, int $end): bool
    {
        $count = count($sorted);
        if ($count === 0) {
            return false;
        }

        // Largest index whose interval start <= $start.
        $lo = 0;
        $hi = $count - 1;
        $idx = -1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($sorted[$mid][0] <= $start) {
                $idx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        // Predecessor (starts at/before $start): overlaps iff it ends after $start.
        if ($idx >= 0 && $sorted[$idx][1] > $start) {
            return true;
        }

        // Successor (starts after $start): overlaps iff it starts before $end.
        $next = $idx + 1;

        return $next < $count && $sorted[$next][0] < $end;
    }

    /**
     * Insert [start, end) into a sorted-by-start interval list, preserving order.
     *
     * @param array<array{0: int, 1: int}> $sorted
     * @param int $end
     * @param int $start
     */
    protected function insertInterval(array &$sorted, int $start, int $end): void
    {
        $count = count($sorted);
        // Fast path: appending in source order (the common case) is O(1).
        if ($count === 0 || $sorted[$count - 1][0] <= $start) {
            $sorted[] = [$start, $end];

            return;
        }

        $lo = 0;
        $hi = $count;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($sorted[$mid][0] < $start) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        array_splice($sorted, $lo, 0, [[$start, $end]]);
    }

    /**
     * Replace every code character (fenced blocks, inline code spans) and
     * link/image destinations with spaces, preserving newlines so byte offsets
     * stay aligned with the original source.
     */
    protected function maskCode(string $source): string
    {
        // Stage 4: constructs whose inner delimiters already mean something
        // else in Carve/Djot and must not be migrated again. Math spans need
        // no handling here: both languages write math as `$` plus a code
        // span, which stage 1 code masking already protects.
        return $this->maskProtectedInlineForms($this->maskCodeAndDestinations($source));
    }

    /**
     * Stages 1 to 3 alone: code and destinations masked, the protected inline
     * forms left visible.
     *
     * The escape pass needs this narrower mask. It runs BEFORE any Carve form
     * exists in the source, so masking those forms would hide the plain text it
     * has to escape.
     */
    protected function maskCodeAndDestinations(string $source): string
    {
        // Stage 1: fenced blocks, line by line.
        $lines = explode("\n", $source);
        foreach ($this->fencedLineMap($lines) as $i => $fenced) {
            if ($fenced) {
                $lines[$i] = $this->blanks($lines[$i]);
            }
        }
        $masked = implode("\n", $lines);

        // Stage 2: inline code spans. A run of N backticks closes at the next run of exactly N.
        $length = strlen($masked);
        $i = 0;
        while ($i < $length) {
            if ($masked[$i] !== '`') {
                $i++;

                continue;
            }
            $run = $this->backtickRun($masked, $i);
            $j = $i + $run;
            $closed = -1;
            while ($j < $length) {
                if ($masked[$j] === '`' && $this->backtickRun($masked, $j) === $run) {
                    $closed = $j;

                    break;
                }
                $j++;
            }
            if ($closed === -1) {
                $i += $run;

                continue;
            }
            for ($k = $i; $k < $closed + $run; $k++) {
                if ($masked[$k] !== "\n") {
                    $masked[$k] = ' ';
                }
            }
            $i = $closed + $run;
        }

        // Stage 3: link/image destinations.
        $masked = preg_replace_callback(
            '/(?<=\])\([^()\n]*\)/',
            fn (array $group): string => $this->blanks($group[0]),
            $masked,
        );

        return $masked ?? $source;
    }

    /**
     * Which lines belong to a fenced block, opener and closer included?
     *
     * Shared by the code mask and the blank-run pass. The mask cannot answer
     * this question after the fact: it replaces fence content with SPACES and
     * keeps the newlines, so a masked code line and a blank line look the same.
     * Anything that has to reason about blankness must consult this map first.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, bool>
     */
    protected function fencedLineMap(array $lines): array
    {
        $fenced = [];
        $fenceChar = null;
        $fenceLen = 0;
        foreach ($lines as $i => $line) {
            if ($fenceChar !== null) {
                if (
                    preg_match('/^ {0,3}([`~]{3,})[ \t]*$/', $line, $close)
                    && $close[1][0] === $fenceChar
                    && strlen($close[1]) >= $fenceLen
                ) {
                    $fenceChar = null;
                    $fenceLen = 0;
                }
                $fenced[$i] = true;

                continue;
            }
            if (preg_match('/^\s*(`{3,}|~{3,})\s*[a-zA-Z0-9_-]*\s*$/', $line, $open)) {
                $fenceChar = $open[1][0];
                $fenceLen = strlen($open[1]);
                $fenced[$i] = true;

                continue;
            }
            $fenced[$i] = false;
        }

        return $fenced;
    }

    /**
     * Collapse a blank-line run that only Carve reads as a list boundary.
     *
     * The two languages disagree about what a long blank run between two
     * sibling markers MEANS. Carve (PART 9 §11, clause N1a) makes three or more
     * blank lines a hard boundary: the marker after the run opens a NEW list.
     * Djot has no such rule - any run of blanks between compatible markers is
     * one loose list. So a verbatim rewrite silently changes the document: the
     * seam is invisible for bullets, but an ordered list restarts numbering.
     *
     * The run is cut back to a single blank line, which both languages read the
     * same way. Only a run Carve alone would break on is touched: it must be
     * followed by a marker line and preceded by a line that keeps a list open
     * (a marker, or an item's indented content - Djot has no indented code
     * blocks, so an indented line under a list is that list's content). A run
     * before an item's own indented content closes nothing under N1a and stays,
     * and a run inside a fenced block is that block's content, not layout.
     *
     * carve-rs applies the same rule in `collapse_false_list_boundaries`; the
     * two importers must agree byte for byte.
     */
    protected function collapseFalseListBoundaries(string $source): string
    {
        $lines = explode("\n", $source);
        $fenced = $this->fencedLineMap($lines);
        $count = count($lines);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            if ($fenced[$i] || trim($lines[$i]) !== '') {
                $result[] = $lines[$i];

                continue;
            }

            $end = $i;
            while ($end + 1 < $count && !$fenced[$end + 1] && trim($lines[$end + 1]) === '') {
                $end++;
            }
            $run = $end - $i + 1;

            if ($run >= 3 && $end + 1 < $count && !$fenced[$end + 1] && $this->isMarkerLine($lines[$end + 1])) {
                $above = null;
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (trim($lines[$k]) !== '') {
                        $above = $lines[$k];

                        break;
                    }
                }
                if ($above !== null && ($this->isMarkerLine($above) || preg_match('/^[ \t]/', $above))) {
                    $result[] = '';
                    $i = $end;

                    continue;
                }
            }

            for ($k = $i; $k <= $end; $k++) {
                $result[] = $lines[$k];
            }
            $i = $end;
        }

        return implode("\n", $result);
    }

    /**
     * Does the line open a list item: a bullet, or an ordered marker, followed
     * by a space and content?
     */
    protected function isMarkerLine(string $line): bool
    {
        return (bool)preg_match('/^[ \t]*(?:[-*+]|[0-9A-Za-z]+[.)])[ \t]+\S/', $line);
    }

    protected function maskProtectedInlineForms(string $masked): string
    {
        $patterns = [
            '/\\\\\{([' . $this->bracedDelimiterClass() . '])(?!\s)[^\n]+?(?<!\s)\1\}/',
            '/\[\^[^\]\n]+\]:?/',
            '/\{\^(?!\s)((?:(?!\n[ \t]*\n)[^^])+?)(?<!\s)\^\}/',
            '/\{,(?!\s)((?:(?!\n[ \t]*\n)[^,])+?)(?<!\s),\}/',
        ];

        foreach ($patterns as $pattern) {
            $masked = preg_replace_callback(
                $pattern,
                fn (array $group): string => $this->blanks($group[0]),
                $masked,
            ) ?? $masked;
        }

        return $masked;
    }

    protected function backtickRun(string $text, int $offset): int
    {
        $count = 0;
        $length = strlen($text);
        while ($offset + $count < $length && $text[$offset + $count] === '`') {
            $count++;
        }

        return $count;
    }

    protected function blanks(string $text): string
    {
        return preg_replace('/[^\n]/', ' ', $text) ?? $text;
    }
}
