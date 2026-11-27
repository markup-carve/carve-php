<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Json\TreeEncoder;

/**
 * Compare inline lists in source order. A read-back construct may hide later nodes; stepping
 * over its spelling lets the same repair round report escapes behind the first mismatch.
 */
final class CompareRound
{
    /**
     * The marks of braced openers (C2-I-055), as carve-js `BRACED_MARKS`.
     *
     * @var string
     */
    private const BRACED_MARKS = '*+,-/=^_~';

    /**
     * @var array<string, array<int, true>>
     */
    private array $asks = [];

    /**
     * @var array<string, int> The written start offset of each expected text, by path.
     */
    private array $starts = [];

    /**
     * @var array<string, int> The written start offset of each expected inline node, by `RepairTrace::packPath()`.
     */
    private array $nodeStarts = [];

    /**
     * @var array<string, int> The written end offset of each expected inline node, by `RepairTrace::packPath()`.
     */
    private array $nodeEnds = [];

    /**
     * The source the expected nodes were written in, or null.
     */
    private ?string $source = null;

    /**
     * The number of asks so far.
     */
    private int $asked = 0;

    /**
     * The type of the node whose content a probe compares: it owns the probe's paragraph (carve-js `probeRound`).
     */
    private ?string $probed = null;

    /**
     * @var array<string, array<int, true>> The asks of `askFirst()`: the first character of a node that did not form.
     */
    private array $firsts = [];

    /**
     * @var array<string, array<int, true>> The asks of `matchPlain()`: a character the parse reads otherwise than the text.
     */
    private array $differs = [];

    /**
     * C2-W-125: a content in this list closed early and its closer was asked; behind it the parse is read again in
     * the next round, so no difference there asks (carve-js `Check.cut`).
     */
    private bool $cut = false;

    /**
     * @var array<int, array{string, int}> The `=` of each node whose braced spelling read as an attribute block.
     */
    private array $attributeKeys = [];

    /**
     * The planner's respelling of a node the walk reached that did not form (carve-js `respell`): its trial, or null.
     *
     * @var (\Closure(string, string): (array<array{string, int}>|null))|null
     */
    private ?\Closure $respell = null;

    /**
     * @param array<mixed> $root
     * @param array<string, array<int, true>> $escapes
     */
    private function __construct(private array $root, private array $escapes)
    {
    }

    /**
     * @param-out array<string, array<int, true>> $firsts
     * @param-out array<string, array<int, true>> $differs
     *
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     * @param array<string, array<int, true>> $escapes
     * @param array{
     *     source?: string,
     *     nodes?: array<int, array{string, string, int, int}>,
     *     ranges?: array<string, array{int, int}>
     * } $candidate
     * @param string|null $probed
     * @param array<string, array<int, true>>|null $firsts Set to the asks for the first character of a node that did
     *   not form.
     * @param array<string, array<int, true>> $differs Set to the escapes of characters the read-back spelled
     *   otherwise, which the round adds as reported kinds.
     * @param array<string, array<int, true>> $keys Set to the `=` of each node whose braced spelling read as an
     *   attribute block (C2-W-125, case nine), which the round asks only where no other repair applies.
     * @param (\Closure(string, string): (array<array{string, int}>|null))|null $respell
     *
     * @return array<string, array<int, true>>
     */
    public static function inspect(
        array $expected,
        array $actual,
        array $escapes,
        array $candidate = [],
        ?string $probed = null,
        ?array &$firsts = null,
        array &$differs = [],
        array &$keys = [],
        ?\Closure $respell = null,
    ): array {
        $comparison = new self($expected, $escapes);
        $comparison->probed = $probed;
        $comparison->respell = $respell;
        foreach ($candidate['ranges'] ?? [] as $path => [$start]) {
            $comparison->starts[$path] = $start;
        }
        foreach ($candidate['nodes'] ?? [] as [$path, , $start, $end]) {
            $comparison->nodeStarts[$path] = $start;
            $comparison->nodeEnds[$path] = $end;
        }
        $comparison->source = $candidate['source'] ?? null;
        $comparison->children($expected, $actual, '', '');
        $keys = [];
        foreach ($comparison->attributeKeys as [$path, $at]) {
            if (!isset($escapes[$path][$at])) {
                $keys[$path][$at] = true;
            }
        }
        $firsts = $comparison->firsts;
        $differs = $comparison->differs;

        return $comparison->asks;
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     * @param string $owner
     * @param string $path
     */
    private function children(array $expected, array $actual, string $path, string $owner): void
    {
        $want = is_array($expected['children'] ?? null) ? $expected['children'] : [];
        $have = is_array($actual['children'] ?? null) ? $actual['children'] : [];
        $count = count($want);
        $i = 0;
        $j = 0;
        $vi = 0;
        $aj = 0;
        while ($i < $count) {
            $node = $want[$i];
            if (!is_array($node)) {
                $i++;

                continue;
            }
            $nodePath = $path . '/children/' . $i;
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            $more = $type === 'text'
                ? $this->matchText($node, $have, $nodePath, $owner, $i, $j, $vi, $aj, $i + 1 === $count ? $path : null, $want)
                : $this->matchNode($node, $have, $nodePath, $type, $i, $j, $aj, $want, $path);
            if (!$more && $type === 'text') {
                $this->keyBehindText($want, $have, $path, $nodePath, $i, $j, $aj);
            }
            if (!$more && !$this->resync($want, $have, $path, $i, $j)) {
                return;
            }
            if (!$more) {
                $vi = 0;
                $aj = 0;
            }
        }
    }

    /**
     * After a mismatch, resume at the next expected node of the line at whose written start a parsed node starts,
     * behind the parsed node that failed, so one round reports the later deviations too (C2-W-088).
     *
     * @param array<mixed> $want
     * @param array<mixed> $have
     * @param int $j
     * @param int $i
     * @param string $path
     */
    private function resync(array $want, array $have, string $path, int &$i, int &$j): bool
    {
        $failedEnd = self::offset($have[$j] ?? null, 1);
        if ($failedEnd === null) {
            return false;
        }
        $count = count($want);
        $children = RepairTrace::packPath($path . '/children/');
        for ($k = $i + 1, $next = $j; $k < $count; $k++) {
            $type = is_array($want[$k]) ? ($want[$k]['type'] ?? '') : '';
            if ($type === 'soft_break' || $type === 'hard_break') {
                return false;
            }
            $start = $this->nodeStarts[$children . $k] ?? $this->starts[$path . '/children/' . $k] ?? null;
            if ($start === null || $start < $failedEnd) {
                continue;
            }
            while (is_array($have[$next] ?? null) && (self::offset($have[$next], 0) ?? -1) < $start) {
                $next++;
            }
            if (self::offset($have[$next] ?? null, 0) === $start) {
                $i = $k;
                $j = $next;

                return true;
            }
        }

        return false;
    }

    /**
     * Whether a parsed node starting at `$start` starts where an expected sibling behind `$i` was written.
     *
     * @param array<mixed> $want
     * @param string $parent
     * @param int $i
     * @param int|null $start
     */
    private function laterSibling(array $want, string $parent, int $i, ?int $start): bool
    {
        if ($start === null) {
            return false;
        }
        $children = RepairTrace::packPath($parent . '/children/');
        $own = $this->nodeStarts[$children . $i] ?? $this->starts[$parent . '/children/' . $i] ?? null;
        if ($own === null || $own === $start) {
            return false;
        }
        for ($k = $i + 1, $count = count($want); $k < $count; $k++) {
            if (($this->nodeStarts[$children . $k] ?? $this->starts[$parent . '/children/' . $k] ?? null) === $start) {
                return true;
            }
        }

        return false;
    }

    /**
     * Asks for the first character of the first text in a node's content.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param bool $bracedOnly Only where that character is the brace of a braced opener.
     */
    private function askFirst(array $node, string $path, bool $bracedOnly = false): void
    {
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (!is_array($child)) {
                continue;
            }
            $at = $path . '/children/' . $index;
            if (($child['type'] ?? '') === 'text') {
                $value = is_string($child['value'] ?? null) ? $child['value'] : '';
                if ($bracedOnly && !($value !== '' && $value[0] === '{' && Text::isPunct($value[1] ?? 'a'))) {
                    return;
                }
                $this->ask($at, 0);
                if (isset($this->asks[$at][0])) {
                    $this->firsts[$at][0] = true;
                }

                return;
            }
            $this->askFirst($child, $at, $bracedOnly);

            return;
        }
    }

    /**
     * The parse holds more text where the node behind a text was written: case nine of C2-W-125 may apply to that node.
     *
     * @param array<mixed> $want
     * @param array<mixed> $have
     * @param string $path
     * @param string $textPath
     * @param int $i
     * @param int $j
     * @param int $aj
     */
    private function keyBehindText(array $want, array $have, string $path, string $textPath, int $i, int $j, int $aj): void
    {
        if ($aj > 0 && is_array($want[$i] ?? null) && $textPath !== $path . '/children/' . $i) {
            $this->askAttributeKey($want[$i], $path . '/children/' . $i, $have[$j] ?? null, $aj);
        }
    }

    /**
     * C2-W-125, case nine: the parse holds the node's braced spelling as a valid attribute block (C2-I-043). A key that
     * starts with `_` is valid only with a value (C2-I-031), so the `=` behind the key characters of the content takes
     * the backslash: an underline of `=.` is `{_\=._}`.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param mixed $other The parsed node where the node was written.
     * @param int $aj Where the node's spelling starts in `$other`.
     */
    private function askAttributeKey(array $node, string $path, mixed $other, int $aj): bool
    {
        $first = is_array($node['children'] ?? null) ? $node['children'][0] ?? null : null;
        $read = is_array($other) && ($other['type'] ?? '') === 'text' && is_string($other['value'] ?? null) ? $other['value'] : '';
        if (!is_array($first) || ($first['type'] ?? '') !== 'text' || !is_string($first['value'] ?? null) || ($read[$aj] ?? '') !== '{') {
            return false;
        }
        if (AttributeParser::read($read, $aj, strlen($read), new Attrs()) < 0) {
            return false;
        }
        $equals = strspn($first['value'], Tables::ATTR_KEY_REST);
        if (($first['value'][$equals] ?? '') !== '=') {
            return false;
        }
        $this->attributeKeys[] = [$path . '/children/0', $equals];

        return true;
    }

    /**
     * Asks the planner for the next respelling of the node at `$path`; its escapes count as placed for the rest of the
     * walk (carve-js `respell`).
     */
    private function respellAt(string $path, string $parent, int $i): bool
    {
        $trial = ($this->respell)($path, $i > 0 ? $parent . '/children/' . ($i - 1) : '');
        if ($trial === null) {
            return false;
        }
        foreach ($trial as [$text, $at]) {
            $this->escapes[$text][$at] = true;
        }

        return true;
    }

    /**
     * The node at `$path` did not form, so the parsed text holds its spelling: the walk steps over it there (carve-js
     * `stepOver`). False where the parsed text does not hold it as written.
     *
     * @param array<mixed> $have
     */
    private function stepOver(string $path, array $have, int &$i, int &$j, int &$aj): bool
    {
        $packed = RepairTrace::packPath($path);
        $start = $this->nodeStarts[$packed] ?? null;
        $end = $this->nodeEnds[$packed] ?? null;
        $other = $have[$j] ?? null;
        if ($start === null || $end === null || $this->source === null || !is_array($other) || ($other['type'] ?? '') !== 'text') {
            return false;
        }
        $spelled = substr($this->source, $start, $end - $start);
        $value = is_string($other['value'] ?? null) ? $other['value'] : '';
        if ($spelled === '' || str_contains($spelled, '\\') || substr($value, $aj, strlen($spelled)) !== $spelled) {
            return false;
        }
        $aj += strlen($spelled);
        if ($aj === strlen($value)) {
            $j++;
            $aj = 0;
        }
        $i++;

        return true;
    }

    private static function offset(mixed $node, int $key): ?int
    {
        $pos = is_array($node) ? TreeEncoder::offsets($node['pos'] ?? null) : null;

        return $pos === null ? null : $pos[$key];
    }

    /**
     * @param array<mixed> $node
     * @param array<mixed> $have
     * @param int $aj
     * @param int $j
     * @param int $i
     * @param string $type
     * @param string $path
     * @param array<mixed> $want The expected siblings.
     * @param string $parent The path of their parent.
     */
    private function matchNode(
        array $node,
        array $have,
        string $path,
        string $type,
        int &$i,
        int &$j,
        int &$aj,
        array $want = [],
        string $parent = '',
    ): bool {
        $other = $have[$j] ?? null;
        if (getenv('EARLY') && $this->respell !== null && is_array($other) && (($other['type'] ?? '') !== $type || $aj !== 0)) {
            $asked = $this->asked;
            $found = null;
            $limit = min(count($have), $j + 16);
            for ($next = $j + 1; $next < $limit && $found === null; $next++) {
                if (is_array($have[$next]) && ($have[$next]['type'] ?? '') === $type) {
                    $found = $next;
                }
            }
            if (getenv('DBG')) { fwrite(STDERR, " early $path j=$j aj=$aj other=" . ($other['type'] ?? '') . " found=" . json_encode($found) . " limit=$limit n=" . count($have) . "\n"); }
            if ($found !== null && $this->laterSibling($want, $parent, $i, self::offset($have[$found], 0))) {
                $this->askFirst($node, $path);
            } elseif ($found === null && $limit === count($have) && !$this->askAttributeKey($node, $path, $other, $aj)) {
                $this->askFirst($node, $path, true);
            }
            if ($this->asked !== $asked || $this->attributeKeys !== []) {
                return false;
            }

            return !$this->cut && $this->respellAt($path, $parent, $i) && $this->stepOver($path, $have, $i, $j, $aj);
        }
        if (!is_array($other) || ($other['type'] ?? '') !== $type || $aj !== 0) {
            $asked = $this->asked;
            $keys = $this->attributeKeys;
            $found = null;
            $limit = min(count($have), $j + 16);
            for ($next = $j + 1; $next < $limit; $next++) {
                if (is_array($have[$next]) && ($have[$next]['type'] ?? '') === $type) {
                    $found = $next;

                    break;
                }
            }
            if ($found !== null && $this->laterSibling($want, $parent, $i, self::offset($have[$found], 0))) {
                // The parsed node is a later sibling's: this node did not form, and its own content is compared with
                // nothing, so only its first character is asked (C2-W-088).
                $this->askFirst($node, $path);
                $found = null;
            } elseif ($found === null && $limit === count($have) && !$this->askAttributeKey($node, $path, $other, $aj)) {
                // The parse holds no node of the type behind: where the content starts with a braced opener, that
                // opener is the one that kept the node from forming.
                $this->askFirst($node, $path, true);
            }
            if ($found === null) {
                // carve-js `matchNode`: where nothing else asks, the node's next respelling is a trial, and the walk
                // steps over its spelling in the parsed text.
                return $this->respell !== null && is_array($other) && !$this->cut && $this->asked === $asked
                    && $this->attributeKeys === $keys
                    && $this->respellAt($path, $parent, $i) && $this->stepOver($path, $have, $i, $j, $aj);
            }
            $j = $found;
            $aj = 0;
            $other = $have[$j];
        }
        if (!is_array($other)) {
            return false;
        }
        $outer = $this->cut;
        $this->cut = false;
        $asked = $this->asked;
        $this->children($node, $other, $path, $this->probed !== null && $path === '/children/0' ? $this->probed : $type);
        // C2-W-125: a bracketed content that read on or ended early was repaired inside; behind it the parse is read
        // again in the next round (carve-js `matchNode`).
        $cut = $this->asked > $asked && self::closer($type) === ']' && $this->extentDiffers($path, $other);
        $this->cut = $cut || (self::closer($type) !== '' ? $this->cut || $outer : $outer);
        $i++;
        $j++;

        return true;
    }

    /**
     * @param array<mixed> $node
     * @param array<mixed> $have
     * @param int $aj
     * @param string|null $last
     * @param int $vi
     * @param int $j
     * @param int $i
     * @param string $owner
     * @param string $path
     * @param array<mixed> $want The expected siblings.
     */
    private function matchText(
        array $node,
        array $have,
        string $path,
        string $owner,
        int &$i,
        int &$j,
        int &$vi,
        int &$aj,
        ?string $last = null,
        array $want = [],
    ): bool {
        $value = is_string($node['value'] ?? null) ? $node['value'] : '';
        $other = $have[$j] ?? null;
        if (!is_array($other)) {
            $mark = $this->cut ? '' : self::closer($owner);
            if ($mark !== '') {
                $at = strpos($value, $mark, $vi);
                if ($at !== false) {
                    $this->ask($path, $at);
                    $this->cut = isset($this->asks[$path][$at]);
                }
            }

            return false;
        }
        if ($this->cut) {
            return false;
        }
        if (($other['type'] ?? '') !== 'text') {
            return $this->matchConstruct($value, $other, $path, $i, $j, $vi, $last !== null ? $owner : '');
        }

        $after = $want[$i + 1] ?? null;
        $textNext = is_array($after) && ($after['type'] ?? '') === 'text';

        return $this->matchPlain($value, $other, $path, $i, $j, $vi, $aj, $last, $owner, $textNext, $have);
    }

    /**
     * @param string $value
     * @param array<mixed> $other
     * @param int $vi
     * @param int $j
     * @param int $i
     * @param string $path
     * @param string $owner The type of the node whose content ends with this text, or ''.
     */
    private function matchConstruct(string $value, array $other, string $path, int &$i, int &$j, int &$vi, string $owner = ''): bool
    {
        $at = $vi;
        if (($value[$at] ?? '') === '{' && ($other['type'] ?? '') === 'smart_punctuation') {
            $at += 2;
        }
        $this->ask($path, $at);
        // C2-W-125, case one: the content's last brace reads with the owner's closing mark as a braced opener, so the
        // owner reads on; that repairs the tree's own node and gives no kind (C2-W-088).
        $mark = self::closer($owner);
        if ($mark === '' || $mark === ']' || $at !== strlen($value) - 1 || $value[$at] !== '{') {
            $this->constructOpener($value, $other, $path, $at);
        } elseif (isset($this->asks[$path][$at])) {
            $this->cut = true;
        }
        $pos = TreeEncoder::offsets($other['pos'] ?? null);
        if ($pos === null) {
            return false;
        }
        [$startOffset, $endOffset] = $pos;
        $end = $this->spelledEnd($value, $path, $vi, $endOffset - $startOffset);
        if ($end === null) {
            return false;
        }
        $vi = $end;
        $j++;
        if ($vi === strlen($value)) {
            $i++;
            $vi = 0;
        }

        return true;
    }

    /**
     * C2-W-088, C2-W-090: the opener of a construct the parse read where the tree has text is reported in its round,
     * at the character that takes the backslash (carve-js `escapeOpener`). A note opener and a smart quote are left
     * to the later rounds, as before.
     *
     * @param string $value
     * @param array<mixed> $other
     * @param string $path
     * @param int $at
     */
    private function constructOpener(string $value, array $other, string $path, int $at): void
    {
        $type = $other['type'] ?? '';
        if ($type === 'inline_footnote' || $type === 'smart_punctuation') {
            return;
        }
        $at += strspn($value, " \t", $at);
        $at += match ($type) {
            'image', 'literal_inline' => 1,
            'math' => ($other['display'] ?? false) === true ? 2 : 1,
            default => 0,
        };
        if (isset($value[$at]) && Text::isPunct($value[$at]) && !isset($this->escapes[$path][$at])) {
            $this->differs[$path][$at] = true;
        }
    }

    /**
     * @param string $value
     * @param array<mixed> $other
     * @param int $aj
     * @param string $owner
     * @param string|null $last
     * @param int $vi
     * @param int $j
     * @param int $i
     * @param string $path
     * @param bool $textNext
     */
    private function matchPlain(
        string $value,
        array $other,
        string $path,
        int &$i,
        int &$j,
        int &$vi,
        int &$aj,
        ?string $last = null,
        string $owner = '',
        bool $textNext = false,
        array $have = [],
    ): bool {
        $otherValue = is_string($other['value'] ?? null) ? $other['value'] : '';
        $limit = min(strlen($value) - $vi, strlen($otherValue) - $aj);
        for ($k = 0; $k < $limit; $k++) {
            if ($value[$vi + $k] !== $otherValue[$aj + $k]) {
                $this->ask($path, $vi + $k);
                if (isset($this->asks[$path][$vi + $k])) {
                    $this->differs[$path][$vi + $k] = true;
                }

                return false;
            }
        }
        $vi += $limit;
        $aj += $limit;
        if ($aj === strlen($otherValue)) {
            $j++;
            $aj = 0;
        }
        if ($vi === strlen($value)) {
            $i++;
            $vi = 0;
            if ($aj > 0 && $last !== null) {
                $this->contentEnd($last, $owner);
            }
            if ($aj > 0 && $last === null && !$textNext) {
                // The parse holds more text where the next node was written: that node did not form, and the list is
                // compared again from where the parse and the writing meet (carve-js `afterTextNode`).
                if ($this->respell === null || getenv('NOPLAIN')) {
                    return false;
                }
                $parent = substr($path, 0, (int)strrpos($path, '/children/'));
                $next = $parent . '/children/' . $i;
                $after = $this->textAt($next);
                if ($after !== null) {
                    $this->askAttributeKey($after, $next, $other, $aj);
                }

                return $this->respellAt($next, $parent, $i) && $this->stepOver($next, $have, $i, $j, $aj);
            }
            if ($aj > 0) {
                $j++;
                $aj = 0;
            }
        }

        return $limit > 0;
    }

    /**
     * The content of the node at `path` is matched to its end and the parse holds more text: its closing mark did not
     * close. The first bare closing mark in the content takes the backslash, else the end of its last text
     * (C2-W-093, C2-W-125; carve-js `afterTextNode`).
     */
    private function contentEnd(string $path, string $type): void
    {
        $node = $this->textAt($path);
        $mark = self::closer($type);
        if ($node === null) {
            return;
        }
        // C2-W-125, case one: a brace of the content that reads as a braced opener with the mark behind it reads on.
        // The span is the tree's own node (C2-W-088), so the backslash gives no kind, and the parse behind it asks nothing.
        if ($mark !== '' && $mark !== ']' && $this->askOpeningBrace($node, $path, $mark)) {
            $this->cut = true;

            return;
        }
        if ($mark !== '' && ($this->askMark($node, $path, $mark) || ($mark === ']' && $this->askMark($node, $path, '[')))) {
            return;
        }
        $last = self::lastText($node, $path);
        if ($last === null) {
            return;
        }
        [$textPath, $value] = $last;
        $length = strlen($value);
        if ($length > 0 && str_contains('\\!^$`', $value[$length - 1]) && $this->tryAsk($textPath, $length - 1)) {
            $this->differs[$textPath][$length - 1] = true;

            return;
        }
        $brace = strrpos($value, '{');
        if ($brace !== false && $this->tryAsk($textPath, $brace)) {
            $this->differs[$textPath][$brace] = true;
        }
    }

    /**
     * The last brace of the content's last text that reads as a braced opener, with its mark behind it (carve-js
     * `escapeOpeningBrace`).
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $mark
     */
    private function askOpeningBrace(array $node, string $path, string $mark): bool
    {
        $last = self::lastText($node, $path);
        if ($last === null) {
            return false;
        }
        [$textPath, $value] = $last;
        $at = strrpos($value, '{');
        while ($at !== false) {
            $next = $value[$at + 1] ?? $mark;
            if (str_contains(self::BRACED_MARKS, $next) && !isset($this->escapes[$textPath][$at])) {
                return $this->tryAsk($textPath, $at);
            }
            if ($at === 0) {
                break;
            }
            $at = strrpos($value, '{', $at - strlen($value) - 1);
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     * @param string $mark
     * @param string $path
     */
    private function askMark(array $node, string $path, string $mark): bool
    {
        if (($node['type'] ?? '') === 'text') {
            $value = is_string($node['value'] ?? null) ? $node['value'] : '';
            for ($at = strpos($value, $mark); $at !== false; $at = strpos($value, $mark, $at + 1)) {
                if ($this->tryAsk($path, $at)) {
                    return true;
                }
            }

            return false;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child) && $this->askMark($child, $path . '/children/' . $index, $mark)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return array{string, string}|null
     */
    private static function lastText(array $node, string $path): ?array
    {
        if (($node['type'] ?? '') === 'text') {
            return [$path, is_string($node['value'] ?? null) ? $node['value'] : ''];
        }
        foreach (array_reverse(is_array($node['children'] ?? null) ? $node['children'] : [], true) as $index => $child) {
            $last = is_array($child) ? self::lastText($child, $path . '/children/' . $index) : null;
            if ($last !== null) {
                return $last;
            }
        }

        return null;
    }

    private function tryAsk(string $path, int $offset): bool
    {
        if (isset($this->asks[$path][$offset]) || isset($this->escapes[$path][$offset])) {
            return false;
        }
        $this->ask($path, $offset);

        return isset($this->asks[$path][$offset]);
    }

    private function ask(string $path, int $offset): void
    {
        $node = $this->textAt($path);
        $value = is_array($node) && is_string($node['value'] ?? null) ? $node['value'] : '';
        if (
            isset($value[$offset]) && Text::isPunct($value[$offset]) && !self::doubled($value, $offset)
            && !isset($this->escapes[$path][$offset]) && !isset($this->asks[$path][$offset])
        ) {
            $this->asks[$path][$offset] = true;
            $this->asked++;
        }
    }

    /**
     * C2-W-097: a literal backslash the bare write doubles, before punctuation, a space, a line end or the markup of the
     * next node; a backslash asked for there changes nothing (carve-js `escaped`).
     */
    private static function doubled(string $value, int $at): bool
    {
        if ($value[$at] !== '\\') {
            return false;
        }
        $next = $value[$at + 1] ?? '';

        return $next === '' || $next === ' ' || $next === "\n" || Text::isPunct($next);
    }

    /**
     * Whether the node at `$path` stands in a table cell.
     */
    private function inCell(string $path): bool
    {
        $node = $this->root;
        foreach (explode('/', trim($path, '/')) as $part) {
            if (!is_array($node[$part] ?? null)) {
                return false;
            }
            $node = $node[$part];
            if (($node['type'] ?? '') === 'table_cell') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<mixed>|null
     */
    private function textAt(string $path): ?array
    {
        $node = $this->root;
        foreach (explode('/', trim($path, '/')) as $part) {
            if (!is_array($node[$part] ?? null)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * Where the text at `$from` ends after `$bytes` of its written form: the bare write doubles a backslash before
     * punctuation or a space (C2-W-097) and escapes a pipe in a cell (C2-W-098), as carve-js `spelledEnd` counts.
     */
    private function spelledEnd(string $value, string $path, int $from, int $bytes): ?int
    {
        $at = $from;
        $length = strlen($value);
        $cell = null;
        while ($bytes > 0 && $at < $length) {
            $char = $value[$at];
            $always = $char === '\\'
                ? $at + 1 < $length && ($value[$at + 1] === ' ' || $value[$at + 1] === "\n" || Text::isPunct($value[$at + 1]))
                : $char === '|' && ($cell ??= $this->inCell($path));
            $bytes -= 1 + (isset($this->escapes[$path][$at]) || $always ? 1 : 0);
            $at++;
        }

        return $bytes === 0 ? $at : null;
    }

    /**
     * Whether the parsed node spans other bytes than the written spelling of the expected node at `$path`, on one line.
     *
     * @param string $path
     * @param array<mixed> $other
     */
    private function extentDiffers(string $path, array $other): bool
    {
        $packed = RepairTrace::packPath($path);
        $start = $this->nodeStarts[$packed] ?? null;
        $end = $this->nodeEnds[$packed] ?? null;
        $pos = TreeEncoder::offsets($other['pos'] ?? null);
        if ($start === null || $end === null || $pos === null || $this->source === null) {
            return false;
        }

        return !str_contains(substr($this->source, $start, $end - $start), "\n") && $pos[1] - $pos[0] !== $end - $start;
    }

    private static function closer(string $type): string
    {
        return match ($type) {
            'superscript' => '^', 'subscript' => ',', 'emphasis' => '/', 'strong' => '*',
            'underline' => '_', 'strike' => '~', 'highlight' => '=', 'insert' => '+', 'delete' => '-',
            'link', 'span', 'inline_footnote', 'inline_extension' => ']',
            default => '',
        };
    }
}
