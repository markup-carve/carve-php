<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * Compare inline lists in source order. A read-back construct may hide later nodes; stepping
 * over its spelling lets the same repair round report escapes behind the first mismatch.
 */
final class CompareRound
{
    /**
     * @var array<string, array<int, true>>
     */
    private array $asks = [];

    /**
     * @var array<string, int> The written start offset of each expected node, by path.
     */
    private array $starts = [];

    /**
     * @param array<mixed> $root
     * @param array<string, array<int, true>> $escapes
     */
    private function __construct(private array $root, private array $escapes)
    {
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     * @param array<string, array<int, true>> $escapes
     * @param array{nodes?: array<int, array{string, string, int, int}>, ranges?: array<string, array{int, int}>} $candidate
     *
     * @return array<string, array<int, true>>
     */
    public static function inspect(array $expected, array $actual, array $escapes, array $candidate = []): array
    {
        $comparison = new self($expected, $escapes);
        foreach ($candidate['ranges'] ?? [] as $path => [$start]) {
            $comparison->starts[$path] = $start;
        }
        foreach ($candidate['nodes'] ?? [] as [$path, , $start]) {
            $comparison->starts[$path] = $start;
        }
        $comparison->children($expected, $actual, '', '');

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
                ? $this->matchText($node, $have, $nodePath, $owner, $i, $j, $vi, $aj)
                : $this->matchNode($node, $have, $nodePath, $type, $i, $j, $aj);
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
     */
    private function resync(array $want, array $have, string $path, int &$i, int &$j): bool
    {
        $failed = $have[$j] ?? null;
        $failedEnd = is_array($failed) ? ($failed['pos']['endOffset'] ?? null) : null;
        if (!is_int($failedEnd)) {
            return false;
        }
        $count = count($want);
        for ($k = $i + 1, $next = $j; $k < $count; $k++) {
            $type = is_array($want[$k]) ? ($want[$k]['type'] ?? '') : '';
            if ($type === 'soft_break' || $type === 'hard_break') {
                return false;
            }
            $start = $this->starts[$path . '/children/' . $k] ?? null;
            if ($start === null || $start < $failedEnd) {
                continue;
            }
            while (is_array($have[$next] ?? null) && ($have[$next]['pos']['startOffset'] ?? -1) < $start) {
                $next++;
            }
            if (is_array($have[$next] ?? null) && ($have[$next]['pos']['startOffset'] ?? -1) === $start) {
                $i = $k;
                $j = $next;

                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     * @param array<mixed> $have
     * @param int $aj
     * @param int $j
     * @param int $i
     * @param string $type
     * @param string $path
     */
    private function matchNode(array $node, array $have, string $path, string $type, int &$i, int &$j, int &$aj): bool
    {
        $other = $have[$j] ?? null;
        if (!is_array($other) || ($other['type'] ?? '') !== $type || $aj !== 0) {
            $found = null;
            $limit = min(count($have), $j + 16);
            for ($next = $j + 1; $next < $limit; $next++) {
                if (is_array($have[$next]) && ($have[$next]['type'] ?? '') === $type) {
                    $found = $next;

                    break;
                }
            }
            if ($found === null) {
                return false;
            }
            $j = $found;
            $aj = 0;
            $other = $have[$j];
        }
        if (!is_array($other)) {
            return false;
        }
        $this->children($node, $other, $path, $type);
        $i++;
        $j++;

        return true;
    }

    /**
     * @param array<mixed> $node
     * @param array<mixed> $have
     * @param int $aj
     * @param int $vi
     * @param int $j
     * @param int $i
     * @param string $owner
     * @param string $path
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
    ): bool {
        $value = is_string($node['value'] ?? null) ? $node['value'] : '';
        $other = $have[$j] ?? null;
        if (!is_array($other)) {
            $mark = self::closer($owner);
            if ($mark !== '') {
                $at = strpos($value, $mark, $vi);
                if ($at !== false) {
                    $this->ask($path, $at);
                }
            }

            return false;
        }
        if (($other['type'] ?? '') !== 'text') {
            return $this->matchConstruct($value, $other, $path, $i, $j, $vi);
        }

        return $this->matchPlain($value, $other, $path, $i, $j, $vi, $aj);
    }

    /**
     * @param string $value
     * @param array<mixed> $other
     * @param int $vi
     * @param int $j
     * @param int $i
     * @param string $path
     */
    private function matchConstruct(string $value, array $other, string $path, int &$i, int &$j, int &$vi): bool
    {
        $at = $vi;
        if (($value[$at] ?? '') === '{' && ($other['type'] ?? '') === 'smart_punctuation') {
            $at += 2;
        }
        $this->ask($path, $at);
        $pos = is_array($other['pos'] ?? null) ? $other['pos'] : [];
        $startOffset = $pos['startOffset'] ?? null;
        $endOffset = $pos['endOffset'] ?? null;
        if (!is_int($startOffset) || !is_int($endOffset)) {
            return false;
        }
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
     * @param string $value
     * @param array<mixed> $other
     * @param int $aj
     * @param int $vi
     * @param int $j
     * @param int $i
     * @param string $path
     */
    private function matchPlain(string $value, array $other, string $path, int &$i, int &$j, int &$vi, int &$aj): bool
    {
        $otherValue = is_string($other['value'] ?? null) ? $other['value'] : '';
        $limit = min(strlen($value) - $vi, strlen($otherValue) - $aj);
        for ($k = 0; $k < $limit; $k++) {
            if ($value[$vi + $k] !== $otherValue[$aj + $k]) {
                $this->ask($path, $vi + $k);

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
            if ($aj > 0) {
                $j++;
                $aj = 0;
            }
        }

        return $limit > 0;
    }

    private function ask(string $path, int $offset): void
    {
        $node = $this->textAt($path);
        $value = is_array($node) && is_string($node['value'] ?? null) ? $node['value'] : '';
        if (
            isset($value[$offset]) && Text::isPunct($value[$offset])
            && !isset($this->escapes[$path][$offset]) && !isset($this->asks[$path][$offset])
        ) {
            $this->asks[$path][$offset] = true;
        }
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

    private function spelledEnd(string $value, string $path, int $from, int $bytes): ?int
    {
        $at = $from;
        $length = strlen($value);
        while ($bytes > 0 && $at < $length) {
            $bytes -= 1 + (isset($this->escapes[$path][$at]) ? 1 : 0);
            $at++;
        }

        return $bytes === 0 ? $at : null;
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
