<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Inline;

use LogicException;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;

/**
 * The delimiter stack (C2-I-008, C2-I-046 to C2-I-061) over one token list of the scanner. Bare and
 * braced entries share the stack; a pair that closes removes every entry opened after its opener.
 *
 * Item shapes: [0, value, ts, te, literal] text; [1, Node, ts, te, host, child TokenList or null] node;
 * [3, Attrs, ts, te] attribute block, attached later.
 */
final class Delimiters
{
    /**
     * @var array<int, array<mixed>>
     */
    private array $items = [];

    /**
     * Entries: [item index, kind, ts, te, poison of `*`, poison of `/`, alive].
     *
     * @var array<int, array{0: int, 1: string, 2: int, 3: int, 4: int, 5: int, 6: bool}>
     */
    private array $stack = [];

    /**
     * @var array<string, array<int>>
     */
    private array $byKind = [];

    private int $live = 0;

    /**
     * C2-I-053: count of own delimiter characters between two word characters seen so far.
     *
     * @var array<string, int>
     */
    private array $poison = ['*' => 0, '/' => 0];

    /**
     * @param \MarkupCarve\Carve\Inline\Scanner $sc
     * @param int $depth
     * @param int $frameBase
     */
    private function __construct(private Scanner $sc, private int $depth, private int $frameBase)
    {
    }

    /**
     * @param \MarkupCarve\Carve\Inline\Scanner $sc
     * @param array<int, array<mixed>> $tokens
     * @param int $depth Open nodes around this list (C2-I-118).
     * @param int $frameBase Formed bracket frames already counted in depth.
     *
     * @return array<int, array<mixed>>
     */
    public static function run(Scanner $sc, array $tokens, int $depth, int $frameBase = 0): array
    {
        if (!$sc->delims && !$sc->images) {
            // No delimiter anywhere, so no entry counts toward the cap either (C2-I-118).
            return $tokens;
        }
        $plain = true;
        foreach ($tokens as $tok) {
            if ($tok[0] === Scanner::DELIM || ($tok[5] ?? null) instanceof TokenList) {
                $plain = false;

                break;
            }
        }
        if ($plain) {
            // Without a delimiter or a construct with content the list passes through as it is.
            return $tokens;
        }
        $d = new self($sc, $depth, $frameBase);
        // Each token is released as it is read, so the tokens and the items are not both held whole.
        $tokens = array_reverse($tokens);
        while (($tok = array_pop($tokens)) !== null) {
            $d->token($tok);
        }
        foreach ($d->stack as $e) {
            if ($e[6]) {
                $d->toText($e[0]);
            }
        }

        return $d->items;
    }

    /**
     * @param array<mixed> $tok
     *
     * @return void
     */
    private function token(array $tok): void
    {
        $type = $tok[0];
        if ($type === Scanner::NODE && $tok[5] instanceof TokenList) {
            if ($tok[5]->open >= 0 && $this->openerDepth($tok) >= Tables::INLINE_DEPTH) {
                $this->pastCap($tok, $tok[5]);

                return;
            }
            if ($tok[1] instanceof Node && $tok[1]->type === 'image') {
                $tok[5] = null;
                $this->items[] = $tok;

                return;
            }
            $tok[5] = new TokenList(self::run(
                $this->sc,
                $tok[5]->list,
                $this->depth + $this->live + 1,
                $this->frameBase + ($tok[5]->open >= 0 ? 1 : 0),
            ));
            $this->items[] = $tok;

            return;
        }
        if ($type !== Scanner::DELIM) {
            $this->items[] = $tok;

            return;
        }
        assert(is_int($tok[1]) && is_string($tok[4]));
        if ($tok[1] === Scanner::BARE) {
            $this->bare($tok);
        } elseif ($tok[1] === Scanner::OPENER) {
            $this->open($tok, '{' . $tok[4]);
        } else {
            $this->closer($tok);
        }
    }

    /**
     * C2-I-118: a bracket construct past the cap is text with its brackets, its tail or label as typed;
     * its content is read in this list as the content of a plain pair.
     *
     * @param array<mixed> $tok
     * @param \MarkupCarve\Carve\Inline\TokenList $inner
     *
     * @return void
     */
    private function pastCap(array $tok, TokenList $inner): void
    {
        assert(is_int($tok[2]) && is_int($tok[3]));
        $node = $tok[1];
        if ($node instanceof Node && $node->type === 'image') {
            $this->text($tok[2], $tok[3]);

            return;
        }
        $this->text($tok[2], $inner->open, true);
        $this->frameBase++;
        foreach ($inner->list as $child) {
            $this->token($child);
        }
        $this->frameBase--;
        $this->text($inner->close, $tok[3], true);
    }

    /**
     * C2-I-051, C2-I-053.
     *
     * @param array<mixed> $tok
     *
     * @return void
     */
    private function bare(array $tok): void
    {
        $m = $tok[4];
        assert(is_string($m) && is_int($tok[2]) && is_int($tok[3]) && is_int($tok[5]));
        if (($tok[5] & Scanner::CAN_CLOSE) !== 0 && ($this->byKind[$m] ?? []) !== []) {
            $id = $this->byKind[$m][count($this->byKind[$m]) - 1];
            $e = $this->stack[$id];
            if ($this->poison[$m] > $e[$m === '*' ? 4 : 5]) {
                $this->stack[$id][6] = false;
                array_pop($this->byKind[$m]);
                $this->live--;
                $this->toText($e[0]);
                $this->text($tok[2], $tok[3]);

                return;
            }
            $this->close($id, $tok[3], Tables::BARE_DELIMITERS[$m]);

            return;
        }
        if (($tok[5] & Scanner::CAN_OPEN) !== 0) {
            $this->open($tok, $m);

            return;
        }
        if (($tok[5] & Scanner::POISON) !== 0) {
            $this->poison[$m]++;
        }
        $this->text($tok[2], $tok[3]);
    }

    /**
     * @param array<mixed> $tok
     * @param string $kind
     *
     * @return void
     */
    private function open(array $tok, string $kind): void
    {
        assert(is_int($tok[2]) && is_int($tok[3]));
        if ($this->openerDepth($tok) >= Tables::INLINE_DEPTH) {
            $this->text($tok[2], $tok[3]);

            return;
        }
        $id = count($this->stack);
        $this->stack[] = [count($this->items), $kind, $tok[2], $tok[3], $this->poison['*'], $this->poison['/'], true];
        $this->byKind[$kind][] = $id;
        $this->live++;
        $this->items[] = $tok;
    }

    /**
     * C2-I-118: scanned brackets still count until their closing bracket, even if no construct forms.
     *
     * @param array<mixed> $tok
     *
     * @throws \LogicException
     */
    private function openerDepth(array $tok): int
    {
        if (!$this->sc->capTracking) {
            return $this->depth + $this->live;
        }
        $frames = $tok[Scanner::FRAMES] ?? 0;
        if (!is_int($frames)) {
            throw new LogicException('Invalid scanner frame count');
        }

        return max(
            $this->depth + $this->live + max(0, $frames - $this->frameBase),
            is_int($tok[Scanner::CAP_DEPTH] ?? null) ? $tok[Scanner::CAP_DEPTH] : 0,
        );
    }

    /**
     * C2-I-057, C2-I-058, C2-I-061.
     *
     * @param array<mixed> $tok
     *
     * @return void
     */
    private function closer(array $tok): void
    {
        $m = $tok[4];
        assert(is_string($m) && is_int($tok[2]) && is_int($tok[3]) && is_int($tok[5]));
        $kind = '{' . $m;
        if (($this->byKind[$kind] ?? []) !== []) {
            $id = $this->byKind[$kind][count($this->byKind[$kind]) - 1];
            $e = $this->stack[$id];
            if ($e[3] === $tok[2]) {
                $this->stack[$id][6] = false;
                array_pop($this->byKind[$kind]);
                $this->live--;
                $this->toText($e[0]);
                $this->text($tok[2], $tok[3]);

                return;
            }
            $this->close($id, $tok[3], Tables::BRACED_MARKS[$m]);
            $this->poison['*'] = $e[4];
            $this->poison['/'] = $e[5];

            return;
        }
        if (($tok[5] & Scanner::FALLBACK) !== 0) {
            $this->bare([
                Scanner::DELIM, Scanner::BARE, $tok[2], $tok[2] + 1, $m, $tok[5] & (Scanner::CAN_OPEN | Scanner::CAN_CLOSE),
                Scanner::FRAMES => $tok[Scanner::FRAMES] ?? 0,
            ]);
            $this->text($tok[3] - 1, $tok[3]);

            return;
        }
        $this->text($tok[2], $tok[3]);
    }

    /**
     * Closes entry `$id`: every entry above it is removed and is text (C2-I-051, C2-I-059).
     *
     * @param int $id
     * @param int $end
     * @param string $type
     *
     * @return void
     */
    private function close(int $id, int $end, string $type): void
    {
        $top = count($this->stack) - 1;
        for (; $top > $id; $top--) {
            $e = array_pop($this->stack);
            if ($e !== null && $e[6]) {
                array_pop($this->byKind[$e[1]]);
                $this->live--;
                $this->toText($e[0]);
            }
        }
        $e = array_pop($this->stack);
        if ($e === null) {
            return;
        }
        array_pop($this->byKind[$e[1]]);
        $this->live--;
        $children = array_slice($this->items, $e[0] + 1);
        for ($k = count($this->items) - 1; $k >= $e[0]; $k--) {
            array_pop($this->items);
        }
        $node = $this->sc->node($type, $e[2], $end);
        $this->items[] = [Scanner::NODE, $node, $e[2], $end, true, new TokenList($children)];
    }

    /**
     * @param int $index
     *
     * @return void
     */
    private function toText(int $index): void
    {
        $tok = $this->items[$index];
        assert(is_int($tok[2]) && is_int($tok[3]));
        $this->items[$index] = [Scanner::TEXT, substr($this->sc->t, $tok[2], $tok[3] - $tok[2]), $tok[2], $tok[3], false];
    }

    /**
     * @param int $ts
     * @param int $te
     * @param bool $typed Text as typed, without typography (C2-I-118).
     *
     * @return void
     */
    private function text(int $ts, int $te, bool $typed = false): void
    {
        $this->items[] = [Scanner::TEXT, substr($this->sc->t, $ts, $te - $ts), $ts, $te, $typed];
    }
}
