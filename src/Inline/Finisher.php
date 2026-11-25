<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Inline;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;

/**
 * The last step of the scan, attachment (C2-I-009, C2-I-036), then smart typography (C2-I-104 to
 * C2-I-117) and abbreviations (C2-I-098 to C2-I-100) on the text the scan left, and the joining of
 * adjacent text (C2-A-040).
 */
final class Finisher
{
    private const START = -1;

    /**
     * @var int
     */
    private const NODE_END = 0x61;

    /**
     * The abbreviation nodes made so far; a parse that made none has no expansion to limit.
     */
    public static int $abbreviations = 0;

    private string $t;

    /**
     * Last visible character in document order, for the word test of abbreviations (C2-I-099).
     */
    private int $visible = -1;

    /**
     * End offset and kind of the last smart punctuation node of the current text run.
     */
    private int $smartEnd = -2;

    private string $smartKind = '';

    private bool $captionSeen = false;

    /**
     * @var array<string, array{kind: string, glyph: string, source: string}>
     */
    private static array $smartFields = [];

    /**
     * @param \MarkupCarve\Carve\Inline\Scanner $sc
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     */
    public function __construct(private Scanner $sc, private InlineContext $ctx)
    {
        $this->t = $sc->t;
    }

    /**
     * @param array<int, array<mixed>> $items
     * @param int $after Visible character after the list (-1 for none).
     * @param bool $top The list of the content itself, not of a node in it.
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public function build(array $items, int $after = -1, bool $top = false): array
    {
        if ($this->sc->attaches) {
            $items = $this->attach($items, $top);
        }
        $out = [];
        $prev = self::START;
        $count = count($items);
        $k = 0;
        while ($k < $count) {
            $item = $items[$k];
            if ($item[0] === Scanner::TEXT) {
                if (($items[$k + 1][0] ?? -1) !== Scanner::TEXT && $this->plainNode($item, $out)) {
                    $prev = $this->visible;
                    $k++;

                    continue;
                }
                $run = [];
                while ($k < $count && $items[$k][0] === Scanner::TEXT) {
                    $run[] = $items[$k];
                    $k++;
                }
                $next = $this->ctx->abbreviations === [] ? -1 : $this->firstVisible($items, $k, $after);
                $this->textRun($run, $prev, $next, $out);
                $last = $run[count($run) - 1];
                assert(is_int($last[3]));
                $prev = $this->context($last[3], Chars::before($this->t, $last[3]));

                continue;
            }
            $node = $item[1];
            assert($node instanceof Node);
            $prev = $this->nodeItem($item, $node, $prev, $items, $k + 1, $after);
            $out[] = $node;
            $k++;
        }

        return $out;
    }

    /**
     * @param array<mixed> $item
     * @param \MarkupCarve\Carve\Node $node
     * @param int $prev
     * @param array<int, array<mixed>> $items
     * @param int $k
     * @param int $after
     *
     * @return int The context for the next quote.
     */
    private function nodeItem(array $item, Node $node, int $prev, array $items, int $k, int $after): int
    {
        if ($item[5] instanceof TokenList && count($item[5]->list) === 1) {
            $children = [];
            if ($item[5]->list[0][0] === Scanner::TEXT && $this->plainNode($item[5]->list[0], $children)) {
                $node->children = $children;

                return self::NODE_END;
            }
        }
        if ($item[5] instanceof TokenList && $node->type === 'inline_footnote') {
            // C2-I-109: an inline footnote is a flow of its own, so no word runs across its brackets.
            $this->visible = -1;
            $node->children = $this->build($item[5]->list);
            $this->visible = -1;

            return self::NODE_END;
        }
        if ($item[5] instanceof TokenList) {
            $inner = $this->ctx->abbreviations === [] ? -1 : $this->firstVisible($items, $k, $after);
            $node->children = $this->build($item[5]->list, $inner);

            return self::NODE_END;
        }
        $type = $node->type;
        if ($type === 'comment' && ($node->f['form'] ?? '') === 'braced') {
            // C2-I-026: a braced comment counts as nothing for its neighbors.
            return $prev;
        }
        $this->visible = -1;

        return match ($type) {
            'soft_break', 'hard_break', 'comment' => 0x20,
            'non_breaking_space' => 0xA0,
            default => self::NODE_END,
        };
    }

    /**
     * Whether typography can replace anything in `$value`: a quote, or the first two characters of a
     * replacement source or of a dash run (C2-I-104 to C2-I-117).
     *
     * @param string $value
     *
     * @return bool
     */
    public static function replaces(string $value): bool
    {
        if (strcspn($value, Tables::SMART_TRIGGERS) === strlen($value)) {
            return false;
        }
        static $pattern = null;
        if ($pattern === null) {
            $starts = ['"' => true, "'" => true, '--' => true];
            foreach (array_keys(Tables::SMART_RUNS) as $source) {
                $starts[substr((string)$source, 0, 2)] = true;
            }
            $pattern = '/' . implode('|', array_map(static fn ($p): string => preg_quote((string)$p, '/'), array_keys($starts))) . '/';
        }

        // C2-I-104: fixed two-byte literals, no quantifier, linear; hot, measured 2.5x faster than a str_contains loop.
        return preg_match($pattern, $value) === 1;
    }

    /**
     * A lone text item with nothing to replace becomes one text node, as textRun would make it.
     *
     * @param array<mixed> $item
     * @param array<\MarkupCarve\Carve\Node> $out
     *
     * @return bool
     */
    private function plainNode(array $item, array &$out): bool
    {
        [, $value, $ts, $te, $lit] = $item;
        if (
            $lit === true || !is_string($value) || !is_int($ts) || !is_int($te) || $value === ''
            || $te - $ts !== strlen($value) || $this->ctx->abbreviations !== []
            || strcspn($value, Tables::SMART_TRIGGERS) !== strlen($value) && self::replaces($value)
        ) {
            return false;
        }
        $c = $this->sc->c;
        $out[] = $c->base >= 0
            ? Node::text($value, $c->base + $ts, $c->base + $te)
            : Node::text($value, $c->src($ts), $c->src($te));
        $this->smartEnd = -2;
        $last = ord($this->t[$te - 1]);
        $this->visible = $last < 0x80 ? $last : Chars::before($this->t, $te);

        return true;
    }

    /**
     * C2-I-036, C2-I-039: an attribute block glued to the end of a formed host attaches; otherwise it is
     * text as written (C2-I-035).
     *
     * @param array<int, array<mixed>> $items
     * @param bool $top
     *
     * @return array<int, array<mixed>>
     */
    private function attach(array $items, bool $top): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item[0] === Scanner::NODE && $item[1] instanceof Node && $item[1]->type === 'caption_number') {
                // caption-number.json: only the first bare mark of the top-level text counts.
                if (!$top || $this->captionSeen) {
                    $item = [Scanner::TEXT, '#', $item[2], $item[3], false];
                }
                $this->captionSeen = true;
            }
            if ($item[0] !== Scanner::ATTR) {
                $out[] = $item;

                continue;
            }
            $attrs = $item[1];
            assert($attrs instanceof Attrs && is_int($item[2]) && is_int($item[3]));
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last][0] === Scanner::NODE && $out[$last][4] === true && $out[$last][3] === $item[2]) {
                $host = $out[$last][1];
                assert($host instanceof Node);
                $attrs->applyTo($host);
                $host->end = $this->sc->c->src($item[3]);
                $out[$last][3] = $item[3];

                continue;
            }
            $mark = $top && !$this->captionSeen && $this->sc->caption ? $this->bareMark($item[2], $item[3]) : -1;
            if ($mark < 0) {
                $out[] = [Scanner::TEXT, substr($this->t, $item[2], $item[3] - $item[2]), $item[2], $item[3], true];

                continue;
            }
            // C2-B-207: an attribute block that stays text is caption text, so its first bare `#` is the mark.
            $this->captionSeen = true;
            if ($mark > $item[2]) {
                $out[] = [Scanner::TEXT, substr($this->t, $item[2], $mark - $item[2]), $item[2], $mark, true];
            }
            $out[] = [Scanner::NODE, $this->sc->node('caption_number', $mark, $mark + 1), $mark, $mark + 1, false, null];
            $out[] = [Scanner::TEXT, substr($this->t, $mark + 1, $item[3] - $mark - 1), $mark + 1, $item[3], true];
        }

        return $out;
    }

    /**
     * The offset of the first `#` in `[$from, $to)` that no mention character follows, or -1.
     */
    private function bareMark(int $from, int $to): int
    {
        for ($i = $from; $i < $to; $i++) {
            $i += strcspn($this->t, '#', $i, $to - $i);
            if ($i < $to && ($i + 1 >= strlen($this->t) || strspn($this->t[$i + 1], Tables::SIGIL_MENTION_CHARS) === 0)) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * The first visible character from item `$k` on, descending into nodes with content.
     *
     * @param array<int, array<mixed>> $items
     * @param int $k
     * @param int $after
     *
     * @return int
     */
    private function firstVisible(array $items, int $k, int $after): int
    {
        while (true) {
            if ($k >= count($items)) {
                return $after;
            }
            $item = $items[$k];
            if ($item[0] === Scanner::TEXT || $item[0] === Scanner::ATTR) {
                assert(is_int($item[2]));

                return Chars::at($this->t, $item[2]);
            }
            // A braced comment shows nothing, so the flow passes through it (C2-I-099).
            if ($item[1] instanceof Node && $item[1]->type === 'comment') {
                $k++;

                continue;
            }
            // An inline footnote shows a marker, not its content (C2-I-099).
            if (!$item[5] instanceof TokenList || ($item[1] instanceof Node && $item[1]->type === 'inline_footnote')) {
                return -1;
            }
            if ($item[5]->list === []) {
                $k++;

                continue;
            }
            $after = $this->firstVisible($items, $k + 1, $after);
            $items = $item[5]->list;
            $k = 0;
        }
    }

    /**
     * @param array<int, array<mixed>> $run
     * @param int $prev
     * @param int $next
     * @param array<\MarkupCarve\Carve\Node> $out
     *
     * @return void
     */
    private function textRun(array $run, int $prev, int $next, array &$out): void
    {
        $pieces = [];
        $first = $run[0][2];
        assert(is_int($first));
        // C2-I-026: a braced comment right after a replacement leaves its context in place.
        $pc = $first;
        while (isset($this->sc->commentStart[$pc])) {
            $pc = $this->sc->commentStart[$pc];
        }
        $this->smartEnd = $pc !== $first && $pc === $this->smartEnd ? $first : -2;
        $count = count($run);
        [, $value, $ts, $te, $lit] = $run[0];
        if (
            $count === 1 && $lit !== true && is_string($value) && is_int($ts) && is_int($te) && $value !== ''
            && $te - $ts === strlen($value) && $this->ctx->abbreviations === []
            && strcspn($value, Tables::SMART_TRIGGERS) === $te - $ts
        ) {
            // A text with nothing to replace is one text node.
            $out[] = Node::text($value, $this->sc->c->src($ts), $this->sc->c->src($te));
            $this->visible = Chars::before($this->t, $te);

            return;
        }
        $k = 0;
        while ($k < $count) {
            [, $value, $ts, $te, $lit] = $run[$k];
            assert(is_string($value) && is_int($ts) && is_int($te));
            $k++;
            if ($lit === true || $te - $ts !== strlen($value)) {
                $pieces[] = [$value, $ts, $te];
                $this->visible = $value === '' ? $this->visible : Chars::before($value, strlen($value));

                continue;
            }
            // Failed openers and closers are text like any other: runs read across them (C2-I-060).
            while ($k < $count && $this->joins($run[$k], $te)) {
                $te = $run[$k][3];
                assert(is_int($te));
                $k++;
            }
            $after = $k < $count && $this->ctx->abbreviations !== [] ? $this->leading($run, $k, $next) : $next;
            $this->segment($ts, $te, $first, $prev, $after, $pieces);
        }
        $this->emitPieces($pieces, $out);
    }

    /**
     * C2-I-099: the first visible character of the run from item `$k`, so a word before a literal item
     * reads that item's text, not the character after the whole run.
     *
     * @param array<int, array<mixed>> $run
     * @param int $k
     * @param int $next
     *
     * @return int
     */
    private function leading(array $run, int $k, int $next): int
    {
        $count = count($run);
        for (; $k < $count; $k++) {
            $value = $run[$k][1];
            if (is_string($value) && $value !== '') {
                return Chars::at($value, 0);
            }
        }

        return $next;
    }

    /**
     * A text item that reads on from `$te` as written.
     *
     * @param array<mixed> $item
     * @param int $te
     *
     * @return bool
     */
    private function joins(array $item, int $te): bool
    {
        return $item[4] !== true && $item[2] === $te && is_string($item[1]) && is_int($item[3]) && $item[3] - $te === strlen($item[1]);
    }

    /**
     * @param int $ts
     * @param int $te
     * @param int $first
     * @param int $prev
     * @param int $next
     * @param array<int, array<mixed>|\MarkupCarve\Carve\Node> $pieces
     *
     * @return void
     */
    private function segment(int $ts, int $te, int $first, int $prev, int $next, array &$pieces): void
    {
        $t = $this->t;
        $abbr = $this->ctx->abbreviations !== [];
        $set = Tables::SMART_TRIGGERS . ($abbr ? 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' : '');
        $j = $ts;
        $plain = $ts;
        while ($j < $te) {
            $j += strcspn($t, $set, $j, $te - $j);
            if ($j >= $te) {
                break;
            }
            $c = $t[$j];
            if (ctype_alnum($c)) {
                $len = strspn($t, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', $j, $te - $j);
                $word = substr($t, $j, $len);
                if (isset($this->ctx->abbreviations[$word]) && $this->wordEdges($j, $len, $te, $first, $next)) {
                    $this->plain($plain, $j, $pieces);
                    $node = $this->sc->node('abbreviation', $j, $j + $len);
                    self::$abbreviations++;
                    $node->f['key'] = $word;
                    $node->f['namespace'] = 'abbr';
                    $node->f['expansion'] = $this->ctx->abbreviations[$word];
                    $pieces[] = $node;
                    $plain = $j + $len;
                }
                $j += $len;
                $this->visible = ord($t[$j - 1]);

                continue;
            }
            $matches = $c === '"' || $c === "'" ? [$this->quote($j, $first, $prev)] : $this->run($j, $te, $first, $prev);
            if ($matches === []) {
                $j++;

                continue;
            }
            $this->plain($plain, $j, $pieces);
            foreach ($matches as [$kind, $len]) {
                $node = $this->sc->node('smart_punctuation', $j, $j + $len);
                $node->f = $this->smartFields($kind, substr($t, $j, $len));
                $pieces[] = $node;
                $j += $len;
                $this->smartEnd = $j;
                $this->smartKind = $kind;
            }
            $plain = $j;
        }
        $this->plain($plain, $te, $pieces);
        if ($te > $ts) {
            $this->visible = Chars::before($t, $te);
        }
    }

    /**
     * One shared field array per short spelling: a run of dashes is otherwise one hash array per node.
     *
     * @return array{kind: string, glyph: string, source: string}
     */
    private function smartFields(string $kind, string $source): array
    {
        $key = $kind . "\0" . $source;
        if (isset(self::$smartFields[$key])) {
            return self::$smartFields[$key];
        }
        $fields = [
            'kind' => $kind,
            'glyph' => Tables::SMART_GLYPHS[$kind] ?? Tables::SMART_RUNS[$source][1] ?? '',
            'source' => $source,
        ];
        if (strlen($source) <= 3) {
            self::$smartFields[$key] = $fields;
        }

        return $fields;
    }

    /**
     * @param int $from
     * @param int $to
     * @param array<int, array<mixed>|\MarkupCarve\Carve\Node> $pieces
     *
     * @return void
     */
    private function plain(int $from, int $to, array &$pieces): void
    {
        if ($to > $from) {
            $pieces[] = [substr($this->t, $from, $to - $from), $from, $to];
        }
    }

    /**
     * C2-I-099: the characters on both sides are absent or no word characters; element edges are no
     * boundary of their own.
     *
     * @param int $j
     * @param int $len
     * @param int $te
     * @param int $first
     * @param int $next
     *
     * @return bool
     */
    private function wordEdges(int $j, int $len, int $te, int $first, int $next): bool
    {
        $before = $j > $first ? Chars::before($this->t, $j) : $this->visible;
        $after = $j + $len < $te ? Chars::at($this->t, $j + $len) : $next;

        return !Chars::isWord($before) && !Chars::isWord($after);
    }

    /**
     * @param array<int, mixed> $pieces
     * @param array<\MarkupCarve\Carve\Node> $out
     *
     * @return void
     */
    private function emitPieces(array $pieces, array &$out): void
    {
        $value = '';
        $start = -1;
        $end = -1;
        foreach ($pieces as $p) {
            if ($p instanceof Node) {
                if ($start >= 0) {
                    $out[] = Node::text($value, $this->sc->c->src($start), $this->sc->c->src($end));
                    $value = '';
                    $start = -1;
                }
                $out[] = $p;

                continue;
            }
            assert(is_array($p) && is_string($p[0]) && is_int($p[1]) && is_int($p[2]));
            if ($start < 0) {
                $start = $p[1];
            }
            $value .= $p[0];
            $end = $p[2];
        }
        if ($start >= 0) {
            $out[] = Node::text($value, $this->sc->c->src($start), $this->sc->c->src($end));
        }
    }

    /**
     * C2-I-104: an emitted quote gives its glyph to the next neighbor test.
     *
     * @param int $j
     * @param int $before
     *
     * @return int
     */
    private function context(int $j, int $before): int
    {
        if ($j !== $this->smartEnd) {
            return $before;
        }

        return match ($this->smartKind) {
            'em_dash' => 0x2014,
            'en_dash' => 0x2013,
            'left_double_quote' => 0x201C,
            'left_single_quote' => 0x2018,
            'right_double_quote', 'right_single_quote' => self::NODE_END,
            default => self::NODE_END,
        };
    }

    /**
     * C2-I-104, C2-I-105: a straight quote reads only its immediate neighbors.
     *
     * @param int $j
     * @param int $first
     * @param int $prev
     *
     * @return array{0: string, 1: int}
     */
    private function quote(int $j, int $first, int $prev): array
    {
        $t = $this->t;
        $before = $j > $first ? Chars::before($t, $j) : $prev;
        $before = $this->context($j, $before);
        $after = false;
        if ($before >= 0) {
            $after = $before < 128
                ? str_contains(Tables::SMART_QUOTE_OPENS_AFTER, chr($before))
                : mb_strpos(Tables::SMART_QUOTE_OPENS_AFTER, mb_chr($before, 'UTF-8')) !== false;
        }
        $opens = $before === self::START || Chars::isSpace($before) || $after;
        if ($t[$j] === '"') {
            return [$opens ? 'left_double_quote' : 'right_double_quote', 1];
        }

        $next = $this->sc->nextCp($j + 1);

        return [$opens && ($next < 0x30 || $next > 0x39) ? 'left_single_quote' : 'right_single_quote', 1];
    }

    /**
     * C2-I-111 to C2-I-117: the run at `$j`, longest match first.
     *
     * @param int $j
     * @param int $te
     * @param int $first
     * @param int $prev
     *
     * @return array<array{0: string, 1: int}>
     */
    private function run(int $j, int $te, int $first, int $prev): array
    {
        $t = $this->t;
        $rest = $te - $j;
        foreach (Tables::SMART_RUNS_BY_FIRST[$t[$j]] ?? [] as $src) {
            $len = strlen($src);
            if ($len > $rest || substr_compare($t, $src, $j, $len) !== 0) {
                continue;
            }
            $dash = strpos($src, '--');
            // C2-I-116: an arrow needs its exact hyphen run.
            if ($dash !== false && $j + $dash + 2 < $te && $t[$j + $dash + 2] === '-') {
                continue;
            }
            if ($src === '+-' && !$this->plusMinusAlone($j, $te, $first, $prev)) {
                continue;
            }

            return [[Tables::SMART_RUNS[$src][0], $len]];
        }
        if ($t[$j] !== '-') {
            return [];
        }
        $len = strspn($t, '-', $j, $rest);

        return $len < 2 ? [] : self::dashes($len);
    }

    /**
     * C2-I-117: `+-` next to another `+` or `-` is text. Only a neighbor in the same text counts, seen
     * through a braced comment (C2-I-026); the character after it is read from the source (G-inline-2).
     *
     * @param int $j
     * @param int $te
     * @param int $first
     * @param int $prevContext The character before the text, seen through braced comments.
     *
     * @return bool
     */
    private function plusMinusAlone(int $j, int $te, int $first, int $prevContext): bool
    {
        $t = $this->t;
        $prev = -1;
        if ($j === $first) {
            $prev = $prevContext;
        } elseif ($j !== $this->smartEnd) {
            $prev = ord($t[$j - 1]);
        }
        $afterAt = $j + 2;
        $after = $afterAt < $te || ($afterAt === $te && substr($t, $te, 2) === '{%')
            ? $this->sc->nextCp($afterAt)
            : -1;

        return $prev !== 0x2B && $prev !== 0x2D && $after !== 0x2B && $after !== 0x2D;
    }

    /**
     * C2-I-111: a hyphen run of `$len` as dashes.
     *
     * @param int $len
     *
     * @return array<array{0: string, 1: int}>
     */
    private static function dashes(int $len): array
    {
        $out = [];
        while ($len > 0) {
            if ($len % 3 === 0) {
                return array_merge($out, array_fill(0, intdiv($len, 3), ['em_dash', 3]));
            }
            if ($len % 2 === 0) {
                return array_merge($out, array_fill(0, intdiv($len, 2), ['en_dash', 2]));
            }
            $out[] = ['em_dash', 3];
            $len -= 3;
        }

        return $out;
    }
}
