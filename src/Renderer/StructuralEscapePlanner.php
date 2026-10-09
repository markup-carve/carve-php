<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use Closure;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Subscript;
use MarkupCarve\Carve\Node\Inline\Superscript;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;

/**
 * Plans escapes for structural sigils and paired brackets.
 *
 * @internal
 */
final class StructuralEscapePlanner
{
    /**
     * @param \MarkupCarve\Carve\Renderer\CarveWriterState $state
     * @param (\Closure(array<\MarkupCarve\Carve\Node\Node>, string, array<int, array{int, int, int, string, int}>): (bool))|null $collectBracketMarksCallback
     * @param (\Closure(array<\MarkupCarve\Carve\Node\Node>, bool): (void))|null $planInlineRunCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Node): (void))|null $planStructuralEscapesCallback
     */
    public function __construct(
        private CarveWriterState $state,
        private ?Closure $collectBracketMarksCallback = null,
        private ?Closure $planInlineRunCallback = null,
        private ?Closure $planStructuralEscapesCallback = null,
    ) {
    }

    /**
     * Mark the lone brackets and destination-opening parens of every inline
     * run, once per document (PART 11 §5, markup-carve/carve#2357).
     */
    public function planStructuralEscapes(Node $node): void
    {
        $run = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof InlineNode) {
                $run[] = $child;

                continue;
            }
            $this->callPlanInlineRun($run, false);
            $run = [];
            $this->callPlanStructuralEscapes($child);
        }
        $this->callPlanInlineRun($run, false);
    }

    /**
     * Pair the bare brackets of one run's text, then mark what §5 escapes.
     *
     * Only inside content a construct wraps in its own brackets is an unpaired
     * bracket lone; the paren rule applies to every run.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param bool $bracketed
     */
    public function planInlineRun(array $nodes, bool $bracketed): void
    {
        if ($nodes === []) {
            return;
        }
        $flat = '';
        $marks = [];
        if (!$this->callCollectBracketMarks($nodes, $flat, $marks) || $marks === []) {
            return;
        }
        $open = [];
        $paired = [];
        $closers = [];
        foreach ($marks as $i => [$at, , , $char]) {
            if ($char === '[') {
                if (!isset($this->state->structuralEscapes[$marks[$i][1]][$marks[$i][2]])) {
                    $open[] = $i;
                }
            } elseif ($char === ']' && $open !== []) {
                $opener = array_pop($open);
                $paired[$opener] = true;
                $paired[$i] = true;
                if (!isset($this->state->fixedBracketSites[$marks[$i][1]][$marks[$i][2]])) {
                    $closers[$at] = $opener;
                }
                // Brackets across formatting boundaries must not isolate a delimiter.
                if ($marks[$opener][4] !== $marks[$i][4] && !isset($this->state->fixedBracketSites[$marks[$opener][1]][$marks[$opener][2]])) {
                    $this->state->structuralEscapes[$marks[$opener][1]][$marks[$opener][2]] = true;
                }
                if ($bracketed) {
                    $this->state->pairedClosers[$marks[$i][1]][$marks[$i][2]] = [$marks[$opener][1], $marks[$opener][2]];
                }
            }
        }
        $scan = null;
        foreach ($marks as $i => [$at, $id, $offset, $char]) {
            if ($char === '(') {
                if (
                    !isset($closers[$at - 1])
                    || isset($this->state->structuralEscapes[$marks[$closers[$at - 1]][1]][$marks[$closers[$at - 1]][2]])
                ) {
                    continue;
                }
                $scan ??= self::destinationScan($flat);
                if (!self::opensADestination($scan, $flat, $at)) {
                    continue;
                }
            } elseif (!$bracketed || isset($paired[$i])) {
                continue;
            }
            if (!isset($this->state->fixedBracketSites[$id][$offset])) {
                $this->state->structuralEscapes[$id][$offset] = true;
            }
        }
    }

    /**
     * Find a reference-shaped run in literal text before escaping its markup.
     */
    public static function literalReferenceOpener(string $text): ?int
    {
        $open = [];
        $reference = null;
        $length = strlen($text);
        for ($offset = 0; $offset < $length; $offset++) {
            $char = $text[$offset];
            if ($char === ']' && $reference !== null) {
                return $reference;
            }
            if ($char === "\n" || $char === "\r") {
                $reference = null;
            } elseif ($char === '[') {
                $open[] = $offset;
            } elseif ($char === ']' && $open !== []) {
                $opener = array_pop($open);
                if (($text[$offset + 1] ?? '') === '[') {
                    $reference = $opener;
                }
            }
        }

        return null;
    }

    /**
     * The run as the minimal form writes it, closely enough to pair its
     * brackets and read a destination, and where its brackets and parens sit.
     *
     * A construct writing its own brackets is planned as a run of its own, one
     * that writes none lends its text to this run, and verbatim content and
     * every other node take no part. Each stands in as a space, which ends a
     * destination, so the approximation can miss an escape but never invent
     * one. An inline extension's reader stops at the first `]` without
     * pairing, so its content gets the paren rule only.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param string $flat
     * @param array<int, array{int, int, int, string, int}> $marks
     *
     * @return bool False where the scan stopped early.
     */
    public function collectBracketMarks(array $nodes, string &$flat, array &$marks): bool
    {
        if ($nodes === []) {
            return true;
        }
        $literalOpeners = [];
        $literalHosts = [];

        return $this->collectBracketMarksWithScope($nodes, $flat, $marks, $literalOpeners, $literalHosts);
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param string $flat
     * @param array<int, array{int, int, int, string, int}> $marks
     * @param list<int> $literalOpeners
     * @param array<int, array<int, bool>> $literalHosts
     *
     * @return bool
     */
    public function collectBracketMarksWithScope(array $nodes, string &$flat, array &$marks, array &$literalOpeners, array &$literalHosts): bool
    {
        $complete = true;
        foreach ($nodes as $node) {
            // An empty code span is written as a bare backtick run, which can
            // swallow what follows it, so the scan ends there.
            if ($node instanceof Code && $node->getContent() === '') {
                return false;
            }
            $rawReference = ($node instanceof Mention || ($node instanceof Link && $node->isAutolink())) ? null : UnresolvedReference::sourceOf($node);
            if ($rawReference === null && !$node instanceof Mention && ($node instanceof Image || ($node instanceof Link && !$node->isAutolink()))) {
                $referenceLabel = $node->getReferenceLabel();
                if (($referenceLabel !== null && $referenceLabel !== '') || ($node instanceof Link && $node->isFromHeadingReference())) {
                    $rawReference = $node->getRawReferenceLabel();
                }
            }
            if ($node instanceof Text || $rawReference !== null) {
                $content = $rawReference ?? str_replace("\r", '', $node->getContent());
                if (strpbrk($content, '[](') !== false) {
                    $id = spl_object_id($node);
                    $host = spl_object_id($node->getParent() ?? $node);
                    $reference = $rawReference === null ? self::literalReferenceOpener($content) : null;
                    $structural = $rawReference !== null ? BracketScanner::structuralBracketOffsets($content) : null;
                    if ($rawReference !== null && $structural === null) {
                        $complete = false;
                        $flat .= ' ';

                        continue;
                    }
                    preg_match_all('/[\[\](]/', $content, $found, PREG_OFFSET_CAPTURE);
                    foreach ($found[0] as [$char, $offset]) {
                        if ($structural !== null) {
                            if (!isset($structural[$offset])) {
                                continue;
                            }
                            $this->state->fixedBracketSites[$id][$offset] = true;
                        }
                        if ($offset === $reference) {
                            $sameHost = $literalHosts[$host] ?? [];
                            unset($literalHosts[$host]);
                            $crossing = $literalHosts !== [];
                            foreach ($literalHosts as $openers) {
                                foreach ($openers as $index => $_) {
                                    [, $markId, $markOffset] = $marks[$index];
                                    if (!isset($this->state->fixedBracketSites[$markId][$markOffset])) {
                                        $this->state->structuralEscapes[$markId][$markOffset] = true;
                                    }
                                }
                            }
                            $literalHosts = $sameHost === [] ? [] : [$host => $sameHost];
                            unset($sameHost);
                            if ($crossing) {
                                $this->state->structuralEscapes[$id][$offset] = true;
                            }
                        }
                        $index = count($marks);
                        $marks[] = [strlen($flat) + $offset, $id, $offset, $char, $host];
                        if ($char === '[' && !isset($this->state->structuralEscapes[$id][$offset])) {
                            $literalOpeners[] = $index;
                            $literalHosts[$host][$index] = true;
                        } elseif ($char === ']') {
                            while ($literalOpeners !== []) {
                                $opener = array_pop($literalOpeners);
                                [, $markId, $markOffset, , $markHost] = $marks[$opener];
                                if (isset($this->state->structuralEscapes[$markId][$markOffset])) {
                                    continue;
                                }
                                unset($literalHosts[$markHost][$opener]);
                                if (($literalHosts[$markHost] ?? []) === []) {
                                    unset($literalHosts[$markHost]);
                                }

                                break;
                            }
                        }
                    }
                }
                $flat .= $content;
            } elseif (
                $node instanceof Span
                || ($node instanceof Link && !$node->isAutolink())
                || $node instanceof InlineFootnote
            ) {
                $this->callPlanInlineRun($node->getChildren(), true);
                $flat .= ' ';
            } elseif ($node instanceof InlineExtension) {
                $this->callPlanInlineRun($node->getChildren(), false);
                $flat .= ' ';
            } elseif (
                $node instanceof Emphasis || $node instanceof Strong || $node instanceof Underline
                || $node instanceof Strike || $node instanceof Superscript || $node instanceof Subscript
                || $node instanceof Highlight || $node instanceof Insert || $node instanceof Delete
            ) {
                // Their delimiters are written, and none is a space or a paren.
                $flat .= "\x01";
                $complete = $this->collectBracketMarksWithScope($node->getChildren(), $flat, $marks, $literalOpeners, $literalHosts) && $complete;
                $flat .= "\x01";
            } else {
                $flat .= ' ';
            }
        }

        return $complete;
    }

    /**
     * Each `(` of a run's matching `)`, and the offsets of its whitespace,
     * found once so a run of `[a](` stays linear.
     *
     * The minimal form escapes every backslash and quote in text, so neither
     * can escape a paren or open a title here.
     *
     * @param string $flat
     *
     * @return array{array<int, int>, array<int, int>}
     */
    public static function destinationScan(string $flat): array
    {
        $close = [];
        $open = [];
        preg_match_all('/[()]/', $flat, $parens, PREG_OFFSET_CAPTURE);
        foreach ($parens[0] as [$paren, $offset]) {
            if ($paren === '(') {
                $open[] = $offset;
            } elseif ($open !== []) {
                $close[array_pop($open)] = $offset;
            }
        }
        if (preg_match_all('/[\s\p{Z}\x{0085}]/u', $flat, $spaces, PREG_OFFSET_CAPTURE) === false) {
            return [[], []];
        }

        return [$close, array_column($spaces[0], 1)];
    }

    /**
     * Does the written text from the `(` at `$at` read as an inline link
     * destination that closes?
     *
     * The GRAMMAR decides this, not this engine's reader. `destination_char`
     * admits every character but `(`, `)` and Unicode whitespace, so `<foo>` is
     * an ordinary destination and `[link](<foo>)` is a link - "there is NO
     * angle-bracket-wrapped destination form" (PART 3 `link_destination`) says
     * the brackets are not STRIPPED, not that the run is refused. This engine's
     * reader does refuse it (carve-php#2634 follow-up), and matching the writer
     * to that refusal is what let the importer write literal `[link](<foo>)`
     * bare, which carve-js and carve-rs then read as a link.
     *
     * @param array{array<int, int>, array<int, int>} $scan
     * @param string $flat
     * @param int $at
     */
    public static function opensADestination(array $scan, string $flat, int $at): bool
    {
        [$close, $spaces] = $scan;
        $end = $close[$at] ?? null;
        if ($end === null || $end === $at + 1) {
            return false;
        }
        $low = 0;
        $high = count($spaces);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($spaces[$mid] <= $at) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }
        if ($low < count($spaces) && $spaces[$low] < $end) {
            return false;
        }

        return true;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param string $flat
     * @param array<int, array{int, int, int, string, int}> $marks
     *
     * @return bool False where the scan stopped early.
     */
    private function callCollectBracketMarks(array $nodes, string &$flat, array &$marks): bool
    {
        if ($this->collectBracketMarksCallback !== null) {
            return ($this->collectBracketMarksCallback)($nodes, $flat, $marks);
        }

        return $this->collectBracketMarks($nodes, $flat, $marks);
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param bool $bracketed
     */
    private function callPlanInlineRun(array $nodes, bool $bracketed): void
    {
        if ($this->planInlineRunCallback !== null) {
            ($this->planInlineRunCallback)($nodes, $bracketed);

            return;
        }

        $this->planInlineRun($nodes, $bracketed);
    }

    private function callPlanStructuralEscapes(Node $node): void
    {
        if ($this->planStructuralEscapesCallback !== null) {
            ($this->planStructuralEscapesCallback)($node);

            return;
        }

        $this->planStructuralEscapes($node);
    }
}
