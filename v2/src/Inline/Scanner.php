<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Inline;

use MarkupCarve\Carve2\AttributeParser;
use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Generated\Tables;
use MarkupCarve\Carve2\Node;

/**
 * The first two steps of the scan (C2-I-004): atoms and the bracket stack. The result is a token list
 * in which every formed bracket construct is one token holding the tokens of its content; the
 * delimiter stack runs on those lists afterwards (C2-I-007).
 *
 * Token shapes: [0, value, ts, te, literal] text; [1, Node, ts, te, host, inner TokenList or null] node;
 * [2, sub, ts, te, mark, canOpen, canClose, poison, fallback] delimiter; [3, Attrs, ts, te] attribute block.
 */
final class Scanner
{
    /**
     * @var int
     */
    public const TEXT = 0;

    /**
     * @var int
     */
    public const NODE = 1;

    /**
     * @var int
     */
    public const DELIM = 2;

    /**
     * @var int
     */
    public const ATTR = 3;

    /**
     * @var int
     */
    public const BARE = 0;

    /**
     * @var int
     */
    public const OPENER = 1;

    /**
     * @var int
     */
    public const CLOSER = 2;

    /**
     * @var string
     */
    /**
     * C2-B-144: no line comment in a line block.
     *
     * @var int
     */
    public const LINE_BLOCK = 1;

    /**
     * C2-A-068: every line end of a hard-break block is a hard break.
     *
     * @var int
     */
    public const HARD_BREAKS = 2;

    /**
     * C2-B-150: the content of a caption reads the caption number mark.
     *
     * @var int
     */
    public const CAPTION = 4;

    /**
     * C2-B-223: the content of a table cell.
     *
     * @var int
     */
    public const CELL = 8;

    private bool $lineBlock;

    private bool $hardBreaks;

    private bool $caption;

    private bool $cell;

    /**
     * @var string
     */
    public const SPECIALS = "\\`!\$<{}%:@#[]^*/\n";

    /**
     * @var int
     */
    private const F_BRACKET = 0;

    /**
     * @var int
     */
    private const F_IMAGE = 1;

    /**
     * @var int
     */
    private const F_FOOTNOTE = 2;

    /**
     * @var int
     */
    private const F_EXTENSION = 3;

    public string $t;

    public int $n;

    /**
     * @var array<int, array<mixed>>
     */
    public array $tok = [];

    /**
     * Open bracket frames: [kind, token index, opener start, content start, name].
     *
     * @var array<array{0: int, 1: int, 2: int, 3: int, 4: string}>
     */
    private array $frames = [];

    private int $textStart = 0;

    /**
     * End offset of a braced comment to its start, for the neighbor tests of C2-I-026.
     *
     * @var array<int, int>
     */
    public array $commentStart = [];

    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private array $nextCache = [];

    /**
     * Backtick run length to the run starts in order, and the read pointer per length.
     *
     * @var array<int, array<int>>|null
     */
    private ?array $runs = null;

    /**
     * @var array<int, int>
     */
    private array $runPtr = [];

    /**
     * @var array{0: int, 1: int, 2: int}
     */
    private array $labelCache = [-1, -1, -1];

    /**
     * @var array{0: int, 1: int}
     */
    private array $xrefCache = [-1, -1];

    private ?LinkTail $tails = null;

    /**
     * @param \MarkupCarve\Carve2\Inline\Content $c
     * @param \MarkupCarve\Carve2\Inline\InlineContext $ctx
     * @param int $depth Block depth of the leaf (C2-I-118).
     * @param int $mode Bits of LINE_BLOCK, HARD_BREAKS, CAPTION and CELL.
     */
    public function __construct(
        public Content $c,
        private InlineContext $ctx,
        public int $depth,
        int $mode = 0,
    ) {
        $this->t = $c->t;
        $this->n = strlen($this->t);
        $this->lineBlock = ($mode & self::LINE_BLOCK) !== 0;
        $this->hardBreaks = ($mode & self::HARD_BREAKS) !== 0;
        $this->caption = ($mode & self::CAPTION) !== 0;
        $this->cell = ($mode & self::CELL) !== 0;
    }

    /**
     * @return array<int, array<mixed>>
     */
    public function scan(): array
    {
        $t = $this->t;
        $n = $this->n;
        $i = 0;
        while ($i < $n) {
            $i += strcspn($t, self::SPECIALS, $i);
            if ($i >= $n) {
                break;
            }
            $next = $this->special($i);
            $i = $next < 0 ? $i + 1 : $next;
        }
        $this->flush($n);
        $this->frames = [];

        return $this->tok;
    }

    /**
     * @param int $i
     *
     * @return int The offset to go on at, or -1 when the character is text.
     */
    private function special(int $i): int
    {
        $t = $this->t;

        return match ($t[$i]) {
            '\\' => $this->backslash($i),
            '`' => $this->codeSpan($i, $i, 'code'),
            '!' => $this->bang($i),
            '$' => $this->dollar($i),
            '<' => $this->angle($i),
            '{' => $this->brace($i),
            '}' => $this->closingBrace($i),
            '%' => $this->lineComment($i),
            ':' => $this->colon($i),
            '@', '#' => $this->sigil($i),
            '[' => $this->openBracket($i),
            ']' => $this->closeBracket($i),
            '^' => $i + 1 < $this->n && $t[$i + 1] === '[' ? $this->pushFrame(self::F_FOOTNOTE, $i, $i + 2, '') : -1,
            '*', '/' => $this->bare($i),
            "\n" => $this->lineBreak($i, $i, $this->hardBreaks ? 'hard_break' : 'soft_break'),
            default => -1,
        };
    }

    /**
     * Turns the pending text before `$ts` into a token.
     *
     * @param int $ts
     *
     * @return void
     */
    private function flush(int $ts): void
    {
        if ($ts > $this->textStart) {
            $this->tok[] = [self::TEXT, substr($this->t, $this->textStart, $ts - $this->textStart), $this->textStart, $ts, false];
        }
    }

    /**
     * @param array<mixed> $token
     *
     * @return int
     */
    private function emit(array $token): int
    {
        assert(is_int($token[2]) && is_int($token[3]));
        $this->flush($token[2]);
        $this->tok[] = $token;
        $this->textStart = $token[3];

        return $token[3];
    }

    /**
     * @param string $type
     * @param int $ts
     * @param int $te
     *
     * @return \MarkupCarve\Carve2\Node
     */
    public function node(string $type, int $ts, int $te): Node
    {
        return Node::at($type, $this->c->src($ts), $this->c->src($te));
    }

    /**
     * @param string $needle
     * @param int $from
     *
     * @return int
     */
    public function nextPos(string $needle, int $from): int
    {
        $c = $this->nextCache[$needle] ?? null;
        if ($c !== null && $from >= $c[0] && ($c[1] < 0 || $from <= $c[1])) {
            return $c[1];
        }
        $p = $from > $this->n ? false : strpos($this->t, $needle, $from);
        $r = $p === false ? -1 : $p;
        $this->nextCache[$needle] = [$from, $r];

        return $r;
    }

    /**
     * @param int $i
     *
     * @return int
     */
    private function lineEnd(int $i): int
    {
        $p = $this->nextPos("\n", $i);

        return $p < 0 ? $this->n : $p;
    }

    /**
     * Code point before `$i` with braced comments skipped (C2-I-026).
     *
     * @param int $i
     *
     * @return int
     */
    public function prevCp(int $i): int
    {
        while (isset($this->commentStart[$i])) {
            $i = $this->commentStart[$i];
        }

        return Chars::before($this->t, $i);
    }

    /**
     * Code point at `$i` with braced comments skipped; an escaped space reads as U+00A0 (C2-I-011).
     *
     * @param int $i
     *
     * @return int
     */
    public function nextCp(int $i): int
    {
        $t = $this->t;
        while ($i + 1 < $this->n && $t[$i] === '{' && $t[$i + 1] === '%') {
            $end = $this->braceCommentEnd($i);
            if ($end < 0) {
                break;
            }
            $i = $end;
        }
        if ($i + 1 < $this->n && $t[$i] === '\\' && $t[$i + 1] === ' ') {
            return 0xA0;
        }

        return Chars::at($t, $i);
    }

    /**
     * @param int $i
     *
     * @return int
     */
    private function braceCommentEnd(int $i): int
    {
        if (Chars::isGlue(Chars::at($this->t, $i + 2))) {
            return -1;
        }
        $p = $this->nextPos('%}', $i + 2);

        return $p < 0 ? -1 : $p + 2;
    }

    /**
     * C2-I-010 to C2-I-012, C2-I-102.
     *
     * @param int $i
     *
     * @return int
     */
    private function backslash(int $i): int
    {
        $t = $this->t;
        if ($i + 1 >= $this->n) {
            return $this->emit([self::NODE, $this->node('hard_break', $i, $i + 1), $i, $i + 1, false, null]);
        }
        $c = $t[$i + 1];
        if ($c === "\n") {
            return $this->lineBreak($i, $i + 1, 'hard_break');
        }
        if ($c === ' ') {
            return $this->emit([self::NODE, $this->node('non_breaking_space', $i, $i + 2), $i, $i + 2, false, null]);
        }
        if (ctype_punct($c)) {
            return $this->emit([self::TEXT, $c, $i, $i + 2, true]);
        }

        return -1;
    }

    /**
     * C2-I-102, C2-I-103: a break owns its line ending and the indent after it.
     *
     * @param int $start
     * @param int $nl
     * @param string $type
     *
     * @return int
     */
    private function lineBreak(int $start, int $nl, string $type): int
    {
        $end = $nl + 1 + strspn($this->t, " \t", $nl + 1);

        return $this->emit([self::NODE, $this->node($type, $start, $end), $start, $end, false, null]);
    }

    /**
     * @param int $i
     *
     * @return int
     */
    private function bang(int $i): int
    {
        $c = $this->t[$i + 1] ?? '';
        if ($c === '`') {
            return $this->codeSpan($i, $i + 1, 'literal');
        }
        if ($c === '[') {
            return $this->pushFrame(self::F_IMAGE, $i, $i + 2, '');
        }

        return -1;
    }

    /**
     * C2-I-019: `$` and `$$` glued before a backtick run.
     *
     * @param int $i
     *
     * @return int
     */
    private function dollar(int $i): int
    {
        $t = $this->t;
        if ($i + 2 < $this->n && $t[$i + 1] === '$' && $t[$i + 2] === '`') {
            return $this->codeSpan($i, $i + 2, 'display');
        }
        if ($i + 1 < $this->n && $t[$i + 1] === '`') {
            return $this->codeSpan($i, $i + 1, 'inline');
        }

        return -1;
    }

    /**
     * The next backtick run of exactly `$len` at or after `$from`, or -1.
     *
     * @param int $len
     * @param int $from
     *
     * @return int
     */
    private function closingRun(int $len, int $from): int
    {
        if ($this->runs === null) {
            $this->runs = [];
            $t = $this->t;
            $p = 0;
            while (($p = strpos($t, '`', $p)) !== false) {
                $l = strspn($t, '`', $p);
                $this->runs[$l][] = $p;
                $p += $l;
            }
        }
        $list = $this->runs[$len] ?? [];
        $k = $this->runPtr[$len] ?? 0;
        $count = count($list);
        while ($k < $count && $list[$k] < $from) {
            $k++;
        }
        $this->runPtr[$len] = $k;

        return $k < $count ? $list[$k] : -1;
    }

    /**
     * C2-I-014 to C2-I-022.
     *
     * @param int $start
     * @param int $run
     * @param string $kind
     *
     * @return int
     */
    private function codeSpan(int $start, int $run, string $kind): int
    {
        $t = $this->t;
        $len = strspn($t, '`', $run);
        $close = $this->closingRun($len, $run + $len);
        if ($close < 0 && $this->cell) {
            // C2-B-223: an unclosed code span at the end of a cell is text.
            return $run + $len;
        }
        if ($close < 0) {
            // C2-I-017: an unclosed span runs to the end of the content.
            $value = rtrim(substr($t, $run + $len), " \t\n");
            $end = $this->n;
        } else {
            $value = substr($t, $run + $len, $close - $run - $len);
            $end = $close + $len;
            // C2-I-016
            $vl = strlen($value);
            if ($vl >= 2 && $value[0] === ' ' && $value[$vl - 1] === ' ' && strspn($value, ' ') !== $vl) {
                $value = substr($value, 1, -1);
            }
        }
        if ($kind === 'code' && $close >= 0) {
            $raw = $this->rawSuffix($end);
            if ($raw !== null) {
                $node = $this->node('raw_inline', $start, $raw[1]);
                $node->f['format'] = $raw[0];
                $node->f['value'] = $value;

                return $this->emit([self::NODE, $node, $start, $raw[1], false, null]);
            }
        }
        if ($kind === 'code') {
            $node = $this->node('code', $start, $end);
        } elseif ($kind === 'literal') {
            $node = $this->node('literal_inline', $start, $end);
        } else {
            $node = $this->node('math', $start, $end);
            $node->f['display'] = $kind === 'display';
        }
        $node->f['value'] = $value;

        return $this->emit([self::NODE, $node, $start, $end, true, null]);
    }

    /**
     * C2-I-021: `{=name}` glued to a closed plain code span.
     *
     * @param int $i
     *
     * @return array{0: string, 1: int}|null
     */
    private function rawSuffix(int $i): ?array
    {
        $t = $this->t;
        if ($i + 2 >= $this->n || $t[$i] !== '{' || $t[$i + 1] !== '=' || strspn($t[$i + 2], Tables::RAW_NAME_FIRST) === 0) {
            return null;
        }
        $k = 1 + strspn($t, Tables::RAW_NAME_REST, $i + 3);
        if ($i + 2 + $k >= $this->n || $t[$i + 2 + $k] !== '}') {
            return null;
        }

        return [substr($t, $i + 2, $k), $i + 3 + $k];
    }

    /**
     * C2-I-073, C2-I-096, C2-I-122 to C2-I-124.
     *
     * @param int $i
     *
     * @return int
     */
    private function angle(int $i): int
    {
        $t = $this->t;
        if ($i + 2 < $this->n && $t[$i + 1] === '/' && $t[$i + 2] === '#') {
            $from = $i + 3;
            if ($from > $this->xrefCache[0] && $from <= $this->xrefCache[1]) {
                $stop = $this->xrefCache[1];
            } else {
                $stop = $from + strcspn($t, "> \n", $from);
                $this->xrefCache = [$from, $stop];
            }
            if ($stop > $from && $stop < $this->n && $t[$stop] === '>') {
                $node = $this->node('xref', $i, $stop + 1);
                $node->f['key'] = substr($t, $from, $stop - $from);
                $node->f['namespace'] = 'heading';

                return $this->emit([self::NODE, $node, $i, $stop + 1, false, null]);
            }

            return -1;
        }
        $gt = $this->nextPos('>', $i + 1);
        if ($gt < 0) {
            return -1;
        }
        $body = substr($t, $i + 1, $gt - $i - 1);
        $email = false;
        if (!self::isUrl($body)) {
            if (!self::isEmail($body)) {
                return -1;
            }
            $email = true;
        }
        $node = $this->node('autolink', $i, $gt + 1);
        $node->f['href'] = $email ? 'mailto:' . $body : $body;
        if ($email) {
            $node->f['text'] = $body;
        }

        return $this->emit([self::NODE, $node, $i, $gt + 1, true, null]);
    }

    /**
     * @param string $b
     *
     * @return bool
     */
    private static function isUrl(string $b): bool
    {
        $len = strlen($b);
        if ($len < 3 || strspn($b[0], Tables::AUTOLINK_SCHEME_FIRST) === 0) {
            return false;
        }
        $k = 1 + strspn($b, Tables::AUTOLINK_SCHEME_REST, 1);
        if ($k >= $len - 1 || $b[$k] !== ':') {
            return false;
        }
        $p = $k + 1;
        while ($p < $len) {
            $p += strspn($b, Tables::AUTOLINK_URL_CHARS, $p);
            if ($p >= $len) {
                return true;
            }
            if (ord($b[$p]) < 0x80) {
                return false;
            }
            $q = $p;
            while ($q < $len && ord($b[$q]) >= 0x80) {
                $q++;
            }
            $chunk = substr($b, $p, $q - $p);
            if (!mb_check_encoding($chunk, 'UTF-8') || Chars::hasNonUrlUnicode($chunk)) {
                return false;
            }
            $p = $q;
        }

        return true;
    }

    /**
     * @param string $b
     *
     * @return bool
     */
    private static function isEmail(string $b): bool
    {
        $len = strlen($b);
        $at = strspn($b, Tables::AUTOLINK_EMAIL_CHARS);
        if ($at === 0 || $at >= $len || $b[$at] !== '@') {
            return false;
        }
        $dom = $at + 1;
        if (strspn($b, Tables::AUTOLINK_EMAIL_CHARS, $dom) !== $len - $dom) {
            return false;
        }
        $dot = strrpos($b, '.');
        if ($dot === false || $dot <= $dom || $dot === $len - 1) {
            return false;
        }

        return strspn($b, Tables::AUTOLINK_EMAIL_LAST, $dot + 1) === $len - $dot - 1;
    }

    /**
     * C2-I-043: attribute block, braced comment, editorial comment, braced opener, text.
     *
     * @param int $i
     *
     * @return int
     */
    private function brace(int $i): int
    {
        $t = $this->t;
        $attrs = new Attrs();
        $end = AttributeParser::read($t, $i, $this->lineEnd($i), $attrs);
        if ($end > 0 && !AttributeParser::$lastEmpty) {
            return $this->emit([self::ATTR, $attrs, $i, $end]);
        }
        if ($i + 1 >= $this->n) {
            return -1;
        }
        $m = $t[$i + 1];
        if ($m === '%') {
            $close = $this->braceCommentEnd($i);
            if ($close > 0) {
                $node = $this->node('comment', $i, $close);
                $node->f['form'] = 'braced';
                $node->f['value'] = self::trimOne(substr($t, $i + 2, $close - $i - 4));
                $this->commentStart[$close] = $i;

                return $this->emit([self::NODE, $node, $i, $close, false, null]);
            }

            return -1;
        }
        if ($m === '#') {
            // C2-I-063: the first `#}` decides.
            $p = Chars::isGlue(Chars::at($t, $i + 2)) ? -1 : $this->nextPos('#}', $i + 2);
            if ($p > $i + 2) {
                $node = $this->node('critic_comment', $i, $p + 2);
                $node->f['value'] = substr($t, $i + 2, $p - $i - 2);

                return $this->emit([self::NODE, $node, $i, $p + 2, false, null]);
            }

            return -1;
        }
        if (isset(Tables::BRACED_MARKS[$m]) && !Chars::isGlue(Chars::at($t, $i + 2))) {
            return $this->emit([self::DELIM, self::OPENER, $i, $i + 2, $m, false, false, false, 0]);
        }

        return -1;
    }

    /**
     * One space less at each end of a comment value (G-inline-1).
     *
     * @param string $v
     *
     * @return string
     */
    private static function trimOne(string $v): string
    {
        if (str_starts_with($v, ' ')) {
            $v = substr($v, 1);
        }

        return str_ends_with($v, ' ') ? substr($v, 0, -1) : $v;
    }

    /**
     * C2-I-057: a mark run before `}`; only the last mark and the brace are a closer.
     *
     * @param int $i
     *
     * @return int
     */
    private function closingBrace(int $i): int
    {
        $t = $this->t;
        if ($i === 0 || $i - 1 < $this->textStart) {
            return -1;
        }
        $m = $t[$i - 1];
        if (!isset(Tables::BRACED_MARKS[$m]) || isset(Tables::BARE_DELIMITERS[$m])) {
            return -1;
        }

        // C2-I-057: the marks before the last of a run stay text.
        return $this->emit([self::DELIM, self::CLOSER, $i - 1, $i + 1, $m, false, false, false, 0]);
    }

    /**
     * C2-I-046 to C2-I-050 for `*` and `/`, and their braced closers (C2-I-057).
     *
     * @param int $i
     *
     * @return int
     */
    private function bare(int $i): int
    {
        $t = $this->t;
        $m = $t[$i];
        $len = strspn($t, $m, $i);
        $after = $i + $len;
        if ($len >= 2) {
            if ($after < $this->n && $t[$after] === '}') {
                return $this->emit([self::DELIM, self::CLOSER, $after - 1, $after + 1, $m, false, false, false, 0]);
            }

            return $after;
        }
        if ($after < $this->n && $t[$after] === '}') {
            [$open, $close] = $this->flanks($i, $i + 1);

            return $this->emit([self::DELIM, self::CLOSER, $i, $i + 2, $m, $open, $close, false, 1]);
        }
        $next = $this->nextCp($i + 1);
        if (Chars::isGlue($next)) {
            return $i + 1;
        }
        [$open, $close, $poison] = $this->flanks($i, $i + 1);

        return $this->emit([self::DELIM, self::BARE, $i, $i + 1, $m, $open, $close, $poison, 0]);
    }

    /**
     * C2-I-047, C2-I-048, C2-I-053: can open, can close, stands between two word characters.
     *
     * @param int $ts
     * @param int $te
     *
     * @return array{0: bool, 1: bool, 2: bool}
     */
    private function flanks(int $ts, int $te): array
    {
        $prev = $this->prevCp($ts);
        $next = $this->nextCp($te);
        $pw = Chars::isWord($prev);
        $nw = Chars::isWord($next);

        return [!$pw && !Chars::isSpace($next), !Chars::isSpace($prev) && !$nw, $pw && $nw];
    }

    /**
     * C2-I-023, C2-I-024.
     *
     * @param int $i
     *
     * @return int
     */
    private function lineComment(int $i): int
    {
        $t = $this->t;
        if ($this->lineBlock || $i + 1 >= $this->n || $t[$i + 1] !== '%') {
            return -1;
        }
        $prev = Chars::before($t, $i);
        if ($prev !== -1 && $prev !== 0x0A && !Chars::isSpace($prev)) {
            return -1;
        }
        $start = $i;
        while ($start > $this->textStart && ($t[$start - 1] === ' ' || $t[$start - 1] === "\t")) {
            $start--;
        }
        $end = $this->lineEnd($i);
        $value = substr($t, $i + 2, $end - $i - 2);
        if ($value !== '' && $value[0] === ' ') {
            $value = substr($value, 1);
        }
        // G-inline-1: the value ends at the last character that is no space, an escaped space included.
        $value = rtrim($value, " \t");
        $node = $this->node('comment', $start, $end);
        $node->f['form'] = 'line';
        $node->f['value'] = $value;

        return $this->emit([self::NODE, $node, $start, $end, false, null]);
    }

    /**
     * C2-I-090: a sigil starts a form at the content start or after a character that is no letter or number.
     *
     * @param int $i
     *
     * @return bool
     */
    private function boundary(int $i): bool
    {
        return !Chars::isWord($this->prevCp($i));
    }

    /**
     * C2-I-093, C2-I-094: a symbol or the opener of an inline extension.
     *
     * @param int $i
     *
     * @return int
     */
    private function colon(int $i): int
    {
        $t = $this->t;
        if ($i + 1 >= $this->n || !$this->boundary($i)) {
            return -1;
        }
        $c = $t[$i + 1];
        if (strspn($c, Tables::SIGIL_SYMBOL_FIRST) === 1) {
            $k = 1 + strspn($t, Tables::SIGIL_SYMBOL_REST, $i + 2);
            $close = $i + 1 + $k;
            if ($close < $this->n && $t[$close] === ':' && $t[$close - 1] !== '-') {
                $node = $this->node('symbol', $i, $close + 1);
                $node->f['name'] = substr($t, $i + 1, $k);

                return $this->emit([self::NODE, $node, $i, $close + 1, true, null]);
            }
        }
        if (strspn($c, Tables::SIGIL_INLINE_EXTENSION_FIRST) === 1) {
            $k = 1 + strspn($t, Tables::SIGIL_INLINE_EXTENSION_REST, $i + 2);
            $b = $i + 1 + $k;
            if ($b < $this->n && $t[$b] === '[') {
                return $this->pushFrame(self::F_EXTENSION, $i, $b + 1, substr($t, $i + 1, $k));
            }
        }

        return -1;
    }

    /**
     * C2-I-092: a mention or a tag.
     *
     * @param int $i
     *
     * @return int
     */
    private function sigil(int $i): int
    {
        $t = $this->t;
        $chars = Tables::SIGIL_MENTION_CHARS;
        if (!$this->boundary($i)) {
            // C2-B-207: the caption mark has no boundary on its left.
            return $this->caption && $t[$i] === '#' && ($i + 1 >= $this->n || strspn($t[$i + 1], $chars) === 0)
                ? $this->emit([self::NODE, Node::at('caption_number', -1, -1), $i, $i + 1, false, null]) : -1;
        }
        $j = $i + 1;
        while (true) {
            $k = strspn($t, $chars, $j);
            if ($k === 0) {
                break;
            }
            $j += $k;
            if ($j + 1 < $this->n && $t[$j] === '.' && strspn($t[$j + 1], $chars) === 1) {
                $j++;

                continue;
            }

            break;
        }
        while ($j > $i + 1 && ($t[$j - 1] === '-' || $t[$j - 1] === '.')) {
            $j--;
        }
        $name = substr($t, $i + 1, $j - $i - 1);
        if ($this->caption && $t[$i] === '#' && ($i + 1 >= $this->n || strspn($t[$i + 1], $chars) === 0)) {
            // C2-B-150, C2-B-207: the finisher keeps the first mark of the top-level list.
            return $this->emit([self::NODE, Node::at('caption_number', -1, -1), $i, $i + 1, false, null]);
        }
        if ($name === '' || strpbrk($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') === false) {
            return -1;
        }
        if ($t[$i] === '@') {
            $node = $this->node('mention', $i, $j);
            $node->f['target'] = $name;
            $node->f['kind'] = 'user';
        } else {
            $node = $this->node('tag', $i, $j);
            $node->f['name'] = $name;
        }

        return $this->emit([self::NODE, $node, $i, $j, false, null]);
    }

    /**
     * @param int $kind
     * @param int $ts
     * @param int $cs
     * @param string $name
     *
     * @return int
     */
    private function pushFrame(int $kind, int $ts, int $cs, string $name): int
    {
        // C2-I-118: an opener at the cap is text.
        if ($this->depth + count($this->frames) >= Tables::DEPTH_CAP) {
            return -1;
        }
        $this->flush($ts);
        $this->frames[] = [$kind, count($this->tok), $ts, $cs, $name];
        $this->tok[] = [self::TEXT, substr($this->t, $ts, $cs - $ts), $ts, $cs, false];
        $this->textStart = $cs;

        return $cs;
    }

    /**
     * C2-I-087: `[^label]` is a footnote reference; with no label the bracket is text.
     *
     * @param int $i
     *
     * @return int
     */
    private function openBracket(int $i): int
    {
        if ($i + 1 < $this->n && $this->t[$i + 1] === '^') {
            $close = $this->label($i + 2);
            if ($close < 0) {
                return -1;
            }
            $key = Chars::fold(substr($this->t, $i + 2, $close - $i - 2));
            if ($key === '') {
                return -1;
            }
            $node = $this->node('footnote_ref', $i, $close + 1);
            $node->f['key'] = $key;
            $node->f['namespace'] = 'footnote';

            return $this->emit([self::NODE, $node, $i, $close + 1, true, null]);
        }

        return $this->pushFrame(self::F_BRACKET, $i, $i + 1, '');
    }

    /**
     * C2-I-076, C2-I-077: the label runs to the next `]` that no backslash escapes; a line comment in it
     * leaves it unclosed. Returns the offset of that `]` or -1.
     *
     * @param int $q
     *
     * @return int
     */
    private function label(int $q): int
    {
        [$from, $stop, $result] = $this->labelCache;
        if ($q > $from && $q <= $stop && $from >= 0) {
            return $result;
        }
        $t = $this->t;
        $n = $this->n;
        $p = $q;
        $result = -1;
        while ($p < $n) {
            $p += strcspn($t, ']\\%', $p);
            if ($p >= $n) {
                break;
            }
            $c = $t[$p];
            if ($c === ']') {
                $result = $p;

                break;
            }
            if ($c === '\\') {
                $p += ($p + 1 < $n && ctype_punct($t[$p + 1])) ? 2 : 1;

                continue;
            }
            if ($p + 1 < $n && $t[$p + 1] === '%' && !$this->lineBlock && Chars::isSpace(Chars::before($t, $p))) {
                break;
            }
            $p++;
        }
        $this->labelCache = [$q, min($p, $n), $result];

        return $result;
    }

    /**
     * C2-I-006: a closing bracket pairs with the nearest open one.
     *
     * @param int $i
     *
     * @return int
     */
    private function closeBracket(int $i): int
    {
        if ($this->frames === []) {
            return -1;
        }
        $frame = array_pop($this->frames);
        [$kind] = $frame;
        if ($kind === self::F_FOOTNOTE || $kind === self::F_EXTENSION) {
            $type = $kind === self::F_FOOTNOTE ? 'inline_footnote' : 'inline_extension';
            $node = $this->node($type, $frame[2], $i + 1);
            if ($kind === self::F_EXTENSION) {
                $node->f['name'] = $frame[4];
            }

            return $this->form($frame, $node, $i, $i + 1);
        }

        return $this->pair($frame, $i);
    }

    /**
     * Forms a bracket construct: the tokens of its content move into it.
     *
     * @param array{0: int, 1: int, 2: int, 3: int, 4: string} $frame
     * @param \MarkupCarve\Carve2\Node $node
     * @param int $close Offset of the closing bracket.
     * @param int $end
     * @param bool $bang C2-I-074: the `!` of the opener stays as text before the node.
     *
     * @return int
     */
    private function form(array $frame, Node $node, int $close, int $end, bool $bang = false): int
    {
        $this->flush($close);
        $inner = array_slice($this->tok, $frame[1] + 1);
        for ($k = count($this->tok) - 1; $k >= $frame[1]; $k--) {
            array_pop($this->tok);
        }
        $start = $frame[2];
        if ($bang) {
            $this->tok[] = [self::TEXT, '!', $start, $start + 1, false];
            $start++;
        }
        $this->tok[] = [self::NODE, $node, $start, $end, true, new TokenList($node->type === 'image' ? [] : $inner, $frame[3], $close)];
        $this->textStart = $end;

        return $end;
    }

    /**
     * C2-I-064 to C2-I-074, C2-I-125: what is glued to the closing bracket decides the construct.
     *
     * @param array{0: int, 1: int, 2: int, 3: int, 4: string} $frame
     * @param int $i
     *
     * @return int
     */
    private function pair(array $frame, int $i): int
    {
        $t = $this->t;
        $image = $frame[0] === self::F_IMAGE;
        $text = substr($t, $frame[3], $i - $frame[3]);
        $next = $t[$i + 1] ?? '';
        if ($next === '(') {
            $this->tails ??= new LinkTail($t, $this->n);
            $tail = $this->tails->read($i + 1);
            if ($tail === null) {
                // C2-I-069: the pair and the parenthesis are text.
                return $i + 2;
            }
            $node = $this->node($image ? 'image' : 'link', $frame[2], $tail[2]);
            $node->f[$image ? 'src' : 'href'] = $tail[0];
            if ($tail[1] !== null) {
                $node->f['tooltip'] = $tail[1];
            }
            if ($image) {
                $node->f['alt'] = $text;
            }

            return $this->form($frame, $node, $i, $tail[2]);
        }
        if ($next === '[') {
            $r = $this->reference($frame, $i, $text, $image);
            if ($r !== -2) {
                return $r;
            }
        }
        if ($next === '{') {
            $attrs = new Attrs();
            $end = AttributeParser::readRun($t, $i + 1, $this->lineEnd($i + 1), $attrs, true);
            if ($end > 0) {
                return $this->span($frame, $i, $end, $attrs);
            }
        }

        return $this->shortcut($frame, $i, $text, $image);
    }

    /**
     * C2-I-040, C2-I-064: a span; before it a `!` is text (C2-I-074).
     *
     * @param array{0: int, 1: int, 2: int, 3: int, 4: string} $frame
     * @param int $i
     * @param int $end
     * @param \MarkupCarve\Carve2\Attrs $attrs
     *
     * @return int
     */
    private function span(array $frame, int $i, int $end, Attrs $attrs): int
    {
        $bang = $frame[0] === self::F_IMAGE;
        $node = $this->node('span', $frame[2] + ($bang ? 1 : 0), $end);
        $attrs->applyTo($node);

        return $this->form($frame, $node, $i, $end, $bang);
    }

    /**
     * C2-I-072, C2-I-078: a full or collapsed reference; -2 when the glued bracket is no label.
     *
     * @param array{0: int, 1: int, 2: int, 3: int, 4: string} $frame
     * @param int $i
     * @param string $text
     * @param bool $image
     *
     * @return int
     */
    private function reference(array $frame, int $i, string $text, bool $image): int
    {
        $t = $this->t;
        if ($i + 2 < $this->n && $t[$i + 2] === ']') {
            $key = Chars::fold($text);
            if ($key === '') {
                return $i + 1;
            }

            return $this->refNode($frame, $i, $i + 3, $key, 'collapsed', $text, $image);
        }
        $first = $t[$i + 2] ?? '';
        if ($first === '^' || $first === '@') {
            // C2-I-078: the pair stays text, the bracket behind it is read on its own.
            return $i + 1;
        }
        $close = $this->label($i + 2);
        if ($close < 0) {
            return -2;
        }
        $key = Chars::fold(substr($t, $i + 2, $close - $i - 2));
        if ($key === '') {
            return -2;
        }

        return $this->refNode($frame, $i, $close + 1, $key, 'full', $text, $image);
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int, 4: string} $frame
     * @param int $i
     * @param int $end
     * @param string $key
     * @param string $form
     * @param string $text
     * @param bool $image
     *
     * @return int
     */
    private function refNode(array $frame, int $i, int $end, string $key, string $form, string $text, bool $image): int
    {
        $node = $this->node($image ? 'image' : 'link', $frame[2], $end);
        $node->f['key'] = $key;
        $node->f['namespace'] = 'link';
        $node->f['refForm'] = $form;
        if ($image) {
            $node->f['alt'] = $text;
        }

        return $this->form($frame, $node, $i, $end);
    }

    /**
     * C2-I-072, C2-I-101, C2-I-125: a shortcut forms only for a defined key; otherwise the pair is text.
     *
     * @param array{0: int, 1: int, 2: int, 3: int, 4: string} $frame
     * @param int $i
     * @param string $text
     * @param bool $image
     *
     * @return int
     */
    private function shortcut(array $frame, int $i, string $text, bool $image): int
    {
        $key = Chars::fold($text);
        if ($key === '' || $key[0] === '@' || !isset($this->ctx->linkKeys[$key])) {
            return -1;
        }

        return $this->refNode($frame, $i, $i + 1, $key, 'shortcut', $text, $image);
    }
}
