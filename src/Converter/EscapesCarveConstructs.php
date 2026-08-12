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
     * Escape Carve inline constructs that are literal text in the source.
     *
     * @param string $line
     * @param array<string, string> $handledDelimiters Delimiters whose source-language constructs the caller converts itself.
     */
    protected function escapePlainCarveInlineSyntax(string $line, array $handledDelimiters = []): string
    {
        $escapeFirst = static fn (array $match): string => '\\' . $match[0];
        $bareHandled = $handledDelimiters['bare'] ?? '';
        $bracedHandled = $handledDelimiters['braced'] ?? '';

        $line = preg_replace_callback('/(^|[ \t])%%(?!%)/', fn (array $match): string => $match[1] . '\%%', $line) ?? $line;

        // Braced forms first, so the bare rules below see an escaped `{` and
        // leave the delimiter inside it alone instead of escaping it twice.
        //
        // Repeated until stable, because one pass escapes only the outermost
        // brace of a nested `{^a{,b,}c^}` - the match consumes the inner pair,
        // which would then render as a subscript inside literal text. The
        // `(?<!\\)` guard is what makes this terminate: an escaped brace is
        // never re-matched, and each pass escapes at least one.
        $bracedDelimiters = $this->bracedDelimiterClass($bracedHandled);
        if ($bracedDelimiters !== '') {
            do {
                $previous = $line;
                $line = preg_replace_callback(
                    '/(?<!\\\\)\{([' . $bracedDelimiters . '])(?!\s)[^\n]+?(?<!\s)\1\}/',
                    $escapeFirst,
                    $line,
                ) ?? $line;
            } while ($line !== $previous);
        }

        // The `/` in the lookbehind is not symmetry with the rules below, it is
        // load-bearing: without it the SECOND slash of `ftp://x/` matched, and
        // escaping it freed the first one to open emphasis - `ftp:/\/x/`
        // rendering as `ftp:<em>/x</em>`. Only `http`/`https` URLs are protected
        // upstream in Markdown, so every other scheme reached this rule.
        if (!str_contains($bareHandled, '/')) {
            $line = preg_replace_callback('/(?<![A-Za-z0-9\/])\/(?!\s)([^\/]+?)(?<!\s)\/(?![A-Za-z0-9])/', $escapeFirst, $line) ?? $line;
        }
        if (!str_contains($bareHandled, '=')) {
            $line = preg_replace_callback('/(?<![A-Za-z0-9=])(?<!(?<!\\\\)\{)=(?![=\s])([^=]+?)(?<!\s)=(?![A-Za-z0-9=])/', $escapeFirst, $line) ?? $line;
        }
        if (!str_contains($bareHandled, '~')) {
            $line = preg_replace_callback('/(?<![A-Za-z0-9~])(?<!(?<!\\\\)\{)~(?![~\s])([^~]+?)(?<!\s)~(?![A-Za-z0-9~])/', $escapeFirst, $line) ?? $line;
        }

        // `*` is a strong and `_` an underline, and both are word-bounded: the
        // lookarounds are the same ones the parser opens on, so `a*b*c`,
        // `feature_flag_company` and `5 * 4 * 3` stay bare - escaping those
        // would put a backslash in front of a character the reader typed as
        // itself. Doubling is excluded because `**x**` and `__x__` are already
        // literal to the parser.
        if (!str_contains($bareHandled, '*')) {
            $line = preg_replace_callback('/(?<![A-Za-z0-9*])(?<!(?<!\\\\)\{)\*(?![*\s])([^*\n]+?)(?<!\s)\*(?![A-Za-z0-9*])/', $escapeFirst, $line) ?? $line;
        }
        if (!str_contains($bareHandled, '_')) {
            $line = preg_replace_callback('/(?<![A-Za-z0-9_])(?<!(?<!\\\\)\{)_(?![_\s])([^_\n]+?)(?<!\s)_(?![A-Za-z0-9_])/', $escapeFirst, $line) ?? $line;
        }

        return $line;
    }

    /**
     * The braced delimiters as a regex character class, minus any the caller
     * converts itself.
     *
     * @param string $handled Literal characters the caller owns.
     */
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
