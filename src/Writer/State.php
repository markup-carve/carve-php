<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Diagnostics\Diagnostic;

/**
 * What one run of the writer holds: the lines written so far, the pending marker lead of the next line (C2-W-027), the
 * sidecar facts by path (C2-L-046) and the losses (C2-W-011).
 */
final class State
{
    public ?string $source = null;

    public ?RepairTrace $repairTrace = null;

    /**
     * @var array<string>
     */
    public array $lines = [];

    /**
     * What the next line starts with instead of its prefix: the markers of the bodies it opens.
     */
    public ?string $lead = null;

    /**
     * C2-W-044: a bare-dot item marker waits on this line.
     */
    public bool $bareDotLead = false;

    /**
     * C2-W-121: the block being written follows a description list among its siblings.
     */
    public bool $afterDescriptions = false;

    /**
     * C2-B-046: the lead behind the marker of an item without a task, where text could read as a task box.
     */
    public ?string $boxLead = null;

    /**
     * @var array<string, true> Paths already reported.
     */
    private array $lost = [];

    /**
     * @var array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public array $losses = [];

    /**
     * @var array<string, true> C2-I-098: the keys the document defines as abbreviations.
     */
    public array $abbreviations = [];

    /**
     * @var array<string, true> C2-W-094: the keys the document defines as link references.
     */
    public array $labels = [];

    /**
     * @var array<string, true> Paths of fences written without their closer (C2-W-123).
     */
    public array $openFences = [];

    /**
     * @var array<string, array<int>> C2-L-020: the offsets in a text node at which a continuation fragment starts.
     */
    public array $wrapPoints = [];

    /**
     * Path of the node whose attribute line was written above a comment (C2-W-122).
     */
    public ?string $prewritten = null;

    /**
     * The first line of each block written, with its path and type, in order.
     *
     * @var array<array{0: int, 1: string, 2: string}>
     */
    public array $starts = [];

    /**
     * The extension registrations of the caller (C2-X-049).
     *
     * @var array<mixed>
     */
    public array $extensions = [];

    /**
     * @var int
     */
    private const REPAIR_FLOOR = 24_000_000;

    /**
     * @var int
     */
    private const REPAIR_FACTOR = 1000;

    /**
     * @var array<mixed>|null
     */
    private ?array $repairGroup = null;

    /**
     * @var array<mixed>|null
     */
    private ?array $repairDocument = null;

    private int $repairRead = 0;

    private ?int $repairLimit = null;

    /**
     * @var array<string, true>
     */
    private array $repairStates = [];

    /**
     * @param array<string, array<mixed>> $facts Sidecar entries by path.
     * @param bool $alignTables
     * @param bool $normalize
     */
    public function __construct(public array $facts, public bool $alignTables, public bool $normalize)
    {
    }

    /**
     * C2-W-133: one repair budget for the whole document, sized by its blocks.
     *
     * @param array<mixed> $children
     */
    public function beginRepairDocument(array $children): void
    {
        $this->repairDocument = $children;
        $this->repairRead = 0;
        $this->repairLimit = null;
    }

    /**
     * C2-W-133: the outer block is the repair group, including any nested blocks. Every group draws on the
     * document budget.
     *
     * @param array<mixed> $node
     */
    public function beginRepairGroup(array $node): void
    {
        $this->repairGroup = $node;
        $this->repairStates = [];
    }

    public function endRepairGroup(): void
    {
        $this->repairGroup = null;
        $this->repairStates = [];
    }

    /**
     * Charge the characters a writer reparse is about to read. A repeated parse at the same
     * node and stage is a repeated repair state. The encoded document is a conservative size
     * allowance for the source it can spell, including escaped characters and markers.
     *
     * @throws \MarkupCarve\Carve\Writer\RepairRefused
     */
    public function chargeRepair(string $path, string $stage, string $text): void
    {
        if ($this->repairGroup === null) {
            return;
        }
        $key = $path . "\0" . $stage . "\0" . hash('xxh3', $text);
        if (isset($this->repairStates[$key])) {
            throw new RepairRefused('writer repair repeated a state');
        }
        $this->repairStates[$key] = true;
        $this->repairRead += strlen($text);
        if ($this->repairLimit === null) {
            $encoded = json_encode($this->repairDocument ?? $this->repairGroup, JSON_INVALID_UTF8_SUBSTITUTE);
            $allowance = $encoded === false ? 0 : strlen($encoded) * 4;
            $this->repairLimit = max(self::REPAIR_FLOOR, self::REPAIR_FACTOR * $allowance);
        }
        if ($this->repairRead > $this->repairLimit) {
            throw new RepairRefused('writer repair exceeded its character limit');
        }
    }

    public function refuseRepair(string $path): void
    {
        $this->losses[] = new Diagnostic('writer-refused', 'warning', 'render-loss', 'Writer repair stopped for this block group', $path);
    }

    /**
     * @param string $path
     * @param string $fact
     *
     * @return mixed
     */
    public function fact(string $path, string $fact): mixed
    {
        // C2-W-127: `normalize` writes as if there were no sidecar.
        if ($this->normalize) {
            return null;
        }

        return $this->facts[$path][$fact] ?? null;
    }

    /**
     * C2-W-011, C2-W-117: `field` of the node at `path`, or the node itself, has no spelling. One entry per node.
     *
     * @param string $path
     * @param string $type
     * @param string|null $field
     *
     * @return void
     */
    public function lose(string $path, string $type, ?string $field): void
    {
        $where = $field === null ? $path : $path . '/' . $field;
        if (isset($this->lost[$where])) {
            return;
        }
        $this->lost[$where] = true;
        $message = $field === null
            ? "Carve source cannot spell a $type node here"
            : "Carve source cannot spell the $field field of a $type node here";
        $code = $field === null ? 'structure-unspellable' : 'field-unspellable';
        $this->losses[] = new Diagnostic($code, 'warning', 'render-loss', $message, $where);
    }

    /**
     * C2-W-016: a CR at the end of a line reads as part of its line ending, so the block that wrote it loses the value.
     *
     * @return void
     */
    public function loseLineEndCarriageReturns(): void
    {
        $at = 0;
        $owner = null;
        foreach ($this->lines as $i => $line) {
            while (isset($this->starts[$at]) && $this->starts[$at][0] <= $i) {
                $owner = $this->starts[$at++];
            }
            if ($owner !== null && str_ends_with($line, "\r") && !$this->lostUnder($owner[1])) {
                $this->lose($owner[1], $owner[2], 'value');
            }
        }
    }

    private function lostUnder(string $path): bool
    {
        foreach (array_keys($this->lost) as $where) {
            if ($where === $path || str_starts_with((string)$where, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes one line behind the pending lead or `prefix`. C2-W-021: an empty line keeps no spaces of its prefix.
     *
     * @param string $prefix
     * @param string $text
     *
     * @return void
     */
    public function emit(string $prefix, string $text): void
    {
        $head = $this->lead ?? $prefix;
        $this->lead = null;
        $this->bareDotLead = false;
        $this->boxLead = null;
        $this->lines[] = $text === '' ? rtrim($head, " \t") : $head . $text;
    }

    /**
     * Writes the lines of `text`: the first behind the lead or `prefix`, the others behind `prefix` and `indent`.
     *
     * @param string $prefix
     * @param string $indent
     * @param string $text
     *
     * @return void
     */
    public function emitLines(string $prefix, string $indent, string $text): void
    {
        $lines = explode("\n", $text);
        foreach ($lines as $at => $line) {
            // C2-W-059, C2-B-138: a comment-only line stands at the column of the leaf that holds it.
            $this->emit($at === 0 || str_starts_with($line, '%%') ? $prefix : $prefix . $indent, $line);
        }
    }
}
