<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Block\Markers;
use MarkupCarve\Carve\Block\TableBuilder;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;

/**
 * The escapes of a text node (C2-W-088 to C2-W-099), decided from the contexts of `data/escape-contexts.json` before the
 * text is written: each character that a context can make an opener at its position takes a backslash, from the
 * characters around it in the written output, and from nothing that a later parse finds.
 */
final class Text
{
    /**
     * @var string
     */
    private const PUNCT = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /**
     * The marks of braced openers and closers (C2-I-055).
     *
     * @var string
     */
    private const MARKS = '/*_~=+-^,#%';

    /**
     * @var array<string, true>
     */
    private const DEFINITIONS = ['link_reference_definition' => true, 'footnote' => true, 'abbreviation_def' => true];

    /**
     * C2-I-036: the inline nodes that take no attribute block glued behind them.
     *
     * @var array<string, true>
     */
    private const NO_HOSTS = [
        'mention' => true,
        'tag' => true,
        'smart_punctuation' => true,
        'abbreviation' => true,
        'comment' => true,
        'critic_comment' => true,
    ];

    /**
     * The inline nodes whose spelling holds no delimiter that pairs with text around them.
     *
     * @var array<string, true>
     */
    private const WHOLE = [
        'code' => true,
        'raw_inline' => true,
        'math' => true,
        'literal_inline' => true,
        'comment' => true,
        'symbol' => true,
        'tag' => true,
        'mention' => true,
        'non_breaking_space' => true,
        'smart_punctuation' => true,
        'footnote_ref' => true,
        'autolink' => true,
    ];

    /**
     * C2-W-092: the punctuation a block opener of a first paragraph line starts with.
     *
     * @var string
     */
    private const LINE_OPENERS = '#=>^|+:%-*.[{`~$!<';

    /**
     * C2-I-092: the characters of a name and the dots that join its runs.
     *
     * @var string
     */
    private const NAME = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.';

    /**
     * Characters that take a backslash wherever they stand in text: a backtick run and the two quotes smart typography
     * replaces (C2-W-095).
     *
     * @var string
     */
    private const ALWAYS = '`"\'';

    /**
     * Every first character of an escape trigger this class decides; WriterTablesTest holds it to the data file.
     *
     * @var string
     */
    public const DECIDED = '!"#$%\'(*+-./:<=>@[\\]^`{|}';

    public static function isPunct(string $c): bool
    {
        return $c !== '' && strlen($c) === 1 && str_contains(self::PUNCT, $c);
    }

    /**
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c Context at the start of the text; the text is followed by `$next`.
     * @param int $next First character written after the text, 10 at a line end, -1 at the end of the content.
     *
     * @return string
     */
    public static function escape(string $value, Context $c, int $next): string
    {
        $n = strlen($value);
        if ($n === 0) {
            return '';
        }
        if (!$c->lineStart && strpbrk($value, self::PUNCT) === false) {
            return $value;
        }
        $marks = self::lineStartMark($value, $c, $next);
        $c->escapedAt = [];
        $out = '';
        $prev = $c->last;
        for ($i = 0; $i < $n; $i++) {
            $ch = $value[$i];
            $byte = ord($ch);
            if ($byte >= 0x80) {
                $len = Chars::len($value, $i);
                $out .= substr($value, $i, $len);
                $prev = Chars::at($value, $i);
                $i += $len - 1;

                continue;
            }
            $after = $i + 1 < $n ? Chars::at($value, $i + 1) : $next;
            $escape = $i === $marks || (str_contains(self::PUNCT, $ch) && self::needs($value, $i, $prev, $after, $c, $next));
            $c->escapedAt[$i] = $escape;
            $out .= $escape ? '\\' . $ch : $ch;
            $prev = $byte;
        }

        return $out;
    }

    /**
     * @param string $value
     * @param int $i
     * @param int $prev
     * @param int $after
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return bool
     */
    private static function needs(string $value, int $i, int $prev, int $after, Context $c, int $next): bool
    {
        $ch = $value[$i];
        if (str_contains(self::ALWAYS, $ch)) {
            return true;
        }
        // C2-I-055, C2-I-060: a mark before `}` closes the node of that mark open around the text, so the mark takes the
        // backslash; with none open the closer is text.
        if ($after === 0x7D && $c->marks !== '' && str_contains($c->marks, $ch)) {
            return true;
        }

        // C2-W-090: a sigil glued to the bracket or the backtick of a construct of the tree takes the backslash.
        if ($i === strlen($value) - 1 && self::gluedSigil($ch, $next)) {
            return true;
        }

        return match ($ch) {
            '[' => self::bracket($i, $c),
            '{' => self::brace($i, $c, $after, self::markEscaped($value, $i, $c, $next)),
            // C2-W-097: a backslash before ASCII punctuation, a space or a line end.
            '\\' => $after === -1 || $after === 10 || $after === 32 || ($after >= 0 && $after < 0x80 && self::isPunct(chr($after))),
            ']' => $c->inBracket,
            // C2-W-098
            '|' => $c->inCell,
            '}' => false,
            '/', '*' => self::pairs($value, $i, $prev, $after, $c),
            '-' => self::hyphen($value, $i, $c->last, $next),
            '.' => self::dot($value, $i),
            default => self::sigil($value, $i, [$prev, $after, $next], $c),
        };
    }

    /**
     * C2-W-094: an opening bracket of the leaf's own text forms a construct when its pair is followed by a link tail, a
     * label, `[]` or a brace block, when it follows `^` or starts `[^`, or when its label resolves as a reference. A
     * bracket inside another node takes the backslash.
     *
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function bracket(int $i, Context $c): bool
    {
        if ($c->here < 0 || $c->inBracket) {
            return true;
        }
        $c->brackets ??= self::brackets((string)$c->flat, $c->labels);

        return $c->brackets[$c->here + $i] ?? true;
    }

    /**
     * The decision for every opening bracket of the leaf, from right to left: a bracket that takes a backslash pairs
     * with nothing, so its closing bracket stays open for the brackets before it. A pair across another node is not
     * known and takes the backslash.
     *
     * @param string $flat
     * @param array<string, true> $labels
     *
     * @return array<int, bool>
     */
    private static function brackets(string $flat, array $labels): array
    {
        $out = [];
        $open = [];
        $node = PHP_INT_MAX;
        $first = PHP_INT_MAX;
        for ($k = strlen($flat) - 1; $k >= 0; $k--) {
            $ch = $flat[$k];
            if ($ch === "\0") {
                $node = $k;
            } elseif ($ch === ']') {
                $open[] = $k;
                $first = $k;
            } elseif ($ch === '[') {
                // C2-I-076: a footnote label ends at the first closing bracket.
                $pair = $open === [] ? PHP_INT_MAX : $open[count($open) - 1];
                $close = ($flat[$k + 1] ?? '') === '^' ? $first : $pair;
                $out[$k] = self::forms($flat, $k, $close, $node, $labels);
                if (!$out[$k] && $open !== []) {
                    array_pop($open);
                }
            }
        }

        return $out;
    }

    /**
     * @param string $flat
     * @param int $at
     * @param int $close
     * @param int $node
     * @param array<string, true> $labels
     *
     * @return bool
     */
    private static function forms(string $flat, int $at, int $close, int $node, array $labels): bool
    {
        if ($close === PHP_INT_MAX) {
            // The written form of a node behind the bracket may hold its pair.
            return $node !== PHP_INT_MAX;
        }
        $tail = $flat[$close + 1] ?? '';
        if ($node < $close || ($tail !== '' && str_contains("([{\0", $tail))) {
            return true;
        }
        $label = substr($flat, $at + 1, $close - $at - 1);
        if (str_starts_with($label, '^') || ($at > 0 && $flat[$at - 1] === '^')) {
            return true;
        }

        return isset($labels[Chars::fold($label)]);
    }

    /**
     * C2-W-096, C2-I-043: an opening brace forms an attribute block glued to the node before it, a braced comment or an
     * editorial comment whose closer follows, or a braced opener whose closer follows (C2-I-055). Inside another node
     * every brace takes the backslash.
     *
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $after The first character written behind the brace.
     * @param bool $markEscaped Whether the mark behind the brace takes a backslash of its own.
     *
     * @return bool
     */
    private static function brace(int $i, Context $c, int $after, bool $markEscaped): bool
    {
        $flat = (string)$c->flat;
        $at = $c->here + $i;
        if ($c->here < 0 || ($flat[$at] ?? '') !== '{') {
            return true;
        }
        // C2-I-036: a valid attribute block glued to the end of a host attaches to it.
        if ($at > 0 && $flat[$at - 1] === "\0" && !isset(self::NO_HOSTS[$c->flatTypes[$at - 1] ?? ''])) {
            if (AttributeParser::readRun($flat, $at, strlen($flat), new Attrs()) >= 0) {
                return true;
            }
        }
        if (self::braceTakesNode($flat, $at, $c)) {
            return true;
        }
        $mark = $flat[$at + 1] ?? '';
        // C2-W-125 case one: a brace glued before a node reads on into the first character of the node.
        if ($mark === "\0") {
            $mark = $after >= 0 && $after < 0x80 ? chr($after) : '';
            // C2-I-060: a braced opener with no closer still takes the mark of the node, which then cannot open.
            if (!isset($c->flatAtoms[$at + 1]) && $mark !== '' && str_contains(self::MARKS . '%', $mark)) {
                return true;
            }
        } elseif ($markEscaped) {
            return false;
        }
        if ($mark === '' || !str_contains(self::MARKS . '%', $mark)) {
            return false;
        }

        return self::closerFollows($flat, $at + 1, $mark, $c);
    }

    /**
     * C2-I-043, C2-I-060: whether the closer of the mark at `$from` can follow it in the written leaf.
     *
     * @param string $flat
     * @param int $from
     * @param string $mark
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function closerFollows(string $flat, int $from, string $mark, Context $c): bool
    {
        $c->closers ??= self::closers($flat, $c);
        [$offsets, $shifts, $closes, $forced] = $c->closers;
        if (($forced[$mark] ?? -1) > $from) {
            return true;
        }
        // The run starts behind the mark, which is the first character of an atom at `$from`.
        $start = self::writtenAt($from, $offsets, $shifts) + 1;
        // C2-I-043: an editorial comment holds at least one character.
        $last = $closes[$mark] ?? -1;

        return $last >= $start + ($mark === '#' ? 1 : 0);
    }

    /**
     * The closer facts of a leaf, found once: the flat offsets of its atoms and the growth of the leaf as written behind
     * each, where an atom is its spelling and every other node one `\0`; the last closer of each mark in the written
     * leaf; and the last node that may supply a closer of each mark itself. A later atom is read with its spelling.
     * Text inside another node writes its openers with a backslash and its closers bare, so another node supplies one
     * by a closer in its text outside brackets, by the bare closing mark of emphasis or strong before a `}` of text, or
     * by the raw body of an editorial comment. For `{#` and
     * `{%` the body is raw, so a comment or any node that holds the mark may supply it.
     *
     * @param string $flat
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array{0: array<int>, 1: array<int>, 2: array<string, int>, 3: array<string, int>}
     */
    private static function closers(string $flat, Context $c): array
    {
        $marks = str_split(self::MARKS . '%');
        $written = '';
        $offsets = [];
        $shifts = [];
        $shift = 0;
        $forced = [];
        $k = 0;
        $end = strlen($flat);
        while ($k < $end) {
            $node = strpos($flat, "\0", $k);
            if ($node === false) {
                $written .= substr($flat, $k);

                break;
            }
            $written .= substr($flat, $k, $node - $k);
            $k = $node + 1;
            if (isset($c->flatAtoms[$node])) {
                $written .= $c->flatAtoms[$node];
                $shift += strlen($c->flatAtoms[$node]) - 1;
                $offsets[] = $node;
                $shifts[] = $shift;

                continue;
            }
            $written .= "\0";
            $type = $c->flatTypes[$node] ?? '';
            $held = Inline::strings($c->flatNodes[$node] ?? []);
            $bare = ($flat[$k] ?? '') === '}' ? Inline::bareMark($type) : null;
            foreach ($marks as $mark) {
                $raw = $mark === '#' || $mark === '%';
                $supplies = !Inline::known($type) || $bare === $mark
                    || ($raw && ($type === ($mark === '%' ? 'comment' : 'critic_comment') || str_contains($held, $mark)))
                    || (!$raw && $type === 'critic_comment' && str_contains($held, $mark . '}'))
                    || (!$raw && str_contains(Inline::openText($c->flatNodes[$node] ?? [], $mark), $mark . '}'));
                if ($supplies) {
                    $forced[$mark] = $node;
                }
            }
        }
        $closes = [];
        foreach ($marks as $mark) {
            $at = strrpos($written, $mark . '}');
            $closes[$mark] = $at === false ? -1 : $at;
        }

        return [$offsets, $shifts, $closes, $forced];
    }

    /**
     * The offset in the written leaf of the flat offset `$at`.
     *
     * @param int $at
     * @param array<int> $offsets The flat offsets of the atoms, ascending.
     * @param array<int> $shifts The growth of the written leaf behind each of them.
     *
     * @return int
     */
    private static function writtenAt(int $at, array $offsets, array $shifts): int
    {
        $low = 0;
        $high = count($offsets);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($offsets[$mid] < $at) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $at + ($low > 0 ? $shifts[$low - 1] : 0);
    }

    /**
     * Whether the mark behind the brace at `$i` takes a backslash of its own, so that the brace opens nothing.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return bool
     */
    private static function markEscaped(string $value, int $i, Context $c, int $next): bool
    {
        $j = $i + 1;
        // A mark at the end of the text is escaped for what follows it, which a later parse may read differently.
        if ($j >= strlen($value) - 1 || !str_contains(self::MARKS, $value[$j])) {
            return false;
        }
        $after = $j + 1 < strlen($value) ? Chars::at($value, $j + 1) : $next;
        // The mark is tested as it stands behind a bare brace.
        $c->escapedAt[$i] = false;
        $escaped = self::needs($value, $j, 0x7B, $after, $c, $next);
        unset($c->escapedAt[$i]);

        return $escaped;
    }

    /**
     * C2-I-035: a valid attribute block is one atom, so one that reads on into the spelling of a later node takes that
     * node in as text. Atoms are read with their spelling; at any other node the first character of its spelling is
     * probed, where a closing brace can still follow: `{key=` before a span takes it, `{k` before a link cannot.
     *
     * @param string $flat
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function braceTakesNode(string $flat, int $at, Context $c): bool
    {
        $node = strpos($flat, "\0", $at);
        if ($node === false) {
            return false;
        }
        $line = strpos($flat, "\n", $at);
        if ($line !== false && $line < $node) {
            return false;
        }
        $end = $line === false ? strlen($flat) : $line;
        $run = self::asWritten(substr($flat, $at, $node - $at));
        if (AttributeParser::readRun($run, 0, strlen($run), new Attrs()) >= 0) {
            return false;
        }
        while ($node !== false && $node < $end && isset($c->flatAtoms[$node])) {
            $next = strpos($flat, "\0", $node + 1);
            $stop = $next === false || $next > $end ? $end : $next;
            $run .= $c->flatAtoms[$node] . self::asWritten(substr($flat, $node + 1, $stop - $node - 1));
            $node = $stop < $end ? $stop : false;
        }
        $valid = AttributeParser::readRun($run, 0, strlen($run), new Attrs()) >= 0;
        if ($node === false || $valid) {
            return $valid;
        }
        if (!($c->flatBraces[$node] ?? true) && !str_contains(substr($flat, $node + 1, $end - $node - 1), '}')) {
            return false;
        }
        $first = $c->flatFirst[$node] ?? -1;
        $first = $first < 0 ? 'a' : (string)mb_chr($first, 'UTF-8');
        foreach (['}', '"}', "'}"] as $close) {
            $probe = $run . $first . $close;
            if (AttributeParser::readRun($probe, 0, strlen($probe), new Attrs()) === strlen($probe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text as the attribute test sees it once written: a backslash and the characters of `ALWAYS` take a backslash.
     *
     * @param string $text
     *
     * @return string
     */
    private static function asWritten(string $text): string
    {
        return addcslashes(str_replace('\\', '\\\\', $text), self::ALWAYS);
    }

    private static function gluedSigil(string $ch, int $next): bool
    {
        return ($next === 0x60 && ($ch === '!' || $ch === '$')) || ($next === 0x5B && ($ch === '!' || $ch === '^'));
    }

    /**
     * C2-W-095: a sigil before a name, an angle bracket before a target, and the runs smart typography replaces.
     *
     * @param string $value
     * @param int $i
     * @param array{0: int, 1: int, 2: int} $around The characters before and after this one, and after the text.
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function sigil(string $value, int $i, array $around, Context $c): bool
    {
        [$prev, $after, $next] = $around;

        return match ($value[$i]) {
            '@', '#' => !self::isLetterOrNumber($prev) && self::sigilName($value, $i, $c, $next),
            ':' => !self::isLetterOrNumber($prev) && (self::symbolFollows($value, $i) || self::extensionFollows($value, $i, $next)),
            '<' => $after === 0x2F || $after === 0x2D || $after === 0x3D || ($after >= 0 && $after < 0x80 && ctype_alnum(chr($after))),
            '>', '!' => $after === 0x3D,
            '=' => substr($value, $i + 1, 2) === '=>',
            '+' => $after === 0x2D,
            '(' => strtolower(substr($value, $i, 4)) === '(tm)',
            // C2-I-023
            '%' => $after === 0x25 && ($prev === -1 || $prev === 10 || Chars::isSpace($prev) || ($i === 0 && $c->lineStart)),
            default => false,
        };
    }

    /**
     * C2-W-093, C2-I-047, C2-I-048: a lone `/` or `*` that can open or close, where the leaf holds another one.
     *
     * @param string $value
     * @param int $i
     * @param int $prev
     * @param int $after
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return bool
     */
    private static function pairs(string $value, int $i, int $prev, int $after, Context $c): bool
    {
        $ch = $value[$i];
        if (($c->counts[$ch] ?? 0) < 2) {
            return false;
        }
        // C2-I-049: a run of two or more is text, counted without the mark a bare brace takes (C2-I-055).
        $run = self::run($value, $i, $c);
        if ($run === null || $run[1] > $run[0]) {
            return false;
        }
        if ($after >= 0 && Chars::isGlue($after)) {
            return false;
        }
        $open = !Chars::isWord($prev) && !Chars::isSpace($after);
        $close = !Chars::isSpace($prev) && !Chars::isWord($after);
        if ($c->here < 0) {
            return $open || $close;
        }
        $at = $c->here + $i;

        return ($open && self::partner($c, $at, $ch, 1)) || ($close && self::partner($c, $at, $ch, -1));
    }

    /**
     * C2-I-047: an opener pairs only with a mark after it that can close, and a closer only with one before it that
     * can open. A node that is not read whole may hold one. The first opener and the last closer of each mark in the
     * leaf are found once.
     *
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $at
     * @param string $ch
     * @param int $step 1 to look for a closer after `$at`, -1 for an opener before it.
     *
     * @return bool
     */
    private static function partner(Context $c, int $at, string $ch, int $step): bool
    {
        if ($c->partners === null) {
            $flat = (string)$c->flat;
            $n = strlen($flat);
            $c->partners = ['*' => [PHP_INT_MAX, -1], '/' => [PHP_INT_MAX, -1]];
            for ($k = 0; $k < $n; $k++) {
                $here = $flat[$k];
                $node = $here === "\0" && !isset(self::WHOLE[$c->flatTypes[$k] ?? '']);
                foreach (['*', '/'] as $mark) {
                    $opens = $node || ($here === $mark && !str_contains(" \t\n", $flat[$k + 1] ?? ' '));
                    $closes = $node || ($here === $mark && !str_contains(" \t\n", $k > 0 ? $flat[$k - 1] : ' '));
                    if ($opens && $c->partners[$mark][0] === PHP_INT_MAX) {
                        $c->partners[$mark][0] = $k;
                    }
                    if ($closes) {
                        $c->partners[$mark][1] = $k;
                    }
                }
            }
        }
        [$firstOpen, $lastClose] = $c->partners[$ch] ?? [PHP_INT_MAX, -1];

        return $step > 0 ? $lastClose > $at : $firstOpen < $at;
    }

    /**
     * The run of `$value[$i]` without the mark that a bare brace before it takes as a braced opener; null
     * when `$i` is that mark.
     *
     * @param string $value
     * @param int $i
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array{0: int, 1: int}|null
     */
    private static function run(string $value, int $i, Context $c): ?array
    {
        $ch = $value[$i];
        $start = $i;
        while ($start > 0 && $value[$start - 1] === $ch) {
            $start--;
        }
        $end = $i;
        $n = strlen($value);
        while ($end + 1 < $n && $value[$end + 1] === $ch) {
            $end++;
        }
        if ($start > 0 && $value[$start - 1] === '{' && ($c->escapedAt[$start - 1] ?? true) === false) {
            if ($i === $start) {
                return null;
            }
            $start++;
        }

        return [$start, $end];
    }

    /**
     * C2-W-099: in a run of two or more hyphens every second one takes a backslash, starting with the second; a run
     * glued to a dash of the tree before it counts that dash's hyphens (C2-W-125 case four), and a run glued to one
     * after it ends with an escaped hyphen.
     *
     * @param string $value
     * @param int $i
     * @param int $last
     * @param int $next
     *
     * @return bool
     */
    private static function hyphen(string $value, int $i, int $last, int $next): bool
    {
        $start = $i;
        while ($start > 0 && $value[$start - 1] === '-') {
            $start--;
        }
        $end = $i;
        $n = strlen($value);
        while ($end + 1 < $n && $value[$end + 1] === '-') {
            $end++;
        }
        $offset = $i - $start + ($start === 0 && $last === 0x2D ? 1 : 0);
        $glued = $end === $n - 1 && $next === 0x2D;
        if ($end === $start && $offset === 0 && !$glued) {
            return false;
        }

        return $offset % 2 === 1 || ($glued && $i === $end && ($end - $start) % 2 === 0);
    }

    /**
     * C2-I-114: three dots read as an ellipsis, so every third dot of a run takes a backslash, starting with the first.
     *
     * @param string $value
     * @param int $i
     *
     * @return bool
     */
    private static function dot(string $value, int $i): bool
    {
        $start = $i;
        while ($start > 0 && $value[$start - 1] === '.') {
            $start--;
        }
        $end = $i;
        $n = strlen($value);
        while ($end + 1 < $n && $value[$end + 1] === '.') {
            $end++;
        }

        return $end - $start >= 2 && ($i - $start) % 3 === 0 && $end - $i >= 2;
    }

    /**
     * C2-I-093: `:`, a symbol name and `:`.
     *
     * @param string $value
     * @param int $i
     *
     * @return bool
     */
    private static function symbolFollows(string $value, int $i): bool
    {
        $len = strspn($value, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_+-', $i + 1);

        return $len > 0 && ($value[$i + 1 + $len] ?? '') === ':';
    }

    /**
     * C2-I-094, C2-W-095: `:`, an inline extension name and `[`, which may be the first character of the next node.
     *
     * @param string $value
     * @param int $i
     * @param int $next
     *
     * @return bool
     */
    private static function extensionFollows(string $value, int $i, int $next): bool
    {
        if (strspn($value, Tables::SIGIL_INLINE_EXTENSION_FIRST, $i + 1, 1) !== 1) {
            return false;
        }
        $len = 1 + strspn($value, Tables::SIGIL_INLINE_EXTENSION_REST, $i + 2);

        $end = $i + 1 + $len;

        return $end < strlen($value) ? $value[$end] === '[' : $next === 0x5B;
    }

    private static function sigilName(string $value, int $i, Context $c, int $next): bool
    {
        if (strspn($value, self::NAME, $i + 1) < strlen($value) - $i - 1) {
            return self::nameFollows($value, $i, $next);
        }
        [$tail, $after] = self::runOn($value, $c, $next);

        return self::nameFollows($value . $tail, $i, $after);
    }

    private static function isLetterOrNumber(int $cp): bool
    {
        return $cp >= 0 && Chars::isWord($cp);
    }

    /**
     * C2-I-092: what a name at the end of the text reads on into, where the leaf is known: the spelling of each atom
     * behind the text and the text behind that atom, up to the first character that ends the name; and the first
     * character after that, the first character of a node that is no atom, or -1 at the end of the leaf.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next The first character after the text.
     *
     * @return array{0: string, 1: int}
     */
    private static function runOn(string $value, Context $c, int $next): array
    {
        $flat = (string)$c->flat;
        $at = $c->here + strlen($value);
        if ($c->here < 0 || !isset($c->flatAtoms[$at])) {
            return ['', $next];
        }
        $out = '';
        while (isset($c->flatAtoms[$at])) {
            $node = strpos($flat, "\0", $at + 1);
            $stop = $node === false ? strlen($flat) : $node;
            $text = substr($flat, $at + 1, $stop - $at - 1);
            $out .= $c->flatAtoms[$at] . $text;
            if ($node === false) {
                return [$out, -1];
            }
            if (strspn($text, self::NAME) < strlen($text) || strspn($c->flatAtoms[$at], self::NAME) < strlen($c->flatAtoms[$at])) {
                return [$out, -1];
            }
            $at = $node;
        }

        return [$out, $c->flatFirst[$at] ?? -1];
    }

    /**
     * C2-I-092: runs of letters, digits, `_` and `-` joined by single dots, holding a letter. A name that runs on into
     * a node that is no atom counts as one, since that node may continue it.
     *
     * @param string $value The text, followed by the spelling it reads on into.
     * @param int $i
     * @param int $next The first character after `$value`.
     *
     * @return bool
     */
    private static function nameFollows(string $value, int $i, int $next): bool
    {
        // A dot only joins two runs, so no name starts with one.
        if (($value[$i + 1] ?? '') === '.' || ($i + 1 === strlen($value) && $next === 0x2E)) {
            return false;
        }
        $len = strspn($value, self::NAME, $i + 1);
        $runsOn = $i + 1 + $len === strlen($value) && $next >= 0 && $next < 0x80 && str_contains(self::NAME, chr($next));
        if ($len === 0) {
            return $runsOn;
        }
        $run = substr($value, $i + 1, $len);
        $double = strpos($run, '..');
        $name = $double === false ? $run : substr($run, 0, $double);

        return preg_match('/[a-zA-Z]/', $name) === 1 || ($runsOn && $double === false);
    }

    /**
     * C2-W-016: whether a line after the first of a verbatim leaf could end its paragraph or open a block there
     * (C2-B-014 to C2-B-019). The test is the later-line test of C2-W-092 inside an item, the widest owner, since the
     * leaf does not know its own.
     *
     * @param string $line
     *
     * @return bool
     */
    public static function opensBlock(string $line): bool
    {
        $line = ltrim($line, " \t");
        if ($line === '' || strspn($line, '`') >= 3) {
            return true;
        }
        $c = new Context();
        $c->lineStart = true;
        $c->last = 10;
        $c->owner = 'body';

        // The closer of the leaf may follow on the line, after a space for a comment, so the line is tested with content.
        return self::lineStartMark($line . ' ', $c, 0) >= 0;
    }

    /**
     * C2-W-092: the offset of the character that keeps a line of text from opening a block, or -1. The first line of a
     * paragraph is tested for every block opener, by the parse of that line; a later line only for the interrupters
     * its owner admits.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return int
     */
    private static function lineStartMark(string $value, Context $c, int $next): int
    {
        if (!$c->lineStart) {
            return -1;
        }
        $eol = in_array($next, [-1, 10], true);
        $line = $eol ? $value : $value . 'x';
        if (self::anyLine($value, $line, $eol, $c->last === -1 && $next === 10)) {
            return 0;
        }

        return $c->last === -1 ? self::firstLine($line, $c) : self::laterLine($line, $eol, $c);
    }

    /**
     * The openers of C2-W-092 that read on any line of a paragraph: a fence run, a star line, a `{{` line, a table row
     * and, on the first line of content that goes on, an attribute line.
     *
     * @param string $value
     * @param string $line
     * @param bool $eol
     * @param bool $attaches
     *
     * @return bool
     */
    private static function anyLine(string $value, string $line, bool $eol, bool $attaches): bool
    {
        $len = strlen($line);

        return match ($value[0]) {
            ':', '%' => LineShapes::isFenceLine($line, 0, $len),
            '*' => self::starLine($value),
            '{' => $eol && self::braceLine($value, $attaches),
            '|' => !$eol || TableBuilder::split($line, 0, $len) !== null,
            default => false,
        };
    }

    /**
     * The first line of a paragraph opens a block where its parse alone is no single paragraph. C2-W-090: the backslash
     * stands before the first punctuation character of the opener, the delimiter of an ordered marker.
     *
     * @param string $line
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return int
     */
    private static function firstLine(string $line, Context $c): int
    {
        $ch = $line[0];
        if (!str_contains(self::LINE_OPENERS, $ch) && (!ctype_alnum($ch) || Markers::quickReject($line, 0, strlen($line)))) {
            return -1;
        }
        // C2-W-121
        if ($ch === ':' && $c->afterDescriptions && in_array($line[1] ?? '', [' ', "\t", ''], true)) {
            return 0;
        }
        $children = Carve::parse($line . "\n")->children ?? [];
        if (count($children) === 1 && $children[0]->type === 'paragraph') {
            return -1;
        }
        // C2-B-081: a definition shape opens a definition only at column 0.
        if ($c->owner !== 'top' && isset(self::DEFINITIONS[$children[0]->type ?? ''])) {
            return -1;
        }

        return ctype_alnum($ch) ? strcspn($line, self::PUNCT) : 0;
    }

    /**
     * A later line interrupts its paragraph by a caption mark, and, inside an item, a description or a footnote, by a
     * list marker or a description mark (C2-B-015 to C2-B-018).
     *
     * @param string $line
     * @param bool $eol
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return int
     */
    private static function laterLine(string $line, bool $eol, Context $c): int
    {
        $ch = $line[0];
        $gap = in_array($line[1] ?? '', [' ', "\t", ''], true);
        if ($ch === '^') {
            return $gap ? 0 : -1;
        }
        if ($c->owner !== 'body') {
            return -1;
        }
        if ($ch === ':') {
            return $gap || substr($line, 1, 1) === ':' ? 0 : -1;
        }
        if (Markers::quickReject($line, 0, strlen($line)) || Markers::read($line, 0, strlen($line)) === null) {
            return -1;
        }

        return ctype_alnum($ch) ? strcspn($line, self::PUNCT) : 0;
    }

    /**
     * C2-B-156: `{{` and `}}` around a whole line. C2-B-090: an attribute line, which attaches only to a block below it,
     * so only on the first line of content that goes on.
     *
     * @param string $value
     * @param bool $attaches
     *
     * @return bool
     */
    private static function braceLine(string $value, bool $attaches): bool
    {
        if (str_starts_with($value, '{{') && str_ends_with(rtrim($value, " \t"), '}}')) {
            return true;
        }

        return $attaches && LineShapes::attributeLine($value, 0, strlen($value), new Attrs());
    }

    private static function starLine(string $value): bool
    {
        return substr_count($value, '*') >= 3 && trim($value, "* \t") === '';
    }
}
