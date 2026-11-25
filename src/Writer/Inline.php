<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;
use MarkupCarve\Carve\Inline\Scanner;
use MarkupCarve\Carve\Node;

/**
 * The inline nodes as Carve source (C2-W-069 to C2-W-080, C2-W-116). A line break of the content is a line feed in the
 * string; the block that holds the content places the lines.
 */
final class Inline
{
    /**
     * C2-W-071, C2-W-116: the nodes that have only a braced form.
     *
     * @var array<string, string>
     */
    private const BRACED = [
        'underline' => '_',
        'strike' => '~',
        'highlight' => '=',
        'superscript' => '^',
        'subscript' => ',',
        'insert' => '+',
        'delete' => '-',
    ];

    /**
     * @var array<string, string>
     */
    private const PAIRED = ['emphasis' => '/', 'strong' => '*'];

    /**
     * The nodes whose content stands between brackets, a barrier of the delimiter stack.
     *
     * @var array<string>
     */
    private const BARRIERS = ['link', 'image', 'span', 'inline_footnote', 'inline_extension', 'citation_group'];

    /**
     * How a text bracket pair reads (`pairForms()`): as text, as a construct, or as a construct only by the `[` or `{`
     * glued behind it.
     *
     * @var int
     */
    private const LITERAL_PAIR = 0;

    /**
     * @var int
     */
    private const FORMED_PAIR = 1;

    /**
     * @var int
     */
    private const FOLLOWED_PAIR = 2;

    /**
     * The characters a run of smart punctuation can continue with (data/smart-punctuation.json): `!+-.<=>()`.
     *
     * @var array<int, true>
     */
    private const SMART_RUN = [
        0x21 => true,
        0x28 => true,
        0x29 => true,
        0x2B => true,
        0x2D => true,
        0x2E => true,
        0x3C => true,
        0x3D => true,
        0x3E => true,
    ];

    /**
     * The first character of the written form of a node, where it does not depend on its fields.
     *
     * @var array<string, string>
     */
    private const FIRST = [
        'soft_break' => "\n",
        'emphasis' => '/',
        'strong' => '*',
        'link' => '[',
        'span' => '[',
        'footnote_ref' => '[',
        'image' => '!',
        'literal_inline' => '!',
        'inline_footnote' => '^',
        'code' => '`',
        'raw_inline' => '`',
        'math' => '$',
        'mention' => '@',
        'tag' => '#',
        'caption_number' => '#',
        'symbol' => ':',
        'inline_extension' => ':',
        'autolink' => '<',
        'xref' => '<',
        'non_breaking_space' => ' ',
    ];

    /**
     * The node written before the one being written in its list past braced comments, null at the start of the list.
     */
    private mixed $before = null;

    /**
     * The character written before the node being written past braced comments, -1 at the start of a line.
     */
    private int $visibleLast = -1;

    /**
     * The first character written behind the node being written past braced comments.
     */
    private int $visibleNext = -1;

    /**
     * The prefix and the value of the text node last written by `text`, for the run log of C2-W-146.
     */
    private int $textPrefix = 0;

    /**
     * @var string
     */
    private const ASCII_PUNCT = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    private int $leafDepth = 0;

    /**
     * The depth of inline footnotes around the node being written: no footnote forms inside one.
     */
    private int $noteDepth = 0;

    /**
     * @var array<string, true> C2-W-125 case eight: the paths of the links whose caret stays bare behind the escaped
     *   closers of a text note.
     */
    private array $caseEightLinks = [];

    /**
     * Whether the span being written follows a bare `!`, behind which its bracket reads as span text (C2-W-125).
     */
    private bool $spanAfterBang = false;

    /**
     * C2-W-088: the characters of the leaf's text that the last write left bare.
     *
     * @var array<string, true>
     */
    private array $bareLeads = [];

    /**
     * The paths of nested content that logged repair kinds; see `content()`.
     *
     * @var array<string, true>
     */
    private array $kindPaths = [];

    /**
     * @var array<string, array{array<mixed>, bool}> The `onlyEmptyAt()` answer of each content, with the content.
     */
    private array $emptyContent = [];

    /**
     * Whether a kind or pair round writes the leaf again; its first write reported the losses of the content.
     */
    private bool $rewrite = false;

    private string $textValue = '';

    /**
     * The paths of the emphasis and strong nodes the last write of their leaf spelled braced; see `paired()`.
     *
     * @var array<string, true>
     */
    private array $bracedPaired = [];

    public function __construct(private State $w)
    {
    }

    /**
     * The counts of `/` and `*` in the leaf, for the pairing test (C2-W-093).
     *
     * @param array<mixed> $nodes
     *
     * @return array<string, int>
     */
    public static function counts(array $nodes): array
    {
        $counts = ['/' => 0, '*' => 0];
        self::count($nodes, $counts);

        return $counts;
    }

    /**
     * @param array<mixed> $nodes
     * @param array<string, int> $counts
     *
     * @return void
     */
    private static function count(array $nodes, array &$counts): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? '';
            if ($type === 'text' && is_string($node['value'] ?? null)) {
                if (strpbrk($node['value'], '/*') !== false) {
                    $counts['/'] += substr_count($node['value'], '/');
                    $counts['*'] += substr_count($node['value'], '*');
                }
            } elseif ($type === 'smart_punctuation' && is_string($node['source'] ?? null)) {
                $counts['/'] += substr_count($node['source'], '/');
            } elseif (isset(self::PAIRED[$type])) {
                $counts[self::PAIRED[$type]] += 2;
            }
            if (is_array($node['children'] ?? null)) {
                self::count($node['children'], $counts);
            }
        }
    }

    /**
     * Writes a list of inline nodes behind what `$c` says was written last.
     *
     * @param array<mixed> $nodes
     * @param string $path Path of the list, such as `/children/0/children`.
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next First character written behind the list, -1 at the end of the content.
     *
     * @return string
     */
    public function content(array $nodes, string $path, Context $c, int $next): string
    {
        if ($c->flat !== null) {
            $siblings = $c->siblings;
            $c->siblings = self::siblingFlat($nodes, $path, $next);
            try {
                return $this->contentOnce($nodes, $path, $c, $next);
            } finally {
                $c->siblings = $siblings;
            }
        }
        if ($this->w->repairTrace !== null) {
            return $this->contentOnce($nodes, $path, $c, $next);
        }
        // C2-W-088: a leaf whose repair escapes report kinds is written again with every potential opener of those
        // kinds escaped, until the kinds settle; the rounds are bounded by the kinds.
        // The kinds belong to the leaf block: content nested in it, as link text, reports them to the leaf.
        $outer = $c->runLog;
        $c->runLog = [];
        if ($this->leafDepth > 0) {
            $kinds = [];
            $out = $this->contentPairs($nodes, $path, $c, $next, clone $c, $kinds);
            // An entry without kinds adds nothing unless it replaces an earlier one of the same path: the pair test
            // reads it as a run of one, and the kind count takes the last entry of each path (carve-php#5722).
            if ($kinds !== [] || isset($this->kindPaths[$path])) {
                $outer[] = new RunEntry($path . '#', '', 0, [], '', array_keys($kinds));
                $this->kindPaths[$path] = true;
            }
            $c->runLog = $outer;

            return $out;
        }
        $this->leafDepth++;
        try {
            return $this->leafContent($nodes, $path, $c, $next);
        } finally {
            $this->leafDepth--;
            $c->runLog = $outer;
            // The kept answers hold the leaf's content arrays alive; no later leaf reads them.
            $this->emptyContent = [];
        }
    }

    /**
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function leafContent(array $nodes, string $path, Context $c, int $next): string
    {
        $first = clone $c;
        $kinds = [];
        $this->bareLeads = [];
        $losses = count($this->w->losses);
        $tickRounds = Text::$tickKindRounds;
        $out = $this->contentPairs($nodes, $path, $c, $next, clone $c, $kinds);
        $reported = $out;
        $afterReported = $kinds === [] ? null : clone $c;
        // A first-line escape of the block writer reports its kind to the leaf before the first write (FirstLine).
        $applied = $c->kinds;
        $fastAdded = [];
        $hashOnlyText = false;
        if (
            count($nodes) === 1 && is_array($nodes[0] ?? null) && ($nodes[0]['type'] ?? null) === 'text'
            && $kinds === ['#' => true]
            && $c->runPairEscapes === [] && !$c->inCell && $this->leftBare($kinds)
            && is_string($nodes[0]['value'] ?? null)
            && !str_contains($nodes[0]['value'], "\n") && rtrim($nodes[0]['value'], " \t") === $nodes[0]['value']
        ) {
            $fast = Text::expandedHashKind($nodes[0]['value'], $out, $c);
            if ($fast !== null) {
                [$out, $fastAdded] = $fast;
                $applied['#'] = true;
                // A leaf with only text and no other mark cannot acquire another inline kind when hashes are escaped.
                $hashOnlyText = strspn($nodes[0]['value'], 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 #{}')
                === strlen($nodes[0]['value']);
            }
        }
        while (array_diff_key($kinds, $applied) !== [] && $this->leftBare($kinds)) {
            $this->bareLeads = [];
            $applied += $kinds;
            $again = clone $first;
            $again->kinds = $applied;
            // The first write charged the reparse of its first line; the same line again is no new repair state.
            $again->writerState = null;
            $out = $this->rewrittenPairs($nodes, $path, $again, $next, $kinds);
            foreach (get_object_vars($again) as $name => $field) {
                $c->{$name} = $field;
            }
            $c->writerState = $first->writerState;
        }
        // C2-W-088: a leaf whose kind rounds would read back as other inline content keeps the reported escapes only.
        if ($afterReported !== null && $out !== $reported && !$hashOnlyText) {
            $outShape = $this->inlineShape($out, $c);
            if ($outShape !== self::treeShape($nodes) && $outShape !== $this->inlineShape($reported, $c)) {
                foreach (get_object_vars($afterReported) as $name => $field) {
                    $c->{$name} = $field;
                }
                $out = $reported;
                $fastAdded = [];
            }
        }
        // C2-W-088: where a node did not read back, the backtick kind rounds give way to the reported openers.
        if (count($this->w->losses) > $losses && Text::$tickKindRounds > $tickRounds) {
            $again = clone $first;
            $again->kinds = $c->kinds;
            $again->tickReportedOnly = true;
            $again->writerState = null;
            $out = $this->rewrittenPairs($nodes, $path, $again, $next, $kinds);
            foreach (get_object_vars($again) as $name => $field) {
                $c->{$name} = $field;
            }
            $c->writerState = $first->writerState;
            $c->tickReportedOnly = false;
            $fastAdded = [];
        }
        if ($fastAdded !== []) {
            Text::recordExpandedHash($c, $fastAdded);
        }
        $c->kinds = [];

        return $out;
    }

    /**
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param \MarkupCarve\Carve\Writer\Context $start
     * @param array<string, true> $kinds Set to the kinds of the repair escapes of the final write.
     *
     * @return string
     */
    private function contentPairs(array $nodes, string $path, Context $c, int $next, Context $start, array &$kinds): string
    {
        // C2-W-146: a pair across the text nodes of a run is tested on the whole content, which is written again
        // with the backslashes it asks for.
        $out = $this->contentOnce($nodes, $path, $c, $next);
        $forced = [];
        for ($round = 0; $round < 64; $round++) {
            $asked = Text::runPairOpeners($c->runLog);
            $new = false;
            foreach ($asked as $at => $offsets) {
                foreach ($offsets as $i => $_) {
                    $new = $new || !isset($forced[$at][$i]);
                    $forced[$at][$i] = true;
                }
            }
            if (!$new) {
                break;
            }
            $again = clone $start;
            $again->runPairEscapes = $forced;
            // The first write charged the reparse of its first line; the same line again is no new repair state.
            $again->writerState = null;
            $rewrite = $this->rewrite;
            $this->rewrite = true;
            try {
                $out = $this->contentOnce($nodes, $path, $again, $next);
            } finally {
                $this->rewrite = $rewrite;
            }
            foreach (get_object_vars($again) as $name => $field) {
                $c->{$name} = $field;
            }
            $c->writerState = $start->writerState;
        }
        $kinds = $this->writtenKinds($c->runLog, $out);
        $c->runLog = [];

        return $out;
    }

    /**
     * `contentPairs()` of content the leaf wrote before.
     *
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param array<string, true> $kinds
     */
    private function rewrittenPairs(array $nodes, string $path, Context $c, int $next, array &$kinds): string
    {
        $rewrite = $this->rewrite;
        $this->rewrite = true;
        try {
            return $this->contentPairs($nodes, $path, $c, $next, clone $c, $kinds);
        } finally {
            $this->rewrite = $rewrite;
        }
    }

    /**
     * Whether a character of a kind stands bare in the leaf's text, so a round has an opener to escape.
     *
     * @param array<string, true> $kinds
     */
    private function leftBare(array $kinds): bool
    {
        foreach ($kinds as $kind => $_) {
            $lead = str_starts_with($kind, 'line') ? $kind[4] : $kind[0];
            if (isset($this->bareLeads[$lead])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed>|null $node
     */
    private static function isText(?array $node): bool
    {
        return $node !== null && ($node['type'] ?? null) === 'text';
    }

    /**
     * The inline nodes one spelling of a leaf's content parses to.
     */
    private function inlineShape(string $text, Context $c): string
    {
        $context = new InlineContext();
        $context->linkKeys = $c->labels;
        $mode = $c->inCell ? Scanner::CELL : 0;

        return self::shape(InlineRunner::parse($text, self::lineSpans($text), $context, 0, $mode));
    }

    /**
     * The shape of the tree's own inline nodes, spelled as `shape()` spells parsed ones.
     *
     * @param array<mixed> $nodes
     */
    private static function treeShape(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                return '';
            }
            $fields = $node;
            unset($fields['type'], $fields['children'], $fields['pos']);
            ksort($fields);
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $out .= '(' . self::str($node, 'type') . (string)json_encode($fields, JSON_PARTIAL_OUTPUT_ON_ERROR)
                . self::treeShape($children) . ')';
        }

        return $out;
    }

    /**
     * @return array<int>
     */
    private static function lineSpans(string $text): array
    {
        $spans = [];
        $at = 0;
        foreach (explode("\n", $text) as $line) {
            $spans[] = $at;
            $spans[] = $at + strlen($line);
            $at += strlen($line) + 1;
        }

        return $spans;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     */
    private static function shape(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $fields = $node->f;
            ksort($fields);
            $out .= '(' . $node->type . (string)json_encode($fields, JSON_PARTIAL_OUTPUT_ON_ERROR)
                . self::shape($node->children ?? []) . ')';
        }

        return $out;
    }

    /**
     * C2-W-088: the kinds of the repair escapes in the last write of each text node.
     *
     * @param array<\MarkupCarve\Carve\Writer\RunEntry> $log
     * @param string $out The content as written; a backslash a later step took out gives no kind.
     *
     * @return array<string, true>
     */
    private function writtenKinds(array $log, string $out): array
    {
        $last = [];
        foreach ($log as $entry) {
            $last[$entry->path] = $entry->kinds;
            $this->bareLeads += self::bareMarks($entry->value, $entry->escaped);
        }
        $kinds = [];
        $seen = [];
        foreach ($last as $written) {
            foreach ($written as $kind) {
                // Each kind scans the content once, not once per escape of it.
                if (isset($seen[$kind])) {
                    continue;
                }
                $seen[$kind] = true;
                $escaped = str_starts_with($kind, 'line') ? $kind[4] : ($kind === '--' ? '-' : $kind[0]);
                if (str_contains($out, '\\' . $escaped)) {
                    $kinds[$kind] = true;
                }
            }
        }

        return $kinds;
    }

    /**
     * The ASCII punctuation marks of a text that stand bare: the text holds more of the mark than it escapes.
     *
     * @param string $value
     * @param array<int, bool|int> $escaped
     *
     * @return array<string, true>
     */
    private static function bareMarks(string $value, array $escaped): array
    {
        if (strpbrk($value, self::ASCII_PUNCT) === false) {
            return [];
        }
        $counts = [];
        foreach (array_filter($escaped) as $at => $_) {
            $mark = $value[$at] ?? '';
            $counts[$mark] = ($counts[$mark] ?? 0) + 1;
        }
        // Each mark the text holds is counted once, found by skipping the runs without one.
        $bare = [];
        $seen = [];
        $n = strlen($value);
        for ($at = strcspn($value, self::ASCII_PUNCT); $at < $n; $at += 1 + strcspn($value, self::ASCII_PUNCT, $at + 1)) {
            $mark = $value[$at];
            if (!isset($seen[$mark])) {
                $seen[$mark] = true;
                if (substr_count($value, $mark) > ($counts[$mark] ?? 0)) {
                    $bare[$mark] = true;
                }
            }
        }

        return $bare;
    }

    // Runs for every inline list of every write; the trace checks stay inline rather than one call per node.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function contentOnce(array $nodes, string $path, Context $c, int $next): string
    {
        if ($c->flat === null) {
            $c->flat = '';
            $c->labels = $this->w->labels;
            self::startLeaf($nodes, $path, $c);
            self::flatten($nodes, $path, $c);
        }
        $this->markCaseEightLinks($nodes, $path);
        $out = '';
        $count = count($nodes);
        $visible = $c->lineStart ? -1 : $c->last;
        $visibleNode = null;
        $redo = null;
        // Each written part as three parallel int lists, its offset in `$out`, first and last node: one small array
        // per node would hold several times the memory on a long paragraph (carve-php#5664).
        $parts = [[], [], []];
        $hyphens = 0;
        $dots = 0;
        // The hyphen and dot runs feed only the escape decisions, and a traced write spells its texts bare.
        $traced = $c->writerState?->repairTrace !== null;
        $dotsAhead = $traced ? [] : self::dotsAhead($nodes);
        for ($at = 0; $at < $count; $at++) {
            $node = $nodes[$at];
            if (!is_array($node)) {
                continue;
            }
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            $following = $nodes[$at + 1] ?? null;
            $text = $type === 'text';
            // The neighbor fields are set here rather than by a call per node: this loop runs for every inline node.
            // C2-W-099, C2-I-114: the hyphen and dot runs a text continues.
            if (!$traced) {
                $c->hyphensBefore = $text ? $hyphens : 0;
                $c->hyphenAfter = $text && is_array($following) && ($following['type'] ?? '') === 'text'
                    && str_starts_with(self::str($following, 'value'), '-');
                $c->lastInContent = $text && $following === null;
                $c->dotsBefore = $text ? $dots : 0;
                $c->dotsAfter = $text ? $dotsAhead[$at + 1] ?? 0 : 0;
                if ($text && ($node['value'] ?? null) === '') {
                    $this->loseLoneEmptyText($nodes, $at, $path);
                }
            }
            if (!$text && !isset(self::VERBATIM[$type])) {
                $c->escapeFollowingPipeAt = -1;
            }
            $c->lineCommentBefore = $text && self::followsLineComment($nodes, $at);
            $c->afterText = self::isText($visibleNode);
            $this->before = $visibleNode;
            $this->visibleLast = $visible;
            // The first character behind the node, which `listed()` reads too; past a braced comment it is searched.
            $firstFollowing = is_array($following) ? self::first($following, $c) : null;
            $this->visibleNext = $firstFollowing !== null && !self::isBracedComment($following)
                ? $firstFollowing : self::visibleNext($nodes, $at, $c, $next);
            $c->lineTail = $text && is_array($following) ? self::spanLineTail($following) : null;
            $c->lineAfterSpan = '';
            $c->lineAfterSpanEnds = false;
            $c->lineAfterSpanPipeSource = -1;
            $c->lineAfterSpanPipeText = -1;
            if (isset(self::VERBATIM[$type])) {
                self::setFollowingTextLine($c, $nodes, $at, $type);
            }
            if ($c->inCell) {
                $this->loseGluedRun($nodes, $at, $path, $type);
            }
            if ($c->inCell && isset($this->w->wrapStarts[$path . '/' . $at])) {
                // C2-L-021: a fragment of a continuation row starts at this node; the joining space is the line break.
                $out = (str_ends_with($out, ' ') ? substr($out, 0, -1) : $out) . "\n";
                $c->last = 10;
                $c->lineStart = true;
            }
            $first = $at;
            if ($type === 'code' && BraceBlock::of($node) === '' && is_array($following) && ($following['type'] ?? '') === 'code') {
                [$part, $at] = $this->codeRun($nodes, $at, $path, $c);
            } else {
                $this->spanAfterBang = $type === 'span' && $this->followsBareBang($node, $out);
                // A continuation row can move the line start, which the first of a hard break reads.
                $part = $this->listed($nodes, $at, $path, $c, $next, $out, $redo, $c->inCell ? null : $firstFollowing);
            }
            // C2-W-125 case six: a reported braced opener in a paired node can take a trial beside it in the same
            // round. Force that trial at the closer mark which starts the following text, without giving it a kind.
            $closer = self::PAIRED[$type] ?? null;
            if (
                !$traced && $closer !== null && is_array($following) && ($following['type'] ?? '') === 'text'
                && str_starts_with(self::str($following, 'value'), $closer) && str_contains($part, '\\{' . $closer)
            ) {
                $c->runPairEscapes[$path . '/' . ($at + 1)][0] = true;
            }
            $parts[0][] = strlen($out);
            $parts[1][] = $first;
            $parts[2][] = $at;
            $out .= $part;
            if (!$traced) {
                $hyphens = self::hyphensAfter($node, $type, $c, $hyphens);
                $dots = self::dotsAfter($node, $dots);
            }
            if ($text) {
                self::advanceFollowingPipe($c, $node, $type);
            }
            if ($type !== 'comment' || ($node['form'] ?? '') !== 'braced') {
                $visible = $c->lineStart ? -1 : $c->last;
                $visibleNode = $node;
            }
        }
        if (!$c->inCell && $this->w->repairTrace === null) {
            $this->readBack($nodes, $parts, $out, $path);
        }

        return $out;
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @var array<string, true>
     */
    private const VERBATIM = ['code' => true, 'math' => true, 'raw_inline' => true, 'literal_inline' => true];

    /**
     * C2-W-112, C2-W-117, C2-W-124: a backtick run glued to the closer of a span joins it, so the span reads on to the
     * next run of its own width, which may lie past several nodes (carve#6341). The content is read back from the
     * first glued closer on; a verbatim span that ends elsewhere than its spelling loses its value, or the node when it
     * reads as another type, and every node that starts inside such a span is lost.
     *
     * @param array<mixed> $nodes
     * @param array{0: array<int>, 1: array<int>, 2: array<int>} $parts Each written part: its offset in `$out`, its first and last node.
     * @param string $out
     * @param string $path
     *
     * @return void
     */
    private function readBack(array $nodes, array $parts, string $out, string $path): void
    {
        $glued = self::gluedPart($parts, $out);
        if ($glued === null) {
            return;
        }
        [$offsets, $firsts, $lasts] = $parts;
        $base = $offsets[$glued];
        // A letter and a space first, so a long backtick run opens no fence and the spans stand mid-line.
        $read = Carve::parse('x ' . substr($out, $base) . "\n")->children ?? [];
        if (count($read) !== 1 || $read[0]->type !== 'paragraph') {
            // Later content opens another block, so the run is predicted from the spans alone.
            foreach ($firsts as $first) {
                $this->loseGluedRun($nodes, $first, $path, self::str((array)$nodes[$first], 'type'));
            }

            return;
        }
        $children = $read[0]->children ?? [];
        $c = 0;
        $lead = '';
        $count = count($offsets);
        for ($k = $glued; $k < $count; $k++) {
            $start = $offsets[$k];
            $first = $firsts[$k];
            $last = $lasts[$k];
            while (isset($children[$c]) && $base + $children[$c]->end - 2 <= $start) {
                $c++;
                $lead = '';
            }
            $type = self::str((array)$nodes[$first], 'type');
            $child = $children[$c] ?? null;
            $field = $child === null ? (isset(self::VERBATIM[$type]) ? null : false) : self::taken(
                $type,
                $child,
                $start - $base + 2,
                ($offsets[$k + 1] ?? strlen($out)) - $base + 2,
                $lead,
            );
            if ($field === false) {
                continue;
            }
            $this->w->lose($path . '/' . $first, $type, $field);
            for ($member = $first + 1; $member <= $last; $member++) {
                $this->w->lose($path . '/' . $member, self::str((array)$nodes[$member], 'type'), null);
            }
        }
    }

    /**
     * The first part whose closing backtick the part behind it joins.
     *
     * @param array{0: array<int>, 1: array<int>, 2: array<int>} $parts
     * @param string $out
     *
     * @return int|null
     */
    private static function gluedPart(array $parts, string $out): ?int
    {
        foreach ($parts[0] as $k => $start) {
            if ($k > 0 && ($out[$start] ?? '') === '`' && $out[$start - 1] === '`') {
                return $k - 1;
            }
        }

        return null;
    }

    /**
     * What a written part from `$start` to `$end` loses when the reader puts its start in `$child`: false for nothing,
     * else the field it loses, or null for the node. `$lead` is the type of the part the child starts with.
     *
     * @param string $type
     * @param \MarkupCarve\Carve\Node $child
     * @param int $start
     * @param int $end
     * @param string $lead
     *
     * @return string|false|null
     */
    private static function taken(string $type, Node $child, int $start, int $end, string &$lead): string|false|null
    {
        $read = $child->start <= $start && isset(self::VERBATIM[$child->type]) ? $child->type : null;
        if ($read !== null && $child->start === $start) {
            $lead = $type;
            if ($child->end === $end && $read === $type) {
                return false;
            }

            return $read === $type ? 'value' : null;
        }
        if ($read !== null) {
            // A code run that ends in a raw span reads as that raw span.
            if ($read === 'raw_inline' && $type === 'raw_inline' && $child->end === $end && $lead === 'code') {
                return false;
            }

            return $type === 'text' ? 'value' : null;
        }

        return isset(self::VERBATIM[$type]) ? null : false;
    }

    /**
     * C2-W-117: no parse gives an empty text. In a run of texts that holds other text it joins that (C2-A-040); a run
     * of only empty texts reads back as none, reported at its first node. Content of nothing else, or ending in such a
     * run, is the loss of its parent (`loseEmptyContent`, `endsInLoneEmptyText`).
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $path
     *
     * @return void
     */
    private function loseLoneEmptyText(array $nodes, int $at, string $path): void
    {
        $before = $nodes[$at - 1] ?? null;
        if (is_array($before) && self::isText($before)) {
            return;
        }
        $run = self::emptyTextRun($nodes, $at);
        if ($run === null || $run[1] === count($nodes) - 1 || self::onlyEmptyText($nodes)) {
            return;
        }
        $this->w->lose($path . '/' . $at, 'text', 'value');
    }

    /**
     * C2-W-117: content that ends in a run of only empty texts reads back without it; the parent reports it, as
     * carve-js and carve-rs do.
     *
     * @param mixed $children
     *
     * @return bool
     */
    public static function endsInLoneEmptyText(mixed $children): bool
    {
        if (!is_array($children) || $children === [] || self::onlyEmptyText($children)) {
            return false;
        }
        $children = array_values($children);

        return self::emptyTextRun($children, count($children) - 1) !== null;
    }

    /**
     * The first and last index of the run of texts around `at` when all of them are empty.
     *
     * @param array<mixed> $nodes
     * @param int $at
     *
     * @return array{int, int}|null
     */
    private static function emptyTextRun(array $nodes, int $at): ?array
    {
        $node = $nodes[$at] ?? null;
        if (!is_array($node) || !self::isText($node) || ($node['value'] ?? null) !== '') {
            return null;
        }
        $empty = static fn (mixed $node): bool => is_array($node) && self::isText($node) && ($node['value'] ?? null) === '';
        $first = $at;
        $last = $at;
        while (is_array($nodes[$first - 1] ?? null) && self::isText($nodes[$first - 1])) {
            if (!$empty($nodes[--$first])) {
                return null;
            }
        }
        while (is_array($nodes[$last + 1] ?? null) && self::isText($nodes[$last + 1])) {
            if (!$empty($nodes[++$last])) {
                return null;
            }
        }

        return [$first, $last];
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param array<mixed> $node
     * @param string $type
     *
     * @return void
     */
    private static function advanceFollowingPipe(Context $c, array $node, string $type): void
    {
        if ($type !== 'text') {
            return;
        }
        $length = strlen(self::str($node, 'value'));
        $c->escapeFollowingPipeAt = $c->escapeFollowingPipeAt >= $length
            ? $c->escapeFollowingPipeAt - $length : -1;
    }

    /**
     * The line up to the first break of a following verbatim span, for the opener test of text before it.
     *
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function spanLineTail(array $node): ?string
    {
        $type = self::str($node, 'type');
        if (!in_array($type, ['code', 'math', 'literal_inline', 'raw_inline'], true)) {
            return null;
        }
        $value = self::str($node, 'value');
        if (!str_contains($value, "\n")) {
            return null;
        }
        $prefix = match ($type) {
            'math' => ($node['display'] ?? false) === true ? '$$' : '$',
            'literal_inline' => '!',
            default => '',
        };
        $spelled = $prefix . self::codeSpan($value);

        return substr($spelled, 0, (int)strpos($spelled, "\n"));
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $type
     *
     * @return void
     */
    private static function setFollowingTextLine(Context $c, array $nodes, int $at, string $type): void
    {
        if (!self::multilineSpan($nodes[$at] ?? null, $type)) {
            return;
        }
        $c->lineAfterSpanEnds = true;
        $textOffset = 0;
        $probe = clone $c;
        $probe->lineStart = false;
        $probe->last = 0x60;
        $probe->here = -1;
        $probe->writerState = null;
        $probe->hyphensBefore = 0;
        $probe->hyphenAfter = false;
        $probe->lastInContent = false;
        $probe->dotsBefore = 0;
        $probe->dotsAfter = 0;
        $probe->escapeFollowingPipeAt = -1;
        for ($i = $at + 1, $n = count($nodes); $i < $n; $i++) {
            $node = $nodes[$i];
            $nextType = is_array($node) ? self::str($node, 'type') : '';
            $spanSpelling = is_array($node) ? self::simpleSpanSpelling($node) : null;
            if (in_array($nextType, ['soft_break', 'hard_break'], true)) {
                break;
            }
            if ($nextType === 'text') {
                $value = self::str($node, 'value');
                $break = strpos($value, "\n");
                $fragment = $break === false ? $value : substr($value, 0, $break);
                $spelled = Text::escape($fragment, $probe, $break === false ? -1 : 10);
                $pipe = strrpos($fragment, '|');
                if ($pipe !== false) {
                    $c->lineAfterSpanPipeSource = strlen($c->lineAfterSpan) + (int)strrpos($spelled, '|');
                    $c->lineAfterSpanPipeText = $textOffset + $pipe;
                }
                $textOffset += strlen($value);
            } elseif ($spanSpelling !== null) {
                $spelled = $spanSpelling;
                $break = strpos($spelled, "\n");
                if ($break !== false) {
                    $spelled = substr($spelled, 0, $break);
                }
            } else {
                $c->lineAfterSpanEnds = false;

                break;
            }
            $c->lineAfterSpan .= $spelled;
            if ($spelled !== '') {
                $probe->last = Chars::before($spelled, strlen($spelled));
            }
            if ($break !== false) {
                break;
            }
        }
    }

    private static function multilineSpan(mixed $node, string $type): bool
    {
        return is_array($node) && in_array($type, ['code', 'math', 'literal_inline', 'raw_inline'], true)
            && str_contains(self::str($node, 'value'), "\n");
    }

    /**
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function simpleSpanSpelling(array $node): ?string
    {
        $type = self::str($node, 'type');
        if (!in_array($type, ['code', 'math', 'literal_inline', 'raw_inline'], true)) {
            return null;
        }
        $prefix = match ($type) {
            'math' => ($node['display'] ?? false) === true ? '$$' : '$',
            'literal_inline' => '!',
            default => '',
        };

        return $prefix . self::codeSpan(self::str($node, 'value'))
            . ($type === 'raw_inline' ? '{=' . self::str($node, 'format') . '}' : '');
    }

    /**
     * @param array<mixed> $nodes
     * @param int $at
     *
     * @return bool
     */
    private static function followsLineComment(array $nodes, int $at): bool
    {
        $prior = $nodes[$at - 2] ?? null;
        $break = $nodes[$at - 1] ?? null;

        return is_array($break) && ($break['type'] ?? '') === 'soft_break'
            && is_array($prior) && ($prior['type'] ?? '') === 'comment' && ($prior['form'] ?? '') === 'line';
    }

    /**
     * Writes the node at `$at` of a list. C2-W-069: a text that ends in `{` before emphasis or strong reads the first
     * character of the form the node takes, so it is written again once the node comes out braced.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param string $out What the list wrote so far.
     * @param array{0: int, 1: int, 2: \MarkupCarve\Carve\Writer\Context}|null $redo Where the text before stands in
     *   `$out`, and the context it was written in.
     *
     * @return string
     */
    private function listed(
        array $nodes,
        int $at,
        string $path,
        Context $c,
        int $next,
        string &$out,
        ?array &$redo,
        ?int $first = null,
    ): string {
        $node = (array)$nodes[$at];
        $following = $nodes[$at + 1] ?? null;
        $c->beforeSoftBreak = self::isSoftBreak($following);
        $c->beforeHardBreak = is_array($following) && ($following['type'] ?? '') === 'hard_break';
        $after = is_array($following) ? $first ?? self::first($following, $c) : $next;
        // C2-W-069, left to right: of two glued spans of one kind, the later one takes the braced form. An empty span
        // writes nothing, so nothing glues to it.
        $type = self::str($node, 'type');
        if (
            isset(self::PAIRED[$type]) && is_array($following) && self::str($following, 'type') === $type
            && ($following['children'] ?? []) !== []
        ) {
            $after = 0x7B;
        }
        $retry = $type === 'text' && is_array($following) && isset(self::PAIRED[self::str($following, 'type')])
            && str_ends_with(self::str($node, 'value'), '{');
        $snapshot = null;
        if ($retry) {
            // The replay never reads the run log; a shared one would be copied in full on the next append.
            $log = $c->runLog;
            $c->runLog = [];
            $snapshot = clone $c;
            $c->runLog = $log;
            unset($log);
        }
        $part = $this->node($node, $path . '/' . $at, $c, $after, $nodes[$at - 1] ?? null);
        if ($redo !== null && str_starts_with($part, '{')) {
            [$from, $length, $text] = $redo;
            // The replay revisits the same first-line lookahead with the next node now known.
            $text->writerState = null;
            $again = $this->node((array)$nodes[$at - 1], $path . '/' . ($at - 1), $text, 0x7B, $nodes[$at - 2] ?? null);
            $out = substr_replace($out, $again, $from, $length);
        }
        $redo = $snapshot === null ? null : [strlen($out), strlen($part), $snapshot];

        return $part;
    }

    private static function isSoftBreak(mixed $node): bool
    {
        return is_array($node) && ($node['type'] ?? '') === 'soft_break';
    }

    /**
     * C2-A-069: inside a line of a line block every line ending ends the line, so a soft break, and a hard break before
     * more content, splits the line. The bare line ending is the nearest form (C2-W-117). A break before a soft break
     * leaves an empty line, which `BlankLines` reports.
     */
    private function loseBreakInLine(string $type, string $path, Context $c, int $next): void
    {
        if ($c->inLineBlock && !$c->inCell && $next !== -1 && !$c->beforeSoftBreak) {
            $this->w->lose($path, $type, null);
        }
    }

    /**
     * The hyphens the written node ends a run with: a text value's, counting those before it when it is all hyphens,
     * and one for a dash node (C2-W-125 case four).
     *
     * @param array<mixed> $node
     * @param string $type
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $before
     *
     * @return int
     */
    private static function hyphensAfter(array $node, string $type, Context $c, int $before): int
    {
        if ($type !== 'text') {
            return $c->last === 0x2D ? 1 : 0;
        }
        $value = self::str($node, 'value');
        $run = strlen($value) - strlen(rtrim($value, '-'));

        return $run === strlen($value) ? $before + $run : $run;
    }

    /**
     * C2-I-114: the dots each node starts the spelling with, counting those of the nodes behind it when it is all dots.
     *
     * @param array<mixed> $nodes
     *
     * @return array<int, int>
     */
    private static function dotsAhead(array $nodes): array
    {
        $ahead = [];
        $run = 0;
        for ($at = count($nodes) - 1; $at >= 0; $at--) {
            $spelled = self::spelledDots($nodes[$at]);
            $lead = $spelled === null ? 0 : strspn($spelled, '.');
            $run = $spelled !== null && $lead === strlen($spelled) ? $lead + $run : $lead;
            // Only the runs: the reader takes a missing index as none, and a long paragraph has few (carve-php#5722).
            if ($run !== 0) {
                $ahead[$at] = $run;
            }
        }

        return $ahead;
    }

    /**
     * The dots a written node ends its spelling with, counting those before it when it is all dots.
     *
     * @param mixed $node
     * @param int $before
     *
     * @return int
     */
    private static function dotsAfter(mixed $node, int $before): int
    {
        $spelled = self::spelledDots($node);
        if ($spelled === null || $spelled === '') {
            return 0;
        }
        $run = strlen($spelled) - strlen(rtrim($spelled, '.'));

        return $run === strlen($spelled) ? $before + $run : $run;
    }

    /**
     * The text of a text node or the spelling of an atom, where a dot run can go on through it.
     *
     * @param mixed $node
     *
     * @return string|null
     */
    private static function spelledDots(mixed $node): ?string
    {
        if (!is_array($node)) {
            return null;
        }
        if (($node['type'] ?? '') === 'text') {
            return self::str($node, 'value');
        }

        return isset($node['attrs']) ? null : self::atom($node);
    }

    /**
     * C2-I-026: a braced comment counts as nothing for its neighbors.
     *
     * @param mixed $node
     *
     * @return bool
     */
    private static function isBracedComment(mixed $node): bool
    {
        return is_array($node) && ($node['type'] ?? '') === 'comment' && ($node['form'] ?? '') === 'braced';
    }

    /**
     * The first character written behind the node at `$at` past braced comments, `$next` at the end of the list.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return int
     */
    private static function visibleNext(array $nodes, int $at, Context $c, int $next): int
    {
        $count = count($nodes);
        for ($k = $at + 1; $k < $count; $k++) {
            if (is_array($nodes[$k]) && !self::isBracedComment($nodes[$k])) {
                return self::first($nodes[$k], $c);
            }
        }

        return $next;
    }

    /**
     * C2-W-093: the content of a node flattened as the leaf is, where a text of it holds a `/` or a `*`.
     *
     * @param array<mixed> $nodes
     * @param string $path
     * @param int $next The first character written behind the content.
     *
     * @return array{flat: string, at: array<string, int>, types: array<int, string>, nodes: array<int, array<mixed>>, next: int}|null
     */
    private static function siblingFlat(array $nodes, string $path, int $next): ?array
    {
        // Most lists hold no mark, and the keys below are as long as the path.
        $marks = false;
        foreach ($nodes as $node) {
            if (
                is_array($node) && ($node['type'] ?? null) === 'text' && is_string($node['value'] ?? null)
                && strpbrk($node['value'], '/*') !== false
            ) {
                $marks = true;

                break;
            }
        }
        if (!$marks) {
            return null;
        }
        $flat = '';
        $at = [];
        $types = [];
        $held = [];
        $marks = false;
        foreach ($nodes as $k => $node) {
            $type = is_array($node) ? self::str($node, 'type') : '';
            if ($type === 'text') {
                $value = self::str($node, 'value');
                $marks = $marks || strpbrk($value, '/*') !== false;
                $at[$path . '/' . $k] = strlen($flat);
                $flat .= $value;
            } elseif ($type === 'soft_break' || $type === 'hard_break') {
                $flat .= "\n";
            } else {
                $types[strlen($flat)] = $type;
                $held[strlen($flat)] = is_array($node) ? $node : [];
                $flat .= "\0";
            }
        }

        return $marks ? ['flat' => $flat, 'at' => $at, 'types' => $types, 'nodes' => $held, 'next' => $next] : null;
    }

    /**
     * The leaf whose backtick plan the texts of `$nodes` read: these nodes, or inside a bracket the leaf around it,
     * whose verbatim nodes the bracketed text cannot plan without (C2-W-088).
     *
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return void
     */
    private static function startLeaf(array $nodes, string $path, Context $c): void
    {
        if ($c->inBracket && $c->tickPlan !== null) {
            return;
        }
        $c->leafNodes = $nodes;
        $c->leafPath = $path;
        if (!$c->inBracket) {
            $c->leafBraces = null;
        }
        $c->tickPlan = null;
        $c->tickForced = [];
    }

    /**
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return void
     */
    private static function flatten(array $nodes, string $path, Context $c): void
    {
        foreach ($nodes as $at => $node) {
            $type = is_array($node) ? self::str($node, 'type') : '';
            if ($type === 'text') {
                $c->flatAt[$path . '/' . $at] = strlen((string)$c->flat);
                $c->flat .= self::str($node, 'value');
            } elseif ($type === 'soft_break' || $type === 'hard_break') {
                $c->flat .= "\n";
            } else {
                $c->flatTypes[strlen((string)$c->flat)] = $type;
                $offset = strlen((string)$c->flat);
                $c->flatFirst[$offset] = is_array($node) ? self::first($node, $c) : -1;
                $atom = is_array($node) && !isset($node['attrs']) ? self::atom($node) : null;
                if ($atom !== null) {
                    $c->flatAtoms[$offset] = $atom;
                }
                if ($atom === null && is_array($node)) {
                    $c->flatNodes[$offset] = $node;
                }
                $c->flatBraces[$offset] = $atom === null && (!is_array($node) || isset($node['attrs'])
                    || in_array($type, ['span', 'raw_inline', 'comment', 'critic_comment'], true) || !isset(self::FIRST[$type]));
                $c->flat .= "\0";
            }
        }
    }

    /**
     * Every string a node holds, its own fields and those of its descendants, each closed by `\0`.
     *
     * @param array<mixed> $node
     *
     * @return string
     */
    public static function strings(array $node): string
    {
        $out = '';
        array_walk_recursive($node, function (mixed $v) use (&$out): void {
            if (is_string($v)) {
                $out .= $v . "\0";
            }
        });

        return $out;
    }

    /**
     * Whether one of `strings()` of `$node` holds `$mark`, a single character, without building them.
     *
     * @param array<mixed> $node
     * @param string $mark
     * @param string|null $skip A field of `$node` itself left out.
     *
     * @return bool
     */
    public static function holds(array $node, string $mark, ?string $skip = null): bool
    {
        foreach ($node as $key => $value) {
            if ($key === $skip) {
                continue;
            }
            if (is_string($value) ? str_contains($value, $mark) : is_array($value) && self::holds($value, $mark)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text a node holds outside brackets and outside nodes of `$mark`, each text closed by `\0`: where its closers of
     * `$mark` are written bare (C2-I-055).
     *
     * @param array<mixed> $node
     * @param string $mark
     *
     * @return string
     */
    public static function openText(array $node, string $mark): string
    {
        $type = self::str($node, 'type');
        if ($type === 'text') {
            return self::str($node, 'value') . "\0";
        }
        if (in_array($type, self::BARRIERS, true) || (self::BRACED[$type] ?? self::PAIRED[$type] ?? '') === $mark) {
            return '';
        }
        $out = '';
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $out .= is_array($child) ? self::openText($child, $mark) : '';
        }

        return $out;
    }

    /**
     * The open texts (`openText()`) of `$nodes`, or null where one of them is a pair of `$mark`, whose bare spelling may
     * hold a closer of that mark (C2-I-057).
     *
     * @param array<mixed> $nodes
     * @param string $mark
     */
    public static function laterOpenText(array $nodes, string $mark): ?string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (self::spellsBareMark($node, $mark)) {
                return null;
            }
            $out .= self::openText($node, $mark);
        }

        return $out;
    }

    /**
     * Whether `$node` is or holds, outside brackets, an emphasis or strong of `$mark`.
     *
     * @param array<mixed> $node
     * @param string $mark
     */
    private static function spellsBareMark(array $node, string $mark): bool
    {
        $type = self::str($node, 'type');
        if ((self::PAIRED[$type] ?? '') === $mark) {
            return true;
        }
        if (in_array($type, self::BARRIERS, true)) {
            return false;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child) && self::spellsBareMark($child, $mark)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The mark a bare form of the type ends with, or null.
     *
     * @param string $type
     *
     * @return string|null
     */
    public static function bareMark(string $type): ?string
    {
        return self::PAIRED[$type] ?? null;
    }

    /**
     * The mark of a paired or braced span, whose content the writer writes at the path of its `children`.
     *
     * @param string $type
     *
     * @return string|null
     */
    public static function spanMark(string $type): ?string
    {
        return self::PAIRED[$type] ?? self::BRACED[$type] ?? null;
    }

    /**
     * Whether the writer knows how the type is spelled, so the brace test of `Text` can read past it.
     *
     * @param string $type
     *
     * @return bool
     */
    public static function known(string $type): bool
    {
        return isset(self::FIRST[$type]) || isset(self::BRACED[$type]) || isset(self::PAIRED[$type])
            || in_array($type, ['comment', 'critic_comment', 'hard_break', 'smart_punctuation', 'abbreviation'], true);
    }

    /**
     * C2-W-112: code spans with nothing between them are written as one span; each span behind the first is reported.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return array{0: string, 1: int}
     */
    private function codeRun(array $nodes, int $at, string $path, Context $c): array
    {
        $value = '';
        $end = $at;
        $last = [];
        for ($span = $nodes[$end] ?? null; is_array($span) && ($span['type'] ?? '') === 'code'; $span = $nodes[$end] ?? null) {
            if ($end > $at) {
                $this->w->lose($path . '/' . $end, 'code', null);
            }
            $value .= self::str($span, 'value');
            $last = $span;
            $end++;
            if (BraceBlock::of($span) !== '') {
                break;
            }
        }
        $out = self::codeSpan($value) . BraceBlock::of($last, ['pipes' => $c->inCell]);
        $this->moved($c, $out);

        return [$out, $end - 1];
    }

    /**
     * C2-W-112, C2-W-117, C2-W-124: a backtick run directly behind a code or math span with no attribute block joins its
     * closer. When the span behind opens with as many backticks as that closer, its own closer ends the joined span, so
     * the run goes on through every such span and ends at the first raw span or attribute block. It reads back as one
     * node: a math leader keeps its type and loses its value, a code run that ends in a raw span reads as that raw span,
     * and every other node of the run is lost. Only a table cell, whose pipes keep it from being read back on its own,
     * predicts the run here; other content is read back by `readBack`.
     *
     * @param array<mixed> $nodes
     * @param int $at
     * @param string $path
     * @param string $type
     *
     * @return void
     */
    private function loseGluedRun(array $nodes, int $at, string $path, string $type): void
    {
        $node = $nodes[$at] ?? null;
        if (($type !== 'code' && $type !== 'math') || !is_array($node) || BraceBlock::of($node) !== '') {
            return;
        }
        $spans = self::writtenSpans($nodes, $at);
        if (count($spans) < 2) {
            return;
        }
        [$leader] = $spans;
        if ($type === 'code') {
            if (count($leader['nodes']) === 1 || $spans[1]['width'] === $leader['width']) {
                // The leader's code spans are reported by `codeRun`; the raw span behind keeps the joined value.
                $this->w->lose($path . '/' . $at, 'code', null);
            }

            return;
        }
        $this->w->lose($path . '/' . $at, 'math', 'value');
        $width = $leader['width'];
        foreach (array_slice($spans, 1) as $k => $span) {
            if ($k > 0 && $span['width'] !== $width) {
                break;
            }
            foreach ($span['nodes'] as $index => $behind) {
                $this->w->lose($path . '/' . $index, $behind, null);
            }
            if ($span['closed'] || ($k === 0 && $span['width'] !== $width)) {
                break;
            }
            $width = $span['width'];
        }
    }

    /**
     * The spans the writer spells from `$at` on while each opens directly behind the closer before it: consecutive code
     * spans make one span, as `codeRun` writes them. Each has its fence width, its nodes and whether an attribute block
     * or a raw format ends it.
     *
     * @param array<mixed> $nodes
     * @param int $at
     *
     * @return list<array{width: int, nodes: array<int, string>, closed: bool}>
     */
    private static function writtenSpans(array $nodes, int $at): array
    {
        $spans = [];
        $end = $at;
        while (is_array($nodes[$end] ?? null)) {
            $type = self::str($nodes[$end], 'type');
            $allowed = $end === $at ? ['code', 'math'] : ['code', 'raw_inline'];
            if (!in_array($type, $allowed, true)) {
                break;
            }
            $value = '';
            $members = [];
            do {
                $value .= self::str($nodes[$end], 'value');
                $members[$end] = $type;
                $closed = $type === 'raw_inline' || BraceBlock::of($nodes[$end]) !== '';
                $end++;
            } while ($type === 'code' && !$closed && is_array($nodes[$end] ?? null) && self::str($nodes[$end], 'type') === 'code');
            $spans[] = ['width' => self::fenceWidth($value), 'nodes' => $members, 'closed' => $closed];
            if ($closed) {
                break;
            }
        }

        return $spans;
    }

    /**
     * The length of the backtick fence `codeSpan` writes around `$value`.
     *
     * @param string $value
     *
     * @return int
     */
    private static function fenceWidth(string $value): int
    {
        return strspn(self::codeSpan($value), '`');
    }

    /**
     * The nominal first character of the written form of `$node`, for the tests of the text before it.
     *
     * @param array<mixed> $node
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return int
     */
    public static function first(array $node, Context $c): int
    {
        $type = self::str($node, 'type');
        $text = match ($type) {
            'text' => self::str($node, 'value'),
            'smart_punctuation' => self::str($node, 'source'),
            'abbreviation' => self::str($node, 'key'),
            'hard_break' => $c->bareBreaks && !$c->lineStart ? "\n" : '\\',
            'comment' => ($node['form'] ?? '') === 'braced' ? '{' : ' ',
            default => self::FIRST[$type] ?? '{',
        };

        return $text === '' ? -1 : Chars::at($text, 0);
    }

    /**
     * C2-W-022: before a line break or at the end of the content a backslash and a space are a hard break, so the
     * character itself is written and the node is reported.
     *
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function noBreakSpace(string $path, Context $c, int $next): string
    {
        if ($c->inCell || ($next !== -1 && $next !== 10) || ($c->inLineBlock && $c->beforeHardBreak)) {
            return '\\ ';
        }
        $this->w->lose($path, 'non_breaking_space', null);

        return "\u{00A0}";
    }

    /**
     * The whole spelling of an inline node that is one fixed token, or null.
     *
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function atom(array $node): ?string
    {
        return match (self::str($node, 'type')) {
            'symbol' => ':' . self::str($node, 'name') . ':',
            'tag' => '#' . self::str($node, 'name'),
            'mention' => '@' . self::str($node, 'target'),
            'non_breaking_space' => '\\ ',
            'smart_punctuation' => self::str($node, 'source'),
            'abbreviation' => self::str($node, 'key'),
            'caption_number' => '#',
            default => null,
        };
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param string $out
     *
     * @return void
     */
    private function moved(Context $c, string $out): void
    {
        if ($out === '') {
            return;
        }
        $c->last = Chars::before($out, strlen($out));
        $c->lineStart = $c->blockLines && $out[strlen($out) - 1] === "\n";
        if ($c->lineStart) {
            $c->prevOpenedLine = $c->openedLine;
            $c->openedLine = null;
        }
    }

    // Runs for every inline node of every write; see contentOnce().
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param mixed $before
     *
     * @return string
     */
    private function node(array $node, string $path, Context $c, int $next, mixed $before): string
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        if ($type === 'text') {
            if (array_key_exists('dir', $node) || array_key_exists('metadata', $node)) {
                $this->loseTextFields($node, $path);
            }
            $points = $c->inCell ? ($this->w->wrapPoints[$path] ?? null) : null;
            $c->here = $points === null ? $c->flatAt[$path] ?? -1 : -1;
            $c->textPath = $path;
            $out = $points === null ? $this->text($node, $c, $next, $before) : $this->wrapped($node, $points, $c, $next);
            $c->here = -1;
            if ($points === null) {
                $c->runLog[] = new RunEntry($path, $out, $this->textPrefix, $c->escapedAt, $this->textValue, $c->escapeKinds);
            }
            // C2-W-016: a CR at the end of a line is part of the line ending.
            if (str_ends_with($out, "\r") && ($next === 10 || $next === -1)) {
                $this->w->lose($path, 'text', 'value');
            }
            $this->moved($c, $out);

            return $out;
        }
        $out = $this->own($node, $type, $path, $c, $next);
        // A traced write reports no losses, and a kind or pair round reported these in the first write of the leaf.
        if (!$this->rewrite && $this->w->repairTrace === null) {
            $this->loseEmptyContent($node, $path, $type);
        }
        if ($type === 'soft_break') {
            $c->runLog[] = new RunEntry($path, "\n", 1, [], '');
        }
        if ($out === null) {
            $this->w->lose($path, $type, null);
            $out = $this->children($node, $path, $c, $next, 'fallback');
        } elseif (!in_array($type, ['soft_break', 'hard_break', 'comment'], true)) {
            // C2-W-076: the attribute block is glued to its host. C2-I-040: a span takes the block with no part.
            $block = BraceBlock::of($node, ['pipes' => $c->inCell]);
            $out .= $type === 'span' && $block === '' ? '{}' : $block;
        }
        $this->moved($c, $out);

        return $this->w->repairTrace === null ? $out : $this->tracedNode($out, $path, $type);
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * C2-W-117: no parse gives an empty text, so content of only empty texts, or ending in a lone one, loses it.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $type
     *
     * @return void
     */
    private function loseEmptyContent(array $node, string $path, string $type): void
    {
        $children = $node['children'] ?? null;
        if (!is_array($children) || $children === []) {
            return;
        }
        $children = array_values($children);
        $lead = $children[0];
        $leadType = is_array($lead) && is_string($lead['type'] ?? null) ? $lead['type'] : '';
        // A first child that writes something of its own leaves the content nonempty (`emptyNested()`).
        if (
            !is_array($lead) || ($leadType === 'text' ? ($lead['value'] ?? '') !== ''
                : !isset(self::PAIRED[$leadType]) && !isset(self::BRACED[$leadType]))
        ) {
            $this->forgetEmptyAt($children, strlen($path));
            $only = false;
        } else {
            $slash = strrpos($path, '/');
            $only = $this->onlyEmptyAt($children, strlen($path), strlen($path) . '/' . substr($path, $slash === false ? 0 : $slash + 1));
        }
        if ($only || self::emptyTextRun($children, count($children) - 1) !== null) {
            $this->w->lose($path, $type, null);
        }
    }

    /**
     * `onlyEmptyText()` with the answer kept by node: the content of a deep nest is written inside out, so each level
     * reads the answer of the level below instead of walking the nest again. A node is keyed by the length of its
     * path and its index, not by the path, which grows with the depth.
     *
     * @param array<mixed> $children
     * @param int $length The length of the node's path.
     * @param string $key
     *
     * @return bool
     */
    private function onlyEmptyAt(array $children, int $length, string $key): bool
    {
        $kept = $this->emptyContent[$key] ?? null;
        // The answer depends on the content alone, and a kept one is read only for an equal content.
        if ($kept !== null && $kept[0] === $children) {
            return $kept[1];
        }
        $nested = self::emptyNested($children);
        $only = $nested !== null;
        if ($only) {
            foreach ($nested as $k => $inner) {
                // The path of a child is the node's path, `/children/` and its index.
                $childLength = $length + 10 + strlen((string)$k);
                if (!is_array($inner) || !$this->onlyEmptyAt($inner, $childLength, $childLength . '/' . $k)) {
                    $only = false;

                    break;
                }
            }
        }
        $this->emptyContent[$key] = [$children, $only];
        $this->forgetEmptyAt($children, $length);

        return $only;
    }

    /**
     * Each answer of `onlyEmptyAt()` is read once, by the level above; a kept one would hold its replaced content alive.
     *
     * @param array<mixed> $children
     * @param int $length The length of the node's path.
     */
    private function forgetEmptyAt(array $children, int $length): void
    {
        if ($this->emptyContent === []) {
            return;
        }
        foreach ($children as $k => $child) {
            $childLength = $length + 10 + strlen((string)$k);
            unset($this->emptyContent[$childLength . '/' . $k]);
        }
    }

    /**
     * The flat test of `onlyEmptyText()`: null where a child writes something of its own, else the content of each
     * child that holds some, by key.
     *
     * @param array<mixed> $children
     *
     * @return array<mixed>|null
     */
    private static function emptyNested(array $children): ?array
    {
        if ($children === []) {
            return null;
        }
        $nested = [];
        foreach ($children as $k => $child) {
            if (!is_array($child)) {
                return null;
            }
            $type = is_string($child['type'] ?? null) ? $child['type'] : '';
            if ($type === 'text' ? ($child['value'] ?? '') !== '' : !isset(self::PAIRED[$type]) && !isset(self::BRACED[$type])) {
                return null;
            }
            if (($child['children'] ?? null) !== []) {
                $nested[$k] = $child['children'] ?? null;
            }
        }

        return $nested;
    }

    /**
     * Content that writes nothing: empty texts, and nodes whose own content is empty.
     *
     * @param mixed $children
     * @param \Closure(mixed, int|string): bool|null $inside The test of the content of a child, by its key.
     *
     * @return bool
     */
    private static function onlyEmptyText(mixed $children, ?Closure $inside = null): bool
    {
        if (!is_array($children) || $children === []) {
            return false;
        }
        // The flat test first, so a deep nest is not walked again at every level.
        $nested = [];
        foreach ($children as $k => $child) {
            if (!is_array($child)) {
                return false;
            }
            $type = is_string($child['type'] ?? null) ? $child['type'] : '';
            if ($type === 'text') {
                if (($child['value'] ?? '') !== '') {
                    return false;
                }

                continue;
            }
            // An empty link or span still writes its brackets.
            if (!isset(self::PAIRED[$type]) && !isset(self::BRACED[$type])) {
                return false;
            }
            if (($child['children'] ?? null) !== []) {
                $nested[$k] = $child['children'] ?? null;
            }
        }

        return self::allEmpty($nested, $inside);
    }

    /**
     * @param array<mixed> $nested The content of each child that holds some, by the child's key.
     * @param \Closure(mixed, int|string): bool|null $inside
     *
     * @return bool
     */
    private static function allEmpty(array $nested, ?Closure $inside): bool
    {
        $inside ??= static fn (mixed $inner): bool => self::onlyEmptyText($inner);
        foreach ($nested as $k => $inner) {
            if (!$inside($inner, $k)) {
                return false;
            }
        }

        return true;
    }

    private function tracedNode(string $out, string $path, string $type): string
    {
        return $this->w->repairTrace?->inlineNode($out, $path, $type) ?? $out;
    }

    /**
     * C2-L-020: a cell text broken by continuation rows; the joining space before each fragment is the line break.
     *
     * @param array<mixed> $node
     * @param array<int> $points
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function wrapped(array $node, array $points, Context $c, int $next): string
    {
        $value = self::str($node, 'value');
        $out = '';
        $from = 0;
        $at = $c->flatAt[$c->textPath] ?? -1;
        sort($points);
        foreach ($points as $point) {
            if ($point < $from || $point > strlen($value)) {
                continue;
            }
            $piece = substr($value, $from, max(0, $point - 1 - $from));
            $c->here = $at >= 0 ? $at + $from : -1;
            $c->fragmentFrom = $from;
            // C2-W-146: each fragment is a content of its own (C2-B-223) and takes the pair escapes too.
            $out .= Text::escapePairs($piece, $c, 10) . "\n";
            $c->last = 10;
            $from = $point;
        }
        $c->here = $at >= 0 ? $at + $from : -1;
        $c->fragmentFrom = $from;
        $out .= Text::escapePairs(substr($value, $from), $c, $next);
        $c->fragmentFrom = 0;

        return $out;
    }

    /**
     * A text node has no carrier for these fields. Check every node, even behind another loss.
     *
     * @param array<string, mixed> $node
     * @param string $path
     */
    private function loseTextFields(array $node, string $path): void
    {
        foreach (['dir', 'metadata'] as $field) {
            if (array_key_exists($field, $node)) {
                $this->w->lose($path, 'text', $field);
            }
        }
    }

    /**
     * C2-I-002: a reader drops the spaces and tabs that start a line. Text of only them is dropped without a report
     * where it also ends the line (C2-W-151), not where more of the line follows it.
     *
     * @param string $value
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     */
    private function reportLeadingValueLoss(string $value, Context $c, int $next): void
    {
        if (
            $c->lineStart && ($c->last === -1 || $c->last === 10) && !$c->inLineBlock && !$c->inCell
            && strspn($value, " \t") > 0 && (trim($value, " \t") !== '' || ($next !== 10 && $next !== -1))
        ) {
            $this->w->lose($c->textPath, 'text', 'value');
        }
    }

    /**
     * C2-I-015, C2-W-125 case six: a text that starts with a backtick right behind a code, literal or math span without
     * attributes would lengthen that span's closing run.
     *
     * @param string $value
     * @param mixed $before
     */
    public static function gluesTick(string $value, mixed $before): bool
    {
        return ($value[0] ?? '') === '`' && is_array($before) && !isset($before['attrs'])
            && in_array($before['type'] ?? '', ['code', 'literal_inline', 'math'], true);
    }

    /**
     * C2-W-125 case six: the glued backtick takes its backslash in every write of the rounds, their trials too
     * (carve-js `escapeGluedBacktick`). The trace records that its write placed this or a text prefix.
     *
     * @param string $prefix
     * @param string $value
     * @param mixed $before
     * @param \MarkupCarve\Carve\Writer\Context $c
     */
    private static function traceGluedTick(string $prefix, string $value, mixed $before, Context $c): void
    {
        if ($prefix !== '' && $c->writerState?->repairTrace !== null) {
            $c->writerState->repairTrace->repaired = true;
        }
        if ($prefix === '' && self::gluesTick($value, $before)) {
            $c->writerState?->repairTrace?->escapeAt($c->textPath, 0);
        }
    }

    /**
     * @param array<mixed> $node
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param mixed $before
     *
     * @return string
     */
    private function text(array $node, Context $c, int $next, mixed $before): string
    {
        $value = is_string($node['value'] ?? null) ? $node['value'] : '';
        if ($c->lineStart) {
            $this->reportLeadingValueLoss($value, $c, $next);
        }
        // C2-W-117: outside a cell a line feed in text reads back as a soft break, so the value does not survive.
        if (!$c->inCell && str_contains($value, "\n")) {
            $this->w->lose($c->textPath, 'text', 'value');
        }
        // C2-W-022: no space or tab of text stands at the end of a line.
        if ($next === 10 && !$c->bareBreaks) {
            $value = rtrim($value, " \t");
        } elseif ($next === -1) {
            $value = rtrim($value, " \t");
        }
        $prefix = '';
        // C2-W-125 case six: a name character behind a mention or a tag would lengthen its name.
        if (
            is_array($before) && in_array($before['type'] ?? '', ['mention', 'tag'], true) && $value !== ''
            && str_contains('_-.', $value[0]) && self::lengthens(self::str($before, $before['type'] === 'tag' ? 'name' : 'target'), $value)
        ) {
            $prefix = '\\' . $value[0];
            $value = substr($value, 1);
            $c->here += $c->here >= 0 ? 1 : 0;
            $c->last = Chars::at($prefix, 1);
            $c->lineStart = false;
        }
        // C2-I-117: `+-` needs its exact run, so a `+` or `-` behind it takes the backslash.
        $plusMinus = is_array($before) && ($before['type'] ?? '') === 'smart_punctuation' && ($before['source'] ?? '') === '+-';
        if ($plusMinus && $value !== '' && str_contains('+-', $value[0])) {
            $prefix .= '\\' . $value[0];
            $value = substr($value, 1);
            $c->here += $c->here >= 0 ? 1 : 0;
            $c->last = Chars::at($prefix, strlen($prefix) - 1);
        }
        // C2-W-094: a parenthesis glued behind a closing bracket would read as a link tail, unless that bracket ends a
        // full or collapsed reference, a footnote reference or an inline footnote with no bracket inside, which take no
        // tail (C2-I-066, C2-I-072).
        if ($c->last === 0x5D && str_starts_with($value, '(') && self::tailCanFollow($before) && self::tailCloses($c)) {
            $prefix .= '\\(';
            $value = substr($value, 1);
            $c->here += $c->here >= 0 ? 1 : 0;
            $c->last = 0x28;
        }

        if ($c->writerState?->repairTrace !== null) {
            self::traceGluedTick($prefix, $value, $before, $c);
        }
        $this->textPrefix = strlen($prefix);
        $this->textValue = $value;

        return $prefix . Text::escapePairs($value, $c, $next);
    }

    /**
     * Whether a link tail can follow the closing bracket that ends the written form of `$before`.
     */
    private static function tailCanFollow(mixed $before): bool
    {
        if (!is_array($before)) {
            return true;
        }

        return match ($before['type'] ?? '') {
            // C2-I-066: the bracket of an inline extension is no link text, so no tail follows it.
            'footnote_ref', 'inline_extension' => false,
            // Bare, a bracket inside the note pairs with its closer, and the tail makes a link of that pair.
            'inline_footnote' => self::holds($before, '['),
            'link', 'image' => !is_string($before['key'] ?? null) || ($before['refForm'] ?? 'full') === 'shortcut',
            default => true,
        };
    }

    /**
     * C2-I-066 to C2-I-069: whether the `(` of the text at `$c->here` opens a link tail that closes on its line; where
     * the leaf's offsets are unknown, it may.
     *
     * @param \MarkupCarve\Carve\Writer\Context $c
     */
    private static function tailCloses(Context $c): bool
    {
        return $c->flat === null || $c->here < 0 || AltSource::tailEnd($c->flat, $c->here) >= 0;
    }

    /**
     * C2-I-092: whether the name a reader takes from the name and the text behind it is longer than the name. A name is
     * runs of letters, digits, `_` and `-` joined by single dots, and a trailing dot or hyphen is text.
     *
     * @param string $name
     * @param string $value
     *
     * @return bool
     */
    private static function lengthens(string $name, string $value): bool
    {
        $whole = $name . $value;
        $run = strspn($whole, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.');
        if ($run < strlen($whole) && Text::endsNoName(Chars::at($whole, $run))) {
            return true;
        }
        preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*/', $whole, $m);

        return strlen(rtrim($m[0] ?? '', '.-')) > strlen($name);
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     * @param string $slot
     *
     * @return string
     */
    private function children(array $node, string $path, Context $c, int $next, string $slot = 'children'): string
    {
        $nodes = is_array($node[$slot] ?? null) ? $node[$slot] : (is_array($node['children'] ?? null) ? $node['children'] : []);
        if ($slot === 'fallback' && !is_array($node['fallback'] ?? null)) {
            $slot = 'children';
        }

        return $this->content($nodes, $path . '/' . $slot, $c, $next);
    }

    /**
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string|null
     */
    private function own(array $node, string $type, string $path, Context $c, int $next): ?string
    {
        if (isset(self::PAIRED[$type])) {
            return $this->paired($node, $type, $path, $c, $next);
        }
        if (isset(self::BRACED[$type])) {
            return $this->braced($node, self::BRACED[$type], $path, $c);
        }

        return $this->spelledFromFields($node, $type, $path, $c, $next) ?? $this->spelledWithContent($node, $type, $path, $c, $next);
    }

    /**
     * C2-W-116: the nodes whose one spelling is made of their fields.
     *
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next First character written behind the node, -1 at the end of the content.
     *
     * @return string|null
     */
    private function spelledFromFields(array $node, string $type, string $path, Context $c, int $next): ?string
    {
        if ($type === 'soft_break' || $type === 'hard_break') {
            $this->loseBreakInLine($type, $path, $c, $next);
        }

        return match ($type) {
            'soft_break' => "\n",
            'hard_break' => $c->bareBreaks && !$c->lineStart ? "\n" : "\\\n",
            'footnote_ref' => $this->footnoteRef(self::str($node, 'key'), $path, $c),
            'symbol' => $this->sigil($type, $path, $next, ':' . self::str($node, 'name') . ':'),
            'tag' => $this->sigil($type, $path, $next, '#' . self::str($node, 'name')),
            'non_breaking_space' => $this->noBreakSpace($path, $c, $next),
            'smart_punctuation' => $this->smart($node, $path),
            // C2-W-116: the mark of a caption number (C2-B-150); its number is a resolution result.
            'caption_number' => '#',
            'mention' => $this->sigil($type, $path, $next, $this->mention($node, $path)),
            'xref' => $this->xref($node, $path),
            'abbreviation' => $this->abbreviation($node, $path),
            default => null,
        };
    }

    /**
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string|null
     */
    private function spelledWithContent(array $node, string $type, string $path, Context $c, int $next): ?string
    {
        return match ($type) {
            'link' => $this->link($node, $path, $c),
            'image' => $this->image($node, $path, $c),
            'span' => $this->bracketed('[', $node, $path, $c),
            'inline_footnote' => $this->bracketed('^[', $node, $path, $c),
            'code' => $this->spanValue($node, $path, $c, ''),
            'literal_inline' => $this->spanValue($node, $path, $c, '!'),
            'math' => $this->spanValue($node, $path, $c, ($node['display'] ?? false) === true ? '$$' : '$'),
            'raw_inline' => $this->spanValue($node, $path, $c, '') . '{=' . self::str($node, 'format') . '}',
            'autolink' => $this->autolink($node, $path),
            'comment' => $this->comment($node, $path, $c, $next),
            'critic_comment' => $this->criticComment($node, $path, $c),
            default => $this->extensionOrCitation($node, $type, $path, $c, $next),
        };
    }

    /**
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string|null
     */
    private function extensionOrCitation(array $node, string $type, string $path, Context $c, int $next): ?string
    {
        if ($type === 'inline_extension') {
            return $this->extension($node, $path, $c, $next);
        }

        return $type === 'citation_group' ? $this->citations($node, $path) : null;
    }

    /**
     * @param array<mixed> $node
     * @param string $field
     * @param string $default
     *
     * @return string
     */
    public static function strOr(array $node, string $field, string $default): string
    {
        return is_string($node[$field] ?? null) ? $node[$field] : $default;
    }

    /**
     * @param array<mixed> $node
     * @param string $field
     *
     * @return string
     */
    public static function str(array $node, string $field): string
    {
        return is_string($node[$field] ?? null) ? $node[$field] : '';
    }

    /**
     * C2-W-069, C2-W-070: emphasis and strong, bare where that reads back and braced otherwise.
     *
     * @param array<mixed> $node
     * @param string $type
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function paired(array $node, string $type, string $path, Context $c, int $next): string
    {
        $mark = self::PAIRED[$type];
        // The attached attribute block immediately follows this closer; the next sibling follows the block.
        if (isset($node['attrs']) && BraceBlock::of($node) !== '') {
            $next = 0x7B;
        }
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children === []) {
            $this->w->lose($path, $type, null);

            return '';
        }
        $prev = $c->last;
        $c->last = ord($mark);
        $c->lineStart = false;
        $open = $c->open;
        $marks = $c->marks;
        $c->open[$type] = ($c->open[$type] ?? 0) + 1;
        $c->marks .= $mark;
        $spanTexts = $c->spanTexts;
        // Only the escape decisions read the facts, and a traced write spells its texts bare.
        if ($c->writerState?->repairTrace === null) {
            $braced = isset($open[$type]) || self::bracedAlways($node, $prev, $next, $path . '/children', $this->bracedPaired);
            // A span not braced whatever its texts is written bare; where it still takes the braced form, the
            // read-back reports a closer its texts left bare (C2-W-069, C2-W-125).
            $behind = $braced ? 0x7D : $next;
            self::noteSpanTexts($children, $path . '/children', $mark, $c, $braced, $behind);
        }
        $inner = $this->content($children, $path . '/children', $c, ord($mark));
        $c->spanTexts = $spanTexts;
        $c->open = $open;
        $c->marks = $marks;
        $visibleInner = $this->w->repairTrace?->decode($inner)['source'] ?? $inner;
        $bare = !isset($open[$type])
            && !self::holdsKind($children, $type)
            && !self::holdsMark($children, $mark, true, $path . '/children', $this->bracedPaired)
            && self::bareFits($visibleInner, $mark, $prev, $next);
        if ($bare) {
            unset($this->bracedPaired[$path]);

            return $mark . $inner . $mark;
        }
        $this->bracedPaired[$path] = true;

        return '{' . $mark . $inner . $mark . '}';
    }

    /**
     * The facts the escape of `{` reads in the text children of a span, which have no place in the flattened leaf.
     *
     * @param array<mixed> $children
     * @param string $path
     * @param string $mark
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param bool $braced Whether the span is written braced whatever its escapes.
     * @param int $next The character behind the span's bare closer.
     *
     * @return void
     */
    private static function noteSpanTexts(
        array $children,
        string $path,
        string $mark,
        Context $c,
        bool $braced = true,
        int $next = 0x7D,
    ): void {
        $last = count($children) - 1;
        foreach ($children as $k => $child) {
            if (is_array($child) && ($child['type'] ?? '') === 'text') {
                $c->spanTexts[$path . '/' . $k] = [$mark, $k < $last, $k > 0, $braced, $next, array_slice($children, $k + 1)];
            }
        }
    }

    /**
     * C2-I-047 to C2-I-050: the bare marks open and close at their places and are no run.
     *
     * @param string $inner
     * @param string $mark
     * @param int $prev
     * @param int $next
     *
     * @return bool
     */
    private static function bareFits(string $inner, string $mark, int $prev, int $next): bool
    {
        if ($inner === '' || $inner[0] === $mark || ($inner[strlen($inner) - 1] === $mark && !self::bracedOpenerEnd($inner))) {
            return false;
        }
        $first = Chars::at($inner, 0);
        $last = Chars::before($inner, strlen($inner));
        if (Chars::isSpace($first) || Chars::isGlue($first) || Chars::isSpace($last) || $first === 0x5C && ($inner[1] ?? '') === ' ') {
            return false;
        }
        if ($prev === ord($mark) || Chars::isWord($prev) || $next === ord($mark) || Chars::isWord($next) || Chars::isGlue($next)) {
            return false;
        }

        // An even run spells escaped backslashes and leaves the closer active.
        $slashes = 0;
        for ($i = strlen($inner) - 1; $i >= 0 && $inner[$i] === '\\'; $i--) {
            $slashes++;
        }

        return $slashes % 2 === 0;
    }

    /**
     * C2-W-069, C2-W-070: whether an emphasis or strong `$node` behind the character `$before` takes its braced form
     * whatever its texts are escaped to: beside a word character or its own mark, with content that starts or ends with
     * its mark, a space or a joining mark, or that holds its own kind or its mark where it pairs.
     *
     * @param array<mixed> $node
     * @param int $before
     * @param int $after The character behind the node, or -1 where it is not known.
     * @param string|null $path The path of the node's children, to look up `$braced`; null where it is not known.
     * @param array<string, true> $braced The emphasis and strong nodes written braced.
     *
     * @return bool
     */
    public static function bracedAlways(array $node, int $before, int $after = -1, ?string $path = null, array $braced = []): bool
    {
        $type = self::str($node, 'type');
        $mark = self::PAIRED[$type] ?? null;
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($mark === null || $children === []) {
            return false;
        }
        $code = ord($mark);
        if ($before === $code || Chars::isWord($before) || $after === $code || Chars::isWord($after) || Chars::isGlue($after)) {
            return true;
        }
        $first = $children[0];
        $last = $children[count($children) - 1];
        $head = is_array($first) && self::str($first, 'type') === 'text' ? self::str($first, 'value') : '';
        $tail = is_array($last) && self::str($last, 'type') === 'text' ? self::str($last, 'value') : '';
        if ($head !== '') {
            $code = Chars::at($head, 0);
            if ($head[0] === $mark || Chars::isSpace($code) || Chars::isGlue($code)) {
                return true;
            }
        }
        $end = strlen($tail) - 1;
        $markEnd = $end >= 0 && $tail[$end] === $mark && ($end === 0 || $tail[$end - 1] !== '{');
        if ($markEnd || ($end >= 0 && Chars::isSpace(Chars::before($tail, $end + 1)))) {
            return true;
        }
        // Without the paths of the spans written braced, only a mark that can pair counts (C2-I-053).
        $holds = $path === null ? self::holdsMark($children, $mark, false) : self::holdsMark($children, $mark, true, $path, $braced);

        return $holds || self::holdsKind($children, $type);
    }

    /**
     * C2-I-055: whether the written content ends with a bare `{` and a mark, the mark of a braced opener, which is no
     * delimiter that pairs with the closer behind it.
     */
    private static function bracedOpenerEnd(string $inner): bool
    {
        $n = strlen($inner);
        if ($n < 2 || $inner[$n - 2] !== '{') {
            return false;
        }
        $slashes = strspn(strrev(substr($inner, 0, $n - 2)), '\\');

        return $slashes % 2 === 0;
    }

    /**
     * Whether the run of `$run` marks at `$at` of the text `$nodes[$k]` is a brace's: the second mark of an empty pair
     * `{//}`, which takes its own closer and forms nothing beside any node (C2-I-043), or a bare brace's that no closer
     * takes (C2-I-055, C2-I-058). A node behind the text that spells no bare `$mark` keeps it, and its texts may hold
     * the closer.
     *
     * @param array<mixed> $nodes
     * @param string|int $k
     * @param string $value
     * @param int $at
     * @param int $run
     * @param string $mark
     */
    private static function braceKeepsMark(array $nodes, string|int $k, string $value, int $at, int $run, string $mark): bool
    {
        if ($nodes === []) {
            return false;
        }
        if ($run === 2 && $at > 0 && $value[$at - 1] === '{' && ($value[$at + 2] ?? '') === '}') {
            return true;
        }
        $later = $k === array_key_last($nodes) ? '' : self::laterOpenText(array_slice($nodes, (int)$k + 1), $mark);
        if ($later === null) {
            return false;
        }

        return self::bareBraceTakes($value . ($later === '' ? '' : "\0" . $later), $at, $run, $mark);
    }

    /**
     * Whether the run of `$run` marks at `$at` belongs to a bare brace before it that no later closer gives a backslash:
     * one mark is that opener's (C2-I-060), and in `{**}` the second closes it holding nothing (C2-I-043).
     */
    private static function bareBraceTakes(string $value, int $at, int $run, string $mark): bool
    {
        if ($at === 0 || $value[$at - 1] !== '{' || $run > 2 || ($run === 2 && ($value[$at + 2] ?? '') !== '}')) {
            return false;
        }

        return !Text::bracedCloserPairs(substr($value, $at + $run), $mark);
    }

    /**
     * C2-W-070: the content holds its own kind.
     *
     * @param array<mixed> $nodes
     * @param string $type
     *
     * @return bool
     */
    private static function holdsKind(array $nodes, string $type): bool
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === $type || (is_array($node['children'] ?? null) && self::holdsKind($node['children'], $type))) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-W-070: the content holds its own delimiter where it can open, close or stand between two word characters.
     * A bracket bounds the delimiter stack, so the text inside one does not count, and a mark between two word
     * characters inside a braced span is text for the pair around it (C2-I-053). A mark between two spaces and a run of
     * two (C2-I-049) are text, unless a brace beside the run takes an escape that leaves one mark alone.
     *
     * @param array<mixed> $nodes
     * @param string $mark
     * @param bool $words Whether a mark between two word characters counts.
     * @param string $path The path of `$nodes`, to look up `$braced`.
     * @param array<string, true> $braced The emphasis and strong nodes written braced: a braced closer drops the marks
     *   between word characters its span held (C2-I-053).
     * @param bool $nested Whether `$nodes` is the content of a span inside the content tested. Its closer drops the
     *   openers opened in it (C2-I-047), so there only a mark that can close counts.
     *
     * @return bool
     */
    public static function holdsMark(
        array $nodes,
        string $mark,
        bool $words = true,
        string $path = '',
        array $braced = [],
        bool $nested = false,
    ): bool {
        foreach ($nodes as $k => $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = self::str($node, 'type');
            if ($type === 'text') {
                $value = self::str($node, 'value');
                $n = strlen($value);
                for ($at = strpos($value, $mark); $at !== false; $at = strpos($value, $mark, $at + 1)) {
                    $run = strspn($value, $mark, $at);
                    if ($run > 1 && ($at === 0 || $value[$at - 1] !== '{')) {
                        $at += $run - 1;

                        continue;
                    }
                    if (self::braceKeepsMark($nodes, $k, $value, $at, $run, $mark)) {
                        $at += $run - 1;

                        continue;
                    }
                    $before = $at > 0 ? Chars::before($value, $at) : 0x20;
                    $after = $at + 1 < $n ? Chars::at($value, $at + 1) : 0x20;
                    $counts = Chars::isWord($before) && Chars::isWord($after)
                        ? $words
                        : !(Chars::isSpace($before) && Chars::isSpace($after))
                        && (!$nested || (!Chars::isSpace($before) && !Chars::isWord($after)));
                    if ($counts) {
                        return true;
                    }
                }
            } elseif (self::tailHoldsMark($node, $mark, $words)) {
                return true;
            } elseif (!in_array($type, self::BARRIERS, true) && is_array($node['children'] ?? null)) {
                $at = $path . '/' . $k . '/children';
                $wordsInside = $words && !isset(self::BRACED[$type]) && !isset($braced[$path . '/' . $k]);
                if (self::holdsMark($node['children'], $mark, $wordsInside, $at, $braced, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     * @param string $mark
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function braced(array $node, string $mark, string $path, Context $c): string
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children === []) {
            $this->w->lose($path, self::str($node, 'type'), null);

            return '';
        }
        $c->last = ord($mark);
        $c->lineStart = false;
        $marks = $c->marks;
        $c->marks .= $mark;
        $spanTexts = $c->spanTexts;
        // Only the escape decisions read the facts, and a traced write spells its texts bare.
        if ($c->writerState?->repairTrace === null) {
            self::noteSpanTexts($children, $path . '/children', $mark, $c);
        }
        $inner = $this->content($children, $path . '/children', $c, ord($mark));
        $c->spanTexts = $spanTexts;
        $c->marks = $marks;

        return '{' . $mark . $inner . $mark . '}';
    }

    /**
     * Content between brackets, as a span or an inline footnote (C2-W-116).
     *
     * @param string $open
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function bracketed(string $open, array $node, string $path, Context $c): string
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $afterBang = $this->spanAfterBang;
        $note = $open === '^[';
        $this->noteDepth += $note ? 1 : 0;
        try {
            $inner = $this->inside($children, $path . '/children', $c, !$note);
        } finally {
            $this->noteDepth -= $note ? 1 : 0;
        }
        // C2-W-125 case five: a caret at the start of span text would read as a footnote reference, except behind a
        // bare `!` and inside an inline footnote.
        if (
            !$note && !$afterBang && $this->noteDepth === 0 && self::leadsWithCaretText($children)
            && str_starts_with($this->w->repairTrace?->decode($inner)['source'] ?? $inner, '^')
        ) {
            $inner = '\\' . $inner;
        }

        return $open . $inner . ']';
    }

    /**
     * Whether a span whose text starts with a caret follows a bare `!` in `$out`.
     *
     * @param array<mixed> $node
     * @param string $out
     *
     * @return bool
     */
    private function followsBareBang(array $node, string $out): bool
    {
        if (!str_ends_with($out, '!') || !self::leadsWithCaretText(is_array($node['children'] ?? null) ? $node['children'] : [])) {
            return false;
        }
        $out = $this->w->repairTrace?->decode($out)['source'] ?? $out;
        $at = strlen($out) - 1;
        $slashes = 0;
        while ($at - $slashes - 1 >= 0 && $out[$at - $slashes - 1] === '\\') {
            $slashes++;
        }

        return $slashes % 2 === 0;
    }

    /**
     * C2-W-125 case five escapes the caret of text only: an inline footnote that starts the content stays one.
     *
     * @param array<mixed> $children
     *
     * @return bool
     */
    private static function leadsWithCaretText(array $children): bool
    {
        $first = $children[0] ?? null;

        return is_array($first) && ($first['type'] ?? null) === 'text' && is_string($first['value'] ?? null)
            && str_starts_with($first['value'], '^');
    }

    /**
     * @param array<mixed> $children
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param bool $notes Whether a footnote can form in the content: not inside an inline footnote.
     *
     * @return string
     */
    private function inside(array $children, string $path, Context $c, bool $notes = true): string
    {
        // The copy must not share the leaf's run log: the content below appends to it, and a shared log is copied in
        // full on each append, once per bracketed node of the leaf.
        // A code span reads over the bracket, so the backtick plan of the leaf around it stays (C2-W-088).
        if ($c->flat !== null && $c->leafNodes !== null) {
            Text::hasTickPlan($c);
        }
        $log = $c->runLog;
        $c->runLog = [];
        $outer = clone $c;
        $c->runLog = $log;
        unset($log);
        $bracket = $c->inBracket;
        $marks = $c->marks;
        $pairs = $c->bracketPairs;
        $c->inBracket = true;
        // C2-I-089: inside an inline footnote, at any depth, `[^` and `^[` are ordinary brackets.
        // C2-I-064: a brace behind a pair opens no attribute block where no `}` stands anywhere in the leaf. A nested
        // bracket reads the answer of its top-level leaf, which is found once per leaf.
        $braces = $bracket ? $c->leafBraces ?? true : $c->leafBraces ??= $c->leafNodes === null || self::mayWriteBrace($c->leafNodes);
        $c->bracketPairs = self::pairedBrackets($children, $path, $c->labels, $notes && $this->noteDepth === 0, $braces);
        // A bracket is a barrier of the delimiter stack: no closer inside it reaches a node outside.
        $c->marks = '';
        $c->last = 0x5B;
        $c->lineStart = false;
        // A bracketed list is its own escape leaf. Its text offsets cannot use the surrounding list's flattened text.
        $c->flat = null;
        $c->flatAt = [];
        $c->flatTypes = [];
        $c->flatFirst = [];
        $c->flatAtoms = [];
        $c->flatBraces = [];
        $c->flatNodes = [];
        $c->closers = null;
        $c->partners = null;
        $inner = $this->content($children, $path, $c, 0x5D);
        $c->flat = $outer->flat;
        $c->flatAt = $outer->flatAt;
        $c->flatTypes = $outer->flatTypes;
        $c->flatFirst = $outer->flatFirst;
        $c->flatAtoms = $outer->flatAtoms;
        $c->flatBraces = $outer->flatBraces;
        $c->flatNodes = $outer->flatNodes;
        $c->closers = $outer->closers;
        $c->partners = $outer->partners;
        $c->leafNodes = $outer->leafNodes;
        $c->leafPath = $outer->leafPath;
        $c->tickPlan = $outer->tickPlan;
        $c->tickAt = $outer->tickAt;
        $c->tickForced = $outer->tickForced;
        $c->inBracket = $bracket;
        $c->bracketPairs = $pairs;
        $c->marks = $marks;

        return $inner;
    }

    /**
     * Pair brackets in text across emphasis and other unbracketed nodes. Nested bracketed constructs own their pairs.
     * C2-W-094: a pair inside one text that forms a construct of its own (its label resolves, a link tail, label or
     * brace block follows, or a caret makes it a footnote) is no balanced text and keeps its backslashes.
     * C2-I-118: where the brackets inside reach the depth cap with the bracket around them, none is paired.
     *
     * @param array<mixed> $nodes
     * @param string $path
     * @param array<string, true> $labels
     * @param bool $notes
     * @param bool $braces Whether a `}` may stand in the written leaf (`mayWriteBrace()`).
     *
     * @return array<string, array<int, true>>
     */
    private static function pairedBrackets(array $nodes, string $path, array $labels, bool $notes, bool $braces = true): array
    {
        // No pair forms without an opening bracket.
        if (!self::holds($nodes, '[')) {
            return [];
        }
        $open = [];
        $nested = [];
        $paired = [];
        $deepest = 0;
        $walk = ['followed' => [], 'last' => null];
        self::pairWalk($nodes, $path, $open, $nested, $paired, $labels, $notes, $deepest, $walk);
        foreach ($walk['followed'] as [$key, $offset, $close, $value]) {
            $literal = $value[$close + 1] === '['
                ? self::blankLabel($value, $close + 2, $key === $walk['last'])
                : !$braces;
            if ($literal) {
                $paired[$key][$offset] = true;
                $paired[$key][$close] = true;
            }
        }

        return $deepest + 1 >= Tables::INLINE_DEPTH ? [] : $paired;
    }

    /**
     * Whether a `}` may stand in the written leaf: in its strings, in an attribute block, or in the braced form or the
     * syntax of a node other than text, a break, a link or an inline footnote.
     *
     * @param array<mixed> $nodes
     */
    private static function mayWriteBrace(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? '';
            // A text of its value alone, as most are.
            if ($type === 'text' && count($node) === 2 && is_string($node['value'] ?? null)) {
                if (str_contains($node['value'], '}')) {
                    return true;
                }

                continue;
            }
            if (
                !in_array($type, ['text', 'soft_break', 'hard_break', 'link', 'inline_footnote'], true)
                || isset($node['attrs']) || self::holds($node, '}', 'children')
                || (is_array($node['children'] ?? null) && self::mayWriteBrace($node['children']))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-I-072, C2-I-079: whether the label a `[` glued behind a pair opens at `$at` is blank, so the pair forms no full
     * or collapsed reference and stays text (carve-js `referenceReading`). The bare label ends at the first `]`, or at
     * the closer of the bracket where the text is the last of its content; a comment or a later node leaves it open.
     */
    private static function blankLabel(string $value, int $at, bool $last): bool
    {
        $end = strcspn($value, ']%', $at) + $at;
        if (($value[$end] ?? '') === '%' || ($end === strlen($value) && !$last)) {
            return false;
        }

        return $end > $at && strspn($value, " \t\n", $at, $end - $at) === $end - $at;
    }

    /**
     * The walk of `pairedBrackets()`. A method, not a closure that captures itself: that closure is a reference cycle,
     * and one per bracketed node held a long paragraph's memory until the cycle collector ran (carve-php#5722).
     *
     * @param array<mixed> $list
     * @param string $base
     * @param array<int, array{string, int}> $open
     * @param array<int, bool> $nested
     * @param array<string, array<int, true>> $paired
     * @param array<string, true> $labels
     * @param bool $notes
     * @param int $deepest The most brackets open at once so far.
     * @param array{followed: array<array{string, int, int, string}>, last: string|null} $walk The pairs that form only
     *   by the bracket or brace behind them, and the last node walked.
     */
    private static function pairWalk(
        array $list,
        string $base,
        array &$open,
        array &$nested,
        array &$paired,
        array $labels,
        bool $notes,
        int &$deepest,
        array &$walk,
    ): void {
        foreach ($list as $at => $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? '';
            $key = $base . '/' . $at;
            $walk['last'] = $key;
            if ($type === 'text') {
                $value = is_string($node['value'] ?? null) ? $node['value'] : '';
                self::pairText($value, $key, $open, $nested, $paired, $labels, $notes, $deepest, $walk);
            } elseif (!in_array($type, self::BARRIERS, true) && is_array($node['children'] ?? null)) {
                self::pairWalk($node['children'], $key . '/children', $open, $nested, $paired, $labels, $notes, $deepest, $walk);
            }
        }
    }

    /**
     * The brackets of one text for `pairedBrackets()`. `$nested` runs beside `$open` and marks an open `[` that holds a
     * closed pair.
     *
     * @param string $value
     * @param string $key
     * @param array<int, array{string, int}> $open
     * @param array<int, bool> $nested
     * @param array<string, array<int, true>> $paired
     * @param array<string, true> $labels
     * @param bool $notes
     * @param int $deepest The most brackets open at once so far.
     * @param array{followed: array<array{string, int, int, string}>, last: string|null} $walk
     */
    private static function pairText(
        string $value,
        string $key,
        array &$open,
        array &$nested,
        array &$paired,
        array $labels,
        bool $notes,
        int &$deepest,
        array &$walk,
    ): void {
        // Only brackets act; the text between them is skipped whole.
        for ($i = strcspn($value, '[]'), $n = strlen($value); $i < $n; $i += 1 + strcspn($value, '[]', $i + 1)) {
            if ($value[$i] === '[') {
                $open[] = [$key, $i];
                $nested[] = false;
                $deepest = max($deepest, count($open));
            } elseif ($value[$i] === ']' && $open !== []) {
                [$other, $offset] = array_pop($open);
                $inner = array_pop($nested) === true;
                if ($nested !== []) {
                    $nested[count($nested) - 1] = true;
                }
                $forms = $other === $key ? self::pairForms($value, $offset, $i, $inner ? [] : $labels, $notes) : self::LITERAL_PAIR;
                if ($forms === self::FOLLOWED_PAIR) {
                    $walk['followed'][] = [$key, $offset, $i, $value];
                }
                if ($forms !== self::LITERAL_PAIR) {
                    continue;
                }
                $paired[$other][$offset] = true;
                $paired[$key][$i] = true;
            }
        }
    }

    /**
     * @param string $value
     * @param int $open
     * @param int $close
     * @param array<string, true> $labels None for a pair around another pair: a key holds no bare bracket.
     * @param bool $notes
     *
     * @return int `FORMED_PAIR`, `LITERAL_PAIR`, or `FOLLOWED_PAIR` where only the `[` or `{` behind it may form one.
     */
    private static function pairForms(string $value, int $open, int $close, array $labels, bool $notes): int
    {
        if ($notes && (($value[$open + 1] ?? '') === '^' || ($open > 0 && $value[$open - 1] === '^'))) {
            return self::FORMED_PAIR;
        }
        if ($labels !== [] && isset($labels[Chars::fold(substr($value, $open + 1, $close - $open - 1))])) {
            return self::FORMED_PAIR;
        }
        $next = $value[$close + 1] ?? '';
        if ($next === '(') {
            return self::FORMED_PAIR;
        }
        // C2-I-072, C2-I-075: a label glued to the pair forms a reference, resolved or not; the `]` that closes it may
        // be written after this text. C2-I-078: a label that starts with a caret or an at-sign is none, and the pair is
        // tested as a shortcut. C2-I-064: a brace glued to the pair may open its attribute block.
        if ($next === '{' || ($next === '[' && !in_array($value[$close + 2] ?? '', ['^', '@'], true))) {
            return self::FOLLOWED_PAIR;
        }

        return self::LITERAL_PAIR;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function link(array $node, string $path, Context $c): string
    {
        $raw = $this->rawReference($node, $path, $c);
        if ($raw !== null) {
            return $raw;
        }
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        // C2-W-074: `[](/u)` is an empty link; a collapsed or shortcut reference with no text has no label to read,
        // while the full form carries its label behind the empty pair (C2-W-075).
        if ($children === [] && isset($node['refForm']) && $node['refForm'] !== 'full') {
            $this->w->lose($path, 'link', null);

            return '';
        }
        $inner = $this->inside($children, $path . '/children', $c);
        // C2-W-125 case five: a caret at the start of link text would read as a footnote reference.
        if (
            $this->noteDepth === 0 && !isset($this->caseEightLinks[$path]) && self::leadsWithCaretText($children)
            && str_starts_with($this->w->repairTrace?->decode($inner)['source'] ?? $inner, '^')
        ) {
            $inner = '\\' . $inner;
        }

        if (in_array($node['refForm'] ?? '', ['collapsed', 'shortcut'], true) && is_string($node['key'] ?? null)) {
            $inner = $this->keySpelled($inner, $node['key']);
        }

        return '[' . $inner . ']' . $this->target($node, self::str($node, 'href'), $c, $path);
    }

    /**
     * C2-W-125 case eight: a text `^[` stays bare before a later link whose text is a lone caret, so the `]` that closed
     * it early takes the backslash. Only the first such link behind the closers keeps its caret bare, since a later bare
     * caret could pair with it; not a collapsed or shortcut reference, which spells its caret through its key. Another
     * node between leaves the case to the rounds.
     *
     * @param array<mixed> $nodes
     * @param string $path
     */
    private function markCaseEightLinks(array $nodes, string $path): void
    {
        $caretLinks = [];
        foreach ($nodes as $at => $node) {
            if (is_array($node) && ($node['type'] ?? '') === 'link' && self::caretText($node)) {
                $caretLinks[$at] = $node;
            }
        }
        if ($caretLinks === []) {
            return;
        }
        $depth = 0;
        $closers = false;
        foreach ($nodes as $at => $node) {
            $type = is_array($node) ? ($node['type'] ?? '') : '';
            if ($type === 'text' && is_string($node['value'] ?? null)) {
                self::scanTextNote($node['value'], $depth, $closers);
            } elseif (isset($caretLinks[$at]) && $closers) {
                if (!in_array($caretLinks[$at]['refForm'] ?? '', ['collapsed', 'shortcut'], true)) {
                    $this->caseEightLinks[$path . '/' . $at] = true;
                }
                $depth = 0;
                $closers = false;
            } elseif ($type !== 'soft_break' && $type !== 'hard_break') {
                $depth = 0;
                $closers = false;
            }
        }
    }

    /**
     * With its closers escaped a text `^[` stays open, so each `]` at its own depth closes it early.
     *
     * @param string $value
     * @param int $depth
     * @param bool $closers
     */
    private static function scanTextNote(string $value, int &$depth, bool &$closers): void
    {
        $n = strlen($value);
        for ($i = strcspn($value, '^[]'); $i < $n; $i += 1 + strcspn($value, '^[]', $i + 1)) {
            if ($depth === 0 && $value[$i] === '^' && ($value[$i + 1] ?? '') === '[') {
                $depth = 1;
                $closers = false;
                $i++;
            } elseif ($depth > 0 && $value[$i] === '[') {
                $depth++;
            } elseif ($depth === 1 && $value[$i] === ']') {
                $closers = true;
            } elseif ($depth > 1 && $value[$i] === ']') {
                $depth--;
            }
        }
    }

    /**
     * A link whose text is a lone caret: `^a` or `^ a` would read as a footnote key, and the rounds decide those.
     *
     * @param array<mixed> $node
     */
    private static function caretText(array $node): bool
    {
        $children = $node['children'] ?? null;

        return ($node['type'] ?? '') === 'link' && is_array($children) && count($children) === 1
            && is_array($children[0] ?? null) && ($children[0]['type'] ?? '') === 'text' && ($children[0]['value'] ?? null) === '^';
    }

    /**
     * The content of a collapsed or shortcut reference as its key spells it. A marked write respells its decoded content,
     * so it spells the key as the bare write does (C2-W-088).
     */
    private function keySpelled(string $inner, string $key): string
    {
        $trace = $this->w->repairTrace;
        $plain = $trace?->decode($inner)['source'] ?? $inner;
        $like = Chars::fold($plain) === $key ? null : self::escapedLike($plain, $key);
        if ($like === null) {
            return $inner;
        }

        return $trace === null ? $like : $trace->respelled($inner, $plain, $like) ?? $inner;
    }

    /**
     * C2-I-001, C2-I-079: the content with a backslash before each ASCII punctuation character that the key escapes and
     * none before one it holds bare, where the two differ in nothing else than those and the whitespace the fold
     * collapses; null otherwise.
     *
     * @param string $content
     * @param string $key
     *
     * @return string|null
     */
    private static function escapedLike(string $content, string $key): ?string
    {
        $out = '';
        $k = 0;
        $n = strlen($content);
        $at = 0;
        while ($at < $n) {
            $ch = $content[$at];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n") {
                $end = $at + strspn($content, " \t\n", $at);
                $out .= substr($content, $at, $end - $at);
                // The fold drops the space at the start and at the end of a label and makes one of each run between.
                $k = $k > 0 && $end < $n && ($key[$k] ?? '') === ' ' ? $k + 1 : $k;
                $at = $end;

                continue;
            }
            $pair = self::escapePair($content, $at, $key, $k);
            if ($pair !== null) {
                $out .= $pair;
                $k += strlen($pair);
                $at += 2;

                continue;
            }
            // A backslash the writer left bare before a letter takes one where the key holds an escaped backslash.
            $escape = ($key[$k] ?? '') === '\\' && ($key[$k + 1] ?? '') === $ch && Text::isPunct($ch);
            if (!$escape && ($key[$k] ?? '') !== $ch) {
                return null;
            }
            $out .= $escape ? '\\' . $ch : $ch;
            $k += $escape ? 2 : 1;
            $at++;
        }

        return $k === strlen($key) && Chars::fold($out) === $key ? $out : null;
    }

    /**
     * A backslash pair of the content at `$at` as the key spells it at `$k`: kept where the key holds it, the backslash
     * dropped where the key holds the punctuation character bare (C2-I-001); null where neither.
     *
     * @param string $content
     * @param int $at
     * @param string $key
     * @param int $k
     *
     * @return string|null
     */
    private static function escapePair(string $content, int $at, string $key, int $k): ?string
    {
        $next = $content[$at + 1] ?? '';
        if ($content[$at] !== '\\' || $next === '') {
            return null;
        }
        if (substr($key, $k, 2) === '\\' . $next) {
            return '\\' . $next;
        }

        return $next !== '\\' && ($key[$k] ?? '') === $next && Text::isPunct($next) ? $next : null;
    }

    /**
     * C2-I-079: the key with each space replaced by the matching space run between the words of the content, so that a
     * line break of the content stays; null where the counts differ.
     *
     * @param string $content
     * @param string $key
     *
     * @return string|null
     */
    private static function spacedLike(string $content, string $key): ?string
    {
        $runs = [];
        $n = strlen($content);
        $at = strspn($content, " \t\n");
        while ($at < $n) {
            $length = strspn($content, " \t\n", $at);
            if ($length === 0) {
                $at++;

                continue;
            }
            if ($at + $length < $n) {
                $runs[] = substr($content, $at, $length);
            }
            $at += $length;
        }
        $words = explode(' ', $key);
        if (count($words) !== count($runs) + 1 || array_filter($runs, static fn (string $run): bool => $run !== ' ') === []) {
            return null;
        }
        $out = $words[0];
        foreach ($runs as $i => $run) {
            $out .= $run . $words[$i + 1];
        }

        return $out;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function image(array $node, string $path, Context $c): string
    {
        if (array_key_exists('decorative', $node)) {
            $this->w->lose($path, 'image', 'decorative');
        }
        $raw = $this->rawReference($node, $path, $c);
        if ($raw !== null && AltSource::spells($raw, $node)) {
            return '!' . $raw;
        }
        $alt = self::str($node, 'alt');
        $form = $node['refForm'] ?? null;
        $keyed = is_string($node['key'] ?? null);
        $collapsed = $form === 'collapsed' || $form === 'shortcut';
        $spelled = AltSource::image($alt, $collapsed ? null : ($keyed ? '[k]' : '()'));
        if ($collapsed && $keyed) {
            $spelled = self::collapsedAlt($spelled, $alt, $node['key']);
        }
        $spelled = $c->inCell ? self::cellPipes($spelled) : $spelled;

        return '![' . $spelled . ']' . $this->target($node, self::str($node, 'src'), $c, $path);
    }

    /**
     * C2-W-075: the alt text of a collapsed or shortcut image folds to its key (C2-I-079), or a spelling of it does.
     *
     * @param string $spelled
     * @param string $alt
     * @param string $key
     *
     * @return string
     */
    private static function collapsedAlt(string $spelled, string $alt, string $key): string
    {
        if (Chars::fold($spelled) === $key) {
            return $spelled;
        }
        foreach ([self::escapedLike($spelled, $key), self::spacedLike($spelled, $key), $key] as $candidate) {
            if ($candidate !== null && AltSource::readsAsCollapsed($candidate, $key, $alt)) {
                return $candidate;
            }
        }

        return $spelled;
    }

    /**
     * C2-W-098, C2-B-224: in a cell every pipe of spelled alt text takes a backslash; the cell reads `\|` as a pipe
     * before the alt source, so the spelling reads back. A pipe behind an escaped backslash is bare and takes one too.
     *
     * @param string $text
     *
     * @return string
     */
    private static function cellPipes(string $text): string
    {
        return (string)preg_replace_callback(
            '/(\\\\*)\|/',
            static fn (array $m): string => $m[1] . (strlen($m[1]) % 2 === 0 ? '\\|' : '|'),
            $text,
        );
    }

    /**
     * C2-W-075, C2-W-084: the verbatim text of a reference, where the sidecar holds it.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string|null
     */
    private function rawReference(array $node, string $path, Context $c): ?string
    {
        if (!isset($node['key'])) {
            return null;
        }
        $raw = $this->w->fact($path, 'rawRef');
        if (!is_string($raw)) {
            return null;
        }
        if (!str_contains($raw, "\n")) {
            return $raw;
        }
        if ($c->inCell) {
            return (string)preg_replace('/[ \t]*\n[ \t]*/', ' ', $raw);
        }

        return (string)preg_replace('/(?<!\\\\)[ \t]+\n/', "\n", $raw);
    }

    /**
     * C2-W-073, C2-W-074, C2-W-075: the link tail, or the label of a reference.
     *
     * @param array<mixed> $node
     * @param string $href
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param string $path
     *
     * @return string
     */
    private function target(array $node, string $href, Context $c, string $path): string
    {
        if (is_string($node['key'] ?? null)) {
            $key = $node['key'];

            return match ($node['refForm'] ?? 'full') {
                'collapsed' => '[]',
                'shortcut' => '',
                // C2-I-078: a label that starts with a caret or an at-sign is no reference label; the space folds away.
                default => '[' . (str_starts_with($key, '^') || str_starts_with($key, '@') ? ' ' : '') . self::labelText($key, $c) . ']',
            };
        }
        // C2-I-067: a destination holds no whitespace, so the bracket pair reads back as text.
        if (strpbrk($href, " \t\n\r") !== false) {
            $this->w->lose($path, self::str($node, 'type'), null);
        }
        $tooltip = $node['tooltip'] ?? null;
        // C2-I-069: the tail stays on one line; a title with a line break folds to spaces and only that field is lost.
        if (is_string($tooltip) && preg_match('/\r\n?|\n/', $tooltip)) {
            $this->w->lose($path, self::str($node, 'type'), 'tooltip');
            $tooltip = (string)preg_replace('/\r\n?|\n/', ' ', $tooltip);
        }
        $title = is_string($tooltip) ? ' ' . BraceBlock::quoted($tooltip, $c->inCell) : '';

        return '(' . self::destination($href, $c->inCell) . $title . ')';
    }

    /**
     * C2-B-224 leaves a footnote label out: its `\|` stays two characters, so the key is written as it is. A pipe
     * without a backslash before it ends the cell (C2-B-169), so in a cell such a key has no spelling.
     *
     * @param string $key
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function footnoteRef(string $key, string $path, Context $c): string
    {
        if ($c->inCell && preg_match('/(?<!\\\\)\\|/', $key) === 1) {
            $this->w->lose($path, 'footnote_ref', 'key');
        }

        return '[^' . self::labelText($key, $c, false) . ']';
    }

    /**
     * C2-I-076, C2-I-079: a backslash at the end of a label would take its closing bracket; a space behind it folds away.
     *
     * @param string $key
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param bool $pipes Whether a pipe in a cell takes its backslash (C2-W-098, C2-B-224).
     *
     * @return string
     */
    private static function labelText(string $key, Context $c, bool $pipes = true): string
    {
        if ($pipes && $c->inCell) {
            $key = str_replace('|', '\\|', $key);
        }

        return str_ends_with($key, '\\') ? $key . ' ' : $key;
    }

    /**
     * C2-I-013: a destination reads only the escaped parenthesis and the escaped backslash.
     *
     * @param string $value
     * @param bool $pipes
     *
     * @return string
     */
    public static function destination(string $value, bool $pipes = false): string
    {
        $unmatched = [];
        $open = [];
        $n = strlen($value);
        for ($i = 0; $i < $n; $i++) {
            if ($value[$i] === '(') {
                $open[] = $i;
            } elseif ($value[$i] === ')' && array_pop($open) === null) {
                $unmatched[$i] = true;
            }
        }
        foreach ($open as $i) {
            $unmatched[$i] = true;
        }
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $ch = $value[$i];
            $next = $value[$i + 1] ?? ')';
            $barePipe = $pipes && $ch === '|' && ($i === 0 || $value[$i - 1] !== '\\');
            if (isset($unmatched[$i]) || ($ch === '\\' && str_contains('()\\', $next)) || $barePipe) {
                $out .= '\\';
            }
            $out .= $ch;
        }

        return $out;
    }

    /**
     * @param array<mixed> $node
     * @param string $mark
     * @param bool $words
     */
    private static function tailHoldsMark(array $node, string $mark, bool $words): bool
    {
        $tail = self::textTail($node);

        return $tail !== null && self::holdsMark([['type' => 'text', 'value' => $tail]], $mark, $words);
    }

    /**
     * C2-I-067: the tail of a link or image whose destination holds whitespace, which reads back as text.
     *
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function textTail(array $node): ?string
    {
        $type = $node['type'] ?? '';
        $href = $type === 'image' ? ($node['src'] ?? null) : ($type === 'link' ? ($node['href'] ?? null) : null);
        if (!is_string($href) || is_string($node['key'] ?? null) || strpbrk($href, " \t\n\r") === false) {
            return null;
        }

        return '(' . $href . (is_string($node['tooltip'] ?? null) ? ' "' . $node['tooltip'] . '"' : '') . ')';
    }

    /**
     * C2-W-077: the shortest backtick run that does not occur in the value, padded where the ends need it.
     *
     * @param string $value
     *
     * @return string
     */
    public static function codeSpan(string $value): string
    {
        $taken = [];
        if (preg_match_all('/`+/', $value, $m)) {
            foreach ($m[0] as $run) {
                $taken[strlen($run)] = true;
            }
        }
        $length = 1;
        while (isset($taken[$length])) {
            $length++;
        }
        $run = str_repeat('`', $length);
        $open = str_starts_with($value, '`') ? $run . ' ' : $run;
        $close = str_ends_with($value, '`') ? ' ' . $run : $run;

        return $open . $value . $close;
    }

    /**
     * C2-I-002: spaces and tabs at the end of a line and the indent of the next are no part of the content. A space
     * behind an odd run of backslashes stays.
     *
     * @param string $value
     *
     * @return bool
     */
    private static function losesLineEdges(string $value): bool
    {
        for ($at = strpos($value, "\n"); $at !== false; $at = strpos($value, "\n", $at + 1)) {
            $after = $value[$at + 1] ?? '';
            $before = $at > 0 ? $value[$at - 1] : '';
            if ($after === ' ' || $after === "\t" || $before === "\t") {
                return true;
            }
            if ($before === ' ' && self::backslashesBefore($value, $at - 1) % 2 === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Counts only the run ending at `$end`, so a value with many line feeds stays linear.
     *
     * @param string $value
     * @param int $end
     *
     * @return int
     */
    private static function backslashesBefore(string $value, int $end): int
    {
        $at = $end;
        while ($at > 0 && $value[$at - 1] === '\\') {
            $at--;
        }

        return $end - $at;
    }

    /**
     * C2-W-077, C2-W-117: a value that starts with a space and a backtick, or ends with a backtick and a space, has no
     * spelling.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param string $prefix
     *
     * @return string
     */
    private function spanValue(array $node, string $path, Context $c, string $prefix): string
    {
        $value = $this->cellValue($node, $path, $c, self::str($node, 'value'), true);
        $spelling = $prefix . self::codeSpan($value);
        if ($c->inLineBlock && !$c->inCell && str_contains($value, "\n")) {
            return $spelling;
        }
        $break = strpos($spelling, "\n");
        $firstLine = $break === false ? $spelling : substr($spelling, 0, $break);
        if ($c->lineStart && strspn($firstLine, '`') >= 3 && Text::opensBlock($firstLine, $c, true)) {
            $this->w->lose($path, self::str($node, 'type'), 'value');
        }
        if ($value === '') {
            // C2-W-116: an empty run pair reads back as text.
            $this->w->lose($path, self::str($node, 'type'), null);
        } elseif (str_starts_with($value, ' `') || str_ends_with($value, '` ') || self::losesLineEdges($value)) {
            $this->w->lose($path, self::str($node, 'type'), 'value');
        }

        return $spelling;
    }

    /**
     * C2-W-067: no form crosses a row of a pipe table, so a line feed in a cell is written as a space and reported.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param string $value
     * @param bool $span
     *
     * @return string
     */
    private function cellValue(array $node, string $path, Context $c, string $value, bool $span = false): string
    {
        if (!str_contains($value, "\n")) {
            return $value;
        }
        if (!$c->inCell) {
            if ($c->inLineBlock) {
                // C2-W-124: the line ending ends the line, so the node reads back as text.
                $this->w->lose($path, self::str($node, 'type'), null);

                return $value;
            }
            if (str_contains($value, "\r\n") || (!$span && self::losesLineEdges($value))) {
                // The reader drops the CR of a line ending and, in a comment, the spaces and tabs around it.
                $this->w->lose($path, self::str($node, 'type'), 'value');
            }
            if (!$span) {
                return $value;
            }
            $lines = array_slice(explode("\n", self::codeSpan($value)), 1);
            $last = count($lines) - 1;
            foreach ($lines as $at => $line) {
                if ($at === $last) {
                    if (!self::spellableFinalSpanLine($line, $c)) {
                        $this->w->lose($path, self::str($node, 'type'), 'value');

                        break;
                    }

                    continue;
                }
                if (Text::opensBlock($line, $c)) {
                    $this->w->lose($path, self::str($node, 'type'), 'value');

                    break;
                }
            }

            return $value;
        }
        $this->w->lose($path, self::str($node, 'type'), 'value');

        return str_replace("\n", ' ', $value);
    }

    private static function spellableFinalSpanLine(string $line, Context $c): bool
    {
        // C2-W-151: spaces and tabs that end the line after the span are dropped from the text, not lost by the span.
        if (str_starts_with($line, ' ')) {
            return false;
        }
        if (!$c->lineAfterSpanEnds && str_starts_with(ltrim($line, " \t"), '|')) {
            return false;
        }
        $emittedLine = $line . $c->lineAfterSpan;
        if (!Text::opensBlock($emittedLine, $c)) {
            return true;
        }
        $pipe = $c->lineAfterSpanPipeSource;
        if (
            $pipe < 0 || Text::opensBlock($line . substr($c->lineAfterSpan, 0, $pipe) . '\\'
                . substr($c->lineAfterSpan, $pipe), $c)
        ) {
            return false;
        }
        $c->escapeFollowingPipeAt = $c->lineAfterSpanPipeText;

        return true;
    }

    /**
     * C2-I-090: a sigil after a letter or number starts no form, so the node reads back as text. C2-I-092: a letter or
     * digit behind a mention or a tag lengthens its name, and no backslash keeps it out (C2-W-125 case six).
     *
     * @param string $type
     * @param string $path
     * @param int $next
     * @param string $out
     *
     * @return string
     */
    private function sigil(string $type, string $path, int $next, string $out): string
    {
        if (Chars::isWord($this->visibleLast)) {
            $this->w->lose($path, $type, null);
        } elseif ($type !== 'symbol' && $next >= 0 && $next < 0x80 && Chars::isWord($next)) {
            $this->w->lose($path, $type, $type === 'tag' ? 'name' : 'target');
        } elseif ($type !== 'symbol' && Text::endsNoName($next)) {
            // C2-W-117: no backslash keeps a letter, number or mark outside ASCII from the name.
            $this->w->lose($path, $type, null);
        }

        return $out;
    }

    /**
     * C2-I-104, C2-I-105: a straight quote takes its direction from the character before it, and a single quote before
     * an ASCII digit is the apostrophe. A quote node of the other kind reads back as that kind.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function smart(array $node, string $path): string
    {
        $source = self::str($node, 'source');
        if ($source !== '"' && $source !== "'") {
            // C2-W-078: a run character beside the node can lengthen or change its run.
            $afterRun = isset(self::SMART_RUN[$this->visibleLast]) && is_array($this->before) && self::isText($this->before);
            if ($afterRun || isset(self::SMART_RUN[$this->visibleNext])) {
                $this->w->smartRun = true;
            }

            return $source;
        }
        $prev = $this->before;
        $prevType = is_array($prev) ? self::str($prev, 'type') : '';
        // C2-I-104: the content of a node starts with nothing before it; behind another node the reader sees a word
        // character, a quote glyph, a no-break space or a line break.
        $before = match ($prevType) {
            '', 'soft_break', 'hard_break', 'non_breaking_space' => -1,
            'text' => $this->visibleLast,
            'smart_punctuation' => in_array(self::str($prev, 'kind'), ['left_single_quote', 'left_double_quote'], true)
                ? 0x201C : 0x61,
            default => 0x61,
        };
        $opens = Chars::isSpace($before) || ($before >= 0 && $before < 0x80
            ? str_contains(Tables::SMART_QUOTE_OPENS_AFTER, chr($before))
            : $before >= 0x80 && mb_strpos(Tables::SMART_QUOTE_OPENS_AFTER, (string)mb_chr($before, 'UTF-8')) !== false);
        $kind = $source === '"'
            ? ($opens ? 'left_double_quote' : 'right_double_quote')
            : ($opens && ($this->visibleNext < 0x30 || $this->visibleNext > 0x39) ? 'left_single_quote' : 'right_single_quote');
        if ($kind !== self::str($node, 'kind')) {
            $this->w->lose($path, 'smart_punctuation', 'kind');
        }

        return $source;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function mention(array $node, string $path): string
    {
        if (isset($node['label'])) {
            $this->w->lose($path, 'mention', 'label');
        } elseif (($node['kind'] ?? 'user') !== 'user') {
            $this->w->lose($path, 'mention', 'kind');
        }

        return '@' . self::str($node, 'target');
    }

    /**
     * C2-W-116, C2-A-216: the target in angle brackets; the email form is its text.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function autolink(array $node, string $path): string
    {
        $href = self::str($node, 'href');
        if (!isset($node['text'])) {
            return '<' . $href . '>';
        }
        $text = self::str($node, 'text');
        if ($href !== 'mailto:' . $text) {
            $this->w->lose($path, 'autolink', 'text');
        }

        return str_starts_with($href, 'mailto:') ? '<' . substr($href, 7) . '>' : '<' . $href . '>';
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function xref(array $node, string $path): string
    {
        if (isset($node['display'])) {
            $this->w->lose($path, 'xref', 'display');
        }

        return '</#' . self::str($node, 'key') . '>';
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function abbreviation(array $node, string $path): string
    {
        $key = self::str($node, 'key');
        // C2-W-117: without a definition of its key the word reads back as text.
        if (!isset($this->w->abbreviations[$key])) {
            $this->w->lose($path, 'abbreviation', null);
        }

        return $key;
    }

    /**
     * C2-W-059: a braced comment, or a line comment at the end of its line.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string
     */
    private function comment(array $node, string $path, Context $c, int $next = -1): string
    {
        $braced = ($node['form'] ?? '') === 'braced';
        // A line comment outside a cell writes its line feeds as spaces below, so it reads back as a comment.
        $value = $braced || $c->inCell ? $this->cellValue($node, $path, $c, self::str($node, 'value')) : self::str($node, 'value');
        if ($braced) {
            if ($value === '') {
                return '{%%}';
            }
            // C2-W-005, C2-A-074: where a padded line reads as a block opener, the value stands glued to the marks.
            if ($this->laterLineOpens($value, ' %}', $c)) {
                $glued = !str_starts_with($value, ' ') && !str_ends_with($value, ' ');
                if (!$glued || $this->laterLineOpens($value, '%}', $c)) {
                    $this->w->lose($path, 'comment', 'value');
                }
                if ($glued) {
                    return '{%' . $value . '%}';
                }
            }

            return '{% ' . $value . ' %}';
        }
        $kept = rtrim($value, " \t");
        if ($kept !== $value || str_contains($kept, "\n")) {
            $this->w->lose($path, 'comment', 'value');
        }
        // C2-W-117: a line comment runs to the end of its line, so content behind it on that line has no spelling.
        if ($c->inCell && $next !== -1 && $next !== 10 && !$this->wrapsBehind($path)) {
            $this->w->lose($path, 'comment', null);
        }
        // C2-I-023: a line comment starts at the start of a line or after whitespace, which it takes with it, so a
        // space a node spells before it (the `\ ` of a non-breaking space) does not count.
        $lead = $c->last === -1 || $c->last === 10 ? '' : ' ';

        return $lead . ($kept === '' ? '%%' : '%% ' . str_replace("\n", ' ', $kept));
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     *
     * @return string
     */
    private function criticComment(array $node, string $path, Context $c): string
    {
        $value = $this->cellValue($node, $path, $c, self::str($node, 'value'));
        if ($this->laterLineOpens($value, '#}', $c)) {
            $this->w->lose($path, 'critic_comment', 'value');
        }

        return '{#' . $value . '#}';
    }

    /**
     * C2-B-014: a later line of a comment value, its last line with the closing marks, can interrupt the paragraph.
     */
    private function laterLineOpens(string $value, string $close, Context $c): bool
    {
        if ($c->inCell || $c->inLineBlock || !str_contains($value, "\n")) {
            return false;
        }
        $lines = array_slice(explode("\n", $value), 1);
        $last = count($lines) - 1;
        foreach ($lines as $at => $line) {
            if (Text::opensBlock($at === $last ? $line . $close : $line, $c)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the text behind the node at `$path` starts a continuation row: its joining space is the wrap (C2-L-020).
     */
    private function wrapsBehind(string $path): bool
    {
        $cut = strrpos($path, '/');
        if ($cut === false) {
            return false;
        }

        return in_array(1, $this->w->wrapPoints[substr($path, 0, $cut + 1) . ((int)substr($path, $cut + 1) + 1)] ?? [], true);
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\Context $c
     * @param int $next
     *
     * @return string|null
     */
    private function extension(array $node, string $path, Context $c, int $next): ?string
    {
        $name = self::str($node, 'name');
        if (!str_contains($name, '.')) {
            $c->last = 0x5B;

            return ':' . $name . $this->bracketed('[', $node + ['children' => []], $path, $c);
        }
        $source = is_array($node['source'] ?? null) ? $node['source'] : [];
        if (($source['form'] ?? '') === 'inline' && is_string($source['name'] ?? null) && is_string($source['body'] ?? null)) {
            $text = ':' . $source['name'] . '[' . $source['body'] . ']';
            // C2-X-023, C2-X-049
            if (ExtensionSource::readsBack($node, $text, $this->w->extensions, $this->w, $path)) {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return string
     */
    private function citations(array $node, string $path): string
    {
        $this->w->lose($path, 'citation_group', null);

        return self::str($node, 'raw');
    }
}
