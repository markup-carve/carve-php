<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Diagnostics;

use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;

/**
 * The messages of the parse findings the block pass notes (C2-B-105, C2-B-111, C2-B-122, C2-B-181), read from the source.
 */
final class ParseMessages
{
    /**
     * @var array<string>
     */
    private const CURLY = ['“', '”', '„', '‟'];

    /**
     * @var array<string, string>
     */
    private const DIV_WORDS = [
        'admonition' => 'admonition',
        'directive' => 'directive',
        'figure_group' => 'figure group',
        'block_quote' => 'quote fence',
        'div' => 'div',
        'line_block' => 'line block',
    ];

    /**
     * @param string $code
     * @param string $s
     * @param \MarkupCarve\Carve\Node $node
     * @param int $from Where the opener text the finding is about starts, or -1.
     *
     * @return string
     */
    public static function of(string $code, string $s, Node $node, int $from): string
    {
        $start = self::opener($s, $node, $from);

        return match ($code) {
            'unclosed-code-fence' => self::unclosedCode($s, $node, $start),
            'unclosed-comment-fence' => self::unclosedComment($s, $node, $start),
            'unclosed-container-fence' => self::unclosedContainer($s, $node, $start),
            'fence-title-syntax' => self::title($s, $start, $from, $node->type === 'code_block'),
            'fence-opener-fallback' => self::fallback($s, $node, $start, $from),
            default => Message::fill($code),
        };
    }

    /**
     * The offset of the fence run of the opener line: the line of `$from`, else the first line of the node that starts with a
     * fence character.
     *
     * @param string $s
     * @param \MarkupCarve\Carve\Node $node
     * @param int $from
     *
     * @return int
     */
    private static function opener(string $s, Node $node, int $from): int
    {
        $n = strlen($s);
        if ($from >= 0) {
            // The opener of a finding with a position: the first fence character of its line, behind any marker.
            $line = self::lineStart($s, $from);
            $fence = $line + strcspn($s, '`~:', $line, $from - $line);

            return $fence < $from ? $fence : $line;
        }
        $at = max(0, $node->start);
        while ($at < $n) {
            $p = $at + strspn($s, " \t>", $at);
            if ($p < $n && str_contains('`~%:|-', $s[$p])) {
                return $p;
            }
            $nl = strpos($s, "\n", $at);
            if ($nl === false) {
                break;
            }
            $at = $nl + 1;
        }

        return max(0, $node->start);
    }

    private static function lineStart(string $s, int $at): int
    {
        $nl = $at > 0 ? strrpos($s, "\n", $at - strlen($s) - 1) : false;

        return $nl === false ? 0 : $nl + 1;
    }

    private static function lineEnd(string $s, int $at): int
    {
        $nl = strpos($s, "\n", $at);

        return $nl === false ? strlen($s) : $nl;
    }

    private static function run(string $s, int $at): string
    {
        return $at < strlen($s) ? substr($s, $at, strspn($s, $s[$at], $at)) : '';
    }

    private static function toEnd(string $s, Node $node): bool
    {
        return $node->end < 0 || trim(substr($s, $node->end), " \t\r\n") === '';
    }

    private static function unclosedCode(string $s, Node $node, int $start): string
    {
        $ending = self::toEnd($s, $node) ? 'it runs to the end of the document'
            : 'it ends at the end of its container on line ' . (substr_count($s, "\n", 0, $node->end) + 1);

        return Message::fill('unclosed-code-fence', ['marker' => self::run($s, $start), 'ending' => $ending]);
    }

    private static function unclosedComment(string $s, Node $node, int $start): string
    {
        return Message::fill('unclosed-comment-fence', ['opener' => self::run($s, $start)]);
    }

    private static function unclosedContainer(string $s, Node $node, int $start): string
    {
        $run = self::run($s, $start);
        $rest = substr($s, $start + strlen($run), self::lineEnd($s, $start) - $start - strlen($run));
        if ($node->type === 'div' && trim($rest, " \t\r") === '') {
            return Message::fill('unclosed-container-fence', ['width' => strlen($run)], 1);
        }

        return Message::fill('unclosed-container-fence', ['width' => strlen($run), 'kind' => self::DIV_WORDS[$node->type] ?? 'div']);
    }

    /**
     * C2-B-122: the opener with its further words read as the quoted title the author meant.
     *
     * @param string $s
     * @param int $start
     * @param int $from
     * @param bool $code The whitespace message is only for a code fence (C2-B-117).
     *
     * @return string
     */
    private static function title(string $s, int $start, int $from, bool $code): string
    {
        $run = self::run($s, $start);
        $lineEnd = self::lineEnd($s, $start);
        $afterRun = $start + strlen($run);
        $before = $from > $afterRun ? substr($s, $afterRun, $from - $afterRun) : '';
        $type = self::word(ltrim($before, " \t"));
        $rest = trim(substr($s, max($from, $afterRun), $lineEnd - max($from, $afterRun)), " \t\r");
        $label = '';
        $open = strrpos($rest, ' [');
        if (str_ends_with($rest, ']') && $open !== false) {
            $label = substr($rest, $open);
            $rest = trim(substr($rest, 0, $open));
        }
        $slots = ['type' => $type, 'fence' => $run, 'label' => $label];
        $first = mb_substr($rest, 0, 1);
        $afterFence = substr($s, $afterRun, $lineEnd - $afterRun);
        if ($code && $type === '' && preg_match('/^ {2,}\{[^{}]*\}/', $afterFence) === 1) {
            return Message::fill('fence-title-syntax', ['correctedOpener' => $run], 4);
        }
        $spacing = $code ? self::spacing($s, $afterRun, $before, $type, $run, $afterFence) : null;
        if ($spacing !== null) {
            return $spacing;
        }
        if ($code && $type !== '' && $rest !== '' && in_array($first, self::CURLY, true)) {
            $title = mb_substr($rest, 1);
            if (in_array(mb_substr($title, -1), self::CURLY, true)) {
                $title = mb_substr($title, 0, -1);
            }

            return Message::fill('fence-title-syntax', $slots + ['title' => $title], 2);
        }
        $wordEnd = $afterRun + strlen($before) - strlen(ltrim($before, " \t")) + strlen($type);
        if (
            $code && (
                (str_contains($before, '"') && !str_contains($before, ']'))
                || preg_match('/^"[^"\n]*"[ \t]+\S/', ltrim(substr($s, $wordEnd, $lineEnd - $wordEnd), " \t")) === 1
            )
        ) {
            return Message::fill('fence-title-syntax', [], 1);
        }
        // A brace block or a title behind the label is no unquoted title (C2-B-117).
        if ($code && $type !== '' && $type[0] !== '=' && $from > $wordEnd && $first !== '{' && !str_contains($before, ']')) {
            return Message::fill('fence-title-syntax', $slots + ['title' => (string)preg_replace('/^"|"\z/', '', $rest)], 3);
        }
        if (!$code && trim($before, " \t") === '') {
            return Message::fill('fence-title-syntax', [], 0);
        }
        $parts = trim($before, " \t");
        $corrected = $run[0] === '`' && $parts !== ''
            ? $run . ' ' . $parts
            : rtrim(substr($s, $start, $from - $start));

        return Message::fill('fence-title-syntax', ['correctedOpener' => $corrected], 4);
    }

    /**
     * C2-B-117, C2-B-122: the message for a code fence opener whose whitespace is wrong: a second space or a tab behind
     * the run, or a tab behind the language word; null for any other opener.
     *
     * @param string $s
     * @param int $afterRun
     * @param string $before
     * @param string $type
     * @param string $run
     * @param string $afterFence
     *
     * @return string|null
     */
    private static function spacing(string $s, int $afterRun, string $before, string $type, string $run, string $afterFence): ?string
    {
        $line = trim($afterFence);
        $respaced = $line === '' ? $run : $run . ' ' . preg_replace('/\s+/', ' ', $line);
        $glued = $s[$afterRun] ?? '';
        if ($type === '' && ($glued === "\t" || ($glued === ' ' && in_array($s[$afterRun + 1] ?? '', [' ', "\t"], true)))) {
            return Message::fill('fence-title-syntax', ['respaced' => $respaced], 5);
        }
        $wordEnd = $afterRun + strlen($before) - strlen(ltrim($before, " \t")) + strlen($type);
        if ($type !== '' && ($s[$wordEnd] ?? '') === "\t") {
            return Message::fill('fence-title-syntax', ['respaced' => $respaced], 6);
        }

        return null;
    }

    /**
     * C2-B-225: the language word, the raw format or the div name or sigil at the start of an opener's text.
     *
     * @param string $text
     *
     * @return string
     */
    private static function word(string $text): string
    {
        if (str_starts_with($text, '=')) {
            return '=' . substr($text, 1, strspn($text, Tables::CHARS_RAW_FORMAT, 1));
        }
        $run = strspn($text, Tables::CHARS_LANGUAGE_WORD);
        if ($run > 0) {
            return substr($text, 0, $run);
        }

        return $text !== '' && strspn($text, '>|', 0, 1) === 1 && in_array($text[1] ?? ' ', [' ', "\t"], true) ? $text[0] : '';
    }

    /**
     * C2-B-105: an attribute block after a fence language or an admonition name.
     *
     * @param string $s
     * @param \MarkupCarve\Carve\Node $node
     * @param int $start
     * @param int $from
     *
     * @return string
     */
    private static function fallback(string $s, Node $node, int $start, int $from): string
    {
        $lineEnd = self::lineEnd($s, $start);
        $end = $from;
        while ($end < $lineEnd && $s[$end] === '{') {
            $close = strpos($s, '}', $end);
            if ($close === false || $close >= $lineEnd) {
                break;
            }
            $end = $close + 1;
        }
        $attributes = substr($s, $from, $end - $from);
        $opener = trim(substr($s, $start, $from - $start) . substr($s, $end, $lineEnd - $end), " \t\r");

        $slots = ['attributes' => $attributes, 'opener' => $opener];

        return Message::fill('fence-opener-fallback', $slots, in_array($node->type, ['code_block', 'raw_block'], true) ? 0 : 1);
    }
}
