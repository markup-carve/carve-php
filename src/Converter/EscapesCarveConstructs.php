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
     * A source language that spells none of these delimiters itself.
     *
     * HTML and BBCode text: every delimiter below is literal there, so nothing
     * is held back from escaping.
     *
     * The three constants here are the PROFILES the shared escaper corpus
     * defines (`tests/spec/tests/corpus-escape/README.md`), named so a call
     * site states which one it passes instead of repeating the characters. The
     * literals were spelled inline at three call sites before, which is a set a
     * converter could drift out of with nothing to say so; `EscaperCorpusTest`
     * now asserts they equal the corpus's, and the same corpus runs against the
     * escaper under each of them.
     *
     * @var array<string, string>
     */
    protected const HANDLED_PLAIN = [];

    /**
     * Markdown, for `MarkdownToCarve`.
     *
     * `*` and `_` are emphasis and `~` is GFM strikethrough, all of which that
     * converter rewrites into Carve itself. Freezing them here would freeze the
     * markup instead of protecting the text.
     *
     * @var array<string, string>
     */
    protected const HANDLED_MARKDOWN = ['braced' => '*_', 'bare' => '*_~'];

    /**
     * Djot, for `DjotToCarve`.
     *
     * @var array<string, string>
     */
    protected const HANDLED_DJOT = ['braced' => '=+-*_^~', 'bare' => '~*_'];

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
     * Escape a brace that opens what Carve would read as an ATTRIBUTE BLOCK,
     * for a source language that has no such construct.
     *
     * `{#id}` is an attribute block in Carve and in Djot, so DjotToCarve must
     * leave it alone - a pinned id is deliberate there. In HTML and BBCode text
     * the same characters are literal, and left bare the `#` rule declines to
     * escape them (it defers to the brace rule, which only matches a complete
     * pair), so `a {#id} b` came back with a tag span inside literal braces.
     *
     * @param string $text
     */
    protected function escapeAttributeBlockOpener(string $text): string
    {
        return $this->escapeUnlessAlreadyEscaped('/\{(?=#)/', $text);
    }

    /**
     * Escape the verbatim delimiter, for a source language that has no code
     * span of its own to convert.
     *
     * A backtick in HTML or BBCode text is a character. Carried across bare it
     * opens a Carve code span, so plain text turned into markup: `a `b` c` came
     * back as `a <code>b</code> c` (markup-carve/carve-php#1216). A lone
     * backtick was worse - `x ` y` has no pair at all and still produced
     * `<code> y</code>`.
     *
     * Only the TEXT path calls this. A `code` or `pre` element takes its own
     * route and emits its own fence, and BBCode's code tags are stashed before
     * any escaping runs, so neither is reached from here.
     *
     * Djot and Markdown do not call it: a backtick there already means a code
     * span, and their converters carry it over as one.
     *
     * @param string $text
     */
    protected function escapeVerbatimDelimiter(string $text): string
    {
        return $this->escapeUnlessAlreadyEscaped('/`/', $text);
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

            // A brace that LOOKS like a pair opener but never closes is escaped
            // too. The bare rules below decline to escape a delimiter sitting
            // behind an unescaped `{`, on the assumption that the rule above
            // already handled the pair - and when there is no pair, nothing
            // did: `a {*b{* c` kept both its brace and its `*` pair and came
            // back as `a {<strong>b{</strong> c` (markup-carve/carve-php#1218).
            // Escaping the brace is what makes that assumption true again.
            // `#` is excluded: `{#id}` is an ATTRIBUTE BLOCK, not a pair
            // opener, and escaping its brace destroyed an id the Djot source
            // pinned deliberately.
            $unpairedOpeners = str_replace(preg_quote('#', '/'), '', $bracedDelimiters);
            if ($unpairedOpeners !== '') {
                $line = $this->escapeUnlessAlreadyEscaped('/\{(?=[' . $unpairedOpeners . '])/', $line);
            }
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

        // A MENTION is the tag's sibling and needs the same rule for the same
        // reason: it opens on its own, so nothing downstream neutralizes it
        // (carve-php#1380). None of the source languages here means a mention
        // by that character, so prose that quotes one came back as a span - a
        // documentation corpus describing Blade and Alpine turned 22 quoted
        // directives into mentions across 15 of its 380 documents.
        //
        // Mirrors `MentionsExtension`'s opener rather than approximating it: a
        // mention opens on an `@` NOT preceded by an alphanumeric or `_` and
        // followed by one of those or `-`. The lookbehind is what leaves an
        // email address alone, since `foo@bar` has a letter before the `@`.
        //
        // No brace guard, unlike the tag: `{@x@}` is not an attribute block and
        // not a braced pair, so there is nothing above for this rule to
        // duplicate.
        if (!str_contains($bareHandled, '@')) {
            $line = $this->escapeUnlessAlreadyEscaped('/(?<![A-Za-z0-9_])@(?=[A-Za-z0-9_-])/', $line);
        }

        // THE SYMBOL SIGIL, the third member of that family and the one that
        // was missing. `a :rocket: b` came back bare and re-parsed as a
        // `symbol` node, so under a configured symbol map the prose stopped
        // being the prose the source held. PART 11 §2 already asks for the
        // escape - omitting it changes the re-parsed AST - and `:` is already
        // in §5's candidate set, which is why the tag sigil beside it is
        // hardened and this one was not.
        //
        // ONLY THE OPENING COLON is escaped, because only the opening colon
        // opens anything: the closing one is preceded by a name character, so
        // the lookbehind declines it and `a \:rocket: b` is the whole escape.
        //
        // Mirrors `InlineParser::parseSymbol()` rather than approximating it: a
        // symbol opens on a `:` NOT preceded by `_` or an alphanumeric and
        // followed by a name that closes on another `:`, where the first name
        // character is a letter, a digit, `+` or `-` and the rest adds `_`.
        // Matching the CLOSER too is what leaves `a : b : c` alone - a colon
        // that closes no shortcode opens no symbol - and the lookbehind is what
        // leaves a URL alone, since `http://x` has a letter before its colon.
        if (!str_contains($bareHandled, ':')) {
            $line = $this->escapeUnlessAlreadyEscaped('/(?<![A-Za-z0-9_]):(?=[A-Za-z0-9+-][\w+-]*:)/', $line);
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
