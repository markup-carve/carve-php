<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;

final class DjotEmphasisRenderer
{
    private string $literalPrefix;

    /**
     * @var array<string, string>
     */
    private array $literals = [];

    /**
     * @var array<int, string>
     */
    private array $rendered = [];

    /**
     * @param string $source
     * @param string $mask
     * @param array<int, true> $structural
     * @param array<int, true> $literalBrackets
     * @param \Closure(string): string $convert
     */
    public function __construct(
        private readonly string $source,
        private readonly string $mask,
        private readonly array $structural,
        private readonly array $literalBrackets,
        private readonly Closure $convert,
    ) {
        $this->literalPrefix = "\0DJOTLITERAL\0";
        while (str_contains($source, $this->literalPrefix)) {
            $this->literalPrefix .= "\0";
        }
    }

    /**
     * @param list<\MarkupCarve\Carve\Converter\DjotEmphasisSpan> $roots
     */
    public function convert(array $roots): string
    {
        $work = [];
        foreach (array_reverse($roots) as $pair) {
            $work[] = ['pair' => $pair, 'outer' => [], 'ready' => false];
        }
        while ($work !== []) {
            $frame = array_pop($work);
            $pair = $frame['pair'];
            $outer = $frame['outer'];
            if ($frame['ready']) {
                $this->rendered[spl_object_id($pair)] = $this->render($pair, $outer);

                continue;
            }
            $work[] = ['pair' => $pair, 'outer' => $outer, 'ready' => true];
            $scope = false;
            foreach ($pair->children as $child) {
                $scope = $scope || array_intersect_key($child->kinds, $outer) !== [];
            }
            $inner = isset($outer[$pair->kind]) ? $outer : ($scope ? [$pair->kind => true] : $outer + [$pair->kind => true]);
            foreach (array_reverse($pair->children) as $child) {
                $work[] = ['pair' => $child, 'outer' => $inner, 'ready' => false];
            }
        }

        return strtr(($this->convert)($this->body(0, strlen($this->source), $roots, [])), $this->literals);
    }

    private function plain(int $start, int $end): string
    {
            $text = '';
        for ($i = $start; $i < $end; $i++) {
            $ch = $this->source[$i];
            if ($ch === '\\' && ($this->source[$i + 1] ?? '') !== "\n") {
                $text .= substr($this->source, $i, min(2, $end - $i));
                $i++;

                continue;
            }
            if ($this->mask[$i] === $ch && ((str_contains('_*', $ch) && !isset($this->structural[$i])) || isset($this->literalBrackets[$i]))) {
                $token = $this->literalPrefix . count($this->literals) . "\0";
                $this->literals[$token] = '\\' . $ch;
                $text .= $token;
            } else {
                $text .= $ch;
            }
        }

        return $text;
    }

    /**
     * @param int $start
     * @param int $end
     * @param list<\MarkupCarve\Carve\Converter\DjotEmphasisSpan> $children
     * @param array<string, true> $outer
     */
    private function body(int $start, int $end, array $children, array $outer): string
    {
            $text = '';
        $cursor = $start;
        foreach ($children as $child) {
            $text .= $this->plain($cursor, $child->start) . $this->rendered[spl_object_id($child)];
            $cursor = $child->end;
        }

        return $text . $this->plain($cursor, $end);
    }

    /**
     * @param \MarkupCarve\Carve\Converter\DjotEmphasisSpan $pair
@param array<string, true> $outer
     */
    private function render(DjotEmphasisSpan $pair, array $outer): string
    {
        if (isset($outer[$pair->kind])) {
            return $this->body($pair->openEnd, $pair->close, $pair->children, $outer);
        }
        $scope = false;
        foreach ($pair->children as $child) {
            if (array_intersect_key($child->kinds, $outer) !== []) {
                $scope = true;

                break;
            }
        }
        $content = $this->body($pair->openEnd, $pair->close, $pair->children, $scope ? [$pair->kind => true] : $outer + [$pair->kind => true]);
        $delimiter = $pair->kind === '_' ? '/' : '*';
        $forced = $pair->forced || $scope || str_starts_with($content, "\0") || str_ends_with($content, "\0")
                || ($pair->start > 0 && preg_match('/[A-Za-z0-9_]/', $this->source[$pair->start - 1]) === 1)
                || preg_match('/[A-Za-z0-9_]/', $this->source[$pair->end] ?? '') === 1
                || preg_match('/^[ \t\r\n]|[ \t\r\n]$/', $content) === 1
                || str_starts_with($content, $delimiter) || str_ends_with($content, $delimiter)
                || ($delimiter === '/' && str_starts_with($content, '*') && str_ends_with($content, '*'));

        return $this->protect($forced ? '{' . $delimiter : $delimiter) . $content . $this->protect($forced ? $delimiter . '}' : $delimiter);
    }

    private function protect(string $value): string
    {
        $token = $this->literalPrefix . count($this->literals) . "\0";
        $this->literals[$token] = $value;

        return $token;
    }
}
