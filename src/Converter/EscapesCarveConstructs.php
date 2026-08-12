<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

trait EscapesCarveConstructs
{
    /**
     * The braced-pair delimiters that may be literal text in source languages.
     *
     * `{X…X}` is a Carve construct for each of these: superscript, subscript,
     * highlight, insert, delete, strike, emphasis and an editorial comment. The
     * list is deliberately longer than the two obvious cases - a delimiter
     * missing from it renders as markup.
     *
     * `*` and `_` are in the list because `{*x*}` and `{_x_}` are a strong and
     * an underline, so they are markup in exactly the way the rest of the list
     * is. A caller whose own language spells emphasis that way - Markdown and
     * Djot both do - names them as handled rather than having them left out
     * here for everyone. `@` and `"` are absent because `{@x@}` and `{"x"}`
     * reinterpret through mentions and smart typography, which apply to any
     * Carve source rather than being introduced here.
     *
     * Held as the literal characters, with the regex character class derived by
     * `bracedDelimiterClass()`. One list, so a delimiter cannot be added to the
     * escaping side and forgotten on the matching side.
     *
     * @var string
     */
    protected const BRACED_DELIMITER_CHARS = '^,=+-~/#*_';

    /**
     * Double every backslash, for a source language that has no backslash
     * escape of its own.
     *
     * HTML and BBCode do not: a backslash in their text is a character the
     * author typed, so it has to survive into Carve, where a backslash IS an
     * escape. Left alone it is read as one and eats the character after it -
     * `a \\*b* c` lost its backslash, and `x \\ y` became a non-breaking
     * space (markup-carve/carve-php#1214).
     *
     * Runs FIRST, before any delimiter escaping, which is also what keeps the
     * already-escaped guard honest: after doubling, every backslash run coming
     * from source text is EVEN, so a delimiter behind one is correctly seen as
     * unescaped and still gets its own escape.
     *
     * Djot and Markdown do not call this. A backslash there is an escape the
     * author wrote, and doubling it would render the backslash they meant to
     * disappear.
     *
     * @param string $text
     */
    protected function escapeLiteralBackslashes(string $text): string
    {
        return str_replace('\\', '\\\\', $text);
    }

    /**
     * Is the character at that offset already escaped?
     *
     * An ODD run of backslashes before it escapes it; an even run is literal
     * backslashes and the character still counts.
     *
     * @param string $subject
     * @param int $offset
     */
    protected function isEscapedAt(string $subject, int $offset): bool
    {
        $backslashes = 0;
        for ($i = $offset - 1; $i >= 0 && $subject[$i] === '\\'; $i--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }

    /**
     * Escape every match of the pattern that is not escaped ALREADY.
     *
     * Escaping an escaped delimiter a second time is worse than leaving it: the
     * doubled backslash renders as a literal backslash and frees the delimiter
     * to open the construct the first escape was suppressing, so the output
     * gains a character the author never wrote AND the markup they escaped away
     * (markup-carve/carve-php#1212). Source arriving already escaped is the
     * normal case here, because the source languages escape with a backslash
     * too.
     *
     * Matched with offsets rather than replaced in place, because a lookbehind
     * cannot express "an odd number of backslashes" and counting back from the
     * offset can. Everything before the offset is untouched at the moment it is
     * counted, so the count is against the text the author wrote.
     *
     * @param string $pattern
     * @param string $subject
     */
    protected function escapeUnlessAlreadyEscaped(string $pattern, string $subject): string
    {
        if (preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $subject;
        }

        // Applied back to front, because inserting a backslash shifts every
        // offset after it and those were measured against the original string.
        foreach (array_reverse($matches[0]) as $match) {
            [$text, $offset] = $match;
            if ($this->isEscapedAt($subject, $offset)) {
                continue;
            }

            $subject = substr_replace($subject, '\\' . $text, $offset, strlen($text));
        }

        return $subject;
    }

    /**
     * Escape Carve inline constructs that are literal text in the source.
     *
     * @param string $line
     * @param array<string, string> $handledDelimiters Delimiters whose source-language constructs the caller converts itself.
     */
    protected function escapePlainCarveInlineSyntax(string $line, array $handledDelimiters = []): string
    {
        $bareHandled = $handledDelimiters['bare'] ?? '';
        $bracedHandled = $handledDelimiters['braced'] ?? '';

        $line = preg_replace_callback('/(^|[ \t])%%(?!%)/', fn (array $match): string => $match[1] . '\%%', $line) ?? $line;

        // Braced forms first, so the bare rules below see an escaped `{` and
        // leave the delimiter inside it alone instead of escaping it twice.
        //
        // Scanned rather than replaced wholesale, because the outer match of a
        // nested `{^a{,b,}c^}` CONSUMES the inner pair, which would then render
        // as a subscript inside literal text. The scanner resumes inside each
        // match so the inner pair is reached. The
        // already-escaped guard is what makes this terminate: a brace behind an
        // odd backslash run is skipped, so a pass that escapes nothing leaves
        // the line unchanged and ends the loop.
        //
        // The guard COUNTS the run rather than testing one character, because a
        // source language without a backslash escape has its backslashes
        // doubled before this runs. In `\\{^x^}` the brace is real and the two
        // backslashes are one literal one; a single-character lookbehind read
        // that as an escaped brace and let the superscript through.
        $bracedDelimiters = $this->bracedDelimiterClass($bracedHandled);
        if ($bracedDelimiters !== '') {
            $line = $this->escapeBracedPairs($line, $bracedDelimiters);
        }

        // The `/` in the lookbehind is not symmetry with the rules below, it is
        // load-bearing: without it the SECOND slash of `ftp://x/` matched, and
        // escaping it freed the first one to open emphasis - `ftp:/\/x/`
        // rendering as `ftp:<em>/x</em>`. Only `http`/`https` URLs are protected
        // upstream in Markdown, so every other scheme reached this rule.
        if (!str_contains($bareHandled, '/')) {
            $line = $this->escapeUnlessAlreadyEscaped('/(?<![A-Za-z0-9\/])\/(?!\s)([^\/]+?)(?<!\s)\/(?![A-Za-z0-9])/', $line);
        }
        if (!str_contains($bareHandled, '=')) {
            $line = $this->escapeUnlessAlreadyEscaped('/(?<![A-Za-z0-9=])(?<!(?<!\\\\)\{)=(?![=\s])([^=]+?)(?<!\s)=(?![A-Za-z0-9=])/', $line);
        }
        if (!str_contains($bareHandled, '~')) {
            $line = $this->escapeUnlessAlreadyEscaped('/(?<![A-Za-z0-9~])(?<!(?<!\\\\)\{)~(?![~\s])([^~]+?)(?<!\s)~(?![A-Za-z0-9~])/', $line);
        }

        // `*` is a strong and `_` an underline, and both are word-bounded: the
        // lookarounds are the same ones the parser opens on, so `a*b*c`,
        // `feature_flag_company` and `5 * 4 * 3` stay bare - escaping those
        // would put a backslash in front of a character the reader typed as
        // itself. Doubling is excluded because `**x**` and `__x__` are already
        // literal to the parser.
        if (!str_contains($bareHandled, '*')) {
            $line = $this->escapeUnlessAlreadyEscaped('/(?<![A-Za-z0-9*])(?<!(?<!\\\\)\{)\*(?![*\s])([^*\n]+?)(?<!\s)\*(?![A-Za-z0-9*])/', $line);
        }
        if (!str_contains($bareHandled, '_')) {
            $line = $this->escapeUnlessAlreadyEscaped('/(?<![A-Za-z0-9_])(?<!(?<!\\\\)\{)_(?![_\s])([^_\n]+?)(?<!\s)_(?![A-Za-z0-9_])/', $line);
        }

        // A TAG is the one construct here that is not a pair: `#x` opens on its
        // own and needs no closer, so nothing downstream can neutralize it and
        // the brace-escaping above cannot either - `\{#y#}` still rendered a tag
        // span inside literal braces (carve-php#1191).
        //
        // Source languages do not share it. Djot and Markdown both mean literal
        // text by `#y`, so every `#word` in their prose became a Carve tag, of
        // which the braced case was only the rarest instance.
        //
        // Mirrors the parser's opener rather than approximating it: a tag opens
        // on a `#` NOT preceded by an alphanumeric and followed by an
        // alphanumeric or `-`. That leaves a heading alone, since `# ` is
        // followed by a space, and leaves `a#y` alone, which is not a tag
        // either.
        //
        // `&` joins the exclusion for a reason the tag rule does not care about
        // but this trait's callers do: `&#8212;` is a NUMERIC CHARACTER
        // REFERENCE, and escaping its `#` stops it decoding, so `a &#8212; b`
        // kept the entity instead of becoming an em dash.
        if (!str_contains($bareHandled, '#')) {
            $line = $this->escapeUnlessAlreadyEscaped('/(?<![A-Za-z0-9&])(?<!(?<!\\\\)\{)#(?=[A-Za-z0-9-])/', $line);
        }

        return $line;
    }

    /**
     * The braced delimiters as a regex character class, minus any the caller
     * converts itself.
     *
     * @param string $handled Literal characters the caller owns.
     */

    /**
     * Escape the opening brace of every braced pair that is not escaped already.
     *
     * Scans with an explicit offset instead of one sweeping replace, for two
     * reasons the plain replace got wrong:
     *
     *  - a nested `{^a{,b,}c^}` has its inner pair swallowed by the outer
     *    match, so resuming AFTER each match never reaches it. Resuming just
     *    inside the match does.
     *  - whether a brace is escaped is a question about the PARITY of the
     *    backslash run before it, which a fixed-width lookbehind cannot ask.
     *    That matters once a source language without backslash escapes has had
     *    its backslashes doubled: `\\{^x^}` is a literal backslash followed by
     *    a real brace.
     *
     * Terminates because the offset strictly increases on every iteration.
     *
     * @param string $line
     * @param string $delimiters A regex character class body.
     */
    protected function escapeBracedPairs(string $line, string $delimiters): string
    {
        $pattern = '/\{([' . $delimiters . '])(?!\s)[^\n]+?(?<!\s)\1\}/';
        $offset = 0;
        $length = strlen($line);

        while ($offset < $length && preg_match($pattern, $line, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$text, $at] = $match[0];
            if ($this->isEscapedAt($line, $at)) {
                $offset = $at + 1;

                continue;
            }

            $line = substr_replace($line, '\\' . $text, $at, strlen($text));
            // Exactly one character longer, so the bound is tracked rather than
            // recomputed.
            $length++;
            // Past the backslash just inserted and the brace it escapes, so a
            // pair nested inside this one is the next thing considered.
            $offset = $at + 2;
        }

        return $line;
    }

    protected function bracedDelimiterClass(string $handled = ''): string
    {
        $class = '';
        foreach (str_split(self::BRACED_DELIMITER_CHARS) as $char) {
            if ($handled !== '' && str_contains($handled, $char)) {
                continue;
            }
            $class .= preg_quote($char, '/');
        }

        return $class;
    }
}
