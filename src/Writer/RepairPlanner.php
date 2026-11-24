<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;

/**
 * The read-back rounds of C2-W-088, including the ordered C2-W-125 trials.
 */
final class RepairPlanner
{
    /**
     * @var array<string, array<int, true>>
     */
    private array $escapes = [];

    /**
     * @var array<string, int>
     */
    private array $attempts = [];

    /**
     * @var array<string, int>
     */
    private array $textOrder = [];

    /**
     * @var array<string, array<int, true>>
     */
    private array $trialEscapes = [];

    private int $read = 0;

    /**
     * C2-W-088: whether a round escapes every potential opener of a reported kind, or the reported openers only.
     */
    private bool $kindRounds = true;

    /**
     * @var array<string, array<string, true>> The kinds already escaped, by leaf path.
     */
    private array $kindsDone = [];

    /**
     * @var array<string>
     */
    private const INLINE_CONTAINERS = [
        'superscript', 'subscript', 'emphasis', 'strong', 'underline', 'strike', 'highlight', 'insert', 'delete',
        'link', 'image', 'span', 'inline_footnote', 'inline_extension', 'citation', 'abbreviation', 'quote',
    ];

    /**
     * @var array<string> The nodes whose content a `]` closes.
     */
    private const BRACKETED = ['link', 'span', 'inline_footnote', 'inline_extension'];

    private int $limit;

    /**
     * The fingerprint of the tree every round compares its read-back with.
     */
    private string $treePrint;

    /**
     * The planner a content probe runs for: its character budget is the one charged.
     */
    private ?self $parent = null;

    /**
     * In a content probe, the type of the probed node.
     */
    private ?string $probed = null;

    // The rounds of one content probe, as MAX_PROBE_ROUNDS in carve-js.
    /**
     * @var int
     */
    private const MAX_PROBE_ROUNDS = 32;

    /**
     * @param array<mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $seed The escapes of the local write, which the rounds go on from.
     * @param string|null $treePrint `TreeEquality::fingerprint()` of `$tree`, when the caller has it.
     */
    public function __construct(
        private array $tree,
        private ?array $layout,
        private array $options = [],
        array $seed = [],
        ?string $treePrint = null,
    ) {
        foreach ($seed as $path => $offsets) {
            $value = self::leafValue($tree, $path);
            foreach ($offsets as $at => $_) {
                // A backslash before punctuation is doubled by the bare write itself.
                if ($value !== null && !($value[$at] === '\\' && Text::isPunct($value[$at + 1] ?? ''))) {
                    $this->escapes[$path][$at] = true;
                }
            }
        }
        $encoded = json_encode($tree, JSON_INVALID_UTF8_SUBSTITUTE);
        $allowance = $encoded === false ? 0 : strlen($encoded) * 4;
        $this->limit = max(24_000_000, 1000 * $allowance);
        self::textOrder($tree, '', $this->textOrder);
        $this->treePrint = $treePrint ?? TreeEquality::fingerprint($tree);
    }

    /**
     * The first round's candidate and read-back.
     *
     * @var array{
     *     array{
     *         source: string,
     *         text: array<int, int>,
     *         paths: array<int, string>,
     *         nodes: array<int, array{string, string, int, int}>,
     *         ranges: array<string, array{int, int}>
     *     },
     *     array{
     *         equal: bool,
     *         openers: array<string, array<int, true>>,
     *         moved: array<int, array{string, string, int, int, int}>,
     *         read: array<string, mixed>
     *     }
     * }|null
     */
    private ?array $first = null;

    /**
     * The escapes of this round's candidate and its source, while the round is applied.
     *
     * @var array{array<string, array<int, true>>, string}|null
     */
    private ?array $roundSource = null;

    /**
     * `roundSource` and the round's read-back, while the trials of the round are read.
     *
     * @var array{array<string, array<int, true>>, string, array<string, mixed>}|null
     */
    private ?array $roundRead = null;

    /**
     * `roundSource` and the fingerprint of its read-back, while a lone trial of the round is read one source at a time.
     *
     * @var array{array<string, array<int, true>>, string, string}|null
     */
    private ?array $roundPrint = null;

    /**
     * `readPrints()` of the read-back the next round starts from, when its comparison has them.
     *
     * @var array<string, string>|null
     */
    private ?array $readParts = null;

    /**
     * `readParts` of the round's read-back, while `roundRead` holds it.
     *
     * @var array<string, string>|null
     */
    private ?array $roundParts = null;

    /**
     * A source a trial read and its read-back, which the next round takes when it writes the same source.
     *
     * @var array{string, array<string, mixed>}|null
     */
    private ?array $nextRead = null;

    /**
     * Whether the last read-back of `compared()` is one paragraph, which a content probe's first round needs.
     */
    private bool $singleParagraph = false;

    /**
     * The candidate of the starting escapes and its `ReadbackRound::inspect()`, when the caller already has them.
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges: array<string, array{int, int}>
     * } $candidate
     * @param array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * } $comparison
     * @param array<string, string>|null $parts `readPrints()` of the read-back.
     */
    public function startFrom(array $candidate, array $comparison, ?array $parts = null): void
    {
        $this->first = [$candidate, $comparison];
        $this->readParts = $parts;
    }

    /**
     * `TreeEquality::fingerprint()` of a read-back, with the fingerprint of each block in it by path, so the trials of
     * a round compare their leaves without hashing the read-back again.
     *
     * @param array<mixed> $read
     *
     * @return array{string, array<string, string>}
     */
    public static function readPrints(array $read): array
    {
        static $stop = null;
        $stop ??= array_fill_keys(self::INLINE_CONTAINERS, true);

        return TreeEquality::fingerprintParts($read, $stop);
    }

    /**
     * C2-W-133: null means the repair could not settle inside the existing character budget.
     */
    public function repair(): ?string
    {
        $seen = [];
        for ($round = 0; $round < 8192; $round++) {
            $escapes = $this->escapes;
            $first = $this->first;
            $this->first = null;
            $source = $first === null ? Writer::bareSource($this->tree, $this->layout, $this->options, $escapes) : $first[0]['source'];
            if (!$this->charge($source)) {
                return null;
            }
            $key = hash('xxh3', $source . "\0" . serialize($this->attempts));
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $compared = $first ?? $this->compared($source, $escapes, $this->layout);
            unset($first);
            if ($compared === null || $compared[1]['equal']) {
                return $source;
            }
            [$candidate, $comparison] = $compared;
            unset($compared);
            if (!$this->appliedRound($escapes, $candidate, $comparison)) {
                return null;
            }
            // The next round's write and parse do not need this round's candidate and read-back.
            unset($candidate, $comparison);
        }

        return null;
    }

    /**
     * The candidate of a bare source and its comparison, or null where the source reads back as the tree: only a round
     * that goes on needs the positions of the marked write.
     *
     * @param string $source
     * @param array<string, array<int, true>> $escapes The escapes the source was written with.
     * @param array<mixed>|null $layout
     *
     * @return array{
     *     array{
     *         source: string,
     *         text: array<int, int>,
     *         paths: array<int, string>,
     *         nodes: array<int, array{string, string, int, int}>,
     *         ranges: array<string, array{int, int}>
     *     },
     *     array{
     *         equal: bool,
     *         openers: array<string, array<int, true>>,
     *         moved: array<int, array{string, string, int, int, int}>,
     *         read: array<string, mixed>
     *     }
     * }|null
     */
    private function compared(string $source, array $escapes, ?array $layout): ?array
    {
        $read = $this->readOf($source);
        $blocks = is_array($read['children'] ?? null) ? $read['children'] : [];
        $this->singleParagraph = count($blocks) === 1 && is_array($blocks[0]) && ($blocks[0]['type'] ?? '') === 'paragraph';
        [$print, $parts] = self::readPrints($read);
        if ($print === $this->treePrint) {
            return null;
        }
        $this->readParts = $parts;
        $candidate = Writer::markedCandidate($this->tree, $layout, $this->options, $escapes, $source);

        return [$candidate, ReadbackRound::compare($candidate, $read, $this->tree, $this->treePrint, false)];
    }

    /**
     * The read-back of a source: the one a trial of the last round read, where it wrote this same source.
     *
     * @return array<string, mixed>
     */
    private function readOf(string $source): array
    {
        $next = $this->nextRead;
        $this->nextRead = null;
        if ($next !== null && $next[0] === $source) {
            return $next[1];
        }
        unset($next);

        return ReadbackRound::read($source, $this->options);
    }

    /**
     * `applyRound()` with the round's read-back at hand for its trials.
     *
     * @param-out array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>
     * } $comparison
     *
     * @param array<string, array<int, true>> $escapes The escapes the candidate was written with.
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * } $comparison
     */
    private function appliedRound(array $escapes, array &$candidate, array &$comparison): bool
    {
        $this->roundSource = [$escapes, $candidate['source']];
        try {
            return $this->applyRound($candidate, $comparison);
        } finally {
            $this->roundSource = null;
        }
    }

    /**
     * The characters the rounds have read so far, the measure of C2-W-133.
     */
    public function charactersRead(): int
    {
        return $this->read;
    }

    /**
     * @param-out array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>
     * } $comparison
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * } $comparison
     */
    private function applyRound(array &$candidate, array &$comparison): bool
    {
        $firsts = [];
        $asked = CompareRound::inspect($this->tree, $comparison['read'], $this->escapes, $candidate, $this->probed, $firsts);
        $missing = self::missingSiblings($candidate, $comparison['read']);
        $trials = $this->nextTrials($candidate, $comparison['read'], $missing);
        $differences = [];
        if ($comparison['openers'] === [] && $missing === [] && $asked === []) {
            self::textDifferences($this->tree, $comparison['read'], '', $differences);
        }
        // The trials below read the whole document again; only a trial read of the round's own source keeps the
        // read-back.
        $this->roundRead = $this->roundSource === null ? null : [...$this->roundSource, $comparison['read']];
        $this->roundParts = $this->roundRead === null ? null : $this->readParts;
        $this->readParts = null;
        unset($comparison['read']);
        // The probes behind the trials need only their own spans of the candidate, not all of it beside the trial reads.
        $groups = $this->movedByLeaf($comparison['moved']);
        $across = self::acrossSpans($candidate, self::probedMoves($groups));
        $candidate = [
            'source' => $candidate['source'],
            'text' => $across === [] ? [] : $candidate['text'],
            'paths' => $candidate['paths'],
            'nodes' => [],
            'ranges' => [],
        ];
        $early = $this->escapeEarlyNoteClosers($comparison['openers']);
        // C2-W-125, case eight: the trials and asked escapes of this round read the note that closed early, which the
        // closer escape undoes.
        $kept = $early ? [] : $this->keptTrials($trials);
        $asked = $early ? [] : $asked;
        $this->roundRead = null;
        $this->roundParts = null;
        foreach ($kept as $trial) {
            foreach ($trial as [$path, $offset]) {
                $this->addEscape($path, $offset);
                $this->trialEscapes[$path][$offset] = true;
            }
        }
        $this->addAsked($comparison['openers']);
        if ($this->kindRounds && $comparison['openers'] !== []) {
            // The kind escapes go first; a closer escape CompareRound asks for (C2-W-125) gives no kind and waits for a
            // later round, which asks for it again only if the kinds did not make it needless.
            $this->escapeKinds($comparison['openers']);
            $this->escapePartnerClosers();
        } else {
            // C2-W-088: a braced opener that starts a node that did not form is an opener of its kind, so the round
            // escapes every potential opener of that kind in the leaf block, not one node per round.
            if ($this->kindRounds && !$early) {
                $this->escapeKinds($this->bracedFirsts($firsts));
            }
            $this->addAsked($asked);
        }
        $moved = false;
        foreach ($groups as $group) {
            $moved = $this->probeMoved($candidate, $across, $group) || $moved;
            $moved = $this->ownerMoved($group) || $moved;
        }
        if ($early || $comparison['openers'] !== [] || $missing !== [] || $asked !== []) {
            return $early || $comparison['openers'] !== [] || $trials !== [] || $asked !== [] || $moved;
        }

        $this->addAsked($differences);

        return $differences !== [] || $moved;
    }

    /**
     * C2-W-125, case eight: a reported inline footnote at a text `^[` whose span keeps a caret pair of the tree ordinary
     * (C2-I-089) stays bare, and the bare `]` that closed it early takes the backslash, which gives no kind. Each later
     * text `^[` before a caret pair takes its early closer in the same round (C2-W-088: the round repairs every note
     * its parse reports, also those behind a node that did not form). The openers it handles leave `$reported`.
     *
     * @param array<string, array<int, true>> $reported
     */
    private function escapeEarlyNoteClosers(array &$reported): bool
    {
        $fired = false;
        $this->notesDone = [];
        // The reported texts of one leaf share their siblings; each list is read and walked once per round.
        $lists = [];
        foreach ($reported as $path => $offsets) {
            $cut = strrpos($path, '/children/');
            if ($cut === false) {
                continue;
            }
            $parent = substr($path, 0, $cut + 10);
            if (!isset($lists[$parent])) {
                $children = self::childrenOf(self::nodeAt($this->tree, substr($path, 0, $cut)) ?? []);
                $lists[$parent] = [$children, self::lastCaretPair($children)];
            }
            [$siblings, $last] = $lists[$parent];
            $i = (int)substr($path, $cut + 10);
            $value = $siblings[$i]['value'] ?? null;
            if (!is_string($value) || $last <= $i) {
                continue;
            }
            foreach ($offsets as $at => $_) {
                if (!$this->isTextNote($siblings, $parent, $i, $value, $at)) {
                    continue;
                }
                if (!isset($this->notesDone[$path][$at])) {
                    $closer = $this->earlyNoteCloser($siblings, $parent, $i, $at + 1);
                    if ($closer === null || !$this->addEscape(...$closer)) {
                        continue;
                    }
                    $this->laterNoteClosers($siblings, $parent, $i, $at + 1, $last);
                    $this->bracketedClosers($siblings, $parent, $i);
                    $fired = true;
                }
                unset($reported[$path][$at]);
            }
            if ($reported[$path] === []) {
                unset($reported[$path]);
            }
        }

        return $fired;
    }

    /**
     * @var array<string, array<int, true>> C2-W-125, case eight: the `[` of each text `^[` whose early closer this round
     *   escaped.
     */
    private array $notesDone = [];

    /**
     * Whether the reported `[` at `$at` follows a bare caret of the text, which may end the text node before.
     *
     * @param array<int, array<mixed>> $siblings
     * @param string $parent
     * @param int $i
     * @param string $value
     * @param int $at
     */
    private function isTextNote(array $siblings, string $parent, int $i, string $value, int $at): bool
    {
        if (($value[$at] ?? '') !== '[' || isset($this->escapes[$parent . $i][$at])) {
            return false;
        }
        if ($at > 0) {
            return $value[$at - 1] === '^' && !isset($this->escapes[$parent . $i][$at - 1]);
        }
        $before = $siblings[$i - 1] ?? null;
        $prior = is_array($before) && ($before['type'] ?? '') === 'text' && is_string($before['value'] ?? null) ? $before['value'] : '';

        return str_ends_with($prior, '^') && !isset($this->escapes[$parent . ($i - 1)][strlen($prior) - 1]);
    }

    /**
     * Each later text `^[` of the siblings that stands before a caret pair takes its early closer.
     *
     * @param array<int, array<mixed>> $siblings
     * @param string $parent
     * @param int $i
     * @param int $from
     * @param int $last The `lastCaretPair()` of the siblings.
     */
    private function laterNoteClosers(array $siblings, string $parent, int $i, int $from, int $last): void
    {
        foreach ($siblings as $k => $text) {
            $value = $text['value'] ?? null;
            if ($k < $i || $k >= $last || ($text['type'] ?? '') !== 'text' || !is_string($value)) {
                continue;
            }
            for ($at = strpos($value, '^[', $k === $i ? $from : 0); $at !== false; $at = strpos($value, '^[', $at + 2)) {
                if (isset($this->escapes[$parent . $k][$at]) || isset($this->escapes[$parent . $k][$at + 1])) {
                    continue;
                }
                $closer = $this->earlyNoteCloser($siblings, $parent, $k, $at + 2);
                if ($closer !== null && $this->addEscape(...$closer)) {
                    $this->notesDone[$parent . $k][$at + 1] = true;
                }
            }
        }
    }

    /**
     * C2-W-125, case five, in the round of case eight: the note that closed early hides the links and spans behind it
     * from the comparison, so a lone `]` of their content that would end one early takes its backslash in this round.
     *
     * @param array<int, array<mixed>> $siblings
     * @param string $parent
     * @param int $i
     */
    private function bracketedClosers(array $siblings, string $parent, int $i): void
    {
        foreach ($siblings as $k => $node) {
            // A caret pair inside an inline footnote opens nothing (C2-I-089), so a note's content stays as it is.
            if ($k <= $i || !in_array($node['type'] ?? '', ['link', 'span', 'inline_extension'], true)) {
                continue;
            }
            $depth = 0;
            foreach (self::childrenOf($node) as $j => $child) {
                $value = ($child['type'] ?? '') === 'text' && is_string($child['value'] ?? null) ? $child['value'] : '';
                $path = $parent . $k . '/children/' . $j;
                $n = strlen($value);
                for ($at = strcspn($value, '[]'); $at < $n; $at += 1 + strcspn($value, '[]', $at + 1)) {
                    if (isset($this->escapes[$path][$at])) {
                        continue;
                    }
                    if ($value[$at] === '[') {
                        $depth++;
                    } elseif ($depth > 0) {
                        $depth--;
                    } else {
                        $this->addEscape($path, $at);
                    }
                }
            }
        }
    }

    /**
     * The bare `]` from sibling `$i` at `$from` on that no bare `[` there pairs with, as a path and an offset. A node of
     * the tree between, as a strong, a code span or a link, holds no bracket of the note.
     *
     * @param array<int, array<mixed>> $siblings
     * @param string $parent
     * @param int $i
     * @param int $from
     *
     * @return array{string, int}|null
     */
    private function earlyNoteCloser(array $siblings, string $parent, int $i, int $from): ?array
    {
        $depth = 0;
        foreach ($siblings as $k => $text) {
            $value = $text['value'] ?? null;
            if ($k < $i || ($text['type'] ?? '') !== 'text' || !is_string($value)) {
                continue;
            }
            $escaped = $this->escapes[$parent . $k] ?? [];
            $n = strlen($value);
            $start = $k === $i ? min($from, $n) : 0;
            for ($at = $start + strcspn($value, '[]', $start); $at < $n; $at += 1 + strcspn($value, '[]', $at + 1)) {
                if (isset($escaped[$at])) {
                    continue;
                }
                if ($value[$at] === '[') {
                    $depth++;
                } elseif ($depth-- === 0) {
                    return [$parent . $k, $at];
                }
            }
        }

        return null;
    }

    /**
     * The last sibling that is, or holds below it, link or span text starting with a caret, the bracket of which forms
     * `[^`; -1 for none. One inside an inline footnote opens nothing there (C2-I-089), so it does not count.
     *
     * @param array<int, array<mixed>> $siblings
     */
    private static function lastCaretPair(array $siblings): int
    {
        for ($k = array_key_last($siblings) ?? -1; $k >= 0; $k--) {
            if (isset($siblings[$k]) && self::holdsCaretPair($siblings[$k])) {
                return $k;
            }
        }

        return -1;
    }

    /**
     * C2-W-125, case eight: whether a text `^[` of the tree stands before a sibling that holds a caret pair, which the
     * span of that `^[` keeps ordinary (C2-I-089). Escaping the bracket of that `^[` would let the caret pair open.
     *
     * @param array<mixed> $node
     */
    public static function hasTextNoteBeforeCaretPair(array $node): bool
    {
        if (self::textNoteBeforeCaretPair($node)) {
            return true;
        }
        foreach (self::childrenOf($node) as $child) {
            if (self::hasTextNoteBeforeCaretPair($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `hasTextNoteBeforeCaretPair()` for the children of `$node` only.
     *
     * @param array<mixed> $node
     */
    private static function textNoteBeforeCaretPair(array $node): bool
    {
        $children = self::childrenOf($node);
        $prior = '';
        foreach ($children as $k => $child) {
            $value = ($child['type'] ?? '') === 'text' && is_string($child['value'] ?? null) ? $child['value'] : null;
            if ($value !== null && (str_contains($value, '^[') || (str_ends_with($prior, '^') && str_starts_with($value, '[')))) {
                // The first such text has the most siblings behind it.
                return $k < self::lastCaretPair($children);
            }
            $prior = $value ?? '';
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     */
    private static function holdsCaretPair(array $node): bool
    {
        $type = $node['type'] ?? '';
        $first = self::childrenOf($node)[0] ?? null;
        if (
            ($type === 'link' || $type === 'span') && is_array($first) && ($first['type'] ?? '') === 'text'
            && str_starts_with(is_string($first['value'] ?? null) ? $first['value'] : '', '^')
        ) {
            return true;
        }
        if ($type === 'inline_footnote') {
            return false;
        }
        foreach (self::childrenOf($node) as $child) {
            if (self::holdsCaretPair($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-W-088: each reported opener's kind escapes every potential opener of that kind in its leaf block.
     *
     * @param array<string, array<int, true>> $reported
     */
    private function escapeKinds(array $reported): void
    {
        foreach ($reported as $path => $offsets) {
            $value = self::nodeAt($this->tree, $path)['value'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $leaf = $this->leafOf($path);
            foreach ($offsets as $offset => $_) {
                $kind = self::kindAt($value, $offset);
                if ($kind === null || isset($this->kindsDone[$leaf][$kind])) {
                    continue;
                }
                $this->kindsDone[$leaf][$kind] = true;
                foreach ($this->leafTexts()[$leaf] ?? [] as $text) {
                    $other = self::nodeAt($this->tree, $text)['value'] ?? null;
                    if (is_string($other)) {
                        foreach (self::kindOffsets($kind, $other) as $at) {
                            $this->addEscape($text, $at);
                        }
                    }
                }
            }
        }
    }

    /**
     * The asks of `$firsts` at a brace that starts a braced opener.
     *
     * @param array<string, array<int, true>> $firsts
     *
     * @return array<string, array<int, true>>
     */
    private function bracedFirsts(array $firsts): array
    {
        $braced = [];
        foreach ($firsts as $path => $offsets) {
            $value = self::nodeAt($this->tree, $path)['value'] ?? null;
            foreach ($offsets as $offset => $_) {
                if (is_string($value) && strlen((string)self::kindAt($value, $offset)) === 2 && $value[$offset] === '{') {
                    $braced[$path][$offset] = true;
                }
            }
        }

        return $braced;
    }

    /**
     * C2-W-125, case five: a `[` escaped in the content of a bracketed node leaves the `]` that closes it in the text
     * bare, which would end that node early, so that `]` takes its backslash in the same round.
     */
    private function escapePartnerClosers(): void
    {
        $pending = [['', $this->tree]];
        while ($pending !== []) {
            [$path, $node] = array_pop($pending);
            if (!in_array($node['type'] ?? '', self::BRACKETED, true)) {
                foreach (self::childrenOf($node) as $index => $child) {
                    $pending[] = [$path . '/children/' . $index, $child];
                }

                continue;
            }
            $open = [];
            $content = [];
            foreach (array_reverse(self::childrenOf($node), true) as $index => $child) {
                $content[] = [$path . '/children/' . $index, $child];
            }
            while ($content !== []) {
                [$at, $child] = array_pop($content);
                $type = $child['type'] ?? '';
                if (in_array($type, self::BRACKETED, true)) {
                    $pending[] = [$at, $child];
                } elseif ($type === 'text') {
                    $this->pairBrackets($at, is_string($child['value'] ?? null) ? $child['value'] : '', $open);
                } else {
                    foreach (array_reverse(self::childrenOf($child), true) as $index => $inner) {
                        $content[] = [$at . '/children/' . $index, $inner];
                    }
                }
            }
        }
    }

    /**
     * @param string $path
     * @param string $value
     * @param array<array{string, int}> $open The `[` of the content not yet closed.
     */
    private function pairBrackets(string $path, string $value, array &$open): void
    {
        $n = strlen($value);
        for ($i = strcspn($value, '[]'); $i < $n; $i += 1 + strcspn($value, '[]', $i + 1)) {
            if ($value[$i] === '[') {
                $open[] = [$path, $i];

                continue;
            }
            $partner = array_pop($open);
            if ($partner !== null && isset($this->escapes[$partner[0]][$partner[1]])) {
                $this->addEscape($path, $i);
            }
        }
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<int, array<mixed>>
     */
    private static function childrenOf(array $node): array
    {
        return array_filter(is_array($node['children'] ?? null) ? $node['children'] : [], 'is_array');
    }

    /**
     * @var array<string, array<string>>|null The text paths of each leaf block, in source order.
     */
    private ?array $leafTexts = null;

    /**
     * @return array<string, array<string>>
     */
    private function leafTexts(): array
    {
        if ($this->leafTexts === null) {
            $this->leafTexts = [];
            foreach ($this->textOrder as $text => $_order) {
                $this->leafTexts[$this->leafOf($text)][] = $text;
            }
        }

        return $this->leafTexts;
    }

    private function leafOf(string $path): string
    {
        // The ancestors are found in one walk down the path: looking each up from the root costs a deep path its
        // depth squared.
        $types = self::typesAlong($this->tree, $path);
        while (($cut = strrpos($path, '/children/')) !== false) {
            $path = substr($path, 0, $cut);
            $type = array_key_exists($cut, $types) ? $types[$cut] : (self::nodeAt($this->tree, $path)['type'] ?? null);
            if (!in_array($type, self::INLINE_CONTAINERS, true)) {
                return $path;
            }
        }

        return $path;
    }

    /**
     * The `type` of the node at each prefix of `path` that ends before a `/`, by the prefix length, as `nodeAt()` reads
     * it; empty for a path that does not start with a single `/`.
     *
     * @param array<mixed> $tree
     * @param string $path
     *
     * @return array<int, mixed>
     */
    private static function typesAlong(array $tree, string $path): array
    {
        if (!str_starts_with($path, '/') || str_contains($path, '//')) {
            return [];
        }
        $types = [];
        $node = $tree;
        $length = 0;
        foreach (explode('/', substr($path, 1)) as $part) {
            $length += 1 + strlen($part);
            if ($node === null || !is_array($node[$part] ?? null)) {
                $node = null;
            } else {
                $node = $node[$part];
            }
            $types[$length] = $node === null ? null : ($node['type'] ?? null);
        }

        return $types;
    }

    private static function kindAt(string $value, int $offset): ?string
    {
        $ch = $value[$offset] ?? '';
        if ($ch === '' || !ctype_punct($ch)) {
            return null;
        }
        if ($ch === '{' && ctype_punct($value[$offset + 1] ?? 'a')) {
            return '{' . $value[$offset + 1];
        }

        return $ch === '-' ? '--' : $ch;
    }

    /**
     * C2-W-088: the potential openers of a kind in a text: the first character of each run of its characters, the
     * brace of a braced opener, the second hyphen of a hyphen run, and a `/` or `*` its neighbors let open.
     *
     * @return array<int>
     */
    private static function kindOffsets(string $kind, string $value): array
    {
        $offsets = [];
        $n = strlen($value);
        $ch = $kind[0];
        $first = $ch === '{' ? '{' : ($kind === '--' ? '-' : $ch);
        for ($i = strpos($value, $first); $i !== false && $i < $n; $i = strpos($value, $value[$i], $i + 1)) {
            // A negative string offset reads from the end, so the first character has no character before it.
            $prev = $i > 0 ? $value[$i - 1] : '';
            $open = match (true) {
                strlen($kind) === 2 && $ch === '{' => ($value[$i + 1] ?? '') === $kind[1] && $prev !== '{',
                $kind === '--' => $prev === '-' && ($i < 2 || $value[$i - 2] !== '-') && ($value[$i + 1] ?? '') !== '',
                $ch === '/' || $ch === '*' => $prev !== $ch && ($value[$i + 1] ?? '') !== $ch
                    && !ctype_alnum($prev) && !in_array($value[$i + 1] ?? ' ', [' ', "\t", "\n"], true),
                default => $prev !== $ch,
            };
            if ($open) {
                $offsets[] = $i;
            }
        }

        return $offsets;
    }

    private function addEscape(string $path, int $offset): bool
    {
        if (isset($this->escapes[$path][$offset])) {
            return false;
        }
        $this->escapes[$path][$offset] = true;

        return true;
    }

    /**
     * @param array<string, array<int, true>> $asked
     */
    private function addAsked(array $asked): bool
    {
        $added = false;
        foreach ($asked as $path => $offsets) {
            foreach ($offsets as $offset => $_) {
                $added = $this->addEscape($path, $offset) || $added;
            }
        }

        return $added;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<string, array{array{int, int}, int}> $across `acrossSpans()` of the candidate.
     * @param array<int, array{string, string, int, int, int}> $moved
     */
    private function probeMoved(array $candidate, array $across, array $moved): bool
    {
        $added = false;
        foreach (self::probedMoves([$moved]) as [$path, , , $end]) {
            foreach ($this->probeAcrossCloser($candidate, $across[$path], $path, $end) as [$owner, $offset]) {
                $added = $this->addEscape($owner, $offset) || $added;
            }
        }

        return $added;
    }

    /**
     * The moved nodes `probeMoved()` probes: past the first of each leaf, those the read-back ends later.
     *
     * @param array<array<int, array{string, string, int, int, int}>> $groups
     *
     * @return array<int, array{string, string, int, int, int}>
     */
    private static function probedMoves(array $groups): array
    {
        $probed = [];
        foreach ($groups as $group) {
            foreach ($group as $position => $entry) {
                if ($position > 0 && $entry[4] > $entry[3]) {
                    $probed[] = $entry;
                }
            }
        }

        return $probed;
    }

    /**
     * The moved nodes grouped by their leaf block, in order, so each leaf repairs its first moved node as its owner.
     *
     * @param array<int, array{string, string, int, int, int}> $moved
     *
     * @return array<array<int, array{string, string, int, int, int}>>
     */
    private function movedByLeaf(array $moved): array
    {
        $groups = [];
        foreach ($moved as $entry) {
            $groups[$this->leafOf($entry[0])][] = $entry;
        }

        return array_values($groups);
    }

    /**
     * @param array<int, array{string, string, int, int, int}> $moved
     */
    private function ownerMoved(array $moved): bool
    {
        [$path, , , $end, $readEnd] = $moved[0] ?? [null, '', 0, 0, 0];
        if ($path === null || $readEnd <= $end || !self::hasLaterOverrun($moved, $path)) {
            return false;
        }
        $parts = explode('/', $path);
        $index = (int)array_pop($parts);
        $nextPath = implode('/', $parts) . '/' . ($index + 1);
        $next = self::nodeAt($this->tree, $nextPath);
        $owner = self::nodeAt($this->tree, $path);
        if ($next === null || $owner === null) {
            return false;
        }
        $mark = self::firstMark($next, $nextPath, self::closer($owner), $this->escapes);

        return $mark !== null && $this->addEscape($mark[0], $mark[1]);
    }

    /**
     * @param array<int, array{string, string, int, int, int}> $moved
     * @param string $path
     */
    private static function hasLaterOverrun(array $moved, string $path): bool
    {
        $parent = substr($path, 0, (int)strrpos($path, '/'));
        foreach (array_slice($moved, 1) as [$otherPath, , , $otherEnd, $otherReadEnd]) {
            if ($otherReadEnd > $otherEnd && substr($otherPath, 0, (int)strrpos($otherPath, '/')) === $parent) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param array<array{string, string}> $missing
     *
     * @return array<array<array{string, int}>>
     */
    private function nextTrials(array $candidate, array $actual, array $missing): array
    {
        // C2-W-088, C2-W-125: the procedure runs per text in its leaf block, so each leaf takes the trial of its own
        // first missing node in the same round, and a leaf's escapes do not depend on the leaves around it.
        $trials = [];
        foreach ($missing as [$path, $prior]) {
            $leaf = $this->leafOf($path);
            if (isset($trials[$leaf])) {
                continue;
            }
            $step = $this->attempts[$path] ?? 0;
            $trial = $this->trial($candidate, $actual, $path, $prior, $step);
            $this->attempts[$path] = $step;
            if ($trial !== null) {
                $trials[$leaf] = $trial;
            }
        }

        return array_values($trials);
    }

    /**
     * The trials that change how their leaf reads. Several trials of one round are tested together: one source without
     * them and one with all of them, each leaf compared on its own, so a round costs two reads and not two per leaf.
     *
     * @param array<array<array{string, int}>> $trials
     *
     * @return array<array<array{string, int}>>
     */
    private function keptTrials(array $trials): array
    {
        $small = array_filter($trials, static fn (array $trial): bool => count($trial) <= 4);
        if (count($small) < 2) {
            // Only the fingerprint of the round's read-back is kept, so it is not held beside the trial reads.
            if ($small !== [] && $this->roundRead !== null) {
                [$escapes, $source, $read] = $this->roundRead;
                $this->roundPrint = [$escapes, $source, TreeEquality::fingerprint($read)];
                unset($read);
            }
            $this->roundRead = null;
            try {
                return array_values(array_filter($trials, fn (array $trial): bool => $this->keepTrial($trial)));
            } finally {
                $this->roundPrint = null;
            }
        }
        $first = [];
        foreach ($small as $k => $trial) {
            $first[$this->leafOf($trial[0][0])] = $k;
        }
        $left = [];
        foreach ($this->escapes as $owner => $offsets) {
            $k = $first[$this->leafOf($owner)] ?? null;
            foreach ($offsets as $at => $_) {
                if ($k === null || $this->before($owner, $at, $small[$k][0])) {
                    $left[$owner][$at] = true;
                }
            }
        }
        $with = $left;
        foreach ($small as $trial) {
            foreach ($trial as [$path, $offset]) {
                $with[$path][$offset] = true;
            }
        }
        if ($this->roundRead !== null && $this->roundRead[0] !== $left) {
            $this->roundRead = null;
        }
        [$count, $prints] = $this->leafPrints($left, $small);
        $read = $count < 0 ? null : $this->readTree($with, true);
        if ($read === null || $count !== count(self::childrenOf($read))) {
            return array_values(array_filter($trials, fn (array $trial): bool => $this->keepTrial($trial)));
        }
        $kept = [];
        foreach ($trials as $k => $trial) {
            $leaf = $this->leafOf($trial[0][0]);
            if (!isset($small[$k]) || $prints[$leaf] !== TreeEquality::fingerprint(self::nodeAt($read, $leaf))) {
                $kept[] = $trial;
            }
        }

        return $kept;
    }

    /**
     * The block count of the source these escapes write and the fingerprint of each trial's leaf in it; -1 past the
     * budget. Only the fingerprints are kept, so the read is not held beside the next one.
     *
     * @param array<string, array<int, true>> $escapes
     * @param array<array<array{string, int}>> $trials
     *
     * @return array{int, array<string, string>}
     */
    private function leafPrints(array $escapes, array $trials): array
    {
        $parts = $this->roundRead !== null && $this->roundRead[0] === $escapes ? $this->roundParts ?? [] : [];
        $read = $this->readTree($escapes);
        if ($read === null) {
            return [-1, []];
        }
        $prints = [];
        foreach ($trials as $trial) {
            $leaf = $this->leafOf($trial[0][0]);
            $prints[$leaf] = ($leaf !== '' ? $parts[$leaf] ?? null : null) ?? TreeEquality::fingerprint(self::nodeAt($read, $leaf));
        }

        return [count(self::childrenOf($read)), $prints];
    }

    /**
     * Whether the escape at `$at` of `$owner` stands before the escape `$trial` in the order of the texts.
     *
     * @param string $owner
     * @param int $at
     * @param array{string, int} $trial
     *
     * @return bool
     */
    private function before(string $owner, int $at, array $trial): bool
    {
        $order = $this->textOrder[$owner] ?? -1;
        $rank = $this->textOrder[$trial[0]] ?? -1;

        return $order < $rank || ($owner === $trial[0] && $at < $trial[1]);
    }

    /**
     * The tree the source written with these escapes reads back as, or null past the budget.
     *
     * @param array<string, array<int, true>> $escapes
     * @param bool $keep Keep the read for the next round, which takes it where it writes the same source.
     *
     * @return array<mixed>|null
     */
    private function readTree(array $escapes, bool $keep = false): ?array
    {
        // The source of the round's own escapes is the round's candidate, read already; it is charged all the same.
        if ($this->roundRead !== null && $this->roundRead[0] === $escapes) {
            [, $source, $read] = $this->roundRead;
            $this->roundRead = null;

            return $this->charge($source) ? $read : null;
        }
        $source = Writer::bareSource($this->tree, $this->layout, $this->options, $escapes);
        if (!$this->charge($source)) {
            return null;
        }
        $read = ReadbackRound::read($source, $this->options);
        if ($keep) {
            $this->nextRead = [$source, $read];
        }

        return $read;
    }

    /**
     * @param array<array{string, int}> $trial
     */
    private function keepTrial(array $trial): bool
    {
        if (count($trial) > 4) {
            return true;
        }
        foreach ($trial as [$path, $offset]) {
            if ($this->trialChangesTree($path, $offset)) {
                return true;
            }
        }

        return false;
    }

    private function charge(string $source): bool
    {
        if ($this->parent !== null) {
            return $this->parent->charge($source);
        }
        $this->read += strlen($source);

        return $this->read <= $this->limit;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges?: array<string, array{int, int}>
     * } $candidate
     * @param array<mixed> $actual
     *
     * @return array<array{string, string}>
     */
    private static function missingSiblings(array $candidate, array $actual): array
    {
        // Two flat maps, not an array per node: these are held beside the candidate and the read-back.
        $ends = [];
        $parents = [];
        self::actualSpans($actual, null, $ends, $parents);
        $byPath = [];
        foreach ($candidate['nodes'] as $id => [$packed]) {
            $byPath[$packed] = $id;
        }
        $nodes = $candidate['nodes'];
        $missing = [];
        foreach ($byPath as $packed => $id) {
            [, $type, $start] = $nodes[$id];
            [$index, $parent, $prior] = self::packedNeighbors((string)$packed);
            $priorId = $index > 0 ? $byPath[$prior] ?? null : null;
            if ($priorId !== null) {
                [, $priorType, $priorStart, $priorEnd] = $nodes[$priorId];
                if (($ends[$priorType . ':' . $priorStart] ?? $priorEnd) > $start) {
                    $missing[] = [RepairTrace::unpackPath((string)$packed), RepairTrace::unpackPath($prior)];

                    continue;
                }
            }
            if (self::heldAsText($candidate['ranges'] ?? [], $ends, [$type, $start, $prior], $priorId !== null)) {
                $missing[] = [RepairTrace::unpackPath((string)$packed), RepairTrace::unpackPath($prior)];

                continue;
            }
            $key = $type . ':' . $start;
            $parentId = isset($ends[$key]) ? $byPath[$parent] ?? null : null;
            if ($parentId === null) {
                continue;
            }
            [, $parentType, $parentStart] = $nodes[$parentId];
            if ($parents[$key] !== $parentType . ':' . $parentStart) {
                $missing[] = [RepairTrace::unpackPath((string)$packed), RepairTrace::unpackPath($prior)];
            }
        }

        return $missing;
    }

    /**
     * C2-W-091: the node did not form, and the parse holds its markup as text: the text before it reads on into it, or
     * behind a node that formed a text starts where the node would.
     *
     * @param array<string, array{int, int}> $ranges The text ranges of the candidate, by path.
     * @param array<string, int> $ends
     * @param array{string, int, string} $node Its type, its start and the packed path of the sibling before it.
     * @param bool $afterNode Whether the sibling before it is a node of the candidate.
     */
    private static function heldAsText(array $ranges, array $ends, array $node, bool $afterNode): bool
    {
        [$type, $start, $prior] = $node;
        if (isset($ends[$type . ':' . $start])) {
            return false;
        }
        if ($afterNode) {
            return isset($ends['text:' . $start]);
        }
        $priorText = $ranges[RepairTrace::unpackPath($prior)] ?? null;

        return $priorText !== null && ($ends['text:' . $priorText[0]] ?? $priorText[1]) > $start;
    }

    /**
     * The last index of a packed path, and the packed paths before that index (its parent) and of the sibling before.
     *
     * @return array{int, string, string}
     */
    private static function packedNeighbors(string $packed): array
    {
        // `<form>\x01<digits>` is `<path>/children/<digits>`; the parent and prior keep `<form>` as it is.
        $cut = $packed === '' || $packed[0] === "\x02" ? false : strrpos($packed, "\x01");
        if ($cut !== false) {
            $digits = substr($packed, $cut + 1);
            if ($digits !== '' && strspn($digits, '0123456789') === strlen($digits)) {
                $index = (int)$digits;

                return [$index, substr($packed, 0, $cut) . '/children', substr($packed, 0, $cut + 1) . ($index - 1)];
            }
        }
        $path = RepairTrace::unpackPath($packed);
        $cut = strrpos($path, '/');
        $index = (int)($cut === false ? $path : substr($path, $cut + 1));
        $parent = $cut === false ? '' : substr($path, 0, $cut);

        return [$index, RepairTrace::packPath($parent), RepairTrace::packPath($parent . '/' . ($index - 1))];
    }

    /**
     * @param array<mixed> $node
     * @param string|null $parent The `type:start` key of the nearest positioned ancestor.
     * @param array<string, int> $ends The end of each positioned node, by its `type:start` key.
     * @param array<string, string|null> $parents
     */
    private static function actualSpans(array $node, ?string $parent, array &$ends, array &$parents): void
    {
        $pos = TreeEncoder::offsets($node['pos'] ?? null);
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        if ($pos !== null) {
            $key = $type . ':' . $pos[0];
            $ends[$key] = $pos[1];
            $parents[$key] = $parent;
            $parent = $key;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                self::actualSpans($child, $parent, $ends, $parents);
            }
        }
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param string $prior
     * @param string $path
     * @param int $step
     *
     * @return array<array{string, int}>|null
     */
    private function trial(array $candidate, array $actual, string $path, string $prior, int &$step): ?array
    {
        $node = self::nodeAt($this->tree, $path);
        if ($node === null) {
            return null;
        }
        $parts = explode('/', $path);
        $index = (int)array_pop($parts);
        $parent = implode('/', $parts);
        while ($step < 14) {
            $case = $step++;
            if ($case === 3 || $case === 4) {
                $probed = $case === 3
                    ? $this->contentConstruct($path, $node)
                    : self::acrossCloser($candidate, $actual, $path, $this->escapes);
                if ($probed !== []) {
                    return $probed;
                }

                continue;
            }
            $chosen = match ($case) {
                0, 11 => self::endEscape(self::lastText(self::nodeAt($this->tree, $prior), $prior), $this->escapes),
                5, 12 => self::endEscape(self::lastText($node, $path), $this->escapes),
                7 => self::after($this->tree, $parent . '/' . ($index + 1), $this->escapes),
                13 => self::firstMark($node, $path, self::closer($node), $this->escapes),
                default => null,
            };
            if ($chosen !== null) {
                return [$chosen];
            }
        }

        return null;
    }

    /**
     * C2-W-088: the content of the node at `path`, written and parsed on its own, holds a construct that the tree does
     * not, as a closer of an inner node that reads early. The escapes its own rounds ask for are this trial. A content
     * that opens a block is read again behind a word, then with its line breaks as spaces (carve-js
     * `escapeContentConstruct`).
     *
     * @param string $path
     * @param array<mixed> $node
     *
     * @return array<array{string, int}>
     */
    private function contentConstruct(string $path, array $node): array
    {
        $children = is_array($node['children'] ?? null) ? array_values($node['children']) : [];
        if ($children === [] || !in_array($node['type'] ?? '', self::INLINE_CONTAINERS, true)) {
            return [];
        }
        $word = ['type' => 'text', 'value' => 'x'];
        $flat = [];
        $kept = [];
        foreach ($children as $index => $child) {
            $break = is_array($child) && ($child['type'] ?? '') === 'soft_break';
            $flat[] = $break ? ['type' => 'text', 'value' => ' '] : $child;
            if (!$break) {
                $kept[$index] = true;
            }
        }
        $all = array_fill_keys(array_keys($children), true);
        $first = is_array($children[0]) ? ($children[0]['type'] ?? '') : '';
        $variants = [[[$word, ...$children], 1, $all], [[$word, ...$flat], 1, $kept]];
        if ($first !== 'soft_break' && $first !== 'hard_break') {
            array_unshift($variants, [$children, 0, $all]);
        }
        foreach ($variants as [$inlines, $shift, $mapped]) {
            $found = $this->probeContent($path, $inlines, $shift, $mapped);
            if ($found !== null) {
                return $found;
            }
        }

        return [];
    }

    /**
     * One variant of a content probe: null where its first parse is no single paragraph, which tells nothing.
     *
     * @param string $path
     * @param array<mixed> $inlines
     * @param int $shift
     * @param array<int, true> $mapped The children of `path` that stand in the probe as themselves.
     *
     * @return array<array{string, int}>|null
     */
    private function probeContent(string $path, array $inlines, int $shift, array $mapped): ?array
    {
        $prefix = $path . '/children/';
        $inside = '/children/0/children/';
        $probe = new self(['type' => 'document', 'children' => [['type' => 'paragraph', 'children' => $inlines]]], null, $this->options);
        $probe->parent = $this;
        $probe->kindRounds = false;
        $type = self::nodeAt($this->tree, $path)['type'] ?? null;
        $probe->probed = is_string($type) ? $type : null;
        $probe->escapes = self::moveEscapes($this->escapes, $prefix, $inside, $shift, $mapped);
        if (!$probe->probeRounds()) {
            return null;
        }
        $found = [];
        foreach (self::moveEscapes($probe->escapes, $inside, $prefix, -$shift, $mapped, true) as $original => $offsets) {
            foreach ($offsets as $at => $_) {
                if (!isset($this->escapes[$original][$at])) {
                    $found[] = [$original, $at];
                }
            }
        }

        return $found;
    }

    /**
     * The rounds of a content probe; false where its first parse is no single paragraph.
     */
    private function probeRounds(): bool
    {
        for ($round = 0; $round < self::MAX_PROBE_ROUNDS; $round++) {
            $escapes = $this->escapes;
            $source = Writer::bareSource($this->tree, null, $this->options, $escapes);
            if (!$this->charge($source)) {
                return true;
            }
            $compared = $this->compared($source, $escapes, null);
            if ($round === 0 && !$this->singleParagraph) {
                return false;
            }
            if ($compared === null) {
                return true;
            }
            [$candidate, $comparison] = $compared;
            unset($compared);
            if (!$this->appliedRound($escapes, $candidate, $comparison)) {
                return true;
            }
        }

        return true;
    }

    /**
     * The escapes below `from` moved below `to`, each child index shifted by `shift`. Only the children in `mapped`
     * (by their index below the content of the probed node) move.
     *
     * @param array<string, array<int, true>> $escapes
     * @param string $from
     * @param string $to
     * @param int $shift
     * @param array<int, true> $mapped
     * @param bool $back
     *
     * @return array<string, array<int, true>>
     */
    private static function moveEscapes(
        array $escapes,
        string $from,
        string $to,
        int $shift,
        array $mapped,
        bool $back = false,
    ): array {
        $moved = [];
        foreach ($escapes as $owner => $offsets) {
            if (!str_starts_with($owner, $from)) {
                continue;
            }
            $rest = substr($owner, strlen($from));
            $cut = strpos($rest, '/');
            $index = (int)($cut === false ? $rest : substr($rest, 0, $cut));
            if (isset($mapped[$back ? $index + $shift : $index])) {
                $moved[$to . ($index + $shift) . ($cut === false ? '' : substr($rest, $cut))] = $offsets;
            }
        }

        return $moved;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     */
    private static function leafValue(array $tree, string $path): ?string
    {
        $node = self::nodeAt($tree, $path);

        return is_string($node['value'] ?? null) ? $node['value'] : null;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     *
     * @return array<mixed>|null
     */
    private static function nodeAt(array $tree, string $path): ?array
    {
        $node = $tree;
        foreach (explode('/', trim($path, '/')) as $part) {
            if (!is_array($node[$part] ?? null)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * @param array<mixed>|null $node
     * @param string $path
     *
     * @return array{string, string}|null
     */
    private static function lastText(?array $node, string $path): ?array
    {
        if ($node === null) {
            return null;
        }
        if (($node['type'] ?? '') === 'text') {
            return [$path, is_string($node['value'] ?? null) ? $node['value'] : ''];
        }
        foreach (array_reverse(is_array($node['children'] ?? null) ? $node['children'] : [], true) as $index => $child) {
            if (is_array($child)) {
                $last = self::lastText($child, $path . '/children/' . $index);
                if ($last !== null) {
                    return $last;
                }
            }
        }

        return null;
    }

    /**
     * @param array{string, string}|null $last
     * @param array<string, array<int, true>> $escapes
     *
     * @return array{string, int}|null
     */
    private static function endEscape(?array $last, array $escapes): ?array
    {
        if ($last === null) {
            return null;
        }
        [$path, $value] = $last;
        $length = strlen($value);
        if ($length > 0 && str_contains('\\!^$`', $value[$length - 1]) && !isset($escapes[$path][$length - 1])) {
            return [$path, $length - 1];
        }
        $brace = strrpos($value, '{');

        return $brace !== false && !isset($escapes[$path][$brace]) ? [$path, $brace] : null;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array{string, int}|null
     */
    private static function after(array $tree, string $path, array $escapes): ?array
    {
        $node = self::nodeAt($tree, $path);
        $value = $node['value'] ?? null;

        return ($node['type'] ?? '') === 'text' && is_string($value) && $value !== ''
            && Text::isPunct($value[0]) && !isset($escapes[$path][0]) ? [$path, 0] : null;
    }

    /**
     * @param array<mixed> $node
     */
    private static function closer(array $node): string
    {
        return match ($node['type'] ?? '') {
            'superscript' => '^', 'subscript' => ',', 'emphasis' => '/', 'strong' => '*',
            'underline' => '_', 'strike' => '~', 'highlight' => '=', 'insert' => '+', 'delete' => '-',
            'link', 'span', 'inline_footnote', 'inline_extension' => ']',
            default => '',
        };
    }

    /**
     * @param array<mixed> $node
     * @param string $mark
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array{string, int}|null
     */
    private static function firstMark(array $node, string $path, string $mark, array $escapes): ?array
    {
        if ($mark === '') {
            return null;
        }
        if (($node['type'] ?? '') === 'text') {
            $value = is_string($node['value'] ?? null) ? $node['value'] : '';
            for ($at = 0, $length = strlen($value); $at < $length; $at++) {
                if ($value[$at] === $mark && !isset($escapes[$path][$at])) {
                    return [$path, $at];
                }
            }

            return null;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $found = self::firstMark($child, $path . '/children/' . $index, $mark, $escapes);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array<array{string, int}>
     */
    private static function acrossCloser(array $candidate, array $actual, string $path, array $escapes): array
    {
        $span = null;
        $packed = RepairTrace::packPath($path);
        foreach ($candidate['nodes'] as [$expectedPath, , $start, $end]) {
            if ($expectedPath === $packed) {
                $span = [$start, $end];

                break;
            }
        }
        if ($span === null) {
            return [];
        }
        $ends = [];
        $parents = [];
        self::actualSpans($actual, null, $ends, $parents);
        unset($parents);
        $endsByStart = [];
        foreach ($ends as $key => $readEnd) {
            if (str_starts_with($key, 'text:')) {
                continue;
            }
            $separator = strrpos($key, ':');
            if ($separator !== false) {
                $start = (int)substr($key, $separator + 1);
                $endsByStart[$start] = max($endsByStart[$start] ?? 0, $readEnd);
            }
        }
        $found = [];
        foreach ($candidate['text'] as $position => $code) {
            [$owner, $offset] = [$candidate['paths'][$code >> 32], $code & 0xFFFFFFFF];
            if (
                !str_starts_with($owner, $path . '/') || $position < $span[0] || $position >= $span[1]
                || isset($escapes[$owner][$offset])
            ) {
                continue;
            }
            $mark = $candidate['source'][$position] ?? '';
            if (!Text::isPunct($mark)) {
                continue;
            }
            if (($endsByStart[$position] ?? 0) > $span[1]) {
                $found[] = [$owner, $offset];
            }
        }

        return $found;
    }

    /**
     * A construct inside a paired node may read across its closer only when the content and
     * following inlines are parsed together. Probe that suffix independently so an outer parse
     * does not hide its nested openers. As in C2-W-125, a probe takes at most 32 rounds.
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array{array{int, int}, int} $across The node's span and the end of its probe, `acrossSpans()`.
     * @param int $end
     * @param string $path
     *
     * @return array<array{string, int}>
     */
    private function probeAcrossCloser(array $candidate, array $across, string $path, int $end): array
    {
        $node = self::nodeAt($this->tree, $path);
        if ($node === null || self::closer($node) === '') {
            return [];
        }
        [$probe, $end] = $this->probeCandidate($candidate, $path, $end);
        if ($probe !== $candidate) {
            $candidate = $probe;
            $across = self::acrossSpans($candidate, [[$path]])[$path];
        }
        [$span, $acrossEnd] = $across;
        $first = null;
        for ($position = $span[0], $stop = $span[1]; $position < $stop; $position++) {
            if (str_starts_with(RepairTrace::owner($candidate, $position)[0] ?? '', $path . '/')) {
                $first = $position;

                break;
            }
        }
        if ($first === null) {
            return [];
        }
        $source = 'x ' . substr($candidate['source'], $first, $acrossEnd - $first);
        if (!$this->charge($source)) {
            return [];
        }
        $read = TreeEncoder::decodedWithOffsets(Carve::parse($source, $this->parseOptions()), $source);

        return $this->probeOpeners($candidate, $read, $path, $end, $first, self::closer($node));
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $read
     * @param string $path
     * @param int $end
     * @param int $first
     * @param string $closer
     *
     * @return array<array{string, int}>
     */
    private function probeOpeners(array $candidate, array $read, string $path, int $end, int $first, string $closer): array
    {
        $found = [];
        $pending = [$read];
        while ($pending !== []) {
            $item = array_pop($pending);
            if (!is_array($item)) {
                continue;
            }
            foreach (is_array($item['children'] ?? null) ? $item['children'] : [] as $child) {
                $pending[] = $child;
            }
            $opener = $this->probeOpener($candidate, $item, $path, $end, $first, $closer);
            if ($opener !== null) {
                [$start, $owned] = $opener;
                $found[$start] = $owned;
            }
        }
        ksort($found);

        return array_slice(array_values($found), 0, 32);
    }

    // The inlines behind a node that a probe across its closer reads, at most (as MAX_ACROSS in carve-js).
    /**
     * @var int
     */
    private const MAX_ACROSS = 16;

    /**
     * Each probed node's span in the candidate and the end of a probe across its closer: the node, its closer and at
     * most MAX_ACROSS inlines behind it. By path.
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges?: array<string, array{int, int}>
     * } $candidate
     * @param array<int, array{0: string}> $moved
     *
     * @return array<string, array{array{int, int}, int}>
     */
    private static function acrossSpans(array $candidate, array $moved): array
    {
        $packed = [];
        $siblings = [];
        foreach ($moved as [$path]) {
            $cut = (int)strrpos($path, '/');
            $parent = substr($path, 0, $cut) . '/';
            // Digits cannot be part of a `/children/`, so a packed path takes them as they are.
            $form = RepairTrace::packPath($parent);
            $packed[$path] = RepairTrace::packPath($path);
            $siblings[$path] = [];
            for ($index = (int)substr($path, $cut + 1), $k = $index + self::MAX_ACROSS; $k >= $index; $k--) {
                $packed[$parent . $k] = $siblings[$path][] = $form . $k;
            }
        }
        $spans = [];
        foreach ($candidate['ranges'] ?? [] as $rangePath => $range) {
            if (isset($packed[$rangePath])) {
                $spans[$packed[$rangePath]] = $range;
            }
        }
        $wanted = array_flip($packed);
        foreach ($candidate['nodes'] as [$nodePath, , $start, $end]) {
            if (isset($wanted[$nodePath])) {
                $spans[$nodePath] = [$start, $end];
            }
        }
        $across = [];
        foreach ($siblings as $path => $forms) {
            $end = strlen($candidate['source']);
            foreach ($forms as $form) {
                if (isset($spans[$form])) {
                    $end = $spans[$form][1];

                    break;
                }
            }
            $across[$path] = [$spans[$packed[$path]] ?? [0, -1], $end];
        }

        return $across;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param int $end
     * @param string $path
     *
     * @return array{
     *     array{
     *         source: string,
     *         text: array<int, int>,
     *         paths: array<int, string>,
     *         nodes: array<int, array{string, string, int, int}>
     *     },
     *     int
     * }
     */
    private function probeCandidate(array $candidate, string $path, int $end): array
    {
        $probeEscapes = $this->escapes;
        $changed = false;
        foreach ($this->trialEscapes as $owner => $offsets) {
            if (!str_starts_with($owner, $path . '/')) {
                continue;
            }
            foreach ($offsets as $at => $_) {
                unset($probeEscapes[$owner][$at]);
                $changed = true;
            }
        }
        if ($changed) {
            $candidate = Writer::bareCandidate($this->tree, $this->layout, $this->options, $probeEscapes);
            $packed = RepairTrace::packPath($path);
            foreach ($candidate['nodes'] as [$nodePath, , , $nodeEnd]) {
                if ($nodePath === $packed) {
                    $end = $nodeEnd;

                    break;
                }
            }
        }

        return [$candidate, $end];
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $item
     * @param string $closer
     * @param int $first
     * @param int $end
     * @param string $path
     *
     * @return array{int, array{string, int}}|null
     */
    private function probeOpener(array $candidate, array $item, string $path, int $end, int $first, string $closer): ?array
    {
        $pos = TreeEncoder::offsets($item['pos'] ?? null);
        if ($pos === null) {
            return null;
        }
        [$startOffset, $endOffset] = $pos;
        $start = $startOffset + $first - 2;
        $finish = $endOffset + $first - 2;
        $owned = RepairTrace::owner($candidate, $start);
        if (
            $owned === null || !str_starts_with($owned[0], $path . '/') || $start >= $end || $finish <= $end
            || !str_contains('[`<{$:', $candidate['source'][$start])
            || $candidate['source'][$start] === $closer || isset($this->escapes[$owned[0]][$owned[1]])
        ) {
            return null;
        }

        return [$start, $owned];
    }

    private function trialChangesTree(string $path, int $offset): bool
    {
        $rank = $this->textOrder[$path] ?? -1;
        $left = [];
        foreach ($this->escapes as $owner => $offsets) {
            $order = $this->textOrder[$owner] ?? -1;
            foreach ($offsets as $at => $_) {
                if ($order < $rank || ($owner === $path && $at < $offset)) {
                    $left[$owner][$at] = true;
                }
            }
        }
        $without = $this->sourceTree($left);
        if ($without === null) {
            return false;
        }
        $left[$path][$offset] = true;
        $with = $this->sourceTree($left);

        return $with !== null && $without !== $with;
    }

    /**
     * The fingerprint of the tree the source written with these escapes reads back as.
     *
     * @param array<string, array<int, true>> $escapes
     *
     * @return string|null
     */
    private function sourceTree(array $escapes): ?string
    {
        // The source of the round's own escapes is the round's candidate, read already; it is charged all the same.
        if ($this->roundPrint !== null && $this->roundPrint[0] === $escapes) {
            return $this->charge($this->roundPrint[1]) ? $this->roundPrint[2] : null;
        }
        $source = Writer::bareCandidate($this->tree, $this->layout, $this->options, $escapes, false)['source'];
        if (!$this->charge($source)) {
            return null;
        }

        return TreeEquality::fingerprint(TreeEncoder::decodedForWriter(Carve::parse($source, $this->parseOptions())));
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOptions(): array
    {
        return isset($this->options['extensions']) ? ['extensions' => $this->options['extensions']] : [];
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param array<string, int> $order
     */
    private static function textOrder(array $node, string $path, array &$order): void
    {
        if (($node['type'] ?? '') === 'text') {
            $order[$path] = count($order);
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                self::textOrder($child, $path . '/children/' . $index, $order);
            }
        }
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed>|null $actual
     * @param string $path
     * @param array<string, array<int, true>> $differences
     */
    private static function textDifferences(array $expected, ?array $actual, string $path, array &$differences): void
    {
        if (($expected['type'] ?? '') === 'text') {
            $at = self::firstTextDifference($expected, $actual);
            if ($at !== null) {
                $differences[$path][$at] = true;
            }

            return;
        }
        if ($actual === null || ($expected['type'] ?? '') !== ($actual['type'] ?? '')) {
            return;
        }
        $actualChildren = is_array($actual['children'] ?? null) ? $actual['children'] : [];
        foreach (is_array($expected['children'] ?? null) ? $expected['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $other = $actualChildren[$index] ?? null;
                self::textDifferences($child, is_array($other) ? $other : null, $path . '/children/' . $index, $differences);
            }
        }
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed>|null $actual
     */
    private static function firstTextDifference(array $expected, ?array $actual): ?int
    {
        $value = is_string($expected['value'] ?? null) ? $expected['value'] : '';
        $other = ($actual['type'] ?? '') === 'text' && is_string($actual['value'] ?? null) ? $actual['value'] : '';
        $at = 0;
        $end = min(strlen($value), strlen($other));
        while ($at < $end && $value[$at] === $other[$at]) {
            $at++;
        }

        return $at < strlen($value) && Text::isPunct($value[$at]) ? $at : null;
    }
}
