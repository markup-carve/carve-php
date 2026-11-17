<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Writer;

use MarkupCarve\Carve2\Diagnostics\Diagnostic;

/**
 * What one run of the writer holds: the lines written so far, the pending marker lead of the next line (C2-W-027), the
 * sidecar facts by path (C2-L-046) and the losses (C2-W-011).
 */
final class State
{
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
     * @var array<string, true> Paths already reported.
     */
    private array $lost = [];

    /**
     * @var array<\MarkupCarve\Carve2\Diagnostics\Diagnostic>
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
     * @param array<string, array<mixed>> $facts Sidecar entries by path.
     * @param bool $alignTables
     * @param bool $normalize
     */
    public function __construct(public array $facts, public bool $alignTables, public bool $normalize)
    {
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
            ? "a $type node has no spelling in Carve source here"
            : "the field $field of a $type node has no spelling in Carve source here";
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
