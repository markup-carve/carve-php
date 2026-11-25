<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\Node\ContentNodeInterface;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Parser\InlineParser;

/**
 * The library inline parser with djot.js 0.3.2 autolinks (the reference library decides Djot syntax in the 0.2 hub):
 * `<`, content without `<`, `>` or white space, `>`; an email when the content matches `[^:]@`, else a URL when it
 * matches `[a-zA-Z]:`, else no autolink.
 *
 * The library keeps some fast paths to its own class (`$this::class === self::class`), so steps that need them run on a
 * plain library instance.
 */
final class DjotInlineParser extends InlineParser
{
    /**
     * @var string The white space of JavaScript `\s` beyond ASCII, which the djot.js autolink pattern also excludes.
     */
    private const UNICODE_SPACE = '/[\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]/u';

    /**
     * @var string The characters djot.js matches as emphasis or quote delimiters.
     */
    private const DELIMITERS = '_*^~+=-"\'';

    /**
     * @var string Where the bracket scan stops: brackets, escapes, verbatim, autolinks, `{` and delimiters.
     */
    private const BRACKET_SCAN_STOPS = '[]\\`<{_*^~+=-"\'';

    private ?InlineParser $library = null;

    private ?string $bracketScanText = null;

    /**
     * @var array<int, int|null>
     */
    private array $bracketScanEnds = [];

    protected function parseInlines(Node $parent, string $text, int $origin = 0): void
    {
        $outerText = $this->bracketScanText;
        $outerEnds = $this->bracketScanEnds;
        try {
            parent::parseInlines($parent, $text, $origin);
        } finally {
            $this->bracketScanText = $outerText;
            $this->bracketScanEnds = $outerEnds;
        }
    }

    /**
     * The `]` that closes the `[` at `$open`, found the way djot.js matches openers: a `]` closes the last open `[`
     * only when `(`, `[` or `{` follows it, any other `]` is text (carve#6665), and a closing emphasis or quote drops
     * the openers inside it. A footnote reference and the label after `][` open no bracket.
     */
    protected function findBalancedBracketEnd(string $text, int $open): ?int
    {
        if ($text !== $this->bracketScanText) {
            $this->bracketScanText = $text;
            $this->bracketScanEnds = [];
        }
        if (array_key_exists($open, $this->bracketScanEnds)) {
            return $this->bracketScanEnds[$open];
        }
        /** @var array<int, array{0: string, 1: int, 2: int}> $openers kind, start and end of each open opener */
        $openers = [['[', $open, $open]];
        /** @var array<string, array<int, int>> $byKind the index in `$openers` of each open opener, by kind */
        $byKind = ['[' => [0]];
        $length = strlen($text);
        $at = $open + 1;
        while ($at < $length) {
            $at += strcspn($text, self::BRACKET_SCAN_STOPS, $at);
            if ($at >= $length) {
                break;
            }
            $skipped = $this->skippedEnd($text, $at);
            if ($skipped !== null) {
                $at = $skipped;

                continue;
            }
            $char = $text[$at];
            if ($char === '[') {
                self::pushOpener($openers, $byKind, '[', $at, $at);
                $at++;

                continue;
            }
            if ($char === ']') {
                $resume = $this->closeBracket($text, $at, $openers, $byKind);
                if ($resume === null) {
                    return $at;
                }
                $at = $resume;

                continue;
            }
            $at = $this->matchDelimiter($text, $at, $openers, $byKind);
        }
        foreach ($openers as [$kind, $start]) {
            if ($kind === '[') {
                $this->bracketScanEnds[$start] = null;
            }
        }

        return null;
    }

    /**
     * Where an escape, a verbatim span, an autolink or a footnote reference at `$at` ends; null when none starts there.
     */
    private function skippedEnd(string $text, int $at): ?int
    {
        $next = $text[$at + 1] ?? '';

        return match ($text[$at]) {
            '\\' => $next === '' ? null : $at + 2,
            '`' => $this->findVerbatimEnd($text, $at),
            '<' => $this->findAutolinkEnd($text, $at),
            '[' => $next === '^' && preg_match('/\G\[\^[^\]]+\]/', $text, $note, 0, $at) === 1 ? $at + strlen($note[0]) : null,
            default => null,
        };
    }

    /**
     * @param string $text
     * @param int $at
     * @param array<int, array{0: string, 1: int, 2: int}> $openers
     * @param array<string, array<int, int>> $byKind
     *
     * @return int|null Where the scan goes on, or null once the scan's own `[` has closed.
     */
    private function closeBracket(string $text, int $at, array &$openers, array &$byKind): ?int
    {
        $next = $text[$at + 1] ?? '';
        if ($next !== '(' && $next !== '[' && $next !== '{') {
            return $at + 1;
        }
        $index = (int)end($byKind['[']);
        $this->bracketScanEnds[$openers[$index][1]] = $at;
        if ($index === 0) {
            return null;
        }
        self::dropOpeners($openers, $byKind, $index);
        if ($next === '(') {
            return $this->findLinkDestinationEnd($text, $at + 1) ?? $at + 1;
        }
        if ($next === '[') {
            $labelEnd = strpos($text, ']', $at + 2);

            return $labelEnd === false ? $at + 1 : $labelEnd + 1;
        }
        $attributeEnd = $this->findAttributeEnd($text, $at + 1);
        if ($attributeEnd !== null && $this->isValidAttrPayload(substr($text, $at + 2, $attributeEnd - $at - 2))) {
            return $attributeEnd + 1;
        }

        return $at + 1;
    }

    /**
     * djot.js `betweenMatched`: a closer matches the last opener of its kind and drops every opener after it.
     *
     * @param string $text
     * @param int $at
     * @param array<int, array{0: string, 1: int, 2: int}> $openers
     * @param array<string, array<int, int>> $byKind
     */
    private function matchDelimiter(string $text, int $at, array &$openers, array &$byKind): int
    {
        $char = $text[$at];
        $next = $text[$at + 1] ?? '';
        if ($char === '{') {
            if ($next !== '' && str_contains(self::DELIMITERS, $next)) {
                self::pushOpener($openers, $byKind, '{' . $next, $at, $at + 1);

                return $at + 2;
            }

            return $at + 1;
        }
        $braced = $next === '}';
        $before = $at > 0 ? $text[$at - 1] : ' ';
        if ($braced || !ctype_space($before)) {
            $kind = $braced ? '{' . $char : $char;
            $index = ($byKind[$kind] ?? []) === [] ? null : end($byKind[$kind]);
            if ($index !== null && $openers[$index][2] !== $at - 1) {
                self::dropOpeners($openers, $byKind, $index);

                return $at + ($braced ? 2 : 1);
            }
        }
        if ($braced || $next === '' || ctype_space($next) || str_contains('+=-', $char)) {
            return $at + ($braced ? 2 : 1);
        }
        if ($char === "'" && $at > 0 && !str_contains(" \t\r\n\"'-([", $before)) {
            return $at + 1;
        }
        self::pushOpener($openers, $byKind, $char, $at, $at);

        return $at + 1;
    }

    /**
     * @param array<int, array{0: string, 1: int, 2: int}> $openers
     * @param array<string, array<int, int>> $byKind
     * @param int $end
     * @param int $start
     * @param string $kind
     */
    private static function pushOpener(array &$openers, array &$byKind, string $kind, int $start, int $end): void
    {
        $byKind[$kind][] = count($openers);
        $openers[] = [$kind, $start, $end];
    }

    /**
     * Drops the opener at `$index` and every opener after it, as djot.js `clearOpeners` does.
     *
     * @param array<int, array{0: string, 1: int, 2: int}> $openers
     * @param array<string, array<int, int>> $byKind
     * @param int $index
     */
    private static function dropOpeners(array &$openers, array &$byKind, int $index): void
    {
        for ($last = count($openers) - 1; $last >= $index; $last--) {
            array_pop($byKind[$openers[$last][0]]);
            unset($openers[$last]);
        }
    }

    /**
     * @return array{node: \Djot\Node\Inline\Link, pos: int}|null
     */
    protected function parseAutolink(string $text, int $pos): ?array
    {
        $autolink = $this->autolink($text, $pos);
        if ($autolink === null) {
            return null;
        }
        [$content, $email, $endPos] = $autolink;
        $link = new Link(($email ? 'mailto:' : '') . $content);
        $link->setAutolink(true);
        $link->appendChild(new Text($content));
        if ($endPos < strlen($text) && $text[$endPos] === '{') {
            $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
        }

        return ['node' => $link, 'pos' => $endPos];
    }

    /**
     * Past 8,192 bytes of abbreviation keys only the library class switches to its matcher; a subclass loses every
     * abbreviation to a regex PCRE refuses.
     *
     * @param \Djot\Node\Node $parent
     * @param string $text
     * @param array<string, string> $abbreviations
     */
    protected function flushTextWithAbbreviations(Node $parent, string $text, array $abbreviations): void
    {
        $this->library ??= new InlineParser($this->blockParser);
        $this->library->flushTextWithAbbreviations($parent, $text, $abbreviations);
    }

    /**
     * An image's alt as djot.js `getStringContent` gives it: the text of every leaf that has one, a line break as `\n`.
     */
    protected function extractText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= match (true) {
                $child instanceof ContentNodeInterface => $child->getContent(),
                $child instanceof FootnoteRef => $child->getLabel(),
                $child instanceof Image => $child->getAlt(),
                $child instanceof SoftBreak, $child instanceof HardBreak => "\n",
                default => $this->extractText($child),
            };
        }

        return $text;
    }

    protected function findAutolinkEnd(string $text, int $pos): ?int
    {
        return $this->autolink($text, $pos)[2] ?? null;
    }

    /**
     * @return array{0: string, 1: bool, 2: int}|null The content, whether it is an email, and the offset after `>`.
     */
    private function autolink(string $text, int $pos): ?array
    {
        if (($text[$pos] ?? '') !== '<') {
            return null;
        }
        $length = strcspn($text, "<> \t\n\v\f\r", $pos + 1);
        if ($length === 0 || ($text[$pos + 1 + $length] ?? '') !== '>') {
            return null;
        }
        $content = substr($text, $pos + 1, $length);
        if (preg_match(self::UNICODE_SPACE, $content) === 1) {
            return null;
        }
        $email = preg_match('/[^:]@/', $content) === 1;
        if (!$email && preg_match('/[a-zA-Z]:/', $content) !== 1) {
            return null;
        }

        return [$content, $email, $pos + $length + 2];
    }
}
