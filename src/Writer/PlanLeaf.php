<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Inline\Chars;

/**
 * The source units of one inline leaf, with the node and offset that wrote each unit.
 * Mirrors the Leaf model in carve-js write-plan-model.ts.
 */
final class PlanLeaf
{
    /**
     * @var int
     */
    public const TEXT = 0;

    /**
     * @var int
     */
    public const ESCAPE = 1;

    /**
     * @var int
     */
    public const OPEN = 2;

    /**
     * @var int
     */
    public const CLOSE = 3;

    /**
     * @var int
     */
    public const ATOM = 4;

    public PlanVector $codes;

    public PlanVector $kinds;

    public PlanStringVector $owners;

    public PlanStringVector $ownerTypes;

    public PlanStringVector $ownerForms;

    /**
     * @var array<string, array<mixed>>
     */
    public array $nodes = [];

    public PlanVector $offsets;

    public function __construct()
    {
        $this->codes = new PlanVector('u16');
        $this->kinds = new PlanVector('u8');
        $this->owners = new PlanStringVector();
        $this->ownerTypes = new PlanStringVector();
        $this->ownerForms = new PlanStringVector();
        $this->offsets = new PlanVector('i32');
    }

    public ?string $undecided = null;

    /**
     * @var array<int, string>
     */
    public array $respell = [];

    public int $cursor = 0;

    public int $where = 0;

    /**
     * @var array<int, bool>|null
     */
    public ?array $opaque = null;

    public int $angleFailEnd = -1;

    public int $braceFailEnd = -1;

    public bool $inCell = false;

    public bool $inTitle = false;

    public bool $inLine = false;

    public int $lastAtomTick = -1;

    /**
     * @var array<int, int>|null
     */
    public ?array $ticks = null;

    /**
     * @var array<int, int>|null
     */
    public ?array $closes = null;

    /**
     * @var array<int, int>|null
     */
    public ?array $opens = null;

    public int $lastBracket = -1;

    /**
     * @var array<int, int>
     */
    public array $extensions = [];

    public bool $textMark = false;

    public bool $textOpener = false;

    public bool $hasLineComment = false;

    public int $openDepth = 0;

    public int $openBrackets = 0;

    public int $openBraces = 0;

    /**
     * @var array<int, string>
     */
    public array $lostValues = [];

    /**
     * @var array<int, bool>|null
     */
    private ?array $notes = null;

    public function undecide(string $why): void
    {
        if ($this->undecided !== null) {
            return;
        }
        $this->undecided = $why;
        $this->where = $this->cursor;
    }

    /**
     * @param int $code
     * @param int $kind
     * @param string $owner Path of the node that wrote this unit.
     * @param string $ownerType
     * @param string|null $ownerForm
     * @param int $offset
     */
    public function push(
        int $code,
        int $kind,
        string $owner,
        int $offset = -1,
        string $ownerType = '',
        ?string $ownerForm = null,
    ): void {
        $last = count($this->kinds) - 1;
        $read = $kind === self::OPEN || $kind === self::CLOSE
            || ($kind === self::TEXT && ($this->kinds[$last] ?? null) !== self::ESCAPE);
        if ($read) {
            if ($code === 91) {
                $this->openBrackets++;
            } elseif ($code === 93 && $this->openBrackets > 0) {
                $this->openBrackets--;
            } elseif ($code === 123) {
                $this->openBraces++;
            } elseif ($code === 125 && $this->openBraces > 0) {
                $this->openBraces--;
            }
        }
        if ($code === 96 && $kind === self::ATOM) {
            $this->lastAtomTick = count($this->codes);
        }
        if ($code === 93) {
            $this->lastBracket = count($this->codes);
        }
        $this->codes[] = $code;
        $this->kinds[] = $kind;
        $this->owners[] = $owner;
        $this->ownerTypes[] = $ownerType;
        $this->ownerForms[] = $ownerForm ?? '';
        $this->offsets[] = $offset;
    }

    public function pushString(string $value, int $kind, string $owner, string $ownerType, ?string $ownerForm = null): void
    {
        for ($at = 0, $length = strlen($value); $at < $length; $at++) {
            $code = Chars::at($value, $at);
            $this->pushCodePoint($code, $kind, $owner, -1, $ownerType, $ownerForm);
            $at += Chars::len($value, $at) - 1;
        }
    }

    /**
     * @param string $value
     * @param string $owner
     * @param array<int, true> $escapes
     */
    public function pushText(string $value, string $owner, array $escapes = []): void
    {
        for ($at = 0, $length = strlen($value); $at < $length; $at++) {
            if (isset($escapes[$at])) {
                $this->push(92, self::ESCAPE, $owner, $at, 'text');
            }
            $code = Chars::at($value, $at);
            $this->pushCodePoint($code, self::TEXT, $owner, $at, 'text');
            $at += Chars::len($value, $at) - 1;
        }
    }

    private function pushCodePoint(
        int $code,
        int $kind,
        string $owner,
        int $offset,
        string $ownerType,
        ?string $ownerForm = null,
    ): void {
        if ($code <= 0xFFFF) {
            $this->push($code, $kind, $owner, $offset, $ownerType, $ownerForm);

            return;
        }
        $part = $code - 0x10000;
        $this->push(0xD800 + ($part >> 10), $kind, $owner, $offset, $ownerType, $ownerForm);
        $this->push(0xDC00 + ($part & 0x3FF), $kind, $owner, $offset, $ownerType, $ownerForm);
    }

    public function inNote(int $at): bool
    {
        if ($this->notes === null) {
            $this->notes = [];
            $depth = 0;
            foreach ($this->codes as $unit => $code) {
                $note = $this->ownerTypes[$unit] === 'inline_footnote';
                if ($note && $this->kinds[$unit] === self::CLOSE) {
                    $depth--;
                }
                $this->notes[$unit] = $depth > 0;
                if ($note && $this->kinds[$unit] === self::OPEN && $code === 91) {
                    $depth++;
                }
            }
        }

        return $this->notes[$at] ?? false;
    }

    /**
     * @return array<mixed>|null
     */
    public function nodeAt(int $unit): ?array
    {
        $owner = $this->owners[$unit] ?? null;
        if ($owner === null) {
            return null;
        }

        return $this->nodes[$owner] ?? ['type' => $this->ownerTypes[$unit]];
    }

    public function trimText(int $from): void
    {
        $end = count($this->codes);
        while ($end > $from) {
            $last = $end - 1;
            if ($this->kinds[$last] !== self::TEXT || ($this->codes[$last] !== 32 && $this->codes[$last] !== 9)) {
                break;
            }
            $this->trimLast();
            $end--;
        }
    }

    public function trimLast(): void
    {
        $this->codes->pop();
        $this->kinds->pop();
        $this->owners->pop();
        $this->ownerTypes->pop();
        $this->ownerForms->pop();
        $this->offsets->pop();
        $this->notes = null;
    }

    public function text(int $from = 0, ?int $to = null): string
    {
        $length = ($to ?? count($this->codes)) - $from;
        if ($length <= 0) {
            return '';
        }

        return mb_convert_encoding($this->codes->raw($from, $length), 'UTF-8', 'UTF-16LE');
    }
}
