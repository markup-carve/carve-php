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

    /**
     * @var array<int, int>
     */
    public array $codes = [];

    /**
     * @var array<int, int>
     */
    public array $kinds = [];

    /**
     * @var array<int, string>
     */
    public array $owners = [];

    /**
     * @var array<int, string>
     */
    public array $ownerTypes = [];

    /**
     * @var array<int, string|null>
     */
    public array $ownerForms = [];

    /**
     * @var array<int, int>
     */
    public array $offsets = [];

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
        $this->ownerForms[] = $ownerForm;
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
        array_pop($this->codes);
        array_pop($this->kinds);
        array_pop($this->owners);
        array_pop($this->ownerTypes);
        array_pop($this->ownerForms);
        array_pop($this->offsets);
        $this->notes = null;
    }

    public function text(int $from = 0, ?int $to = null): string
    {
        $codes = array_slice($this->codes, $from, ($to ?? count($this->codes)) - $from);
        if ($codes === []) {
            return '';
        }

        return mb_convert_encoding(pack('v*', ...$codes), 'UTF-8', 'UTF-16LE');
    }
}
